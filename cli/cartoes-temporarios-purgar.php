<?php
declare(strict_types=1);

/**
 * cli/cartoes-temporarios-purgar.php
 *
 * Recolhe os cartões que o cliente pediu para NÃO salvar e que ficaram para
 * trás.
 *
 * POR QUE EXISTE: quando o cliente desmarca "salvar cartão para as próximas
 * compras", o cartão ainda precisa ir para os cofres das adquirentes — é de
 * lá que sai a referência de cobrança. A limpeza normal acontece no fim da
 * compra, assim que o pagamento tem resultado. Este worker é a rede embaixo,
 * para quando essa hora não chega:
 *
 *   - o cliente fechou o navegador no meio do desafio 3DS;
 *   - o PHP morreu depois de gravar o cartão e antes de cobrar;
 *   - o pagamento ficou pendente e ninguém voltou.
 *
 * Sem ele o cartão fica nos cofres para sempre, contra a vontade de quem
 * digitou — e contando contra o limite de cartões por cliente da adquirente.
 *
 * A janela padrão é de 60 minutos e o mínimo aceito é 15. Ela PRECISA ser
 * maior que a validade de um desafio 3DS: apagar o cartão de uma compra que
 * ainda está acontecendo faria a cobrança falhar sozinha.
 *
 * Uso:
 *   php cli/cartoes-temporarios-purgar.php              # simula
 *   php cli/cartoes-temporarios-purgar.php --aplicar    # remove de verdade
 *   php cli/cartoes-temporarios-purgar.php --aplicar --minutos=120
 *
 * Cron sugerido: a cada 5 minutos.
 * Ver docs/sportmoto-os/07-workers-cron/mapa-workers-cron.md para a linha
 * exata do crontab (nao cabe aqui: a expressao fecharia este comentario).
 */

require __DIR__ . '/../bootstrap-cli.php';

$aplicar = in_array('--aplicar', $argv, true);

$minutos = 60;
foreach ($argv as $a) {
    if (preg_match('/^--minutos=(\d+)$/', (string) $a, $m)) {
        $minutos = (int) $m[1];
    }
}
$minutos = max(15, $minutos);

echo $aplicar
    ? "Purgando cartoes temporarios com mais de {$minutos} min (REMOVENDO)...\n\n"
    : "Simulacao — nada sera removido. Use --aplicar para valer.\n"
    . "Janela: {$minutos} minutos.\n\n";

$modelo = new CartaoSalvo();
$achados = $modelo->temporariosExpirados($minutos);

if ($achados === []) {
    echo "Nada a fazer.\n";
    exit(0);
}

foreach ($achados as $c) {
    printf("  cartao #%-6d cliente %-6d criado em %s\n",
        (int) $c['id'], (int) $c['cliente_id'], (string) $c['criado_em']);
}
echo "\n";

if (!$aplicar) {
    printf("%d cartao(oes) seriam removidos.\n", count($achados));
    exit(0);
}

$r = (new CartaoSalvoService())->purgarExpirados($minutos);

printf("achados:   %d\nremovidos: %d\nerros:     %d\n",
    $r['achados'], $r['removidos'], $r['erros']);

// Sai diferente de zero quando algo falhou, para o cron registrar.
exit($r['erros'] > 0 ? 1 : 0);
