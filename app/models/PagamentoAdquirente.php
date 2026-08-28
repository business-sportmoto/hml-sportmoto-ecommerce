<?php
declare(strict_types=1);

/**
 * app/models/PagamentoAdquirente.php
 *
 * Persistência de pgto_gateways — o cadastro das adquirentes e suas
 * credenciais.
 *
 * SEGREDOS: api_key e webhook_secret nunca saem daqui em claro para a tela.
 * `listarParaTela()` devolve apenas se o campo ESTÁ preenchido, não o valor.
 * Um segredo que aparece num input HTML acaba em cache de navegador, em
 * screenshot de suporte e no histórico do formulário.
 *
 * Gravação é parcial de propósito: campo enviado vazio significa "não mexer",
 * não "apagar". Sem isso, abrir a tela e salvar sem tocar em nada limparia as
 * credenciais — e derrubaria os pagamentos.
 */
class PagamentoAdquirente extends Model
{
    protected string $table = 'pgto_gateways';

    /** Campos tratados como segredo: só gravados quando vêm preenchidos. */
    private const SEGREDOS = ['api_key', 'front_api_key', 'webhook_secret', 'webhook_public_key'];

    /** Campos comuns, sobrescritos normalmente. */
    private const CAMPOS = [
        'nome', 'ativo', 'sandbox', 'client_id', 'front_client_id',
        'merchant_id', 'webhook_id', 'webhook_endpoint',
    ];

    /** Adapters que existem no código — o que a tela pode oferecer. */
    public const SUPORTADAS = [
        'safrapay'    => 'Safra Pay',
        'mercadopago' => 'Mercado Pago',
        'fake'        => 'Fake (testes)',
    ];

    public function listar(): array
    {
        return $this->db->query(
            "SELECT * FROM pgto_gateways ORDER BY ativo DESC, nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Versão segura para a view: troca cada segredo por um booleano.
     * A tela mostra "configurado" ou "pendente", nunca o valor.
     */
    public function listarParaTela(): array
    {
        $out = [];
        foreach ($this->listar() as $g) {
            foreach (self::SEGREDOS as $s) {
                $g[$s . '_preenchido'] = !empty($g[$s]);
                unset($g[$s]);
            }
            $g['tem_adapter'] = isset(self::SUPORTADAS[$g['codigo']]);
            $out[] = $g;
        }
        return $out;
    }

    public function porCodigo(string $codigo): ?array
    {
        $st = $this->db->prepare("SELECT * FROM pgto_gateways WHERE codigo = ? LIMIT 1");
        $st->execute([$codigo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Grava a configuração. Segredo vazio = preserva o que já existe.
     */
    public function salvar(int $id, array $dados): bool
    {
        $set = [];
        $par = [];

        foreach (self::CAMPOS as $c) {
            if (!array_key_exists($c, $dados)) continue;
            $set[]   = "`{$c}` = :{$c}";
            $par[$c] = $dados[$c];
        }

        foreach (self::SEGREDOS as $c) {
            // String vazia significa "o lojista não digitou nada agora".
            // Só sobrescreve quando há valor novo de verdade.
            if (!isset($dados[$c]) || trim((string) $dados[$c]) === '') continue;
            $set[]   = "`{$c}` = :{$c}";
            $par[$c] = trim((string) $dados[$c]);
        }

        if (!$set) return false;

        $par['id'] = $id;
        $sql = "UPDATE pgto_gateways SET " . implode(', ', $set) . ", atualizado_em = NOW() WHERE id = :id";
        return $this->db->prepare($sql)->execute($par);
    }

    /**
     * Ativa/desativa. Ativar exige credencial mínima — uma adquirente ativa
     * e sem credencial é pior do que desativada: ela entra no fluxo e falha
     * na hora do pagamento.
     *
     * @return array{ok:bool, msg:string}
     */
    public function alternarAtivo(int $id, bool $ativar): array
    {
        $g = $this->find($id);
        if (!$g) return ['ok' => false, 'msg' => 'Adquirente não encontrada.'];

        if ($ativar) {
            if (!isset(self::SUPORTADAS[$g['codigo']])) {
                return ['ok' => false, 'msg' => 'Não existe adapter no código para esta adquirente.'];
            }
            if (empty($g['merchant_id']) && empty($g['client_id'])) {
                return ['ok' => false, 'msg' => 'Preencha as credenciais antes de ativar.'];
            }
            if (empty($g['api_key'])) {
                return ['ok' => false, 'msg' => 'Chave de API ausente. Preencha antes de ativar.'];
            }
        }

        $this->db->prepare("UPDATE pgto_gateways SET ativo = ?, atualizado_em = NOW() WHERE id = ?")
                 ->execute([$ativar ? 1 : 0, $id]);

        return ['ok' => true, 'msg' => $ativar ? 'Adquirente ativada.' : 'Adquirente desativada.'];
    }

    /**
     * A adquirente está sendo usada por algum fluxo publicado?
     * Desativar uma que está no grafo deixa o roteamento sem saída.
     */
    public function emUsoPorFluxo(string $codigo): array
    {
        $st = $this->db->prepare(
            "SELECT DISTINCT f.id, f.nome, f.metodo_codigo, f.versao
               FROM pgto_fluxo_nos n
               JOIN pgto_fluxos f ON f.id = n.fluxo_id
              WHERE f.status = 'publicado'
                AND n.tipo = 'tentar_adquirente'
                AND JSON_UNQUOTE(JSON_EXTRACT(n.config, '$.adquirente')) = ?"
        );
        $st->execute([$codigo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
