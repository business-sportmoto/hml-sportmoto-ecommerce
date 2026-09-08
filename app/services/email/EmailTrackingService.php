<?php
/**
 * app/services/email/EmailTrackingService.php
 *
 * Reescreve links do HTML para tracking, injeta pixel de abertura, gera URLs
 * canônicas e registra eventos quando o public controller é acessado.
 */
class EmailTrackingService
{
    /** @var EmailLink */
    private $links;
    /** @var EmailEvent */
    private $eventos;
    /** @var EmailCampaignRecipient */
    private $destinatarios;
    /** @var EmailCampaign */
    private $campanhas;

    public function __construct()
    {
        $this->links = new EmailLink();
        $this->eventos = new EmailEvent();
        $this->destinatarios = new EmailCampaignRecipient();
        $this->campanhas = new EmailCampaign();
    }

    private function baseUrl()
    {
        return rtrim(defined('BASE_URL') ? BASE_URL : '', '/');
    }

    public function pixelUrl($tokenOpen)
    {
        return $this->baseUrl() . '/email/open/' . $tokenOpen . '.png';
    }

    public function clickUrl($destinatarioId, $linkId, $tokenOpen)
    {
        return $this->baseUrl() . '/email/click/' . (int)$destinatarioId . '/' . (int)$linkId . '/' . $tokenOpen;
    }

    public function unsubUrl($tokenDesc)
    {
        return $this->baseUrl() . '/email/descadastrar/' . $tokenDesc;
    }

    /**
     * Reescreve todos os <a href="..."> do HTML para passar pela rota de
     * click. Não toca em href que comecem com 'mailto:', 'tel:', '#' ou que
     * já apontem para a rota de descadastro.
     */
    public function reescreverLinks($html, $campanhaId, $destinatarioId, $tokenOpen, $urlUnsub)
    {
        $self = $this;
        $links = $this->links;
        $base  = $this->baseUrl();

        return preg_replace_callback(
            '#<a([^>]*?)href\s*=\s*(["\'])(.*?)\2([^>]*)>#i',
            function ($m) use ($self, $links, $campanhaId, $destinatarioId, $tokenOpen, $urlUnsub, $base) {
                $pre = $m[1]; $quote = $m[2]; $url = $m[3]; $pos = $m[4];

                // ignora não-trackable
                $lc = strtolower($url);
                if ($lc === '' || $lc[0] === '#' ||
                    strpos($lc, 'mailto:') === 0 ||
                    strpos($lc, 'tel:') === 0 ||
                    strpos($url, $urlUnsub) === 0 ||
                    strpos($url, $base . '/email/') === 0) {
                    return $m[0];
                }
                $link = $links->findOrCreate((int)$campanhaId, $url);
                $tracked = $self->clickUrl($destinatarioId, $link['id'], $tokenOpen);
                return '<a' . $pre . 'href=' . $quote . $tracked . $quote . $pos . '>';
            },
            $html
        );
    }

    /**
     * Acrescenta as UTMs de campanha ao destino, no momento do redirect.
     *
     * POR QUE AQUI, E NÃO NO HTML DO E-MAIL
     *   O link do e-mail já aponta para o redirect rastreado; o destino real
     *   fica em email_links.url_destino. Marcar no redirect mantém o destino
     *   armazenado limpo e garante a UTM mesmo em template escrito à mão, que
     *   ninguém lembraria de marcar.
     *
     * POR QUE ISSO IMPORTA
     *   É o que fecha o ciclo até a venda. O ClickCaptureService já grava a
     *   UTM da chegada em tracking_clicks, e o CheckoutController congela a
     *   atribuição em pedidos.utm_campaign. Sem esta marcação, aquela cadeia
     *   inteira existia e nunca via um clique de e-mail — o banco tinha 417
     *   linhas em tracking_clicks e utm_source 100% NULL.
     *
     * NÃO SOBRESCREVE o que já estiver na URL: se quem montou o template pôs
     * a própria marcação, ela vence. Só preenche o que falta.
     *
     * @param  string $url         destino real
     * @param  int    $campanhaId  vira utm_campaign = camp_<id>
     * @param  int    $linkId      vira utm_content — diz QUAL link converteu
     * @return string
     */
    public function comUtm($url, $campanhaId, $linkId = 0)
    {
        $url = (string) $url;
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $url;   // âncora, mailto:, relativo — não marca
        }

        // O fragmento fica SEMPRE no fim: parâmetro depois do # some.
        $frag = '';
        if (($p = strpos($url, '#')) !== false) {
            $frag = substr($url, $p);
            $url  = substr($url, 0, $p);
        }

        $partes = explode('?', $url, 2);
        $base   = $partes[0];
        $query  = [];
        if (isset($partes[1]) && $partes[1] !== '') {
            parse_str($partes[1], $query);
        }

        $utm = [
            'utm_source'   => 'email_marketing',
            'utm_medium'   => 'email',
            'utm_campaign' => 'camp_' . (int) $campanhaId,
        ];
        if ((int) $linkId > 0) {
            $utm['utm_content'] = 'link_' . (int) $linkId;
        }

        foreach ($utm as $chave => $valor) {
            if (!array_key_exists($chave, $query) || $query[$chave] === '') {
                $query[$chave] = $valor;
            }
        }

        return $base . '?' . http_build_query($query) . $frag;
    }

    /**
     * Injeta o pixel de abertura antes de </body>, ou no final do HTML.
     */
    public function injetarPixel($html, $tokenOpen)
    {
        $url = $this->pixelUrl($tokenOpen);
        $img = '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" '
             . 'width="1" height="1" alt="" border="0" '
             . 'style="display:block;width:1px;height:1px;border:0;outline:none;" />';
        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $img . '</body>', $html, 1);
        }
        return $html . $img;
    }

    // ---- Registro de eventos vindos do tracking público --------------------

    public function registrarAbertura($destinatarioRow, $ip = null, $ua = null)
    {
        if (!$destinatarioRow) return;

        $jaAberto = !empty($destinatarioRow['aberto_em']);

        $this->eventos->registrar([
            'campanha_id' => $destinatarioRow['campanha_id'],
            'destinatario_id' => $destinatarioRow['id'],
            'contato_id' => $destinatarioRow['contato_id'],
            'provider_message_id' => $destinatarioRow['provider_message_id'] ?? null,
            'tipo' => 'aberto',
            'ip' => $ip,
            'user_agent' => $ua,
            'dedupe_key' => hash('sha256', 'open|' . $destinatarioRow['id'] . '|' . date('YmdH')),
        ]);

        // só sobe status se ainda não estiver acima
        $promo = !in_array($destinatarioRow['status'], ['aberto','clicado','bounce','complaint','descadastrado'], true);
        if ($promo) {
            $this->destinatarios->atualizarStatusEvento(
                $destinatarioRow['id'],
                'aberto',
                ['aberto_em' => date('Y-m-d H:i:s')]
            );
        }
        if (!$jaAberto) {
            $this->campanhas->incrementar($destinatarioRow['campanha_id'], 'total_aberturas');
        }
    }

    public function registrarClique($destinatarioRow, $linkRow, $ip = null, $ua = null)
    {
        if (!$destinatarioRow || !$linkRow) return;

        $jaClicou = !empty($destinatarioRow['clicado_em']);

        $this->eventos->registrar([
            'campanha_id' => $destinatarioRow['campanha_id'],
            'destinatario_id' => $destinatarioRow['id'],
            'contato_id' => $destinatarioRow['contato_id'],
            'provider_message_id' => $destinatarioRow['provider_message_id'] ?? null,
            'tipo' => 'clicado',
            'ip' => $ip,
            'user_agent' => $ua,
            'link_id' => $linkRow['id'],
            'dedupe_key' => hash('sha256', 'click|' . $destinatarioRow['id'] . '|' . $linkRow['id'] . '|' . date('YmdH')),
        ]);
        $this->links->incrementarClique($linkRow['id']);

        $promo = !in_array($destinatarioRow['status'], ['clicado','bounce','complaint','descadastrado'], true);
        if ($promo) {
            $this->destinatarios->atualizarStatusEvento(
                $destinatarioRow['id'],
                'clicado',
                ['clicado_em' => date('Y-m-d H:i:s')]
            );
        }
        if (!$jaClicou) {
            $this->campanhas->incrementar($destinatarioRow['campanha_id'], 'total_cliques');
            // se ainda não havia abertura registrada, também registra uma
            if (empty($destinatarioRow['aberto_em'])) {
                $this->campanhas->incrementar($destinatarioRow['campanha_id'], 'total_aberturas');
                $this->destinatarios->atualizarStatusEvento(
                    $destinatarioRow['id'],
                    'clicado',
                    ['aberto_em' => date('Y-m-d H:i:s')]
                );
            }
        }
    }
}
