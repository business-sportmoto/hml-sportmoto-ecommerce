<?php
/**
 * app/services/email/EmailProviderService.php
 *
 * Responsável por:
 *  - Criptografar/descriptografar credenciais de provedores.
 *  - Instanciar o adapter correto (factory).
 *  - Carregar provedor padrão.
 */
class EmailProviderService
{
    /** @var EmailProvider */
    private $model;
    /** @var string */
    private $key;

    public function __construct()
    {
        $this->model = new EmailProvider();
        $cfg = require dirname(__DIR__, 2) . '/../config/email-marketing.php';
        $this->key = (string)($cfg['encryption_key'] ?? '');
    }

    /** Cria o adapter já com credenciais decifradas */
    public function build($idOrConfig)
    {
        $config = is_array($idOrConfig) ? $idOrConfig : $this->model->find((int)$idOrConfig);
        if (!$config) {
            throw new RuntimeException('Provedor de email não encontrado');
        }
        $config['credenciais_decoded'] = $this->decryptCreds($config['credenciais'] ?? '');

        switch ($config['tipo']) {
            case 'ses':      return new AwsSesEmailProvider($config);
            case 'mailgun':  return new MailgunEmailProvider($config);
            case 'sendgrid': return new SendGridEmailProvider($config);
            case 'brevo':    return new BrevoEmailProvider($config);
            case 'smtp':     return new SmtpEmailProvider($config);
            default:
                throw new RuntimeException('Tipo de provedor desconhecido: ' . $config['tipo']);
        }
    }

    public function buildPadrao()
    {
        $p = $this->model->padrao();
        if (!$p) throw new RuntimeException('Nenhum provedor padrão configurado');
        return $this->build($p);
    }

    public function getConfig($id)
    {
        $c = $this->model->find((int)$id);
        if (!$c) return null;
        $c['credenciais_decoded'] = $this->decryptCreds($c['credenciais'] ?? '');
        return $c;
    }

    /** Criptografa um array de credenciais e devolve string opaca */
    public function encryptCreds(array $creds)
    {
        if (!$this->key) {
            throw new RuntimeException('EMAIL_MARKETING_KEY não definida em config');
        }
        $iv  = random_bytes(16);
        $key = $this->keyBytes();
        $enc = openssl_encrypt(json_encode($creds, JSON_UNESCAPED_UNICODE),
            'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($enc === false) {
            throw new RuntimeException('Falha ao criptografar credenciais');
        }
        $mac = hash_hmac('sha256', $iv . $enc, $key, true);
        return 'enc1:' . base64_encode($iv . $mac . $enc);
    }

    /** Descriptografa as credenciais armazenadas em campo `credenciais` */
    public function decryptCreds($value)
    {
        $value = (string)$value;
        if ($value === '') return [];
        if (!$this->key) return [];

        if (strpos($value, 'enc1:') !== 0) {
            // legado/plaintext (não recomendado)
            $j = json_decode($value, true);
            return is_array($j) ? $j : [];
        }
        $raw = base64_decode(substr($value, 5), true);
        if ($raw === false || strlen($raw) < 16 + 32 + 1) return [];

        $iv  = substr($raw, 0, 16);
        $mac = substr($raw, 16, 32);
        $enc = substr($raw, 48);

        $key = $this->keyBytes();
        $expected = hash_hmac('sha256', $iv . $enc, $key, true);
        if (!hash_equals($expected, $mac)) {
            return [];
        }
        $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($dec === false) return [];
        $j = json_decode($dec, true);
        return is_array($j) ? $j : [];
    }

    private function keyBytes()
    {
        $k = $this->key;
        // Aceita key em hex de 64 chars; senão hash da string p/ ter 32 bytes
        if (preg_match('/^[a-f0-9]{64}$/i', $k)) {
            return hex2bin($k);
        }
        return hash('sha256', $k, true);
    }
}
