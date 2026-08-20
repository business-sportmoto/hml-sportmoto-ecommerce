<?php
/**
 * EtiquetaService — ciclo de vida das etiquetas de postagem.
 *
 * Ciclo: criar (registro, status aguardando_postagem) -> comprar
 * (adapter.gerarEtiqueta: cart -> checkout -> generate -> print) ->
 * imprimir (reimpressão) -> cancelar. Lote e manifesto (PLP) agrupam
 * etiquetas emitidas da mesma transportadora num único PDF.
 *
 * IDEMPOTÊNCIA PONTA A PONTA:
 *  - idempotencyKey (SHA-256 determinístico de pedido+transportadora+serviço+
 *    volumes) + UNIQUE em log_etiquetas: mesma combinação nunca vira 2 compras.
 *  - Retomada: se a compra falhou DEPOIS do carrinho (external_id já existe),
 *    comprar() reenvia com me_order_id e o adapter conclui as etapas pendentes
 *    sem recarrinhar (sem cobrar de novo).
 *
 * Os métodos de DECISÃO (idempotencyKey, acoesPermitidas, statusAposCompra,
 * montarParamsEtiqueta) são PUROS — testáveis sem banco.
 *
 * NOTA de persistência: a coluna `volumes` (JSON) guarda um objeto
 * {volumes, produtos, valor_declarado} para carregar tudo que o carrinho ME
 * precisa entre criar() e comprar() sem ALTER TABLE. montarParamsEtiqueta lê
 * tanto o objeto quanto um array puro (compatível).
 */
class EtiquetaService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       DECISÃO (puro)
       ================================================================= */

    public static function idempotencyKey(?int $pedidoId, int $transportadoraId, string $servico, array $volumes): string
    {
        $vs = array_map(static fn($v) => [
            'a' => (float)($v['altura_cm'] ?? $v['altura'] ?? 0),
            'l' => (float)($v['largura_cm'] ?? $v['largura'] ?? 0),
            'c' => (float)($v['comprimento_cm'] ?? $v['comprimento'] ?? 0),
            'p' => (int)($v['peso_g'] ?? $v['peso_cobranca_g'] ?? $v['peso'] ?? 0),
        ], $volumes);
        usort($vs, static fn($x, $y) => [$x['a'], $x['l'], $x['c'], $x['p']] <=> [$y['a'], $y['l'], $y['c'], $y['p']]);
        $base = json_encode(['p' => $pedidoId ?: 0, 't' => $transportadoraId, 's' => $servico, 'v' => $vs], JSON_UNESCAPED_UNICODE);
        return hash('sha256', (string)$base);
    }

    /** Ações válidas de acordo com o status (guarda de transição + UI). */
    public static function acoesPermitidas(string $status): array
    {
        return match ($status) {
            'aguardando_postagem' => ['comprar', 'cancelar'],
            'emitida'             => ['imprimir', 'cancelar'],
            'postada'             => ['imprimir'],
            'erro'                => ['comprar', 'remover'],
            'cancelada'           => ['remover'],
            default               => [],
        };
    }

    /** Campos a atualizar após uma tentativa de compra (inclui retomada). */
    public static function statusAposCompra(array $atual, array $r): array
    {
        if (!empty($r['ok'])) {
            return [
                'status'          => 'emitida',
                'external_id'     => $r['external_id'] ?? ($atual['external_id'] ?? null),
                'codigo_rastreio' => $r['codigo_rastreio'] ?? ($atual['codigo_rastreio'] ?? null),
                'url_pdf'         => $r['url_pdf'] ?? ($atual['url_pdf'] ?? null),
                'valor'           => isset($r['valor']) ? round((float)$r['valor'], 2) : ($atual['valor'] ?? null),
                'contrato'        => $r['contrato'] ?? ($atual['contrato'] ?? null),
                'erro'            => null,
            ];
        }
        // Falha: se o carrinho já gerou order id, preserva p/ retomar (segue aguardando).
        $ext = $r['external_id'] ?? ($atual['external_id'] ?? null);
        return [
            'status'      => $ext ? 'aguardando_postagem' : 'erro',
            'external_id' => $ext,
            'erro'        => mb_substr((string)($r['erro'] ?? 'Falha ao gerar etiqueta'), 0, 255),
        ];
    }

    /** Monta os params do adapter a partir do registro persistido. */
    public static function montarParamsEtiqueta(array $e, array $extra = []): array
    {
        $dec = static fn($j) => is_array($j) ? $j : (json_decode((string)$j, true) ?: []);
        $volPayload = $dec($e['volumes'] ?? []);
        $volumes  = $volPayload['volumes'] ?? (isset($volPayload[0]) ? $volPayload : []);
        $produtos = $volPayload['produtos'] ?? [];
        $declarado = (float)($volPayload['valor_declarado'] ?? 0);

        return array_merge([
            'servico_codigo'    => (string)($e['servico_codigo'] ?? ''),
            'remetente'         => $dec($e['remetente'] ?? []),
            'destinatario'      => $dec($e['destinatario'] ?? []),
            'volumes'           => $volumes,
            'produtos'          => $produtos,
            'valor'             => $declarado,
            'valor_frete'       => (float)($e['valor'] ?? 0),
            'me_order_id'       => (string)($e['external_id'] ?? ''), // retomada idempotente
            'nota_fiscal_chave' => $e['nota_fiscal_chave'] ?? null,
        ], $extra);
    }

    /* =================================================================
       CRIAÇÃO (idempotente)
       ================================================================= */

    public function criar(array $d, ?int $usuarioId = null): array
    {
        $transportadoraId = (int)($d['transportadora_id'] ?? 0);
        $servico = trim((string)($d['servico_codigo'] ?? ''));
        $destinatario = is_array($d['destinatario'] ?? null) ? $d['destinatario'] : [];
        $volumes = is_array($d['volumes'] ?? null) ? array_values($d['volumes']) : [];

        if ($transportadoraId <= 0) return ['ok' => false, 'erro' => 'Transportadora não informada.'];
        if ($servico === '')       return ['ok' => false, 'erro' => 'Serviço da transportadora não informado.'];
        if (empty($destinatario))  return ['ok' => false, 'erro' => 'Destinatário ausente.'];
        if (empty($volumes))       return ['ok' => false, 'erro' => 'Informe ao menos um volume.'];

        $pedidoId = !empty($d['pedido_id']) ? (int)$d['pedido_id'] : null;
        $chave = self::idempotencyKey($pedidoId, $transportadoraId, $servico, $volumes);

        // Idempotência: já existe uma etiqueta viva com a mesma chave?
        try {
            $st = $this->pdo->prepare("SELECT id, status FROM log_etiquetas WHERE idempotency_key = :k AND status <> 'cancelada' LIMIT 1");
            $st->execute([':k' => $chave]);
            if ($ex = $st->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => true, 'id' => (int)$ex['id'], 'status' => $ex['status'], 'idempotente' => true];
            }
        } catch (\Throwable $e) {
            LogService::error('Falha ao checar idempotência de etiqueta', ['erro' => $e->getMessage()]);
        }

        $pesoTotal = (int)($d['peso_total_g'] ?? 0);
        if ($pesoTotal <= 0) {
            foreach ($volumes as $v) $pesoTotal += (int)($v['peso_cobranca_g'] ?? $v['peso_g'] ?? 0);
        }
        $volPayload = [
            'volumes'         => $volumes,
            'produtos'        => is_array($d['produtos'] ?? null) ? $d['produtos'] : [],
            'valor_declarado' => round((float)($d['valor_declarado'] ?? $d['valor_mercadoria'] ?? 0), 2),
        ];
        $formato = in_array($d['formato'] ?? '', ['termica', 'a4', 'pdf'], true) ? $d['formato'] : 'pdf';

        try {
            $st = $this->pdo->prepare(
                "INSERT INTO log_etiquetas
                 (pedido_id, cotacao_id, transportadora_id, servico_codigo, servico_nome, canal, status,
                  remetente, destinatario, volumes, peso_total_g, formato, idempotency_key, usuario_id)
                 VALUES (:ped, :cot, :tid, :sc, :sn, :canal, 'aguardando_postagem',
                  :rem, :dest, :vol, :peso, :fmt, :key, :usr)"
            );
            $st->execute([
                ':ped'   => $pedidoId,
                ':cot'   => !empty($d['cotacao_id']) ? (int)$d['cotacao_id'] : null,
                ':tid'   => $transportadoraId,
                ':sc'    => $servico,
                ':sn'    => $d['servico_nome'] ?? null,
                ':canal' => (string)($d['canal'] ?? 'site'),
                ':rem'   => json_encode(is_array($d['remetente'] ?? null) ? $d['remetente'] : [], JSON_UNESCAPED_UNICODE),
                ':dest'  => json_encode($destinatario, JSON_UNESCAPED_UNICODE),
                ':vol'   => json_encode($volPayload, JSON_UNESCAPED_UNICODE),
                ':peso'  => $pesoTotal,
                ':fmt'   => $formato,
                ':key'   => $chave,
                ':usr'   => $usuarioId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            // Corrida na UNIQUE: recupera a existente.
            try {
                $st = $this->pdo->prepare("SELECT id, status FROM log_etiquetas WHERE idempotency_key = :k LIMIT 1");
                $st->execute([':k' => $chave]);
                if ($ex = $st->fetch(PDO::FETCH_ASSOC)) {
                    return ['ok' => true, 'id' => (int)$ex['id'], 'status' => $ex['status'], 'idempotente' => true];
                }
            } catch (\Throwable $e2) { /* cai no erro abaixo */ }
            LogService::error('Falha ao criar etiqueta', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao criar etiqueta.'];
        }

        $this->evento($id, 'criada', 'Etiqueta registrada (aguardando postagem)', $usuarioId);
        LogService::audit('Etiqueta criada', ['etiqueta_id' => $id, 'pedido_id' => $pedidoId, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id, 'status' => 'aguardando_postagem'];
    }

    /**
     * Registra uma etiqueta JÁ GERADA (usada pela reversa). Diferente de criar()+
     * comprar(): a emissão aconteceu fora (adapter.gerarReversa), aqui só
     * persistimos o resultado como um log_etiquetas emitido (canal 'reversa').
     * Idempotente pelo external_id.
     */
    public function registrarReversa(array $d, ?int $usuarioId = null): array
    {
        $externalId = trim((string)($d['external_id'] ?? ''));
        $transportadoraId = (int)($d['transportadora_id'] ?? 0);
        if ($externalId === '') return ['ok' => false, 'erro' => 'Etiqueta reversa sem identificador externo.'];

        $chave = hash('sha256', 'reversa:' . $externalId);

        try {
            $st = $this->pdo->prepare("SELECT id FROM log_etiquetas WHERE idempotency_key = :k LIMIT 1");
            $st->execute([':k' => $chave]);
            if ($ex = (int)$st->fetchColumn()) {
                return ['ok' => true, 'id' => $ex, 'reutilizada' => true];
            }
        } catch (\Throwable $e) { /* segue para o insert */ }

        $volumes = is_array($d['volumes'] ?? null) ? array_values($d['volumes']) : [];
        $peso = 0; foreach ($volumes as $v) $peso += (int)($v['peso_cobranca_g'] ?? $v['peso_g'] ?? 0);
        if ($peso <= 0) $peso = 300; // reversa é pesada na agência; evita registro com peso zero
        $volPayload = ['volumes' => $volumes, 'produtos' => [], 'valor_declarado' => round((float)($d['valor_declarado'] ?? 0), 2)];

        try {
            $st = $this->pdo->prepare(
                "INSERT INTO log_etiquetas
                 (pedido_id, transportadora_id, servico_codigo, servico_nome, canal, status,
                  remetente, destinatario, volumes, peso_total_g, url_pdf, codigo_rastreio, external_id,
                  valor, contrato, formato, idempotency_key, usuario_id)
                 VALUES (:ped, :tid, :sc, :sn, 'reversa', 'emitida',
                  :rem, :dest, :vol, :peso, :pdf, :cod, :ext, :val, :ctr, :fmt, :key, :usr)"
            );
            $st->execute([
                ':ped'  => !empty($d['pedido_id']) ? (int)$d['pedido_id'] : null,
                ':tid'  => $transportadoraId ?: null,
                ':sc'   => (string)($d['servico_codigo'] ?? ''),
                ':sn'   => $d['servico_nome'] ?? 'Reversa',
                ':rem'  => json_encode(is_array($d['remetente'] ?? null) ? $d['remetente'] : [], JSON_UNESCAPED_UNICODE),
                ':dest' => json_encode(is_array($d['destinatario'] ?? null) ? $d['destinatario'] : [], JSON_UNESCAPED_UNICODE),
                ':vol'  => json_encode($volPayload, JSON_UNESCAPED_UNICODE),
                ':peso' => $peso,
                ':pdf'  => $d['url_pdf'] ?? null,
                ':cod'  => $d['codigo_rastreio'] ?? null,
                ':ext'  => $externalId,
                ':val'  => isset($d['valor']) ? round((float)$d['valor'], 2) : null,
                ':ctr'  => $d['contrato'] ?? null,
                ':fmt'  => in_array($d['formato'] ?? '', ['termica', 'a4', 'pdf'], true) ? $d['formato'] : 'pdf',
                ':key'  => $chave,
                ':usr'  => $usuarioId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            LogService::error('Falha ao registrar etiqueta reversa', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao registrar etiqueta reversa.'];
        }

        $this->evento($id, 'comprada', 'Etiqueta reversa gerada' . (!empty($d['codigo_rastreio']) ? ' — ' . $d['codigo_rastreio'] : ''), $usuarioId);
        return ['ok' => true, 'id' => $id];
    }

    /* =================================================================
       COMPRA (cart -> checkout -> generate -> print) com retomada
       ================================================================= */

    public function comprar(int $id, ?int $usuarioId = null): array
    {
        $e = $this->obter($id);
        if (!$e) return ['ok' => false, 'erro' => 'Etiqueta não encontrada.'];
        if (!in_array('comprar', self::acoesPermitidas((string)$e['status']), true)) {
            return ['ok' => false, 'erro' => 'Etiqueta não está em um estado que permita compra.'];
        }

        $adapter = $this->resolverAdapter((int)$e['transportadora_id']);
        if (!$adapter) return ['ok' => false, 'erro' => 'Transportadora indisponível para emissão.'];

        $r = $adapter->gerarEtiqueta(self::montarParamsEtiqueta($e));
        $upd = self::statusAposCompra($e, $r);
        $this->aplicarUpdate($id, $upd);

        if (!empty($r['ok'])) {
            $this->evento($id, 'comprada', 'Etiqueta emitida' . (!empty($r['codigo_rastreio']) ? ' — ' . $r['codigo_rastreio'] : ''), $usuarioId);
            LogService::audit('Etiqueta comprada', ['etiqueta_id' => $id, 'external_id' => $upd['external_id'] ?? null, 'usuario_id' => $usuarioId]);
            return ['ok' => true, 'status' => 'emitida', 'url_pdf' => $upd['url_pdf'] ?? null, 'codigo_rastreio' => $upd['codigo_rastreio'] ?? null];
        }

        $this->evento($id, 'erro', ($r['etapa'] ?? '') . ': ' . ($r['erro'] ?? 'falha'), $usuarioId);
        return ['ok' => false, 'status' => $upd['status'], 'erro' => $r['erro'] ?? 'Falha ao gerar etiqueta.', 'etapa' => $r['etapa'] ?? null];
    }

    public function comprarLote(array $ids, ?int $usuarioId = null): array
    {
        $ok = 0; $falha = 0; $res = [];
        foreach ($ids as $rid) {
            $r = $this->comprar((int)$rid, $usuarioId);
            $res[(int)$rid] = $r;
            !empty($r['ok']) ? $ok++ : $falha++;
        }
        return ['ok' => $falha === 0, 'compradas' => $ok, 'falhas' => $falha, 'resultados' => $res];
    }

    /* =================================================================
       IMPRESSÃO / REIMPRESSÃO
       ================================================================= */

    public function imprimir(int $id, ?int $usuarioId = null): array
    {
        $e = $this->obter($id);
        if (!$e) return ['ok' => false, 'erro' => 'Etiqueta não encontrada.'];
        if (empty($e['external_id'])) return ['ok' => false, 'erro' => 'Etiqueta ainda não emitida.'];

        // Rótulo já salvo localmente (Correios)? Serve SEMPRE pela rota — corrige
        // URLs antigas que apontavam direto para /storage (caminho não público).
        if ($this->pdfDoRotulo($id) !== null) {
            $url = $this->urlRotaRotulo($id);
            if (($e['url_pdf'] ?? '') !== $url) $this->aplicarUpdate($id, ['url_pdf' => $url]);
            $this->evento($id, 'reimpressa', null, $usuarioId);
            return ['ok' => true, 'url_pdf' => $url];
        }

        // PDF externo já salvo (ex.: Melhor Envio) — mas ignora caminho velho de /storage.
        $urlSalva = (string)($e['url_pdf'] ?? '');
        if ($urlSalva !== '' && str_starts_with($urlSalva, 'http') && !str_contains($urlSalva, '/storage/logistica/rotulos/')) {
            $this->evento($id, 'reimpressa', null, $usuarioId);
            return ['ok' => true, 'url_pdf' => $urlSalva];
        }

        $adapter = $this->resolverAdapter((int)$e['transportadora_id']);
        if (!$adapter) return ['ok' => false, 'erro' => 'Transportadora indisponível.'];

        // 2ª ETAPA: já existe um recibo pendente -> baixa o PDF por ele.
        if (!empty($e['id_recibo']) && method_exists($adapter, 'rotuloPorRecibo')) {
            $d = $adapter->rotuloPorRecibo((string)$e['id_recibo']);
            if (!empty($d['ok'])) {
                $url = $this->urlDoRotulo($id, $d);
                if ($url) {
                    $this->aplicarUpdate($id, ['url_pdf' => $url]);
                    $this->evento($id, 'impressa', null, $usuarioId);
                    return ['ok' => true, 'url_pdf' => $url];
                }
            }
            return ['ok' => false, 'processando' => !empty($d['processando']),
                    'erro' => $d['erro'] ?? 'Rótulo ainda em processamento. Tente novamente em instantes.',
                    'id_recibo' => $e['id_recibo']];
        }

        // 1ª ETAPA: solicita o rótulo (assíncrono).
        $r = $adapter->imprimirEtiqueta([(string)$e['external_id']], 'private');
        if (empty($r['ok'])) return ['ok' => false, 'erro' => $r['erro'] ?? 'Falha ao solicitar o rótulo.'];

        // PDF já veio na hora?
        $url = $this->urlDoRotulo($id, $r);
        if ($url) {
            $this->aplicarUpdate($id, ['url_pdf' => $url]);
            $this->evento($id, 'impressa', null, $usuarioId);
            return ['ok' => true, 'url_pdf' => $url];
        }

        // Guarda o recibo para a 2ª etapa.
        if (!empty($r['id_recibo'])) {
            $this->aplicarUpdate($id, ['id_recibo' => (string)$r['id_recibo']]);
            return ['ok' => false, 'processando' => true, 'id_recibo' => $r['id_recibo'],
                    'erro' => 'Rótulo solicitado. Clique em "Baixar etiqueta" em alguns segundos.'];
        }
        return ['ok' => false, 'erro' => 'Não foi possível obter o PDF da etiqueta.'];
    }

    /** Resolve a URL do rótulo a partir do retorno do adapter (URL direta ou PDF base64 salvo em arquivo). */
    private function urlDoRotulo(int $id, array $r): ?string
    {
        if (!empty($r['url_pdf'])) return (string)$r['url_pdf'];
        if (!empty($r['pdf_base64'])) return $this->salvarPdfBase64($id, (string)$r['pdf_base64']);
        return null;
    }

    /** Diretório (NÃO público) onde os rótulos ficam guardados. */
    private function dirRotulos(): string
    {
        return defined('ROOT_PATH') ? ROOT_PATH . '/storage/logistica/rotulos' : (__DIR__ . '/../../../storage/logistica/rotulos');
    }

    /** URL da rota que faz o stream do rótulo (abre pelo controller, sem pasta pública). */
    private function urlRotaRotulo(int $id): string
    {
        $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
        return $base . '/admin/logistica/etiquetas/rotulo?id=' . $id;
    }

    /** Salva o PDF (base64) em storage e devolve a URL da ROTA que faz o stream. */
    private function salvarPdfBase64(int $id, string $base64): ?string
    {
        $bin = base64_decode($base64, true);
        if ($bin === false || $bin === '') return null;
        $dir = $this->dirRotulos();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (@file_put_contents($dir . '/etiqueta_' . $id . '.pdf', $bin) === false) return null;
        return $this->urlRotaRotulo($id);
    }

    /** Lê o PDF do rótulo já salvo (para o controller fazer o stream). */
    public function pdfDoRotulo(int $id): ?string
    {
        $f = $this->dirRotulos() . '/etiqueta_' . $id . '.pdf';
        return is_file($f) ? ((@file_get_contents($f)) ?: null) : null;
    }

    /* =================================================================
       CANCELAMENTO
       ================================================================= */

    /**
     * Cancela a etiqueta APENAS localmente (status -> cancelada), sem chamar a
     * transportadora. Fallback para quando o cancelamento remoto falha/indisponível,
     * evitando que a etiqueta fique presa em 'emitida'. Registra que foi forçado.
     */
    public function forcarCancelamentoLocal(int $id, ?int $usuarioId = null, string $motivo = 'Cancelamento forçado localmente'): array
    {
        $this->aplicarUpdate($id, ['status' => 'cancelada']);
        $this->evento($id, 'cancelada', $motivo, $usuarioId);
        LogService::audit('Etiqueta cancelada localmente (forçado)', ['etiqueta_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /**
     * CRON: busca o preço/dados reais da POSTAGEM das etiquetas Correios já postadas
     * (GET /prepostagens/postada). Só toca em etiquetas Correios, com rastreio, ainda
     * sem valor_postado, não canceladas e recentes. Se o objeto ainda não foi postado,
     * a API responde "não postada" e a etiqueta é tentada de novo na próxima rodada.
     *
     * @return array{ok:bool, consultadas:int, atualizadas:int, pendentes:int}
     */
    public function atualizarPrecosPostagem(int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        try {
            $st = $this->pdo->prepare(
                "SELECT e.id, e.codigo_rastreio, e.transportadora_id
                   FROM log_etiquetas e
                   JOIN log_transportadoras t ON t.id = e.transportadora_id
                  WHERE t.adapter = 'CorreiosAdapter'
                    AND e.canal <> 'reversa'
                    AND e.valor_postado IS NULL
                    AND e.codigo_rastreio IS NOT NULL AND e.codigo_rastreio <> ''
                    AND e.status NOT IN ('cancelada', 'erro')
                    AND e.criado_em >= (NOW() - INTERVAL 120 DAY)
                  ORDER BY e.id DESC
                  LIMIT " . $limite
            );
            $st->execute();
            $linhas = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar etiquetas para preço de postagem', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'consultadas' => 0, 'atualizadas' => 0, 'pendentes' => 0];
        }

        $consultadas = 0; $atualizadas = 0; $pendentes = 0;
        $adapters = [];
        foreach ($linhas as $e) {
            $tid = (int)$e['transportadora_id'];
            if (!array_key_exists($tid, $adapters)) $adapters[$tid] = $this->resolverAdapter($tid);
            $adapter = $adapters[$tid];
            if (!$adapter || !method_exists($adapter, 'consultarPostagem')) continue;

            $consultadas++;
            try {
                $res = $adapter->consultarPostagem((string)$e['codigo_rastreio']);
            } catch (\Throwable $ex) {
                LogService::warning('Erro ao consultar postagem', ['etiqueta_id' => $e['id'], 'erro' => $ex->getMessage()]);
                continue;
            }
            if (!empty($res['nao_postada'])) { $pendentes++; continue; }
            if (empty($res['ok'])) continue;

            $campos = ['valor_postado' => round((float)($res['valor'] ?? 0), 2)];
            if (!empty($res['data_postagem']))   $campos['data_postagem']   = $res['data_postagem'];
            if (!empty($res['peso_tarifado_g'])) $campos['peso_tarifado_g'] = (int)$res['peso_tarifado_g'];
            $this->aplicarUpdate((int)$e['id'], $campos);
            LogService::audit('Preço de postagem atualizado', ['etiqueta_id' => $e['id'], 'valor_postado' => $campos['valor_postado'], 'codigo' => $e['codigo_rastreio']]);
            $atualizadas++;
        }
        return ['ok' => true, 'consultadas' => $consultadas, 'atualizadas' => $atualizadas, 'pendentes' => $pendentes];
    }

    public function cancelar(int $id, ?int $usuarioId = null): array
    {
        $e = $this->obter($id);
        if (!$e) return ['ok' => false, 'erro' => 'Etiqueta não encontrada.'];
        if (!in_array('cancelar', self::acoesPermitidas((string)$e['status']), true)) {
            return ['ok' => false, 'erro' => 'Etiqueta não pode ser cancelada neste estado.'];
        }

        // Ainda não comprada (sem order na transportadora): cancela só localmente.
        if (empty($e['external_id'])) {
            $this->aplicarUpdate($id, ['status' => 'cancelada']);
            $this->evento($id, 'cancelada', 'Cancelada antes da emissão', $usuarioId);
            LogService::audit('Etiqueta cancelada (pré-emissão)', ['etiqueta_id' => $id, 'usuario_id' => $usuarioId]);
            return ['ok' => true];
        }

        $adapter = $this->resolverAdapter((int)$e['transportadora_id']);
        if (!$adapter) return ['ok' => false, 'erro' => 'Transportadora indisponível para cancelamento.'];

        $r = $adapter->cancelarEtiqueta((string)$e['external_id']);
        if (empty($r['ok'])) {
            $this->evento($id, 'erro', 'Cancelamento: ' . ($r['erro'] ?? 'falha'), $usuarioId);
            return ['ok' => false, 'erro' => $r['erro'] ?? 'Falha ao cancelar na transportadora.'];
        }
        $this->aplicarUpdate($id, ['status' => 'cancelada']);
        $this->evento($id, 'cancelada', 'Cancelada na transportadora', $usuarioId);
        LogService::audit('Etiqueta cancelada', ['etiqueta_id' => $id, 'external_id' => $e['external_id'], 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /* =================================================================
       MANIFESTO / PLP (lote de impressão)
       ================================================================= */

    public function gerarManifesto(array $ids, ?int $usuarioId = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return ['ok' => false, 'erro' => 'Selecione etiquetas para o manifesto.'];

        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $this->pdo->prepare("SELECT id, transportadora_id, external_id FROM log_etiquetas WHERE id IN ($in) AND status = 'emitida' AND external_id IS NOT NULL");
            $st->execute($ids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao carregar etiquetas.'];
        }
        if (!$rows) return ['ok' => false, 'erro' => 'Nenhuma etiqueta emitida elegível na seleção.'];

        $transportadoras = array_unique(array_column($rows, 'transportadora_id'));
        if (count($transportadoras) > 1) {
            return ['ok' => false, 'erro' => 'O manifesto deve conter etiquetas de uma única transportadora.'];
        }
        $transportadoraId = (int)$transportadoras[0];
        $externalIds = array_column($rows, 'external_id');

        $adapter = $this->resolverAdapter($transportadoraId);
        if (!$adapter) return ['ok' => false, 'erro' => 'Transportadora indisponível.'];

        $r = $adapter->imprimirEtiqueta($externalIds, 'private');
        if (empty($r['ok'])) return ['ok' => false, 'erro' => $r['erro'] ?? 'Falha ao gerar o manifesto.'];

        try {
            $this->pdo->beginTransaction();
            $ins = $this->pdo->prepare("INSERT INTO log_plps (transportadora_id, status, url_pdf, qtd_etiquetas, fechado_em) VALUES (:t, 'fechada', :url, :q, NOW())");
            $ins->execute([':t' => $transportadoraId, ':url' => $r['url_pdf'] ?? null, ':q' => count($rows)]);
            $plpId = (int)$this->pdo->lastInsertId();
            $up = $this->pdo->prepare("UPDATE log_etiquetas SET plp_id = :p WHERE id IN ($in)");
            $up->execute(array_merge([$plpId], array_column($rows, 'id')));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            LogService::error('Falha ao registrar manifesto', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Falha ao registrar o manifesto.'];
        }

        LogService::audit('Manifesto gerado', ['plp_id' => $plpId, 'qtd' => count($rows), 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'plp_id' => $plpId, 'url_pdf' => $r['url_pdf'] ?? null, 'qtd' => count($rows)];
    }

    /* =================================================================
       LEITURA
       ================================================================= */

    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $where = []; $p = [];
        if (!empty($filtros['status']))            { $where[] = 'e.status = :st'; $p[':st'] = $filtros['status']; }
        if (!empty($filtros['transportadora_id'])) { $where[] = 'e.transportadora_id = :tid'; $p[':tid'] = (int)$filtros['transportadora_id']; }
        if (!empty($filtros['busca'])) {
            $where[] = '(e.codigo_rastreio LIKE :q OR e.external_id LIKE :q OR e.pedido_id = :qexato)';
            $p[':q'] = '%' . $filtros['busca'] . '%';
            $p[':qexato'] = ctype_digit((string)$filtros['busca']) ? (int)$filtros['busca'] : 0;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagina = max(1, $pagina); $porPagina = max(1, min(100, $porPagina));
        $off = ($pagina - 1) * $porPagina;

        try {
            $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM log_etiquetas e$sqlWhere");
            $cnt->execute($p);
            $total = (int)$cnt->fetchColumn();

            $sql = "SELECT e.*, t.nome AS transportadora_nome, t.slug AS transportadora_slug
                    FROM log_etiquetas e
                    LEFT JOIN log_transportadoras t ON t.id = e.transportadora_id
                    $sqlWhere ORDER BY e.id DESC LIMIT $porPagina OFFSET $off";
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar etiquetas', ['erro' => $e->getMessage()]);
            return ['itens' => [], 'total' => 0, 'pagina' => $pagina, 'por_pagina' => $porPagina];
        }

        foreach ($rows as &$r) {
            $r['acoes'] = self::acoesPermitidas((string)$r['status']);
        }
        return ['itens' => $rows, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    public function obter(int $id): ?array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT e.*, t.nome AS transportadora_nome, t.slug AS transportadora_slug
                 FROM log_etiquetas e LEFT JOIN log_transportadoras t ON t.id = e.transportadora_id
                 WHERE e.id = :id LIMIT 1"
            );
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            foreach (['remetente', 'destinatario', 'volumes'] as $k) {
                $r[$k . '_json'] = json_decode((string)$r[$k], true) ?: [];
            }
            $r['acoes'] = self::acoesPermitidas((string)$r['status']);
            return $r;
        } catch (\Throwable $e) {
            LogService::error('Falha ao obter etiqueta', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    public function eventos(int $id): array
    {
        try {
            $st = $this->pdo->prepare("SELECT acao, detalhe, usuario_id, criado_em FROM log_etiqueta_eventos WHERE etiqueta_id = :id ORDER BY id DESC");
            $st->execute([':id' => $id]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function remover(int $id, ?int $usuarioId = null): array
    {
        $e = $this->obter($id);
        if (!$e) return ['ok' => false, 'erro' => 'Etiqueta não encontrada.'];
        if (!in_array('remover', self::acoesPermitidas((string)$e['status']), true)) {
            return ['ok' => false, 'erro' => 'Só é possível remover etiquetas com erro ou canceladas.'];
        }
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare("DELETE FROM log_etiqueta_eventos WHERE etiqueta_id = :id")->execute([':id' => $id]);
            $this->pdo->prepare("DELETE FROM log_etiquetas WHERE id = :id")->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $ex) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok' => false, 'erro' => 'Não foi possível remover.'];
        }
        LogService::audit('Etiqueta removida', ['etiqueta_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /* =================================================================
       Internos
       ================================================================= */

    /** Sobrescrevível em teste para injetar um adapter fake. */
    protected function resolverAdapter(int $transportadoraId): ?TransportadoraInterface
    {
        $row = TransportadoraManager::porId($transportadoraId);
        if (!$row) return null;
        try {
            return TransportadoraManager::resolver($row);
        } catch (\Throwable $e) {
            LogService::error('Falha ao resolver adapter de transportadora', ['transportadora_id' => $transportadoraId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** UPDATE com allowlist de colunas. */
    private function aplicarUpdate(int $id, array $campos): void
    {
        $permitidos = ['status', 'external_id', 'codigo_rastreio', 'url_pdf', 'id_recibo', 'valor', 'valor_postado', 'data_postagem', 'peso_tarifado_g', 'contrato', 'erro', 'plp_id'];
        $sets = []; $vals = [':id' => $id];
        foreach ($campos as $k => $v) {
            if (!in_array($k, $permitidos, true)) continue;
            $sets[] = "`$k` = :$k";
            $vals[":$k"] = $v;
        }
        if (!$sets) return;
        try {
            $this->pdo->prepare("UPDATE log_etiquetas SET " . implode(', ', $sets) . " WHERE id = :id")->execute($vals);
        } catch (\Throwable $e) {
            LogService::error('Falha ao atualizar etiqueta', ['id' => $id, 'erro' => $e->getMessage()]);
        }
    }

    private function evento(int $etiquetaId, string $acao, ?string $detalhe, ?int $usuarioId): void
    {
        try {
            $this->pdo->prepare("INSERT INTO log_etiqueta_eventos (etiqueta_id, acao, detalhe, usuario_id) VALUES (:e, :a, :d, :u)")
                      ->execute([':e' => $etiquetaId, ':a' => $acao, ':d' => $detalhe ? mb_substr($detalhe, 0, 500) : null, ':u' => $usuarioId]);
        } catch (\Throwable $e) { /* evento nunca derruba a operação */ }
    }
}