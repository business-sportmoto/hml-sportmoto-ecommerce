<?php
/**
 * ApiLogisticaController — API pública v1.
 *
 * Endpoints (prefixo /api/logistica/v1):
 *   POST /cotacoes            escopo: cotar       -> CalculadoraService
 *   POST /etiquetas           escopo: etiquetas   -> EtiquetaService (cria e, por padrão, emite)
 *   GET  /etiquetas/{id}      escopo: etiquetas   -> status/PDF da etiqueta
 *   GET  /rastreios/{codigo}  escopo: rastreio    -> timeline sanitizada
 *
 * Respostas nunca expõem custo interno, IDs externos ou endereços completos.
 */
class ApiLogisticaController extends ApiController
{
    /* ---------------- POST /cotacoes ---------------- */
    public function cotar(): void
    {
        $this->exigir('cotar');
        $b = $this->corpo();

        if (empty($b['cep_destino']) || empty($b['itens']) || !is_array($b['itens'])) {
            $this->falha(422, 'dados_invalidos', 'Informe cep_destino e itens.');
        }

        try {
            $calc = new CalculadoraService();
            $res = $calc->cotar([
                'origem'       => 'api',
                'canal'        => 'api',
                'cep_destino'  => (string)$b['cep_destino'],
                'uf'           => $b['uf'] ?? null,
                'tipo_cliente' => $b['tipo_cliente'] ?? null,
                'itens'        => $b['itens'],
                'pedido_id'    => $b['pedido_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            LogService::error('API cotação falhou', ['erro' => $e->getMessage()]);
            $this->falha(502, 'falha_cotacao', 'Não foi possível cotar no momento.');
        }

        $opcoes = array_map([$this, 'opcaoPublica'], array_filter($res['opcoes'] ?? [], static fn($o) => empty($o['oculto'])));
        $this->ok(['opcoes' => array_values($opcoes)]);
    }

    /* ---------------- POST /etiquetas ---------------- */
    public function criarEtiqueta(): void
    {
        $key = $this->exigir('etiquetas');
        $b = $this->corpo();

        if (empty($b['transportadora_id']) || empty($b['servico_codigo']) || empty($b['destinatario']) || empty($b['volumes'])) {
            $this->falha(422, 'dados_invalidos', 'Informe transportadora_id, servico_codigo, destinatario e volumes.');
        }

        $comprar = array_key_exists('comprar', $b) ? (bool)$b['comprar'] : true;

        try {
            $svc = new EtiquetaService();
            $criar = $svc->criar([
                'pedido_id'         => $b['pedido_id'] ?? null,
                'transportadora_id' => (int)$b['transportadora_id'],
                'servico_codigo'    => (string)$b['servico_codigo'],
                'servico_nome'      => $b['servico_nome'] ?? null,
                'canal'             => 'api',
                'remetente'         => is_array($b['remetente'] ?? null) ? $b['remetente'] : [],
                'destinatario'      => is_array($b['destinatario']) ? $b['destinatario'] : [],
                'volumes'           => is_array($b['volumes']) ? $b['volumes'] : [],
                'produtos'          => is_array($b['produtos'] ?? null) ? $b['produtos'] : [],
                'valor_declarado'   => $b['valor_declarado'] ?? 0,
                'formato'           => $b['formato'] ?? 'pdf',
            ], null);

            if (empty($criar['ok'])) $this->falha(422, 'falha_criacao', $criar['erro'] ?? 'Não foi possível criar a etiqueta.');
            $id = (int)$criar['id'];

            if ($comprar) {
                $compra = $svc->comprar($id, null);
                if (empty($compra['ok'])) {
                    // Registro criado, emissão falhou — 202 com o id para retomar.
                    $this->ok($this->etiquetaPublica($svc->obter($id)) + ['aviso' => $compra['erro'] ?? 'Emissão pendente'], 202);
                }
                // webhook opcional
                $wh = $this->webhook();
                if (!empty($wh['url'])) WebhookService::notificar($wh, 'etiqueta.emitida', $this->etiquetaPublica($svc->obter($id)));
            }
        } catch (\Throwable $e) {
            LogService::error('API criação de etiqueta falhou', ['erro' => $e->getMessage()]);
            $this->falha(502, 'falha_etiqueta', 'Falha ao processar a etiqueta.');
        }

        $this->ok($this->etiquetaPublica($svc->obter($id)), !empty($criar['idempotente']) ? 200 : 201);
    }

    /* ---------------- GET /etiquetas/{id} ---------------- */
    public function obterEtiqueta($id = null): void
    {
        $this->exigir('etiquetas');
        $id = (int)($id ?? $this->ultimoSegmento());
        $e = $id > 0 ? (new EtiquetaService())->obter($id) : null;
        if (!$e) $this->falha(404, 'nao_encontrada', 'Etiqueta não encontrada.');
        $this->ok($this->etiquetaPublica($e));
    }

    /* ---------------- GET /rastreios/{codigo} ---------------- */
    public function rastrear($codigo = null): void
    {
        $this->exigir('rastreio');
        $codigo = (string)($codigo ?? $this->ultimoSegmento());
        $r = $codigo !== '' ? (new RastreioService())->porCodigo($codigo) : null;
        if (!$r) $this->falha(404, 'nao_encontrado', 'Rastreio não encontrado para o código informado.');
        $this->ok($r);
    }

    /* ---------------- projeções públicas ---------------- */

    private function opcaoPublica(array $o): array
    {
        return [
            'transportadora'  => $o['transportadora_nome'] ?? null,
            'servico'         => $o['servico_nome'] ?? null,
            'servico_codigo'  => $o['servico_codigo'] ?? null,
            'prazo_dias'      => isset($o['prazo_dias']) ? (int)$o['prazo_dias'] : null,
            'valor'           => isset($o['valor_final']) ? round((float)$o['valor_final'], 2) : null,
            'frete_gratis'    => !empty($o['frete_gratis']),
        ];
    }

    private function etiquetaPublica(?array $e): array
    {
        if (!$e) return [];
        return [
            'id'              => (int)$e['id'],
            'status'          => $e['status'] ?? null,
            'codigo_rastreio' => $e['codigo_rastreio'] ?? null,
            'url_pdf'         => $e['url_pdf'] ?? null,
            'transportadora'  => $e['transportadora_nome'] ?? null,
            'servico'         => $e['servico_nome'] ?? $e['servico_codigo'] ?? null,
            'formato'         => $e['formato'] ?? null,
        ];
    }

    private function ultimoSegmento(): string
    {
        $uri = explode('?', (string)($_SERVER['REQUEST_URI'] ?? ''))[0];
        $partes = array_values(array_filter(explode('/', $uri)));
        return $partes ? (string)end($partes) : '';
    }
}
