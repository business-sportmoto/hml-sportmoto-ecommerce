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
            $sql = "SELECT id, codigo, nome, grupo, capacidade, campos_briefing, max_tokens
                      FROM ia_tipos_conteudo
                     WHERE ativo = 1 AND grupo <> 'sistema'
                  ORDER BY grupo ASC, ordem ASC, nome ASC";
            return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_tipo_listar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Cria um tipo. Usado pelo catálogo de agentes (tipos de sistema com
     * capacidade `agente`); os tipos de conteúdo de marketing continuam
     * vindo das migrations.
     * $d: codigo, nome, grupo, capacidade, saida, modelo_id(?),
     *     instrucoes_sistema, ferramentas(array|null), max_tokens, ordem, ativo
     */
    public function criar(array $d): int
    {
        $st = $this->db->prepare(
            'INSERT INTO ia_tipos_conteudo
                (codigo, nome, grupo, capacidade, saida, modelo_id, campos_briefing,
                 instrucoes_sistema, ferramentas, max_tokens, ordem, ativo)
             VALUES
                (:codigo, :nome, :grupo, :capacidade, :saida, :modelo_id, NULL,
                 :instrucoes, :ferramentas, :max_tokens, :ordem, :ativo)'
        );
        $st->execute([
            ':codigo'      => $d['codigo'],
            ':nome'        => $d['nome'],
            ':grupo'       => $d['grupo'] ?? 'sistema',
            ':capacidade'  => $d['capacidade'] ?? 'texto',
            ':saida'       => $d['saida'] ?? 'texto',
            ':modelo_id'   => $d['modelo_id'] ?? null,
            ':instrucoes'  => $d['instrucoes_sistema'] ?? null,
            ':ferramentas' => isset($d['ferramentas']) ? json_encode(array_values($d['ferramentas']), JSON_UNESCAPED_UNICODE) : null,
            ':max_tokens'  => $d['max_tokens'] ?? null,
            ':ordem'       => (int) ($d['ordem'] ?? 0),
            ':ativo'       => (int) ($d['ativo'] ?? 1),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Atualiza os campos editáveis de um tipo (o código não muda). */
    public function atualizar(int $id, array $d): bool
    {
        $st = $this->db->prepare(
            'UPDATE ia_tipos_conteudo SET
                nome = :nome, modelo_id = :modelo_id, instrucoes_sistema = :instrucoes,
                ferramentas = :ferramentas, max_tokens = :max_tokens, ativo = :ativo
              WHERE id = :id LIMIT 1'
        );
        return $st->execute([
            ':nome'        => $d['nome'],
            ':modelo_id'   => $d['modelo_id'] ?? null,
            ':instrucoes'  => $d['instrucoes_sistema'] ?? null,
            ':ferramentas' => isset($d['ferramentas']) ? json_encode(array_values($d['ferramentas']), JSON_UNESCAPED_UNICODE) : null,
            ':max_tokens'  => $d['max_tokens'] ?? null,
            ':ativo'       => (int) ($d['ativo'] ?? 1),
            ':id'          => $id,
        ]);
    }

    /** Tipos internos (grupo sistema) são localizados pelo código. */
    public function buscarPorCodigo(string $codigo): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM ia_tipos_conteudo WHERE codigo = :c LIMIT 1');
            $stmt->execute([':c' => $codigo]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            return $t ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_tipo_codigo_erro', ['codigo' => $codigo, 'erro' => $e->getMessage()]);
            return null;
        }
    }
}
