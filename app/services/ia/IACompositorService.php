<?php
/**
 * IACompositorService — motor Imagick do Bloco C. PURO: recebe binários e
 * config, devolve binário. Zero banco, zero HTTP — 100% testável.
 *
 * Camadas (frações do canvas; x/y/larg sobre a LARGURA, fonte sobre a ALTURA):
 *   overlay   {tipo: esquerda|baixo|nenhum, cor: "R,G,B", opacidade}
 *   produto   {cx, cy, larg}                — recorte PNG, âncora no centro
 *   headline  {x, y, larg, fonte, cor, max_linhas}
 *   subtitulo {x, y, larg, fonte, cor, max_linhas}   (opcional)
 *   preco     {x, y, fonte, fundo, cor}              — pill "R$ 9.999,99"
 *   logo      {x, y, larg}                            (opcional, IA_LOGO_PATH)
 *
 * Fonte: IA_FONTE_PATH (AJUSTE opcional p/ fonte da marca) com fallback
 * DejaVuSans-Bold — presente no Ubuntu do VPS e no ambiente de teste.
 */
class IACompositorService
{
    /**
     * A extensão Imagick está disponível?
     *
     * Existe para o pipeline poder barrar ANTES de gastar: sem esta checagem,
     * a ausência do Imagick só aparecia no último passo — depois de pagar o
     * recorte e a cena — como "Error: Class Imagick not found" no log. Com ela,
     * a geração falha no enfileiramento, de graça e com mensagem clara.
     */
    public static function disponivel(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    /**
     * @param array   $layout   linha de ia_layouts (largura, altura)
     * @param array   $camadas  JSON decodificado de ia_layouts.camadas
     * @param string  $cenaBin  binário da cena (JPEG/PNG/WebP)
     * @param ?string $recorteBin binário do PNG transparente do produto (null = sem produto)
     * @param array   $textos   ['headline','subtitulo','preco_txt']
     * @return ?array ['binario','mime','extensao'] ou null em falha (logada)
     */
    public function compor(array $layout, array $camadas, string $cenaBin, ?string $recorteBin, array $textos): ?array
    {
        try {
            $W = (int) $layout['largura'];
            $H = (int) $layout['altura'];

            $base = $this->cenaCover($cenaBin, $W, $H);
            if ($base === null) {
                return null;
            }

            $this->aplicarOverlay($base, $camadas['overlay'] ?? null, $W, $H);

            if ($recorteBin !== null && !empty($camadas['produto'])) {
                $this->aplicarProduto($base, $recorteBin, $camadas['produto'], $W, $H);
            }

            $fonte = $this->fonte();

            if (!empty($camadas['headline']) && trim((string) ($textos['headline'] ?? '')) !== '') {
                $this->escrever($base, $fonte, (string) $textos['headline'], $camadas['headline'], $W, $H);
            }
            if (!empty($camadas['subtitulo']) && trim((string) ($textos['subtitulo'] ?? '')) !== '') {
                $this->escrever($base, $fonte, (string) $textos['subtitulo'], $camadas['subtitulo'], $W, $H);
            }
            if (!empty($camadas['preco']) && trim((string) ($textos['preco_txt'] ?? '')) !== '') {
                $this->aplicarPreco($base, $fonte, (string) $textos['preco_txt'], $camadas['preco'], $W, $H);
            }
            if (!empty($camadas['logo']) && defined('IA_LOGO_PATH') && is_file((string) IA_LOGO_PATH)) {
                $this->aplicarLogo($base, (string) IA_LOGO_PATH, $camadas['logo'], $W, $H);
            }

            $base->setImageFormat('jpeg');
            $base->setImageCompressionQuality(90);
            $binario = $base->getImagesBlob();
            $base->clear();

            return ['binario' => $binario, 'mime' => 'image/jpeg', 'extensao' => 'jpg'];
        } catch (Throwable $e) {
            LogService::error('ia_compositor_erro', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /* ── Camadas ──────────────────────────────────────────── */

    /** Cena em cover: escala pelo lado maior e corta centrado. */
    private function cenaCover(string $bin, int $W, int $H): ?Imagick
    {
        $img = new Imagick();
        if (!$img->readImageBlob($bin)) {
            return null;
        }
        $img->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $iw = $img->getImageWidth();
        $ih = $img->getImageHeight();
        $escala = max($W / max(1, $iw), $H / max(1, $ih));
        $img->resizeImage((int) ceil($iw * $escala), (int) ceil($ih * $escala), Imagick::FILTER_LANCZOS, 1);
        $img->cropImage($W, $H, (int) (($img->getImageWidth() - $W) / 2), (int) (($img->getImageHeight() - $H) / 2));
        $img->setImagePage($W, $H, 0, 0);
        return $img;
    }

    /** Gradiente direcional para legibilidade do texto. */
    private function aplicarOverlay(Imagick $base, ?array $cfg, int $W, int $H): void
    {
        $tipo = (string) ($cfg['tipo'] ?? 'nenhum');
        if ($tipo === 'nenhum' || $cfg === null) {
            return;
        }
        $rgb = (string) ($cfg['cor'] ?? '6,10,18');
        $op  = max(0, min(1, (float) ($cfg['opacidade'] ?? 0.5)));

        $grad = new Imagick();
        // Pseudo-gradiente nasce vertical (topo→base); rotaciona p/ "esquerda".
        if ($tipo === 'esquerda') {
            $grad->newPseudoImage($H, $W, "gradient:rgba({$rgb},{$op})-rgba({$rgb},0)");
            $grad->rotateImage(new ImagickPixel('none'), -90);
        } else { // baixo: transparente em cima, escuro embaixo
            $grad->newPseudoImage($W, $H, "gradient:rgba({$rgb},0)-rgba({$rgb},{$op})");
        }
        $base->compositeImage($grad, Imagick::COMPOSITE_OVER, 0, 0);
        $grad->clear();
    }

    /** Recorte do produto: trim, escala pela largura-alvo e âncora central. */
    private function aplicarProduto(Imagick $base, string $recorteBin, array $cfg, int $W, int $H): void
    {
        $p = new Imagick();
        $p->readImageBlob($recorteBin);
        $p->trimImage(0.02 * Imagick::getQuantum());
        $p->setImagePage(0, 0, 0, 0);

        $alvoW = max(1, (int) round(((float) ($cfg['larg'] ?? 0.4)) * $W));
        $ratio = $p->getImageHeight() / max(1, $p->getImageWidth());
        $alvoH = (int) round($alvoW * $ratio);
        if ($alvoH > 0.92 * $H) { // não estourar o canvas
            $alvoH = (int) round(0.92 * $H);
            $alvoW = (int) round($alvoH / max(0.0001, $ratio));
        }
        $p->resizeImage($alvoW, $alvoH, Imagick::FILTER_LANCZOS, 1);

        $x = (int) round(((float) ($cfg['cx'] ?? 0.5)) * $W - $alvoW / 2);
        $y = (int) round(((float) ($cfg['cy'] ?? 0.5)) * $H - $alvoH / 2);
        $base->compositeImage($p, Imagick::COMPOSITE_OVER, $x, $y);
        $p->clear();
    }

    /** Texto com quebra por medição real (queryFontMetrics). */
    private function escrever(Imagick $base, string $fonte, string $texto, array $cfg, int $W, int $H): void
    {
        $tam     = max(10, (int) round(((float) ($cfg['fonte'] ?? 0.06)) * $H));
        $largMax = (int) round(((float) ($cfg['larg'] ?? 0.8)) * $W);
        $maxLin  = max(1, (int) ($cfg['max_linhas'] ?? 2));

        $draw = new ImagickDraw();
        $draw->setFont($fonte);
        $draw->setFontSize($tam);
        $draw->setFillColor(new ImagickPixel((string) ($cfg['cor'] ?? '#ffffff')));

        $linhas = $this->quebrar($base, $draw, $texto, $largMax, $maxLin);

        $x = (int) round(((float) ($cfg['x'] ?? 0.05)) * $W);
        $y = (int) round(((float) ($cfg['y'] ?? 0.1)) * $H) + $tam; // annotate usa baseline
        foreach ($linhas as $linha) {
            $base->annotateImage($draw, $x, $y, 0, $linha);
            $y += (int) round($tam * 1.18);
        }
    }

    /** Pill do preço: fundo arredondado + texto centrado. */
    private function aplicarPreco(Imagick $base, string $fonte, string $precoTxt, array $cfg, int $W, int $H): void
    {
        $tam = max(10, (int) round(((float) ($cfg['fonte'] ?? 0.07)) * $H));

        $draw = new ImagickDraw();
        $draw->setFont($fonte);
        $draw->setFontSize($tam);

        $m    = $base->queryFontMetrics($draw, $precoTxt);
        $padX = (int) round($tam * 0.65);
        $padY = (int) round($tam * 0.38);
        $pw   = (int) ceil($m['textWidth']) + $padX * 2;
        $ph   = (int) ceil($m['characterHeight']) + $padY * 2;
        $x    = (int) round(((float) ($cfg['x'] ?? 0.05)) * $W);
        $y    = (int) round(((float) ($cfg['y'] ?? 0.7)) * $H);

        $pill = new ImagickDraw();
        $pill->setFillColor(new ImagickPixel((string) ($cfg['fundo'] ?? '#0a66c2')));
        $pill->roundRectangle($x, $y, $x + $pw, $y + $ph, (int) ($ph / 2), (int) ($ph / 2));
        $base->drawImage($pill);

        $draw->setFillColor(new ImagickPixel((string) ($cfg['cor'] ?? '#ffffff')));
        $base->annotateImage($draw, $x + $padX, $y + $padY + (int) ceil($m['ascender']) - 2, 0, $precoTxt);
    }

    private function aplicarLogo(Imagick $base, string $caminho, array $cfg, int $W, int $H): void
    {
        try {
            $logo  = new Imagick($caminho);
            $alvoW = max(1, (int) round(((float) ($cfg['larg'] ?? 0.12)) * $W));
            $logo->resizeImage($alvoW, 0, Imagick::FILTER_LANCZOS, 1);
            $base->compositeImage(
                $logo,
                Imagick::COMPOSITE_OVER,
                (int) round(((float) ($cfg['x'] ?? 0.05)) * $W),
                (int) round(((float) ($cfg['y'] ?? 0.05)) * $H)
            );
            $logo->clear();
        } catch (Throwable $e) {
            LogService::warning('ia_compositor_logo_ignorado', ['erro' => $e->getMessage()]);
        }
    }

    /* ── Internos ─────────────────────────────────────────── */

    private function quebrar(Imagick $base, ImagickDraw $draw, string $texto, int $largMax, int $maxLinhas): array
    {
        $palavras = preg_split('/\s+/u', trim($texto)) ?: [];
        $linhas   = [];
        $atual    = '';

        foreach ($palavras as $palavra) {
            $tentativa = ($atual === '') ? $palavra : $atual . ' ' . $palavra;
            $m = $base->queryFontMetrics($draw, $tentativa);
            if ($m['textWidth'] > $largMax && $atual !== '') {
                $linhas[] = $atual;
                $atual = $palavra;
                if (count($linhas) === $maxLinhas) {
                    break;
                }
            } else {
                $atual = $tentativa;
            }
        }
        if ($atual !== '' && count($linhas) < $maxLinhas) {
            $linhas[] = $atual;
        }
        if (count($linhas) === $maxLinhas) {
            // truncagem honesta na última linha
            $ultima = $linhas[$maxLinhas - 1];
            while ($ultima !== '' && $base->queryFontMetrics($draw, $ultima . '…')['textWidth'] > $largMax) {
                $ultima = mb_substr($ultima, 0, -1);
            }
            if ($ultima !== $linhas[$maxLinhas - 1]) {
                $linhas[$maxLinhas - 1] = rtrim($ultima) . '…';
            }
        }
        return $linhas;
    }

    private function fonte(): string
    {
        if (defined('IA_FONTE_PATH') && is_file((string) IA_FONTE_PATH)) {
            return (string) IA_FONTE_PATH; // AJUSTE opcional: fonte da marca
        }
        foreach ([
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        ] as $f) {
            if (is_file($f)) {
                return $f;
            }
        }
        return 'DejaVu-Sans-Bold'; // nome lógico — último recurso
    }
}
