<?php
/**
 * LogisticaController — páginas administrativas do módulo de logística.
 *
 * Fase 1 entrega a Torre de Controle (visão geral). As demais páginas
 * (transportadoras, calculadora, etiquetas, rastreios, reversa,
 * divergências, regras, API) entram nas fases seguintes, cada uma como
 * método/controller próprio reutilizando este mesmo esqueleto.
 *
 * Segue o padrão do projeto: extends Controller, permissão em cascata,
 * render com layout 'admin', respostas JSON via $this->json().
 */
class LogisticaController extends Controller
{
    private LogisticaService $logistica;

    public function __construct()
    {
        // Logística é do estoque (CLAUDE.md §4.2): chave nominal `logistica`
        // ou cargo. Era requirePermission() sem fallback — um admin `estoque`
        // criado pela tela (permissoes NULL) não entrava em tela nenhuma.
        AuthHelper::requirePermissaoOuNivel('logistica', 'super', 'gerente', 'estoque');
        $this->logistica = new LogisticaService();
    }

    /**
     * GET /admin/logistica  (e /admin/logistica/torre)
     * Renderiza a Torre com o primeiro carregamento já preenchido.
     */
    public function torre(): void
    {
        $filtros = $this->filtrosDaRequisicao();
        $dados   = $this->logistica->torre($filtros);

        $this->render('logistica/torre', [
            'titulo'        => 'Torre de Controle',
            'kpis'          => $dados['kpis'],
            'distribuicao'  => $dados['distribuicao'],
            'alertas'       => $dados['alertas'],
            'periodo'       => $dados['periodo'],
            'opcoes'        => $this->logistica->filtrosOpcoes(),
            'filtros'       => $filtros,
            'podeVerCustos' => $this->podeVerCustos(),
        ], 'admin');
    }

    /**
     * GET /admin/logistica/torre/dados
     * Endpoint leve (read-only) para o polling adaptativo e para a
     * aplicação de filtros sem recarregar a página.
     */
    public function torreDados(): void
    {
        $filtros = $this->filtrosDaRequisicao();
        $dados   = $this->logistica->torre($filtros);

        // Oculta indicadores de custo para quem não tem permissão.
        if (!$this->podeVerCustos()) {
            $dados['kpis']['gasto_fretes']       = null;
            $dados['kpis']['divergencias_valor'] = null;
        }

        $this->json([
            'ok'     => true,
            'dados'  => $dados,
            'em'     => date('c'),
        ]);
    }

    /* ---------------------------------------------------------------
       Helpers
       --------------------------------------------------------------- */

    /** Lê e sanitiza os filtros aceitos pela Torre. */
    private function filtrosDaRequisicao(): array
    {
        $g = static fn(string $k): ?string =>
            isset($_GET[$k]) && $_GET[$k] !== '' ? trim((string)$_GET[$k]) : null;

        return array_filter([
            'periodo'           => $g('periodo') ?? '7d',
            'inicio'            => $g('inicio'),
            'fim'               => $g('fim'),
            'transportadora_id' => $g('transportadora_id'),
            'servico'           => $g('servico'),
            'status'            => $g('status'),
            'uf'                => $g('uf'),
            'canal'             => $g('canal'),
        ], static fn($v) => $v !== null);
    }

    /**
     * Quem vê o custo real de frete: a chave fina `logistica.custos`, ou gestor.
     *
     * As duas checagens que viviam aqui procuravam AuthHelper::pode() e
     * ::temPermissao(), que não existem — o método sempre devolvia true. Com o
     * estoque entrando na logística, isso daria a ele o custo real de frete
     * sem ninguém ter decidido. A permissão fina que o comentário original
     * previa passa a valer; o gestor passa pelo cargo.
     */
    private function podeVerCustos(): bool
    {
        return Session::adminTemPermissao('logistica.custos')
            || AuthHelper::hasLevel('super', 'gerente');
    }
}
