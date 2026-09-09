<?php
declare(strict_types=1);

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;

/**
 * app/services/payment/PixQrCodeRenderer.php
 *
 * Gera a imagem do QR do Pix a partir do copia-e-cola.
 *
 * POR QUE NÃO USAR A IMAGEM DA ADQUIRENTE:
 *   A Safra devolve o QR como BMP NÃO COMPRIMIDO em base64 — 113.760 bytes.
 *   A coluna `pedidos.pix_qr_code` é TEXT (65.535), então o UPDATE inteiro
 *   falhava com "1406 Data too long" e derrubava a finalização da compra.
 *   Renderizado aqui em PNG dá ~3 KB: 97% menor e sempre cabe.
 *
 *   Cada adquirente devolve a imagem num formato e tamanho diferentes (ou não
 *   devolve). O copia-e-cola é o padrão do Banco Central e é idêntico em todas
 *   — gerar a partir dele torna o resultado previsível, independente de quem
 *   processou.
 *
 * O retorno é um data URI (`data:image/png;base64,...`), que é o que as telas
 * de pedido já esperam em `pix_qr_code`.
 */
class PixQrCodeRenderer
{
    /** Escala do módulo. 6 dá ~3 KB e boa leitura em tela e impressão. */
    private const ESCALA = 6;

    /** Margem obrigatória do padrão EMV para o leitor achar o código. */
    private const MARGEM = 2;

    /**
     * Data URI do QR, ou null se não for possível gerar.
     *
     * NUNCA lança: um QR que não renderiza não pode derrubar o checkout —
     * o cliente ainda pode pagar pelo copia-e-cola.
     */
    public static function dataUri(?string $copiaCola): ?string
    {
        $copiaCola = trim((string) $copiaCola);
        if ($copiaCola === '') {
            return null;
        }

        try {
            $opcoes = new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'scale'           => self::ESCALA,
                'quietzoneSize'   => self::MARGEM,
                'outputBase64'    => true,
            ]);

            return (new QRCode($opcoes))->render($copiaCola);

        } catch (\Throwable $e) {
            LogService::warning('Falha ao renderizar QR do Pix', [
                'erro' => $e->getMessage(),
            ], 'pagamento');
            return null;
        }
    }

    /**
     * Escolhe o que gravar em `pedidos.pix_qr_code`.
     *
     * Prefere o QR gerado aqui. A imagem da adquirente só entra se couber na
     * coluna — assim, uma adquirente que devolva um PNG enxuto continua sendo
     * aproveitada, e uma que devolva BMP gigante não quebra a gravação.
     *
     * @param int $limite Bytes disponíveis na coluna (TEXT = 65.535).
     */
    public static function paraPersistir(?string $copiaCola, ?string $imagemAdquirente, int $limite = 65000): ?string
    {
        $nosso = self::dataUri($copiaCola);
        if ($nosso !== null && strlen($nosso) <= $limite) {
            return $nosso;
        }

        if ($imagemAdquirente !== null && $imagemAdquirente !== ''
            && strlen($imagemAdquirente) <= $limite) {
            return $imagemAdquirente;
        }

        // Sem imagem que caiba. O copia-e-cola é gravado à parte e sozinho já
        // permite o pagamento — gravar null aqui é melhor do que perder o
        // UPDATE inteiro do pedido.
        return null;
    }
}
