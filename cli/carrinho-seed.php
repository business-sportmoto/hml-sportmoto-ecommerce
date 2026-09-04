<?php
/**
 * cli/carrinho-seed.php
 *
 * Cria o fluxo-modelo de carrinho abandonado — o ponto de partida que o
 * operador clona e ajusta, em vez de montar do zero no canvas.
 *
 * Uso:
 *   php cli/carrinho-seed.php
 *   php cli/carrinho-seed.php --forcar    recria do zero
 *
 * ────────────────────────────────────────────────────────────────────────────
 * A CASCATA
 *
 *   Instagram  →  e-mail  →  e-mail  →  WhatsApp (com cupom)
 *
 * Nessa ordem por um motivo: o Instagram é o canal mais barato e o menos
 * invasivo; o e-mail não tem janela nem custo por mensagem; o WhatsApp é o
 * mais caro e o mais fácil de irritar, então vem por último — e é o único que
 * leva desconto, porque é a última tentativa.
 *
 * Antes de CADA envio o fluxo pergunta se a pessoa já comprou o produto.
 * Não é zelo: entre uma etapa e outra passam-se dias, e insistir com quem já
 * comprou é o tipo de mensagem que faz bloquear a loja.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NASCE COMO RASCUNHO, DE PROPÓSITO.
 *
 * O bloco `msg_template` precisa do NOME de um template HSM aprovado na Meta,
 * e esse nome só quem tem é você. O validador do `publicar()` recusa enquanto
 * estiver em branco — então o fluxo não sobe pela metade.
 *
 * Enquanto está em rascunho, o `fluxoParaEvento()` não o encontra e nenhum
 * evento dispara. Publicar é o interruptor.
 *
 * PASSOS DEPOIS DE RODAR:
 *   1. abra /admin/chat/fluxos e edite "Carrinho abandonado (modelo)"
 *   2. no bloco "Template aprovado", ponha o nome do HSM
 *   3. revise os textos, as esperas e o percentual do cupom
 *   4. publique
 *
 * ────────────────────────────────────────────────────────────────────────────
 * O QUE SABER ANTES DE MEXER NAS ESPERAS
 *
 * A cascata inteira leva ~3 dias, e uma sessão dormindo é encerrada por
 * QUALQUER outro fluxo que a pessoa acione no intervalo (o
 * `encerrarSessoesAbertas()` do motor). Na prática: se ela comentar num reel
 * e cair noutra automação, a cascata do carrinho para ali.
 *
 * Isso é aceitável — e até desejável: quem está conversando com a loja por
 * outro caminho não precisa da régua de recuperação em cima. Mas é
 * comportamento, não acidente: esperas mais longas aumentam a chance de a
 * cascata não chegar ao fim.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

$argv   = $argv ?? [];
$forcar = in_array('--forcar', $argv, true);

$ROOT = dirname(__DIR__);
chdir($ROOT);

require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($ROOT): void {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $class)) return;
    foreach (['/core/', '/app/models/', '/app/helpers/', '/app/services/',
              '/app/services/ia/', '/app/services/ia/providers/'] as $p) {
        $f = $ROOT . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});
require_once $ROOT . '/app/services/ChatNoRegistry.php';

$db  = Database::getInstance()->getConnection();
$svc = new ChatFluxoAdminService($db);

$NOME = 'Carrinho abandonado (modelo)';

// ── Já existe? ──────────────────────────────────────────────────────────────
$st = $db->prepare("SELECT id, status FROM chat_fluxos WHERE nome = :n LIMIT 1");
$st->execute([':n' => $NOME]);
$atual = $st->fetch(PDO::FETCH_ASSOC);

if ($atual && !$forcar) {
    echo "O fluxo \"{$NOME}\" já existe (id {$atual['id']}, {$atual['status']}).\n";
    echo "Use --forcar para recriar — ATENÇÃO: descarta as edições feitas nele.\n";
    exit(0);
}
if ($atual && $forcar) {
    $db->prepare("DELETE FROM chat_fluxos WHERE id = :id")->execute([':id' => (int)$atual['id']]);
    echo "Fluxo anterior removido (id {$atual['id']}).\n";
}

// ── Constantes do desenho ───────────────────────────────────────────────────

// Janela das checagens de compra. Cobre a cascata inteira (~3 dias) mais as
// 20h que o evento espera na fila, com folga. Bounded de propósito: quem
// comprou a peça há seis meses e abandonou de novo agora quer outra — e
// merece a mensagem.
$OLHAR_HORAS = 168;   // 7 dias

$ESPERA = ['horas' => 24];

$grafo = [
    'nos' => [
        // ── Entrada ─────────────────────────────────────────────────────────
        // O `evento` casa com o que o ChatEventoLojaService emite. Trocar aqui
        // sem trocar lá é a forma mais fácil de o fluxo nunca rodar.
        ['chave' => 'entrada', 'tipo' => 'gatilho_evento_loja', 'pos' => [40, 420], 'config' => [
            'evento' => 'carrinho_abandonado',
        ]],

        // ═══════════════ 1ª TENTATIVA — INSTAGRAM ═══════════════
        ['chave' => 'checa_1', 'tipo' => 'cond_produto_comprado', 'pos' => [280, 420], 'config' => [
            'produto_id'  => '{{carrinho_produto_id}}',
            'desde_horas' => $OLHAR_HORAS,
        ]],

        // Olha TODOS os canais da pessoa, não só o desta conversa: o contato
        // do Instagram é outra linha em chat_contatos, costurada pelo cliente_id.
        ['chave' => 'tem_insta', 'tipo' => 'cond_canal_disponivel', 'pos' => [520, 340], 'config' => [
            'canal'         => 'instagram',
            'exigir_janela' => true,
        ]],

        ['chave' => 'insta', 'tipo' => 'msg_canal', 'pos' => [760, 260], 'config' => [
            'canal' => 'instagram',
            'texto' => "Oi, {{primeiro_nome}}! 👋\n\n"
                     . "Vi que você deixou *{{carrinho_produto}}* no carrinho "
                     . "({{carrinho_valor}}).\n\n"
                     . "Ainda dá tempo de finalizar:\n{{carrinho_link}}",
        ]],

        ['chave' => 'espera_1', 'tipo' => 'esperar', 'pos' => [1000, 420], 'config' => $ESPERA],

        // ═══════════════ 2ª TENTATIVA — E-MAIL ═══════════════
        ['chave' => 'checa_2', 'tipo' => 'cond_produto_comprado', 'pos' => [1240, 420], 'config' => [
            'produto_id'  => '{{carrinho_produto_id}}',
            'desde_horas' => $OLHAR_HORAS,
        ]],

        // E-mail não passa por chat_contatos nem por janela: o endereço vem
        // de `usuarios`, alcançado por `clientes.usuario_id`.
        ['chave' => 'email_1', 'tipo' => 'msg_canal', 'pos' => [1480, 340], 'config' => [
            'canal'       => 'email',
            'assunto'     => 'Você esqueceu {{carrinho_produto}} no carrinho',
            'texto'       => "Oi, {{primeiro_nome}}!\n\n"
                           . "Seu carrinho ainda está aqui, com {{carrinho_produto}} "
                           . "e mais itens — {{carrinho_valor}} no total.\n\n"
                           . "Guardamos tudo para você. É só continuar de onde parou.",
            'botao_texto' => 'Voltar ao carrinho',
            'botao_url'   => '{{carrinho_link}}',
        ]],

        ['chave' => 'espera_2', 'tipo' => 'esperar', 'pos' => [1720, 420], 'config' => $ESPERA],

        // ═══════════════ 3ª TENTATIVA — E-MAIL (2º toque) ═══════════════
        ['chave' => 'checa_3', 'tipo' => 'cond_produto_comprado', 'pos' => [1960, 420], 'config' => [
            'produto_id'  => '{{carrinho_produto_id}}',
            'desde_horas' => $OLHAR_HORAS,
        ]],

        ['chave' => 'email_2', 'tipo' => 'msg_canal', 'pos' => [2200, 340], 'config' => [
            'canal'       => 'email',
            'assunto'     => 'Última chamada: {{carrinho_produto}}',
            'texto'       => "{{primeiro_nome}}, seu carrinho está prestes a expirar.\n\n"
                           . "{{carrinho_produto}} continua reservado, mas não por muito tempo — "
                           . "estoque de peça é o que é.\n\n"
                           . "Se mudou de ideia, tudo bem. Se não, o link está abaixo.",
            'botao_texto' => 'Finalizar compra',
            'botao_url'   => '{{carrinho_link}}',
        ]],

        ['chave' => 'espera_3', 'tipo' => 'esperar', 'pos' => [2440, 420], 'config' => $ESPERA],

        // ═══════════════ 4ª TENTATIVA — WHATSAPP + CUPOM ═══════════════
        ['chave' => 'checa_4', 'tipo' => 'cond_produto_comprado', 'pos' => [2680, 420], 'config' => [
            'produto_id'  => '{{carrinho_produto_id}}',
            'desde_horas' => $OLHAR_HORAS,
        ]],

        // O desconto só aqui: é a última tentativa. Dar cupom na primeira
        // mensagem desconta também quem compraria pelo preço cheio.
        ['chave' => 'cupom', 'tipo' => 'acao_cupom', 'pos' => [2920, 340], 'config' => [
            'pct'           => 10,
            'dias_validade' => 7,
            'prefixo'       => 'VOLTA',
            'nome'          => 'Cupom de retorno — carrinho',
            'valor_minimo'  => 0,
        ]],

        ['chave' => 'tem_wa', 'tipo' => 'cond_canal_disponivel', 'pos' => [3160, 340], 'config' => [
            'canal'         => 'whatsapp',
            'exigir_janela' => true,
        ]],

        // Dentro da janela de 24h: texto livre, com o cupom no corpo.
        ['chave' => 'wa_livre', 'tipo' => 'msg_canal', 'pos' => [3400, 240], 'config' => [
            'canal' => 'whatsapp',
            'texto' => "{{primeiro_nome}}, última tentativa — e com desconto. 😄\n\n"
                     . "*{{cupom_codigo}}* dá {{cupom_valor}} OFF em {{carrinho_produto}}, "
                     . "vale até {{cupom_validade}}.\n\n"
                     . "É só aplicar no carrinho:\n{{carrinho_link}}",
        ]],

        // Fora da janela, a Meta só aceita template aprovado. Nome em branco
        // de propósito — é o passo 2 do cabeçalho deste arquivo.
        ['chave' => 'wa_hsm', 'tipo' => 'msg_template', 'pos' => [3400, 440], 'config' => [
            'nome'        => '',
            'idioma'      => 'pt_BR',
            'componentes' => [],
        ]],

        // ── Saída ───────────────────────────────────────────────────────────
        ['chave' => 'fim', 'tipo' => 'encerrar', 'pos' => [3680, 420], 'config' => []],
    ],

    'conexoes' => [
        ['de' => 'entrada', 'porta' => 'saida', 'para' => 'checa_1'],

        // ── Instagram ──
        ['de' => 'checa_1', 'porta' => 'comprou',     'para' => 'fim'],
        ['de' => 'checa_1', 'porta' => 'nao_comprou', 'para' => 'tem_insta'],

        ['de' => 'tem_insta', 'porta' => 'true',  'para' => 'insta'],
        // Sem Instagram alcançável, pula direto para o e-mail — sem esperar
        // um dia por uma mensagem que não saiu.
        ['de' => 'tem_insta', 'porta' => 'false', 'para' => 'checa_2'],

        ['de' => 'insta', 'porta' => 'enviado',   'para' => 'espera_1'],
        ['de' => 'insta', 'porta' => 'sem_canal', 'para' => 'checa_2'],
        ['de' => 'insta', 'porta' => 'falhou',    'para' => 'checa_2'],

        ['de' => 'espera_1', 'porta' => 'saida', 'para' => 'checa_2'],

        // ── E-mail 1 ──
        ['de' => 'checa_2', 'porta' => 'comprou',     'para' => 'fim'],
        ['de' => 'checa_2', 'porta' => 'nao_comprou', 'para' => 'email_1'],

        ['de' => 'email_1', 'porta' => 'enviado',   'para' => 'espera_2'],
        ['de' => 'email_1', 'porta' => 'sem_canal', 'para' => 'checa_4'],
        ['de' => 'email_1', 'porta' => 'falhou',    'para' => 'espera_2'],

        ['de' => 'espera_2', 'porta' => 'saida', 'para' => 'checa_3'],

        // ── E-mail 2 ──
        ['de' => 'checa_3', 'porta' => 'comprou',     'para' => 'fim'],
        ['de' => 'checa_3', 'porta' => 'nao_comprou', 'para' => 'email_2'],

        ['de' => 'email_2', 'porta' => 'enviado',   'para' => 'espera_3'],
        ['de' => 'email_2', 'porta' => 'sem_canal', 'para' => 'espera_3'],
        ['de' => 'email_2', 'porta' => 'falhou',    'para' => 'espera_3'],

        ['de' => 'espera_3', 'porta' => 'saida', 'para' => 'checa_4'],

        // ── WhatsApp + cupom ──
        ['de' => 'checa_4', 'porta' => 'comprou',     'para' => 'fim'],
        ['de' => 'checa_4', 'porta' => 'nao_comprou', 'para' => 'cupom'],

        // Sem cadastro não há a quem amarrar cupom nominal — segue sem ele
        ['de' => 'cupom', 'porta' => 'saida',       'para' => 'tem_wa'],
        ['de' => 'cupom', 'porta' => 'sem_cliente', 'para' => 'tem_wa'],

        ['de' => 'tem_wa', 'porta' => 'true',  'para' => 'wa_livre'],
        ['de' => 'tem_wa', 'porta' => 'false', 'para' => 'wa_hsm'],

        ['de' => 'wa_livre', 'porta' => 'enviado',   'para' => 'fim'],
        ['de' => 'wa_livre', 'porta' => 'sem_canal', 'para' => 'fim'],
        ['de' => 'wa_livre', 'porta' => 'falhou',    'para' => 'wa_hsm'],

        ['de' => 'wa_hsm', 'porta' => 'saida', 'para' => 'fim'],
    ],
];

$fluxoId = $svc->criar(
    $NOME,
    'Modelo gerado pelo instalador. Cascata Instagram → e-mail (2x) → WhatsApp '
  . 'com cupom, checando antes de cada envio se a pessoa já comprou o produto.'
);

// `reentrada: uma_vez` — a mesma pessoa abandonando carrinho toda semana não
// deve receber a mesma cascata toda semana. Quem quiser o contrário troca no
// painel; o padrão protege o contato.
$r = $svc->salvarRascunho($fluxoId, $grafo, ['config' => ['reentrada' => 'uma_vez']]);

if (!$r['ok']) {
    fwrite(STDERR, "Falha ao salvar o grafo:\n  - " . implode("\n  - ", $r['erros']) . "\n");
    exit(1);
}
if (!empty($r['erros'])) {
    echo "Avisos do validador:\n  - " . implode("\n  - ", $r['erros']) . "\n\n";
}

$cfg = (new CarrinhoAbandonado())->configListar();

echo "Fluxo \"{$NOME}\" criado como RASCUNHO (id {$fluxoId}).\n\n";
echo "A cascata:  Instagram → e-mail → e-mail → WhatsApp (com cupom)\n";
echo "            {$ESPERA['horas']}h entre cada etapa, checando compra antes de cada envio.\n\n";
echo "Antes de publicar:\n";
echo "  1. /admin/chat/fluxos/{$fluxoId} — abra o editor\n";
echo "  2. bloco \"Template aprovado\": ponha o nome do HSM aprovado na Meta\n";
echo "  3. revise os textos, as esperas e o cupom (hoje 10%, 7 dias)\n";
echo "  4. publique — enquanto for rascunho, nenhum evento dispara\n\n";
echo 'Quem chega aqui: carrinho abaixo de R$ '
   . number_format((float)($cfg['evento_loja_valor_corte'] ?? 500), 2, ',', '.')
   . ', com telefone, ' . (int)($cfg['evento_loja_atraso_h'] ?? 20)
   . "h depois do abandono.\n";
exit(0);
