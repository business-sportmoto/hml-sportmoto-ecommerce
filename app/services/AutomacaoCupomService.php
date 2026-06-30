<?php
/**
 * app/services/AutomacaoCupomService.php
 *
 * Gera cupons únicos por cliente para uso nas automações.
 */
class AutomacaoCupomService
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Gera ou recupera cupom de aniversário para o cliente.
     * Tipo 'exclusivo', válido N dias, % configurável.
     *
     * @return array{id:int, codigo:string}
     */
    public function gerarAniversario(int $clienteId, float $pct = 10.0, int $diasValidade = 7): array
    {
        $ano = date('Y');
        $codigo = 'ANIV-' . $this->prefixoCliente($clienteId) . '-' . $ano;

        // Reutiliza se já existir e ainda válido
        $existente = $this->buscarPorCodigo($codigo);
        if ($existente && empty($existente['deleted_at'])) {
            return ['id' => (int)$existente['id'], 'codigo' => $codigo];
        }

        $inicio = date('Y-m-d H:i:s');
        $fim    = date('Y-m-d H:i:s', strtotime("+{$diasValidade} days"));

        return $this->inserir([
            'codigo'          => $codigo,
            'nome'            => 'Cupom de Aniversário ' . $ano,
            'descricao'       => 'Gerado automaticamente — aniversário do cliente #' . $clienteId,
            'tipo'            => 'exclusivo',
            'valor'           => $pct,
            'valor_minimo_pedido' => 0,
            'ativo'           => 1,
            'data_inicio'     => $inicio,
            'data_fim'        => $fim,
            'limite_total'    => 1,
            'limite_por_cliente' => 1,
            'escopo_clientes' => json_encode([$clienteId]),
        ]);
    }

    /**
     * Gera cupom de reengajamento único por cliente.
     * Tipo 'recuperacao_carrinho'.
     */
    public function gerarReengajamento(int $clienteId, float $pct = 10.0, int $diasValidade = 15): array
    {
        // Código único por cliente + hash curto
        $hash   = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $codigo = 'VOLT-' . $this->prefixoCliente($clienteId) . '-' . $hash;

        $inicio = date('Y-m-d H:i:s');
        $fim    = date('Y-m-d H:i:s', strtotime("+{$diasValidade} days"));

        return $this->inserir([
            'codigo'          => $codigo,
            'nome'            => 'Cupom de Reengajamento',
            'descricao'       => 'Gerado automaticamente — reengajamento cliente #' . $clienteId,
            'tipo'            => 'recuperacao_carrinho',
            'valor'           => $pct,
            'valor_minimo_pedido' => 0,
            'ativo'           => 1,
            'data_inicio'     => $inicio,
            'data_fim'        => $fim,
            'limite_total'    => 1,
            'limite_por_cliente' => 1,
            'escopo_clientes' => json_encode([$clienteId]),
        ]);
    }

    /**
     * Verifica se o cupom de aniversário do cliente já foi utilizado.
     */
    public function aniversarioJaUsado(int $clienteId): bool
    {
        $ano    = date('Y');
        $codigo = 'ANIV-' . $this->prefixoCliente($clienteId) . '-' . $ano;

        $st = $this->db->prepare(
            "SELECT total_usos FROM cupons
             WHERE codigo = :c AND deleted_at IS NULL LIMIT 1"
        );
        $st->execute([':c' => $codigo]);
        $usos = $st->fetchColumn();
        return $usos !== false && (int)$usos > 0;
    }

    // -------------------------------------------------------------------------

    private function prefixoCliente(int $clienteId): string
    {
        // Busca primeiras letras do nome do cliente
        $st = $this->db->prepare(
            "SELECT u.nome FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.id = :id LIMIT 1"
        );
        $st->execute([':id' => $clienteId]);
        $nome = $st->fetchColumn() ?: 'CLI';
        $partes = explode(' ', strtoupper(trim($nome)));
        $prefix = substr($partes[0], 0, 4);
        if (count($partes) > 1) $prefix .= substr($partes[1], 0, 2);
        return preg_replace('/[^A-Z0-9]/', '', $prefix) ?: 'CLI' . $clienteId;
    }

    private function buscarPorCodigo(string $codigo): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM cupons WHERE codigo = :c LIMIT 1"
        );
        $st->execute([':c' => $codigo]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function inserir(array $dados): array
    {
        $sql = "INSERT INTO cupons
                (codigo, nome, descricao, tipo, valor, valor_minimo_pedido,
                 ativo, data_inicio, data_fim, limite_total, limite_por_cliente,
                 escopo_clientes)
                VALUES
                (:cod, :nom, :desc, :tipo, :val, :vmin,
                 :at, :di, :df, :lt, :lpc, :ec)";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':cod'  => $dados['codigo'],
            ':nom'  => $dados['nome'],
            ':desc' => $dados['descricao'],
            ':tipo' => $dados['tipo'],
            ':val'  => $dados['valor'],
            ':vmin' => $dados['valor_minimo_pedido'],
            ':at'   => $dados['ativo'],
            ':di'   => $dados['data_inicio'],
            ':df'   => $dados['data_fim'],
            ':lt'   => $dados['limite_total'],
            ':lpc'  => $dados['limite_por_cliente'],
            ':ec'   => $dados['escopo_clientes'] ?? null,
        ]);
        return ['id' => (int)$this->db->lastInsertId(), 'codigo' => $dados['codigo']];
    }
}
