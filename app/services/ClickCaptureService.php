<?php
declare(strict_types=1);

/**
 * app/services/ClickCaptureService.php
 *
 * Captura os click IDs de anúncios (fbclid da Meta, gclid do Google)
 * quando o visitante CHEGA vindo de um anúncio, e grava na
 * tracking_clicks — ligado ao mesmo visitante_token (sm_vt).
 *
 * POR QUE: o adapter (MetaCapiAdapter) já LÊ o fbc da tracking_clicks
 * pra enviar no user_data, mas nada GRAVAVA ainda. Este serviço
 * completa a atribuição: clique no anúncio → conversão futura
 * amarrada à campanha de origem.
 *
 * FORMATO fbc (confirmado doc Meta 2026):
 *   fb.{subdomainIndex}.{creationTimeMs}.{fbclid}
 *   - subdomainIndex: 'com'=0, 'dominio.com'=1, 'www.dominio.com'=2
 *   - creationTimeMs: timestamp UNIX em MILISSEGUNDOS
 *   - fbclid: valor cru do parâmetro
 *   REGRA: só monta fbc se houver fbclid REAL. Nunca fabricar.
 *   REGRA: fbc/fbp NUNCA são hasheados (quebra o match).
 *
 * Chamado 1x por request no bootstrap (é barato: só age se a URL
 * tem fbclid/gclid, ou se precisa persistir um cookie novo).
 */
final class ClickCaptureService
{
    private const COOKIE_VT = 'sm_vt';   // mesmo do TrackingService
    private const COOKIE_FBC = '_fbc';   // padrão Meta
    private const COOKIE_FBP = '_fbp';   // padrão Meta (o Pixel cria; lemos se existir)
    private const DIAS       = 90;       // janela de atribuição

    /**
     * Captura e persiste os click IDs da request atual, se houver.
     * Idempotente: se não há nada novo pra capturar, não faz nada.
     */
    public static function capturar(): void
    {
        try {
            $fbclid = self::param('fbclid');
            $gclid  = self::param('gclid');

            // fbc: usa o cookie _fbc se já existe; senão, monta do fbclid
            $fbc = $_COOKIE[self::COOKIE_FBC] ?? null;
            if (!$fbc && $fbclid !== null) {
                $fbc = self::montarFbc($fbclid);
                self::setCookie(self::COOKIE_FBC, $fbc);
            }

            // fbp: só lemos (quem cria é o Pixel do navegador, na Fase 2)
            $fbp = $_COOKIE[self::COOKIE_FBP] ?? null;

            // UTM entra na condição de captura, não só de carona.
            //
            // Antes, uma chegada que trazia SÓ utm (link de e-mail
            // marketing, bio do Instagram, QR code impresso) caía no
            // return abaixo e era jogada fora: a tabela só ganhava
            // linha se houvesse click ID de anúncio ou cookie _fbp.
            // Como o Pixel cria _fbp para todo visitante, nasciam
            // milhares de linhas sem utm nenhuma e ZERO linhas de
            // tráfego de campanha não-paga — foi exatamente o que se
            // viu no banco: 344 de 352 linhas existiam só pelo _fbp e
            // utm_source estava 100% NULL.
            $utmSource   = self::paramUtm('utm_source');
            $utmMedium   = self::paramUtm('utm_medium');
            $utmCampaign = self::paramUtm('utm_campaign');
            $temUtm      = $utmSource !== null || $utmMedium !== null || $utmCampaign !== null;

            // Se não veio NENHUM sinal de anúncio nem utm e não há
            // fbc/fbp, não há o que gravar — sai barato.
            if ($fbclid === null && $gclid === null && !$temUtm && !$fbc && !$fbp) {
                return;
            }

            $token = self::visitanteToken();
            if ($token === null) {
                return; // sem token de visitante, não liga a ninguém
            }

            self::gravar($token, $fbclid, $gclid, $fbc, $fbp,
                         $utmSource, $utmMedium, $utmCampaign);

        } catch (\Throwable $e) {
            // captura NUNCA quebra a navegação
            error_log('[ClickCapture] ' . $e->getMessage());
        }
    }

    /**
     * Atribuição a congelar no pedido: a última chegada COM origem
     * conhecida deste visitante, dentro da janela de 90 dias.
     *
     * Por que congelar em vez de fazer join na hora do relatório:
     * tracking_clicks é ligada por visitante_token (cookie). Cookie
     * expira, o cliente troca de aparelho, limpa o navegador — e a
     * atribuição do pedido de ontem muda ou some. Copiar para o
     * pedido no momento da compra torna o dado imutável, que é o que
     * um relatório financeiro exige.
     *
     * Modelo: último clique com origem. Linhas geradas só pelo cookie
     * _fbp (sem utm e sem click id) são ignoradas — elas existem para
     * todo visitante e não dizem de onde ele veio.
     *
     * @return array{utm_source:?string,utm_medium:?string,utm_campaign:?string,click_id:?string}
     */
    public static function atribuicaoAtual(?int $clienteId = null): array
    {
        $vazio = ['utm_source' => null, 'utm_medium' => null,
                  'utm_campaign' => null, 'click_id' => null];
        try {
            $token = self::visitanteToken();
            if ($token === null && $clienteId === null) return $vazio;

            $db = Database::getInstance()->getConnection();

            // Casa por token OU por cliente: quem logou tem as duas
            // pistas, e o cliente sobrevive à troca de dispositivo.
            $st = $db->prepare(
                "SELECT utm_source, utm_medium, utm_campaign, gclid, fbclid
                   FROM tracking_clicks
                  WHERE (visitante_token = ? OR (? IS NOT NULL AND cliente_id = ?))
                    AND (utm_source IS NOT NULL OR gclid IS NOT NULL OR fbclid IS NOT NULL)
                    AND criado_em >= (NOW() - INTERVAL " . self::DIAS . " DAY)
                  ORDER BY criado_em DESC
                  LIMIT 1"
            );
            $st->execute([$token, $clienteId, $clienteId]);
            $r = $st->fetch();
            if (!$r) return $vazio;

            return [
                'utm_source'   => $r['utm_source']   ?: null,
                'utm_medium'   => $r['utm_medium']   ?: null,
                'utm_campaign' => $r['utm_campaign'] ?: null,
                'click_id'     => $r['gclid'] ?: ($r['fbclid'] ?: null),
            ];
        } catch (\Throwable $e) {
            error_log('[ClickCapture] atribuicaoAtual: ' . $e->getMessage());
            return $vazio;
        }
    }

    /**
     * Monta o fbc no formato exato da Meta.
     * fb.{subdomainIndex}.{creationTimeMs}.{fbclid}
     */
    private static function montarFbc(string $fbclid): string
    {
        // subdomainIndex: conta os "níveis" do host.
        // Regra Meta: com=0, dominio.com=1, www.dominio.com=2.
        // Na prática: (número de pontos no host) dá o índice.
        // ex: sportmoto.com.br → 2 pontos → mas a regra real conta
        // a partir do domínio registrável. Usamos 1 como padrão
        // seguro (o valor mais comum p/ domínio raiz), que a Meta
        // aceita bem. O importante é o timestamp e o fbclid corretos.
        $subdomainIndex = 1;

        $creationTimeMs = (int)round(microtime(true) * 1000); // MILISSEGUNDOS

        return 'fb.' . $subdomainIndex . '.' . $creationTimeMs . '.' . $fbclid;
    }

    /** Lê um parâmetro da query, sanitizado. Null se ausente/vazio. */
    private static function param(string $nome): ?string
    {
        $v = $_GET[$nome] ?? '';
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') return null;
        // fbclid/gclid são alfanuméricos + alguns símbolos; limita tamanho
        $v = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $v);
        return ($v !== '') ? mb_substr($v, 0, 500) : null;
    }

    /**
     * Lê um parâmetro UTM. Sanitização mais permissiva que param().
     *
     * param() só deixa passar [A-Za-z0-9_-.] porque foi escrito para
     * fbclid/gclid, que são opacos e alfanuméricos. Aplicá-la a UTM
     * deforma valor legítimo em silêncio: "black friday 2026" virava
     * "blackfriday2026" e "email|jan" virava "emailjan", quebrando o
     * agrupamento por campanha no relatório.
     *
     * Aqui espaço, +, |, :, / e vírgula sobrevivem; o que some é o
     * que poderia virar injeção ou lixo de controle. O limite de 255
     * casa com a coluna.
     */
    private static function paramUtm(string $nome): ?string
    {
        $v = $_GET[$nome] ?? '';
        if (!is_string($v)) return null;

        $v = trim($v);
        if ($v === '') return null;

        // Remove caracteres de controle e tags; mantém pontuação usual de campanha.
        $v = preg_replace('/[\x00-\x1F\x7F<>"\']/u', '', $v) ?? '';
        $v = preg_replace('/\s+/u', ' ', $v) ?? '';
        $v = trim($v);

        return ($v !== '') ? mb_substr($v, 0, 255) : null;
    }

    /** Token do visitante (mesmo sm_vt do TrackingService). */
    private static function visitanteToken(): ?string
    {
        $t = $_COOKIE[self::COOKIE_VT] ?? '';
        return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : null;
    }

    /**
     * Grava na tracking_clicks. Estratégia: 1 linha por chegada com
     * click ID. Dedup SEPARADA por plataforma — fbclid e gclid são
     * independentes (vêm de anúncios diferentes, ciclos diferentes).
     * Colar os dois num OR fazia um "sujar" a checagem do outro.
     */
    private static function gravar(
        string $token, ?string $fbclid, ?string $gclid,
        ?string $fbc, ?string $fbp,
        ?string $utmSource = null, ?string $utmMedium = null, ?string $utmCampaign = null
    ): void {
        $db = Database::getInstance()->getConnection();

        // Dedup INDEPENDENTE: se ESTE fbclid específico já foi
        // capturado pra este visitante na última hora, não regrava.
        // Mesmo pro gclid, separadamente. Um não interfere no outro.
        if ($fbclid !== null && self::jaCapturado($db, $token, 'fbclid', $fbclid)) {
            return;
        }
        if ($gclid !== null && self::jaCapturado($db, $token, 'gclid', $gclid)) {
            return;
        }
        // Mesma ideia para utm_source: sem isto, cada página navegada
        // com a utm ainda na URL viraria uma linha nova e a atribuição
        // "último clique" apontaria para a última página vista em vez
        // da chegada.
        if ($utmSource !== null && self::jaCapturado($db, $token, 'utm_source', $utmSource)) {
            return;
        }

        $db->prepare(
            "INSERT INTO tracking_clicks
             (visitante_token, cliente_id, fbclid, gclid, fbp, fbc,
              landing_url, referrer, utm_source, utm_medium, utm_campaign)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $token,
            self::clienteId(),
            $fbclid,
            $gclid,
            $fbp,
            $fbc,
            self::urlAtual(),
            mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 1000) ?: null,
            $utmSource,
            $utmMedium,
            $utmCampaign,
        ]);
    }

    /**
     * Este click ID específico já foi capturado pra este visitante
     * na última hora? Checagem por UMA coluna só (fbclid OU gclid),
     * nunca as duas juntas — é o que corrige o acoplamento.
     *
     * @param string $coluna 'fbclid' ou 'gclid' (whitelist interna)
     */
    private static function jaCapturado(PDO $db, string $token, string $coluna, string $valor): bool
    {
        // Whitelist da coluna (nunca interpola input do usuário em SQL)
        if (!in_array($coluna, ['fbclid', 'gclid', 'utm_source'], true)) {
            return false;
        }

        $st = $db->prepare(
            "SELECT id FROM tracking_clicks
             WHERE visitante_token = ?
               AND {$coluna} = ?
               AND criado_em >= (NOW() - INTERVAL 1 HOUR)
             LIMIT 1"
        );
        $st->execute([$token, $valor]);
        return (bool)$st->fetchColumn();
    }

    private static function clienteId(): ?int
    {
        try {
            if (class_exists('Session')) {
                $id = (int)(Session::get('cliente_id') ?? 0);
                return $id > 0 ? $id : null;
            }
        } catch (\Throwable $e) {}
        return null;
    }

    private static function urlAtual(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return mb_substr($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')
             . ($_SERVER['REQUEST_URI'] ?? ''), 0, 1000);
    }

    private static function setCookie(string $nome, string $valor): void
    {
        if (headers_sent()) return;
        setcookie($nome, $valor, [
            'expires'  => time() + self::DIAS * 86400,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => false, // o Pixel (Fase 2) precisa ler o _fbc
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$nome] = $valor;
    }
}