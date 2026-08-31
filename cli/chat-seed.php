<?php
/**
 * cli/chat-seed.php
 *
 * Instala um atendimento inicial funcional: um fluxo de menu com botões,
 * mais os gatilhos de boas-vindas, palavra-chave e resposta padrão.
 *
 * Serve para o módulo já nascer respondendo, em vez de exigir que alguém
 * monte tudo do zero antes de ver qualquer coisa funcionar. Depois é só
 * abrir em Chat → Fluxos e ajustar os textos.
 *
 * Uso:
 *   php cli/chat-seed.php            → cria se ainda não existir
 *   php cli/chat-seed.php --forcar   → recria mesmo se já existir
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }

$ROOT = dirname(__DIR__);
chdir($ROOT);

require_once $ROOT . '/config/defines.php';
require_once $ROOT . '/config/config.php';
require_once $ROOT . '/config/database.php';
if (is_file($ROOT . '/vendor/autoload.php')) require_once $ROOT . '/vendor/autoload.php';

spl_autoload_register(function (string $c) use ($ROOT): void {
    foreach (['/core/', '/app/helpers/', '/app/services/', '/app/models/'] as $p) {
        $f = $ROOT . $p . $c . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});
require_once $ROOT . '/app/services/ChatNoRegistry.php';

$forcar = in_array('--forcar', $argv ?? [], true);
$db     = Database::getInstance()->getConnection();
$svc    = new ChatFluxoAdminService($db);
$gat    = new ChatGatilhoService($db);
$cts    = new ChatContatoService($db);

$NOME = 'Menu de atendimento';

// ── Já existe? ───────────────────────────────────────────────────────────────
$st = $db->prepare("SELECT id FROM chat_fluxos WHERE nome = :n LIMIT 1");
$st->execute([':n' => $NOME]);
$existente = (int)$st->fetchColumn();

if ($existente && !$forcar) {
    echo "O fluxo \"$NOME\" já existe (id $existente). Use --forcar para recriar.\n";
    exit(0);
}
if ($existente && $forcar) {
    $db->prepare("DELETE FROM chat_fluxos WHERE id = :id")->execute([':id' => $existente]);
    echo "Fluxo anterior removido.\n";
}

// ── Tags usadas pelo fluxo ───────────────────────────────────────────────────
$tagAtend  = $cts->tagIdPorSlug('atendimento', 'Atendimento');
$tagPedido = $cts->tagIdPorSlug('duvida-pedido', 'Dúvida de pedido');

// ── Fluxo ────────────────────────────────────────────────────────────────────
$fluxoId = $svc->criar($NOME, 'Menu inicial com botões — criado pelo instalador');

$grafo = [
    'nos' => [
        ['chave' => 'inicio', 'tipo' => 'gatilho_manual', 'config' => [], 'pos' => [40, 220]],

        ['chave' => 'saudacao', 'tipo' => 'msg_texto', 'pos' => [280, 220], 'config' => [
            'texto' => "{{saudacao}}, {{primeiro_nome}}! 👋\n\n"
                     . "Aqui é a {{site_nome}}. Posso te ajudar com peças, pedidos ou dúvidas.",
            'preview_url' => false,
        ]],

        ['chave' => 'menu', 'tipo' => 'msg_botoes', 'pos' => [520, 220], 'config' => [
            'corpo'     => 'Sobre o que você quer falar?',
            'rodape'    => 'Escolha uma opção',
            'salvar_em' => 'assunto',
            'botoes'    => [
                ['titulo' => 'Meu pedido'],
                ['titulo' => 'Comprar peça'],
                ['titulo' => 'Falar com alguém'],
            ],
            'timeout' => ['horas' => 6],
        ]],

        // Botão 1 — pedido
        ['chave' => 'tag_pedido', 'tipo' => 'acao_tag', 'pos' => [800, 40], 'config' => [
            'acao' => 'adicionar', 'tag_id' => $tagPedido,
        ]],
        ['chave' => 'pede_codigo', 'tipo' => 'esperar_resposta', 'pos' => [1040, 40], 'config' => [
            'pergunta'  => 'Certo! Me manda o número do seu pedido que eu verifico. 🔎',
            'salvar_em' => 'codigo_pedido',
            'validacao' => 'texto',
            'mensagem_invalida' => 'Não consegui ler. Pode mandar só o número do pedido?',
            'max_tentativas' => 2,
            'timeout' => ['horas' => 6],
        ]],
        ['chave' => 'confirma_pedido', 'tipo' => 'msg_texto', 'pos' => [1300, 40], 'config' => [
            'texto' => "Anotei o pedido *{{codigo_pedido}}*.\n\n"
                     . 'Já vou verificar e te retorno em instantes. 🙌',
        ]],

        // Botão 2 — comprar
        ['chave' => 'comprar', 'tipo' => 'msg_botao_url', 'pos' => [800, 240], 'config' => [
            'corpo'       => 'Nossa loja tem o catálogo completo, com busca por moto. 🏍️',
            'texto_botao' => 'Ver catálogo',
            'url'         => (defined('BASE_URL') ? BASE_URL : ''),
            'rodape'      => 'Qualquer dúvida, é só chamar',
        ]],

        // Botão 3 — humano
        ['chave' => 'humano', 'tipo' => 'acao_humano', 'pos' => [800, 420], 'config' => [
            'mensagem'       => 'Claro! Já estou chamando alguém do time. Um instante, por favor. 🙂',
            'atribuir_a'     => 0,
            'pausar_minutos' => 120,
            'status'         => 'pendente',
        ]],
        ['chave' => 'tag_atend', 'tipo' => 'acao_tag', 'pos' => [1040, 420], 'config' => [
            'acao' => 'adicionar', 'tag_id' => $tagAtend,
        ]],

        // Sem resposta
        ['chave' => 'sem_resposta', 'tipo' => 'msg_texto', 'pos' => [800, 600], 'config' => [
            'texto' => 'Vou ficar por aqui, então. Quando precisar, é só mandar *menu*. 👋',
        ]],

        ['chave' => 'fim', 'tipo' => 'encerrar', 'config' => [], 'pos' => [1560, 240]],
    ],
    'conexoes' => [
        ['de' => 'inicio',      'porta' => 'saida', 'para' => 'saudacao'],
        ['de' => 'saudacao',    'porta' => 'saida', 'para' => 'menu'],

        ['de' => 'menu', 'porta' => 'btn_1',   'para' => 'tag_pedido'],
        ['de' => 'menu', 'porta' => 'btn_2',   'para' => 'comprar'],
        ['de' => 'menu', 'porta' => 'btn_3',   'para' => 'humano'],
        ['de' => 'menu', 'porta' => 'timeout', 'para' => 'sem_resposta'],

        ['de' => 'tag_pedido',      'porta' => 'saida',    'para' => 'pede_codigo'],
        ['de' => 'pede_codigo',     'porta' => 'resposta', 'para' => 'confirma_pedido'],
        ['de' => 'pede_codigo',     'porta' => 'invalido', 'para' => 'humano'],
        ['de' => 'pede_codigo',     'porta' => 'timeout',  'para' => 'sem_resposta'],
        ['de' => 'confirma_pedido', 'porta' => 'saida',    'para' => 'fim'],

        ['de' => 'comprar',      'porta' => 'saida', 'para' => 'fim'],
        ['de' => 'humano',       'porta' => 'saida', 'para' => 'tag_atend'],
        ['de' => 'tag_atend',    'porta' => 'saida', 'para' => 'fim'],
        ['de' => 'sem_resposta', 'porta' => 'saida', 'para' => 'fim'],
    ],
];

$r = $svc->salvarRascunho($fluxoId, $grafo, ['config' => ['reentrada' => 'sempre']]);
if (!$r['ok']) {
    fwrite(STDERR, "Falha ao salvar o grafo:\n  - " . implode("\n  - ", $r['erros']) . "\n");
    exit(1);
}
if ($r['erros']) {
    echo "Avisos no rascunho:\n  - " . implode("\n  - ", $r['erros']) . "\n";
}

$p = $svc->publicar($fluxoId);
if (!$p['ok']) {
    fwrite(STDERR, "Falha ao publicar:\n  - " . implode("\n  - ", $p['erros']) . "\n");
    exit(1);
}
echo "Fluxo \"$NOME\" criado e publicado (id $fluxoId, v{$p['versao']}).\n";

// ── Gatilhos ─────────────────────────────────────────────────────────────────
$gatilhos = [
    [
        'nome' => 'Boas-vindas', 'tipo' => 'boas_vindas', 'acao' => 'fluxo',
        'fluxo_id' => $fluxoId, 'prioridade' => 10, 'ativo' => 1, 'so_fora_fluxo' => 1,
    ],
    [
        'nome' => 'Menu por palavra-chave', 'tipo' => 'palavra_chave',
        'padrao' => 'menu,oi,ola,bom dia,boa tarde,boa noite,ajuda',
        'modo_match' => 'exato', 'acao' => 'fluxo', 'fluxo_id' => $fluxoId,
        'prioridade' => 20, 'ativo' => 1, 'so_fora_fluxo' => 1,
    ],
    [
        'nome' => 'Falar com atendente', 'tipo' => 'palavra_chave',
        'padrao' => 'atendente,humano,pessoa,suporte',
        'modo_match' => 'contem', 'acao' => 'humano',
        'mensagem' => 'Claro! Já estou chamando alguém do time. 🙂',
        // Este pode cortar um fluxo no meio de propósito: é o pedido de socorro
        'prioridade' => 5, 'ativo' => 1, 'so_fora_fluxo' => 0,
    ],
    [
        'nome' => 'Resposta padrão', 'tipo' => 'padrao', 'acao' => 'mensagem',
        'mensagem' => "Não tenho certeza se entendi. 🤔\n\n"
                    . "Manda *menu* para ver as opções, ou *atendente* para falar com uma pessoa.",
        'prioridade' => 900, 'ativo' => 1, 'so_fora_fluxo' => 1,
    ],
];

foreach ($gatilhos as $g) {
    // Não duplica o que já existe com o mesmo nome
    $st = $db->prepare("SELECT id FROM chat_gatilhos WHERE nome = :n LIMIT 1");
    $st->execute([':n' => $g['nome']]);
    $id = (int)$st->fetchColumn();

    if ($id && !$forcar) { echo "  gatilho \"{$g['nome']}\" já existe — mantido.\n"; continue; }

    $res = $gat->salvar($g, $id ?: null);
    echo $res['ok']
        ? "  gatilho \"{$g['nome']}\" " . ($id ? 'atualizado' : 'criado') . ".\n"
        : "  FALHA no gatilho \"{$g['nome']}\": {$res['erro']}\n";
}

echo "\nPronto. Abra Chat → Fluxos para ajustar os textos ao seu tom de voz.\n";
echo "Lembre de cadastrar o webhook e configurar META_APP_SECRET antes de testar de verdade.\n";
exit(0);
