<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/controllers/CarrinhoRecuperacaoController.php
// Rota PÚBLICA do link de retorno. O token de 256 bits é a
// única credencial — nenhum dado do cliente é exposto; o
// link apenas restaura ITENS no carrinho da sessão atual.
// ════════════════════════════════════════════════════════

class CarrinhoRecuperacaoController extends Controller {

    // ── GET /carrinho/recuperar/{token} ───────────────────
    public function recuperar(string $token): void {
        // Rate limit: links vazam em prints/encaminhamentos — 10/min/IP
        // impede varredura mesmo sabendo o formato do token
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (SecurityHelper::rateLimitExceeded('cart_recover_' . md5($ip), 10, 60)) {
            http_response_code(429);
            $this->redirect('/carrinho');
            return;
        }

        $rec = (new CarrinhoRecuperacaoService())->resolverToken($token);
        if (!$rec) {
            // Token inválido/expirado: sem detalhe do motivo (anti-oráculo)
            Session::flash('cart_msg', 'Este link expirou. Seus itens podem ainda estar no carrinho.');
            $this->redirect('/carrinho');
            return;
        }

        // Restaura itens do carrinho abandonado no carrinho da sessão
        // atual via merge — preço NÃO vem do snapshot: addItem() busca
        // o preço vigente do cadastro (integridade de preço garantida)
        $cart        = new Cart();
        $clienteId   = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $carrinhoAtual = $cart->getOrCreate($clienteId, session_id());

        foreach ($cart->getItens((int)$rec['carrinho_id']) as $item) {
            try {
                $cart->addItem(
                    $carrinhoAtual['id'],
                    (int)$item['produto_id'],
                    (int)$item['quantidade'],
                    $item['sku_id'] ? (int)$item['sku_id'] : null
                );
            } catch (\Throwable $e) {
                // Item pode ter saído de linha/estoque — segue os demais
                error_log('[CartRecover] item skip: ' . $e->getMessage());
            }
        }

        Session::flash('cart_msg', 'Bem-vindo de volta! Seu carrinho foi restaurado. 🛒');
        $this->redirect('/carrinho');
    }
}


/* ════════════════════════════════════════════════════════
   bin/carrinhos-abandonados-processar.php  (ARQUIVO SEPARADO)
   Ploi Scheduler → Command: php /home/ploi/SITE/bin/carrinhos-abandonados-processar.php
                    Frequency: Every 30 minutes
   ════════════════════════════════════════════════════════

#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

require __DIR__ . '/../app/bootstrap.php'; // AJUSTAR entrypoint

$inicio = microtime(true);

try {
    $svc = new CarrinhoRecuperacaoService();

    $novos       = $svc->detectarAbandonados();
    $recuperados = $svc->reconciliarRecuperados();
    $sugestoes   = $svc->contarSugestaoPerdidos();

    error_log(sprintf(
        '[carrinhos-cron] ok novos=%d recuperados=%d sugerir_perdido=%d dur=%dms',
        $novos, $recuperados, $sugestoes,
        (int)round((microtime(true) - $inicio) * 1000)
    ));
    exit(0);

} catch (\Throwable $e) {
    error_log('[carrinhos-cron] FALHA: ' . $e->getMessage());
    exit(1);
}
*/