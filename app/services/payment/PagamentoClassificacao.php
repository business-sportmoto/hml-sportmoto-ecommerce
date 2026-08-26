<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoClassificacao.php
 *
 * Resultado normalizado de UMA tentativa em adquirente, já traduzido para o
 * vocabulário do motor de roteamento. É o contrato entre os adapters e o
 * grafo: o adapter fala "Safra", isto fala "fluxo".
 *
 * `porta` é literalmente a porta de saída do nó tentar_adquirente no Drawflow.
 * Adicionar uma porta aqui significa adicionar uma saída no canvas.
 */
final class PagamentoClassificacao
{
    // ── Portas do nó tentar_adquirente ──────────────────────────────────────
    public const APROVADO          = 'aprovado';
    public const NEGADO_SALDO      = 'negado_saldo';
    public const NEGADO_ANTIFRAUDE = 'negado_antifraude';
    public const NEGADO_DADOS      = 'negado_dados';      // cartão inválido/vencido
    public const NEGADO_GENERICO   = 'negado_generico';
    public const ERRO_TECNICO      = 'erro_tecnico';      // não chegou ao emissor
    public const INDISPONIVEL      = 'indisponivel';      // adquirente fora do ar
    public const INCERTO           = 'incerto';           // timeout: pode ter autorizado

    /**
     * Cobrança criada e aguardando o pagador — Pix e boleto.
     *
     * NÃO é aprovado e NÃO é negado: o fluxo cumpriu seu papel ao produzir um
     * instrumento pagável (QR code, linha digitável). A confirmação chega
     * depois, por webhook. Tratar isto como "aprovado" liberaria pedido sem
     * dinheiro; tratar como falha mandaria o cliente para outra adquirente
     * com um Pix válido já emitido na primeira.
     */
    public const PENDENTE          = 'pendente';

    public string $porta = self::ERRO_TECNICO;

    /** Slug canônico gravado em pgto_tentativas.classe_erro (dashboard/BI). */
    public string $classeErro = 'tecnico';

    /**
     * O motor pode tentar outra adquirente?
     *
     * OPÇÃO 2 (escolhida): true APENAS quando a transação não chegou a ser
     * julgada pelo emissor. Negativa de emissor nunca cai para outra —
     * é isso que mantém a operação fora dos programas de retentativa das
     * bandeiras (Visa Excessive Reattempts, Mastercard TPE) e das multas.
     */
    public bool $podeCairParaOutra = false;

    /**
     * Antes de QUALQUER coisa, consultar a adquirente por merchantTransactionId.
     *
     * Timeout não significa "não aconteceu", significa "não sei": a autorização
     * pode ter sido aprovada e a resposta ter se perdido. Cair para a próxima
     * adquirente sem consultar cobra o cliente duas vezes.
     */
    public bool $exigeConsulta = false;

    /** Texto exibido ao comprador. Nunca vaza detalhe interno da adquirente. */
    public string $mensagemCliente = 'Não foi possível processar o pagamento.';

    // ── Rastro da adquirente (log e auditoria) ──────────────────────────────
    public ?string $codigoAdquirente   = null;  // authorizationResponseCode (ABECS)
    public ?string $mensagemAdquirente = null;
    public ?string $merchantAdviceCode = null;  // MAC — Visa/Mastercard
    public ?string $bandeira           = null;
    public ?string $traceKey           = null;  // correlação no suporte da Safra
    public ?string $chargeId           = null;
    public ?int    $httpStatus         = null;
    public int     $duracaoMs          = 0;

    // ── Instrumento de pagamento (Pix e boleto) ─────────────────────────────
    /** Pix copia-e-cola, imagem do QR em base64 e expiração. */
    public ?string $pixQrCode       = null;
    public ?string $pixQrCodeBase64 = null;
    public ?string $pixExpiraEm     = null;
    /** Boleto: linha digitável, código de barras, URL e vencimento. */
    public ?string $boletoLinhaDigitavel = null;
    public ?string $boletoCodigoBarras   = null;
    public ?string $boletoUrl            = null;
    public ?string $boletoVencimento     = null;

    /** Cancelamento aceito porém ainda em processamento na adquirente (D+N). */
    public bool $cancelamentoPendente = false;

    /**
     * Classificação ABECS: a bandeira considera nova tentativa viável?
     *
     * GRAVADO, NÃO OBEDECIDO na opção 2 — nenhuma negativa é retentada hoje.
     * Fica registrado para que a decisão de abrir retentativa de negativa,
     * mais adiante, seja tomada sobre dados reais da operação e não sobre
     * teoria.
     */
    public ?bool $reversivel = null;

    public function aprovado(): bool
    {
        return $this->porta === self::APROVADO;
    }

    /** Cobrança utilizável pelo cliente: aprovada OU aguardando pagamento. */
    public function sucesso(): bool
    {
        return $this->porta === self::APROVADO || $this->porta === self::PENDENTE;
    }

    /** Emissor respondeu — aprovando ou negando. */
    public function houveJulgamento(): bool
    {
        return in_array($this->porta, [
            self::APROVADO, self::PENDENTE, self::NEGADO_SALDO,
            self::NEGADO_ANTIFRAUDE, self::NEGADO_DADOS, self::NEGADO_GENERICO,
        ], true);
    }

    /** Resultado dessa tentativa para pgto_tentativas.resultado. */
    public function resultado(): string
    {
        return match ($this->porta) {
            self::APROVADO     => 'aprovado',
            self::PENDENTE     => 'pendente',
            self::INDISPONIVEL => 'indisponivel',
            self::INCERTO      => 'timeout',
            self::ERRO_TECNICO => 'erro',
            default            => 'negado',
        };
    }
}
