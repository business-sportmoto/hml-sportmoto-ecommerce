<?php
declare(strict_types=1);

/**
 * cli/cartoes-reconciliar.php
 *
 * Preenche titular e validade dos cartões salvos, lendo a adquirente.
 *
 * POR QUE EXISTE: até agora o checkout gravava 'TITULAR' e '12/99' fixos —
 * herança dos hosted fields, onde esses dados nunca saíam do iframe. O
 * Mercado Pago devolve os dois na resposta do cartão, então os registros
 * antigos podem ser corrigidos com o dado real em vez de ficarem com um
 * placeholder que a tela do cliente exibia como se fosse do cartão dele.
 *
 * Uso:
 *   php cli/cartoes-reconciliar.php            # simula, não grava
 *   php cli/cartoes-reconciliar.php --aplicar  # grava
 *
 * Só toca em linhas que ainda estão com o placeholder ou nulas, e só nas que
 * têm customer_ref + card_ref — sem esse par não há o que perguntar.
 */

require __DIR__ . '/../bootstrap-cli.php';

$aplicar = in_array('--aplicar', $argv, true);

echo $aplicar
    ? "Reconciliando cartoes salvos (GRAVANDO)...\n\n"
    : "Simulacao — nada sera gravado. Use --aplicar para valer.\n\n";

$r = (new CartaoSalvoService())->reconciliar($aplicar);

printf("  lidos:       %d\n", $r['lidos']);
printf("  %s %d\n", $aplicar ? 'atualizados:' : 'atualizaveis:', $r['atualizados']);
printf("  sem resposta da adquirente: %d\n", $r['erros']);

if (!$aplicar && $r['atualizados'] > 0) {
    echo "\nPara gravar:  php cli/cartoes-reconciliar.php --aplicar\n";
}

exit(0);
