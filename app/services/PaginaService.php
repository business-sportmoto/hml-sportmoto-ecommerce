<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/PaginaService.php
//
// Regras das páginas de conteúdo: slug, validação e sanitização.
//
// ── Onde mora a segurança ─────────────────────────────────────────────────
// O conteúdo é HTML digitado no painel e renderizado sem escape numa página
// pública que compartilha domínio com o checkout. A defesa é o
// HtmlHelper::sanitizeRich() (HTML Purifier) chamado AQUI, no momento de
// gravar — o editor do painel é conforto, e um POST direto ignora o editor.
// ════════════════════════════════════════════════════════

class PaginaService
{
    private Pagina $model;

    public function __construct(?Pagina $model = null)
    {
        $this->model = $model ?? new Pagina();
    }

    /**
     * Primeiros segmentos de URL que o roteador já usa.
     *
     * Uma página criada com um desses slugs nunca seria alcançada: o curinga
     * /{slug} é a ÚLTIMA rota do arquivo, então /carrinho sempre cai no
     * CarrinhoController. Sem esta checagem o admin salvaria uma página que
     * simplesmente não abre, sem nenhum erro para explicar por quê.
     *
     * Extraída de config/routes.php. Rota nova de primeiro nível entra aqui.
     */
    public const SLUGS_RESERVADOS = [
        'admin', 'ajax', 'ajuda', 'api', 'autenticacao-2fa', 'auth', 'avaliacao',
        'avaliacoes', 'banner', 'beacon', 'busca', 'cadastro', 'carrinho',
        'categoria', 'cep', 'checkout', 'clip', 'clips', 'consent', 'cupom',
        'dica', 'favoritos', 'feed', 'frete', 'historico', 'home', 'ir', 'lista',
        'login', 'manifest', 'marca', 'marcas', 'meu-veiculo', 'minha-conta',
        'montadora', 'motos', 'newsletter', 'notificacoes', 'pages', 'perguntas',
        'produto', 'promocoes', 'rastreio', 'recuperar-senha', 'redefinir-senha',
        'sair', 'sessao', 'sitemap', 'storage', 'track', 'uploads',
        'verificar-documento', 'verificar-email', 'webhook', 'webhooks',
    ];

    /* =================================================================
       LEITURA
       ================================================================= */

    public function listar(array $filtros = []): array
    {
        return $this->model->listar($filtros);
    }

    public function porId(int $id): ?array
    {
        return $this->model->porId($id);
    }

    public function porSlug(string $slug, bool $somenteAtivas = true): ?array
    {
        return $this->model->porSlug($slug, $somenteAtivas);
    }

    public function publicadas(): array
    {
        return $this->model->publicadas();
    }

    /* =================================================================
       ESCRITA
       ================================================================= */

    /**
     * Cria ou atualiza. Devolve ['ok', 'msg', 'id'].
     *
     * Recebe o $_POST cru: quem valida é este método, não o controller nem a
     * view. Assim a mesma regra vale para o formulário, para um import e para
     * qualquer chamada futura.
     */
    public function salvar(array $post, int $id = 0): array
    {
        $titulo = trim(strip_tags((string) ($post['titulo'] ?? '')));
        if ($titulo === '') {
            return ['ok' => false, 'msg' => 'O título é obrigatório.'];
        }
        if (mb_strlen($titulo) > 200) {
            return ['ok' => false, 'msg' => 'O título passa de 200 caracteres.'];
        }

        // Slug em branco vira o título — é o caso comum, e obrigar a digitar
        // duas vezes só produz slug esquecido com maiúscula e acento.
        $slug = self::slugify((string) ($post['slug'] ?? '') ?: $titulo);
        if ($slug === '') {
            return ['ok' => false, 'msg' => 'Não foi possível gerar um endereço a partir do título.'];
        }
        if (mb_strlen($slug) > 120) {
            return ['ok' => false, 'msg' => 'O endereço passa de 120 caracteres.'];
        }

        if (in_array($slug, self::SLUGS_RESERVADOS, true)) {
            return ['ok' => false, 'msg' => "O endereço “{$slug}” é usado pelo próprio site. Escolha outro."];
        }
        if ($this->model->slugEmUso($slug, $id)) {
            return ['ok' => false, 'msg' => "Já existe outra página em “/{$slug}”."];
        }
        if (self::existeEmArquivo($slug)) {
            return ['ok' => false, 'msg' => "Já existe uma página montada em arquivo em “/{$slug}”. "
                . 'Ela tem prioridade e esta nunca apareceria.'];
        }

        $ativo = !empty($post['ativo']) ? 1 : 0;

        // Página vazia publicada é pior que 404: o cliente acha que a loja
        // esqueceu de escrever os termos.
        $conteudo = HtmlHelper::sanitizeRich((string) ($post['conteudo'] ?? ''));
        if ($ativo === 1 && trim(strip_tags($conteudo)) === '') {
            return ['ok' => false, 'msg' => 'Não dá para publicar uma página sem conteúdo. Salve como rascunho.'];
        }

        $dados = [
            'slug'             => $slug,
            'titulo'           => $titulo,
            'menu_label'       => trim(strip_tags((string) ($post['menu_label'] ?? ''))),
            'conteudo'         => $conteudo,
            'meta_title'       => trim(strip_tags((string) ($post['meta_title'] ?? ''))),
            'meta_description' => trim(strip_tags((string) ($post['meta_description'] ?? ''))),
            'ativo'            => $ativo,
            'ordem_menu'       => ($post['ordem_menu'] ?? '') !== '' ? (int) $post['ordem_menu'] : null,
            'no_menu'          => !empty($post['no_menu']) ? 1 : 0,
            'no_rodape'        => !empty($post['no_rodape']) ? 1 : 0,
            'noindex'          => !empty($post['noindex']) ? 1 : 0,
            'publicado_em'     => null,
        ];

        try {
            if ($id > 0) {
                $atual = $this->model->porId($id);
                if (!$atual) return ['ok' => false, 'msg' => 'Página não encontrada.'];

                // Preserva a primeira publicação; só carimba se estreou agora.
                $dados['publicado_em'] = $atual['publicado_em']
                    ?: ($ativo === 1 ? date('Y-m-d H:i:s') : null);

                $this->model->atualizar($id, $dados);
                $novoId = $id;
            } else {
                $dados['publicado_em'] = $ativo === 1 ? date('Y-m-d H:i:s') : null;
                $novoId = $this->model->criar($dados);
            }
        } catch (Throwable $e) {
            LogService::exception($e, 'error', 'app', ['onde' => 'PaginaService::salvar', 'slug' => $slug]);
            return ['ok' => false, 'msg' => 'Falha ao gravar a página.'];
        }

        return [
            'ok'   => true,
            'msg'  => $ativo ? 'Página publicada.' : 'Rascunho salvo.',
            'id'   => $novoId,
            'slug' => $slug,
            'url'  => BASE_URL . '/' . $slug,
        ];
    }

    public function excluir(int $id): array
    {
        $p = $this->model->porId($id);
        if (!$p) return ['ok' => false, 'msg' => 'Página não encontrada.'];

        $this->model->excluir($id);
        LogService::audit('Página excluída', [
            'pagina_id' => $id, 'slug' => $p['slug'], 'usuario_id' => AuthHelper::usuarioId(),
        ]);
        return ['ok' => true, 'msg' => 'Página excluída.'];
    }

    public function alternarAtivo(int $id): array
    {
        $novo = $this->model->alternarAtivo($id);
        if ($novo === null) return ['ok' => false, 'msg' => 'Página não encontrada.'];

        return ['ok' => true, 'ativo' => $novo,
                'msg' => $novo ? 'Página publicada.' : 'Página despublicada.'];
    }

    /* =================================================================
       AUXILIARES
       ================================================================= */

    /**
     * Texto → slug.
     *
     * Acento vira a letra sem acento (não some): "Trocas e devoluções" precisa
     * virar "trocas-e-devolucoes", e não "trocas-e-devolues".
     */
    public static function slugify(string $texto): string
    {
        $s = trim($texto);
        if ($s === '') return '';

        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($conv !== false) $s = $conv;
        }

        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /** O slug já é de uma página montada em arquivo? */
    public static function existeEmArquivo(string $slug): bool
    {
        if ($slug === '' || !defined('ROOT_PATH')) return false;
        $dir = ROOT_PATH . '/pages/' . $slug;
        return is_dir($dir) && is_file($dir . '/index.php');
    }
}
