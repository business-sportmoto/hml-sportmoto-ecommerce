<?php
// app/presenters/AjudaPresenter.php
//
// A central de ajuda — espelha views/help/index.php e views/help/categoria.php.
//
// Duas traduções acontecem aqui, e as duas existem porque o cadastro do FAQ foi
// feito para o navegador:
//
//  1. `help_categorias.icone` guarda a CHAVE de um SVG da IconLibrary da loja
//     ("truck", "engine", "person-circle"). O app tem Material Symbols e não
//     conhece esses nomes. O mapa abaixo converte. Mesma solução já usada em
//     BeneficioPresenter, e pelo mesmo motivo — só que aqui o vocabulário é
//     maior, porque o admin escolhe o ícone da categoria numa lista com os 155
//     ícones do assets/icons.json.
//
//  2. `help_perguntas.resposta` é HTML. O editor do admin oferece <strong>,
//     <em>, <u>, <br> e <a href> (admin/views/help_faq/pergunta_form.php:110),
//     e as respostas cadastradas usam listas com "•" separadas por <br>.
//     <Text> do React Native não interpreta HTML: mandar a string crua faria o
//     cliente ler "<strong>PIX</strong>" na tela. A conversão para blocos
//     acontece AQUI, no servidor, e não no app — o app recebe estrutura, nunca
//     marcação, e assim nenhuma tag nova cadastrada no admin vaza para a tela.
//
// Formato do texto rico:
//   blocos[] = { tipo: 'paragrafo'|'item', partes: [{ texto, negrito, italico,
//                sublinhado, destino }] }
//   'item' é uma linha que começa com marcador — vira lista de verdade no app,
//   com recuo pendurado, em vez de um "•" solto no meio do parágrafo.

final class AjudaPresenter
{
    /**
     * Chave do SVG da loja → nome do ícone no app.
     *
     * Cobre o vocabulário plausível de uma categoria de FAQ, não os 155 ícones
     * do arquivo: os que sobram caem no genérico. Uma categoria nova cadastrada
     * amanhã aparece com o ícone de ajuda em vez de ficar sem símbolo.
     */
    private const ICONES = [
        // Em uso hoje
        'truck'                 => 'entrega',
        'card'                  => 'cartao',
        'undo'                  => 'devolucao',
        'engine'                => 'motor',
        'person-circle'         => 'conta',

        // Entrega e logística
        'caminhao'              => 'entrega',
        'delivery-truck-speed'  => 'entrega',
        'package'               => 'vazio',
        'embalagem'             => 'vazio',
        'torre-pack'            => 'vazio',
        'returned'              => 'devolucao',
        'devolvido'             => 'devolucao',
        'reversa'               => 'devolucao',
        'rotate-left'           => 'devolucao',
        'refresh-cw'            => 'devolucao',
        'reload'                => 'devolucao',
        'sync'                  => 'devolucao',

        // Pagamento
        'credit-card-clock'     => 'cartao',
        'credit-score'          => 'cartao',
        'shield-card'           => 'cartao',
        'payments'              => 'cartao',
        'cash'                  => 'cartao',
        'boleto'                => 'codigoBarras',
        'barcode-scanner'       => 'codigoBarras',
        'pix-main'              => 'pix',
        'price-check'           => 'etiqueta',
        'discount'              => 'etiqueta',
        'etiqueta'              => 'etiqueta',
        'label'                 => 'etiqueta',
        'calculadora'           => 'etiqueta',

        // Conta e segurança
        'person-shield'         => 'seguro',
        'person-serach'         => 'conta', // grafia do cadastro, preservada
        'user_admin'            => 'conta',
        'lock'                  => 'senha',
        'shield-locked'         => 'senha',
        'key-arrpw-down'        => 'senha',  // idem
        'shield'                => 'seguro',
        'shield-check'          => 'seguro',
        'mail'                  => 'email',
        'mark_email_read'       => 'email',
        'home'                  => 'local',
        'add-location-alt'      => 'local',
        'edit-location-alt'     => 'local',
        'globe-location'        => 'local',

        // Moto e peças
        'motorcycle'            => 'moto',
        'two-wheeler'           => 'moto',
        'engrenagem'            => 'motor',
        'settings'              => 'motor',
        'build-circle'          => 'motor',
        'factory'               => 'marcas',
        'shelves'               => 'vazio',
        'grid'                  => 'categorias',
        'category'              => 'categorias',
        'stacks'                => 'categorias',

        // Atendimento
        'help'                  => 'ajuda',
        'question-circle'       => 'ajuda',
        'contact-support'       => 'suporte',
        'contact-support-2'     => 'suporte',
        'headphones'            => 'suporte',
        'chat-dashed'           => 'pergunta',
        'chat-info'             => 'pergunta',
        'business-messages'     => 'pergunta',
        'whatsapp'              => 'whatsapp',
        'info'                  => 'ajuda',
        'alert-circle'          => 'erro',
        'alert-triangle'        => 'erro',
        'alerta'                => 'erro',
        'star'                  => 'estrela',
        'favorite'              => 'favorito',
        'heart-check'           => 'favorito',
        'relogio'               => 'historico',
        'history-toggle-off'    => 'historico',
        'calendar-today'        => 'historico',
        'docs'                  => 'documento',
        'regras'                => 'documento',
        'rule'                  => 'documento',
        'format-list-bulleted'  => 'documento',
        'list'                  => 'documento',
    ];

    private const ICONE_PADRAO = 'ajuda';

    /**
     * Tags que o editor do admin oferece. Qualquer outra é descartada com o
     * texto preservado — uma tag desconhecida nunca deve sumir com o conteúdo.
     */
    private const TAGS_ESTILO = ['strong' => 'negrito', 'b' => 'negrito',
                                 'em' => 'italico', 'i' => 'italico',
                                 'u' => 'sublinhado'];

    /** Marcadores que o admin usa para montar lista. */
    private const MARCADORES = ['•', '-', '–', '—', '*'];

    /* ── Categorias ─────────────────────────────────────────────────────── */

    /** @return array<int,array> */
    public static function categorias(array $rows): array
    {
        return array_values(array_map(
            static fn(array $c) => self::categoria($c),
            $rows
        ));
    }

    public static function categoria(array $c): array
    {
        return [
            'id'        => (int)($c['id'] ?? 0),
            'nome'      => trim((string)($c['nome'] ?? '')),
            'slug'      => (string)($c['slug'] ?? ''),
            'icone'     => self::ICONES[$c['icone'] ?? ''] ?? self::ICONE_PADRAO,
            'descricao' => trim((string)($c['descricao'] ?? '')) ?: null,
            'total'     => (int)($c['total_perguntas'] ?? 0),
        ];
    }

    /* ── Perguntas ──────────────────────────────────────────────────────── */

    /** @return array<int,array> */
    public static function perguntas(array $rows): array
    {
        return array_values(array_map(
            static fn(array $p) => self::pergunta($p),
            $rows
        ));
    }

    public static function pergunta(array $p): array
    {
        $html = (string)($p['resposta'] ?? '');

        return [
            'id'            => (int)($p['id'] ?? 0),
            'categoria_id'  => (int)($p['categoria_id'] ?? 0),
            'pergunta'      => trim((string)($p['pergunta'] ?? '')),

            // O corpo formatado, pronto para <Text>.
            'resposta'      => self::blocos($html),

            // Versão sem formatação, para a prévia do resultado de busca e para
            // o rótulo de acessibilidade. O app não precisa achatar os blocos.
            'resposta_texto' => self::texto($html),
        ];
    }

    /* ── HTML → blocos ──────────────────────────────────────────────────── */

    /**
     * Converte o HTML restrito do editor numa árvore de blocos e trechos.
     *
     * @return array<int,array{tipo:string,partes:array}>
     */
    public static function blocos(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        // <br>, </p> e <p> abrem linha nova. O editor não usa <p>, mas colar do
        // Word ou de outra página traz — e sem isto o texto colado vira um
        // parágrafo único gigante.
        $normalizado = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $html) ?? $html;
        $normalizado = preg_replace('#<\s*/\s*p\s*>#i', "\n", $normalizado) ?? $normalizado;
        $normalizado = preg_replace('#<\s*p[^>]*>#i', "\n", $normalizado) ?? $normalizado;

        $blocos = [];

        foreach (explode("\n", $normalizado) as $linha) {
            $partes = self::partes($linha);

            // Linha que só tinha tags ou espaço não vira bloco vazio na tela.
            $vazia = true;
            foreach ($partes as $parte) {
                if (trim($parte['texto']) !== '') {
                    $vazia = false;
                    break;
                }
            }
            if ($vazia) {
                continue;
            }

            $tipo = 'paragrafo';

            // Marcador no começo da linha: o "•" some do texto e a linha vira
            // item de lista. O app desenha o marcador, o que dá alinhamento e
            // recuo pendurado — coisa que "• " embutido na string não dá.
            $primeiro = ltrim($partes[0]['texto']);
            foreach (self::MARCADORES as $marcador) {
                if ($primeiro !== '' && str_starts_with($primeiro, $marcador . ' ')) {
                    $tipo = 'item';
                    $partes[0]['texto'] = ltrim(substr($primeiro, strlen($marcador) + 1));
                    break;
                }
                // "•PIX" — sem espaço depois do marcador, como está no cadastro.
                if ($primeiro !== '' && $marcador === '•' && str_starts_with($primeiro, $marcador)) {
                    $tipo = 'item';
                    $partes[0]['texto'] = ltrim(substr($primeiro, strlen($marcador)));
                    break;
                }
            }

            // Trecho que ficou vazio depois de tirar o marcador atrapalha o
            // espaçamento; some.
            $partes = array_values(array_filter(
                $partes,
                static fn(array $parte) => $parte['texto'] !== ''
            ));

            if ($partes !== []) {
                $blocos[] = ['tipo' => $tipo, 'partes' => $partes];
            }
        }

        return $blocos;
    }

    /**
     * Quebra uma linha nos trechos formatados que a compõem.
     *
     * Feito com um scanner de tokens em vez de DOM: a entrada é HTML de um
     * campo de texto, quase sempre malformado (`<br>` sem fechar, tag aberta e
     * nunca fechada), e carregar DOMDocument para isso custaria mais do que
     * resolve. Tag desconhecida é ignorada; o texto dentro dela permanece.
     *
     * @return array<int,array{texto:string,negrito:bool,italico:bool,sublinhado:bool,destino:?array}>
     */
    private static function partes(string $html): array
    {
        $partes = [];
        $pilha  = ['negrito' => 0, 'italico' => 0, 'sublinhado' => 0];
        $link   = null;

        $tokens = preg_split(
            '#(<[^>]*>)#',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        ) ?: [];

        foreach ($tokens as $token) {
            if ($token === '' || $token[0] !== '<') {
                $texto = html_entity_decode($token, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Espaço em excesso do HTML (indentação, quebras do editor) não
                // significa nada na tela.
                $texto = preg_replace('/[ \t]+/', ' ', $texto) ?? $texto;
                if ($texto === '') {
                    continue;
                }

                $partes[] = [
                    'texto'      => $texto,
                    'negrito'    => $pilha['negrito']    > 0,
                    'italico'    => $pilha['italico']    > 0,
                    'sublinhado' => $pilha['sublinhado'] > 0,
                    'destino'    => $link,
                ];
                continue;
            }

            if (!preg_match('#^<\s*(/?)\s*([a-z0-9]+)#i', $token, $m)) {
                continue;
            }

            $fechando = $m[1] === '/';
            $tag      = strtolower($m[2]);

            if (isset(self::TAGS_ESTILO[$tag])) {
                $estilo = self::TAGS_ESTILO[$tag];
                // Contador em vez de booleano: <strong>a<strong>b</strong>c</strong>
                // não pode desligar o negrito no meio.
                $pilha[$estilo] = $fechando
                    ? max(0, $pilha[$estilo] - 1)
                    : $pilha[$estilo] + 1;
                continue;
            }

            if ($tag === 'a') {
                if ($fechando) {
                    $link = null;
                } elseif (preg_match('#href\s*=\s*["\']([^"\']+)["\']#i', $token, $h)) {
                    $link = self::destino(html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
        }

        // Sobrou só espaço nas bordas: tira, sem mexer nos espaços internos que
        // separam um trecho em negrito do texto ao lado.
        if ($partes !== []) {
            $partes[0]['texto'] = ltrim($partes[0]['texto']);
            $ultimo = count($partes) - 1;
            $partes[$ultimo]['texto'] = rtrim($partes[$ultimo]['texto']);
        }

        $partes = array_values(array_filter(
            $partes,
            static fn(array $p) => $p['texto'] !== ''
        ));

        return self::juntarVizinhos($partes);
    }

    /**
     * Funde trechos consecutivos de formatação idêntica.
     *
     * `<strong>a<strong>b</strong>c</strong>` produz três trechos em negrito
     * seguidos, indistinguíveis na tela. Cada um vira um <Text> aninhado no
     * app; juntá-los aqui encolhe o payload e a árvore de render, e é de graça.
     *
     * @param  array<int,array> $partes
     * @return array<int,array>
     */
    private static function juntarVizinhos(array $partes): array
    {
        $saida = [];

        foreach ($partes as $parte) {
            $anterior = $saida !== [] ? $saida[count($saida) - 1] : null;

            $igual = $anterior !== null
                && $anterior['negrito']    === $parte['negrito']
                && $anterior['italico']    === $parte['italico']
                && $anterior['sublinhado'] === $parte['sublinhado']
                && $anterior['destino']    === $parte['destino'];

            if ($igual) {
                $saida[count($saida) - 1]['texto'] .= $parte['texto'];
                continue;
            }

            $saida[] = $parte;
        }

        return $saida;
    }

    /**
     * Link de dentro de uma resposta. `mailto:` e `tel:` viram ação do sistema;
     * o resto passa por DestinoPresenter, que já sabe distinguir tela do app de
     * página externa.
     */
    private static function destino(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        foreach (['mailto:' => 'email', 'tel:' => 'telefone', 'whatsapp:' => 'externo'] as $esquema => $tipo) {
            if (stripos($url, $esquema) === 0) {
                return ['tipo' => $tipo, 'params' => [], 'url' => $url];
            }
        }

        return DestinoPresenter::de($url);
    }

    /** O mesmo HTML sem formatação nenhuma — prévia e acessibilidade. */
    public static function texto(string $html): string
    {
        $t = preg_replace('#<\s*br\s*/?\s*>#i', ' ', $html) ?? $html;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /* ── Contato ────────────────────────────────────────────────────────── */

    /**
     * Os canais de atendimento, para quando o FAQ não resolve.
     *
     * Uma busca sem resultado é o momento em que a pessoa mais precisa falar
     * com alguém; terminar ali num "nada encontrado" é onde a central de ajuda
     * deixa de ajudar. Só entram canais que existem em `configuracoes` — nada
     * inventado, e o que não está cadastrado simplesmente não aparece.
     */
    public static function contato(): array
    {
        $email    = trim((string)ConfigHelper::get('site_email', ''));
        $telefone = trim((string)ConfigHelper::get('site_telefone', ''));
        $whatsapp = preg_replace('/\D+/', '', (string)ConfigHelper::get('loja_whatsapp_avisos', '')) ?? '';

        $canais = [];

        if ($whatsapp !== '') {
            // O número cadastrado é nacional; o link exige DDI.
            $numero = str_starts_with($whatsapp, '55') ? $whatsapp : '55' . $whatsapp;
            $canais[] = [
                'tipo'   => 'whatsapp',
                'rotulo' => 'WhatsApp',
                'valor'  => self::formatarTelefone($whatsapp),
                'url'    => 'https://wa.me/' . $numero,
            ];
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $canais[] = [
                'tipo'   => 'email',
                'rotulo' => 'E-mail',
                'valor'  => $email,
                'url'    => 'mailto:' . $email,
            ];
        }

        if ($telefone !== '') {
            $canais[] = [
                'tipo'   => 'telefone',
                'rotulo' => 'Telefone',
                'valor'  => $telefone,
                'url'    => 'tel:+55' . (preg_replace('/\D+/', '', $telefone) ?? ''),
            ];
        }

        return $canais;
    }

    private static function formatarTelefone(string $digitos): string
    {
        // Tira o DDI quando ele veio junto: o rótulo mostra o número como
        // alguém o disca aqui, não como o link precisa dele.
        $d = strlen($digitos) > 11 ? substr($digitos, -11) : $digitos;

        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }

        return $digitos;
    }
}
