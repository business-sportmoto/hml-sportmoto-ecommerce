<?php
/**
 * IAPromptTemplate — acesso a ia_prompt_templates.
 *
 * Duas naturezas na mesma tabela:
 *  - 'angulo': modificador de tom injetado no prompt montado (Fase 1, só
 *    texto). Ângulo específico do tipo prevalece sobre o genérico.
 *  - 'prompt': prompt COMPLETO da biblioteca — texto, imagem ou vídeo —
 *    que ocupa o lugar da montagem automática. Um padrão por tipo de
 *    conteúdo, garantido pelo índice único sobre a coluna virtual
 *    padrao_tipo (migration 2026-09-11_ia_prompt_biblioteca).
 *
 * Regras de negócio (validação, marcadores, procedência) ficam no
 * IAPromptService. Aqui é só persistência.
 */
class IAPromptTemplate
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /* ------------------------------------------------------------------ */
    /* Ângulos (Fase 1)                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Lista de ângulos ativos para o select (deduplicada por angulo).
     * O filtro de natureza é o que impede um prompt salvo — que não tem
     * ângulo — de aparecer como uma opção em branco no select.
     */
    public function listarAngulos(): array
    {
        try {
            $sql = "SELECT angulo, MIN(nome) AS nome
                      FROM ia_prompt_templates
                     WHERE ativo = 1 AND natureza = 'angulo'
                  GROUP BY angulo
                  ORDER BY nome ASC";
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
                "SELECT id, angulo, nome, corpo
                   FROM ia_prompt_templates
                  WHERE angulo = :angulo AND ativo = 1 AND natureza = 'angulo'
                    AND (tipo_conteudo_id = :tipo OR tipo_conteudo_id IS NULL)
               ORDER BY (tipo_conteudo_id IS NULL) ASC
                  LIMIT 1"
            );
            $stmt->execute([':angulo' => $angulo, ':tipo' => $tipoConteudoId]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_tpl_buscar_erro', ['angulo' => $angulo, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Biblioteca — leitura                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Lista com filtros: natureza, capacidade, origem, tipo_conteudo_id, busca.
     * Prompts antes de ângulos; dentro deles, padrão e mais usados primeiro.
     */
    public function listarBiblioteca(array $f): array
    {
        $where  = ['1 = 1'];
        $params = [];

        if (in_array($f['natureza'] ?? '', ['angulo', 'prompt'], true)) {
            $where[] = 'p.natureza = :natureza';
            $params[':natureza'] = $f['natureza'];
        }
        if (in_array($f['capacidade'] ?? '', ['texto', 'imagem', 'video'], true)) {
            $where[] = 'p.capacidade = :capacidade';
            $params[':capacidade'] = $f['capacidade'];
        }
        if (in_array($f['origem'] ?? '', ['sistema', 'manual', 'imagem'], true)) {
            $where[] = 'p.origem = :origem';
            $params[':origem'] = $f['origem'];
        }
        if ((int) ($f['tipo_conteudo_id'] ?? 0) > 0) {
            $where[] = 'p.tipo_conteudo_id = :tipo';
            $params[':tipo'] = (int) $f['tipo_conteudo_id'];
        }
        $busca = trim((string) ($f['busca'] ?? ''));
        if ($busca !== '') {
            // Um placeholder por ocorrência: sem emulação de prepare, o
            // MySQL não aceita o mesmo nome repetido.
            $where[] = '(p.nome LIKE :b1 OR p.descricao LIKE :b2 OR p.corpo LIKE :b3)';
            $like = '%' . $busca . '%';
            $params[':b1'] = $like;
            $params[':b2'] = $like;
            $params[':b3'] = $like;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT p.id, p.tipo_conteudo_id, p.natureza, p.capacidade, p.angulo, p.nome,
                        p.descricao, p.corpo, p.ativo, p.padrao, p.origem, p.geracao_id,
                        p.imagem_arquivo_id, p.usos, p.usado_em, p.criado_em, p.atualizado_em,
                        t.nome AS tipo_nome, u.nome AS autor_nome
                   FROM ia_prompt_templates p
              LEFT JOIN ia_tipos_conteudo t ON t.id = p.tipo_conteudo_id
              LEFT JOIN usuarios u ON u.id = p.criado_por
                  WHERE ' . implode(' AND ', $where) . "
               ORDER BY (p.natureza = 'prompt') DESC, p.padrao DESC, p.ativo DESC, p.usos DESC, p.nome ASC
                  LIMIT 300"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_tpl_biblioteca_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Contagens do topo da biblioteca. */
    public function resumo(): array
    {
        $vazio = ['prompts' => 0, 'angulos' => 0, 'padroes' => 0, 'de_imagem' => 0, 'usos' => 0];
        try {
            $l = $this->db->query(
                "SELECT SUM(natureza = 'prompt')                AS prompts,
                        SUM(natureza = 'angulo')                AS angulos,
                        SUM(natureza = 'prompt' AND padrao = 1) AS padroes,
                        SUM(origem = 'imagem')                  AS de_imagem,
                        COALESCE(SUM(usos), 0)                  AS usos
                   FROM ia_prompt_templates"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            return array_merge($vazio, array_map('intval', array_filter($l, 'is_numeric')));
        } catch (Throwable $e) {
            LogService::error('ia_tpl_resumo_erro', ['erro' => $e->getMessage()]);
            return $vazio;
        }
    }

    public function buscar(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT p.*, t.nome AS tipo_nome, t.capacidade AS tipo_capacidade
                   FROM ia_prompt_templates p
              LEFT JOIN ia_tipos_conteudo t ON t.id = p.tipo_conteudo_id
                  WHERE p.id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_tpl_buscar_id_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Prompt que o enfileiramento pode usar: ativo, completo e da capacidade certa. */
    public function buscarPromptUsavel(int $id, string $capacidade): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, nome, corpo, capacidade, tipo_conteudo_id
                   FROM ia_prompt_templates
                  WHERE id = :id AND ativo = 1 AND natureza = 'prompt' AND capacidade = :cap
                  LIMIT 1"
            );
            $stmt->execute([':id' => $id, ':cap' => $capacidade]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_tpl_usavel_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Prompts ativos para o select do Gerar (a tela filtra por tipo). */
    public function paraGerar(): array
    {
        try {
            return $this->db->query(
                "SELECT id, nome, descricao, corpo, capacidade, tipo_conteudo_id, padrao
                   FROM ia_prompt_templates
                  WHERE natureza = 'prompt' AND ativo = 1
               ORDER BY padrao DESC, usos DESC, nome ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_tpl_para_gerar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Já existe ângulo com o mesmo código para o mesmo tipo (NULL-safe)? */
    public function anguloDuplicado(string $angulo, ?int $tipoId, ?int $ignorarId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM ia_prompt_templates
                  WHERE natureza = 'angulo' AND angulo = :a
                    AND tipo_conteudo_id <=> :t AND id <> :i"
            );
            $stmt->execute([':a' => $angulo, ':t' => $tipoId, ':i' => (int) $ignorarId]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            LogService::error('ia_tpl_angulo_dup_erro', ['erro' => $e->getMessage()]);
            return true; // na dúvida, não deixa duplicar
        }
    }

    /** Quantas gerações apontam para este prompt (bloqueia exclusão). */
    public function emUso(int $id): int
    {
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ia_geracoes WHERE prompt_template_id = :id');
            $stmt->execute([':id' => $id]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            LogService::error('ia_tpl_em_uso_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return 1; // na dúvida, trata como usado
        }
    }

    /* ------------------------------------------------------------------ */
    /* Biblioteca — escrita                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Cria (id null) ou atualiza. Na atualização, natureza, origem,
     * procedência e autor NÃO mudam — são fatos de nascimento do prompt.
     *
     * Padrão: o índice único recusaria o segundo padrão do tipo, então o
     * anterior é desmarcado ANTES, na mesma transação. Devolve o id ou 0.
     */
    public function salvar(?int $id, array $d): int
    {
        try {
            $this->db->beginTransaction();

            if (!empty($d['padrao']) && !empty($d['tipo_conteudo_id'])) {
                $this->desmarcarPadrao((int) $d['tipo_conteudo_id'], $id);
            }

            if ($id === null) {
                $stmt = $this->db->prepare(
                    'INSERT INTO ia_prompt_templates
                        (tipo_conteudo_id, natureza, capacidade, angulo, nome, descricao, corpo,
                         ativo, padrao, origem, geracao_id, imagem_arquivo_id, criado_por)
                     VALUES
                        (:tipo, :natureza, :cap, :angulo, :nome, :descricao, :corpo,
                         :ativo, :padrao, :origem, :geracao, :arquivo, :autor)'
                );
                $stmt->execute([
                    ':tipo'      => $d['tipo_conteudo_id'] ?: null,
                    ':natureza'  => $d['natureza'],
                    ':cap'       => $d['capacidade'],
                    ':angulo'    => $d['angulo'],
                    ':nome'      => $d['nome'],
                    ':descricao' => $d['descricao'] !== '' ? $d['descricao'] : null,
                    ':corpo'     => $d['corpo'],
                    ':ativo'     => (int) $d['ativo'],
                    ':padrao'    => !empty($d['padrao']) ? 1 : 0,
                    ':origem'    => $d['origem'],
                    ':geracao'   => $d['geracao_id'] ?: null,
                    ':arquivo'   => $d['imagem_arquivo_id'] ?: null,
                    ':autor'     => $d['criado_por'] ?: null,
                ]);
                $id = (int) $this->db->lastInsertId();
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE ia_prompt_templates SET
                        tipo_conteudo_id = :tipo,
                        capacidade       = :cap,
                        angulo           = :angulo,
                        nome             = :nome,
                        descricao        = :descricao,
                        corpo            = :corpo,
                        ativo            = :ativo,
                        padrao           = :padrao
                      WHERE id = :id LIMIT 1'
                );
                $stmt->execute([
                    ':tipo'      => $d['tipo_conteudo_id'] ?: null,
                    ':cap'       => $d['capacidade'],
                    ':angulo'    => $d['angulo'],
                    ':nome'      => $d['nome'],
                    ':descricao' => $d['descricao'] !== '' ? $d['descricao'] : null,
                    ':corpo'     => $d['corpo'],
                    ':ativo'     => (int) $d['ativo'],
                    ':padrao'    => !empty($d['padrao']) ? 1 : 0,
                    ':id'        => $id,
                ]);
            }

            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            LogService::error('ia_tpl_salvar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Liga/desliga. Desligar um padrão tira o padrão junto — um prompt
     * desativado não pode continuar entrando sozinho no campo do Gerar.
     * (No UPDATE do MySQL as atribuições valem da esquerda para a direita:
     * o `ativo` do IF já é o valor novo.) Devolve o novo ativo ou null.
     */
    public function alternar(int $id): ?int
    {
        try {
            $this->db->prepare(
                'UPDATE ia_prompt_templates
                    SET ativo = 1 - ativo, padrao = IF(ativo = 0, 0, padrao)
                  WHERE id = :id LIMIT 1'
            )->execute([':id' => $id]);

            $stmt = $this->db->prepare('SELECT ativo FROM ia_prompt_templates WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (int) $v;
        } catch (Throwable $e) {
            LogService::error('ia_tpl_alternar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Torna o prompt o padrão do tipo (e ativo), desmarcando o anterior. */
    public function definirPadrao(int $id, int $tipoId): bool
    {
        try {
            $this->db->beginTransaction();
            $this->desmarcarPadrao($tipoId, $id);
            $this->db->prepare(
                'UPDATE ia_prompt_templates SET padrao = 1, ativo = 1 WHERE id = :id LIMIT 1'
            )->execute([':id' => $id]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            LogService::error('ia_tpl_padrao_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    public function excluir(int $id): bool
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM ia_prompt_templates WHERE id = :id AND origem <> 'sistema' LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            LogService::error('ia_tpl_excluir_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /** Conta um uso do prompt (enfileiramento concluído). */
    public function registrarUso(int $id): void
    {
        try {
            $this->db->prepare(
                'UPDATE ia_prompt_templates SET usos = usos + 1, usado_em = NOW() WHERE id = :id LIMIT 1'
            )->execute([':id' => $id]);
        } catch (Throwable $e) {
            LogService::error('ia_tpl_uso_erro', ['id' => $id, 'erro' => $e->getMessage()]);
        }
    }

    private function desmarcarPadrao(int $tipoId, ?int $exceto): void
    {
        $this->db->prepare(
            "UPDATE ia_prompt_templates
                SET padrao = 0
              WHERE natureza = 'prompt' AND tipo_conteudo_id = :t AND padrao = 1 AND id <> :e"
        )->execute([':t' => $tipoId, ':e' => (int) $exceto]);
    }
}
