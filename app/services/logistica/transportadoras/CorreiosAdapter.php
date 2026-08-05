<?php
/**
 * Correios (integração DIRETA via CWS — Correios Web Services).
 *
 * IMPORTANTE: para a maioria das lojas, o caminho recomendado é o
 * MelhorEnvioAdapter, que já opera Correios (PAC, SEDEX, Mini) além de
 * Jadlog/Loggi/Azul/LATAM/J&T — sem contrato direto com os Correios.
 *
 * Este adapter existe para quem tem CONTRATO PRÓPRIO com os Correios e
 * quer falar direto com o CWS. As APIs do CWS (Preço, Prazo, PrePostagem,
 * SRO Rastro) dependem do contrato/cartão de postagem do cliente e dos
 * escopos liberados — por isso as operações abaixo estão marcadas como
 * pontos de extensão, para serem completadas com os dados do SEU contrato.
 *
 * Config esperada em log_transportadoras.config (JSON):
 *   usuario           (usuário do CWS)
 *   codigo_acesso     (código de acesso/API do CWS)
 *   cartao_postagem   (número do cartão de postagem)
 *   contrato          (número do contrato)
 *
 * Autenticação CWS (visão geral): obtém-se um token Bearer em
 *   POST {base}/token/v1/autentica/cartaopostagem
 *   Header Authorization: Basic base64(usuario:codigo_acesso)
 *   Body   { "numero": "<cartao_postagem>" }
 * O token retornado é reutilizado até expirar (cacheável em config_extra,
 * padrão análogo ao garantirProdutoAvulso() da Vindi nos pagamentos).
 */
class CorreiosAdapter extends TransportadoraBase
{
    private function baseUrl(): string
    {
        $amb = $this->transportadora['ambiente'] ?? 'producao';
        // Homologação dos Correios usa host próprio; produção usa api.correios.com.br.
        return $amb === 'producao'
            ? 'https://api.correios.com.br'
            : 'https://apihom.correios.com.br';
    }

    private function credenciaisOk(): bool
    {
        return !empty($this->config['usuario'])
            && !empty($this->config['codigo_acesso'])
            && !empty($this->config['cartao_postagem']);
    }

    /**
     * Obtém (e futuramente cacheia) o token Bearer do CWS.
     * Retorna ['ok'=>bool, 'token'=>?string, 'erro'=>?string].
     */
    private function autenticar(): array
    {
        if (!$this->credenciaisOk()) {
            return ['ok' => false, 'token' => null, 'erro' => 'Credenciais do CWS incompletas (usuário, código de acesso e cartão de postagem).'];
        }
        $basic = base64_encode($this->config['usuario'] . ':' . $this->config['codigo_acesso']);
        $r = $this->requisicaoHttp(
            'POST',
            $this->baseUrl() . '/token/v1/autentica/cartaopostagem',
            ['numero' => (string)$this->config['cartao_postagem']],
            ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Basic ' . $basic]
        );
        $ok = $r['erro'] === null && $r['status'] >= 200 && $r['status'] < 300 && !empty($r['json']['token']);
        $this->logComunicacao(
            'teste',
            ['acao' => 'autenticar_cws'],
            ['status' => $r['status'], 'erro' => $r['erro'], 'expira' => $r['json']['expiraEm'] ?? null],
            $ok,
            $r['status'] ?: null,
            $r['ms']
        );
        if (!$ok) {
            $msg = $r['json']['msgs'][0] ?? ($r['json']['message'] ?? ('Falha na autenticação CWS (HTTP ' . $r['status'] . ')'));
            return ['ok' => false, 'token' => null, 'erro' => (string)$msg];
        }
        return ['ok' => true, 'token' => (string)$r['json']['token'], 'erro' => null];
    }

    public function testarConexao(): array
    {
        $auth = $this->autenticar();
        if ($auth['ok']) {
            return ['ok' => true, 'mensagem' => 'Autenticação CWS OK — token obtido.'];
        }
        return ['ok' => false, 'mensagem' => $auth['erro'] ?? 'Falha ao autenticar no CWS.'];
    }

    /* -----------------------------------------------------------------
       Pontos de extensão — completar com as APIs do SEU contrato CWS.
       Cada método já autentica e devolve retorno padronizado; basta
       preencher a chamada específica (Preço/Prazo, PrePostagem, SRO).
       ----------------------------------------------------------------- */

    public function cotar(array $params): array
    {
        // TODO(contrato): CWS "Preço" (/preco/v1/nacional/{codigo}) e
        // "Prazo" (/prazo/v1/nacional/{codigo}) — combinar valor + prazo.
        return ['ok' => false, 'erro' => 'Cotação direta Correios/CWS pendente de contrato. Use o Melhor Envio para operar Correios sem contrato próprio.'];
    }

    public function gerarEtiqueta(array $params): array
    {
        // TODO(contrato): CWS "PrePostagem" (/prepostagem/v1/prepostagens)
        // -> objeto de prepostagem -> rótulo assíncrono (/rotulo/v1/...).
        return ['ok' => false, 'erro' => 'Geração de etiqueta Correios/CWS pendente de contrato.'];
    }

    public function imprimirEtiqueta(array $externalIds, string $modo = 'private'): array
    {
        // TODO(contrato): rótulo/manifesto de prepostagem no CWS.
        return ['ok' => false, 'erro' => 'Impressão de etiqueta Correios/CWS pendente de contrato.'];
    }

    public function cancelarEtiqueta(string $externalId): array
    {
        // TODO(contrato): cancelamento de prepostagem no CWS.
        return ['ok' => false, 'erro' => 'Cancelamento Correios/CWS pendente de contrato.'];
    }

    public function rastrear(string $codigo): array
    {
        // TODO(contrato): CWS "SRO Rastro" (/srorastro/v1/objetos/{codigo}).
        // Ao implementar, mapear os eventos crus com $this->mapearStatus().
        return ['ok' => false, 'erro' => 'Rastreio direto Correios/CWS pendente de contrato.'];
    }

    public function gerarReversa(array $params): array
    {
        // TODO(contrato): Logística Reversa dos Correios (autorização de postagem).
        return ['ok' => false, 'erro' => 'Logística reversa Correios/CWS pendente de contrato.'];
    }
}
