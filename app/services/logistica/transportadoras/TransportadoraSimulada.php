<?php
/**
 * Transportadora SIMULADA — sandbox interno.
 *
 * Serve para: (a) ver a Torre de Controle e as telas com dados plausíveis
 * antes de plugar integrações reais; (b) validar o fluxo cotação ->
 * etiqueta -> rastreio de ponta a ponta em homologação; (c) servir de
 * modelo mínimo para escrever um adapter real (Correios, Melhor Envio...).
 *
 * NÃO usar em produção com tráfego real.
 */
class TransportadoraSimulada extends TransportadoraBase
{
    public function cotar(array $params): array
    {
        $peso = max(0.3, ((int)($params['peso_g'] ?? 500)) / 1000);
        $seguro = !empty($params['seguro']) ? round(((float)($params['valor'] ?? 0)) * 0.01, 2) : 0;

        $base = [
            ['servico_codigo' => 'ECON', 'servico_nome' => 'Econômico', 'prazo_dias' => 6, 'fator' => 8.5,  'tipo_postagem' => 'postagem'],
            ['servico_codigo' => 'EXPR', 'servico_nome' => 'Expresso',  'prazo_dias' => 2, 'fator' => 14.0, 'tipo_postagem' => 'postagem'],
            ['servico_codigo' => 'COLE', 'servico_nome' => 'Coleta',    'prazo_dias' => 3, 'fator' => 12.0, 'tipo_postagem' => 'coleta'],
        ];

        $opcoes = [];
        foreach ($base as $s) {
            $custo = round($s['fator'] + ($peso * 3.2) + $seguro, 2);
            $opcoes[] = [
                'servico_codigo' => $s['servico_codigo'],
                'servico_nome'   => $s['servico_nome'],
                'prazo_dias'     => $s['prazo_dias'] + (int)($this->transportadora['prazo_preparo_dias'] ?? 0),
                'valor'          => $this->aplicarMargem($custo),
                'tipo_postagem'  => $s['tipo_postagem'],
                'avisos'         => $seguro > 0 ? ['Seguro incluso'] : [],
            ];
        }

        $this->logComunicacao('cotacao', $params, ['opcoes' => count($opcoes)], true, 200, 12);
        return ['ok' => true, 'opcoes' => $opcoes];
    }

    public function gerarEtiqueta(array $params): array
    {
        $codigo = 'SIM' . strtoupper(substr(md5(json_encode($params) . microtime()), 0, 9)) . 'BR';
        $resp = [
            'ok'              => true,
            'external_id'     => 'sim_' . substr(sha1($codigo), 0, 12),
            'codigo_rastreio' => $codigo,
            'url_pdf'         => 'about:blank#etiqueta-simulada',
            'valor'           => round((float)($params['valor_frete'] ?? 15.0), 2),
            'contrato'        => 'SIMULADO',
        ];
        $this->logComunicacao('etiqueta', $params, $resp, true, 201, 40);
        return $resp;
    }

    public function imprimirEtiqueta(array $externalIds, string $modo = 'private'): array
    {
        $ids = array_values(array_filter(array_map('strval', $externalIds), static fn($v) => $v !== ''));
        if (!$ids) return ['ok' => false, 'erro' => 'Nenhuma etiqueta informada.'];
        $resp = ['ok' => true, 'url_pdf' => 'about:blank#etiquetas-simuladas-' . count($ids)];
        $this->logComunicacao('etiqueta', ['orders' => $ids, 'mode' => $modo], $resp, true, 200, 12);
        return $resp;
    }

    public function cancelarEtiqueta(string $externalId): array
    {
        $this->logComunicacao('cancelamento', ['external_id' => $externalId], ['ok' => true], true, 200, 15);
        return ['ok' => true];
    }

    public function rastrear(string $codigo): array
    {
        $agora = new DateTimeImmutable('now');
        $crus = [
            ['-3 days', 'Objeto postado',       'Porto Alegre / RS'],
            ['-2 days', 'Em trânsito',          'Centro de Distribuição'],
            ['-1 days', 'Saiu para entrega',    'Unidade de destino'],
            ['-2 hours','Entregue',             'Endereço do destinatário'],
        ];
        $eventos = [];
        $ultimo = 'postado';
        foreach ($crus as [$delta, $txt, $local]) {
            $ultimo = $this->mapearStatus($txt);
            $eventos[] = [
                'data'                 => $agora->modify($delta)->format('Y-m-d H:i:s'),
                'status_transportadora'=> $txt,
                'status_interno'       => $ultimo,
                'descricao'            => $txt,
                'local'                => $local,
            ];
        }
        $resp = [
            'ok'               => true,
            'status_interno'   => $ultimo,
            'previsao_entrega' => $agora->modify('-1 days')->format('Y-m-d'),
            'eventos'          => $eventos,
        ];
        $this->logComunicacao('rastreio', ['codigo' => $codigo], ['eventos' => count($eventos)], true, 200, 18);
        return $resp;
    }

    public function gerarReversa(array $params): array
    {
        $codigo = 'REV' . strtoupper(substr(md5(json_encode($params) . microtime()), 0, 9)) . 'BR';
        $resp = [
            'ok'              => true,
            'external_id'     => 'simrev_' . substr(sha1($codigo), 0, 12),
            'codigo_rastreio' => $codigo,
            'url_pdf'         => 'about:blank#reversa-simulada',
            'validade'        => (new DateTimeImmutable('+15 days'))->format('Y-m-d'),
        ];
        $this->logComunicacao('reversa', $params, $resp, true, 201, 35);
        return $resp;
    }

    public function testarConexao(): array
    {
        $this->logComunicacao('teste', [], ['ok' => true], true, 200, 5);
        return ['ok' => true, 'mensagem' => 'Conexão simulada OK (sandbox interno).'];
    }
}
