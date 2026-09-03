<?php

// admin/controllers/SeoIaController.php

class SeoIaController extends Controller {

    public function __construct() {
        AuthHelper::requireAdmin();
    }

    /**
     * Endpoint Ajax universal — gera SEO para qualquer tipo.
     * POST /admin/seo-ia/gerar
     */
    public function gerar(): void {
        $this->verifyCsrf();

        $tipo     = SecurityHelper::sanitizeString($_POST['tipo']    ?? '');
        $contexto = $_POST['contexto'] ?? [];
        $idioma   = SecurityHelper::sanitizeString($_POST['idioma']  ?? 'pt-BR');

        $tiposPermitidos = ['produto', 'categoria', 'marca', 'pagina'];
        if (!in_array($tipo, $tiposPermitidos, true)) {
            $this->json(['ok' => false, 'msg' => 'Tipo inválido.']);
        }

        // A chave do SEO não vem mais do .env: o SeoIaService roda pelo
        // orquestrador da Central de IA e a credencial fica cifrada em
        // ia_provedores. Checar GEMINI_API_KEY aqui barrava a geração mesmo
        // com o provedor corretamente configurado na tela. Quem não estiver
        // configurado agora recebe a mensagem exata do serviço (tipo ausente,
        // nenhum modelo ativo, teto de gasto atingido), tratada no catch abaixo.

        // Sanitiza o contexto
        $contextoLimpo = [];
        $camposPermitidos = [
            'nome', 'descricao', 'descricao_curta', 'categoria',
            'marca', 'preco', 'parent', 'titulo', 'conteudo',
        ];
        foreach ($camposPermitidos as $campo) {
            if (!empty($contexto[$campo])) {
                $contextoLimpo[$campo] = mb_substr(
                    SecurityHelper::sanitizeString((string)$contexto[$campo]),
                    0, 800
                );
            }
        }

        if (empty($contextoLimpo['nome']) && empty($contextoLimpo['titulo'])) {
            $this->json([
                'ok'  => false,
                'msg' => 'Informe pelo menos o nome para gerar o SEO.',
            ]);
        }

        // Modelo escolhido no seletor e entidade alvo. Os dois são opcionais e
        // vêm do navegador — o service revalida ambos contra o catálogo antes
        // de usar; aqui é só o transporte.
        $modeloId = !empty($_POST['modelo_id']) ? (int) $_POST['modelo_id'] : null;
        $alvoId   = !empty($_POST['alvo_id'])   ? (int) $_POST['alvo_id']   : 0;
        $alvo     = $alvoId > 0 ? ['entidade' => $tipo, 'id' => $alvoId] : null;

        try {
            $seoService = new SeoIaService();
            $resultado  = $seoService->gerarSeo($tipo, $contextoLimpo, $idioma, $modeloId, $alvo);

            // Loga o uso
            LogService::info("SEO IA gerado: tipo={$tipo}, nome=" . ($contextoLimpo['nome'] ?? ''));

            $this->json(['ok' => true, 'seo' => $resultado]);

        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Modelos de texto disponíveis para o seletor + a procedência atual da
     * entidade, numa chamada só (a tela precisa dos dois ao abrir).
     * GET /admin/seo-ia/modelos?tipo=produto&alvo_id=123
     */
    public function modelos(): void {
        $svc  = new SeoIaService();
        $tipo = SecurityHelper::sanitizeString($_GET['tipo'] ?? '');
        $id   = !empty($_GET['alvo_id']) ? (int) $_GET['alvo_id'] : 0;

        $this->json([
            'ok'          => true,
            'modelos'     => $svc->modelosDisponiveis(),
            'procedencia' => ($tipo !== '' && $id > 0) ? $svc->procedencia($tipo, $id) : null,
        ]);
    }

    /**
     * Marca que a geração foi APLICADA aos campos da entidade — é o clique em
     * "Aplicar", não o de "Gerar". Só depois disso o texto da IA é o conteúdo
     * da loja, e é isso que o badge de procedência informa.
     * POST /admin/seo-ia/aplicado
     */
    public function aplicado(): void {
        $this->verifyCsrf();

        $tipo       = SecurityHelper::sanitizeString($_POST['tipo'] ?? '');
        $geracaoId  = (int) ($_POST['geracao_id'] ?? 0);
        $entidadeId = (int) ($_POST['alvo_id'] ?? 0);

        if ($entidadeId <= 0) {
            // Cadastro novo ainda sem id: gerar e aplicar funcionam, só não há
            // onde ancorar a procedência. Não é erro.
            $this->json(['ok' => true, 'registrado' => false, 'msg' => 'Sem id — salve o cadastro para registrar a procedência.']);
        }

        $svc = new SeoIaService();
        $ok  = $svc->registrarAplicacao($geracaoId, $tipo, $entidadeId, AuthHelper::usuarioId());

        $this->json([
            'ok'          => $ok,
            'registrado'  => $ok,
            'procedencia' => $ok ? $svc->procedencia($tipo, $entidadeId) : null,
            'msg'         => $ok ? null : 'Não foi possível registrar a procedência do SEO.',
        ]);
    }
}