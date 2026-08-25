<?php
// app/services/app/AppLog.php
// Observabilidade do app mobile, sobre o LogService que a loja já usa.
//
// ── Por que uma camada e não LogService direto ──────────────────────────────
//
// 1. CANAL PRÓPRIO. A loja já usa 'app', 'auth', 'audit', 'bling', 'import'.
//    Aqui o canal é 'mobile' (e 'mobile_client' para erro que veio do
//    aparelho). Sem separar, a saúde do aplicativo se mistura à do site e o
//    dashboard perde a pergunta mais útil: "o app está bem?".
//
// 2. CONTEXTO AUTOMÁTICO. Todo registro carrega dispositivo, plataforma e
//    versão do app. É o que transforma "erro no checkout" em "erro no checkout
//    só no Android 14 a partir da 1.4.2" — sem isso não dá para diagnosticar
//    nada remotamente.
//
// A redação de segredos, o fingerprint de deduplicação e o request_id vêm de
// graça do LogService. Atenção: `token`, `authorization`, `session` e `cartao`
// estão na lista de chaves sensíveis dele, então nunca vazam para o log mesmo
// que alguém os coloque no contexto por engano.

final class AppLog
{
    public const CANAL = 'mobile';
    /** Erro originado no aparelho, não no servidor. */
    public const CANAL_CLIENTE = 'mobile_client';

    /** Preenchido uma vez por request pelo AppApiController. */
    private static array $contexto = [];

    /**
     * Instala o contexto do dispositivo. Tudo que for logado a partir daqui
     * carrega esses campos sem que o ponto de chamada precise repeti-los.
     */
    public static function contextualizar(?array $dispositivo, ?int $clienteId = null): void
    {
        if (!$dispositivo) {
            self::$contexto = [];
            return;
        }

        self::$contexto = array_filter([
            'dispositivo_id' => (int)$dispositivo['id'],
            'plataforma'     => $dispositivo['plataforma'] ?? null,
            'app_versao'     => $dispositivo['app_versao'] ?? null,
            'cliente_id'     => $clienteId,
        ], static fn($v) => $v !== null);
    }

    public static function limpar(): void
    {
        self::$contexto = [];
    }

    /* =================================================================
       Níveis
       ================================================================= */

    public static function debug(string $msg, array $ctx = []): void
    {
        LogService::debug($msg, self::juntar($ctx), self::CANAL);
    }

    public static function info(string $msg, array $ctx = []): void
    {
        LogService::info($msg, self::juntar($ctx), self::CANAL);
    }

    public static function warning(string $msg, array $ctx = []): void
    {
        LogService::warning($msg, self::juntar($ctx), self::CANAL);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        LogService::error($msg, self::juntar($ctx), self::CANAL);
    }

    public static function critical(string $msg, array $ctx = []): void
    {
        LogService::critical($msg, self::juntar($ctx), self::CANAL);
    }

    /** Exceção com tipo, arquivo, linha e trace — o que salva um 500 sem pista. */
    public static function exception(Throwable $e, array $ctx = [], string $nivel = 'error'): void
    {
        LogService::exception($e, $nivel, self::CANAL, self::juntar($ctx));
    }

    /** Trilha de auditoria (LGPD) — vai para o canal 'audit', como na web. */
    public static function audit(string $msg, array $ctx = []): void
    {
        LogService::audit($msg, self::juntar($ctx));
    }

    /* =================================================================
       Erro vindo do aparelho
       ================================================================= */

    /**
     * Registra uma falha que aconteceu no CLIENTE (crash de JS, tela de erro,
     * falha de reprodução de vídeo), reportada pelo app.
     *
     * Vai para um canal separado de propósito: erro de cliente tem outra
     * natureza — não é o servidor que está doente, é uma versão do app numa
     * plataforma específica. Misturar os dois estraga o alerta de ambos.
     *
     * O `tipo`, a `tela` e a `app_versao` entram no fingerprint do LogService
     * porque fazem parte da mensagem normalizada, então o mesmo crash em mil
     * aparelhos vira UMA linha com `ocorrencias = 1000`.
     */
    public static function doCliente(
        string $nivel,
        string $mensagem,
        array $ctx = []
    ): void {
        $niveisAceitos = ['debug', 'info', 'warning', 'error', 'critical'];
        if (!in_array($nivel, $niveisAceitos, true)) {
            $nivel = 'error';
        }

        LogService::{$nivel}(
            mb_substr($mensagem, 0, 500),
            self::juntar($ctx),
            self::CANAL_CLIENTE
        );
    }

    private static function juntar(array $ctx): array
    {
        return $ctx ? array_merge(self::$contexto, $ctx) : self::$contexto;
    }

    /* =================================================================
       Saúde
       ================================================================= */

    /**
     * Resumo de saúde do app nas últimas 24h — o equivalente ao
     * LogService::resumo() da loja, restrito aos canais do mobile e quebrado
     * por plataforma e versão.
     *
     * Serve tanto para um painel no admin quanto para responder rápido à
     * pergunta "a versão que subiu ontem quebrou alguma coisa?".
     */
    public static function saude(int $horas = 24): array
    {
        $horas = max(1, min(720, $horas));

        try {
            $pdo = Database::getInstance()->getConnection();

            $totais = $pdo->query(
                "SELECT
                    COALESCE(SUM(nivel = 'critical'), 0) AS criticos,
                    COALESCE(SUM(nivel = 'error'),    0) AS erros,
                    COALESCE(SUM(nivel = 'warning'),  0) AS avisos,
                    COALESCE(SUM(ocorrencias), 0)        AS ocorrencias
                 FROM logs
                 WHERE canal IN ('" . self::CANAL . "','" . self::CANAL_CLIENTE . "')
                   AND resolvido = 0
                   AND nivel IN ('warning','error','critical')
                   AND visto_em >= (UTC_TIMESTAMP() - INTERVAL {$horas} HOUR)"
            )->fetch(PDO::FETCH_ASSOC) ?: [];

            $piores = $pdo->query(
                "SELECT id, canal, nivel, mensagem, ocorrencias,
                        JSON_UNQUOTE(JSON_EXTRACT(contexto, '$.plataforma')) AS plataforma,
                        JSON_UNQUOTE(JSON_EXTRACT(contexto, '$.app_versao')) AS app_versao
                 FROM logs
                 WHERE canal IN ('" . self::CANAL . "','" . self::CANAL_CLIENTE . "')
                   AND resolvido = 0
                   AND nivel IN ('error','critical')
                   AND visto_em >= (UTC_TIMESTAMP() - INTERVAL {$horas} HOUR)
                 ORDER BY ocorrencias DESC, visto_em DESC
                 LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Requisições e taxa de erro HTTP saem do log próprio da API.
            $trafego = $pdo->query(
                "SELECT
                    COUNT(*)                                        AS requisicoes,
                    COALESCE(SUM(status_http >= 500), 0)            AS erros_5xx,
                    COALESCE(SUM(status_http BETWEEN 400 AND 499), 0) AS erros_4xx,
                    COUNT(DISTINCT dispositivo_id)                  AS dispositivos,
                    ROUND(AVG(duracao_ms))                          AS duracao_media_ms,
                    MAX(duracao_ms)                                 AS duracao_max_ms
                 FROM log_app_requisicoes
                 WHERE criado_em >= (NOW() - INTERVAL {$horas} HOUR)"
            )->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'janela_horas' => $horas,
                'logs' => [
                    'criticos'    => (int)($totais['criticos'] ?? 0),
                    'erros'       => (int)($totais['erros'] ?? 0),
                    'avisos'      => (int)($totais['avisos'] ?? 0),
                    'ocorrencias' => (int)($totais['ocorrencias'] ?? 0),
                ],
                'trafego' => [
                    'requisicoes'      => (int)($trafego['requisicoes'] ?? 0),
                    'erros_5xx'        => (int)($trafego['erros_5xx'] ?? 0),
                    'erros_4xx'        => (int)($trafego['erros_4xx'] ?? 0),
                    'dispositivos'     => (int)($trafego['dispositivos'] ?? 0),
                    'duracao_media_ms' => (int)($trafego['duracao_media_ms'] ?? 0),
                    'duracao_max_ms'   => (int)($trafego['duracao_max_ms'] ?? 0),
                ],
                'piores' => $piores,
            ];
        } catch (\Throwable $e) {
            // Painel de saúde nunca derruba nada. Falhou, devolve zeros.
            error_log('[APP-SAUDE] ' . $e->getMessage());
            return [
                'janela_horas' => $horas,
                'logs' => ['criticos' => 0, 'erros' => 0, 'avisos' => 0, 'ocorrencias' => 0],
                'trafego' => [
                    'requisicoes' => 0, 'erros_5xx' => 0, 'erros_4xx' => 0,
                    'dispositivos' => 0, 'duracao_media_ms' => 0, 'duracao_max_ms' => 0,
                ],
                'piores' => [],
            ];
        }
    }
}
