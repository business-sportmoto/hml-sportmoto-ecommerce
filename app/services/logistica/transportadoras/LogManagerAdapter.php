<?php
/**
 * LogManagerAdapter — integração real com a API LogManager (perfil VENDEDOR).
 * Doc: https://logmanager.readme.io/reference/primeiros-passos
 *
 * PARTICULARIDADE: a LogManager NÃO cota frete. Portanto:
 *   - cotar()  -> devolve UMA opção D+1 (categoria 'd1') com PREÇO-BASE vindo da
 *                 config; as REGRAS de frete (Fase 3) aplicam grátis/desconto por
 *                 cima, no motor. Só aparece se o CEP estiver na área atendida.
 *   - etiqueta e rastreio -> via API da LogManager (token do painel do vendedor).
 *
 * Endpoints (base https://app.logmanager.com.br):
 *   POST /api/integrations/erp/callback       -> enviar pedido (gera etiqueta/rastreio)
 *   GET  /api/integrations/erp/shipment/{id}   -> consultar/rastrear (id = ref_logmanager_id ou idEnvio)
 * Autenticação: header Authorization: Bearer {token}.
 * Webhook de status: a LogManager faz POST no seu endpoint cadastrado
 *   ({carrierId, orderId, status, processedAt, ...}) — ver eventoDeWebhook().
 */
class LogManagerAdapter extends TransportadoraBase
{
    /** Mapa oficial de status da LogManager -> estado interno do módulo. */
    private const MAPA_LM = [
        'collected'             => 'postado',
        'received'              => 'em_transito',
        'handling'              => 'em_transito',
        'shipped'               => 'saiu_entrega',
        'delivered'             => 'entregue',
        'devolution'            => 'devolucao',
        'in_devolution'         => 'devolucao',
        'away'                  => 'ocorrencia',
        'risk_area'             => 'ocorrencia',
        'stolen'                => 'ocorrencia',
        'thefted'               => 'ocorrencia',
        'cancelled'             => 'ocorrencia',
        'incorrect_address'     => 'ocorrencia',
        'recused'               => 'ocorrencia',
        'avaried'               => 'ocorrencia',
        'prejudicted'           => 'ocorrencia',
        'collect_not_performed' => 'ocorrencia',
        'not_collected'         => 'ocorrencia',
        'other_carrier'         => 'ocorrencia',
        'confrontation'         => 'ocorrencia',
        'affected_by_rain'      => 'ocorrencia',
        'ready_to_ship'         => 'postado',
        'pending'               => 'postado',
    ];

    /* =========================================================
       Regras de negócio D+1 (PURAS)
       ========================================================= */

    /**
     * Dias úteis de prazo do D+1 a partir de agora, considerando o corte:
     *   - sexta / sábado / domingo  -> 2 (entrega na terça)
     *   - seg a qui ANTES do corte  -> 1 (D+1)
     *   - seg a qui DEPOIS do corte  -> 2 (D+2)
     */
    public static function prazoD1(?int $agora = null, int $cutoffHora = 12): int
    {
        $agora = $agora ?? time();
        $w = (int)date('N', $agora); // 1=seg ... 7=dom
        $h = (int)date('G', $agora); // 0..23
        if ($w >= 5) return 2;       // sexta, sábado, domingo -> terça
        return $h < $cutoffHora ? 1 : 2;
    }

    /** Data estimada de entrega (Y-m-d) somando prazoD1() dias úteis a hoje. */
    public static function entregaEstimadaD1(?int $agora = null, int $cutoffHora = 12): string
    {
        $agora = $agora ?? time();
        $dias = self::prazoD1($agora, $cutoffHora);
        $ts = strtotime('today', $agora);
        $add = 0;
        while ($add < $dias) {
            $ts = strtotime('+1 day', $ts);
            if ((int)date('N', $ts) < 6) $add++; // conta só seg-sex
        }
        return date('Y-m-d', $ts);
    }

    /**
     * CEP dentro da área D+1? Cada item de `ceps_atendidos` (separados por
     * vírgula) pode ser:
     *   - PREFIXO:  "90"  "912"  "90230"  "90230-000"  (casa por início do CEP)
     *   - FAIXA:    "90000000-91999999"  "90000-000..91999-999"  "900 a 919"
     *               (início–fim; grafias aceitas: -  ..  :  –  —  " a ")
     * Prefixos e faixas podem ser misturados. Ex.:
     *   "90000000-91999999, 92, 93010-000, 94900000-94999999"
     */
    public function atendeCep(string $cep): bool
    {
        $cep = preg_replace('/\D/', '', $cep) ?? '';
        if (strlen($cep) !== 8) return false;
        $n = (int)$cep;

        foreach ($this->cepsAtendidos() as $entrada) {
            $entrada = trim((string)$entrada);
            if ($entrada === '') continue;

            [$ini, $fim] = self::faixaCep($entrada);
            if ($ini !== null) {
                if ($n >= $ini && $n <= $fim) return true;      // faixa
            } else {
                $pref = preg_replace('/\D/', '', $entrada) ?? '';
                if ($pref !== '' && str_starts_with($cep, $pref)) return true; // prefixo
            }
        }
        return false;
    }

    /**
     * Interpreta uma entrada como faixa "A..B". Retorna [iniInt, fimInt] (CEPs
     * de 8 dígitos como inteiros) ou [null, null] quando é prefixo/CEP único.
     * O início é completado com zeros à direita e o fim com noves — então
     * "900 a 919" vira 90000000..91999999.
     */
    private static function faixaCep(string $entrada): array
    {
        $entrada = trim($entrada);
        if ($entrada === '') return [null, null];
        // CEP único formatado (12345-678) é PREFIXO, não faixa.
        if (preg_match('/^\d{5}-\d{3}$/', $entrada)) return [null, null];

        $partes = preg_split('/\s*(?:\.\.|:|–|—|-|\s+a\s+)\s*/u', $entrada);
        if (is_array($partes) && count($partes) === 2 && trim($partes[0]) !== '' && trim($partes[1]) !== '') {
            $a = preg_replace('/\D/', '', $partes[0]) ?? '';
            $b = preg_replace('/\D/', '', $partes[1]) ?? '';
            if ($a !== '' && $b !== '') {
                $ini = (int)str_pad(substr($a, 0, 8), 8, '0');
                $fim = (int)str_pad(substr($b, 0, 8), 8, '9');
                if ($fim < $ini) { $t = $ini; $ini = $fim; $fim = $t; }
                return [$ini, $fim];
            }
        }
        return [null, null];
    }

    private function cepsAtendidos(): array
    {
        $c = $this->config['ceps_atendidos'] ?? [];
        if (is_string($c)) $c = array_filter(array_map('trim', explode(',', $c)));
        return is_array($c) ? $c : [];
    }

    private function cutoff(): int
    {
        return (int)($this->config['cutoff_hora'] ?? 12);
    }

    /* =========================================================
       Cotação (via REGRAS — sem chamar a LogManager)
       ========================================================= */

    public function cotar(array $params): array
    {
        $cepDestino = preg_replace('/\D/', '', (string)($params['cep_destino'] ?? '')) ?? '';
        if (strlen($cepDestino) !== 8) return ['ok' => true, 'opcoes' => []];
        if (!$this->atendeCep($cepDestino)) return ['ok' => true, 'opcoes' => []]; // fora da área D+1

        $base = $this->aplicarMargem((float)($this->config['d1_valor_base'] ?? 19.90));
        $prazo = self::prazoD1(null, $this->cutoff());

        return ['ok' => true, 'opcoes' => [[
            'servico_codigo' => 'D1',
            'servico_nome'   => (string)($this->config['d1_nome'] ?? 'Entrega rápida'),
            'prazo_dias'     => $prazo,
            'valor'          => $base,
            'tipo_postagem'  => 'entrega',
            'categoria'      => 'd1',
            'avisos'         => [],
            
        ]]];
    }

    /* =========================================================
       Etiqueta (envio do pedido)  —  POST /api/integrations/erp/callback
       ========================================================= */

    public function gerarEtiqueta(array $params): array
    {
        $dest = is_array($params['destinatario'] ?? null) ? $params['destinatario'] : [];
        if (!$dest) return ['ok' => false, 'erro' => 'Destinatário ausente para a etiqueta.'];

        // idEnvio: identificador único do envio no meu sistema (idempotência).
        $idEnvio = (string)($params['id_envio'] ?? $params['me_order_id'] ?? $params['idempotency_key'] ?? '');
        if ($idEnvio === '') $idEnvio = 'lm-' . substr(sha1(json_encode($dest) . microtime(true)), 0, 16);

        $payload = [
            'idVenda'                    => (string)($params['id_venda'] ?? $params['pedido_codigo'] ?? $idEnvio),
            'idEnvio'                    => $idEnvio,
            'codigointerno'              => (string)($params['codigo_interno'] ?? $params['pedido_id'] ?? ''),
            'dtCriacao'                  => date('Y-m-d H:i:s'),
            // A doc trata vlFrete/vlPago como inteiros (reais). Se sua conta aceitar
            // decimais, troque por (float) — o resto do fluxo não muda.
            'vlFrete'                    => (int)round((float)($params['valor_frete'] ?? 0)),
            'vlPago'                     => (int)round((float)($params['valor_pago'] ?? $params['valor'] ?? 0)),
            'nomeComprador'              => (string)($dest['nome'] ?? $dest['name'] ?? ''),
            'enderecoEntrega'            => (string)($dest['logradouro'] ?? $dest['address'] ?? ''),
            'enderecoEntregaNumero'      => (string)($dest['numero'] ?? $dest['number'] ?? ''),
            'enderecoEntregaComplemento' => (string)($dest['complemento'] ?? $dest['complement'] ?? ''),
            'cepEntrega'                 => $this->cepFmt($dest['cep'] ?? $dest['postal_code'] ?? ''),
            'cidadeEntrega'              => (string)($dest['cidade'] ?? $dest['city'] ?? ''),
            'estadoEntrega'              => (string)($dest['uf'] ?? $dest['state_abbr'] ?? ''),
            'bairroEntrega'              => (string)($dest['bairro'] ?? $dest['district'] ?? ''),
            'comentarios'                => (string)($params['comentarios'] ?? $params['observacao'] ?? ''),
            'telefoneComprador'          => (string)($dest['telefone'] ?? $dest['phone'] ?? ''),
            'telefoneComprador_1'        => (string)($dest['telefone2'] ?? $dest['celular'] ?? ''),
            'chaveNFE'                   => (string)($params['nota_fiscal_chave'] ?? ''),
            'numeroNFE'                  => (string)($params['nota_fiscal_numero'] ?? ''),
            'serieNFE'                   => (string)($params['nota_fiscal_serie'] ?? ''),
            'itens'                      => $this->itensPayload($params),
        ];

        $r = $this->req('POST', $this->url('/api/integrations/erp/callback'), $payload, $this->headers(), 'gerar_etiqueta', $params['referencia_id'] ?? null);
        if (!$this->ok($r)) {
            return ['ok' => false, 'erro' => $this->erroDe($r, 'Falha ao enviar pedido à LogManager')];
        }
        $j = $r['json'] ?? [];
        $ref = (string)($j['ref_logmanager_id'] ?? '');
        if (empty($j['success']) && $ref === '') {
            return ['ok' => false, 'erro' => $this->erroDe($r, 'LogManager não confirmou o envio')];
        }
        return [
            'ok'              => true,
            'external_id'     => $ref,
            'codigo_rastreio' => $ref, // o rastreio é feito pelo ref_logmanager_id
            'url_pdf'         => $j['label_url'] ?? null,
            'tracking_url'    => $j['tracking_url'] ?? null,
            'valor'           => (float)($params['valor_frete'] ?? 0),
        ];
    }

    public function imprimirEtiqueta(array $externalIds, string $modo = 'private'): array
    {
        $ids = array_values(array_filter(array_map('strval', $externalIds)));
        if (!$ids) return ['ok' => false, 'erro' => 'Nenhuma etiqueta informada para impressão.'];
        // A LogManager não tem impressão em lote: a etiqueta é uma URL por pedido,
        // obtida na consulta. Devolvemos a URL do primeiro id (reimpressão unitária).
        $r = $this->req('GET', $this->url('/api/integrations/erp/shipment/' . rawurlencode($ids[0])), null, $this->headers(), 'imprimir_etiqueta');
        if ($this->ok($r)) {
            $url = $r['json']['order']['label_url'] ?? null;
            if ($url) return ['ok' => true, 'url_pdf' => $url];
        }
        return ['ok' => false, 'erro' => $this->erroDe($r, 'Não foi possível obter a etiqueta na LogManager')];
    }

    public function cancelarEtiqueta(string $externalId): array
    {
        // A API de Vendedor não expõe cancelamento; é feito pelo painel.
        return ['ok' => false, 'erro' => 'Cancelamento não disponível na API LogManager. Cancele pelo painel da LogManager.'];
    }

    /* =========================================================
       Rastreio  —  GET /api/integrations/erp/shipment/{id}
       ========================================================= */

    public function rastrear(string $codigo): array
    {
        $r = $this->req('GET', $this->url('/api/integrations/erp/shipment/' . rawurlencode($codigo)), null, $this->headers(), 'rastrear');
        if (!$this->ok($r)) {
            $msg = ((int)($r['status'] ?? 0) === 404)
                ? 'Pedido não encontrado na LogManager.'
                : $this->erroDe($r, 'Falha ao consultar na LogManager');
            return ['ok' => false, 'erro' => $msg];
        }
        $j = $r['json'] ?? [];
        $order = is_array($j['order'] ?? null) ? $j['order'] : [];
        $lista = is_array($j['status'] ?? null) ? $j['status'] : [];

        $eventos = [];
        foreach ($lista as $ev) {
            $code = (string)($ev['status'] ?? '');
            $eventos[] = [
                'data'                  => (string)($ev['date'] ?? ''),
                'status_transportadora' => $code,
                'status_interno'        => $this->mapearStatus($code),
                'descricao'             => (string)($ev['translated_status'] ?? $code),
                'local'                 => $this->localDe($ev),
            ];
        }
        // A LogManager devolve do mais recente para o mais antigo; deixo cronológico.
        $eventos = array_reverse($eventos);

        $atual = (string)($order['status'] ?? ($lista[0]['status'] ?? ''));
        return [
            'ok'               => true,
            'status_interno'   => $atual !== '' ? $this->mapearStatus($atual) : null,
            'previsao_entrega' => $order['date_estimated_delivery'] ?? null,
            'eventos'          => $eventos,
            'codigo_rastreio'  => $codigo,
        ];
    }

    public function gerarReversa(array $params): array
    {
        return ['ok' => false, 'erro' => 'Logística reversa não disponível na LogManager nesta integração.'];
    }

    public function testarConexao(): array
    {
        if ($this->token() === '') return ['ok' => false, 'mensagem' => 'Token da LogManager não configurado.'];
        // Sem endpoint de ping: consultamos um id inexistente.
        //   404 => autenticado (token OK);  401/403 => token inválido.
        $r = $this->req('GET', $this->url('/api/integrations/erp/shipment/__ping__'), null, $this->headers(), 'testar_conexao');
        $s = (int)($r['status'] ?? 0);
        if ($s === 404) return ['ok' => true, 'mensagem' => 'Conexão com a LogManager OK (token válido).'];
        if ($s === 401 || $s === 403) return ['ok' => false, 'mensagem' => 'Token da LogManager inválido ou sem permissão.'];
        if ($this->ok($r)) return ['ok' => true, 'mensagem' => 'Conexão com a LogManager OK.'];
        return ['ok' => false, 'mensagem' => $this->erroDe($r, 'Falha ao conectar na LogManager')];
    }

    /* =========================================================
       Status / webhook
       ========================================================= */

    /** Converte um status da LogManager no estado interno (usa parent como fallback). */
    public function mapearStatus(string $statusCru): string
    {
        $k = strtolower(trim($statusCru));
        return self::MAPA_LM[$k] ?? parent::mapearStatus($statusCru);
    }

    /** Igual ao acima, porém estático (para uso no handler do webhook). */
    public static function statusInternoDe(string $code): string
    {
        $k = strtolower(trim($code));
        return self::MAPA_LM[$k] ?? 'ocorrencia';
    }

    /**
     * Normaliza o corpo do webhook da LogManager num evento de rastreio.
     * Corpo: { carrierId:'LOGMANAGER', orderId, status, processedAt, lat, long,
     *          receiver_name, receiver_grau, receiver_doc, url_comprovante }
     */
    public static function eventoDeWebhook(array $payload): array
    {
        $code = (string)($payload['status'] ?? '');
        return [
            'order_id'              => (string)($payload['orderId'] ?? ''),
            'data'                  => (string)($payload['processedAt'] ?? date('Y-m-d H:i:s')),
            'status_transportadora' => $code,
            'status_interno'        => self::statusInternoDe($code),
            'descricao'             => $code,
            'recebedor'             => $payload['receiver_name'] ?? null,
            'comprovante'           => $payload['url_comprovante'] ?? null,
        ];
    }

    /* =========================================================
       Infra
       ========================================================= */

    private function baseUrl(): string
    {
        $u = trim((string)($this->config['base_url'] ?? ''));
        if ($u === '') $u = 'https://app.logmanager.com.br';
        return rtrim($u, '/');
    }

    private function url(string $path): string
    {
        return $this->baseUrl() . $path;
    }

    private function token(): string
    {
        return (string)($this->config['token'] ?? $this->config['api_key'] ?? '');
    }

    private function headers(): array
    {
        $h = ['Accept: application/json', 'Content-Type: application/json'];
        $tk = $this->token();
        if ($tk !== '') $h[] = 'Authorization: Bearer ' . $tk;
        return $h;
    }

    private function cepFmt(string $cep): string
    {
        $d = preg_replace('/\D/', '', $cep) ?? '';
        return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : $cep;
    }

    private function localDe(array $ev): string
    {
        $lat = $ev['lat'] ?? null;
        $long = $ev['long'] ?? null;
        return ($lat && $long) ? "{$lat}, {$long}" : (string)($ev['local'] ?? '');
    }

    /** Monta itens[] no formato da LogManager (dimensões em cm, peso em kg). */
    private function itensPayload(array $params): array
    {
        $produtos = is_array($params['produtos'] ?? null) ? array_values($params['produtos']) : [];
        $volumes  = is_array($params['volumes'] ?? null) ? array_values($params['volumes']) : [];

        $out = [];
        if ($produtos) {
            foreach ($produtos as $i => $p) {
                $out[] = [
                    'quantidade' => (int)($p['quantidade'] ?? $p['quantity'] ?? 1),
                    'descricao'  => (string)($p['nome'] ?? $p['descricao'] ?? $p['name'] ?? 'Item'),
                    'dimensoes'  => $this->dimensoes($volumes[$i] ?? $volumes[0] ?? []),
                ];
            }
            return $out;
        }
        foreach ($volumes as $v) {
            $out[] = ['quantidade' => 1, 'descricao' => 'Volume', 'dimensoes' => $this->dimensoes($v)];
        }
        if (!$out) $out[] = ['quantidade' => 1, 'descricao' => 'Pedido', 'dimensoes' => $this->dimensoes([])];
        return $out;
    }

    private function dimensoes(array $v): array
    {
        $pesoG = (float)($v['peso_g'] ?? $v['peso_cobranca_g'] ?? 0);
        return [
            'largura'     => (int)round((float)($v['largura_cm'] ?? $v['largura'] ?? $v['width'] ?? 11)),
            'altura'      => (int)round((float)($v['altura_cm'] ?? $v['altura'] ?? $v['height'] ?? 2)),
            'comprimento' => (int)round((float)($v['comprimento_cm'] ?? $v['comprimento'] ?? $v['length'] ?? 16)),
            'peso'        => $pesoG > 0 ? round($pesoG / 1000, 3) : (float)($v['peso'] ?? $v['peso_kg'] ?? 0.3),
        ];
    }

    /** Chama a API, loga a comunicação e devolve o resultado bruto do HTTP. */
    private function req(string $metodo, string $url, $corpo, array $headers, string $tipo, ?int $refId = null): array
    {
        $r = $this->requisicaoHttp($metodo, $url, $corpo, $headers);
        $this->logComunicacao(
            $tipo,
            ['url' => $url, 'metodo' => $metodo, 'corpo' => is_array($corpo) ? $corpo : []],
            $r['json'] ?? ['body' => mb_substr($r['body'] ?? '', 0, 500)],
            $this->ok($r),
            $r['status'] ?? null,
            $r['ms'] ?? null,
            $refId
        );
        return $r;
    }

    private function ok(array $r): bool
    {
        $s = (int)($r['status'] ?? 0);
        return $s >= 200 && $s < 300 && empty($r['erro']);
    }

    private function erroDe(array $r, string $fallback): string
    {
        $j = $r['json'] ?? null;
        if (is_array($j)) {
            // Erro de validação da LogManager: { message, errors: { campo: [..] } }
            if (!empty($j['errors']) && is_array($j['errors'])) {
                $primeiro = reset($j['errors']);
                if (is_array($primeiro) && !empty($primeiro[0])) return (string)$primeiro[0];
            }
            foreach (['error', 'message', 'mensagem', 'erro'] as $k) {
                if (!empty($j[$k]) && is_string($j[$k])) return $j[$k];
            }
        }
        if (!empty($r['erro'])) return (string)$r['erro'];
        $s = (int)($r['status'] ?? 0);
        return $s ? "{$fallback} (HTTP {$s})" : $fallback;
    }
}