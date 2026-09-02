<?php
/**
 * IALimite — acesso a ia_limites.
 *
 * escopo 'global' usa referencia_id = 0 (UNIQUE em escopo+referencia_id
 * garante uma única linha global). escopo 'usuario' usa usuarios.id.
 */
class IALimite
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Lista limites com o nome do usuário quando escopo = usuario.
     * AJUSTE: se a coluna de nome em `usuarios` for outra, altere u.nome abaixo.
     */
    public function listar(): array
    {
        try {
            $sql = "SELECT l.*, u.nome AS usuario_nome
                      FROM ia_limites l
                 LEFT JOIN usuarios u ON u.id = l.referencia_id AND l.escopo = 'usuario'
                  ORDER BY (l.escopo = 'global') DESC, u.nome ASC, l.referencia_id ASC";

            $linhas = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return $linhas ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_limite_listar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    public function buscar(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM ia_limites WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_limite_buscar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Limite global vigente (ativo). */
    public function globalVigente(): ?array
    {
        try {
            $sql   = "SELECT * FROM ia_limites WHERE escopo = 'global' AND ativo = 1 LIMIT 1";
            $linha = $this->db->query($sql)->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_limite_global_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Upsert por (escopo, referencia_id). $dados esperado:
     *  escopo, referencia_id, limite_diario_usd, limite_mensal_usd,
     *  limite_geracoes_minuto, alerta_percentual, ativo
     */
    public function salvar(array $dados): bool
    {
        try {
            $sql = 'INSERT INTO ia_limites
                        (escopo, referencia_id, limite_diario_usd, limite_mensal_usd,
                         limite_geracoes_minuto, alerta_percentual, ativo)
                    VALUES
                        (:escopo, :referencia_id, :diario, :mensal, :minuto, :alerta, :ativo)
                    ON DUPLICATE KEY UPDATE
                        limite_diario_usd      = VALUES(limite_diario_usd),
                        limite_mensal_usd      = VALUES(limite_mensal_usd),
                        limite_geracoes_minuto = VALUES(limite_geracoes_minuto),
                        alerta_percentual      = VALUES(alerta_percentual),
                        ativo                  = VALUES(ativo)';

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':escopo'        => $dados['escopo'],
                ':referencia_id' => (int) $dados['referencia_id'],
                ':diario'        => $dados['limite_diario_usd'],
                ':mensal'        => $dados['limite_mensal_usd'],
                ':minuto'        => $dados['limite_geracoes_minuto'],
                ':alerta'        => (int) $dados['alerta_percentual'],
                ':ativo'         => (int) $dados['ativo'],
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_limite_salvar_erro', ['erro' => $e->getMessage()]);
            return false;
        }
    }

    /** Exclui um limite. A linha global é protegida — edite em vez de excluir. */
    public function excluir(int $id): array
    {
        $limite = $this->buscar($id);
        if ($limite === null) {
            return ['ok' => false, 'msg' => 'Limite não encontrado.'];
        }

        if ($limite['escopo'] === 'global') {
            return ['ok' => false, 'msg' => 'O limite global não pode ser excluído — edite os valores.'];
        }

        try {
            $stmt = $this->db->prepare('DELETE FROM ia_limites WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            return ['ok' => true, 'msg' => 'Limite excluído.'];
        } catch (Throwable $e) {
            LogService::error('ia_limite_excluir_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro ao excluir o limite.'];
        }
    }
}
