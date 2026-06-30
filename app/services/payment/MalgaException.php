<?php
/**
 * Exception específica do gateway Malga.
 *
 * Carrega o HTTP status code e o payload de resposta cru pra facilitar debug
 * e tratamento diferenciado por tipo de erro.
 *
 * Uso típico:
 *   try {
 *       $service->criarChargePix(...);
 *   } catch (MalgaException $e) {
 *       LogService::error('Falha Malga', ['code' => $e->getHttpCode(), 'body' => $e->getResponseBody()]);
 *   }
 */
class MalgaException extends Exception
{
    /** @var int HTTP status retornado pela Malga (0 quando erro de rede/timeout) */
    protected $httpCode = 0;

    /** @var array Corpo decodificado da resposta de erro */
    protected $responseBody = [];

    public function __construct(string $message, int $httpCode = 0, array $responseBody = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $httpCode, $previous);
        $this->httpCode = $httpCode;
        $this->responseBody = $responseBody;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    /**
     * True quando o erro é de rede (timeout, DNS, conexão recusada).
     * Útil pra decidir retry automático.
     */
    public function isNetworkError(): bool
    {
        return $this->httpCode === 0;
    }
}
