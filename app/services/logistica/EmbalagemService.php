<?php
/**
 * EmbalagemService — catálogo de embalagens (caixas/envelopes).
 *
 * O empacotador da Calculadora consome ativas() para escolher volumes.
 * CRUD enxuto com allowlist; a tela de gestão é opcional (as embalagens
 * seed já vêm na migração).
 */
class EmbalagemService
{
    private PDO $pdo;

    private const CAMPOS = ['nome', 'tipo', 'peso_g', 'altura_cm', 'largura_cm', 'comprimento_cm', 'peso_max_g', 'ativo'];
    private const TIPOS  = ['caixa', 'envelope', 'rolo', 'pallet'];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /** Embalagens ativas, da menor para a maior (volume). */
    public function ativas(): array
    {
        try {
            return $this->pdo->query(
                "SELECT * FROM log_embalagens WHERE ativo = 1
                 ORDER BY (altura_cm * largura_cm * comprimento_cm) ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar embalagens ativas', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    public function listar(): array
    {
        try {
            return $this->pdo->query("SELECT * FROM log_embalagens ORDER BY ativo DESC, nome ASC")
                             ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar embalagens', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    public function salvar(array $d, ?int $usuarioId = null): array
    {
        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '') return ['ok' => false, 'erro' => 'Informe o nome da embalagem.'];
        $tipo = in_array($d['tipo'] ?? '', self::TIPOS, true) ? $d['tipo'] : 'caixa';

        $campos = [
            'nome'           => $nome,
            'tipo'           => $tipo,
            'peso_g'         => max(0, (int)($d['peso_g'] ?? 0)),
            'altura_cm'      => round((float)($d['altura_cm'] ?? 0), 2),
            'largura_cm'     => round((float)($d['largura_cm'] ?? 0), 2),
            'comprimento_cm' => round((float)($d['comprimento_cm'] ?? 0), 2),
            'peso_max_g'     => ($d['peso_max_g'] ?? '') === '' ? null : max(0, (int)$d['peso_max_g']),
            'ativo'          => !empty($d['ativo']) ? 1 : 0,
        ];
        $id = (int)($d['id'] ?? 0);

        try {
            if ($id > 0) {
                $sets = implode(', ', array_map(static fn($c) => "`$c` = :$c", self::CAMPOS));
                $stmt = $this->pdo->prepare("UPDATE log_embalagens SET $sets WHERE id = :id");
                $campos['id'] = $id;
                $stmt->execute($campos);
            } else {
                $cols = self::CAMPOS;
                $stmt = $this->pdo->prepare("INSERT INTO log_embalagens (`" . implode('`,`', $cols) . "`) VALUES (:" . implode(',:', $cols) . ")");
                $stmt->execute($campos);
                $id = (int)$this->pdo->lastInsertId();
            }
        } catch (\Throwable $e) {
            LogService::error('Falha ao salvar embalagem', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar embalagem.'];
        }
        LogService::audit($id ? 'Embalagem salva' : 'Embalagem criada', ['embalagem_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id];
    }

    public function alternar(int $id, bool $ativo, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->prepare("UPDATE log_embalagens SET ativo = :a WHERE id = :id")
                      ->execute([':a' => $ativo ? 1 : 0, ':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Não foi possível alterar.'];
        }
        LogService::audit('Embalagem ' . ($ativo ? 'ativada' : 'desativada'), ['embalagem_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    public function remover(int $id, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->prepare("DELETE FROM log_embalagens WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Não foi possível remover.'];
        }
        LogService::audit('Embalagem removida', ['embalagem_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }
}
