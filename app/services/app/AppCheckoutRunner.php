<?php
declare(strict_types=1);

/**
 * app/services/app/AppCheckoutRunner.php
 *
 * Executa o checkout do app pelo MESMO código que a web usa.
 *
 * CheckoutController::process() são ~400 linhas que criam o pedido, baixam
 * estoque, reservam cupom, registram promoções, inserem brindes, debitam
 * crédito e chamam o gateway — tudo numa transação. Reescrever isso para o app
 * seria criar um segundo caminho para o dinheiro, que envelheceria em direções
 * diferentes. Um cupom novo passaria a valer na web e não no app, e ninguém
 * descobriria até um cliente reclamar.
 *
 * Então o app não reimplementa: ele prepara $_POST e a sessão exatamente como
 * finalize() faz e chama process(). O único obstáculo é que process() termina
 * chamando $this->json(), que imprime a resposta da WEB e encerra o processo —
 * e o app precisa do envelope {ok, dados} da API.
 *
 * A saída é sobrescrever json(): em vez de imprimir, guarda o payload e lança
 * CheckoutRespondeu, que desempilha até executar(). Não é elegante, mas é
 * honesto sobre o que está acontecendo, e é temporário: a Fase 5 extrai um
 * OrderPlacementService com DTO e os dois viram chamadores finos.
 */
final class AppCheckoutRunner extends CheckoutController
{
    private array $resposta = [];

    /**
     * @param array $post   O que finalize() montaria em $_POST.
     * @return array        O payload que process() teria imprimido.
     */
    public static function finalizar(array $post): array
    {
        // process() lê de $_POST direto. Preservamos e restauramos o global
        // para não deixar rastro em nada que rode depois nesta requisição.
        $original = $_POST;
        $_POST    = array_merge($_POST, $post);

        try {
            $runner = new self();
            return $runner->executar();
        } finally {
            $_POST = $original;
        }
    }

    private function executar(): array
    {
        try {
            $this->process();
        } catch (CheckoutRespondeu) {
            // Caminho normal: process() "respondeu" e nós capturamos.
        }

        return $this->resposta;
    }

    /**
     * Captura em vez de imprimir. Precisa lançar: process() chama json() em
     * pontos de saída antecipada (carrinho vazio, endereço inválido, cartão sem
     * token) contando que o `exit` do Controller interrompa ali. Sem a exceção,
     * a execução continuaria e criaria um pedido que a validação recusou.
     */
    protected function json(array $payload, int $status = 200): void
    {
        $this->resposta = $payload;
        throw new CheckoutRespondeu();
    }
}
