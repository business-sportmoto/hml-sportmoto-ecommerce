<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/helpers/QrHelper.php
//
// QR code como SVG inline, para impressao.
//
// Por que SVG e nao imagem: a etiqueta de separacao sai em impressora termica
// 80mm. PNG rasterizado borra na conversao de escala da termica; SVG imprime
// nitido em qualquer resolucao. E, sendo inline, nao depende de uma segunda
// requisicao que a janela de impressao poderia disparar tarde demais.
// ════════════════════════════════════════════════════════

class QrHelper
{
    /**
     * SVG pronto para embutir no HTML.
     *
     * @param string $conteudo texto codificado (aqui: o ID do pedido)
     * @param int    $escala   tamanho do modulo; 4 rende ~1,5cm na termica
     */
    public static function svg(string $conteudo, int $escala = 4, string $classe = 'qr'): string
    {
        $conteudo = trim($conteudo);
        if ($conteudo === '') {
            return '';
        }

        if (!class_exists(\chillerlan\QRCode\QRCode::class)) {
            // Sem a lib o resto da etiqueta ainda deve imprimir: o QR e uma
            // conveniencia de busca, nao o dado em si (o ID vai impresso ao lado).
            return '';
        }

        try {
            $opcoes = new \chillerlan\QRCode\QROptions([
                'outputInterface'  => \chillerlan\QRCode\Output\QRMarkupSVG::class,
                'eccLevel'         => \chillerlan\QRCode\Common\EccLevel::M,
                'scale'            => max(1, min(20, $escala)),
                'addQuietzone'     => true,
                'quietzoneSize'    => 2,
                'drawLightModules' => false,
                'outputBase64'     => false,
                'cssClass'         => $classe,
            ]);

            $svg = (new \chillerlan\QRCode\QRCode($opcoes))->render($conteudo);

            // A lib prefixa a declaracao XML; dentro de HTML ela apareceria como texto.
            return (string) preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $svg);
        } catch (\Throwable $e) {
            if (class_exists('LogService')) {
                LogService::warning('Falha ao gerar QR', ['conteudo' => $conteudo, 'erro' => $e->getMessage()]);
            }
            return '';
        }
    }
}
