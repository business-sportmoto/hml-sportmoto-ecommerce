<?php
declare(strict_types=1);

/**
 * VeiculoService
 * Gerencia a "Minha Garagem" do cliente.
 *
 * - Suporta múltiplas motos por cliente
 * - Apenas uma moto fica como "ativa" por vez (principal)
 * - O veículo ativo aparece em $_SESSION['meu_veiculo']
 * - Compatibilidade verificada apenas para cliente logado
 */
class VeiculoService {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Lista todas as motos da garagem do cliente ──────────
    public function listarPorCliente(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT cv.*,
                    mm.nome AS montadora_nome,
                    mm.slug AS montadora_slug,
                    mm.thumb AS montadora_thumb,
                    mo.nome AS modelo_nome,
                    mo.slug AS modelo_slug,
                    mo.thumb AS modelo_thumb
             FROM cliente_veiculos cv
             JOIN moto_montadoras  mm ON mm.id = cv.montadora_id
             LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
             WHERE cv.cliente_id = ?
             ORDER BY cv.principal DESC, cv.criado_em DESC"
        );
        $stmt->execute([$clienteId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['label'] = $this->buildLabel($r, $r['ano']);
        }
        return $rows;
    }

    // ── Veículo ativo ───────────────────────────────────────
    public function getAtivo(): ?array {
        return $_SESSION['meu_veiculo'] ?? null;
    }

    // ── Adicionar nova moto à garagem ───────────────────────
    public function adicionar(
        int     $clienteId,
        int     $montadoraId,
        ?int    $modeloId,
        ?int    $ano,
        string  $apelido = '',
        ?string $cor     = null,
        ?string $placa   = null,
        bool    $tornarAtivo = true
    ): array {
        if (!$montadoraId) {
            throw new \InvalidArgumentException('Montadora obrigatória.');
        }

        // Busca info para o label
        $stmt = $this->db->prepare(
            "SELECT mm.nome AS montadora_nome, mo.nome AS modelo_nome
             FROM moto_montadoras mm
             LEFT JOIN moto_modelos mo ON mo.id = ?
             WHERE mm.id = ? LIMIT 1"
        );
        $stmt->execute([$modeloId ?: null, $montadoraId]);
        $info = $stmt->fetch();
        if (!$info) {
            throw new \InvalidArgumentException('Montadora inválida.');
        }

        // Se vai ser principal, desativa as outras
        if ($tornarAtivo) {
            $this->db->prepare(
                "UPDATE cliente_veiculos SET principal=0 WHERE cliente_id=?"
            )->execute([$clienteId]);
        }

        $this->db->prepare(
            "INSERT INTO cliente_veiculos
             (cliente_id, montadora_id, modelo_id, ano, apelido, cor, placa, principal)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $clienteId, $montadoraId, $modeloId, $ano,
            $apelido ?: null, $cor, $placa,
            $tornarAtivo ? 1 : 0,
        ]);

        $id = (int)$this->db->lastInsertId();

        if ($tornarAtivo) $this->ativar($clienteId, $id);

        return $this->buscarPorId($id);
    }

    // ── Atualizar moto existente ────────────────────────────
    public function atualizar(int $clienteId, int $veiculoId, array $dados): bool {
        // Garante propriedade
        $stmt = $this->db->prepare(
            "SELECT id FROM cliente_veiculos WHERE id=? AND cliente_id=? LIMIT 1"
        );
        $stmt->execute([$veiculoId, $clienteId]);
        if (!$stmt->fetch()) return false;

        $campos = ['apelido', 'cor', 'placa', 'observacoes'];
        $sets   = [];
        $params = [];

        foreach ($campos as $c) {
            if (array_key_exists($c, $dados)) {
                $sets[]   = "{$c}=?";
                $params[] = $dados[$c] ?: null;
            }
        }

        if (empty($sets)) return false;

        $params[] = $veiculoId;
        $params[] = $clienteId;

        $sql = "UPDATE cliente_veiculos SET " . implode(', ', $sets)
             . " WHERE id=? AND cliente_id=?";
        $this->db->prepare($sql)->execute($params);

        // Atualiza sessão se for o ativo
        $ativo = $this->getAtivo();
        if ($ativo && (int)($ativo['id'] ?? 0) === $veiculoId) {
            $this->ativar($clienteId, $veiculoId);
        }

        return true;
    }

    // ── Definir moto como ativa ─────────────────────────────
    public function ativar(int $clienteId, int $veiculoId): bool {
        $stmt = $this->db->prepare(
            "SELECT cv.*,
                    mm.nome AS montadora_nome, mm.slug AS montadora_slug,
                    mo.nome AS modelo_nome,    mo.slug AS modelo_slug
             FROM cliente_veiculos cv
             JOIN moto_montadoras mm ON mm.id = cv.montadora_id
             LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
             WHERE cv.id=? AND cv.cliente_id=? LIMIT 1"
        );
        $stmt->execute([$veiculoId, $clienteId]);
        $row = $stmt->fetch();
        if (!$row) return false;

        // Marca como principal
        $this->db->prepare(
            "UPDATE cliente_veiculos SET principal=0 WHERE cliente_id=?"
        )->execute([$clienteId]);

        $this->db->prepare(
            "UPDATE cliente_veiculos SET principal=1 WHERE id=?"
        )->execute([$veiculoId]);

        // Atualiza sessão
        $_SESSION['meu_veiculo'] = [
            'id'             => (int)$row['id'],
            'montadora_id'   => (int)$row['montadora_id'],
            'modelo_id'      => $row['modelo_id'] ? (int)$row['modelo_id'] : null,
            'ano'            => $row['ano'] ? (int)$row['ano'] : null,
            'apelido'        => $row['apelido'],
            'cor'            => $row['cor'],
            'placa'          => $row['placa'],
            'montadora_nome' => $row['montadora_nome'],
            'montadora_slug' => $row['montadora_slug'],
            'modelo_nome'    => $row['modelo_nome'] ?? null,
            'modelo_slug'    => $row['modelo_slug'] ?? null,
            'label'          => $this->buildLabel($row, $row['ano']),
        ];

        return true;
    }

    // ── Remover moto da garagem ─────────────────────────────
    public function remover(int $clienteId, int $veiculoId): bool {
        $stmt = $this->db->prepare(
            "DELETE FROM cliente_veiculos WHERE id=? AND cliente_id=?"
        );
        $stmt->execute([$veiculoId, $clienteId]);

        if ($stmt->rowCount() === 0) return false;

        // Se era o ativo, limpa sessão e ativa outra (se houver)
        $ativo = $this->getAtivo();
        if ($ativo && (int)($ativo['id'] ?? 0) === $veiculoId) {
            unset($_SESSION['meu_veiculo']);

            // Tenta ativar a próxima moto disponível
            $stmt = $this->db->prepare(
                "SELECT id FROM cliente_veiculos
                 WHERE cliente_id=? ORDER BY criado_em DESC LIMIT 1"
            );
            $stmt->execute([$clienteId]);
            $proxima = $stmt->fetchColumn();
            if ($proxima) $this->ativar($clienteId, (int)$proxima);
        }

        return true;
    }

    // ── Carregar veículo ativo ao fazer login ───────────────
    public function carregarDoCliente(int $clienteId): void {
        $stmt = $this->db->prepare(
            "SELECT id FROM cliente_veiculos
             WHERE cliente_id=? AND principal=1 LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $id = $stmt->fetchColumn();
        if ($id) $this->ativar($clienteId, (int)$id);
    }

    // ── Limpar veículo ativo no logout ──────────────────────
    public function limparSessao(): void {
        unset($_SESSION['meu_veiculo']);
    }

    // ── Compatibilidade em lote ─────────────────────────────
    public function getProdutosCompativeisLote(array $produtoIds): array {
        $veiculo = $this->getAtivo();
        if (!$veiculo || empty($produtoIds)) return [];

        $montId = (int)$veiculo['montadora_id'];
        $modId  = $veiculo['modelo_id'] ? (int)$veiculo['modelo_id'] : null;
        $ano    = $veiculo['ano']       ? (int)$veiculo['ano']       : null;

        $in     = implode(',', array_fill(0, count($produtoIds), '?'));
        $params = $produtoIds;
        $params[]= $montId;

        $sub = "pc.produto_id IN ({$in}) AND pc.montadora_id = ?";

        if ($modId) {
            $sub .= " AND (pc.modelo_id = ? OR pc.modelo_id IS NULL)";
            $params[] = $modId;
        }
        if ($ano) {
            $sub .= " AND (
                (pc.ano_inicio IS NULL AND pc.ano_fim IS NULL)
                OR (pc.ano_inicio IS NULL AND pc.ano_fim   >= ?)
                OR (pc.ano_fim   IS NULL AND pc.ano_inicio <= ?)
                OR (pc.ano_inicio <= ?   AND pc.ano_fim    >= ?)
            )";
            $params[] = $ano; $params[] = $ano;
            $params[] = $ano; $params[] = $ano;
        }

        $stmt = $this->db->prepare(
            "SELECT DISTINCT produto_id FROM produto_compatibilidade pc WHERE {$sub}"
        );
        $stmt->execute($params);
        return array_column($stmt->fetchAll(), 'produto_id');
    }

    public function isProdutoCompativel(int $produtoId): bool {
        return !empty($this->getProdutosCompativeisLote([$produtoId]));
    }

    // ── Helpers ─────────────────────────────────────────────
    private function buscarPorId(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT cv.*,
                    mm.nome AS montadora_nome, mm.slug AS montadora_slug,
                    mo.nome AS modelo_nome,    mo.slug AS modelo_slug
             FROM cliente_veiculos cv
             JOIN moto_montadoras mm ON mm.id = cv.montadora_id
             LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
             WHERE cv.id=? LIMIT 1"
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if ($r) $r['label'] = $this->buildLabel($r, $r['ano']);
        return $r ?: null;
    }

    private function buildLabel(array $info, ?int $ano): string {
        $parts = [$info['montadora_nome'] ?? ''];
        if (!empty($info['modelo_nome'])) $parts[] = $info['modelo_nome'];
        if ($ano) $parts[] = (string)$ano;
        return implode(' · ', array_filter($parts));
    }
}