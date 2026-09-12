<?php
declare(strict_types=1);

/**
 * app/services/EstoquePonteService.php
 *
 * A ponte de estoque entre Syscar e Bling, do lado do painel.
 *
 * Estado e regra moram aqui; I/O mora nos clients (BlingApiClient,
 * SyscarClient). O worker (cli/estoque-worker.php) só orquestra.
 *
 * ── O que este serviço corrige em relação ao admin.loja ──────────────
 *
 * 1. RESERVADO != CONCLUÍDO. Lá a linha nasce com o protocolo e é atualizada
 *    com o resultado, então a chave única — que existe para impedir duplicar —
 *    também impede consertar: falha de rede virava `insert` e o reprocesso
 *    batia no UNIQUE. Aqui o `status` caminha
 *        pendente -> enviando -> confirmado | falhou
 *    e reenviar o MESMO protocolo é permitido enquanto não confirmar.
 *
 * 2. SUCESSO É RESPOSTA CONFIRMADA. Lá só JSON com a chave `erro` contava como
 *    falha, então timeout e HTML de erro viravam sucesso. Aqui o padrão é o
 *    inverso: quem decide é o client, e o que não for sucesso explícito falha.
 *
 * 3. O PROTOCOLO NÃO USA O RELÓGIO. Ver protocolo().
 *
 * Ver docs/sportmoto-os/12-decisoes-tecnicas/estoque-modulo-especificacao.md
 */
class EstoquePonteService
{
    private PDO $db;

    /** Cache dos interruptores e limites, por requisição. */
    private array $config = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // ENTRADA — o que chegou
    // ════════════════════════════════════════════════════

    /**
     * Grava o evento recebido e devolve na hora. Processar é do worker.
     *
     * A reentrega do mesmo evento é absorvida pelo UNIQUE (origem,
     * chave_externa): devolve o id que já existia com duplicado = true, em vez
     * de criar outro. Evento sem chave externa não é deduplicado aqui — o
     * protocolo do movimento barra adiante.
     *
     * @return array{id:int, duplicado:bool}
     */
    public function registrarEvento(
        string  $origem,
        string  $tipo,
        ?string $chaveExterna,
        array   $payload,
        bool    $assinaturaOk = true,
        ?string $requestId = null
    ): array {
        $chaveExterna = ($chaveExterna !== null && trim($chaveExterna) !== '')
            ? trim($chaveExterna)
            : null;

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO estoque_eventos
                   (origem, tipo, chave_externa, payload, assinatura_ok, request_id)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $origem,
                $tipo,
                $chaveExterna,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $assinaturaOk ? 1 : 0,
                $requestId,
            ]);

            return ['id' => (int)$this->db->lastInsertId(), 'duplicado' => false];

        } catch (\PDOException $e) {
            // 1062 = já registrado. Não é erro: é o webhook reentregando.
            if ((int)($e->errorInfo[1] ?? 0) !== 1062 || $chaveExterna === null) {
                throw $e;
            }

            $stmt = $this->db->prepare(
                "SELECT id FROM estoque_eventos
                  WHERE origem = ? AND chave_externa = ? LIMIT 1"
            );
            $stmt->execute([$origem, $chaveExterna]);

            return ['id' => (int)$stmt->fetchColumn(), 'duplicado' => true];
        }
    }

    /** Eventos à espera do worker, respeitando o teto de tentativas. */
    public function eventosPendentes(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM estoque_eventos
              WHERE status = 'pendente' AND tentativas < ?
              ORDER BY tentativas ASC, id ASC
              LIMIT ?"
        );
        $stmt->bindValue(1, $this->maxTentativas(), PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function marcarEventoProcessado(int $id): void
    {
        $this->db->prepare(
            "UPDATE estoque_eventos
                SET status = 'processado', processado_em = NOW(), erro = NULL
              WHERE id = ?"
        )->execute([$id]);
    }

    public function marcarEventoIgnorado(int $id, string $motivo): void
    {
        $this->db->prepare(
            "UPDATE estoque_eventos
                SET status = 'ignorado', processado_em = NOW(), erro = ?
              WHERE id = ?"
        )->execute([mb_substr($motivo, 0, 500), $id]);
    }

    /**
     * Conta a tentativa e devolve para a fila; esgotado o teto, vira `erro`.
     * Mesmo desenho da fila de pedidos (BlingOrderService::marcarFalha).
     */
    public function marcarEventoErro(int $id, string $erro): void
    {
        $this->db->prepare(
            "UPDATE estoque_eventos
                SET tentativas = tentativas + 1,
                    erro       = ?,
                    status     = IF(tentativas + 1 >= ?, 'erro', 'pendente')
              WHERE id = ?"
        )->execute([mb_substr($erro, 0, 500), $this->maxTentativas(), $id]);
    }

    // ════════════════════════════════════════════════════
    // IDEMPOTÊNCIA — protocolo e ciclo
    // ════════════════════════════════════════════════════

    /**
     * A chave do movimento. Deriva do CONTEÚDO, nunca do relógio.
     *
     * Com `date('YmdHis')` — o que o site fazia até 12/09/2026 — a reentrega
     * do mesmo webhook dois segundos depois gerava chave nova e a baixa era
     * aplicada de novo. Em E/S isso é cumulativo.
     *
     * O ciclo entra na chave de propósito: é ele que distingue a MESMA venda
     * reprocessada (mesmo ciclo, mesma chave, deduplicada) de uma venda nova
     * do mesmo item no mesmo pedido depois de um estorno (ciclo seguinte,
     * chave nova, processada).
     */
    public function protocolo(array $partes): string
    {
        $normalizadas = [];
        foreach ($partes as $chave => $valor) {
            $normalizadas[] = $chave . ':' . (is_scalar($valor) ? (string)$valor : json_encode($valor));
        }
        sort($normalizadas, SORT_STRING);   // ordem das chaves não muda o hash

        return hash('sha256', implode('|', $normalizadas));
    }

    /**
     * O ciclo vigente de (pedido, produto) para a ação pedida.
     *
     * VENDA  depois de VENDA  -> mantém o ciclo (mudar de "Em aberto" para
     *                            "Em andamento" não pode baixar de novo)
     * VENDA  depois de ESTORNO-> abre o ciclo seguinte
     * ESTORNO com ciclo aberto-> usa o ciclo vigente
     * ESTORNO sem venda       -> NULL, e quem chama ignora o movimento
     *
     * O último caso é o que torna o corte da fase 2 seguro: pedido criado
     * antes do corte e cancelado depois não gera estorno de uma venda que este
     * painel nunca registrou.
     */
    public function proximoCiclo(int $pedidoBlingId, int $produtoId, string $acao): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT ciclo, operacao
               FROM estoque_movimentos
              WHERE pedido_bling_id = ?
                AND produto_id      = ?
                AND ciclo IS NOT NULL
                AND status <> 'ignorado'
              ORDER BY ciclo DESC, id DESC
              LIMIT 1"
        );
        $stmt->execute([$pedidoBlingId, $produtoId]);
        $ultimo = $stmt->fetch();

        $ultimoCiclo = $ultimo ? (int)$ultimo['ciclo']       : 0;
        $ultimaOp    = $ultimo ? (string)$ultimo['operacao'] : '';

        if (strtoupper($acao) === 'VENDA') {
            return ($ultimoCiclo > 0 && $ultimaOp === 'S')
                ? $ultimoCiclo          // já baixou neste ciclo
                : $ultimoCiclo + 1;     // primeiro, ou depois de estorno
        }

        // ESTORNO só faz sentido sobre uma venda registrada.
        return $ultimoCiclo > 0 ? $ultimoCiclo : null;
    }

    // ════════════════════════════════════════════════════
    // TRADUÇÃO — evento vira movimento
    // ════════════════════════════════════════════════════

    /**
     * Resolve o produto do site a partir do código que circula na ponte.
     *
     * O código é o `sku_legado` — o fator de unificação entre Syscar, Bling e
     * site. Procura primeiro no SKU (produto com variação), depois no produto.
     *
     * @return array{produto_id:int, sku_id:?int}|null
     */
    public function resolverProdutoPorSku(string $codigo): ?array
    {
        $codigo = trim($codigo);
        if ($codigo === '') return null;

        $stmt = $this->db->prepare(
            "SELECT ps.id AS sku_id, ps.produto_id
               FROM produto_skus ps
              WHERE ps.sku = ? AND ps.ativo = 1
              LIMIT 1"
        );
        $stmt->execute([$codigo]);
        if ($sku = $stmt->fetch()) {
            return ['produto_id' => (int)$sku['produto_id'], 'sku_id' => (int)$sku['sku_id']];
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM produtos
              WHERE sku_legado = ? AND deleted_at IS NULL
              LIMIT 1"
        );
        $stmt->execute([$codigo]);
        if ($id = $stmt->fetchColumn()) {
            return ['produto_id' => (int)$id, 'sku_id' => null];
        }

        return null;
    }

    /**
     * Traduz um evento em zero ou mais movimentos a despachar.
     *
     * FASE 1: só a perna A (movimento vindo do Syscar) está traduzida. A perna
     * B — pedido do Bling virando baixa item a item — entra na fase 2, junto
     * com o corte. Evento de origem `bling` é marcado como ignorado com motivo
     * explícito, para aparecer na tela em vez de sumir.
     *
     * @return array{movimentos:array, motivo_ignorado:?string}
     */
    public function traduzirEvento(array $evento): array
    {
        if ($evento['origem'] !== 'syscar') {
            return ['movimentos' => [], 'motivo_ignorado' => 'perna_b_chega_na_fase_2'];
        }

        $p = json_decode((string)$evento['payload'], true);
        if (!is_array($p)) {
            throw new \RuntimeException('Payload do evento não é JSON válido.');
        }

        $codigo   = trim((string)($p['cod'] ?? $p['sku'] ?? ''));
        $operacao = strtoupper(trim((string)($p['lancamento'] ?? $p['operacao'] ?? 'B')));
        $qtd      = abs((float)($p['movimento'] ?? $p['quantidade'] ?? 0));
        $valor    = (float)($p['preco_mov'] ?? $p['valor'] ?? 0);

        if ($codigo === '') {
            throw new \RuntimeException('Evento do Syscar sem código de SKU.');
        }
        if (!in_array($operacao, ['E', 'S', 'B'], true)) {
            throw new \RuntimeException("Operação inválida: {$operacao}");
        }

        // Saldo novo ZERO vira balanço zero — zero é autoritativo, não se soma
        // nem se subtrai. É o comportamento bom que o admin.loja já tinha.
        if (isset($p['n_saldo']) && (float)$p['n_saldo'] === 0.0) {
            $operacao = 'B';
            $qtd      = 0.0;
        }

        $alvo = $this->resolverProdutoPorSku($codigo);

        // Produto que o site não conhece não é erro: é catálogo desalinhado.
        // Registra ignorado para a tela mostrar, em vez de repetir tentativa.
        if (!$alvo) {
            return [
                'movimentos' => [[
                    'evento_id'  => (int)$evento['id'],
                    'direcao'    => 'para_bling',
                    'protocolo'  => $this->protocolo([
                        'evt' => (int)$evento['id'], 'sku' => $codigo,
                        'op'  => $operacao, 'qtd' => $qtd,
                    ]),
                    'sku_codigo' => $codigo,
                    'operacao'   => $operacao,
                    'quantidade' => $qtd,
                    'valor'      => $valor,
                    'status'         => 'ignorado',
                    'motivo_ignorado'=> 'sku_sem_vinculo_no_site',
                ]],
                'motivo_ignorado' => null,
            ];
        }

        return [
            'movimentos' => [[
                'evento_id'  => (int)$evento['id'],
                'direcao'    => 'para_bling',
                'protocolo'  => $this->protocolo([
                    'evt' => (int)$evento['id'], 'sku' => $codigo,
                    'op'  => $operacao, 'qtd' => $qtd, 'val' => $valor,
                ]),
                'produto_id' => $alvo['produto_id'],
                'sku_id'     => $alvo['sku_id'],
                'sku_codigo' => $codigo,
                'operacao'   => $operacao,
                'quantidade' => $qtd,
                'valor'      => $valor,
            ]],
            'motivo_ignorado' => null,
        ];
    }

    // ════════════════════════════════════════════════════
    // MOVIMENTOS — a fila de saída
    // ════════════════════════════════════════════════════

    /**
     * Cria o movimento em `pendente`. O UNIQUE do protocolo absorve a
     * duplicata: devolve duplicado = true sem criar linha nova.
     *
     * @return array{id:int, duplicado:bool}
     */
    public function criarMovimento(array $mov): array
    {
        $campos = [
            'evento_id'       => $mov['evento_id']       ?? null,
            'direcao'         => $mov['direcao'],
            'protocolo'       => $mov['protocolo'],
            'ciclo'           => $mov['ciclo']           ?? null,
            'produto_id'      => $mov['produto_id']      ?? null,
            'sku_id'          => $mov['sku_id']          ?? null,
            'sku_codigo'      => (string)($mov['sku_codigo'] ?? ''),
            'operacao'        => strtoupper((string)$mov['operacao']),
            'quantidade'      => $mov['quantidade'],
            'valor'           => $mov['valor']           ?? null,
            'pedido_bling_id' => $mov['pedido_bling_id'] ?? null,
            'canal_id'        => $mov['canal_id']        ?? null,
            'status'          => $mov['status']          ?? 'pendente',
            'motivo_ignorado' => $mov['motivo_ignorado'] ?? null,
        ];

        try {
            $cols = implode(', ', array_keys($campos));
            $phs  = implode(', ', array_fill(0, count($campos), '?'));

            $this->db->prepare(
                "INSERT INTO estoque_movimentos ({$cols}) VALUES ({$phs})"
            )->execute(array_values($campos));

            return ['id' => (int)$this->db->lastInsertId(), 'duplicado' => false];

        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;

            $stmt = $this->db->prepare(
                "SELECT id FROM estoque_movimentos WHERE protocolo = ? LIMIT 1"
            );
            $stmt->execute([$campos['protocolo']]);

            return ['id' => (int)$stmt->fetchColumn(), 'duplicado' => true];
        }
    }

    /** Movimentos à espera de envio. */
    public function movimentosPendentes(int $limite = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM estoque_movimentos
              WHERE status = 'pendente' AND tentativas < ?
              ORDER BY tentativas ASC, id ASC
              LIMIT ?"
        );
        $stmt->bindValue(1, $this->maxTentativas(), PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Toma posse do movimento antes de enviar.
     *
     * O UPDATE condicionado a status = 'pendente' é o que impede dois workers
     * (ou um worker e um reenvio manual pela tela) de mandarem o mesmo
     * movimento: só um consegue mudar a linha, o outro recebe rowCount 0.
     */
    public function marcarEnviando(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE estoque_movimentos
                SET status = 'enviando', enviado_em = NOW(), tentativas = tentativas + 1
              WHERE id = ? AND status = 'pendente'"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    public function confirmar(int $id, array $resposta = []): void
    {
        $this->db->prepare(
            "UPDATE estoque_movimentos
                SET status = 'confirmado', confirmado_em = NOW(),
                    ultimo_erro = NULL, resposta = ?
              WHERE id = ?"
        )->execute([json_encode($resposta, JSON_UNESCAPED_UNICODE), $id]);
    }

    /**
     * Devolve para a fila, ou encerra em `falhou` quando esgota o teto.
     * Quem grita (LogService::critical + sino) é o worker — o serviço só
     * registra o estado.
     *
     * @return bool true quando esgotou as tentativas
     */
    public function falhar(int $id, string $erro, array $resposta = []): bool
    {
        $max = $this->maxTentativas();

        $this->db->prepare(
            "UPDATE estoque_movimentos
                SET status      = IF(tentativas >= ?, 'falhou', 'pendente'),
                    ultimo_erro = ?,
                    resposta    = ?
              WHERE id = ?"
        )->execute([
            $max,
            mb_substr($erro, 0, 500),
            $resposta ? json_encode($resposta, JSON_UNESCAPED_UNICODE) : null,
            $id,
        ]);

        $stmt = $this->db->prepare("SELECT status FROM estoque_movimentos WHERE id = ?");
        $stmt->execute([$id]);
        return (string)$stmt->fetchColumn() === 'falhou';
    }

    public function ignorar(int $id, string $motivo): void
    {
        $this->db->prepare(
            "UPDATE estoque_movimentos
                SET status = 'ignorado', motivo_ignorado = ?
              WHERE id = ?"
        )->execute([mb_substr($motivo, 0, 120), $id]);
    }

    /**
     * Movimento que ficou preso em `enviando` — processo morto no meio,
     * deploy no meio do envio. Volta para `pendente` para o worker retomar.
     * A tentativa já foi contada, então isso não vira laço infinito.
     */
    public function resgatarPresos(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE estoque_movimentos
                SET status = 'pendente',
                    ultimo_erro = CONCAT('resgatado de enviando em ', NOW())
              WHERE status = 'enviando'
                AND enviado_em < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->bindValue(1, $this->minutosResgate(), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Reenfileira um movimento que falhou — é o que a tela oferece. */
    public function reenfileirar(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE estoque_movimentos
                SET status = 'pendente', tentativas = 0, ultimo_erro = NULL
              WHERE id = ? AND status IN ('falhou','pendente')"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    // ════════════════════════════════════════════════════
    // DESPACHO
    // ════════════════════════════════════════════════════

    /**
     * Envia o movimento para o destino da sua direção.
     *
     * Com a perna desligada devolve `enviado = false` sem tocar em API
     * nenhuma — é o estado da fase 1: o movimento é criado, fica em
     * `pendente`, e nada sai. Ligar a perna é o corte.
     *
     * @return array{enviado:bool, ok:bool, motivo:string, resposta:array}
     */
    public function despachar(array $mov): array
    {
        $perna = $mov['direcao'] === 'para_bling' ? 'a' : 'b';

        if (!$this->pernaLigada($perna)) {
            return [
                'enviado'  => false,
                'ok'       => false,
                'motivo'   => 'perna_desligada',
                'resposta' => [],
            ];
        }

        if ($mov['direcao'] === 'para_syscar') {
            $resposta = (new SyscarClient())->enviarMovimento($mov);
        } else {
            $resposta = $this->enviarAoBling($mov);
        }

        return [
            'enviado'  => true,
            'ok'       => (bool)($resposta['ok'] ?? false),
            'motivo'   => (string)($resposta['msg'] ?? ''),
            'resposta' => $resposta,
        ];
    }

    /**
     * Perna A: escreve o movimento no produto do Bling.
     *
     * Mantém o comportamento bom do admin.loja: saldo novo ZERO vira balanço
     * zero. Zero é autoritativo — não se soma nem se subtrai, se crava.
     */
    private function enviarAoBling(array $mov): array
    {
        $deposito = $this->depositoPadrao();
        if (!$deposito) {
            return ['ok' => false, 'msg' => 'Nenhum depósito Bling configurado.'];
        }

        try {
            $resp = (new BlingApiClient())->post('/estoques', [
                'produto'     => ['id' => $mov['sku_codigo']],
                'deposito'    => ['id' => $deposito],
                'operacao'    => $mov['operacao'],
                'quantidade'  => (float)$mov['quantidade'],
                'preco'       => (float)($mov['valor'] ?? 0),
                'observacoes' => 'Ponte de estoque — protocolo ' . substr((string)$mov['protocolo'], 0, 12),
            ]);

            // Sucesso é resposta reconhecida. Qualquer outra coisa falha.
            $ok = !isset($resp['error']) && (isset($resp['data']) || $resp !== []);

            return ['ok' => $ok, 'msg' => $ok ? 'ok' : 'Resposta não reconhecida do Bling.',
                    'bruto' => $resp];

        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    private function depositoPadrao(): ?int
    {
        $id = $this->db->query(
            "SELECT bling_deposito_id FROM bling_depositos
              WHERE ativo = 1 ORDER BY padrao DESC, id ASC LIMIT 1"
        )->fetchColumn();
        return $id ? (int)$id : null;
    }

    // ════════════════════════════════════════════════════
    // MÉTRICAS E CONFIG
    // ════════════════════════════════════════════════════

    /** Os números dos cards da tela. */
    public function resumo(): array
    {
        $mov = $this->db->query(
            "SELECT
                COUNT(*)                                                   AS total,
                SUM(status = 'pendente')                                   AS pendentes,
                SUM(status = 'enviando')                                   AS enviando,
                SUM(status = 'falhou')                                     AS falhou,
                SUM(status = 'ignorado')                                   AS ignorado,
                SUM(status = 'confirmado'
                    AND confirmado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS confirmados_24h,
                SUM(status = 'falhou'
                    AND criado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR))     AS falhas_24h,
                MAX(confirmado_em)                                          AS ultima_confirmacao
             FROM estoque_movimentos"
        )->fetch() ?: [];

        $evt = $this->db->query(
            "SELECT
                SUM(status = 'pendente') AS pendentes,
                SUM(status = 'erro')     AS erro,
                MAX(criado_em)           AS ultimo
             FROM estoque_eventos"
        )->fetch() ?: [];

        return ['movimentos' => $mov, 'eventos' => $evt];
    }

    public function pernaLigada(string $perna): bool
    {
        return $this->config('estoque_ponte_perna_' . strtolower($perna), '0') === '1';
    }

    public function maxTentativas(): int
    {
        return max(1, (int)$this->config('estoque_ponte_max_tentativas', '5'));
    }

    public function minutosResgate(): int
    {
        return max(1, (int)$this->config('estoque_ponte_resgate_min', '15'));
    }

    private function config(string $chave, string $default): string
    {
        if (array_key_exists($chave, $this->config)) {
            return $this->config[$chave];
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1"
            );
            $stmt->execute([$chave]);
            $v = $stmt->fetchColumn();
            $this->config[$chave] = ($v !== false) ? (string)$v : $default;
        } catch (\Throwable) {
            $this->config[$chave] = $default;
        }
        return $this->config[$chave];
    }
}
