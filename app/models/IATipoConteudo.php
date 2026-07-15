<?php
/**
 * IATipoConteudo — acesso a ia_tipos_conteudo.
 */
class IATipoConteudo
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function buscar(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM ia_tipos_conteudo WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_tipo_buscar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Tipos ativos ordenados por grupo/ordem (monta o select da tela de geração). */
    public function listarAtivos(): array
    {
        try {
            $sql = 'SELECT id, codigo, nome, grupo, capacidade, campos_briefing, max_tokens
                      FROM ia_tipos_conteudo
                     WHERE ativo = 1
                  ORDER BY grupo ASC, ordem ASC, nome ASC';
            return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_tipo_listar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }
}
