<?php
/**
 * IAGeracao — acesso a ia_geracoes / ia_arquivos.
 * A tabela é fila E histórico: status = pipeline técnico, aprovacao = curadoria.
 */
class IAGeracao
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /* ------------------------------------------------------------------ */
    /* Escrita                                                             */
    /* ------------------------------------------------------------------ */

    /** Insere na fila. Retorna id, 0 em erro, ou -1062 quando a dedup barrar. */
    public function criar(array $d): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ia_geracoes
                    (uuid, usuario_id, produto_id, campanha_id, geracao_origem_id,
                     tipo_conteudo_id, capacidade, formato, angulo, prompt_template_id,
                     prompt_template_snapshot, prompt_final, contexto,
                     chave_dedup, custo_estimado_usd, status, aprovacao)
                 VALUES
                    (:uuid, :usuario_id, :produto_id, :campanha_id, :origem,
                     :tipo_id, :capacidade, :formato, :angulo, :template_id,
                     :template_snapshot, :prompt_final, :contexto,
                     :dedup, :custo_estimado, \'na_fila\', \'pendente\')'
            );
            $stmt->execute([
                ':uuid'              => $d['uuid'],
                ':usuario_id'        => $d['usuario_id'],
                ':produto_id'        => $d['produto_id'],
                ':campanha_id'       => $d['campanha_id'],
                ':origem'            => $d['geracao_origem_id'],
                ':tipo_id'           => $d['tipo_conteudo_id'],
                ':capacidade'        => $d['capacidade'],
                ':formato'           => $d['formato'] ?? null,
                ':angulo'            => $d['angulo'],
                ':template_id'       => $d['prompt_template_id'],
                ':template_snapshot' => $d['prompt_template_snapshot'],
                ':prompt_final'      => $d['prompt_final'],
                ':contexto'          => $d['contexto'],
                ':dedup'             => $d['chave_dedup'],
                ':custo_estimado'    => $d['custo_estimado_usd'],
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return -1062; // requisição duplicada (chave_dedup)
            }
            LogService::error('ia_geracao_criar_erro', ['erro' => $e->getMessage()]);
            return 0;
        } catch (Throwable $e) {
            LogService::error('ia_geracao_criar_erro', ['erro' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Reivindica um lote da fila para o worker (worker único via flock).
     * Devolve as linhas já com os dados do tipo necessários ao orquestrador.
     */
    public function reivindicarLote(int $quantidade): array
    {
        try {
            $limite = max(1, min(20, $quantidade));
            // LIMIT interpolado de propósito: com ATTR_EMULATE_PREPARES ativo o
            // bind viraria string quotada e quebraria a query. Valor é int validado.
            $stmt = $this->db->query(
                "SELECT id FROM ia_geracoes WHERE status = 'na_fila' ORDER BY id ASC LIMIT {$limite}"
            );
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($ids)) {
                return [];
            }

            $marcadores = implode(',', array_fill(0, count($ids), '?'));
            $upd = $this->db->prepare(
                "UPDATE ia_geracoes
                    SET status = 'processando', iniciado_em = NOW(), tentativas = tentativas + 1
                  WHERE id IN ({$marcadores}) AND status = 'na_fila'"
            );
            $upd->execute(array_map('intval', $ids));

            $sel = $this->db->prepare(
                "SELECT g.*, t.instrucoes_sistema, t.max_tokens, t.modelo_id AS tipo_modelo_id, t.nome AS tipo_nome
                   FROM ia_geracoes g
             INNER JOIN ia_tipos_conteudo t ON t.id = g.tipo_conteudo_id
                  WHERE g.id IN ({$marcadores}) AND g.status = 'processando'
               ORDER BY g.id ASC"
            );
            $sel->execute(array_map('intval', $ids));
            return $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_reivindicar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Watchdog: devolve à fila jobs presos e falha definitivamente após 3 tentativas.
     *
     * O `iniciado_em IS NULL` cobre um ponto cego: reivindicarLote() sempre
     * preenche a coluna, mas uma linha que chegue a 'processando' por fora
     * (SQL manual, script novo) fica com NULL — e `NULL < data` é NULL, nunca
     * verdadeiro. Sem essa cláusula a linha some das duas pontas: o claim só
     * pega 'na_fila' e o watchdog não a enxerga. Fica presa para sempre.
     * Sem hora de início conhecida, trata-se como parada.
     */
    public function recuperarPresos(int $minutos = 10): void
    {
        try {
            $this->db->prepare(
                "UPDATE ia_geracoes
                    SET status = 'na_fila'
                  WHERE status = 'processando'
                    AND (iniciado_em IS NULL OR iniciado_em < DATE_SUB(NOW(), INTERVAL {$minutos} MINUTE))
                    AND tentativas < 3"
            )->execute();

            $this->db->prepare(
                "UPDATE ia_geracoes
                    SET status = 'falhou', erro = '[watchdog] excedeu 3 tentativas sem conclusão', concluido_em = NOW()
                  WHERE status = 'processando'
                    AND (iniciado_em IS NULL OR iniciado_em < DATE_SUB(NOW(), INTERVAL {$minutos} MINUTE))
                    AND tentativas >= 3"
            )->execute();

            // Assíncronas paradas há tempo demais no provedor: encerra com falha.
            $this->db->prepare(
                "UPDATE ia_geracoes
                    SET status = 'falhou', erro = '[watchdog] provedor não respondeu em 15 minutos', concluido_em = NOW()
                  WHERE status = 'aguardando_provedor'
                    AND iniciado_em < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
            )->execute();
        } catch (Throwable $e) {
            LogService::error('ia_watchdog_erro', ['erro' => $e->getMessage()]);
        }
    }

    /** Assíncrono aceito pelo provedor: guarda a referência e o modelo escolhido. */
    public function marcarAguardando(int $id, string $externalId, ?int $modeloId, ?string $provedorCodigo, ?string $modeloCodigo): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE ia_geracoes SET
                    status = 'aguardando_provedor',
                    external_id = :ref,
                    modelo_id = :modelo_id,
                    provedor_codigo = :prov,
                    modelo_codigo = :mod
                  WHERE id = :id LIMIT 1"
            );
            return $stmt->execute([
                ':ref'       => mb_substr($externalId, 0, 191),
                ':modelo_id' => $modeloId,
                ':prov'      => $provedorCodigo,
                ':mod'       => $modeloCodigo,
                ':id'        => $id,
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_aguardando_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /** Gerações aguardando o provedor há pelo menos N segundos (varredura do worker). */
    public function listarAguardando(int $limite = 5, int $idadeMinSegundos = 20): array
    {
        try {
            $limite = max(1, min(20, $limite));
            $idade  = max(0, $idadeMinSegundos);
            $stmt = $this->db->query(
                "SELECT id, uuid, usuario_id, produto_id, capacidade, formato, external_id,
                        modelo_id, provedor_codigo, modelo_codigo, contexto,
                        custo_estimado_usd, status, iniciado_em
                   FROM ia_geracoes
                  WHERE status = 'aguardando_provedor'
                    AND external_id IS NOT NULL
                    AND iniciado_em <= DATE_SUB(NOW(), INTERVAL {$idade} SECOND)
               ORDER BY iniciado_em ASC
                  LIMIT {$limite}"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_listar_aguardando_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Localiza a geração pela referência do provedor (webhook). */
    public function buscarPorExternalId(string $externalId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, uuid, usuario_id, produto_id, capacidade, formato, external_id,
                        modelo_id, provedor_codigo, modelo_codigo, contexto,
                        custo_estimado_usd, status
                   FROM ia_geracoes
                  WHERE external_id = :ref
                  LIMIT 1'
            );
            $stmt->execute([':ref' => $externalId]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_buscar_external_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Metadados de um arquivo gerado (para o endpoint autenticado de imagem). */
    public function arquivoPorId(int $arquivoId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, geracao_id, tipo, caminho, mime, tamanho_bytes
                   FROM ia_arquivos WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $arquivoId]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_arquivo_buscar_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Primeiro arquivo de imagem de uma geração (detalhe do histórico). */
    public function arquivoPrincipalDe(int $geracaoId): ?int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MIN(id) FROM ia_arquivos WHERE geracao_id = :g AND tipo = 'imagem'"
            );
            $stmt->execute([':g' => $geracaoId]);
            $id = $stmt->fetchColumn();
            return ($id !== false && $id !== null) ? (int) $id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function marcarConcluida(int $id, array $d): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE ia_geracoes SET
                    status = 'concluida',
                    resultado_texto = :texto,
                    modelo_id = :modelo_id,
                    provedor_codigo = :prov,
                    modelo_codigo = :mod,
                    tokens_in = :tin,
                    tokens_out = :tout,
                    tempo_ms = :tempo,
                    custo_real_usd = :custo,
                    erro = NULL,
                    concluido_em = NOW()
                  WHERE id = :id LIMIT 1"
            );
            return $stmt->execute([
                ':texto'     => $d['resultado_texto'],
                ':modelo_id' => $d['modelo_id'],
                ':prov'      => $d['provedor_codigo'],
                ':mod'       => $d['modelo_codigo'],
                ':tin'       => $d['tokens_in'],
                ':tout'      => $d['tokens_out'],
                ':tempo'     => $d['tempo_ms'],
                ':custo'     => $d['custo_real_usd'],
                ':id'        => $id,
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_concluir_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    public function marcarFalha(int $id, string $erro, int $tempoMs): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE ia_geracoes
                    SET status = 'falhou', erro = :erro, tempo_ms = :tempo, concluido_em = NOW()
                  WHERE id = :id LIMIT 1"
            );
            return $stmt->execute([
                ':erro'  => mb_substr($erro, 0, 600),
                ':tempo' => $tempoMs,
                ':id'    => $id,
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_falhar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    public function definirAprovacao(int $id, string $aprovacao): bool
    {
        if (!in_array($aprovacao, ['pendente', 'aprovado', 'reprovado', 'arquivado'], true)) {
            return false;
        }
        try {
            $stmt = $this->db->prepare('UPDATE ia_geracoes SET aprovacao = :a WHERE id = :id LIMIT 1');
            return $stmt->execute([':a' => $aprovacao, ':id' => $id]);
        } catch (Throwable $e) {
            LogService::error('ia_aprovacao_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    public function registrarArquivo(int $geracaoId, string $tipo, string $caminho, string $mime, int $bytes, string $hash): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ia_arquivos (geracao_id, tipo, caminho, mime, tamanho_bytes, hash_sha256)
                 VALUES (:g, :t, :c, :m, :b, :h)'
            );
            $stmt->execute([':g' => $geracaoId, ':t' => $tipo, ':c' => $caminho, ':m' => $mime, ':b' => $bytes, ':h' => $hash]);
        } catch (Throwable $e) {
            LogService::error('ia_arquivo_erro', ['geracao_id' => $geracaoId, 'erro' => $e->getMessage()]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Leitura                                                             */
    /* ------------------------------------------------------------------ */

    public function buscarPorId(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT g.*, t.nome AS tipo_nome, t.grupo AS tipo_grupo, p.nome AS produto_nome
                   FROM ia_geracoes g
             INNER JOIN ia_tipos_conteudo t ON t.id = g.tipo_conteudo_id
              LEFT JOIN produtos p ON p.id = g.produto_id
                  WHERE g.id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_buscar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    public function uuidDe(int $id): string
    {
        try {
            $stmt = $this->db->prepare('SELECT uuid FROM ia_geracoes WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            return (string) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Status em lote para polling. */
    public function statusPorUuids(array $uuids): array
    {
        try {
            $marcadores = implode(',', array_fill(0, count($uuids), '?'));
            $stmt = $this->db->prepare(
                "SELECT g.uuid, g.id, g.status, g.aprovacao, g.resultado_texto, g.erro,
                        g.custo_real_usd, g.custo_estimado_usd, g.modelo_codigo, g.provedor_codigo,
                        g.tempo_ms, g.angulo, g.criado_em, g.capacidade, g.formato,
                        (SELECT MIN(a.id) FROM ia_arquivos a
                          WHERE a.geracao_id = g.id AND a.tipo = 'imagem') AS arquivo_id,
                        t.nome AS tipo_nome
                   FROM ia_geracoes g
             INNER JOIN ia_tipos_conteudo t ON t.id = g.tipo_conteudo_id
                  WHERE g.uuid IN ({$marcadores})
               ORDER BY g.id ASC"
            );
            $stmt->execute($uuids);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_status_lote_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Histórico com filtros: status, aprovacao, tipo_conteudo_id, busca (produto),
     * data_ini, data_fim. Retorna ['linhas'=>[], 'total'=>int].
     */
    public function listar(array $filtros, int $pagina, int $porPagina = 25): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filtros['status'])) {
            $where[] = 'g.status = :status';
            $params[':status'] = $filtros['status'];
        }
        if (!empty($filtros['aprovacao'])) {
            $where[] = 'g.aprovacao = :aprovacao';
            $params[':aprovacao'] = $filtros['aprovacao'];
        }
        if (!empty($filtros['tipo_conteudo_id'])) {
            $where[] = 'g.tipo_conteudo_id = :tipo';
            $params[':tipo'] = (int) $filtros['tipo_conteudo_id'];
        }
        if (!empty($filtros['busca'])) {
            $where[] = '(p.nome LIKE :busca OR g.produto_id = :busca_id)';
            $params[':busca']    = '%' . $filtros['busca'] . '%';
            $params[':busca_id'] = (int) $filtros['busca'];
        }
        if (!empty($filtros['data_ini'])) {
            $where[] = 'g.criado_em >= :dini';
            $params[':dini'] = $filtros['data_ini'] . ' 00:00:00';
        }
        if (!empty($filtros['data_fim'])) {
            $where[] = 'g.criado_em <= :dfim';
            $params[':dfim'] = $filtros['data_fim'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        try {
            $stmtTotal = $this->db->prepare(
                "SELECT COUNT(*)
                   FROM ia_geracoes g
              LEFT JOIN produtos p ON p.id = g.produto_id
                  WHERE {$whereSql}"
            );
            $stmtTotal->execute($params);
            $total = (int) $stmtTotal->fetchColumn();

            $offset = max(0, ($pagina - 1) * $porPagina);
            $stmt = $this->db->prepare(
                "SELECT g.id, g.uuid, g.status, g.aprovacao, g.angulo, g.modelo_codigo,
                        g.provedor_codigo, g.custo_real_usd, g.custo_estimado_usd,
                        g.tempo_ms, g.erro, g.criado_em, g.geracao_origem_id,
                        t.nome AS tipo_nome, p.nome AS produto_nome, g.produto_id
                   FROM ia_geracoes g
             INNER JOIN ia_tipos_conteudo t ON t.id = g.tipo_conteudo_id
              LEFT JOIN produtos p ON p.id = g.produto_id
                  WHERE {$whereSql}
               ORDER BY g.id DESC
                  LIMIT {$porPagina} OFFSET {$offset}"
            );
            $stmt->execute($params);
            $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return ['linhas' => $linhas, 'total' => $total];
        } catch (Throwable $e) {
            LogService::error('ia_listar_erro', ['erro' => $e->getMessage()]);
            return ['linhas' => [], 'total' => 0];
        }
    }

    /** KPIs do histórico. */
    public function kpis(): array
    {
        try {
            $linha = $this->db->query(
                "SELECT
                    SUM(criado_em >= CURDATE()) AS hoje,
                    SUM(criado_em >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS mes,
                    SUM(aprovacao = 'aprovado') AS aprovados,
                    SUM(status = 'falhou') AS falhas
                   FROM ia_geracoes"
            )->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'hoje'      => (int) ($linha['hoje'] ?? 0),
                'mes'       => (int) ($linha['mes'] ?? 0),
                'aprovados' => (int) ($linha['aprovados'] ?? 0),
                'falhas'    => (int) ($linha['falhas'] ?? 0),
            ];
        } catch (Throwable $e) {
            LogService::error('ia_kpis_erro', ['erro' => $e->getMessage()]);
            return ['hoje' => 0, 'mes' => 0, 'aprovados' => 0, 'falhas' => 0];
        }
    }

    /** Log de roteamento de uma geração (drawer de detalhe). */
    public function roteamentoDe(int $geracaoId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT provedor_codigo, modelo_codigo, resultado, erro_codigo, erro, tempo_ms, criado_em
                   FROM ia_roteamento_log
                  WHERE geracao_id = :id
               ORDER BY id ASC'
            );
            $stmt->execute([':id' => $geracaoId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_roteamento_leitura_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }
}
