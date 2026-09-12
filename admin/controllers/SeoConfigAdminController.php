<?php
declare(strict_types=1);

/**
 * admin/controllers/SeoConfigAdminController.php
 *
 * Configuração de SEO da loja — /admin/seo
 *
 * ── POR QUE ESTA TELA EXISTE ──────────────────────────────────────────
 *
 * Título da home, sufixo, descrição padrão e imagem de compartilhamento
 * moravam só na tabela `configuracoes`. Dava para mexer pela tela genérica
 * de configurações, mas ali é uma lista de chave/valor: ninguém vê o que o
 * título vira na busca, nem quanto ele passou do tamanho que o Google mostra.
 *
 * Aqui os mesmos campos aparecem com o nome do que fazem, com contador e com
 * pré-visualização. Quem escreve o texto é quem vende, não quem edita SQL.
 *
 * ── NÃO TEM CAMPO DE PALAVRAS-CHAVE, DE PROPÓSITO ────────────────────
 *
 * `<meta name="keywords">` não é usado pelo Google desde 2009 (declaração
 * pública deles) e é lido por concorrente para saber em que você aposta.
 * Preencher aquele campo dá sensação de trabalho feito e não move ranking.
 */
final class SeoConfigAdminController extends Controller
{
    /**
     * Os campos da tela. A lista é a ÚNICA porta: `salvar()` recusa chave
     * que não esteja aqui, então a tela não vira um editor de qualquer
     * configuração do sistema.
     *
     * limite = o que é razoável para o campo, não o que a coluna aceita.
     */
    private const CAMPOS = [
        'seo_titulo_home' => [
            'rotulo' => 'Título da loja (home)',
            'dica'   => 'O título da página inicial. Não recebe o sufixo — senão a marca apareceria duas vezes.',
            'tipo'   => 'string', 'limite' => 120, 'ideal' => 60,
        ],
        'seo_description' => [
            'rotulo' => 'Descrição padrão',
            'dica'   => 'Usada na home e em qualquer página sem descrição própria.',
            'tipo'   => 'text', 'limite' => 320, 'ideal' => 155,
        ],
        'seo_title_sufixo' => [
            'rotulo' => 'Sufixo do título',
            'dica'   => 'Entra no fim do título das páginas que NÃO têm título escrito à mão. Ex.: " | Sportmoto".',
            'tipo'   => 'string', 'limite' => 60, 'ideal' => 20,
        ],
        'seo_og_imagem' => [
            'rotulo' => 'Imagem de compartilhamento padrão',
            'dica'   => 'Aparece no WhatsApp e nas redes quando a página não tem foto. JPEG ou PNG, 1200×630. SVG não funciona.',
            'tipo'   => 'url', 'limite' => 500, 'ideal' => 0,
        ],
        'seo_titulo_produto' => [
            'rotulo' => 'Modelo de título dos produtos',
            'dica'   => 'Vale para produto SEM título próprio. Vazio = nome do produto + sufixo.',
            'tipo'   => 'string', 'limite' => 160, 'ideal' => 60,
            'campos' => ['[nome_produto]', '[marca]', '[loja]'],
        ],
        'seo_titulo_categoria' => [
            'rotulo' => 'Modelo de título das categorias',
            'dica'   => 'Vale para categoria SEM título próprio. Vazio = nome da categoria + sufixo.',
            'tipo'   => 'string', 'limite' => 160, 'ideal' => 60,
            'campos' => ['[nome_categoria]', '[loja]'],
        ],
        'seo_titulo_busca' => [
            'rotulo' => 'Modelo de título da busca',
            'dica'   => 'Vazio = "Busca: termo" + sufixo. A busca não é indexada; o título serve a quem compartilha o link.',
            'tipo'   => 'string', 'limite' => 160, 'ideal' => 60,
            'campos' => ['[termo]', '[loja]'],
        ],
        'seo_desc_categoria' => [
            'rotulo' => 'Descrição padrão das categorias',
            'dica'   => 'Para categoria sem descrição própria. Vazio = frase montada com o nome e o total de produtos.',
            'tipo'   => 'text', 'limite' => 320, 'ideal' => 155,
            'campos' => ['[nome_categoria]', '[total]', '[loja]'],
        ],
        'seo_desc_busca' => [
            'rotulo' => 'Descrição padrão da busca',
            'dica'   => 'Para a página de resultados. Vazio = frase montada com o termo.',
            'tipo'   => 'text', 'limite' => 320, 'ideal' => 155,
            'campos' => ['[termo]', '[loja]'],
        ],
    ];

    private PDO $db;

    public function __construct()
    {
        // Mesma régua da tela de configurações: quem mexe em texto que vai
        // para o Google é gestão, não operação.
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /admin/seo */
    public function index(): void
    {
        $valores = [];
        foreach (array_keys(self::CAMPOS) as $chave) {
            $valores[$chave] = (string) ConfigHelper::get($chave, '');
        }

        SeoHelper::setTitle('SEO da loja');

        $this->render('seo/config', [
            'campos'   => self::CAMPOS,
            'valores'  => $valores,
            'loja'     => (string) ConfigHelper::get('site_nome', ''),
            'exemplo'  => $this->exemploReal(),
            'sitemap'  => BASE_URL . '/sitemap.xml',
            'robots'   => BASE_URL . '/robots.txt',
        ], 'admin');
    }

    /** POST /admin/seo/salvar */
    public function salvar(): void
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $salvos = [];
        $erros  = [];

        foreach (self::CAMPOS as $chave => $def) {
            if (!array_key_exists($chave, $_POST)) continue;

            $valor = trim((string) $_POST[$chave]);

            // Texto que vai para dentro de uma meta tag: sem HTML, sem quebra
            // de linha. Quebra de linha em <title> não renderiza e ainda
            // desalinha o HTML da página.
            $valor = preg_replace('/\s+/u', ' ', strip_tags($valor));
            $valor = trim((string) $valor);

            if ($def['tipo'] === 'url' && $valor !== '' && !preg_match('~^https?://~i', $valor)) {
                $erros[$def['rotulo']] = 'Informe a URL completa, começando com https://';
                continue;
            }

            if (mb_strlen($valor) > (int) $def['limite']) {
                $erros[$def['rotulo']] = 'Passou de ' . $def['limite'] . ' caracteres.';
                continue;
            }

            $this->gravar($chave, $valor, $def['tipo'] === 'text' ? 'text' : 'string');
            $salvos[] = $chave;
        }

        // O ConfigHelper guarda em cache: sem limpar, a loja continua
        // mostrando o texto antigo até o cache virar.
        ConfigHelper::limparCache();

        if ($erros) {
            $this->json([
                'ok'    => false,
                'erros' => $erros,
                'msg'   => 'Alguns campos não foram salvos: ' . implode(' · ', array_keys($erros)),
            ]);
        }

        LogService::audit('Configuração de SEO alterada', [
            'chaves' => $salvos,
            'por'    => AuthHelper::usuarioId(),
        ]);

        $this->json(['ok' => true, 'msg' => 'Configuração de SEO salva.', 'salvos' => count($salvos)]);
    }

    /**
     * Grava a chave, criando a linha se ela ainda não existir.
     *
     * As chaves novas (modelos de título, imagem padrão) não existem em banco
     * antigo; sem o INSERT, salvar não daria erro e também não guardaria nada.
     */
    private function gravar(string $chave, string $valor, string $tipo): void
    {
        $st = $this->db->prepare("UPDATE configuracoes SET valor = ? WHERE chave = ?");
        $st->execute([$valor, $chave]);
        if ($st->rowCount() > 0) return;

        $existe = $this->db->prepare("SELECT 1 FROM configuracoes WHERE chave = ? LIMIT 1");
        $existe->execute([$chave]);
        if ($existe->fetchColumn()) return;   // já tinha o mesmo valor

        $this->db->prepare(
            "INSERT INTO configuracoes (chave, valor, tipo, grupo, descricao)
             VALUES (?, ?, ?, 'seo', ?)"
        )->execute([$chave, $valor, $tipo, self::CAMPOS[$chave]['rotulo'] ?? '']);
    }

    /**
     * Um produto e uma categoria de verdade, para a pré-visualização mostrar
     * o resultado com dado real em vez de "Lorem ipsum".
     */
    private function exemploReal(): array
    {
        $p = $this->db->query(
            "SELECT p.nome, p.slug, m.nome AS marca
               FROM produtos p LEFT JOIN marcas m ON m.id = p.marca_id
              WHERE p.ativo = 1 ORDER BY p.id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $c = $this->db->query(
            "SELECT nome, slug FROM categorias WHERE ativo = 1 ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'produto'   => (string) ($p['nome']  ?? 'Nome do produto'),
            'marca'     => (string) ($p['marca'] ?? 'Marca'),
            'categoria' => (string) ($c['nome']  ?? 'Categoria'),
            'url'       => rtrim(BASE_URL, '/') . '/produto/' . (string) ($p['slug'] ?? 'produto'),
        ];
    }
}
