<?php
// app/controllers/AppHistoricoController.php
// Histórico de navegação.
//
// É o que alimenta as seções personalizadas da home ("Baseado no que você
// viu", "Relacionado às suas buscas") — então não é só uma tela de consulta:
// é a matéria-prima da personalização.
//
// Também é dado pessoal. Por isso `DELETE /historico` existe e apaga de
// verdade: LGPD à parte, o cliente tem que conseguir limpar o que a loja
// registrou sobre ele sem pedir para ninguém.

class AppHistoricoController extends AppApiController
{
    /**
     * GET /api/app/v1/conta/historico
     */
    public function index(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $pagina = $this->pagina(30, 60);
        $ctx    = $this->contexto();
        $modelo = new History();

        try {
            $itens = $modelo->getClienteHistory((int)$this->clienteId, $pagina['limit'], $pagina['offset']);
            $total = $modelo->countClienteHistory((int)$this->clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'historico']);
            $this->falha(500, 'falha_historico', 'Não foi possível carregar seu histórico.');
        }

        $this->okPaginado(
            'itens',
            HistoricoPresenter::colecao($itens, $ctx),
            $total,
            $pagina
        );
    }

    /**
     * GET /api/app/v1/conta/historico/resumo
     *
     * O retrato do gosto do cliente: mais vistos, categorias e marcas
     * favoritas, buscas recentes. Numa chamada só, porque a tela mostra os
     * quatro blocos juntos.
     */
    public function resumo(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $ctx    = $this->contexto();
        $modelo = new History();
        $id     = (int)$this->clienteId;

        try {
            $maisVistos  = $modelo->getMaisVistos($id, 8);
            $categorias  = $modelo->getCategoriasFavoritas($id, 5);
            $marcas      = $modelo->getMarcasFavoritas($id, 5);
            $buscas      = $modelo->getTermosBusca($id, 10);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'historico_resumo']);
            $maisVistos = $categorias = $marcas = $buscas = [];
        }

        $this->ok([
            // Os mais vistos passam pelo card padrão: é uma vitrine, e o
            // usuário espera poder tocar e comprar dali.
            // getMaisVistos() devolve a linha de `produtos`, então a chave é
            // `id` — mas aceitamos `produto_id` porque a versão anterior do
            // método (comentada em History.php:125) usava esse nome.
            'mais_vistos' => $maisVistos
                ? ProductCardPresenter::colecao(
                    (new Product())->getByFilters(
                        ['ids' => array_values(array_filter(array_map(
                            static fn(array $p) => (int)($p['id'] ?? $p['produto_id'] ?? 0),
                            $maisVistos
                        )))],
                        count($maisVistos)
                    ),
                    $ctx
                )
                : [],

            'categorias' => array_values(array_map(static fn(array $c) => [
                'id'    => (int)($c['categoria_id'] ?? $c['id'] ?? 0),
                'nome'  => $c['nome'] ?? null,
                'slug'  => $c['slug'] ?? null,
                'visitas' => (int)($c['total'] ?? $c['visitas'] ?? 0),
            ], $categorias)),

            'marcas' => array_values(array_map(static fn(array $m) => [
                'id'    => (int)($m['marca_id'] ?? $m['id'] ?? 0),
                'nome'  => $m['nome'] ?? null,
                'slug'  => $m['slug'] ?? null,
                'logo'  => $ctx->url($m['logo'] ?? null),
                'visitas' => (int)($m['total'] ?? $m['visitas'] ?? 0),
            ], $marcas)),

            'buscas' => array_values(array_map(
                static fn($b) => is_array($b) ? ($b['termo_busca'] ?? $b['termo'] ?? '') : (string)$b,
                $buscas
            )),
        ]);
    }

    /**
     * DELETE /api/app/v1/conta/historico
     *
     * Apaga de verdade. Devolve `invalidar_home` porque as seções
     * personalizadas somem junto — deixar o cache antigo faria a home continuar
     * mostrando "baseado no que você viu" depois de o usuário apagar o que viu.
     */
    public function limpar(): void
    {
        $this->bootCliente();

        try {
            (new History())->clearHistory((int)$this->clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'limpar_historico']);
            $this->liberarSessao();
            $this->falha(500, 'falha_historico', 'Não foi possível limpar o histórico.');
        }

        AppLog::audit('Histórico de navegação apagado pelo cliente', ['cliente_id' => $this->clienteId]);
        $this->liberarSessao();

        $this->ok(['limpo' => true, 'invalidar_home' => true]);
    }

    /**
     * POST /api/app/v1/conta/historico/tempo   Corpo: { id, segundos }
     *
     * Quanto tempo o usuário ficou num item. É sinal de interesse muito melhor
     * que a simples visita — quem olha um capacete por dois minutos está mais
     * perto de comprar que quem passou por ele em dois segundos.
     */
    public function tempo(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['id', 'segundos']);
        $this->liberarSessao();

        $segundos = (int)$corpo['segundos'];

        // Teto de 30 min: aba esquecida aberta não é interesse, e sem limite
        // um valor absurdo distorce a personalização de todo mundo.
        if ($segundos <= 0 || $segundos > 1800) {
            $this->ok(['registrado' => false]);
        }

        try {
            (new History())->updateTime((int)$corpo['id'], $segundos);
        } catch (\Throwable $e) {
            // Métrica de enriquecimento nunca derruba a requisição.
        }

        $this->ok(['registrado' => true]);
    }
}
