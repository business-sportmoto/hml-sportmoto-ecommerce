<?php
/**
 * ReplicateAdapter — Fase 1 traz apenas o teste de conexão; predictions de
 * imagem/remoção de fundo/upscale entram na Fase 2 (worker + webhook).
 */
class ReplicateAdapter extends IAProviderBase
{
    public function codigo(): string
    {
        return 'replicate';
    }

    public function testarConexao(): IAResultado
    {
        $resp = $this->httpJson('GET', '/account', null, 20);

        if ($resp['status'] === 200 && is_array($resp['corpo'])) {
            $conta = (string) ($resp['corpo']['username'] ?? $resp['corpo']['name'] ?? 'conta verificada');
            $r = IAResultado::sucesso('Conexão OK — conta: ' . $conta . '.');
            $r->tempoMs = $resp['tempo_ms'];
            return $r;
        }

        if ($resp['status'] === 0) {
            return IAResultado::falha('rede', 'Sem resposta do provedor: ' . ($resp['erro'] ?? 'falha de rede'));
        }

        [$codigo, $msg] = $this->extrairErro($resp['corpo'], $resp['status']);
        return IAResultado::falha($codigo, $msg, false);
    }
}
