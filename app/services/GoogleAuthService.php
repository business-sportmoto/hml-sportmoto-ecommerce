<?php
declare(strict_types=1);
 
// ════════════════════════════════════════════════════════
// app/services/GoogleAuthService.php — v2
// Adaptado para usuarios + clientes
// ════════════════════════════════════════════════════════
class GoogleAuthService {
 
    public const PROVIDER = 'google';
 
    private \Google_Client $client;
    private PDO $db;
 
    public function __construct() {
        $this->client = new \Google_Client(['client_id' => GOOGLE_CLIENT_ID]);
        $this->db     = Database::getInstance()->getConnection();
    }
 
    /**
     * Valida ID Token do GIS.
     * Verifica: assinatura, iss, aud, exp, email_verified, sub.
     */
    public function validarToken(string $idToken): array {
        $payload = $this->client->verifyIdToken($idToken);
        if (!$payload) {
            throw new \RuntimeException('Token Google inválido ou expirado.');
        }
        if (empty($payload['email_verified'])) {
            throw new \RuntimeException('E-mail não verificado pelo Google.');
        }
        $issValidos = ['accounts.google.com', 'https://accounts.google.com'];
        if (!in_array($payload['iss'] ?? '', $issValidos, true)) {
            throw new \RuntimeException('Issuer inválido.');
        }
        if (empty($payload['sub'])) {
            throw new \RuntimeException('Token sem identificador (sub).');
        }
        return $payload;
    }
 
    /**
     * Avalia cenário para o sub recebido.
     *
     * Cenários:
     *  - 'login_direto'   → já existe social_account com este sub → autentica
     *  - 'criar_conta'    → sub não existe em nenhum lugar → pedir CPF/tel → criar
     *
     * NOTA: Não fazemos mais merge automático por e-mail.
     * Se o usuário quer conectar Google à conta existente,
     * ele faz isso em /minha-conta/sessoes (logado).
     */
    public function avaliarCenario(array $payload): array {
        $sub = $payload['sub'];
 
        $stmt = $this->db->prepare(
            "SELECT sa.cliente_id, u.ativo, u.email
             FROM social_accounts sa
             JOIN usuarios u ON u.id = sa.cliente_id
             WHERE sa.provider = ? AND sa.provider_id = ?
             LIMIT 1"
        );
        $stmt->execute([self::PROVIDER, $sub]);
        $vinculo = $stmt->fetch();
 
        if ($vinculo) {
            if (!$vinculo['ativo']) {
                throw new \RuntimeException('Conta inativa. Contate o suporte.');
            }
            return [
                'cenario'    => 'login_direto',
                'usuario_id' => (int)$vinculo['cliente_id'],
            ];
        }
 
        return ['cenario' => 'criar_conta'];
    }
 
    /**
     * Cria usuario + cliente + social_accounts em uma transaction.
     * Senha aleatória impossível de adivinhar (conta social).
     *
     * @param array $payload Payload validado do Google
     * @param array $extra   ['cpf' => string, 'telefone' => string]
     */
    public function criarConta(array $payload, array $extra = []): int {
        $email    = mb_strtolower($payload['email']);
        $nome     = $payload['name'] ?? ($payload['given_name'] ?? 'Usuário');
        $cpf      = preg_replace('/\D/', '', $extra['cpf']     ?? '');
        $telefone = preg_replace('/\D/', '', $extra['telefone'] ?? '');
 
        $this->db->beginTransaction();
        try {
            // 1. Cria usuario (sem senha real, senha_definida=0)
            $this->db->prepare(
                "INSERT INTO usuarios
                 (nome, email, senha_hash, tipo, email_verificado,
                  senha_definida, ativo, criado_em)
                 VALUES (?,?,?,?,1,0,1,NOW())"
            )->execute([
                $nome, $email,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'cliente',
            ]);
            $usuarioId = (int)$this->db->lastInsertId();
 
            // 2. Cria cliente
            $this->db->prepare(
                "INSERT INTO clientes (usuario_id, cpf, celular, avatar_url, criado_em)
                 VALUES (?,?,?,?,NOW())"
            )->execute([
                $usuarioId,
                $cpf      ?: null,
                $telefone ?: null,
                $payload['picture'] ?? null,
            ]);
            $clienteId = (int)$this->db->lastInsertId();
 
            // 3. Cria wishlist padrão
            $this->db->prepare(
                "INSERT INTO wishlist (cliente_id, nome, padrao, criado_em)
                 VALUES (?, 'Meus favoritos', 1, NOW())"
            )->execute([$clienteId]);
 
            // 4. Vincula social
            $this->vincular($usuarioId, $payload);
 
            // 5. Avatar local (salva URL do Google em clientes.avatar_url)
            if (!empty($payload['picture'])) {
                $this->atualizarAvatar($usuarioId, $payload['picture']);
            }
 
            $this->db->commit();
            return $usuarioId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
 
    /**
     * Cria/atualiza registro em social_accounts.
     * Pode ser chamado para contas existentes (link manual pelo painel).
     */
    public function vincular(int $usuarioId, array $payload): void {
        $this->db->prepare(
            "INSERT INTO social_accounts
             (cliente_id, provider, provider_id, provider_email, nome_provider, avatar_url)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                provider_email = VALUES(provider_email),
                nome_provider  = VALUES(nome_provider),
                avatar_url     = VALUES(avatar_url),
                atualizado_em  = NOW()"
        )->execute([
            $usuarioId,
            self::PROVIDER,
            $payload['sub'],
            mb_strtolower($payload['email']),
            $payload['name']    ?? null,
            $payload['picture'] ?? null,
        ]);
    }
 
    /**
     * Remove vínculo. Bloqueia se for o único método de acesso.
     */
    public function desvincular(int $usuarioId): void {
        $stmt = $this->db->prepare(
            "SELECT senha_definida FROM usuarios WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        $temSenha = (int)$stmt->fetchColumn();
 
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM social_accounts WHERE usuario_id = ?"
        );
        $stmt->execute([$usuarioId]);
        $totalVinculos = (int)$stmt->fetchColumn();
 
        if (!$temSenha && $totalVinculos <= 1) {
            throw new \RuntimeException(
                'Defina uma senha local antes de desvincular o Google, pois é o único método de acesso.'
            );
        }
 
        $this->db->prepare(
            "DELETE FROM social_accounts WHERE usuario_id = ? AND provider = ?"
        )->execute([$usuarioId, self::PROVIDER]);
    }
 
    /**
     * Lista contas sociais vinculadas ao usuário.
     */
    public function listarVinculos(int $usuarioId): array {
        $stmt = $this->db->prepare(
            "SELECT provider, provider_email, nome_provider, avatar_url, criado_em
             FROM social_accounts WHERE cliente_id = ? ORDER BY criado_em ASC"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchAll();
    }
 
    /**
     * Baixa o avatar do Google, processa (crop 1:1, resize 200px) e
     * salva localmente em uploads/avatars/.
     * Atualiza clientes.avatar com o nome do arquivo local.
     * So executa se o cliente ainda nao tiver avatar local.
     */
    public function baixarESalvarAvatar(int $usuarioId, string $url): void {
        if (empty($url)) return;
 
        $stmt = $this->db->prepare(
            "SELECT avatar FROM clientes WHERE usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        if (!empty($stmt->fetchColumn())) return;
 
        try {
            $urlAlta = preg_replace('/=s\d+$/', '', rtrim($url, '/')) . '=s400';
 
            $ch = curl_init($urlAlta);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; EcommerceBot/1.0)',
            ]);
            $dados    = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $mime     = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
 
            if (!$dados || $httpCode !== 200) {
                throw new \RuntimeException("Download falhou. HTTP {$httpCode}");
            }
 
            $mimeBase = strtolower(explode(';', $mime)[0]);
            if (!in_array($mimeBase, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
                throw new \RuntimeException("Tipo invalido: {$mimeBase}");
            }
 
            $origem = imagecreatefromstring($dados);
            if ($origem === false) throw new \RuntimeException('GD falhou.');
 
            $larg  = imagesx($origem);
            $alt   = imagesy($origem);
            $lado  = min($larg, $alt);
            $xOff  = (int)(($larg - $lado) / 2);
            $yOff  = (int)(($alt  - $lado) / 2);
            $size  = 200;
 
            $canvas = imagecreatetruecolor($size, $size);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $origem, 0, 0, $xOff, $yOff, $size, $size, $lado, $lado);
            imagedestroy($origem);
 
            $dir = rtrim(UPLOAD_PATH, '/') . '/avatars/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
 
            $base    = 'google_' . $usuarioId . '_' . time();
            $arquivo = function_exists('imagewebp') ? $base . '.webp' : $base . '.jpg';
            function_exists('imagewebp')
                ? imagewebp($canvas, $dir . $arquivo, 88)
                : imagejpeg($canvas, $dir . $arquivo, 90);
            imagedestroy($canvas);
 
            if (!file_exists($dir . $arquivo) || filesize($dir . $arquivo) < 100) {
                throw new \RuntimeException('Arquivo corrompido.');
            }
 
            $this->db->prepare(
                "UPDATE clientes SET avatar = ? WHERE usuario_id = ?"
            )->execute([$arquivo, $usuarioId]);
 
        } catch (\Throwable $e) {
            error_log("[GoogleAuth] baixarESalvarAvatar erro usuario {$usuarioId}: " . $e->getMessage());
        }
    }
 
    /** @deprecated use baixarESalvarAvatar() */
    public function atualizarAvatar(int $usuarioId, string $url): void {
        $this->baixarESalvarAvatar($usuarioId, $url);
    }
 
    /**
     * Busca usuário completo (usuario + cliente) pelo usuario_id.
     */
    public function buscarUsuario(int $usuarioId): ?array {
        $stmt = $this->db->prepare(
            "SELECT u.*, c.id AS cliente_id, c.cpf, c.telefone
             FROM usuarios u
             JOIN clientes c ON c.usuario_id = u.id
             WHERE u.id = ? AND u.ativo = 1 LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetch() ?: null;
    }
}