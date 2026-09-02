<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/AdminRodapeController.php
//
// Editor do rodapé da loja.
//
// Uma tela só para o rodapé, e não mais uma aba dentro de Configurações, porque
// o conteúdo dele é composto: listas de links, benefícios, selos. A tela genérica
// edita uma chave por vez e não tem como montar isso.
//
// Nível: super/gerente. O rodapé carrega CNPJ, endereço e os links legais — é
// dado da empresa em página pública, não conteúdo de catálogo.
// ════════════════════════════════════════════════════════

class AdminRodapeController extends Controller
{
    private FooterService $footer;

    public function __construct()
    {
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->footer = new FooterService();
    }

    // ── GET /admin/configuracoes/rodape ──────────────────
    public function index(): void
    {
        $this->render('configuracoes/rodape', [
            'page_title' => 'Rodapé da loja',
            'cfg'        => $this->footer->valores(),
            'loja'       => $this->footer->valoresLoja(),
            'icones'     => FooterService::icones(),
            'pagamentos' => FooterService::pagamentos(),
            'previa'     => $this->footer->dados(),
        ], 'admin');
    }

    // ── POST /admin/configuracoes/rodape/salvar ──────────
    public function salvar(): void
    {
        $this->verifyCsrf();

        // As listas chegam como JSON num campo escondido: o formulário monta as
        // linhas no navegador, e mandar name="colunas[0][links][2][url]" faria a
        // ordem depender do PHP remontar índices esparsos depois de remoções.
        $post = $_POST;
        foreach (FooterService::definicoes() as $chave => $def) {
            if ($def['tipo'] !== 'json' || !isset($post[$chave]) || !is_string($post[$chave])) continue;
            $post[$chave] = json_decode($post[$chave], true) ?? [];
        }

        $r = $this->footer->salvar($post);

        if (!empty($r['ok'])) {
            LogService::audit('Rodapé atualizado', ['usuario_id' => AuthHelper::usuarioId()]);
            $r['previa'] = $this->footer->dados();
        }

        $this->json($r);
    }
}
