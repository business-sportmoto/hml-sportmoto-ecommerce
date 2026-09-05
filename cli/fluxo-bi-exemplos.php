#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * cli/fluxo-bi-exemplos.php — dois fluxos de exemplo da Fase C (BI & IA).
 *
 * Cria como RASCUNHO (não gasta nada até alguém publicar no canvas):
 *
 *   1. "BI: Alerta crítico → agente → sino"
 *      bi_alerta_critico → agente_ia(auto) → prioridade ≥ Alta → sino dos admins
 *      Substitui a regra fixa do ia-agentes-worker --modo=evento: com este
 *      fluxo publicado, o worker vê que há quem trate o alerta e se retira.
 *
 *   2. "BI: Resumo das 6h → Financeiro → sino"
 *      agenda_06h → agente_ia(agente_financeiro) → sino dos admins
 *      O "cron das 6h" como fluxo editável.
 *
 * Idempotente: fluxo com o mesmo nome já existente é pulado.
 *
 *   php cli/fluxo-bi-exemplos.php             # cria os rascunhos
 *   php cli/fluxo-bi-exemplos.php --publicar  # cria E publica (começa a rodar
 *                                             # no próximo ciclo do fluxo-worker)
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "Só roda em CLI.\n"); exit(1); }
require_once dirname(__DIR__) . '/bootstrap-cli.php';
require_once ROOT_PATH . '/app/services/FluxoNoRegistry.php';

$publicar = in_array('--publicar', $argv ?? [], true);

$exemplos = [
    [
        'nome'      => 'BI: Alerta crítico → agente → sino',
        'descricao' => 'Quando o BI detecta um alerta crítico (ruptura ≤ 7d, queda ≥ 20%), o agente sugerido analisa; se a prioridade for Alta, todos os admins recebem no sino.',
        'grafo'     => [
            'nos' => [
                ['chave' => 'trig_alerta', 'tipo' => 'trigger_evento', 'pos' => [40, 140],
                 'config' => ['evento' => BiEventoService::ALERTAS['critico'], 'apenas_logados' => false]],
                ['chave' => 'agente',      'tipo' => 'agente_ia',      'pos' => [340, 140],
                 'config' => ['agente' => 'auto', 'periodo' => '30d',
                              'pergunta' => 'ALERTA CRÍTICO detectado pelo sistema: "{{titulo}}" — {{detalhe}}. '
                                          . 'Analise a causa provável, o impacto e recomende a ação imediata.']],
                ['chave' => 'prio_alta',   'tipo' => 'cond_prioridade', 'pos' => [660, 140],
                 'config' => ['minimo' => 'Alta']],
                ['chave' => 'sino',        'tipo' => 'acao_sino_admins', 'pos' => [960, 80],
                 'config' => ['categoria' => 'sistema',
                              'titulo'    => '{{titulo}} · prioridade {{ia_prioridade}}',
                              'mensagem'  => '{{ia_resumo}}',
                              'url'       => '{{ia_url}}']],
            ],
            'conexoes' => [
                ['de' => 'trig_alerta', 'porta' => 'saida', 'para' => 'agente'],
                ['de' => 'agente',      'porta' => 'ok',    'para' => 'prio_alta'],
                ['de' => 'prio_alta',   'porta' => 'true',  'para' => 'sino'],
            ],
        ],
    ],
    [
        'nome'      => 'BI: Resumo das 6h → Financeiro → sino',
        'descricao' => 'Todo dia às 6h o Analista Financeiro produz o resumo executivo e avisa os admins pelo sino.',
        'grafo'     => [
            'nos' => [
                ['chave' => 'trig_06h', 'tipo' => 'trigger_evento', 'pos' => [40, 140],
                 'config' => ['evento' => BiEventoService::tipoAgenda(6), 'apenas_logados' => false]],
                ['chave' => 'financeiro', 'tipo' => 'agente_ia', 'pos' => [340, 140],
                 'config' => ['agente' => 'agente_financeiro', 'periodo' => '30d',
                              'pergunta' => 'Resumo executivo de ontem e dos últimos 30 dias: faturamento e variação, '
                                          . 'margem (só se houver cobertura de custo), alertas ativos e o que merece atenção hoje.']],
                ['chave' => 'sino', 'tipo' => 'acao_sino_admins', 'pos' => [660, 140],
                 'config' => ['categoria' => 'financeiro',
                              'titulo'    => 'Resumo Financeiro de hoje · prioridade {{ia_prioridade}}',
                              'mensagem'  => '{{ia_resumo}}',
                              'url'       => '{{ia_url}}']],
            ],
            'conexoes' => [
                ['de' => 'trig_06h',   'porta' => 'saida', 'para' => 'financeiro'],
                ['de' => 'financeiro', 'porta' => 'ok',    'para' => 'sino'],
            ],
        ],
    ],
];

$db  = Database::getInstance()->getConnection();
$svc = new FluxoAdminService();
$saida = 0;

foreach ($exemplos as $ex) {
    $st = $db->prepare("SELECT id, status FROM fluxo_v2 WHERE nome = :n LIMIT 1");
    $st->execute([':n' => $ex['nome']]);
    if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
        echo "  [pulado] \"{$ex['nome']}\" já existe (#{$f['id']}, {$f['status']})\n";
        continue;
    }

    $id = $svc->criar($ex['nome'], $ex['descricao']);
    // 'sempre': o token do evento já é único por dia; só bloqueia se a rodada
    // anterior do mesmo dia ainda estiver em andamento.
    $r  = $svc->salvarRascunho($id, $ex['grafo'], ['config' => ['reentrada' => 'sempre', 'sair_se_eventos' => []]]);
    if (!$r['ok']) {
        echo "  [ERRO]   \"{$ex['nome']}\": " . implode('; ', $r['erros']) . "\n";
        $saida = 1;
        continue;
    }
    if ($r['erros']) echo "  [aviso]  \"{$ex['nome']}\": " . implode('; ', $r['erros']) . "\n";

    if ($publicar) {
        $p = $svc->publicar($id);
        echo $p['ok']
            ? "  [ok]     \"{$ex['nome']}\" criado e PUBLICADO (#{$id}, v{$p['versao']})\n"
            : "  [ERRO]   \"{$ex['nome']}\" criado (#{$id}) mas não publicou: " . implode('; ', $p['erros']) . "\n";
        if (!$p['ok']) $saida = 1;
    } else {
        echo "  [ok]     \"{$ex['nome']}\" criado como rascunho (#{$id}) — abra /admin/fluxos/{$id} e publique\n";
    }
}

if (!$publicar) {
    echo "\nNada roda até publicar. Publicado, o fluxo-worker passa a publicar os eventos do BI\n"
       . "(alertas/metas a cada 15 min, agenda no minuto seguinte à hora) e a executar o fluxo.\n"
       . "Cada rodada do nó agente_ia é uma chamada ao modelo (≈ US$ 0,03–0,15).\n";
}
exit($saida);
