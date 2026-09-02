<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/NewsletterController.php
//
// Inscrição na newsletter em duas etapas:
//   POST /newsletter            → manda o código para o e-mail
//   POST /newsletter/confirmar  → valida o código, grava o nome, entrega o cupom
//
// Toda a regra está no NewsletterService. Aqui só entra o que é de HTTP:
// CSRF, IP e a forma da resposta.
// ════════════════════════════════════════════════════════

class NewsletterController extends Controller
{
    private NewsletterService $service;

    public function __construct()
    {
        $this->service = new NewsletterService();
    }

    // ── POST /newsletter ─────────────────────────────────
    public function solicitar(): void
    {
        $this->verifyCsrf();

        $this->json($this->service->solicitarCodigo(
            (string) ($_POST['email'] ?? ''),
            $this->ip()
        ));
    }

    // ── POST /newsletter/confirmar ───────────────────────
    public function confirmar(): void
    {
        $this->verifyCsrf();

        $this->json($this->service->confirmar(
            (string) ($_POST['email']  ?? ''),
            (string) ($_POST['nome']   ?? ''),
            (string) ($_POST['codigo'] ?? ''),
            $this->ip()
        ));
    }

    /**
     * IP real do visitante.
     *
     * Atrás da Cloudflare o REMOTE_ADDR é da própria CDN: sem isto o freio por
     * IP contaria todo mundo como o mesmo dispositivo e bloquearia a loja
     * inteira depois de 8 inscrições.
     */
    private function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $chave) {
            $v = trim((string) ($_SERVER[$chave] ?? ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
        }
        return '';
    }
}
