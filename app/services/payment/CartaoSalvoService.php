<?php
declare(strict_types=1);

/**
 * app/services/payment/CartaoSalvoService.php
 *
 * Cartão salvo existe em DOIS lugares: a linha em `cartoes_salvos` e o cartão
 * dentro da adquirente. Quem apaga só a linha não remove nada — apenas perde
 * o endereço do que continua lá, cobrável e contando contra o limite de
 * cartões por cliente da adquirente (Mercado Pago, erro 129).
 *
 * Este service mantém os dois lados juntos.
 */
class CartaoSalvoService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Cartão do cliente com a adquirente que o emitiu.
     *
     * O `cliente_id` entra na consulta, não numa checagem depois: cartão de
     * outra pessoa tem de ser invisível, não "encontrado e negado".
     */
    public function daPessoa(int $cartaoId, int $clienteId): ?array
    {
        $st = $this->db->prepare(
            "SELECT cs.*, g.codigo AS adquirente
               FROM cartoes_salvos cs
               LEFT JOIN pgto_gateways g ON g.id = cs.gateway_id
              WHERE cs.id = :id AND cs.cliente_id = :cid
              LIMIT 1"
        );
        $st->execute([':id' => $cartaoId, ':cid' => $clienteId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Remove o cartão na adquirente e, só então, apaga a linha local.
     *
     * A ORDEM IMPORTA. Apagando a linha primeiro, uma falha na adquirente
     * deixaria o cartão lá para sempre, sem ninguém que soubesse o
     * customer_ref/card_ref para ir buscar depois.
     *
     * @return array{ok:bool, msg:string}
     */
    public function remover(int $cartaoId, int $clienteId): array
    {
        $cartao = $this->daPessoa($cartaoId, $clienteId);

        if ($cartao === null) {
            return ['ok' => false, 'msg' => 'Cartão não encontrado.'];
        }

        $naAdquirente = $this->removerNaAdquirente($cartao);

        // Falha temporária: a linha FICA. Dizer "removido" com o cartão ainda
        // cobrável na adquirente seria mentir sobre o que aconteceu.
        if ($naAdquirente === false) {
            return ['ok' => false, 'msg' =>
                'Não foi possível remover o cartão na operadora agora. Tente novamente.'];
        }

        $st = $this->db->prepare(
            "DELETE FROM cartoes_salvos WHERE id = :id AND cliente_id = :cid"
        );
        $st->execute([':id' => $cartaoId, ':cid' => $clienteId]);

        // rowCount, não o retorno do execute: um DELETE que não casou nada
        // também "deu certo", e devolvia "Cartão removido." sem remover nada.
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'msg' => 'Cartão não encontrado.'];
        }

        // Sumiu o cartão principal? O cliente fica sem padrão e o checkout
        // volta a perguntar. Promover o mais recente evita isso.
        if (!empty($cartao['principal'])) {
            $this->promoverMaisRecente($clienteId);
        }

        LogService::audit('Cartao salvo removido', [
            'cliente_id'    => $clienteId,
            'cartao_id'     => $cartaoId,
            'adquirente'    => $cartao['adquirente'] ?? null,
            'na_adquirente' => $naAdquirente === true ? 'removido' : 'nao_aplicavel',
        ]);

        return ['ok' => true, 'msg' => 'Cartão removido.'];
    }

    /**
     * Remove o cartão em TODAS as adquirentes onde ele existe.
     *
     * Um cartão pode ter referência no Mercado Pago e na Cielo ao mesmo
     * tempo (ver cartoes_salvos_adquirentes). Remover só na primeira deixaria
     * o outro cofre com um cartão cobrável e sem dono conhecido.
     *
     * @return true|null|false  true = removeu onde dava · null = nada a
     *                          remover lá · false = alguma adquirente com
     *                          remoção RECUSOU (não apagar local)
     */
    private function removerNaAdquirente(array $cartao): ?bool
    {
        $refs = (new CartaoSalvo())->todasAsRefs((int) $cartao['id']);

        // Legado sem linha filha: cai para as colunas antigas do próprio
        // cartão, que guardam a primeira (e única) adquirente.
        if ($refs === [] && !empty($cartao['adquirente'])) {
            $refs = [[
                'codigo'       => (string) $cartao['adquirente'],
                'customer_ref' => $cartao['customer_ref'] ?? null,
                'card_ref'     => $cartao['card_ref'] ?? null,
            ]];
        }

        if ($refs === []) return null;

        $resultado = null;   // null = nada removível encontrado ainda

        foreach ($refs as $ref) {
            $codigo   = (string) ($ref['codigo'] ?? '');
            $customer = (string) ($ref['customer_ref'] ?? '');
            $card     = (string) ($ref['card_ref'] ?? '');

            if ($codigo === '' || $card === '') continue;

            $adapter = AdquirenteFactory::porCodigo($codigo);

            // Sem endpoint de remoção — a Cielo não publica nenhum para o
            // Cartão Protegido; a Malga também não tinha. O registro local
            // sai e o log fica para reconciliar. Não é falha: é limitação
            // deles, e travar o cliente por isso seria pior.
            if ($adapter === null || !method_exists($adapter, 'removerCartao')) {
                LogService::warning('Cartao removido so localmente nesta adquirente', [
                    'adquirente' => $codigo, 'motivo' => 'adapter sem removerCartao',
                ], 'pagamento');
                continue;
            }

            // Mercado Pago exige o par customer + card.
            if ($customer === '') continue;

            $ok = $adapter->removerCartao($customer, $card);

            // Uma recusa de verdade (não-404) segura a exclusão local: dizer
            // "removido" com o cartão ainda cobrável lá seria mentir.
            if (!$ok) return false;

            $resultado = true;
        }

        return $resultado;
    }

    private function promoverMaisRecente(int $clienteId): void
    {
        $this->db->prepare(
            "UPDATE cartoes_salvos SET principal = 1
              WHERE cliente_id = :cid AND ativo = 1
              ORDER BY criado_em DESC
              LIMIT 1"
        )->execute([':cid' => $clienteId]);
    }

    /**
     * Preenche titular e validade lendo a adquirente.
     *
     * Serve os cartões salvos antes de o código passar a guardar esses dados
     * — ficaram com 'TITULAR' e '12/99', que a tela mostrava como se fossem
     * do cartão.
     *
     * @return array{lidos:int, atualizados:int, erros:int}
     */
    public function reconciliar(bool $aplicar = false): array
    {
        $r = ['lidos' => 0, 'atualizados' => 0, 'erros' => 0];

        $sql = "SELECT cs.id, cs.customer_ref, cs.card_ref, g.codigo AS adquirente
                  FROM cartoes_salvos cs
                  JOIN pgto_gateways g ON g.id = cs.gateway_id
                 WHERE cs.customer_ref IS NOT NULL AND cs.card_ref IS NOT NULL
                   AND (cs.nome_titular IS NULL OR cs.nome_titular = 'TITULAR'
                        OR cs.validade IS NULL OR cs.validade = '12/99')";

        $up = $this->db->prepare(
            "UPDATE cartoes_salvos SET nome_titular = :n, validade = :v WHERE id = :id"
        );

        foreach ($this->db->query($sql) as $c) {
            $r['lidos']++;

            $adapter = AdquirenteFactory::porCodigo((string) $c['adquirente']);
            if ($adapter === null || !method_exists($adapter, 'listarCartoes')) continue;

            try {
                $achado = null;
                foreach ($adapter->listarCartoes((string) $c['customer_ref']) as $card) {
                    if ((string) ($card['id'] ?? '') === (string) $c['card_ref']) {
                        $achado = $card;
                        break;
                    }
                }
                if ($achado === null) { $r['erros']++; continue; }

                $nome = trim((string) ($achado['cardholder']['name'] ?? '')) ?: null;
                $mes  = (int) ($achado['expiration_month'] ?? 0);
                $ano  = (int) ($achado['expiration_year'] ?? 0);
                $val  = ($mes >= 1 && $mes <= 12 && $ano >= 2000)
                        ? sprintf('%02d/%02d', $mes, $ano % 100) : null;

                if ($nome === null && $val === null) continue;

                if ($aplicar) {
                    $up->execute([
                        ':n'  => $nome !== null ? mb_substr(strtoupper($nome), 0, 120) : null,
                        ':v'  => $val,
                        ':id' => (int) $c['id'],
                    ]);
                }
                $r['atualizados']++;
            } catch (\Throwable $e) {
                $r['erros']++;
            }
        }

        return $r;
    }

    // ── Cartão temporário ──────────────────────────────────────────────

    /**
     * Apaga um cartão que o cliente pediu para NÃO salvar.
     *
     * Ele precisou existir: é por `cartao_id` que ficam as referências de cada
     * adquirente, e sem referência não há cobrança. Terminada a compra, ele
     * sai — dos cofres e daqui.
     *
     * Difere de `remover()` em uma coisa, de propósito: **uma adquirente que
     * recusa a exclusão não impede a limpeza local.** Em `remover()` a linha
     * fica, porque o cliente está olhando a lista e precisa ver a verdade.
     * Aqui não há lista: manter a linha significaria mostrar na conta dele um
     * cartão que ele disse para não guardar. O que sobra vira aviso no log e
     * cai na reconciliação.
     *
     * @return bool true se a linha saiu do banco.
     */
    public function purgarTemporario(int $cartaoId, int $clienteId): bool
    {
        $cartao = $this->daPessoa($cartaoId, $clienteId);
        if ($cartao === null) return false;

        $naAdquirente = null;
        try {
            $naAdquirente = $this->removerNaAdquirente($cartao);
        } catch (\Throwable $e) {
            // Nunca derruba a compra que acabou de acontecer.
            LogService::exception($e, 'error', 'pagamento', [
                'acao' => 'purgar_cartao_temporario', 'cartao_id' => $cartaoId,
            ]);
        }

        if ($naAdquirente === false) {
            // O cofre disse não. A linha sai mesmo assim (ver acima), mas
            // isso precisa ficar registrado: há um cartão órfão lá dentro.
            LogService::warning('Cartao temporario continua na adquirente', [
                'cliente_id' => $clienteId,
                'cartao_id'  => $cartaoId,
                'adquirente' => $cartao['adquirente'] ?? null,
            ], 'pagamento');
        }

        $st = $this->db->prepare(
            "DELETE FROM cartoes_salvos
              WHERE id = :id AND cliente_id = :cid AND temporario = 1"
        );
        $st->execute([':id' => $cartaoId, ':cid' => $clienteId]);
        $saiu = $st->rowCount() > 0;

        if ($saiu) {
            LogService::audit('Cartao temporario removido', [
                'cliente_id'    => $clienteId,
                'cartao_id'     => $cartaoId,
                'na_adquirente' => $naAdquirente === true ? 'removido'
                                 : ($naAdquirente === false ? 'recusou' : 'nao_aplicavel'),
            ]);
        }

        return $saiu;
    }

    /**
     * Recolhe os temporários que ficaram para trás — o que o cron chama.
     *
     * A limpeza normal é no fim da compra. Esta pega o resto: navegador
     * fechado no desafio 3DS, PHP morto no meio, compra que virou pendente e
     * ninguém voltou. Sem ela o cartão fica nos cofres para sempre, contra a
     * vontade de quem digitou.
     *
     * @param int $minutos idade mínima. Precisa ser maior que a validade de um
     *                     desafio 3DS — apagar o cartão de uma compra em
     *                     andamento faria a cobrança falhar sozinha.
     */
    public function purgarExpirados(int $minutos = 60, int $limite = 200): array
    {
        $r = ['achados' => 0, 'removidos' => 0, 'erros' => 0];

        foreach ((new CartaoSalvo())->temporariosExpirados($minutos, $limite) as $c) {
            $r['achados']++;
            try {
                if ($this->purgarTemporario((int) $c['id'], (int) $c['cliente_id'])) {
                    $r['removidos']++;
                }
            } catch (\Throwable $e) {
                $r['erros']++;
                LogService::exception($e, 'error', 'pagamento', [
                    'acao' => 'purgar_expirados', 'cartao_id' => $c['id'] ?? null,
                ]);
            }
        }

        return $r;
    }
}
