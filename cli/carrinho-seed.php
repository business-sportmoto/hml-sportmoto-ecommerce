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
 * NASCE COMO RASCUNHO, DE PROPÓSITO.
 *
 * O bloco `msg_template` precisa do NOME de um template HSM aprovado na Meta,
 * e esse nome só quem tem é você. Publicar com o campo em branco faria o fluxo
 * falhar em silêncio para todo mundo fora da janela de 24h — que é quase todo
 * mundo, já que o contato de um carrinho abandonado nunca escreveu para a loja.
 *
 * Enquanto está em rascunho, o `fluxoParaEvento()` não o encontra e nenhum
 * evento dispara. Publicar é o interruptor.
 *
 * PASSOS DEPOIS DE RODAR:
 *   1. abra /admin/chat/fluxos e edite "Carrinho abandonado (modelo)"
 *   2. no bloco "Template aprovado", ponha o nome do HSM
 *   3. revise os textos e o percentual do cupom
 *   4. publique
 * ────────────────────────────────────────────────────────────────────────────
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

// ── O grafo ─────────────────────────────────────────────────────────────────
$fluxoId = $svc->criar(
    $NOME,
    'Modelo gerado pelo instalador. Entra pelo evento carrinho_abandonado; '
  . 'lembra do carrinho, espera e oferece cupom. Ajuste os textos antes de publicar.'
);

$grafo = [
    'nos' => [
        // ── Entrada ─────────────────────────────────────────────────────────
        // O `evento` casa com o que o ChatEventoLojaService emite. Trocar aqui
        // sem trocar lá é a forma mais fácil de o fluxo nunca rodar.
        ['chave' => 'entrada', 'tipo' => 'gatilho_evento_loja', 'pos' => [40, 300], 'config' => [
            'evento' => 'carrinho_abandonado',
        ]],

        // ── Por onde dá para falar ──────────────────────────────────────────
        // Fora da janela de 24h a Meta só aceita template aprovado. Um contato
        // de carrinho abandonado quase nunca escreveu para a loja, então o
        // caminho de baixo é o normal — o de cima é a exceção feliz.
        ['chave' => 'janela', 'tipo' => 'cond_na_janela', 'pos' => [300, 300], 'config' => []],

        ['chave' => 'lembrete_livre', 'tipo' => 'msg_texto', 'pos' => [580, 160], 'config' => [
            'texto' => "Oi, {{primeiro_nome}}! 👋\n\n"
                     . "Vi que você deixou *{{carrinho_produto}}* no carrinho "
                     . "({{carrinho_valor}}).\n\n"
                     . "Ainda dá tempo — é só voltar por aqui:\n{{carrinho_link}}",
            'preview_url' => true,
        ]],

        // O nome do template fica em branco DE PROPÓSITO: só você tem o nome
        // aprovado na Meta. Preencher é o passo 2 do cabeçalho deste arquivo.
        ['chave' => 'lembrete_hsm', 'tipo' => 'msg_template', 'pos' => [580, 440], 'config' => [
            'nome'   => '',
            'idioma' => 'pt_BR',
            'componentes' => [],
        ]],

        // ── A espera antes do desconto ──────────────────────────────────────
        // Cupom na primeira mensagem desconta quem compraria pelo preço cheio.
        // Seis horas dão tempo de a pessoa voltar sozinha — e quem voltou já
        // saiu da faixa 'abandonado' antes de o cupom sair.
        //
        // Esta espera é curta por um motivo: sessão dormindo é encerrada por
        // qualquer outro fluxo que o contato acione no intervalo. A espera
        // longa (as 20h até o lembrete) mora na fila de eventos, não aqui.
        ['chave' => 'respira', 'tipo' => 'esperar', 'pos' => [860, 300], 'config' => [
            'horas' => 6,
        ]],

        // ── O cupom ─────────────────────────────────────────────────────────
        // Nominal, código único, um uso só, amarrado ao cliente. A porta
        // `sem_cliente` cobre o contato que existe mas não está vinculado a
        // um cadastro — aí cai no cupom divulgável, que não precisa de conta.
        ['chave' => 'cupom_proprio', 'tipo' => 'acao_cupom', 'pos' => [1140, 220], 'config' => [
            'pct'           => 10,
            'dias_validade' => 7,
            'prefixo'       => 'VOLTA',
            'nome'          => 'Cupom de retorno — carrinho',
            'valor_minimo'  => 0,
        ]],

        ['chave' => 'oferta', 'tipo' => 'msg_texto', 'pos' => [1420, 220], 'config' => [
            'texto' => "Separei um cupom pra você fechar: *{{cupom_codigo}}* "
                     . "({{cupom_valor}} OFF, vale até {{cupom_validade}}).\n\n"
                     . "É só aplicar no carrinho:\n{{carrinho_link}}",
            'preview_url' => true,
        ]],

        // Sem cadastro vinculado: oferece um cupom já existente e divulgável
        // que sirva no produto do carrinho. O produto vem por VARIÁVEL — cada
        // carrinho tem o seu.
        ['chave' => 'cupom_divulgavel', 'tipo' => 'acao_cupom_produto', 'pos' => [1140, 460], 'config' => [
            'produto_id'   => '{{carrinho_produto_id}}',
            'texto'        => 'Tenho um cupom que serve pra esse item 👀',
            'rotulo_botao' => 'Quero o cupom',
            'timeout'      => ['horas' => 24],
        ]],

        // ── Saídas ──────────────────────────────────────────────────────────
        ['chave' => 'fim', 'tipo' => 'encerrar', 'pos' => [1700, 300], 'config' => []],
    ],

    'conexoes' => [
        ['de' => 'entrada', 'porta' => 'saida', 'para' => 'janela'],

        ['de' => 'janela', 'porta' => 'true',  'para' => 'lembrete_livre'],
        ['de' => 'janela', 'porta' => 'false', 'para' => 'lembrete_hsm'],

        ['de' => 'lembrete_livre', 'porta' => 'saida', 'para' => 'respira'],
        ['de' => 'lembrete_hsm',   'porta' => 'saida', 'para' => 'respira'],

        ['de' => 'respira', 'porta' => 'saida', 'para' => 'cupom_proprio'],

        ['de' => 'cupom_proprio', 'porta' => 'saida',       'para' => 'oferta'],
        ['de' => 'cupom_proprio', 'porta' => 'sem_cliente', 'para' => 'cupom_divulgavel'],

        ['de' => 'oferta', 'porta' => 'saida', 'para' => 'fim'],

        ['de' => 'cupom_divulgavel', 'porta' => 'pegou',      'para' => 'fim'],
        ['de' => 'cupom_divulgavel', 'porta' => 'recusou',    'para' => 'fim'],
        ['de' => 'cupom_divulgavel', 'porta' => 'sem_cupom',  'para' => 'fim'],
    ],
];

// `reentrada: uma_vez` — a mesma pessoa abandonando carrinho toda semana não
// deve receber a mesma sequência toda semana. Quem quiser o contrário troca
// no painel; o padrão protege o contato.
$r = $svc->salvarRascunho($fluxoId, $grafo, ['config' => ['reentrada' => 'uma_vez']]);

if (!$r['ok']) {
    fwrite(STDERR, "Falha ao salvar o grafo:\n  - " . implode("\n  - ", $r['erros']) . "\n");
    exit(1);
}
if (!empty($r['erros'])) {
    echo "Avisos do validador:\n  - " . implode("\n  - ", $r['erros']) . "\n\n";
}

echo "Fluxo \"{$NOME}\" criado como RASCUNHO (id {$fluxoId}).\n\n";
echo "Antes de publicar:\n";
echo "  1. /admin/chat/fluxos/{$fluxoId} — abra o editor\n";
echo "  2. bloco \"Template aprovado\": ponha o nome do HSM aprovado na Meta\n";
echo "  3. revise os textos e o percentual do cupom (hoje 10%, 7 dias)\n";
echo "  4. publique — enquanto for rascunho, nenhum evento dispara\n\n";
echo "Quem chega aqui: carrinho abaixo de R\$ "
   . number_format((float)((new CarrinhoAbandonado())->configListar()['evento_loja_valor_corte'] ?? 500), 2, ',', '.')
   . ", com telefone, "
   . (int)((new CarrinhoAbandonado())->configListar()['evento_loja_atraso_h'] ?? 20)
   . "h depois do abandono.\n";
exit(0);
