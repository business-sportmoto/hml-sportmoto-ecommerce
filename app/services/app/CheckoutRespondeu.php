<?php
declare(strict_types=1);

/**
 * app/services/app/CheckoutRespondeu.php
 *
 * Sinal de controle de fluxo, não erro.
 *
 * CheckoutController::process() encerra chamando $this->json(), que na web
 * imprime e dá `exit`. No app, AppCheckoutRunner sobrescreve json() para
 * capturar o payload — e precisa de alguma forma de interromper a execução no
 * mesmo ponto em que o `exit` interromperia. É esta exceção.
 *
 * Nunca deve escapar de AppCheckoutRunner::executar(). Se aparecer num log, é
 * porque process() ganhou um caminho que chama json() fora do runner.
 */
final class CheckoutRespondeu extends RuntimeException
{
}
