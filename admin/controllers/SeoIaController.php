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

        if (empty(GEMINI_API_KEY)) {
            $this->json([
                'ok'  => false,
                'msg' => 'Chave da API Gemini não configurada. Verifique o .env',
            ]);
        }

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

        try {
            $seoService = new SeoIaService();
            $resultado  = $seoService->gerarSeo($tipo, $contextoLimpo, $idioma);

            // Loga o uso
            LogService::info("SEO IA gerado: tipo={$tipo}, nome=" . ($contextoLimpo['nome'] ?? ''));

            $this->json(['ok' => true, 'seo' => $resultado]);

        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }
}