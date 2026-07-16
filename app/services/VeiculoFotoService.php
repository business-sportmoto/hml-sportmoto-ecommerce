<?php
declare(strict_types=1);

class VeiculoFotoService {

    private const MAX_FOTOS_POR_MOTO   = 10;
    private const MAX_UPLOADS_POR_HORA = 30;
    private const SUBDIR               = 'garagem';

    private PDO $db;
    private ImageProcessorService $img;

    public function __construct() {
        $this->db  = Database::getInstance()->getConnection();
        $this->img = new ImageProcessorService();
    }

    // ── Listar fotos da moto (do dono) ──────────────────────
    public function listarPorVeiculo(int $clienteId, int $veiculoId): array {
        if (!$this->garantirPropriedadeMoto($clienteId, $veiculoId)) return [];

        $stmt = $this->db->prepare(
            "SELECT * FROM cliente_veiculo_fotos
             WHERE veiculo_id = ?
             ORDER BY capa DESC, ordem ASC, criado_em DESC"
        );
        $stmt->execute([$veiculoId]);
        return $stmt->fetchAll();
    }

    // ── Upload ──────────────────────────────────────────────
    public function upload(int $clienteId, int $veiculoId, array $file, array $opts = []): array {
        if (!$this->garantirPropriedadeMoto($clienteId, $veiculoId)) {
            throw new \RuntimeException('Moto não encontrada.');
        }

        $this->checarRateLimit($clienteId);
        $this->checarLimiteFotos($veiculoId);

        // SEMPRE inicia como privado. Cliente promove pra público depois.
        $visibilidade = $opts['visibilidade'] ?? 'privado';
        if (!in_array($visibilidade, ['privado', 'publico'], true)) {
            $visibilidade = 'privado';
        }

        // Pública entra como pendente. Privada já fica aprovada.
        $statusModeracao = $visibilidade === 'publico' ? 'pendente' : 'aprovada';

        $resultado = $this->img->processar($file, self::SUBDIR);

        $isCapa = $this->contarFotos($veiculoId) === 0 ? 1 : 0;

        $this->db->prepare(
            "INSERT INTO cliente_veiculo_fotos
             (veiculo_id, cliente_id, arquivo_thumb, arquivo_medium, arquivo_full,
              largura, altura, tamanho_bytes,
              visibilidade, status_moderacao,
              legenda, capa, ip_upload)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $veiculoId, $clienteId,
            $resultado['thumb'], $resultado['medium'], $resultado['full'],
            $resultado['largura'], $resultado['altura'], $resultado['bytes'],
            $visibilidade, $statusModeracao,
            substr($opts['legenda'] ?? '', 0, 255) ?: null,
            $isCapa,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        $fotoId = (int)$this->db->lastInsertId();
        $this->logUpload($clienteId);

        return $this->buscarFoto($fotoId);
    }

    // ── Atualizar (legenda, visibilidade) ──────────────────
    public function atualizar(int $clienteId, int $fotoId, array $dados): bool {
        $foto = $this->buscarFotoDoCliente($clienteId, $fotoId);
        if (!$foto) return false;

        $sets   = [];
        $params = [];

        if (array_key_exists('legenda', $dados)) {
            $sets[]   = 'legenda = ?';
            $params[] = substr((string)$dados['legenda'], 0, 255) ?: null;
        }

        if (array_key_exists('visibilidade', $dados)) {
            $nova = in_array($dados['visibilidade'], ['privado','publico'], true)
                  ? $dados['visibilidade'] : 'privado';
            $sets[] = 'visibilidade = ?';
            $params[] = $nova;

            // Mudou pra pública: volta pra moderação
            // Mudou pra privada: volta a ser aprovada (não precisa moderação pra privado)
            if ($nova === 'publico' && $foto['visibilidade'] !== 'publico') {
                $sets[] = 'status_moderacao = ?';
                $params[] = 'pendente';
            } elseif ($nova === 'privado') {
                $sets[] = 'status_moderacao = ?';
                $params[] = 'aprovada';
            }
        }

        if (empty($sets)) return false;

        $params[] = $fotoId;
        $params[] = $clienteId;

        $sql = "UPDATE cliente_veiculo_fotos SET " . implode(', ', $sets)
             . " WHERE id = ? AND cliente_id = ?";
        $this->db->prepare($sql)->execute($params);
        
        NotificacaoService::criarBroadcast([
            'categoria' => 'sistema',
            'tipo'      => 'nova_foto_publica',
            'titulo'    => "Um cliente acabou de tornar sua foto publica",
            'url'       => "/admin/moderacao/fotos",
        ], 'todos_admins');

        return true;
    }

    // ── Definir capa ───────────────────────────────────────
    public function definirCapa(int $clienteId, int $fotoId): bool {
        $foto = $this->buscarFotoDoCliente($clienteId, $fotoId);
        if (!$foto) return false;

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE cliente_veiculo_fotos SET capa = 0 WHERE veiculo_id = ?"
            )->execute([$foto['veiculo_id']]);

            $this->db->prepare(
                "UPDATE cliente_veiculo_fotos SET capa = 1 WHERE id = ?"
            )->execute([$fotoId]);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Reordenar ──────────────────────────────────────────
    public function reordenar(int $clienteId, int $veiculoId, array $ordens): bool {
        if (!$this->garantirPropriedadeMoto($clienteId, $veiculoId)) return false;

        $stmt = $this->db->prepare(
            "UPDATE cliente_veiculo_fotos SET ordem = ?
             WHERE id = ? AND veiculo_id = ?"
        );
        foreach ($ordens as $ordem => $fotoId) {
            $stmt->execute([(int)$ordem, (int)$fotoId, $veiculoId]);
        }
        return true;
    }

    // ── Remover ────────────────────────────────────────────
    public function remover(int $clienteId, int $fotoId): bool {
        $foto = $this->buscarFotoDoCliente($clienteId, $fotoId);
        if (!$foto) return false;

        $this->img->deletar(self::SUBDIR, [
            $foto['arquivo_thumb'],
            $foto['arquivo_medium'],
            $foto['arquivo_full'],
        ]);

        $this->db->prepare(
            "DELETE FROM cliente_veiculo_fotos WHERE id = ? AND cliente_id = ?"
        )->execute([$fotoId, $clienteId]);

        // Se era capa, promove a próxima
        if ($foto['capa']) {
            $this->db->prepare(
                "UPDATE cliente_veiculo_fotos SET capa = 1
                 WHERE veiculo_id = ? ORDER BY ordem ASC, id ASC LIMIT 1"
            )->execute([$foto['veiculo_id']]);
        }

        return true;
    }

    // ── Feed público (futuro) ──────────────────────────────
    public function listarPublicas(int $limit = 24, int $offset = 0): array {
        $stmt = $this->db->prepare(
            "SELECT f.id, f.arquivo_thumb, f.arquivo_medium, f.legenda,
                    f.criado_em,
                    cv.id           AS veiculo_id,
                    mm.nome         AS montadora_nome,
                    mm.slug         AS montadora_slug,
                    mo.nome         AS modelo_nome,
                    mo.slug         AS modelo_slug,
                    cv.ano          AS moto_ano,
                    cv.apelido      AS moto_apelido,
                    c.nome          AS cliente_nome
             FROM cliente_veiculo_fotos f
             JOIN cliente_veiculos cv  ON cv.id = f.veiculo_id
             JOIN clientes c           ON c.id  = f.cliente_id
             JOIN moto_montadoras mm   ON mm.id = cv.montadora_id
             LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
             WHERE f.visibilidade = 'publico'
               AND f.status_moderacao = 'aprovada'
             ORDER BY f.criado_em DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $limit,  PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Helpers privados ───────────────────────────────────
    private function garantirPropriedadeMoto(int $clienteId, int $veiculoId): bool {
        $stmt = $this->db->prepare(
            "SELECT id FROM cliente_veiculos
             WHERE id = ? AND cliente_id = ? LIMIT 1"
        );
        $stmt->execute([$veiculoId, $clienteId]);
        return (bool)$stmt->fetchColumn();
    }

    private function buscarFotoDoCliente(int $clienteId, int $fotoId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cliente_veiculo_fotos
             WHERE id = ? AND cliente_id = ? LIMIT 1"
        );
        $stmt->execute([$fotoId, $clienteId]);
        return $stmt->fetch() ?: null;
    }

    private function buscarFoto(int $fotoId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM cliente_veiculo_fotos WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$fotoId]);
        return $stmt->fetch() ?: null;
    }

    private function contarFotos(int $veiculoId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cliente_veiculo_fotos WHERE veiculo_id = ?"
        );
        $stmt->execute([$veiculoId]);
        return (int)$stmt->fetchColumn();
    }

    private function checarLimiteFotos(int $veiculoId): void {
        if ($this->contarFotos($veiculoId) >= self::MAX_FOTOS_POR_MOTO) {
            throw new \RuntimeException(
                'Limite de ' . self::MAX_FOTOS_POR_MOTO . ' fotos por moto atingido.'
            );
        }
    }

    private function checarRateLimit(int $clienteId): void {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM upload_log
             WHERE cliente_id = ? AND tipo = 'garagem_foto'
               AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$clienteId]);
        $count = (int)$stmt->fetchColumn();
        if ($count >= self::MAX_UPLOADS_POR_HORA) {
            throw new \RuntimeException('Muitos uploads recentes. Aguarde alguns minutos.');
        }
    }

    private function logUpload(int $clienteId): void {
        $this->db->prepare(
            "INSERT INTO upload_log (cliente_id, tipo, ip)
             VALUES (?, 'garagem_foto', ?)"
        )->execute([$clienteId, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}