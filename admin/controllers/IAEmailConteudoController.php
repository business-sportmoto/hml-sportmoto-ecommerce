<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// admin/controllers/IAEmailConteudoController.php
// ════════════════════════════════════════════════════════

/**
 * Conteúdo de e-mail por segmento — Fase 2.
 *
 * Fluxo: escolher esqueleto → escolher produtos → briefing → IA escreve a
 * copy → revisar e editar → salvar como template de campanha (rascunho).
 *
 * O HTML NUNCA vem do navegador. Diferente da Fase 1, aqui o servidor
 * remonta tudo a partir de template_id + ids dos produtos + valores: não há
 * como injetar markup pelo POST. Os valores de copy o usuário pode editar —
 * eles entram escapados pelo renderizador.
 */
class IAEmailConteudoController extends Controller
{
    private const PERMISSAO = 'marketing_ia';

    private IAEmailConteudoService $svc;

    public function __construct()
    {
        $this->exigirPermissao(self::PERMISSAO);
        $this->svc = new IAEmailConteudoService();
    }

    // ── GET /admin/ia/email-conteudo ──────────────────────
    public function index(): void
    {
        $this->render('ia/email-conteudo/index', [
            'esqueletos' => $this->svc->esqueletosDisponiveis(),
            'filtros'    => $this->svc->filtrosDisponiveis(),
            'modelos'    => $this->svc->modelosDisponiveis(),
            'csrf'       => SecurityHelper::generateCsrf(),
        ], 'admin');
    }

    // ── POST /admin/ia/email-conteudo/produtos ────────────
    // Prévia da seleção. Não chama IA, não custa nada.
    public function produtos(): void
    {
        $this->verifyCsrf();

        $produtos = $this->svc->selecionarProdutos($this->criterioDoPost());

        $this->json([
            'ok'       => true,
            'produtos' => array_map(fn ($p) => [
                'id'     => $p['id'],
                'nome'   => $p['nome'],
                'preco'  => $p['preco'],
                'imagem' => $p['imagem_principal'],
            ], $produtos),
        ]);
    }

    // ── POST /admin/ia/email-conteudo/gerar ───────────────
    public function gerar(): void
    {
        $this->verifyCsrf();

        $templateId = (int) ($_POST['template_id'] ?? 0);
        $esqueleto  = $this->svc->esqueleto($templateId);
        if ($esqueleto === null) {
            $this->json(['ok' => false, 'msg' => 'Escolha um layout de base.']);
        }

        $produtos = $this->svc->selecionarProdutos($this->criterioDoPost());
        if ($produtos === []) {
            $this->json(['ok' => false, 'msg' => 'Nenhum produto vendável casou com esse critério.']);
        }

        $classes = $this->svc->classificarVariaveis((string) $esqueleto['html']);
        $briefing = SecurityHelper::sanitizeString($_POST['briefing'] ?? '');

        $opcoes = [];
        foreach (['objetivo', 'publico', 'tom'] as $campo) {
            $v = SecurityHelper::sanitizeString($_POST[$campo] ?? '');
            if ($v !== '') { $opcoes[$campo] = mb_substr($v, 0, 200); }
        }

        $modeloId = !empty($_POST['modelo_id']) ? (int) $_POST['modelo_id'] : null;

        try {
            // Layout sem variável de copy é legítimo — uma vitrine pura. Nesse
            // caso não há o que a IA escreva, e chamar o provedor só para
            // receber um objeto vazio seria gastar por nada.
            if ($classes['copy'] === []) {
                $valores  = $this->svc->constantesDaLoja();
                $conteudo = ['valores' => [], 'notas' => '', '_ia' => null];
            } else {
                $conteudo = $this->svc->gerarConteudo(
                    $produtos, $classes['copy'], $briefing, $opcoes, $modeloId
                );
                $valores = array_merge($this->svc->constantesDaLoja(), $conteudo['valores']);
            }

            $html = $this->svc->montar((string) $esqueleto['html'], $produtos, $valores);

            $this->json([
                'ok'        => true,
                'html'      => $html,
                'assunto'   => (string) $esqueleto['assunto'],
                'preheader' => (string) ($esqueleto['preheader'] ?? ''),
                'valores'   => $valores,
                'classes'   => $classes,
                'produtos'  => array_column($produtos, 'id'),
                'notas'     => $conteudo['notas'],
                'bytes'     => strlen($html),
                '_ia'       => $conteudo['_ia'],
            ]);

        } catch (\RuntimeException $e) {
            $this->json(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'app', ['acao' => 'ia_email_conteudo_gerar']);
            $this->json(['ok' => false, 'msg' => 'Falha ao gerar o conteúdo. O erro foi registrado.']);
        }
    }

    // ── POST /admin/ia/email-conteudo/montar ──────────────
    // Remonta com os textos editados. NÃO chama IA — é de graça, e serve para
    // a prévia refletir exatamente o que o salvar vai gravar: os dois passam
    // pelo mesmo caminho.
    public function montar(): void
    {
        $this->verifyCsrf();

        [$esqueleto, $produtos, $valores, $erro] = $this->remontar();
        if ($erro !== null) {
            $this->json(['ok' => false, 'msg' => $erro]);
        }

        $html = $this->svc->montar((string) $esqueleto['html'], $produtos, $valores);
        $this->json(['ok' => true, 'html' => $html, 'bytes' => strlen($html)]);
    }

    // ── POST /admin/ia/email-conteudo/salvar ──────────────
    public function salvar(): void
    {
        $this->verifyCsrf();

        [$esqueleto, $produtos, $valores, $erro] = $this->remontar();
        if ($erro !== null) {
            $this->json(['ok' => false, 'msg' => $erro]);
        }
        $templateId = (int) ($_POST['template_id'] ?? 0);

        // Remontado no servidor: o navegador nunca envia HTML.
        $html = $this->svc->montar((string) $esqueleto['html'], $produtos, $valores);

        $r = $this->svc->salvarComoTemplate(
            SecurityHelper::sanitizeString($_POST['nome'] ?? ''),
            $html,
            SecurityHelper::sanitizeString($_POST['assunto'] ?? ''),
            SecurityHelper::sanitizeString($_POST['preheader'] ?? ''),
            (int) ($_POST['geracao_id'] ?? 0),
            $templateId,
            $this->criterioDoPost(),
            $produtos,
            SecurityHelper::sanitizeString($_POST['briefing'] ?? '')
        );

        if (!empty($r['ok'])) {
            $r['url'] = BASE_URL . '/admin/email-marketing/templates/' . (int) $r['template_id'] . '/editar';
        }

        $this->json($r);
    }

    /* ------------------------------------------------------------------ */

    /**
     * O caminho comum de montar() e salvar(): esqueleto + produtos da prévia +
     * valores editados. Um só lugar para as duas ações garante que a prévia
     * mostre exatamente o que vai ser gravado.
     *
     * @return array{0:?array, 1:array, 2:array, 3:?string}
     */
    private function remontar(): array
    {
        $esqueleto = $this->svc->esqueleto((int) ($_POST['template_id'] ?? 0));
        if ($esqueleto === null) {
            return [null, [], [], 'Layout de base não encontrado.'];
        }

        // Os ids vêm da PRÉVIA, não do critério. Com modo "aleatórios", refazer
        // a seleção aqui daria produtos diferentes dos que foram revisados.
        $ids = array_values(array_filter(
            array_map('intval', (array) ($_POST['produtos'] ?? [])), fn ($i) => $i > 0
        ));
        if ($ids === []) {
            return [null, [], [], 'Gere a prévia antes.'];
        }
        $produtos = $this->svc->selecionarProdutos(['modo' => 'escolhidos', 'ids' => $ids, 'limite' => count($ids)]);

        // Valores editados na tela. Sanitizados como TEXTO — entram no HTML
        // pelo renderizador, que escapa.
        $valores = [];
        foreach ((array) ($_POST['valores'] ?? []) as $k => $v) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $k)) { continue; }
            $valores[$k] = mb_substr(SecurityHelper::sanitizeString((string) $v), 0, 500);
        }

        return [$esqueleto, $produtos, $valores, null];
    }

    private function criterioDoPost(): array
    {
        return [
            'modo'   => SecurityHelper::sanitizeString($_POST['modo'] ?? 'todos'),
            'limite' => (int) ($_POST['limite'] ?? 3),
            'ids'    => array_map('intval', (array) ($_POST['ids'] ?? [])),
        ];
    }

    /** Mesma guarda da Central: granular primeiro, cargo depois, Ajax ≠ navegação. */
    private function exigirPermissao(string $permissao): void
    {
        AuthHelper::requireAdmin();
        if ((new IAPermissaoService())->pode($permissao)) {
            return;
        }
        http_response_code(403);
        if (AuthHelper::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sem permissão para esta ação.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Sem permissão</title>'
               . '<p style="font:16px system-ui;padding:2rem">Você não tem permissão para montar campanhas de e-mail.</p>';
        }
        exit;
    }
}
