<?php
declare(strict_types=1);

/**
 * app/services/conversion/ConversionAdapter.php
 *
 * CONTRATO de um destino de conversão (Meta, Google, TikTok...).
 * O dispatcher fala com esta interface, não com plataformas
 * concretas — por isso adicionar um destino novo = criar uma
 * classe que implementa isto, sem tocar no dispatcher.
 *
 * Cada adaptador é responsável por:
 *  - transformar o evento do ledger no formato da SUA plataforma
 *  - hashear a PII (via HashingService) conforme a plataforma exige
 *  - enviar e devolver um resultado padronizado
 */
interface ConversionAdapter
{
    /**
     * Nome curto do destino (grava no dead_letter): 'meta', 'google_ads'...
     */
    public function nome(): string;

    /**
     * Este adaptador está configurado e pronto? (tem token/credenciais)
     * Se false, o dispatcher pula este destino sem erro.
     */
    public function estaConfigurado(): bool;

    /**
     * Este evento requer consentimento de MARKETING?
     * (Meta/Ads sim; um destino de analytics puro poderia diferir.)
     * O dispatcher usa isto pra decidir com base no consent snapshot.
     */
    public function requerMarketing(): bool;

    /**
     * Envia UM evento. Recebe o evento já lido do ledger (com o
     * payload decodificado). Devolve o resultado padronizado.
     *
     * @param array $evento linha do tracking_events (payload já em array)
     * @return ConversionResult
     */
    public function enviar(array $evento): ConversionResult;
}

/**
 * Resultado padronizado de um envio. O dispatcher decide o que
 * fazer (retry, dead_letter, sucesso) com base nisto — sem
 * conhecer detalhes de cada plataforma.
 */
final class ConversionResult
{
    public function __construct(
        public readonly bool    $sucesso,
        public readonly ?int    $httpStatus = null,
        public readonly ?string $erro = null,
        public readonly bool    $reenviar = false  // true = falha temporária (retry); false = permanente (dead_letter)
    ) {}

    public static function ok(int $httpStatus = 200): self
    {
        return new self(true, $httpStatus);
    }

    /** Falha TEMPORÁRIA (5xx, timeout) → o dispatcher agenda retry. */
    public static function falhaTemporaria(?int $httpStatus, string $erro): self
    {
        return new self(false, $httpStatus, $erro, true);
    }

    /** Falha PERMANENTE (4xx, payload inválido) → vai pro dead_letter. */
    public static function falhaPermanente(?int $httpStatus, string $erro): self
    {
        return new self(false, $httpStatus, $erro, false);
    }
}