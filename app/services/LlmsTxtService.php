<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════════════════
// app/services/LlmsTxtService.php
//
// Monta o /llms.txt — a loja explicada para um modelo de linguagem.
//
// ── PARA QUE SERVE ─────────────────────────────────────────────────
//
// Quando alguém pergunta a uma IA "qual capacete serve na minha CG 160?", o
// assistente ou responde do que aprendeu, ou busca e LÊ páginas. Nos dois
// casos ele precisa entender rápido: que loja é esta, o que ela vende, como
// saber se uma peça serve, quanto custa entregar, o que acontece se o cliente
// devolver.
//
// Uma página de e-commerce responde isso mal: o texto útil vem cercado de
// menu, filtro, banner e script. O `llms.txt` é a versão sem cerimônia —
// texto puro, curto, com os links para quem quiser o detalhe.
//
// É convenção emergente (llmstxt.org), não padrão com validador. Nenhum robô
// é obrigado a ler. Custa uma rota e some do caminho de quem não usa; o
// downside é zero e o upside é ser entendido corretamente quando é lido.
//
// ── REGRA DO CONTEÚDO ──────────────────────────────────────────────
//
// Tudo sai do banco e da configuração. Nada de texto de marketing escrito
// aqui: se a loja mudar de categoria, de marca ou de prazo, o arquivo muda
// junto. Texto cravado no código é texto que mente depois de um mês.
// ════════════════════════════════════════════════════════════════════

class LlmsTxtService
{
    /** Teto de itens por lista — o arquivo tem de caber numa leitura. */
    private const TETO_CATEGORIAS = 40;
    private const TETO_MARCAS     = 40;
    private const TETO_MONTADORAS = 30;
    private const TETO_FAQ        = 15;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public function gerar(): string
    {
        $loja = (string) ConfigHelper::get('site_nome', 'Loja');
        $l    = [];

        $l[] = '# ' . $loja;
        $l[] = '';
        $l[] = '> ' . $this->resumo();
        $l[] = '';
        $l[] = 'Site: ' . BASE_URL;
        $l[] = 'Atualizado: ' . date('Y-m-d');
        $l[] = '';

        $l[] = '## O que esta loja faz';
        $l[] = '';
        foreach ($this->oQueFaz() as $linha) $l[] = '- ' . $linha;
        $l[] = '';

        $l[] = '## Como saber se uma peça serve na moto';
        $l[] = '';
        $l[] = 'O catálogo tem compatibilidade por montadora, modelo e ano. Os endereços seguem este formato:';
        $l[] = '';
        $l[] = '- `' . BASE_URL . '/montadora/{montadora}` — tudo que serve na marca da moto';
        $l[] = '- `' . BASE_URL . '/montadora/{montadora}/{modelo}` — por modelo';
        $l[] = '- `' . BASE_URL . '/montadora/{montadora}/{modelo}-{ano}` — por modelo e ano';
        $l[] = '';
        $l[] = 'Exemplo: `' . BASE_URL . '/montadora/honda/cg-160-2017`.';
        $l[] = 'A página de cada produto lista as motos compatíveis. Na dúvida, a compatibilidade da página vale mais que a suposição pelo nome da peça.';
        $l[] = '';

        if ($montadoras = $this->montadoras()) {
            $l[] = '### Montadoras com peças no catálogo';
            $l[] = '';
            foreach ($montadoras as $m) $l[] = '- [' . $m['nome'] . '](' . $m['url'] . ')';
            $l[] = '';
        }

        if ($cats = $this->categorias()) {
            $l[] = '## Categorias';
            $l[] = '';
            foreach ($cats as $c) {
                $l[] = '- [' . $c['nome'] . '](' . $c['url'] . ')'
                     . ($c['total'] > 0 ? ' — ' . $c['total'] . ' ' . ($c['total'] === 1 ? 'produto' : 'produtos') : '');
            }
            $l[] = '';
        }

        if ($marcas = $this->marcas()) {
            $l[] = '## Marcas';
            $l[] = '';
            foreach ($marcas as $m) $l[] = '- [' . $m['nome'] . '](' . $m['url'] . ')';
            $l[] = '';
        }

        if ($faq = $this->faq()) {
            $l[] = '## Perguntas frequentes';
            $l[] = '';
            foreach ($faq as $f) {
                $l[] = '### ' . $f['pergunta'];
                $l[] = '';
                $l[] = $f['resposta'];
                $l[] = '';
            }
        }

        if ($paginas = $this->paginas()) {
            $l[] = '## Políticas e informações';
            $l[] = '';
            foreach ($paginas as $p) $l[] = '- [' . $p['titulo'] . '](' . $p['url'] . ')';
            $l[] = '';
        }

        $l[] = '## Endereços úteis';
        $l[] = '';
        $l[] = '- [Central de ajuda](' . BASE_URL . '/ajuda) — dúvidas de compra, entrega, troca e devolução';
        $l[] = '- [Peças por moto](' . BASE_URL . '/motos) — escolher pela montadora';
        $l[] = '- [Todas as marcas](' . BASE_URL . '/marcas)';
        $l[] = '- [Promoções](' . BASE_URL . '/promocoes)';
        $l[] = '- [Rastrear pedido](' . BASE_URL . '/rastrear-pedido)';
        $l[] = '- [Mapa do site completo](' . BASE_URL . '/sitemap.xml)';
        $l[] = '';

        $l[] = '## Como citar';
        $l[] = '';
        $l[] = 'Preço, disponibilidade e prazo mudam. Cada página de produto traz os dados atuais em '
             . 'JSON-LD (schema.org/Product, com `offers.price` e `offers.availability`) — é a fonte '
             . 'a consultar antes de afirmar valor ou estoque.';
        $l[] = '';

        return implode("\n", $l) . "\n";
    }

    /* ================================================================
       FONTES — tudo do banco
       ================================================================ */

    private function resumo(): string
    {
        $texto = trim((string) (ConfigHelper::get('site_descricao', '') ?: ConfigHelper::get('seo_description', '')));
        if ($texto === '') $texto = 'Loja de peças e acessórios para motos.';

        $n = $this->conta("SELECT COUNT(*) FROM produtos WHERE ativo = 1");
        if ($n > 0) $texto .= ' Catálogo com ' . number_format($n, 0, ',', '.') . ' produtos ativos.';

        return preg_replace('/\s+/u', ' ', $texto);
    }

    /** @return string[] */
    private function oQueFaz(): array
    {
        $out = ['Vende peças e acessórios para motos, com entrega em todo o Brasil.'];

        $cidade = trim((string) ConfigHelper::get('endereco_cidade', ''));
        $uf     = trim((string) ConfigHelper::get('endereco_uf', ''));
        if ($cidade !== '') {
            $out[] = 'Loja física em ' . $cidade . ($uf !== '' ? '/' . $uf : '') . ', além da loja online.';
        }

        $tel = trim((string) ConfigHelper::get('site_telefone', ''));
        if ($tel !== '') $out[] = 'Atendimento: ' . $tel . '.';

        $out[] = 'Compatibilidade de peça por montadora, modelo e ano da moto.';
        $out[] = 'Pagamento com cartão, Pix e boleto; parcelamento conforme a página do produto.';

        return $out;
    }

    private function categorias(): array
    {
        $sql = "SELECT c.nome, c.slug, COUNT(p.id) AS total
                  FROM categorias c
             LEFT JOIN produtos p ON p.categoria_id = c.id AND p.ativo = 1
                 WHERE c.ativo = 1 AND c.slug <> ''
              GROUP BY c.id, c.nome, c.slug
              HAVING total > 0
              ORDER BY total DESC, c.nome
                 LIMIT " . self::TETO_CATEGORIAS;

        return array_map(static fn(array $r) => [
            'nome'  => (string) $r['nome'],
            'url'   => BASE_URL . '/categoria/' . $r['slug'],
            'total' => (int) $r['total'],
        ], $this->linhas($sql));
    }

    private function marcas(): array
    {
        $sql = "SELECT m.nome, m.slug
                  FROM marcas m
                  JOIN produtos p ON p.marca_id = m.id AND p.ativo = 1
                 WHERE m.ativo = 1 AND m.slug <> ''
              GROUP BY m.id, m.nome, m.slug
              ORDER BY m.nome
                 LIMIT " . self::TETO_MARCAS;

        return array_map(static fn(array $r) => [
            'nome' => (string) $r['nome'],
            'url'  => BASE_URL . '/marca/' . $r['slug'],
        ], $this->linhas($sql));
    }

    private function montadoras(): array
    {
        $sql = "SELECT mm.nome, mm.slug
                  FROM moto_montadoras mm
                  JOIN produto_compatibilidade pc ON pc.montadora_id = mm.id
                 WHERE mm.ativo = 1 AND mm.slug <> ''
              GROUP BY mm.id, mm.nome, mm.slug
              ORDER BY mm.nome
                 LIMIT " . self::TETO_MONTADORAS;

        return array_map(static fn(array $r) => [
            'nome' => (string) $r['nome'],
            'url'  => BASE_URL . '/montadora/' . $r['slug'],
        ], $this->linhas($sql));
    }

    private function faq(): array
    {
        $sql = "SELECT pergunta, resposta
                  FROM help_perguntas
                 WHERE ativo = 1 AND resposta <> ''
              ORDER BY visualizacoes DESC, ordem
                 LIMIT " . self::TETO_FAQ;

        return array_map(static function (array $r): array {
            $resposta = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $r['resposta'])));
            return [
                'pergunta' => trim((string) $r['pergunta']),
                'resposta' => mb_strimwidth($resposta, 0, 600, '…'),
            ];
        }, $this->linhas($sql));
    }

    private function paginas(): array
    {
        $out = [];
        try {
            foreach (PaginaService::todas() as $p) {
                if (empty($p['slug']) || !empty($p['noindex'])) continue;
                $out[] = [
                    'titulo' => (string) ($p['titulo'] ?? $p['menu_label'] ?? $p['slug']),
                    'url'    => BASE_URL . '/' . $p['slug'],
                ];
            }
        } catch (Throwable $e) {
            LogService::exception($e, 'warning', 'app', ['onde' => 'LlmsTxtService::paginas']);
        }
        return $out;
    }

    /* ================================================================ */

    private function linhas(string $sql): array
    {
        try {
            return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Uma lista que falha não pode derrubar o arquivo inteiro.
            LogService::exception($e, 'warning', 'app', ['onde' => 'LlmsTxtService', 'sql' => mb_substr($sql, 0, 60)]);
            return [];
        }
    }

    private function conta(string $sql): int
    {
        try {
            return (int) $this->db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
