<?php
/**
 * IACustoService — estimativa de custo ANTES de gastar, custo real DEPOIS,
 * checagem de limites (minuto/dia/mês, global e por usuário) e rollup diário.
 *
 * Convenção: custo em USD com 6 casas; conversão BRL só na exibição.
 */
class IACustoService
{
    private PDO $db;

    /** Estimativa conservadora: ~4 chars por token no input. */
    private const CHARS_POR_TOKEN = 4;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /* ------------------------------------------------------------------ */
    /* Estimativa e custo real                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Estimativa para TEXTO usando o custo do primeiro modelo candidato.
     * Conservadora de propósito: assume max_tokens inteiros na saída —
     * é o valor usado para BARRAR antes de gastar.
     */
    public function estimarTexto(?array $custoConfig, int $charsPrompt, ?int $maxTokens): float
    {
        if (empty($custoConfig) || ($custoConfig['tipo'] ?? '') !== 'por_token') {
            return 0.0;
        }

        $tokensIn  = (int) ceil($charsPrompt / self::CHARS_POR_TOKEN);
        $tokensOut = max(64, (int) ($maxTokens ?? 1024));

        $usdIn  = (float) ($custoConfig['usd_in_1m'] ?? 0);
        $usdOut = (float) ($custoConfig['usd_out_1m'] ?? 0);

        return round(($tokensIn * $usdIn + $tokensOut * $usdOut) / 1000000, 6);
    }

    /** Custo real a partir do uso devolvido pelo provedor. */
    public function custoRealTexto(?array $custoConfig, ?int $tokensIn, ?int $tokensOut): ?float
    {
        if (empty($custoConfig) || ($custoConfig['tipo'] ?? '') !== 'por_token') {
            return null;
        }
        if ($tokensIn === null && $tokensOut === null) {
            return null;
        }

        $usdIn  = (float) ($custoConfig['usd_in_1m'] ?? 0);
        $usdOut = (float) ($custoConfig['usd_out_1m'] ?? 0);

        return round((((int) $tokensIn) * $usdIn + ((int) $tokensOut) * $usdOut) / 1000000, 6);
    }

    /** Custo do primeiro modelo ativo da capacidade (para estimativa no enfileiramento). */
    public function custoConfigPrimario(string $capacidade): ?array
    {
        try {
            $sql = "SELECT m.custo_config
                      FROM ia_modelos m
                INNER JOIN ia_provedores p ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                     WHERE m.capacidade = :cap AND m.ativo = 1
                  ORDER BY m.prioridade ASC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':cap' => $capacidade]);
            $json = $stmt->fetchColumn();
            if ($json === false || $json === null) {
                return null;
            }
            $dec = json_decode((string) $json, true);
            return is_array($dec) ? $dec : null;
        } catch (Throwable $e) {
            LogService::error('ia_custo_primario_erro', ['capacidade' => $capacidade, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    public function custoConfigPrimarioTexto(): ?array
    {
        return $this->custoConfigPrimario('texto');
    }

    /** Estimativa para IMAGEM: custo fixo por execução/imagem do config. */
    public function estimarImagem(?array $custoConfig): float
    {
        return $this->custoFixo($custoConfig) ?? 0.0;
    }

    /** Custo real de imagem do modelo que executou (flat por imagem/execução). */
    public function custoRealImagemPorModelo(?int $modeloId): ?float
    {
        if ($modeloId === null || $modeloId <= 0) {
            return null;
        }
        try {
            $stmt = $this->db->prepare('SELECT custo_config FROM ia_modelos WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $modeloId]);
            $json = $stmt->fetchColumn();
            $cfg  = is_string($json) ? json_decode($json, true) : null;
            return $this->custoFixo(is_array($cfg) ? $cfg : null);
        } catch (Throwable $e) {
            LogService::error('ia_custo_modelo_erro', ['modelo_id' => $modeloId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** por_imagem / por_execucao → valor fixo; outros formatos → null. */
    private function custoFixo(?array $cfg): ?float
    {
        if (empty($cfg)) {
            return null;
        }
        return match ($cfg['tipo'] ?? '') {
            'por_imagem'   => round((float) ($cfg['usd_imagem'] ?? 0), 6),
            'por_execucao' => round((float) ($cfg['usd_execucao'] ?? 0), 6),
            default        => null,
        };
    }

    /* ------------------------------------------------------------------ */
    /* Limites                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Verifica TODOS os limites antes de enfileirar.
     * Retorna ['ok'=>true] ou ['ok'=>false,'msg'=>...].
     */
    public function podeGerar(int $usuarioId, float $custoEstimado, int $quantidade = 1): array
    {
        $global  = $this->limite('global', 0);
        $usuario = $this->limite('usuario', $usuarioId);

        // 1) Taxa por minuto (linha do usuário prevalece; senão a global)
        $limMin = $usuario['limite_geracoes_minuto'] ?? $global['limite_geracoes_minuto'] ?? null;
        if ($limMin !== null) {
            $noMinuto = $this->geracoesNoUltimoMinuto($usuarioId);
            if (($noMinuto + $quantidade) > (int) $limMin) {
                return ['ok' => false, 'msg' => 'Limite de gerações por minuto atingido — aguarde alguns instantes.'];
            }
        }

        // 2) Teto diário/mensal do usuário (se houver linha específica)
        if ($usuario !== null) {
            $gastoDiaUsuario = $this->gastoUsuarioHoje($usuarioId);
            $chk = $this->checarTeto($gastoDiaUsuario, $custoEstimado, $usuario['limite_diario_usd'], 'seu limite diário');
            if (!$chk['ok']) { return $chk; }

            $gastoMesUsuario = $this->gastoUsuarioMes($usuarioId);
            $chk = $this->checarTeto($gastoMesUsuario, $custoEstimado, $usuario['limite_mensal_usd'], 'seu limite mensal');
            if (!$chk['ok']) { return $chk; }
        }

        // 3) Teto diário/mensal global
        if ($global !== null) {
            $chk = $this->checarTeto($this->gastoGlobalHoje(), $custoEstimado, $global['limite_diario_usd'], 'o limite diário global');
            if (!$chk['ok']) { return $chk; }

            $chk = $this->checarTeto($this->gastoGlobalMes(), $custoEstimado, $global['limite_mensal_usd'], 'o limite mensal global');
            if (!$chk['ok']) { return $chk; }
        }

        return ['ok' => true];
    }

    /** Percentual consumido do limite diário global (para alerta no dashboard). */
    public function percentualDiarioGlobal(): ?int
    {
        $global = $this->limite('global', 0);
        if ($global === null || $global['limite_diario_usd'] === null || (float) $global['limite_diario_usd'] <= 0) {
            return null;
        }
        return (int) round($this->gastoGlobalHoje() / (float) $global['limite_diario_usd'] * 100);
    }

    /* ------------------------------------------------------------------ */
    /* Rollup e leituras de gasto                                          */
    /* ------------------------------------------------------------------ */

    /** Incrementa o rollup diário na conclusão (ou falha) de uma geração. */
    public function registrarRollup(int $usuarioId, string $provedorCodigo, string $capacidade, float $custoUsd, bool $falhou): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ia_custos_diarios
                    (`data`, usuario_id, provedor_codigo, capacidade, total_usd, total_geracoes, total_falhas)
                 VALUES (CURDATE(), :u, :p, :c, :usd, 1, :f)
                 ON DUPLICATE KEY UPDATE
                    total_usd      = total_usd + VALUES(total_usd),
                    total_geracoes = total_geracoes + 1,
                    total_falhas   = total_falhas + VALUES(total_falhas)'
            );
            $stmt->execute([
                ':u'   => $usuarioId,
                ':p'   => $provedorCodigo,
                ':c'   => $capacidade,
                ':usd' => max(0, $custoUsd),
                ':f'   => $falhou ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_rollup_erro', ['erro' => $e->getMessage()]);
        }
    }

    public function gastoGlobalHoje(): float
    {
        return $this->somaRollup('`data` = CURDATE()', []);
    }

    public function gastoGlobalMes(): float
    {
        return $this->somaRollup("`data` >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", []);
    }

    public function gastoUsuarioHoje(int $usuarioId): float
    {
        return $this->somaRollup('`data` = CURDATE() AND usuario_id = :u', [':u' => $usuarioId]);
    }

    public function gastoUsuarioMes(int $usuarioId): float
    {
        return $this->somaRollup("`data` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND usuario_id = :u", [':u' => $usuarioId]);
    }

    public function gastoProvedorHoje(string $provedorCodigo): float
    {
        return $this->somaRollup('`data` = CURDATE() AND provedor_codigo = :p', [':p' => $provedorCodigo]);
    }

    public function gastoCapacidadeHoje(string $capacidade): float
    {
        return $this->somaRollup('`data` = CURDATE() AND capacidade = :c', [':c' => $capacidade]);
    }

    public function gastoCapacidadeMes(string $capacidade): float
    {
        return $this->somaRollup("`data` >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND capacidade = :c", [':c' => $capacidade]);
    }

    /**
     * Limites dos agentes de BI: TODOS os limites gerais (podeGerar) E o
     * teto próprio do escopo `agentes_bi`, medido pela capacidade
     * `agente` no rollup. O escopo existe porque o global é dividido
     * com a Central de Marketing — sem ele, uma campanha de imagens
     * esvazia o orçamento dos agentes sem ninguém perceber.
     *
     * Sem linha `agentes_bi` ativa, só os limites gerais valem.
     */
    public function podeGerarAgente(int $usuarioId, float $custoEstimado): array
    {
        $chk = $this->podeGerar($usuarioId, $custoEstimado, 1);
        if (!$chk['ok']) { return $chk; }

        $escopo = $this->limite('agentes_bi', 0);
        if ($escopo === null) { return ['ok' => true]; }

        $chk = $this->checarTeto($this->gastoCapacidadeHoje('agente'), $custoEstimado,
                                 $escopo['limite_diario_usd'], 'o limite diário dos agentes de BI');
        if (!$chk['ok']) { return $chk; }

        return $this->checarTeto($this->gastoCapacidadeMes('agente'), $custoEstimado,
                                 $escopo['limite_mensal_usd'], 'o limite mensal dos agentes de BI');
    }

    /* ------------------------------------------------------------------ */
    /* Internos                                                            */
    /* ------------------------------------------------------------------ */

    private function checarTeto(float $gastoAtual, float $custoEstimado, $limite, string $rotulo): array
    {
        if ($limite === null) {
            return ['ok' => true];
        }
        $lim = (float) $limite;
        if ($lim <= 0) {
            return ['ok' => true];
        }
        if (($gastoAtual + $custoEstimado) > $lim) {
            return ['ok' => false, 'msg' => sprintf(
                'Geração bloqueada: %s (US$ %s) seria excedido. Gasto atual: US$ %s.',
                $rotulo,
                number_format($lim, ($lim > 0 && $lim < 0.01) ? 4 : 2, ',', '.'),
                number_format($gastoAtual, 4, ',', '.')
            )];
        }
        return ['ok' => true];
    }

    private function limite(string $escopo, int $referenciaId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT limite_diario_usd, limite_mensal_usd, limite_geracoes_minuto
                   FROM ia_limites
                  WHERE escopo = :e AND referencia_id = :r AND ativo = 1
                  LIMIT 1'
            );
            $stmt->execute([':e' => $escopo, ':r' => $referenciaId]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_limite_leitura_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    private function geracoesNoUltimoMinuto(int $usuarioId): int
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM ia_geracoes
                  WHERE usuario_id = :u AND criado_em >= DATE_SUB(NOW(), INTERVAL 60 SECOND)'
            );
            $stmt->execute([':u' => $usuarioId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            LogService::error('ia_rate_erro', ['erro' => $e->getMessage()]);
            return 0;
        }
    }

    private function somaRollup(string $where, array $params): float
    {
        try {
            $stmt = $this->db->prepare('SELECT COALESCE(SUM(total_usd), 0) FROM ia_custos_diarios WHERE ' . $where);
            $stmt->execute($params);
            return (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            LogService::error('ia_soma_rollup_erro', ['erro' => $e->getMessage()]);
            return 0.0;
        }
    }
}
