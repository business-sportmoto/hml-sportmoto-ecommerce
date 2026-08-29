<?php
declare(strict_types=1);

// app/services/AvaliacaoMidiaService.php
//
// Upload de fotos e vídeos de avaliação, antes de a avaliação existir.
//
// O arquivo vai para uploads/avaliacoes/ e uma linha em avaliacao_midias_temp
// segura o vínculo pelo `token`. Quando a avaliação é publicada,
// Review::vincularMidias() move as linhas para avaliacao_midias e limpa as
// temporárias. Mídia órfã (o cliente desistiu) fica no temp e é lixo conhecido.
//
// Existe como service porque web e app fazem o MESMO upload. A regra de limite
// (5 por avaliação, 5 MB por imagem, 30 MB por vídeo) precisa valer nos dois —
// duplicá-la seria garantir que um dia divergem.

final class AvaliacaoMidiaService
{
    public const MAX_POR_AVALIACAO = 5;

    private const MAX_IMAGEM = 5  * 1024 * 1024;
    private const MAX_VIDEO  = 30 * 1024 * 1024;

    private const EXT_IMAGEM = ['jpg', 'jpeg', 'png', 'webp'];
    private const EXT_VIDEO  = ['mp4', 'webm', 'mov'];

    private const MIME_IMAGEM = ['image/jpeg', 'image/png', 'image/webp'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function novoToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function pasta(): string
    {
        return rtrim(UPLOAD_PATH, '/\\') . '/avaliacoes/';
    }

    public function contar(string $token): int
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM avaliacao_midias_temp WHERE token = ?");
        $st->execute([$token]);
        return (int)$st->fetchColumn();
    }

    /**
     * Recebe um item de $_FILES e guarda como mídia temporária.
     *
     * @param array $arquivo Item de $_FILES
     * @return array{ok:bool,erro?:string,midia?:array{token:string,arquivo:string,thumb:?string,tipo:string}}
     */
    public function guardar(array $arquivo, string $token, ?string $ip): array
    {
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->erro('Nenhum arquivo recebido.');
        }

        $token = $token !== '' ? $token : self::novoToken();

        // O limite vale no servidor, não só no JS: sem ele, chamadas repetidas
        // acumulam dezenas de arquivos sob o mesmo token.
        if ($this->contar($token) >= self::MAX_POR_AVALIACAO) {
            return $this->erro('Limite de ' . self::MAX_POR_AVALIACAO . ' fotos ou vídeos por avaliação.');
        }

        $ext = strtolower(pathinfo((string)($arquivo['name'] ?? ''), PATHINFO_EXTENSION));

        $ehImagem = in_array($ext, self::EXT_IMAGEM, true);
        $ehVideo  = in_array($ext, self::EXT_VIDEO,  true);

        if (!$ehImagem && !$ehVideo) {
            return $this->erro('Envie imagem (JPG, PNG, WEBP) ou vídeo (MP4, WEBM, MOV).');
        }

        $limite = $ehImagem ? self::MAX_IMAGEM : self::MAX_VIDEO;
        if ((int)($arquivo['size'] ?? 0) > $limite) {
            return $this->erro($ehImagem
                ? 'A imagem excede 5 MB.'
                : 'O vídeo excede 30 MB.');
        }

        $tmp = (string)($arquivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return $this->erro('Envio inválido.');
        }

        // A extensão é dado do cliente e não prova nada. Para imagem dá para
        // conferir o tipo real de graça — e é justamente a imagem que depois
        // passa pelo GD, que quebraria feio com um arquivo que não é imagem.
        if ($ehImagem) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $tmp) : null;
            if ($finfo) finfo_close($finfo);

            if (!in_array($mime, self::MIME_IMAGEM, true)) {
                return $this->erro('O arquivo não é uma imagem válida.');
            }
        }

        $dir = self::pasta();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->erro('Não foi possível preparar o envio.');
        }

        $hash = bin2hex(random_bytes(8));
        $nome = 'rev_' . $hash . '.' . $ext;

        if (!move_uploaded_file($tmp, $dir . $nome)) {
            return $this->erro('Não foi possível salvar o arquivo.');
        }

        $thumb = $ehImagem
            ? $this->thumbImagem($dir . $nome, $dir, $hash, $ext)
            : $this->thumbVideo($dir . $nome, $dir, $hash);

        $tipo = $ehImagem ? 'imagem' : 'video';

        $this->db->prepare(
            "INSERT INTO avaliacao_midias_temp (token, tipo, arquivo, thumb, ip)
             VALUES (?,?,?,?,?)"
        )->execute([$token, $tipo, $nome, $thumb, $ip]);

        return [
            'ok'    => true,
            'midia' => [
                'token'   => $token,
                'arquivo' => $nome,
                'thumb'   => $thumb,
                'tipo'    => $tipo,
            ],
        ];
    }

    /**
     * Apaga uma mídia temporária de verdade — arquivo e linha.
     *
     * Sem isto, tirar a foto da pré-visualização só some com ela na tela:
     * vincularMidias() busca TODAS as linhas do token e anexaria a foto
     * "removida" mesmo assim.
     *
     * Idempotente: se a linha não existe, o estado desejado já é real.
     *
     * @param string|null $ip     Escopo de posse do visitante anônimo.
     * @param int|null $clienteId Quando informado, dispensa o IP: o dono é
     *                            conhecido por outro caminho (token do app).
     */
    public function remover(string $token, string $arquivo, ?string $ip, ?int $clienteId = null): bool
    {
        if ($token === '' || $arquivo === '') {
            return false;
        }

        // Nome sempre gerado por guardar() — nunca aceite um caminho do cliente,
        // ou "../../config/.env" vira alvo do unlink abaixo.
        if (!preg_match('/^rev_[0-9a-f]{16}\.[a-z0-9]{2,5}$/i', $arquivo)) {
            return false;
        }

        if ($clienteId !== null) {
            $st = $this->db->prepare(
                "SELECT id, arquivo, thumb FROM avaliacao_midias_temp
                 WHERE token = ? AND arquivo = ? LIMIT 1"
            );
            $st->execute([$token, $arquivo]);
        } else {
            $st = $this->db->prepare(
                "SELECT id, arquivo, thumb FROM avaliacao_midias_temp
                 WHERE token = ? AND arquivo = ? AND ip = ? LIMIT 1"
            );
            $st->execute([$token, $arquivo, (string)$ip]);
        }

        $row = $st->fetch();
        if (!$row) {
            return true;
        }

        $dir = self::pasta();
        if (!empty($row['arquivo'])) @unlink($dir . $row['arquivo']);
        if (!empty($row['thumb']))   @unlink($dir . $row['thumb']);

        $this->db->prepare("DELETE FROM avaliacao_midias_temp WHERE id = ?")
                 ->execute([$row['id']]);

        return true;
    }

    /** @return array<int,array> Linhas temporárias de um token, na ordem de envio. */
    public function doToken(string $token): array
    {
        $st = $this->db->prepare(
            "SELECT id, tipo, arquivo, thumb FROM avaliacao_midias_temp
             WHERE token = ? ORDER BY id ASC"
        );
        $st->execute([$token]);
        return $st->fetchAll();
    }

    /* ================================================================= */

    /** @return array{ok:false,erro:string} */
    private function erro(string $msg): array
    {
        return ['ok' => false, 'erro' => $msg];
    }

    private function thumbImagem(string $origem, string $dir, string $hash, string $ext): ?string
    {
        if (!function_exists('imagewebp')) {
            return null;
        }

        try {
            $img = match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($origem),
                'png'         => @imagecreatefrompng($origem),
                'webp'        => @imagecreatefromwebp($origem),
                default       => null,
            };
            if (!$img) return null;

            $w   = imagesx($img);
            $h   = imagesy($img);
            $max = 400;

            if ($w > $max || $h > $max) {
                $r   = min($max / $w, $max / $h);
                $nw  = max(1, (int)($w * $r));
                $nh  = max(1, (int)($h * $r));
                $dst = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $dst;
            }

            $thumb = 'th_' . $hash . '.webp';
            imagewebp($img, $dir . $thumb, 80);
            imagedestroy($img);

            return $thumb;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Um frame do vídeo como capa. Depende de ffmpeg no PATH; sem ele o
     * retorno é null e o app desenha o ícone de play sobre fundo neutro —
     * nunca uma imagem quebrada.
     */
    private function thumbVideo(string $video, string $dir, string $hash): ?string
    {
        if (!file_exists($video)) return null;

        $saida  = [];
        $codigo = 1;
        @exec('ffmpeg -version 2>&1', $saida, $codigo);
        if ($codigo !== 0) {
            return null;
        }

        $ext     = function_exists('imagewebp') ? 'webp' : 'jpg';
        $thumb   = 'th_' . $hash . '.' . $ext;
        $caminho = $dir . $thumb;

        $filtro = 'scale=400:400:force_original_aspect_ratio=decrease,'
                . 'pad=400:400:(400-iw)/2:(400-ih)/2,setsar=1';

        // Segundo 1 representa melhor o conteúdo que o frame 0 (que costuma
        // ser preto). Vídeo mais curto que isso cai no fallback sem seek.
        foreach (['-ss 00:00:01 ', ''] as $seek) {
            @exec(sprintf(
                'ffmpeg -y %s-i %s -vframes 1 -q:v 2 -vf "%s" %s 2>&1',
                $seek,
                escapeshellarg($video),
                $filtro,
                escapeshellarg($caminho)
            ));

            if (file_exists($caminho) && filesize($caminho) > 100) {
                return $thumb;
            }
        }

        return null;
    }
}
