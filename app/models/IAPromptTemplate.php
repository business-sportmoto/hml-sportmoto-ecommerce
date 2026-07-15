<?php
/**
 * IAPromptTemplate — acesso a ia_prompt_templates (ângulos criativos).
 * Ângulo específico do tipo prevalece sobre o genérico (tipo_conteudo_id NULL).
 */
class IAPromptTemplate
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Lista de ângulos ativos para o select (deduplicada por angulo). */
    public function listarAngulos(): array
    {
        try {
            $sql = 'SELECT angulo, MIN(nome) AS nome
                      FROM ia_prompt_templates
                     WHERE ativo = 1
                  GROUP BY angulo
                  ORDER BY nome ASC';
            return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_tpl_listar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Template do ângulo: específico do tipo primeiro, genérico como fallback. */
    public function buscarPorAngulo(string $angulo, int $tipoConteudoId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, angulo, nome, corpo
                   FROM ia_prompt_templates
                  WHERE angulo = :angulo AND ativo = 1
                    AND (tipo_conteudo_id = :tipo OR tipo_conteudo_id IS NULL)
               ORDER BY (tipo_conteudo_id IS NULL) ASC
                  LIMIT 1'
            );
            $stmt->execute([':angulo' => $angulo, ':tipo' => $tipoConteudoId]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_tpl_buscar_erro', ['angulo' => $angulo, 'erro' => $e->getMessage()]);
            return null;
        }
    }
}
