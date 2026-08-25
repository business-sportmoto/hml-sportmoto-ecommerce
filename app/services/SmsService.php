<?php
/**
 * app/services/SmsService.php
 *
 * Fachada de envio de SMS. Mesma divisão de responsabilidades da camada
 * de e-mail: os adapters em app/services/sms/providers/ só sabem falar
 * com a API do gateway; tudo que é política do projeto mora aqui —
 * normalização de telefone, montagem da mensagem, deduplicação, log e a
 * garantia de nunca lançar exceção para fora.
 *
 * Trocar de gateway = escrever um adapter novo e mudar SMS_DRIVER.
 * Nada em AuthController precisa saber quem entrega a mensagem.
 *
 * CONFIGURAÇÃO (.env):
 *   SMS_DRIVER       comtele | log        (padrão: log em dev, comtele fora)
 *   COMTELE_API_KEY  chave da conta Comtele
 *   COMTELE_SENDER   remetente homologado (opcional)
 *   COMTELE_TIMEOUT  segundos (opcional, padrão 12)
 *
 * USO:
 *   SmsService::sendCodigo($celular, $codigo, 10, ['cliente_id' => 42]);
 */
class SmsService
{
    /** Janela anti-duplicação, em segundos. Igual à do WhatsappService. */
    private const DEDUP_JANELA_SEG = 120;

    /** Limite de um segmento GSM-7. Acima disso o gateway cobra dobrado. */
    private const LIMITE_SEGMENTO = 160;

    private static ?SmsProviderInterface $provider = null;

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /**
     * Envia o código de verificação (2FA / confirmação de login).
     * Retorna bool — nunca lança. Um SMS que não sai não pode derrubar
     * o login: quem chama decide se oferece outro canal.
     *
     * @param string $telefone    Como está no cadastro; é normalizado aqui.
     * @param string $codigo      Código numérico já gerado.
     * @param int    $validadeMin Validade em minutos, para o texto.
     * @param array  $opts        cliente_id
     */
    public static function sendCodigo(
        string $telefone,
        string $codigo,
        int    $validadeMin = 10,
        array  $opts = []
    ): bool {
        // Texto curto e sem acento de propósito: cabe em um único
        // segmento GSM-7 (160 chars). Com acento o gateway comuta para
        // UCS-2, o limite cai para 70 e o mesmo SMS passa a custar 2.
        $mensagem = sprintf(
            '%s e seu codigo de verificacao %s. Valido por %d minutos. Nao compartilhe com ninguem.',
            $codigo,
            self::nomeLoja(),
            $validadeMin
        );

        // dedup_chave usa o telefone, NUNCA o código: dois pedidos
        // seguidos geram códigos diferentes e escapariam da janela.
        $opts['dedup_chave'] = 'codigo_verificacao:' . $telefone;

        // O código é segredo de acesso: o que vai para o canal_log é uma
        // versão mascarada. Ver §5 do CLAUDE.md.
        $opts['preview'] = preg_replace('/\b\d{4,8}\b/', '******', $mensagem);

        return self::send($telefone, 'codigo_verificacao', $mensagem, $opts);
    }

    /**
     * Envio genérico. Sempre retorna bool, nunca lança.
     *
     * @param array $opts cliente_id, pedido_id, dedup (bool), dedup_chave,
     *                    preview (texto alternativo para o log)
     */
    public static function send(string $telefone, string $tipo, string $mensagem, array $opts = []): bool
    {
        $clienteId = isset($opts['cliente_id']) ? (int) $opts['cliente_id'] : null;
        $preview   = (string) ($opts['preview'] ?? $mensagem);

        try {
            $telefone = trim($telefone);
            $mensagem = trim($mensagem);

            if ($telefone === '') {
                self::log($tipo, '', $preview, 'erro', 'telefone vazio', $clienteId, $opts);
                return false;
            }
            if ($mensagem === '') {
                self::log($tipo, $telefone, '', 'erro', 'mensagem vazia', $clienteId, $opts);
                return false;
            }

            $numero = self::normalizarTelefone($telefone);
            if ($numero === null) {
                self::log($tipo, $telefone, $preview, 'erro', 'telefone inválido', $clienteId, $opts);
                return false;
            }

            // Fixo não recebe SMS. A normalização aceita fixo porque é a
            // mesma regra do WhatsApp, mas aqui isso seria crédito gasto
            // numa mensagem que o cliente nunca vê — e ele ficaria olhando
            // para a tela de código esperando algo que não chega.
            if (!self::ehCelular($numero)) {
                self::log($tipo, $numero, $preview, 'erro', 'número não é celular', $clienteId, $opts);
                return false;
            }

            $provider = self::provider();
            if (!$provider->configurado()) {
                self::log($tipo, $numero, $preview, 'sem_canal', 'gateway não configurado', $clienteId, $opts);
                return false;
            }

            // ── Anti-duplicação ────────────────────────────────────
            // Cliente que clica duas vezes em "SMS" não pode gerar dois
            // envios cobrados. O rate limit do controller é por usuário;
            // este é por número, e pega também o reenvio automático.
            $dedupChave = (string) ($opts['dedup_chave'] ?? ($tipo . ':' . $numero));
            if (($opts['dedup'] ?? true) && self::jaEnviadoRecentemente($dedupChave)) {
                self::log($tipo, $numero, $preview, 'cancelado', 'duplicado na janela de '
                    . self::DEDUP_JANELA_SEG . 's', $clienteId, $opts, $dedupChave);
                // true: do ponto de vista do cliente a mensagem ESTÁ a
                // caminho — a anterior. Devolver false o mandaria para
                // uma tela de erro sem motivo.
                return true;
            }

            $texto = self::paraGsm7($mensagem);
            $res   = $provider->send($numero, $texto);

            self::log(
                $tipo,
                $numero,
                $preview,
                $res->success ? 'enviado' : 'erro',
                $res->success ? null : $res->error,
                $clienteId,
                $opts,
                $dedupChave,
                $res->messageId,
                $provider->nome()
            );

            if (!$res->success) {
                // [LOG] O cliente legítimo não recebeu o segundo fator.
                // NUNCA inclua a mensagem no contexto: ela contém o código.
                LogService::error('Falha no envio de SMS', [
                    'tipo'       => $tipo,
                    'cliente_id' => $clienteId,
                    'erro'       => $res->error,
                    'temporario' => $res->temporary,
                ], 'sms');
            }

            return $res->success;

        } catch (\Throwable $e) {
            // Blindagem final: nada aqui pode escapar para o fluxo de login.
            LogService::exception($e, 'error', 'sms', [
                'tipo'       => $tipo,
                'cliente_id' => $clienteId,
            ]);
            return false;
        }
    }

    /**
     * O canal SMS pode ser oferecido ao cliente?
     * Usado por AuthController::getCanais2FA() para não mostrar um botão
     * que só levaria a uma mensagem de erro.
     */
    public static function disponivel(): bool
    {
        try {
            return self::provider()->configurado();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Reseta o adapter em memória (workers de longa duração e testes). */
    public static function resetProvider(): void
    {
        self::$provider = null;
    }

    // =========================================================================
    // PROVEDOR
    // =========================================================================

    private static function provider(): SmsProviderInterface
    {
        if (self::$provider instanceof SmsProviderInterface) {
            return self::$provider;
        }

        $driver = strtolower(self::config('SMS_DRIVER'));

        // Sem driver explícito: dev simula, o resto envia de verdade.
        // Assim ninguém queima crédito rodando o login no localhost, e
        // esquecer a variável em produção não vira "SMS silencioso".
        if ($driver === '') {
            $driver = (defined('APP_ENV') && APP_ENV === 'development') ? 'log' : 'comtele';
        }

        self::$provider = match ($driver) {
            'log'     => new LogSmsProvider(),
            'comtele' => new ComteleSmsProvider(
                self::config('COMTELE_API_KEY'),
                self::config('COMTELE_SENDER'),
                (int) (self::config('COMTELE_TIMEOUT') ?: 12)
            ),
            default   => throw new RuntimeException("SMS_DRIVER desconhecido: {$driver}"),
        };

        return self::$provider;
    }

    private static function config(string $chave, string $default = ''): string
    {
        if (defined($chave)) {
            $v = constant($chave);
            if (is_string($v) && $v !== '') return $v;
        }
        $val = getenv($chave);
        if ($val !== false && $val !== '') return (string) $val;
        if (isset($_ENV[$chave])    && $_ENV[$chave]    !== '') return (string) $_ENV[$chave];
        if (isset($_SERVER[$chave]) && $_SERVER[$chave] !== '') return (string) $_SERVER[$chave];
        return $default;
    }

    private static function nomeLoja(): string
    {
        try {
            $nome = (string) ConfigHelper::get('site_nome', 'SportMoto');
        } catch (\Throwable) {
            $nome = 'SportMoto';
        }
        return self::paraGsm7($nome);
    }

    // =========================================================================
    // TELEFONE
    // =========================================================================

    /**
     * Normaliza para E.164 sem "+" (ex.: 5551999998888) ou null se o
     * número não for utilizável.
     *
     * As regras são as mesmas de DataCrazyService::normalizarTelefone()
     * — inclusive a correção do nono dígito. Ficam duplicadas de
     * propósito: o canal SMS não deve depender do adapter de WhatsApp
     * para funcionar. Se um terceiro canal precisar disto, aí vale
     * extrair para um helper compartilhado.
     */
    public static function normalizarTelefone(string $telefone): ?string
    {
        $d = preg_replace('/\D/', '', $telefone);
        if ($d === '' || strlen($d) < 8) return null;

        $d = ltrim($d, '0');

        // Já veio com DDI 55
        if (strlen($d) >= 12 && str_starts_with($d, '55')) {
            $ddd   = substr($d, 2, 2);
            $resto = substr($d, 4);
            if (!self::dddValido($ddd)) return null;
            return self::validarFinal('55' . $ddd . self::corrigirNono($resto));
        }

        // DDD + celular com o 9
        if (strlen($d) === 11) {
            $ddd   = substr($d, 0, 2);
            $resto = substr($d, 2);
            if (!self::dddValido($ddd)) return null;
            return self::validarFinal('55' . $ddd . $resto);
        }

        // DDD + 8 dígitos (celular antigo ou fixo)
        if (strlen($d) === 10) {
            $ddd   = substr($d, 0, 2);
            $resto = substr($d, 2);
            if (!self::dddValido($ddd)) return null;
            return self::validarFinal('55' . $ddd . self::corrigirNono($resto));
        }

        return null;
    }

    /**
     * O número normalizado é um celular brasileiro?
     * Celular no Brasil = 55 + DDD(2) + 9 dígitos começando em 9.
     * Fixo fica com 8 dígitos (total 12) e é recusado.
     */
    public static function ehCelular(string $numeroE164): bool
    {
        if (strlen($numeroE164) !== 13 || !str_starts_with($numeroE164, '55')) {
            return false;
        }
        return $numeroE164[4] === '9';
    }

    /** Celulares antigos de 8 dígitos ganharam o 9 na frente. */
    private static function corrigirNono(string $resto): string
    {
        if (strlen($resto) === 8 && in_array($resto[0], ['6', '7', '8', '9'], true)) {
            return '9' . $resto;
        }
        return $resto;
    }

    private static function dddValido(string $ddd): bool
    {
        $n = (int) $ddd;
        return $n >= 11 && $n <= 99;
    }

    private static function validarFinal(string $numero): ?string
    {
        $len = strlen($numero);
        return ($len >= 12 && $len <= 13) ? $numero : null;
    }

    // =========================================================================
    // TEXTO
    // =========================================================================

    /**
     * Reduz o texto ao alfabeto GSM-7.
     *
     * Um único caractere fora dele (um "ó", um travessão) faz o gateway
     * comutar a mensagem inteira para UCS-2: o segmento cai de 160 para
     * 70 caracteres e o SMS passa a custar o dobro. Para código de
     * verificação, legibilidade sem acento é um preço barato.
     */
    public static function paraGsm7(string $texto): string
    {
        // Mapa explícito primeiro. iconv//TRANSLIT depende da libc do
        // servidor: a mesma string vira "aca'i" no Windows e "acai" no
        // Linux. Para o texto que o cliente lê, o resultado não pode
        // depender de onde o código está rodando.
        static $mapa = [
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c','ñ'=>'n',
            'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
            'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
            'Ç'=>'C','Ñ'=>'N',
            '—'=>'-','–'=>'-','…'=>'...',
            '“'=>'"','”'=>'"','‘'=>"'",'’'=>"'",'º'=>'o','ª'=>'a',
        ];
        $texto = strtr($texto, $mapa);

        // Rede de segurança para o que o mapa não previu.
        $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if ($convertido !== false) {
            $texto = $convertido;
        }

        $texto = preg_replace('/[^\x20-\x7E\n]/', '', $texto) ?? $texto;
        $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;

        return trim(mb_substr($texto, 0, self::LIMITE_SEGMENTO));
    }

    // =========================================================================
    // LOG E DEDUPLICAÇÃO
    // =========================================================================

    /**
     * Já saiu um SMS com esta mesma chave dentro da janela?
     * Consulta o canal_log — a mesma fonte que o painel exibe, então não
     * há um segundo lugar da verdade para manter em sincronia.
     */
    private static function jaEnviadoRecentemente(string $chave): bool
    {
        try {
            $st = Database::getInstance()->getConnection()->prepare(
                "SELECT 1 FROM canal_log
                 WHERE canal = 'sms'
                   AND dedup_chave = ?
                   AND status = 'enviado'
                   AND criado_em >= (NOW() - INTERVAL ? SECOND)
                 LIMIT 1"
            );
            $st->execute([$chave, self::DEDUP_JANELA_SEG]);
            return (bool) $st->fetchColumn();
        } catch (\Throwable) {
            // Banco instável não pode impedir o segundo fator de sair.
            return false;
        }
    }

    private static function log(
        string  $tipo,
        string  $destinatario,
        string  $preview,
        string  $status,
        ?string $erro       = null,
        ?int    $clienteId  = null,
        array   $opts       = [],
        ?string $dedupChave = null,
        ?string $msgId      = null,
        ?string $via        = null
    ): void {
        try {
            LogService::debug('sms', [
                'cliente_id'      => $clienteId,
                'pedido_id'       => $opts['pedido_id'] ?? null,
                'destinatario'    => $destinatario,
                'preview'         => $preview,
                'status'          => $status,
                'provider_msg_id' => $msgId,
                'erro_detalhe'    => $erro,
                'dedup_chave'     => $dedupChave,
                'via'             => $via,
            ], $tipo);
        } catch (\Throwable) {
            // Log é observabilidade, não regra de negócio.
        }
    }
}
