<?php
/**
 * IAOrchestrator — porta única de execução de IA.
 *
 * Recebe uma geração já enfileirada e:
 *  1. resolve a lista de modelos ativos da capacidade (prioridade ASC),
 *     com override opcional do tipo de conteúdo na frente;
 *  2. pula modelos cujo provedor estourou o teto diário próprio;
 *  3. percorre a lista com fallback, registrando cada tentativa em
 *     ia_roteamento_log e atualizando as estatísticas do modelo;
 *  4. devolve IAResultado com modelo/provedor/custo real preenchidos.
 *
 * Controllers NUNCA falam com adapters — sempre por aqui (padrão GatewayRouter).
 */
class IAOrchestrator
{
    private PDO $db;
    private IACustoService $custo;

    /** Cache de chaves decifradas por provedor_id dentro da mesma execução. */
    private array $chaves = [];

    public function __construct()
    {
        $this->db    = Database::getInstance()->getConnection();
        $this->custo = new IACustoService();
    }

    /**
     * Executa uma geração de TEXTO (Fase 1).
     * $geracao precisa de: id, capacidade, prompt_final, contexto(json), tipo:
     *   instrucoes_sistema, max_tokens, modelo_id (override do tipo, opcional).
     */
    public function executarTexto(array $geracao, array $tipo): IAResultado
    {
        $candidatos = $this->modelosDaCapacidade('texto', isset($tipo['modelo_id']) ? (int) $tipo['modelo_id'] : null);

        if (empty($candidatos)) {
            return IAResultado::falha('sem_modelos', 'Nenhum modelo de texto ativo com provedor configurado.', false);
        }

        $ultimo = null;

        foreach ($candidatos as $m) {
            // Teto diário do provedor (se definido): pula sem gastar.
            $limiteProv = ($m['prov_limite'] !== null) ? (float) $m['prov_limite'] : null;
            if ($limiteProv !== null) {
                $gastoProv = $this->custo->gastoProvedorHoje($m['prov_codigo']);
                if ($gastoProv >= $limiteProv) {
                    $this->logRoteamento((int) $geracao['id'], $m, 'pulado', 'limite_provedor',
                        'Teto diário do provedor atingido (US$ ' . number_format($gastoProv, 4, '.', '') . ')', 0);
                    continue;
                }
            }

            $adapter = $this->fabricarAdapter($m);
            if ($adapter === null) {
                $this->logRoteamento((int) $geracao['id'], $m, 'pulado', 'sem_adapter',
                    'Adapter indisponível ou chave não decifrável.', 0);
                continue;
            }

            $job = [
                'prompt'        => (string) $geracao['prompt_final'],
                'instrucoes'    => $tipo['instrucoes_sistema'] ?? null,
                'max_tokens'    => isset($tipo['max_tokens']) ? (int) $tipo['max_tokens'] : null,
                'modelo_codigo' => (string) $m['codigo_modelo'],
                'timeout_s'     => (int) $m['timeout_s'],
                'params'        => $this->decodificarJson($m['params_padrao']),
                'saida_json'    => (($tipo['saida'] ?? 'texto') === 'json'),
            ];

            $resultado = $adapter->gerarTexto($job);

            $this->atualizarEstatisticas((int) $m['id'], $resultado->ok, $resultado->tempoMs);
            $this->logRoteamento(
                (int) $geracao['id'],
                $m,
                $resultado->ok ? 'ok' : (($resultado->erroCodigo === 'rede') ? 'timeout' : 'falha'),
                $resultado->erroCodigo,
                $resultado->erro,
                $resultado->tempoMs
            );

            if ($resultado->ok) {
                $resultado->modeloId       = (int) $m['id'];
                $resultado->provedorCodigo = (string) $m['prov_codigo'];
                $resultado->modeloCodigo   = (string) $m['codigo_modelo'];
                $resultado->custoRealUsd   = $this->custo->custoRealTexto(
                    $this->decodificarJson($m['custo_config']),
                    $resultado->tokensIn,
                    $resultado->tokensOut
                );
                return $resultado;
            }

            $ultimo = $resultado;

            if (!$resultado->retryable) {
                LogService::warning('ia_fallback_interrompido', [
                    'geracao_id' => (int) $geracao['id'],
                    'modelo'     => $m['codigo_modelo'],
                    'erro'       => $resultado->erroCodigo,
                ]);
                return $resultado;
            }

            LogService::warning('ia_fallback_proximo_modelo', [
                'geracao_id' => (int) $geracao['id'],
                'falhou'     => $m['codigo_modelo'],
                'erro'       => $resultado->erroCodigo,
            ]);
        }

        return $ultimo ?? IAResultado::falha('todos_falharam', 'Todos os modelos da capacidade falharam.', false);
    }

    /**
     * Executa uma geração de MÍDIA (imagem | remocao_fundo) — Fase 2 A/B.
     * Síncrono (OpenAI): devolve ok com binários. Assíncrono (Replicate):
     * devolve aguardando + externalId — a tentativa PARA aqui de propósito
     * (o job vive no provedor; fallback entre tentativas assíncronas é Fase 2C).
     */
    public function executarImagem(array $geracao, array $tipo): IAResultado
    {
        $cap = in_array((string) ($geracao['capacidade'] ?? 'imagem'), ['imagem', 'remocao_fundo'], true)
            ? (string) $geracao['capacidade'] : 'imagem';

        $ctx = json_decode((string) ($geracao['contexto'] ?? ''), true);
        $ctx = is_array($ctx) ? $ctx : [];

        // Foto do produto como referência (só na geração criativa)
        $referencia = ($cap === 'imagem' && !empty($ctx['imagem_referencia']))
            ? (string) $ctx['imagem_referencia'] : null;

        $candidatos = $this->modelosDaCapacidade($cap, isset($tipo['modelo_id']) ? (int) $tipo['modelo_id'] : null);

        if (empty($candidatos)) {
            return IAResultado::falha('sem_modelos', "Nenhum modelo ativo da capacidade {$cap} com provedor configurado.", false);
        }

        $ultimo = null;

        foreach ($candidatos as $m) {
            // Referência de imagem: por ora só o Replicate (FLUX.2) aceita.
            if ($referencia !== null && $m['prov_codigo'] !== 'replicate') {
                $this->logRoteamento((int) $geracao['id'], $m, 'pulado', 'sem_suporte_referencia', 'Modelo não aceita imagem de referência.', 0);
                continue;
            }

            $limiteProv = ($m['prov_limite'] !== null) ? (float) $m['prov_limite'] : null;
            if ($limiteProv !== null && $this->custo->gastoProvedorHoje($m['prov_codigo']) >= $limiteProv) {
                $this->logRoteamento((int) $geracao['id'], $m, 'pulado', 'limite_provedor', 'Teto diário do provedor atingido.', 0);
                continue;
            }

            $adapter = $this->fabricarAdapter($m);
            if ($adapter === null) {
                $this->logRoteamento((int) $geracao['id'], $m, 'pulado', 'sem_adapter', 'Adapter indisponível ou chave não decifrável.', 0);
                continue;
            }

            if ($cap === 'remocao_fundo') {
                $job = [
                    'imagem_origem' => (string) ($ctx['imagem_origem'] ?? ''),
                    'modelo_codigo' => (string) $m['codigo_modelo'],
                    'timeout_s'     => (int) $m['timeout_s'],
                    'params'        => $this->decodificarJson($m['params_padrao']),
                ];
                $resultado = $adapter->removerFundo($job);
            } else {
                $job = [
                    'prompt'            => (string) $geracao['prompt_final'],
                    'proporcao'         => !empty($geracao['formato']) ? (string) $geracao['formato'] : '1:1',
                    'modelo_codigo'     => (string) $m['codigo_modelo'],
                    'timeout_s'         => (int) $m['timeout_s'],
                    'params'            => $this->decodificarJson($m['params_padrao']),
                    'imagem_referencia' => $referencia,
                ];
                $resultado = $adapter->gerarImagem($job);
            }

            if ($resultado->aguardando) {
                // Provedor aceitou: registra a tentativa e encerra — webhook/varredura concluem.
                $this->logRoteamento((int) $geracao['id'], $m, 'aguardando', null, null, $resultado->tempoMs);
                $resultado->modeloId       = (int) $m['id'];
                $resultado->provedorCodigo = (string) $m['prov_codigo'];
                $resultado->modeloCodigo   = (string) $m['codigo_modelo'];
                return $resultado;
            }

            $this->atualizarEstatisticas((int) $m['id'], $resultado->ok, $resultado->tempoMs);
            $this->logRoteamento(
                (int) $geracao['id'],
                $m,
                $resultado->ok ? 'ok' : (($resultado->erroCodigo === 'rede') ? 'timeout' : 'falha'),
                $resultado->erroCodigo,
                $resultado->erro,
                $resultado->tempoMs
            );

            if ($resultado->ok) {
                $resultado->modeloId       = (int) $m['id'];
                $resultado->provedorCodigo = (string) $m['prov_codigo'];
                $resultado->modeloCodigo   = (string) $m['codigo_modelo'];
                $resultado->custoRealUsd   = $this->custo->custoRealImagemPorModelo((int) $m['id']);
                return $resultado;
            }

            $ultimo = $resultado;

            if (!$resultado->retryable) {
                LogService::warning('ia_fallback_interrompido', [
                    'geracao_id' => (int) $geracao['id'],
                    'modelo'     => $m['codigo_modelo'],
                    'erro'       => $resultado->erroCodigo,
                ]);
                return $resultado;
            }

            LogService::warning('ia_fallback_proximo_modelo', [
                'geracao_id' => (int) $geracao['id'],
                'falhou'     => $m['codigo_modelo'],
                'erro'       => $resultado->erroCodigo,
            ]);
        }

        return $ultimo ?? IAResultado::falha('todos_falharam', 'Todos os modelos da capacidade falharam.', false);
    }

    /**
     * Adapter pronto (com chave decifrada) a partir do CÓDIGO do provedor —
     * usado pela varredura do worker e pelo webhook para consultar predictions.
     */
    public function adapterPorCodigo(string $codigo): ?IAProviderBase
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, codigo, base_url, config_extra FROM ia_provedores
                  WHERE codigo = :c AND api_key_enc IS NOT NULL LIMIT 1'
            );
            $stmt->execute([':c' => $codigo]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            LogService::error('ia_adapter_codigo_erro', ['codigo' => $codigo, 'erro' => $e->getMessage()]);
            return null;
        }

        if (!$p) {
            return null;
        }

        return $this->fabricarAdapterDireto(
            (string) $p['codigo'],
            (int) $p['id'],
            (string) $p['base_url'],
            $this->decodificarJson($p['config_extra'] ?? null)
        );
    }

    /** Teste de conexão de um provedor (botão da tela de config). */
    public function testarProvedor(array $provedor): IAResultado
    {
        $adapter = $this->fabricarAdapterDireto(
            (string) $provedor['codigo'],
            (int) $provedor['id'],
            (string) $provedor['base_url'],
            $this->decodificarJson($provedor['config_extra'] ?? null)
        );

        if ($adapter === null) {
            return IAResultado::falha('sem_adapter', 'Sem adapter para este provedor ou chave não configurada.', false);
        }

        return $adapter->testarConexao();
    }

    /* ------------------------------------------------------------------ */
    /* Internos                                                            */
    /* ------------------------------------------------------------------ */

    /** Modelos ativos da capacidade, provedor ativo + com chave, prioridade ASC. */
    public function modelosDaCapacidade(string $capacidade, ?int $modeloOverride): array
    {
        try {
            $sql = "SELECT m.id, m.provedor_id, m.codigo_modelo, m.timeout_s,
                           m.custo_config, m.params_padrao, m.prioridade,
                           p.codigo AS prov_codigo, p.base_url, p.config_extra,
                           p.limite_diario_usd AS prov_limite
                      FROM ia_modelos m
                INNER JOIN ia_provedores p
                        ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                     WHERE m.capacidade = :cap AND m.ativo = 1
                  ORDER BY m.prioridade ASC, m.id ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':cap' => $capacidade]);
            $lista = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_orq_catalogo_erro', ['erro' => $e->getMessage()]);
            return [];
        }

        if ($modeloOverride !== null && $modeloOverride > 0) {
            usort($lista, function ($a, $b) use ($modeloOverride) {
                $pa = ((int) $a['id'] === $modeloOverride) ? -1 : (int) $a['prioridade'];
                $pb = ((int) $b['id'] === $modeloOverride) ? -1 : (int) $b['prioridade'];
                return $pa <=> $pb;
            });
        }

        return $lista;
    }

    /**
     * Fábrica de adapters a partir da linha do catálogo.
     * Protected de propósito — testes substituem por um adapter fake.
     */
    protected function fabricarAdapter(array $modelo): ?IAProviderBase
    {
        return $this->fabricarAdapterDireto(
            (string) $modelo['prov_codigo'],
            (int) $modelo['provedor_id'],
            (string) $modelo['base_url'],
            $this->decodificarJson($modelo['config_extra'] ?? null)
        );
    }

    protected function fabricarAdapterDireto(string $codigo, int $provedorId, string $baseUrl, array $configExtra): ?IAProviderBase
    {
        $chave = $this->chaveDoProvedor($provedorId);
        if ($chave === null || $chave === '') {
            return null;
        }

        switch ($codigo) {
            case 'openai':
                return new OpenAIAdapter($chave, $baseUrl, $configExtra);
            case 'replicate':
                return new ReplicateAdapter($chave, $baseUrl, $configExtra);
            case 'gemini':
                return new GeminiAdapter($chave, $baseUrl, $configExtra);
            default:
                LogService::warning('ia_adapter_desconhecido', ['codigo' => $codigo]);
                return null;
        }
    }

    private function chaveDoProvedor(int $provedorId): ?string
    {
        if (!array_key_exists($provedorId, $this->chaves)) {
            $this->chaves[$provedorId] = (new IAProvedor())->chaveDecifrada($provedorId);
        }
        return $this->chaves[$provedorId];
    }

    public function logRoteamento(int $geracaoId, array $m, string $resultado, ?string $erroCodigo, ?string $erro, int $tempoMs): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ia_roteamento_log
                    (geracao_id, provedor_codigo, modelo_codigo, resultado, erro_codigo, erro, tempo_ms)
                 VALUES (:g, :p, :m, :r, :ec, :e, :t)'
            );
            $stmt->execute([
                ':g'  => $geracaoId,
                ':p'  => (string) $m['prov_codigo'],
                ':m'  => (string) $m['codigo_modelo'],
                ':r'  => $resultado,
                ':ec' => $erroCodigo !== null ? mb_substr((string) $erroCodigo, 0, 80) : null,
                ':e'  => $erro !== null ? mb_substr((string) $erro, 0, 600) : null,
                ':t'  => $tempoMs,
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_roteamento_log_erro', ['erro' => $e->getMessage()]);
        }
    }

    /** total_execucoes/total_falhas + média móvel do tempo (80/20). */
    private function atualizarEstatisticas(int $modeloId, bool $ok, int $tempoMs): void
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE ia_modelos
                    SET total_execucoes = total_execucoes + 1,
                        total_falhas    = total_falhas + :f,
                        tempo_medio_ms  = ROUND(COALESCE(tempo_medio_ms, :t) * 0.8 + :t * 0.2)
                  WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':f' => $ok ? 0 : 1, ':t' => max(0, $tempoMs), ':id' => $modeloId]);
        } catch (Throwable $e) {
            LogService::error('ia_stats_erro', ['modelo_id' => $modeloId, 'erro' => $e->getMessage()]);
        }
    }

    private function decodificarJson($valor): array
    {
        if (is_array($valor)) {
            return $valor;
        }
        if (!is_string($valor) || trim($valor) === '') {
            return [];
        }
        $dec = json_decode($valor, true);
        return is_array($dec) ? $dec : [];
    }
}
