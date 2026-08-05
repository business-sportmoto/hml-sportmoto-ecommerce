<?php
/**
 * Melhor Envio — intermediador que cobre Correios (PAC/SEDEX/Mini),
 * Jadlog, Loggi, Azul Cargo, LATAM Cargo e J&T por UMA integração.
 *
 * Fluxo oficial de compra: calculate -> cart -> checkout -> generate -> print.
 * Rastreio e cancelamento por ID da etiqueta (order id do Melhor Envio).
 *
 * Config esperada em log_transportadoras.config (JSON):
 *   token            (Bearer OAuth2 — obrigatório)
 *   app_nome         (para o header User-Agent — obrigatório pela API)
 *   email_contato    (para o header User-Agent — obrigatório pela API)
 *   remetente        (objeto: name, phone, email, document/company_document,
 *                     state_register, address, complement, number, district,
 *                     city, state_abbr; postal_code cai para cep_origem)
 *
 * O ambiente (sandbox|homologacao|producao) vem da coluna `ambiente`.
 * O Melhor Envio só tem SANDBOX e PRODUÇÃO — 'homologacao' usa sandbox.
 *
 * Referência: https://docs.melhorenvio.com.br/  (verificado no build da Fase 2)
 */
class MelhorEnvioAdapter extends TransportadoraBase
{
    private function baseUrl(): string
    {
        $amb = $this->transportadora['ambiente'] ?? 'sandbox';
        return $amb === 'producao'
            ? 'https://www.melhorenvio.com.br/api/v2'
            : 'https://sandbox.melhorenvio.com.br/api/v2';
    }

    /** Cabeçalhos exigidos pela API (Accept/Content-Type/Bearer/User-Agent). */
    private function headers(): array
    {
        $token = (string)($this->config['token'] ?? '');
        $app   = trim((string)($this->config['app_nome'] ?? 'SportMoto'));
        $mail  = trim((string)($this->config['email_contato'] ?? ''));
        // A API bloqueia requisições sem User-Agent no formato "App (email)".
        $ua = $mail !== '' ? "{$app} ({$mail})" : $app;
        return [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: ' . $ua,
        ];
    }

    /** Wrapper: monta URL + headers, dispara e registra em log_comunicacoes. */
    private function req(string $tipoLog, string $metodo, string $path, $corpo = null, ?int $ref = null): array
    {
        $r = $this->requisicaoHttp($metodo, $this->baseUrl() . $path, $corpo, $this->headers());
        $ok = $r['erro'] === null && $r['status'] >= 200 && $r['status'] < 300;
        $this->logComunicacao(
            $tipoLog,
            ['metodo' => $metodo, 'path' => $path, 'corpo' => is_array($corpo) ? $corpo : null],
            ['status' => $r['status'], 'resposta' => $r['json'] ?? mb_substr($r['body'], 0, 500), 'erro' => $r['erro']],
            $ok,
            $r['status'] ?: null,
            $r['ms'],
            $ref
        );
        return $r + ['ok' => $ok];
    }

    /** Mensagem de erro legível a partir da resposta do Melhor Envio. */
    private function erroDe(array $r, string $fallback): string
    {
        $j = $r['json'] ?? null;
        if (is_array($j)) {
            if (!empty($j['message'])) return (string)$j['message'];
            if (!empty($j['error']))   return is_string($j['error']) ? $j['error'] : json_encode($j['error'], JSON_UNESCAPED_UNICODE);
            if (!empty($j['errors']))  return json_encode($j['errors'], JSON_UNESCAPED_UNICODE);
        }
        if (!empty($r['erro'])) return (string)$r['erro'];
        return $fallback . ' (HTTP ' . ($r['status'] ?? 0) . ')';
    }

    /* =================================================================
       COTAÇÃO
       ================================================================= */
    public function cotar(array $params): array
    {
        $origem  = $this->soDigitos($params['cep_origem'] ?? $this->cepOrigem() ?? ($this->config['remetente']['postal_code'] ?? ''));
        $destino = $this->soDigitos($params['cep_destino'] ?? '');
        if ($origem === '' || $destino === '') {
            return ['ok' => false, 'erro' => 'CEP de origem/destino ausente para cotação.'];
        }

        $pacote = $this->pacoteConsolidado($params);
        $corpo = [
            'from' => ['postal_code' => $origem],
            'to'   => ['postal_code' => $destino],
            'package' => $pacote,
            'options' => [
                'insurance_value' => (float)($params['valor'] ?? 0),
                'receipt'  => !empty($params['aviso_recebimento']),
                'own_hand' => !empty($params['maos_proprias']),
            ],
        ];

        $r = $this->req('cotacao', 'POST', '/me/shipment/calculate', $corpo);
        if (!$r['ok'] || !is_array($r['json'])) {
            return ['ok' => false, 'erro' => $this->erroDe($r, 'Falha ao cotar no Melhor Envio')];
        }

        $preparo = (int)($this->transportadora['prazo_preparo_dias'] ?? 0);
        $opcoes = [];
        foreach ($r['json'] as $s) {
            if (!is_array($s) || !empty($s['error'])) {
                continue; // serviço indisponível para esta rota
            }
            $custo = (float)($s['custom_price'] ?? $s['price'] ?? 0);
            $prazo = (int)($s['custom_delivery_time'] ?? $s['delivery_time'] ?? 0);
            $empresa = $s['company']['name'] ?? '';
            $opcoes[] = [
                'servico_codigo' => (string)($s['id'] ?? ''),
                'servico_nome'   => trim($empresa . ' ' . ($s['name'] ?? '')),
                'prazo_dias'     => $prazo + $preparo,
                'valor'          => $this->aplicarMargem($custo),
                'tipo_postagem'  => 'postagem',
                'avisos'         => [],
            ];
        }

        return ['ok' => true, 'opcoes' => $opcoes];
    }

    /* =================================================================
       ETIQUETA (cart -> checkout -> generate -> print)
       ================================================================= */
    public function gerarEtiqueta(array $params): array
    {
        // Retomada idempotente: se já existe um order id de tentativa anterior,
        // não recria o carrinho — apenas conclui as etapas pendentes.
        $orderId = (string)($params['me_order_id'] ?? '');

        if ($orderId === '') {
            $corpo = $this->montarCarrinho($params);
            if (isset($corpo['__erro'])) {
                return ['ok' => false, 'erro' => $corpo['__erro']];
            }
            $r = $this->req('etiqueta', 'POST', '/me/cart', $corpo);
            if (!$r['ok'] || empty($r['json']['id'])) {
                return ['ok' => false, 'erro' => $this->erroDe($r, 'Falha ao inserir no carrinho'), 'etapa' => 'cart'];
            }
            $orderId = (string)$r['json']['id'];
            $preco   = (float)($r['json']['price'] ?? 0);
        } else {
            $preco = (float)($params['valor_frete'] ?? 0);
        }

        // checkout (pagamento)
        $c = $this->req('etiqueta', 'POST', '/me/shipment/checkout', ['orders' => [$orderId]]);
        if (!$c['ok']) {
            return ['ok' => false, 'erro' => $this->erroDe($c, 'Falha no checkout'), 'etapa' => 'checkout', 'external_id' => $orderId];
        }

        // generate (gera a etiqueta na transportadora)
        $g = $this->req('etiqueta', 'POST', '/me/shipment/generate', ['orders' => [$orderId]]);
        if (!$g['ok']) {
            return ['ok' => false, 'erro' => $this->erroDe($g, 'Falha ao gerar etiqueta'), 'etapa' => 'generate', 'external_id' => $orderId];
        }
        $codigoRastreio = $this->extrairRastreio($g['json'], $orderId);

        // print (URL do PDF)
        $p = $this->req('etiqueta', 'POST', '/me/shipment/print', ['mode' => 'private', 'orders' => [$orderId]]);
        $urlPdf = is_array($p['json']) ? ($p['json']['url'] ?? null) : null;

        return [
            'ok'              => true,
            'external_id'     => $orderId,
            'codigo_rastreio' => $codigoRastreio,
            'url_pdf'         => $urlPdf,
            'valor'           => $preco,
            'contrato'        => 'melhor-envio',
        ];
    }

    public function imprimirEtiqueta(array $externalIds, string $modo = 'private'): array
    {
        $ids = array_values(array_filter(array_map('strval', $externalIds), static fn($v) => $v !== ''));
        if (!$ids) return ['ok' => false, 'erro' => 'Nenhuma etiqueta informada para impressão.'];

        $modo = in_array($modo, ['private', 'public'], true) ? $modo : 'private';
        $p = $this->req('etiqueta', 'POST', '/me/shipment/print', ['mode' => $modo, 'orders' => $ids]);
        $url = is_array($p['json']) ? ($p['json']['url'] ?? null) : null;
        if ($p['ok'] && $url) {
            return ['ok' => true, 'url_pdf' => $url];
        }
        return ['ok' => false, 'erro' => $this->erroDe($p, 'Falha ao imprimir etiqueta(s)')];
    }

    public function cancelarEtiqueta(string $externalId): array
    {
        $r = $this->req('cancelamento', 'POST', '/me/shipment/cancel', [
            'order' => ['id' => $externalId, 'reason_id' => '2', 'description' => 'Cancelamento solicitado no painel'],
        ]);
        $canceled = is_array($r['json']) && !empty($r['json'][$externalId]['canceled']);
        if ($r['ok'] && $canceled) {
            return ['ok' => true];
        }
        return ['ok' => false, 'erro' => $this->erroDe($r, 'Não foi possível cancelar a etiqueta')];
    }

    /* =================================================================
       RASTREIO — no Melhor Envio, o "código" é o ID da etiqueta (order id)
       ================================================================= */
    public function rastrear(string $codigo): array
    {
        $r = $this->req('rastreio', 'POST', '/me/shipment/tracking', ['orders' => [$codigo]]);
        if (!$r['ok'] || !is_array($r['json']) || empty($r['json'][$codigo])) {
            return ['ok' => false, 'erro' => $this->erroDe($r, 'Falha ao rastrear no Melhor Envio')];
        }

        $o = $r['json'][$codigo];
        $statusCru = (string)($o['status'] ?? '');
        $interno   = $this->mapearStatus($statusCru);

        // O endpoint devolve um snapshot; montamos eventos a partir das datas
        // disponíveis (posted_at, delivered_at) + o status atual.
        $eventos = [];
        foreach ([['posted_at', 'Postado', 'postado'], ['delivered_at', 'Entregue', 'entregue']] as [$campo, $txt, $st]) {
            if (!empty($o[$campo])) {
                $eventos[] = [
                    'data'                  => (string)$o[$campo],
                    'status_transportadora' => $txt,
                    'status_interno'        => $st,
                    'descricao'             => $txt,
                    'local'                 => '',
                ];
            }
        }
        if (empty($eventos)) {
            $eventos[] = [
                'data'                  => date('Y-m-d H:i:s'),
                'status_transportadora' => $statusCru ?: 'Atualização',
                'status_interno'        => $interno,
                'descricao'             => $statusCru ?: 'Atualização de status',
                'local'                 => '',
            ];
        }

        return [
            'ok'               => true,
            'status_interno'   => $interno,
            'previsao_entrega' => $o['delivery_max'] ?? ($o['expected_delivery'] ?? null),
            'eventos'          => $eventos,
            'codigo_rastreio'  => $o['tracking'] ?? null,
        ];
    }

    /* =================================================================
       LOGÍSTICA REVERSA — carrinho com options.reverse = true (cliente -> loja)
       ================================================================= */
    public function gerarReversa(array $params): array
    {
        $params['reversa'] = true;
        // Na reversa, remetente = cliente e destinatário = loja.
        $params['remetente']    = $params['cliente']  ?? ($params['remetente'] ?? []);
        $params['destinatario'] = $params['loja']     ?? ($this->config['remetente'] ?? []);
        $res = $this->gerarEtiqueta($params);
        if (!$res['ok']) {
            return $res;
        }
        return [
            'ok'              => true,
            'external_id'     => $res['external_id'] ?? null,
            'codigo_rastreio' => $res['codigo_rastreio'] ?? null,
            'url_pdf'         => $res['url_pdf'] ?? null,
            'validade'        => null,
        ];
    }

    /* =================================================================
       TESTE DE CONEXÃO
       ================================================================= */
    public function testarConexao(): array
    {
        if (empty($this->config['token'])) {
            return ['ok' => false, 'mensagem' => 'Token do Melhor Envio não configurado.'];
        }
        $r = $this->req('teste', 'GET', '/me');
        if ($r['ok'] && is_array($r['json'])) {
            $nome = $r['json']['firstname'] ?? ($r['json']['name'] ?? 'conta');
            $amb  = ($this->transportadora['ambiente'] ?? 'sandbox') === 'producao' ? 'produção' : 'sandbox';
            return ['ok' => true, 'mensagem' => "Conexão OK ({$amb}) — {$nome}.", 'detalhe' => ['email' => $r['json']['email'] ?? null]];
        }
        if (($r['status'] ?? 0) === 401) {
            return ['ok' => false, 'mensagem' => 'Token inválido, expirado ou sem escopo.'];
        }
        return ['ok' => false, 'mensagem' => $this->erroDe($r, 'Falha ao conectar no Melhor Envio')];
    }

    /* =================================================================
       Mapa de status específico do Melhor Envio
       ================================================================= */
    public function mapearStatus(string $statusCru): string
    {
        static $me = [
            'pending'          => 'aguardando_etiqueta',
            'released'         => 'etiqueta_emitida',
            'generated'        => 'etiqueta_emitida',
            'posted'           => 'postado',
            'in_transit'       => 'em_transito',
            'out_for_delivery' => 'saiu_entrega',
            'delivered'        => 'entregue',
            'returning'        => 'devolucao',
            'returned'         => 'devolucao',
            'canceled'         => 'ocorrencia',
            'undelivered'      => 'ocorrencia',
            'expired'          => 'ocorrencia',
            'suspended'        => 'ocorrencia',
        ];
        $chave = mb_strtolower(trim($statusCru));
        if (isset($me[$chave])) {
            return $me[$chave];
        }
        return parent::mapearStatus($statusCru); // heurística textual da Base
    }

    /* =================================================================
       Helpers de montagem
       ================================================================= */

    /** Consolida os volumes num único pacote para a COTAÇÃO (kg/cm). */
    private function pacoteConsolidado(array $params): array
    {
        $volumes = $params['volumes'] ?? [];
        if (is_array($volumes) && count($volumes) > 0) {
            $peso = 0; $h = 0; $w = 0; $l = 0;
            foreach ($volumes as $v) {
                $peso += (float)($v['peso_g'] ?? $v['peso'] ?? 0) / (isset($v['peso_g']) ? 1000 : 1);
                $h = max($h, (float)($v['altura'] ?? $v['height'] ?? 0));
                $w = max($w, (float)($v['largura'] ?? $v['width'] ?? 0));
                $l = max($l, (float)($v['comprimento'] ?? $v['length'] ?? 0));
            }
            return [
                'height' => max(2, round($h)),
                'width'  => max(11, round($w)),
                'length' => max(16, round($l)),
                'weight' => max(0.1, round($peso, 3)),
            ];
        }
        // Sem volumes: deriva do peso e usa dimensões mínimas seguras.
        $pesoKg = max(0.1, ((float)($params['peso_g'] ?? 300)) / 1000);
        return ['height' => 4, 'width' => 12, 'length' => 17, 'weight' => round($pesoKg, 3)];
    }

    /** Monta o payload de /me/cart a partir dos dados do envio. */
    private function montarCarrinho(array $params): array
    {
        $servico = (int)($params['servico_codigo'] ?? $params['service'] ?? 0);
        if ($servico <= 0) {
            return ['__erro' => 'Serviço (service id) do Melhor Envio não informado.'];
        }

        $remetente = $params['remetente'] ?? ($this->config['remetente'] ?? []);
        $dest      = $params['destinatario'] ?? [];
        if (empty($dest)) {
            return ['__erro' => 'Destinatário ausente para a etiqueta.'];
        }

        $volumes = [];
        foreach (($params['volumes'] ?? []) as $v) {
            $volumes[] = [
                'height' => (int)round((float)($v['altura'] ?? $v['height'] ?? 2)),
                'width'  => (int)round((float)($v['largura'] ?? $v['width'] ?? 11)),
                'length' => (int)round((float)($v['comprimento'] ?? $v['length'] ?? 16)),
                'weight' => (float)($v['peso_g'] ?? null) !== null && isset($v['peso_g'])
                            ? round(((float)$v['peso_g']) / 1000, 3)
                            : (float)($v['peso'] ?? $v['weight'] ?? 0.3),
            ];
        }
        if (empty($volumes)) {
            $p = $this->pacoteConsolidado($params);
            $volumes[] = $p;
        }

        $produtos = [];
        foreach (($params['produtos'] ?? []) as $p) {
            $produtos[] = [
                'name'          => (string)($p['nome'] ?? $p['name'] ?? 'Item'),
                'quantity'      => (string)($p['quantidade'] ?? $p['quantity'] ?? 1),
                'unitary_value' => (string)($p['valor'] ?? $p['unitary_value'] ?? 0),
            ];
        }
        if (empty($produtos)) {
            $produtos[] = ['name' => 'Pedido', 'quantity' => '1', 'unitary_value' => (string)($params['valor'] ?? 0)];
        }

        return [
            'service' => $servico,
            'from'    => $this->endereco($remetente, $this->cepOrigem()),
            'to'      => $this->endereco($dest, ''),
            'products'=> $produtos,
            'volumes' => $volumes,
            'options' => [
                'insurance_value' => (float)($params['valor'] ?? 0),
                'receipt'         => !empty($params['aviso_recebimento']),
                'own_hand'        => !empty($params['maos_proprias']),
                'reverse'         => !empty($params['reversa']),
                'non_commercial'  => empty($params['nota_fiscal_chave']),
                'platform'        => 'SportMoto',
            ] + (!empty($params['nota_fiscal_chave']) ? ['invoice' => ['key' => (string)$params['nota_fiscal_chave']]] : []),
        ];
    }

    /** Normaliza um endereço (remetente/destinatário) para o formato da API. */
    private function endereco(array $e, string $cepFallback): array
    {
        return [
            'name'             => (string)($e['name'] ?? $e['nome'] ?? ''),
            'phone'            => (string)($e['phone'] ?? $e['telefone'] ?? ''),
            'email'            => (string)($e['email'] ?? ''),
            'document'         => $this->soDigitos((string)($e['document'] ?? $e['cpf'] ?? '')),
            'company_document' => $this->soDigitos((string)($e['company_document'] ?? $e['cnpj'] ?? '')),
            'state_register'   => (string)($e['state_register'] ?? $e['inscricao_estadual'] ?? ''),
            'address'          => (string)($e['address'] ?? $e['logradouro'] ?? ''),
            'complement'       => (string)($e['complement'] ?? $e['complemento'] ?? ''),
            'number'           => (string)($e['number'] ?? $e['numero'] ?? ''),
            'district'         => (string)($e['district'] ?? $e['bairro'] ?? ''),
            'city'             => (string)($e['city'] ?? $e['cidade'] ?? ''),
            'state_abbr'       => (string)($e['state_abbr'] ?? $e['uf'] ?? ''),
            'country_id'       => 'BR',
            'postal_code'      => $this->soDigitos((string)($e['postal_code'] ?? $e['cep'] ?? $cepFallback)),
        ];
    }

    /** Extrai o código de rastreio da resposta de generate (varia por transportadora). */
    private function extrairRastreio(?array $json, string $orderId): ?string
    {
        if (!is_array($json)) return null;
        $o = $json[$orderId] ?? $json;
        return $o['tracking'] ?? ($o['self_tracking'] ?? null);
    }

    private function soDigitos(string $s): string
    {
        return preg_replace('/\D+/', '', $s) ?? '';
    }
}
