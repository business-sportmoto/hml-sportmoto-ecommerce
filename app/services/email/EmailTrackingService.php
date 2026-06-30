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
