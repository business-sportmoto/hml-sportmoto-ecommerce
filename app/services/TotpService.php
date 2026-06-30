<?php
declare(strict_types=1);

/**
 * TotpService — implementação nativa de TOTP (RFC 6238), compatível com
 * Google Authenticator, Authy, Microsoft Authenticator, 1Password, etc.
 *
 * Por que implementação própria em vez de lib via Composer: o projeto
 * não usa Composer hoje, e o algoritmo é curto e bem padronizado — não
 * há "segredo" de implementação, é HMAC-SHA1 + contagem de janelas de
 * tempo. Mantém zero dependências externas.
 *
 * Este serviço é SEPARADO do TwoFactorService (e-mail/WhatsApp/SMS):
 * usuarios.totp_secret é um segredo PERMANENTE (gerado uma vez no setup),
 * diferente de usuarios.dois_fatores_segredo (código temporário de e-mail).
 */
class TotpService {

    private PDO $db;

    /** Janela de tempo padrão do protocolo (30s) — não mexer, é o que
     *  todos os apps autenticadores (Google/Authy/Microsoft) esperam. */
    private const PERIODO = 30;

    /** Código de 6 dígitos — padrão universal dos apps autenticadores. */
    private const DIGITOS = 6;

    /** Tolerância de ±1 janela (30s antes/depois) para absorver pequena
     *  diferença de relógio entre servidor e celular do usuário. */
    private const JANELAS_TOLERANCIA = 1;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Gera um novo segredo TOTP em Base32 (formato exigido pelo padrão
     * para ser legível/escaneável pelos apps). 160 bits de entropia
     * (20 bytes) — acima do mínimo recomendado pela RFC.
     */
    public function gerarSegredo(): string {
        $bytes = random_bytes(20);
        return $this->base32Encode($bytes);
    }

    /**
     * Monta a URI otpauth:// que o QR code deve codificar.
     * $conta normalmente é o e-mail do usuário (aparece no app abaixo
     * do nome do site, ajuda o usuário a identificar qual conta é).
     */
    public function gerarUri(string $segredo, string $conta, string $emissor = null): string {
        $emissor = $emissor ?? ConfigHelper::get('site_nome', 'SportMoto');
        $label   = rawurlencode("{$emissor}:{$conta}");
        $params  = http_build_query([
            'secret' => $segredo,
            'issuer' => $emissor,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITOS,
            'period' => self::PERIODO,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Gera o código TOTP de 6 dígitos para o instante atual.
     * Útil para testes/debug — o fluxo real usa validarCodigo().
     */
    public function gerarCodigoAtual(string $segredo): string {
        return $this->calcularCodigo($segredo, $this->janelaAtual());
    }

    /**
     * Valida um código digitado pelo usuário contra o segredo armazenado.
     * Aceita a janela atual e ±1 janela adjacente (tolerância de relógio).
     */
    public function validarCodigo(string $segredo, string $codigoDigitado): bool {
        $codigoDigitado = preg_replace('/\D/', '', $codigoDigitado);
        if (strlen($codigoDigitado) !== self::DIGITOS) return false;

        $janelaAtual = $this->janelaAtual();

        for ($i = -self::JANELAS_TOLERANCIA; $i <= self::JANELAS_TOLERANCIA; $i++) {
            $codigoEsperado = $this->calcularCodigo($segredo, $janelaAtual + $i);
            if (hash_equals($codigoEsperado, $codigoDigitado)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ativa o TOTP para o usuário — chamado depois que o setup confirma
     * o primeiro código com sucesso (prova que o app foi configurado
     * corretamente antes de travar a conta nisso).
     */
    public function ativar(int $usuarioId, string $segredo): void {
        $stmt = $this->db->prepare(
            "UPDATE usuarios
             SET totp_secret = ?, totp_ativo = 1, totp_confirmado_em = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$segredo, $usuarioId]);
    }

    public function desativar(int $usuarioId): void {
        $stmt = $this->db->prepare(
            "UPDATE usuarios
             SET totp_secret = NULL, totp_ativo = 0, totp_confirmado_em = NULL
             WHERE id = ?"
        );
        $stmt->execute([$usuarioId]);

        // Invalida todos os códigos de backup ao desativar — evita que
        // um código antigo continue valendo para um setup futuro novo.
        $this->db->prepare(
            "DELETE FROM totp_backup_codes WHERE usuario_id = ?"
        )->execute([$usuarioId]);
    }

    public function isAtivo(int $usuarioId): bool {
        $stmt = $this->db->prepare("SELECT totp_ativo FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        return (bool)$stmt->fetchColumn();
    }

    public function getSegredo(int $usuarioId): ?string {
        $stmt = $this->db->prepare("SELECT totp_secret FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Gera N códigos de backup de uso único, salva os HASHES no banco
     * (nunca o texto puro — mesmo padrão de senha) e retorna os códigos
     * em texto puro UMA VEZ para o controller exibir ao usuário.
     * Chamadas subsequentes substituem os códigos antigos (regeneração).
     */
    public function gerarCodigosBackup(int $usuarioId, int $quantidade = 8): array {
        // Remove códigos antigos antes de gerar novos — evita acúmulo
        // e garante que só o lote mais recente seja válido.
        $this->db->prepare(
            "DELETE FROM totp_backup_codes WHERE usuario_id = ?"
        )->execute([$usuarioId]);

        $stmt = $this->db->prepare(
            "INSERT INTO totp_backup_codes (usuario_id, codigo_hash, criado_em)
             VALUES (?, ?, NOW())"
        );

        $codigos = [];
        for ($i = 0; $i < $quantidade; $i++) {
            // Formato XXXX-XXXX (legível, fácil de digitar/anotar)
            $codigo = $this->gerarCodigoBackupLegivel();
            $codigos[] = $codigo;
            $stmt->execute([$usuarioId, password_hash($codigo, PASSWORD_DEFAULT)]);
        }
        return $codigos;
    }

    /**
     * Valida e CONSOME um código de backup (uso único — marca como
     * usado imediatamente, não pode ser reaproveitado).
     */
    public function validarCodigoBackup(int $usuarioId, string $codigoDigitado): bool {
        $codigoDigitado = trim($codigoDigitado);

        $stmt = $this->db->prepare(
            "SELECT id, codigo_hash FROM totp_backup_codes
             WHERE usuario_id = ? AND usado_em IS NULL"
        );
        $stmt->execute([$usuarioId]);

        foreach ($stmt->fetchAll() as $row) {
            if (password_verify($codigoDigitado, $row['codigo_hash'])) {
                // Consome imediatamente — uso único
                $this->db->prepare(
                    "UPDATE totp_backup_codes SET usado_em = NOW() WHERE id = ?"
                )->execute([(int)$row['id']]);
                return true;
            }
        }
        return false;
    }

    public function contarCodigosBackupRestantes(int $usuarioId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM totp_backup_codes
             WHERE usuario_id = ? AND usado_em IS NULL"
        );
        $stmt->execute([$usuarioId]);
        return (int)$stmt->fetchColumn();
    }

    // ── Internos: algoritmo TOTP (RFC 6238) ────────────────────────

    private function janelaAtual(): int {
        return intdiv(time(), self::PERIODO);
    }

    /**
     * Calcula o código TOTP para uma janela de tempo específica.
     * HOTP (RFC 4226) aplicado sobre a janela de tempo em vez de um
     * contador incremental — é exatamente isso que define "TOTP".
     */
    private function calcularCodigo(string $segredoBase32, int $janela): string {
        $segredoBinario = $this->base32Decode($segredoBase32);

        // Contador de 8 bytes, big-endian (especificação RFC 4226)
        $contador = pack('N*', 0, $janela);

        $hash = hash_hmac('sha1', $contador, $segredoBinario, true);

        // Truncamento dinâmico (RFC 4226 §5.3)
        $offset = ord($hash[19]) & 0x0F;
        $parteRelevante =
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
            (ord($hash[$offset + 3])  & 0xFF);

        $codigo = $parteRelevante % (10 ** self::DIGITOS);
        return str_pad((string)$codigo, self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private function gerarCodigoBackupLegivel(): string {
        // 8 caracteres alfanuméricos maiúsculos, formato XXXX-XXXX
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O/1/I (evita confusão visual)
        $codigo = '';
        for ($i = 0; $i < 8; $i++) {
            $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        return substr($codigo, 0, 4) . '-' . substr($codigo, 4, 4);
    }

    private function base32Encode(string $dados): string {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binario  = '';
        foreach (str_split($dados) as $byte) {
            $binario .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $resultado = '';
        foreach (str_split($binario, 5) as $grupo) {
            if (strlen($grupo) < 5) {
                $grupo = str_pad($grupo, 5, '0', STR_PAD_RIGHT);
            }
            $resultado .= $alfabeto[bindec($grupo)];
        }
        return $resultado;
    }

    private function base32Decode(string $segredo): string {
        $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $segredo  = strtoupper(rtrim($segredo, '='));

        $binario = '';
        foreach (str_split($segredo) as $char) {
            $pos = strpos($alfabeto, $char);
            if ($pos === false) continue; // ignora caracteres inválidos
            $binario .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($binario, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }
}