<?php
declare(strict_types=1);

/**
 * RecaptchaService — valida tokens do reCAPTCHA v3 contra a API do Google.
 *
 * Fluxo: o JS no front gera um token a cada submit de formulário (invisível,
 * sem interação do usuário). Esse token vem junto no POST e este serviço
 * o valida com a Secret Key, recebendo de volta um score 0.0–1.0 (quanto
 * mais alto, mais "humano" o comportamento pareceu ao Google).
 *
 * O Google NÃO decide bloquear ou liberar — só devolve o score. A decisão
 * de negócio (o que fazer com esse número) é nossa, aplicada em
 * RateLimitService::avaliarCaptcha().
 */
class RecaptchaService {

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private string $secretKey;
    private float  $threshold;

    public function __construct() {
        $this->secretKey = ConfigHelper::get('recaptcha_secret_key', '');
        // Threshold configurável via .env/config — ajustável sem deploy
        // de código enquanto observamos os scores reais em produção.
        $this->threshold = (float)ConfigHelper::get('recaptcha_threshold', 0.5);
    }

    public function isConfigured(): bool {
        return $this->secretKey !== '';
    }

    /**
     * Valida o token com a API do Google. Retorna o payload completo
     * (success, score, action, hostname) ou null em caso de falha de
     * comunicação — falha de rede NUNCA deve travar o login do usuário
     * (fail-open: se o Google estiver fora do ar, deixamos passar e
     * confiamos só no rate limit normal).
     */
    public function validar(string $token, string $ipUsuario = ''): ?array {
        if (!$this->isConfigured() || $token === '') {
            return null;
        }

        $params = [
            'secret'   => $this->secretKey,
            'response' => $token,
        ];
        if ($ipUsuario !== '') {
            $params['remoteip'] = $ipUsuario;
        }

        $contexto = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($params),
                'timeout' => 5, // não deixa o login travar esperando o Google
            ],
        ]);

        $resposta = @file_get_contents(self::VERIFY_URL, false, $contexto);
        if ($resposta === false) {
            error_log('[RecaptchaService] Falha ao contatar API do Google reCAPTCHA');
            return null;
        }

        $dados = json_decode($resposta, true);
        if (!is_array($dados)) {
            return null;
        }

        return $dados;
    }

    /**
     * Atalho: valida e já devolve se o score passou no threshold
     * configurado. true = humano provável, false = suspeito ou inválido.
     *
     * Em caso de falha de comunicação com o Google (validar() retorna
     * null), o comportamento é FAIL-OPEN — assume true (não bloqueia o
     * usuário por um problema do nosso lado/do Google). O rate limit
     * continua valendo normalmente como rede de segurança.
     */
    public function passou(string $token, string $ipUsuario = ''): bool {
        $resultado = $this->validar($token, $ipUsuario);

        if ($resultado === null) {
            return true; // fail-open
        }

        if (empty($resultado['success'])) {
            return false; // token inválido/expirado — bloqueia
        }

        $score = (float)($resultado['score'] ?? 0);
        return $score >= $this->threshold;
    }

    public function getThreshold(): float {
        return $this->threshold;
    }
}