<?php
/**
 * RastreioPublicoController — página pública de rastreio.
 *
 * SEM autenticação: acessível pelo token compartilhável. Renderiza uma página
 * autossuficiente (não usa o layout admin). Só expõe dados sanitizados
 * (RastreioService::sanitizarPublico) — sem IDs internos, custo ou endereço.
 */
class RastreioPublicoController extends Controller
{
    private RastreioService $rastreios;

    public function __construct()
    {
        // Público — nenhuma verificação de permissão.
        $this->rastreios = new RastreioService();
    }

    /**
     * GET /rastreio/{token}
     * Recebe o token pela rota; cai para $_GET['token'] ou último segmento da URL.
     */
    public function rastrear($token = null): void
    {
        $token = (string)($token ?? ($_GET['token'] ?? ''));
        if ($token === '') {
            $seg = explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''))[0];
            $partes = array_values(array_filter(explode('/', $seg)));
            $token = end($partes) ?: '';
        }

        $dados = $token !== '' ? $this->rastreios->porToken($token) : null;

        // Página autossuficiente (não passa pelo layout admin).
        $view = __DIR__ . '/../../views/logistica/rastreio-publico.php';
        $rastreio = $dados;                 // disponível na view
        header('Content-Type: text/html; charset=UTF-8');
        include $view;
    }
}
