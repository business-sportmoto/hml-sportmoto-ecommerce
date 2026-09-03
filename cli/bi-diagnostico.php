#!/usr/bin/env php
<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// cli/bi-diagnostico.php — por que o painel de BI está vazio?
//
// Subir os arquivos PHP é METADE do deploy. O BI depende de
// objetos de banco (colunas, 3 tabelas e 26 views) que só existem
// depois de rodar os três .sql. Sem eles o painel abre
// vazio ou estoura — e nada na tela explica o porquê.
//
// Este script diz, no ambiente, exatamente o que falta.
//
//   php cli/bi-diagnostico.php
//
// Só LÊ. Não cria, não altera, não apaga nada.
// ════════════════════════════════════════════════════════

require_once __DIR__ . '/../bootstrap-cli.php';

$db = Database::getInstance()->getConnection();
$sc = $db->query('SELECT DATABASE()')->fetchColumn();

$problemas = [];
$avisos    = [];

function linha(string $status, string $texto, string $extra = ''): void {
    $icone = [
        'ok'    => '  [ok]   ',   // está no lugar
        'falta' => '  [FALTA]',   // objeto de banco ausente — rode o .sql
        'aviso' => '  [aviso]',   // dado ainda vazio, sem defeito
        'bug'   => '  [BUG]  ',   // defeito de OUTRO módulo, ver 04-bugs
    ][$status];
    echo $icone . ' ' . $texto . ($extra !== '' ? "  — {$extra}" : '') . PHP_EOL;
}

function existeColuna(PDO $db, string $tabela, string $coluna): bool {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $st->execute([$tabela, $coluna]);
    return (bool)$st->fetchColumn();
}

function existeObjeto(PDO $db, string $nome): bool {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $st->execute([$nome]);
    return (bool)$st->fetchColumn();
}

echo PHP_EOL . "══ DIAGNÓSTICO DO BI ══  banco: {$sc}" . PHP_EOL . PHP_EOL;

// ────────────────────────────────────────────────────────
echo "FASE 0 — verdade única (sql/bi-fase0.sql)" . PHP_EOL;

$f0 = [
    ['coluna', 'pedido_status', 'classe_bi', 'sem ela o BI não sabe o que é venda'],
    ['objeto', 'bi_dim_data',   null,        'calendário; sem ele as séries não têm espinha'],
    ['objeto', 'bi_dim_status', null,        'dimensão de status'],
    ['objeto', 'bi_pedido_geografia', null,  'geografia dos pedidos'],
];
$faltaFase0 = 0;
foreach ($f0 as [$tipo, $a, $b, $motivo]) {
    $ok = $tipo === 'coluna' ? existeColuna($db, $a, $b) : existeObjeto($db, $a);
    linha($ok ? 'ok' : 'falta', $tipo === 'coluna' ? "{$a}.{$b}" : $a, $ok ? '' : $motivo);
    if (!$ok) { $problemas[] = 'sql/bi-fase0.sql'; $faltaFase0++; }
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "FASE 1 — instrumentação (sql/bi-fase1.sql)" . PHP_EOL;

$f1 = [
    ['coluna', 'produtos',     'preco_custo'],
    ['coluna', 'pedido_itens', 'custo_unitario'],
    ['coluna', 'pedidos',      'canal'],
    ['coluna', 'pedidos',      'utm_source'],
    ['coluna', 'pedidos',      'motivo_cancelamento_id'],
    ['objeto', 'motivos_cancelamento', null],
    ['objeto', 'bi_metas',              null],
];
$faltaFase1 = 0;
foreach ($f1 as [$tipo, $a, $b]) {
    $ok = $tipo === 'coluna' ? existeColuna($db, $a, $b) : existeObjeto($db, $a);
    linha($ok ? 'ok' : 'falta', $tipo === 'coluna' ? "{$a}.{$b}" : $a);
    if (!$ok) { $problemas[] = 'sql/bi-fase1.sql'; $faltaFase1++; }
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "FASE 2 — camada semântica (sql/bi-fase2.sql)" . PHP_EOL;

// A camada semântica CRESCEU depois do primeiro deploy. Quem subiu os
// PHP e não reaplicou o bi-fase2.sql fica sem as views do segundo
// lote — e é a causa mais comum de "nada de novo aparece".
// Por isso as duas listas são separadas: a mensagem tem que dizer
// QUAL lote falta, não só "faltam N views".
$viewsBase = [
    'bi_dim_categoria','bi_dim_marca','bi_dim_produto','bi_dim_sku','bi_dim_cliente',
    'bi_dim_vendedor','bi_dim_cupom','bi_dim_transportadora','bi_dim_motivo',
    'bi_fato_pedido','bi_fato_item','bi_fato_pagamento','bi_fato_devolucao',
    'bi_fato_frete','bi_fato_estoque_saldo','bi_fato_estoque_mov','bi_fato_carrinho',
    'bi_fato_funil','bi_fato_meta','bi_cobertura_custo','bi_saude_dados',
];
$viewsNovas = [
    'bi_fato_cupom'            => 'Cupons',
    'bi_fato_clip'             => 'Clips',
    'bi_fato_clip_view'        => 'Clips (views por dia)',
    'bi_fato_clip_produto'     => 'Clips (produtos do clip)',
    'bi_fato_compartilhamento' => 'Carrinhos compartilhados',
    'bi_fato_pergunta'         => 'Perguntas e IA',
    'bi_fato_ia'               => 'Perguntas e IA (uso de IA)',
];

$faltaBase = array_values(array_filter($viewsBase, fn($v) => !existeObjeto($db, $v)));
if ($faltaBase) {
    linha('falta', count($faltaBase) . ' de ' . count($viewsBase) . ' views base ausentes',
          implode(', ', array_slice($faltaBase, 0, 6)) . (count($faltaBase) > 6 ? '…' : ''));
    $problemas[] = 'sql/bi-fase2.sql';
} else {
    linha('ok', 'as ' . count($viewsBase) . ' views base existem');
}

$faltaNovas = [];
foreach ($viewsNovas as $v => $modulo) {
    if (!existeObjeto($db, $v)) $faltaNovas[$v] = $modulo;
}
if ($faltaNovas) {
    linha('falta', count($faltaNovas) . ' view(s) do LOTE NOVO ausentes',
          implode(', ', array_keys($faltaNovas)));
    $problemas[] = 'sql/bi-fase2.sql';
    $avisos[] = "As páginas " . implode(', ', array_unique(array_values($faltaNovas)))
              . " vão aparecer VAZIAS até o bi-fase2.sql ser reaplicado. O resto do "
              . "painel funciona: desde 02/09/2026 o dashboard degrada em vez de "
              . "estourar 500, e mostra um aviso no topo listando o que falta.";
} else {
    linha('ok', 'as ' . count($viewsNovas) . ' views do lote novo existem'
               . ' (clips, compartilhados, cupons, perguntas e IA)');
}

// ────────────────────────────────────────────────────────
// Se a base não está de pé, não adianta olhar dado.
if ($problemas) {
    $unicos = array_values(array_unique($problemas));
    echo PHP_EOL . str_repeat('─', 62) . PHP_EOL;

    // Falta base e falta lote novo têm gravidade diferente: sem a base
    // o painel não tem número nenhum; sem o lote novo só os painéis
    // novos ficam vazios. Dizer "a tela está vazia" nos dois casos
    // manda o leitor procurar o problema no lugar errado.
    $soLoteNovo = $faltaFase0 === 0
               && $faltaFase1 === 0
               && empty($faltaBase)
               && !empty($faltaNovas);

    if ($soLoteNovo) {
        echo "O PAINEL FUNCIONA — MAS FALTAM AS VIEWS DO LOTE NOVO." . PHP_EOL . PHP_EOL;
        echo "Faturamento, produtos, clientes e geografia estão de pé." . PHP_EOL;
        echo "Só as páginas " . implode(', ', array_unique(array_values($faltaNovas))) . PHP_EOL;
        echo "aparecem vazias, com um aviso amarelo no topo do painel." . PHP_EOL . PHP_EOL;
        echo "É o caso clássico de subir os PHP sem reaplicar o SQL: a" . PHP_EOL;
        echo "camada semântica cresceu depois do último deploy." . PHP_EOL . PHP_EOL;
        echo "  mysql --default-character-set=utf8mb4 -u USER -p {$sc} < sql/bi-fase2.sql" . PHP_EOL;
        echo PHP_EOL . "É idempotente: rodar de novo não duplica nada." . PHP_EOL;
        exit(1);
    }

    echo "É POR ISSO QUE O PAINEL ESTÁ SEM NÚMERO." . PHP_EOL . PHP_EOL;
    echo "Os arquivos PHP subiram, mas os objetos de banco não existem" . PHP_EOL;
    echo "neste ambiente. Rode, NESTA ORDEM e com o charset explícito:" . PHP_EOL . PHP_EOL;
    foreach (['sql/bi-fase0.sql','sql/bi-fase1.sql','sql/bi-fase2.sql'] as $f) {
        $marca = in_array($f, $unicos, true) ? '  <<< pendente' : '';
        echo "  mysql --default-character-set=utf8mb4 -u USER -p {$sc} < {$f}{$marca}" . PHP_EOL;
    }
    echo PHP_EOL . "Os três são idempotentes: rodar de novo não duplica nada." . PHP_EOL;
    echo "O --default-character-set=utf8mb4 NÃO é opcional (acento e colação)." . PHP_EOL;
    exit(1);
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "O QUE JÁ APARECE (lido do que a loja sempre teve)" . PHP_EOL;

$pedidos = (int)$db->query("SELECT COUNT(*) FROM bi_fato_pedido")->fetchColumn();
$vendas  = (int)$db->query("SELECT COUNT(*) FROM bi_fato_pedido WHERE venda_valida=1")->fetchColumn();
$receita = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM bi_fato_pedido WHERE venda_valida=1")->fetchColumn();

linha($pedidos > 0 ? 'ok' : 'aviso', "pedidos na base: {$pedidos}");
linha($vendas  > 0 ? 'ok' : 'aviso',
      "contando como venda: {$vendas}  ·  R$ " . number_format($receita, 2, ',', '.'));

if ($pedidos > 0 && $vendas === 0) {
    $avisos[] = "Há pedidos, mas NENHUM conta como venda. Provável causa: os status "
              . "deste ambiente não são os mesmos do desenvolvimento e ficaram todos "
              . "em 'pre_venda' (o default seguro). Confira em "
              . "/admin/configuracoes/status-pedidos — cada status tem o campo "
              . "\"Como o BI deve contar este status\".";
}

$semClasse = $db->query(
    "SELECT slug FROM pedido_status
      WHERE classe_bi = 'pre_venda' AND slug NOT LIKE 'aguardando%' AND slug <> 'em_analise'"
)->fetchAll(PDO::FETCH_COLUMN);
if ($semClasse) {
    linha('aviso', count($semClasse) . " status ainda como 'pre_venda'", implode(', ', $semClasse));
    $avisos[] = "Status em 'pre_venda' NÃO entram no faturamento. É o default de "
              . "propósito — classifique cada um em /admin/configuracoes/status-pedidos.";
}

// Pedido cuja data não existe em bi_dim_data fica INVISÍVEL em
// qualquer modelo que use o calendário como tabela de datas — o caso
// do Power BI. Some sem erro, que é o pior tipo de falha.
$foraCal = (int)$db->query(
    "SELECT COUNT(*) FROM bi_fato_pedido p
      LEFT JOIN bi_dim_data d ON d.data = p.data
      WHERE p.data IS NOT NULL AND d.data IS NULL"
)->fetchColumn();
if ($foraCal > 0) {
    $lim = $db->query("SELECT MIN(data) a, MAX(data) b FROM bi_dim_data")->fetch();
    linha('falta', "{$foraCal} pedido(s) FORA do calendário",
          "bi_dim_data cobre {$lim['a']} a {$lim['b']} — esses pedidos somem no Power BI");
    $avisos[] = "Estenda o seed de bi_dim_data em sql/bi-fase0.sql §2 (e acrescente "
              . "as Páscoas correspondentes em §2.2), depois rode o script de novo.";
} else {
    linha('ok', 'todo pedido tem linha no calendário');
}

// ────────────────────────────────────────────────────────
// Só faz sentido consultar se as views do lote novo existem.
if (!$faltaNovas) {
    echo PHP_EOL . "ENGAJAMENTO (clips e compartilhamentos)" . PHP_EOL;

    $cl = $db->query(
        "SELECT COUNT(*) clips, COALESCE(SUM(views),0) views,
                COALESCE(SUM(likes),0) likes, COALESCE(SUM(comentarios),0) coment,
                COALESCE(SUM(produtos_vinculados > 0),0) com_produto
           FROM bi_fato_clip"
    )->fetch();
    linha((int)$cl['clips'] > 0 ? 'ok' : 'aviso',
          "clips: {$cl['clips']}  ·  {$cl['views']} views  ·  {$cl['likes']} curtidas  ·  {$cl['coment']} comentários");

    // Clip sem produto vinculado não responde "clip vende?" — o
    // vínculo é a N:N clip_produtos, não clips.produto_id.
    if ((int)$cl['clips'] > 0 && (int)$cl['com_produto'] === 0) {
        linha('aviso', 'nenhum clip tem produto vinculado',
              'vincule em clip_produtos — sem isso não dá para cruzar clip com receita');
    } elseif ((int)$cl['clips'] > 0) {
        linha('ok', "{$cl['com_produto']} de {$cl['clips']} clips com produto vinculado");
    }

    $sh = $db->query(
        "SELECT COUNT(*) shares, COALESCE(SUM(visualizacoes_unicas),0) views,
                COALESCE(SUM(conversoes),0) conv,
                COALESCE(SUM(pedidos_identificados),0) ped,
                COALESCE(SUM(contador_visualizacoes),0) contador
           FROM bi_fato_compartilhamento"
    )->fetch();
    linha((int)$sh['shares'] > 0 ? 'ok' : 'aviso',
          "compartilhamentos: {$sh['shares']}  ·  {$sh['views']} views  ·  {$sh['conv']} conversões");

    // A lacuna que zera a receita de compartilhamento — e que também
    // zera /minha-conta/carrinhos-compartilhados.
    if ((int)$sh['conv'] > 0 && (int)$sh['ped'] === 0) {
        linha('bug', "{$sh['conv']} conversão(ões) SEM pedido amarrado",
              'uso.pedido_id nunca é gravado — receita por compartilhamento fica incalculável');
        $avisos[] = "`carrinhos_compartilhados_uso.pedido_id` vem NULL até no evento "
                  . "'finalizou_pedido'. Dá para contar conversão, não receita. A mesma "
                  . "lacuna zera /minha-conta/carrinhos-compartilhados. Ver 04-bugs.";
    }

    // Contador desnormalizado divergindo do evento.
    if ((int)$sh['contador'] !== (int)$sh['views'] && (int)$sh['shares'] > 0) {
        linha('aviso', "contador de visualizações divergente: {$sh['contador']} vs {$sh['views']} eventos",
              'o BI usa o EVENTO; o contador é cache');
    }
}

// ────────────────────────────────────────────────────────
if (!$faltaNovas) {
    echo PHP_EOL . "ATENDIMENTO (perguntas e IA)" . PHP_EOL;

    $q = $db->query(
        "SELECT COUNT(*) total,
                COALESCE(SUM(respondida),0)              respondidas,
                COALESCE(SUM(respondida_por_ia),0)       por_ia,
                COALESCE(SUM(respondida_por_admin),0)    por_admin,
                COALESCE(SUM(status='aguardando_ia'),0)  fila,
                COALESCE(SUM(votos_uteis),0)             uteis,
                MIN(data) de, MAX(data) ate
           FROM bi_fato_pergunta"
    )->fetch();

    linha((int)$q['total'] > 0 ? 'ok' : 'aviso',
          "perguntas: {$q['total']}  ·  {$q['respondidas']} respondidas "
          . "({$q['por_ia']} por IA, {$q['por_admin']} pelo time)  ·  {$q['uteis']} votos úteis");

    // Fila parada é cliente esperando na loja — o pior número desta seção.
    if ((int)$q['fila'] > 0) {
        linha('bug', "{$q['fila']} pergunta(s) parada(s) em 'aguardando_ia'",
              'a IA não respondeu e não passou para o admin — verifique o caminho '
              . 'PerguntaController → GeminiQAService');
        $avisos[] = "{$q['fila']} perguntas em 'aguardando_ia'. O status inicial é esse, "
                  . "e quem sai dele é o GeminiQAService (ou marcarParaAdmin). Parada ali "
                  . "significa que nenhum dos dois rodou.";
    }

    // O período padrão do painel é 30 dias. Pergunta antiga não aparece
    // ali, e "painel vazio" fica indistinguível de "painel quebrado".
    if ((int)$q['total'] > 0) {
        $recentes = (int)$db->query(
            "SELECT COUNT(*) FROM bi_fato_pergunta WHERE data >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        )->fetchColumn();
        if ($recentes === 0) {
            linha('aviso', 'nenhuma pergunta nos últimos 30 dias',
                  "as {$q['total']} existentes são de {$q['de']} a {$q['ate']} — "
                  . 'no painel, amplie o período para 12 meses');
        } else {
            linha('ok', "{$recentes} pergunta(s) nos últimos 30 dias (aparecem no período padrão)");
        }
    }

    // Modelo de IA: existe para o roteador, NÃO para as perguntas.
    $ia = $db->query(
        "SELECT COUNT(*) total, COUNT(DISTINCT modelo) modelos,
                COALESCE(SUM(falhou),0) falhas,
                ROUND(COALESCE(SUM(custo_real_usd),0),4) custo
           FROM bi_fato_ia"
    )->fetch();
    linha((int)$ia['total'] > 0 ? 'ok' : 'aviso',
          "gerações de IA: {$ia['total']}  ·  {$ia['modelos']} modelos  ·  "
          . "{$ia['falhas']} falhas  ·  US\$ {$ia['custo']} reais");

    if ((int)$q['por_ia'] > 0) {
        linha('aviso', 'qual modelo respondeu cada pergunta NÃO é gravado',
              'o GeminiQAService salva só o texto; nada vai para ia_geracoes');
    }
}

// ────────────────────────────────────────────────────────
// Marca é quase obrigatória no cadastro, então produto sem marca
// derruba a página de Marcas em silêncio.
if (!$faltaBase) {
    echo PHP_EOL . "RECORTES DE CATÁLOGO" . PHP_EOL;
    foreach ([
        ['marca',     'marca_id',     'Marcas'],
        ['categoria', 'categoria_id', 'Categorias'],
    ] as [$rot, $col, $pagina]) {
        $d = $db->query(
            "SELECT COUNT(*) itens, COALESCE(SUM({$col} IS NOT NULL),0) com,
                    ROUND(100 * COALESCE(SUM({$col} IS NOT NULL),0) / NULLIF(COUNT(*),0), 1) pct
               FROM bi_fato_item WHERE venda_valida = 1"
        )->fetch();
        $pct = (float)($d['pct'] ?? 0);
        linha($pct >= 90 ? 'ok' : 'aviso',
              str_pad("itens de venda com {$rot}", 34) . "{$d['com']} de {$d['itens']}  ({$pct}%)",
              $pct >= 90 ? '' : "o que falta não aparece na página {$pagina}");
    }
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "O QUE SÓ PREENCHE DAQUI PRA FRENTE" . PHP_EOL;

$daquiPraFrente = [
    ['custo dos produtos',   "SELECT COUNT(*) FROM produtos WHERE preco_custo > 0",
     'cadastre no formulário do produto/SKU — destrava margem, lucro e ABC por lucro'],
    ['custo congelado nas vendas', "SELECT COUNT(*) FROM pedido_itens WHERE custo_unitario IS NOT NULL",
     'preenche sozinho a cada venda nova, depois que houver custo cadastrado'],
    ['metas cadastradas',    "SELECT COUNT(*) FROM bi_metas",
     'cadastre em /admin/bi/metas'],
    ['pedidos com UTM',      "SELECT COUNT(*) FROM pedidos WHERE utm_source IS NOT NULL",
     'preenche sozinho quando houver campanha com utm na URL'],
    ['motivo de cancelamento', "SELECT COUNT(*) FROM pedidos WHERE motivo_cancelamento_id IS NOT NULL",
     'preenche ao cancelar um pedido pelo admin'],
];
foreach ($daquiPraFrente as [$rotulo, $sql, $comoEncher]) {
    $n = (int)$db->query($sql)->fetchColumn();
    linha($n > 0 ? 'ok' : 'aviso', str_pad($rotulo, 30) . $n, $n > 0 ? '' : $comoEncher);
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . "COBERTURA (bi_saude_dados)" . PHP_EOL;
foreach ($db->query("SELECT * FROM bi_saude_dados") as $s) {
    $total = (int)$s['total'];
    if ($total === 0) {
        // 0% sobre zero linhas nao e "nada coberto", e "nada a cobrir".
        // Mostrar 0% aqui sugeriria um problema que nao existe.
        linha('aviso', str_pad($s['indicador'], 26) . str_pad('—', 8) . '(sem base)');
        continue;
    }
    $pct = (float)($s['pct'] ?? 0);
    linha($pct >= 50 ? 'ok' : 'aviso',
          str_pad($s['indicador'], 26) . str_pad($pct . '%', 8)
          . "({$s['preenchido']} de {$total})");
}

// ────────────────────────────────────────────────────────
echo PHP_EOL . str_repeat('─', 62) . PHP_EOL;

if ($pedidos === 0) {
    echo "ESTRUTURA OK — MAS A BASE ESTÁ VAZIA." . PHP_EOL . PHP_EOL;
    echo "Os 24 objetos de banco existem e as views respondem. O painel vai" . PHP_EOL;
    echo "abrir sem números porque NÃO HÁ PEDIDO NENHUM em `{$sc}` —" . PHP_EOL;
    echo "isso é o comportamento correto, não uma falha do BI." . PHP_EOL . PHP_EOL;
    echo "O BI lê o que a loja tem; ele não gera dado. Para ver o painel" . PHP_EOL;
    echo "com conteúdo, este ambiente precisa de pedidos: importe uma cópia" . PHP_EOL;
    echo "da produção, ou faça alguns pedidos de teste pela própria loja." . PHP_EOL . PHP_EOL;
    echo "Um pedido de teste já acende quase tudo: faturamento, produtos," . PHP_EOL;
    echo "geografia, funil, pagamentos e estoque." . PHP_EOL;
    exit(0);
}

echo "BANCO OK — o painel tem o que precisar para renderizar." . PHP_EOL;
if ($avisos) {
    echo PHP_EOL . "Atenção:" . PHP_EOL;
    foreach ($avisos as $a) echo PHP_EOL . '  · ' . wordwrap($a, 68, PHP_EOL . '    ') . PHP_EOL;
}
echo PHP_EOL . "Se mesmo assim a tela estiver vazia, o problema é de aplicação:" . PHP_EOL;
echo "  1. aviso amarelo no topo do painel = view faltando. Reaplique" . PHP_EOL;
echo "     sql/bi-fase2.sql e rode este script de novo." . PHP_EOL;
echo "  2. acesse /admin/power-bi por GET (link do topo), não por POST" . PHP_EOL;
echo "  3. o painel exige cargo super ou gerente" . PHP_EOL;
echo "  4. CSS sem estilo (barras viradas texto) = cache do navegador" . PHP_EOL;
echo "  5. confira storage/logs/ do dia — o painel loga 'BI: view ausente'" . PHP_EOL . PHP_EOL;
