<?php
declare(strict_types=1);

/**
 * cli/seed-fluxos-pagamento.php
 *
 * Cria os fluxos de referência dos três métodos de pagamento, como RASCUNHO.
 *
 * Rascunho, e não publicado, de propósito: publicar é decisão de quem opera,
 * depois de abrir o canvas e conferir. Este script só desenha.
 *
 * Reexecutável: arquiva o rascunho anterior do método e cria uma versão nova.
 * Nunca toca no fluxo publicado.
 *
 *   php cli/seed-fluxos-pagamento.php            (todos)
 *   php cli/seed-fluxos-pagamento.php pix        (um método)
 */

require __DIR__ . '/../bootstrap-cli.php';

/**
 * Adquirente dos nós de tentativa.
 * Sobrescreva pela linha de comando: ADQ=safrapay php cli/seed-fluxos-pagamento.php
 */
define('ADQ', getenv('ADQ') ?: 'mercadopago');

/** Acima disto, pedido é grande demais para passar sem antifraude. R$ 500,00. */
const VALOR_ALTO_CENTAVOS = 50000;

// =============================================================================
// PIX  —  linear de propósito
// =============================================================================
//
// Um Pix não é "autorizado": ele é CRIADO. A adquirente devolve o QR e o fluxo
// acaba ali, em `pendente`. Quem paga é o cliente, no banco dele, minutos ou
// horas depois — e quem avisa é o webhook.
//
// Por isso não há antifraude aqui. No momento em que este fluxo roda, nenhum
// dinheiro se moveu e nada será enviado. Consultar a ClearSale agora seria
// pagar consulta por um QR que tem boa chance de nunca ser pago. A análise de
// risco do Pix pertence ao webhook de confirmação.
//
$PIX = [
    'nome' => 'PIX — geração de QR',
    'nos'  => [
        ['entrada',   'entrada',           [],                     60, 250],
        ['pix_1', 'tentar_adquirente', ['adquirente' => ADQ], 310, 250],
        ['ok',        'aprovar',           [],                    640, 160],
        ['nok_tec',   'recusar',           [],                    640, 300],
        ['nok',       'recusar',           [],                    640, 420],
    ],
    'arestas' => [
        ['entrada',   'saida',           'pix_1'],
        // O desfecho NORMAL do Pix é `pendente`: QR na tela, aguardando.
        ['pix_1', 'pendente',        'ok'],
        // `aprovado` só acontece se a confirmação vier na mesma resposta.
        ['pix_1', 'aprovado',        'ok'],
        // Adquirente fora do ar: sem segunda, recusa e o cliente
        // escolhe outra forma. Ver rodapé.
        ['pix_1', 'erro_tecnico',    'nok_tec'],
        ['pix_1', 'indisponivel',    'nok_tec'],
        // Chave inválida, valor fora de faixa, CPF recusado no registro.
        ['pix_1', 'negado_dados',    'nok'],
        ['pix_1', 'negado_generico', 'nok'],
    ],
];

// =============================================================================
// BOLETO  —  mesma forma do Pix, e pela mesma razão
// =============================================================================
//
// O boleto é registrado, não autorizado. E tem uma proteção que o cartão não
// tem: a mercadoria só sai depois da compensação. Fraude de pagamento em
// boleto praticamente não existe — não há emissor para contestar nem
// chargeback para sofrer. Antifraude aqui seria custo sem contrapartida.
//
$BOLETO = [
    'nome' => 'Boleto — registro',
    'nos'  => [
        ['entrada',   'entrada',           [],                     60, 250],
        ['bol_1', 'tentar_adquirente', ['adquirente' => ADQ], 310, 250],
        ['ok',        'aprovar',           [],                    640, 160],
        ['nok_tec',   'recusar',           [],                    640, 300],
        ['nok',       'recusar',           [],                    640, 420],
    ],
    'arestas' => [
        ['entrada',   'saida',           'bol_1'],
        ['bol_1', 'pendente',        'ok'],   // boleto emitido
        ['bol_1', 'aprovado',        'ok'],
        ['bol_1', 'erro_tecnico',    'nok_tec'],
        ['bol_1', 'indisponivel',    'nok_tec'],
        ['bol_1', 'negado_dados',    'nok'],  // CPF/endereço recusado
        ['bol_1', 'negado_generico', 'nok'],
    ],
];

// =============================================================================
// CARTÃO DE CRÉDITO  —  onde a complexidade realmente mora
// =============================================================================
//
// Só o cartão tem emissor decidindo, parcelamento, chargeback e custo de
// adquirência diferente por faixa. Por isso é o único fluxo com condição,
// antifraude e retenção.
//
// ORDEM: autoriza primeiro, analisa depois. O antifraude custa consulta; não
// faz sentido gastá-la num cartão que o emissor vai recusar de graça.
//
$CARTAO = [
    'nome' => 'Cartão — parcelas, antifraude e retenção',
    'nos'  => [
        ['entrada',  'entrada',           [],                                   40, 330],

        // Divisor por faixa de parcela: é aqui que entra a segunda adquirente
        // quando existir (à vista numa, parcelado noutra).
        ['ate6',     'cond_parcelas',     ['min' => 1, 'max' => 6],            250, 330],

        ['card_1_6', 'tentar_adquirente', ['adquirente' => ADQ],               490, 180],
        ['card_712', 'tentar_adquirente', ['adquirente' => ADQ],               490, 480],

        // Pós-captura: o dinheiro já foi capturado, então reprovar aqui exige
        // estorno. É o único modo suportado hoje — a captura em duas etapas
        // ainda não está no adapter da Safra.
        ['af',       'antifraude',        ['modo' => 'pos_captura',
                                           'pular_se_aprovado_local' => '1'],  780, 330],

        // Rede de segurança para antifraude indisponível — ver abaixo.
        ['af_caiu',  'cond_valor',        ['min' => VALOR_ALTO_CENTAVOS,
                                           'max' => 99999999],                1040, 560],

        ['ok',       'aprovar',           [],                                 1320, 150],
        ['ret',      'reter_analise',     [],                                 1320, 330],
        ['nok',      'recusar',           [],                                 1320, 700],
        ['nok_tec',  'recusar',           [],                                  790, 700],
    ],
    'arestas' => [
        ['entrada',  'saida',             'ate6'],
        ['ate6',     'sim',               'card_1_6'],   // 1x a 6x
        ['ate6',     'nao',               'card_712'],   // 7x a 12x

        // ── Autorizou: só agora o antifraude entra ──────────────────
        ['card_1_6', 'aprovado',          'af'],
        ['card_712', 'aprovado',          'af'],

        // ── Recusa do emissor: termina. NUNCA vai para outra adquirente ──
        // Retentar uma recusa do emissor é o que gera multa de bandeira
        // (Visa Excessive Reattempts / Mastercard TPE). O motor bloqueia
        // mesmo que alguém desenhe, mas o desenho já reflete a regra.
        ['card_1_6', 'negado_saldo',      'nok'],
        ['card_1_6', 'negado_antifraude', 'nok'],
        ['card_1_6', 'negado_dados',      'nok'],
        ['card_1_6', 'negado_generico',   'nok'],
        ['card_712', 'negado_saldo',      'nok'],
        ['card_712', 'negado_antifraude', 'nok'],
        ['card_712', 'negado_dados',      'nok'],
        ['card_712', 'negado_generico',   'nok'],

        // ── Falha técnica: aqui SIM caberia outra adquirente ────────
        ['card_1_6', 'erro_tecnico',      'nok_tec'],
        ['card_1_6', 'indisponivel',      'nok_tec'],
        ['card_712', 'erro_tecnico',      'nok_tec'],
        ['card_712', 'indisponivel',      'nok_tec'],

        // ── Veredito do antifraude ──────────────────────────────────
        ['af',       'aprovado',          'ok'],
        ['af',       'analise',           'ret'],   // fila humana
        ['af',       'reprovado',         'nok'],

        // ── ClearSale fora do ar ────────────────────────────────────
        // Aprovar tudo às cegas expõe a loja; recusar tudo derruba a receita.
        // O valor decide: pedido grande espera um humano, pedido pequeno passa.
        ['af',       'erro',              'af_caiu'],
        ['af_caiu',  'sim',               'ret'],   // acima de R$ 500 → retém
        ['af_caiu',  'nao',               'ok'],    // abaixo → segue
    ],
];

// =============================================================================

$FLUXOS = ['pix' => $PIX, 'boleto' => $BOLETO, 'cartao_credito' => $CARTAO];

$alvo = $argv[1] ?? null;
if ($alvo !== null && !isset($FLUXOS[$alvo])) {
    fwrite(STDERR, "Metodo desconhecido: {$alvo}. Use: " . implode(', ', array_keys($FLUXOS)) . "\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();

foreach ($FLUXOS as $metodo => $def) {
    if ($alvo !== null && $alvo !== $metodo) continue;
    semear($db, $metodo, $def);
}

// =============================================================================

function semear(PDO $db, string $metodo, array $def): void
{
    // Valida ANTES de gravar. Semear um grafo inválido só empurra o erro para
    // a hora em que alguém tentar publicar.
    $nosVal = array_map(
        static fn($n) => ['no_ref' => $n[0], 'tipo' => $n[1], 'config' => $n[2]],
        $def['nos']
    );
    $arestasVal = array_map(
        static fn($a) => ['no_origem' => $a[0], 'porta_origem' => $a[1], 'no_destino' => $a[2]],
        $def['arestas']
    );

    $v = PagamentoNoCatalogo::validarGrafo($nosVal, $arestasVal);
    if ($v['erros']) {
        fwrite(STDERR, "\n[{$metodo}] GRAFO INVALIDO - nada foi gravado:\n");
        foreach ($v['erros'] as $e) fwrite(STDERR, "   ERRO  {$e}\n");
        return;
    }

    $db->beginTransaction();
    try {
        // Arquiva o rascunho anterior: o editor trabalha com um por método.
        $st = $db->prepare(
            "UPDATE pgto_fluxos SET status = 'arquivado', atualizado_em = NOW()
              WHERE metodo_codigo = ? AND status = 'rascunho'"
        );
        $st->execute([$metodo]);
        $arquivados = $st->rowCount();

        $st = $db->prepare("SELECT COALESCE(MAX(versao),0)+1 FROM pgto_fluxos WHERE metodo_codigo = ?");
        $st->execute([$metodo]);
        $versao = (int) $st->fetchColumn();

        $db->prepare(
            "INSERT INTO pgto_fluxos (metodo_codigo, nome, versao, status, criado_em, atualizado_em)
             VALUES (?,?,?,'rascunho',NOW(),NOW())"
        )->execute([$metodo, $def['nome'], $versao]);

        $fluxoId = (int) $db->lastInsertId();

        $insNo = $db->prepare(
            "INSERT INTO pgto_fluxo_nos (fluxo_id, no_ref, tipo, config, pos_x, pos_y)
             VALUES (?,?,?,?,?,?)"
        );
        foreach ($def['nos'] as [$ref, $tipo, $cfg, $x, $y]) {
            $insNo->execute([$fluxoId, $ref, $tipo,
                             json_encode($cfg, JSON_UNESCAPED_UNICODE), $x, $y]);
        }

        $insAr = $db->prepare(
            "INSERT INTO pgto_fluxo_conexoes (fluxo_id, no_origem, porta_origem, no_destino)
             VALUES (?,?,?,?)"
        );
        foreach ($def['arestas'] as [$de, $porta, $para]) {
            $insAr->execute([$fluxoId, $de, $porta, $para]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "[{$metodo}] falhou: {$e->getMessage()}\n");
        LogService::exception($e, 'error', 'pagamento', ['acao' => 'seed_fluxo', 'metodo' => $metodo]);
        return;
    }

    printf("\n[%s] rascunho #%d v%d - %s\n", $metodo, $fluxoId, $versao, $def['nome']);
    printf("   %d nos, %d conexoes%s\n",
        count($def['nos']), count($def['arestas']),
        $arquivados ? " | {$arquivados} rascunho(s) anterior(es) arquivado(s)" : '');
    foreach ($v['avisos'] as $a) printf("   aviso  %s\n", $a);
    printf("   abrir: %s/pagamentos/fluxos/%d\n", ADMIN_URL, $fluxoId);
}

/*
 * POR QUE TODOS OS NÓS APONTAM PARA A SAFRA
 * -----------------------------------------
 * Fallback exige DUAS adquirentes. Hoje só a Safra tem adapter, então as
 * saídas de falha técnica terminam em Recusar: é o comportamento honesto — o
 * cliente descobre na hora e escolhe outra forma, em vez de esperar uma
 * segunda tentativa que não existe.
 *
 * Quando a segunda entrar, são poucas mudanças no canvas:
 *   cartão  → o nó `card_712` troca de adquirente (7x a 12x na mais barata)
 *   cartão  → `erro_tecnico` e `indisponivel` deixam de ir para `nok_tec` e
 *             passam a apontar para um novo nó "Tentar adquirente"
 *   pix     → mesma troca em `erro_tecnico` / `indisponivel`
 *   boleto  → idem
 *
 * O nó `ate6` já está no lugar justamente para essa troca não exigir redesenho.
 */
