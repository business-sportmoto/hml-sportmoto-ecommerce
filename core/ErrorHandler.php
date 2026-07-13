<?php
declare(strict_types=1);

/**
 * core/ErrorHandler.php
 *
 * Captura GLOBAL de falhas e envia ao LogService.
 * É isto que transforma "500 Internal Server Error sem pista nenhuma" (o que
 * te fez perder tempo no hml) em um log com arquivo, linha, trace e URL.
 *
 * Cobre as três formas de o PHP falhar:
 *   1. Exceções não capturadas  -> set_exception_handler()
 *   2. Erros/warnings/notices   -> set_error_handler()
 *   3. Erros FATAIS             -> register_shutdown_function()
 *      (fatal não passa por try/catch nem por set_error_handler — só o
 *       shutdown handler pega. Era o caso do imagewebp() inexistente.)
 *
 * INSTALAÇÃO — no bootstrap (index.php e admin/index.php), APÓS carregar
 * Database/Session e ANTES de rotear:
 *
 *     ErrorHandler::register();
 *
 * IMPORTANTE: em produção, o usuário vê uma página de erro genérica; o
 * detalhe fica só no banco. display_errors permanece OFF (o vazamento de
 * stack trace na tela é OWASP A05).
 */
final class ErrorHandler
{
    private static bool $registrado = false;

    public static function register(): void
    {
        if (self::$registrado) {
            return;
        }
        self::$registrado = true;

        set_exception_handler([self::class, 'onException']);
        set_error_handler([self::class, 'onError']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /** Exceção não capturada. */
    public static function onException(Throwable $e): void
    {
        LogService::exception($e, 'critical', self::canal());
        self::responder(500);
    }

    /**
     * Erros do PHP (warning, notice, deprecated...).
     * Retorna false para que o handler padrão do PHP também rode (respeita
     * error_reporting e o log do FPM).
     */
    public static function onError(int $tipo, string $msg, string $arquivo = '', int $linha = 0): bool
    {
        // Respeita o @ e o error_reporting configurado.
        if (!(error_reporting() & $tipo)) {
            return false;
        }

        $nivel = match ($tipo) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'critical',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING             => 'warning',
            E_DEPRECATED, E_USER_DEPRECATED                                          => 'info',
            default                                                                  => 'warning',
        };

        LogService::exception(
            new ErrorException($msg, 0, $tipo, $arquivo, $linha),
            $nivel,
            self::canal(),
            ['tipo_php' => self::nomeErro($tipo)]
        );

        return false; // deixa o PHP seguir o fluxo normal
    }

    /**
     * Erros FATAIS — a única forma de capturá-los.
     * É aqui que cai "Call to undefined function imagewebp()", esgotamento de
     * memória, timeout, e o parse error em runtime.
     */
    public static function onShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }

        $fatais = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!($err['type'] & $fatais)) {
            return;
        }

        LogService::exception(
            new ErrorException(
                $err['message'],
                0,
                $err['type'],
                $err['file'] ?? '',
                (int) ($err['line'] ?? 0)
            ),
            'critical',
            self::canal(),
            ['tipo_php' => self::nomeErro($err['type']), 'fatal' => true]
        );

        self::responder(500);
    }

    // ── internos ─────────────────────────────────────────────────────────

    /** Separa logs do painel dos da loja — filtro útil no dashboard. */
    private static function canal(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_starts_with($uri, '/admin') ? 'admin' : 'app';
    }

    /**
     * Resposta ao usuário. NUNCA expõe o erro — o detalhe vive no banco.
     * Devolve JSON se a requisição for AJAX (o front espera JSON e um HTML
     * de erro quebraria o parse silenciosamente).
     */
    private static function responder(int $status): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
        $rid = LogService::requestId();

        $ehAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

        if ($ehAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'         => false,
                'msg'        => 'Erro interno. A equipe foi notificada.',
                'request_id' => $rid,   // o usuário pode informar isso ao suporte
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Erro interno</title></head>'
           . '<body style="font-family:system-ui,sans-serif;background:#0a0b0d;color:#b6bcc6;'
           . 'display:grid;place-items:center;min-height:100vh;margin:0;text-align:center;padding:24px">'
           . '<div><h1 style="color:#f4f5f7;font-size:20px;margin:0 0 8px">Algo saiu errado</h1>'
           . '<p style="margin:0 0 16px">Não conseguimos concluir esta ação. Tente novamente em instantes.</p>'
           . '<code style="font-size:12px;color:#6b7280">ref: ' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '</code>'
           . '</div></body></html>';
    }

    private static function nomeErro(int $tipo): string
    {
        return match ($tipo) {
            E_ERROR             => 'E_ERROR',
            E_WARNING           => 'E_WARNING',
            E_PARSE             => 'E_PARSE',
            E_NOTICE            => 'E_NOTICE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_CORE_WARNING      => 'E_CORE_WARNING',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_USER_WARNING      => 'E_USER_WARNING',
            E_USER_NOTICE       => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED        => 'E_DEPRECATED',
            E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
            default             => 'E_UNKNOWN(' . $tipo . ')',
        };
    }
}