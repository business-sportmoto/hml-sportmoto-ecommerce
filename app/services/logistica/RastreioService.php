<?php
/**
 * RastreioService — rastreio normalizado dos envios.
 *
 * `log_rastreios` = estado atual (fonte única). `log_rastreio_eventos` =
 * timeline; o UNIQUE (rastreio_id, hash_evento) faz o dedup — reconsultar a
 * transportadora nunca duplica um evento (polling idempotente).
 *
 * Cadência ADAPTATIVA por status (intervaloPorStatus): "saiu para entrega"
 * consulta com frequência, "aguardando" devagar, "entregue" para. O worker
 * (cli/logistica-rastreio-worker.php) chama abrirPendentesDeEtiquetas() +
 * atualizarPendentes().
 *
 * Link PÚBLICO: token aleatório (token_publico, UNIQUE) — o cliente vê a
 * timeline sanitizada (sem IDs, custo ou endereço completo).
 *
 * CONSULTA ao adapter: o Melhor Envio rastreia pelo ORDER ID (external_id da
 * etiqueta); usamos external_id quando existe, senão o codigo_rastreio.
 *
 * Métodos de DECISÃO (hashEvento, statusFinal, intervaloPorStatus,
 * normalizarEventos, resumoDoRastreio, statusEtiquetaPorRastreio,
 * sanitizarPublico) são PUROS — testáveis sem banco.
 */
class RastreioService
{
    private PDO $pdo;

    /** Após tantos dias sem finalizar, para de fazer polling (evita fila eterna). */
    private const MAX_DIAS_POLLING = 60;

    private const LABELS = [
        'aguardando_etiqueta' => 'Aguardando etiqueta',
        'etiqueta_emitida'    => 'Etiqueta emitida',
        'postado'             => 'Postado',
        'em_transito'         => 'Em trânsito',
        'saiu_entrega'        => 'Saiu para entrega',
        'entregue'            => 'Entregue',
        'devolucao'           => 'Em devolução',
        'ocorrencia'          => 'Ocorrência',
    ];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       DECISÃO (puro)
       ================================================================= */

    public static function rotulo(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** Dedup: assinatura estável de um evento. */
    public static function hashEvento(string $data, string $statusTransp, string $statusInterno, string $local): string
    {
        return sha1($data . '|' . mb_strtolower(trim($statusTransp)) . '|' . $statusInterno . '|' . mb_strtolower(trim($local)));
    }

    /** Estado terminal — não precisa mais consultar. */
    public static function statusFinal(string $status): bool
    {
        return $status === 'entregue';
    }

    /** Cadência de polling (minutos) por status. */
    public static function intervaloPorStatus(string $status): int
    {
        return match ($status) {
            'saiu_entrega'     => 60,     // prestes a entregar → checa de hora em hora
            'postado'          => 180,
            'em_transito'      => 240,
            'ocorrencia'       => 360,
            'etiqueta_emitida' => 240,
            'devolucao'        => 480,
            'aguardando_etiqueta' => 720, // mal se moveu → devagar
            default            => 240,
        };
    }

    /** Normaliza a saída do adapter.rastrear em linhas de evento (com hash). */
    public static function normalizarEventos(array $res): array
    {
        $out = [];
        foreach (($res['eventos'] ?? []) as $ev) {
            $data = self::normalizarData((string)($ev['data'] ?? ''));
            $statusTransp = (string)($ev['status_transportadora'] ?? '');
            $statusInterno = (string)($ev['status_interno'] ?? 'ocorrencia');
            $local = (string)($ev['local'] ?? '');
            $out[] = [
                'data_evento'           => $data,
                'status_transportadora' => $statusTransp !== '' ? $statusTransp : null,
                'status_interno'        => $statusInterno,
                'descricao'             => (string)($ev['descricao'] ?? $statusTransp),
                'local'                 => $local !== '' ? $local : null,
                'hash'                  => self::hashEvento($data, $statusTransp, $statusInterno, $local),
            ];
        }
        return $out;
    }

    /**
     * Resumo do rastreio a partir dos eventos (mais o previsão atual).
     * @return array{status_interno:?string, postado_em:?string, entregue_em:?string, atraso:int, ocorrencia:int}
     */
    public static function resumoDoRastreio(array $eventos, ?string $previsao): array
    {
        if (!$eventos) {
            return ['status_interno' => null, 'postado_em' => null, 'entregue_em' => null, 'atraso' => 0, 'ocorrencia' => 0];
        }
        usort($eventos, static fn($a, $b) => strcmp((string)$a['data_evento'], (string)$b['data_evento']));

        $atual = end($eventos)['status_interno'] ?? null;
        $postadoEm = null; $entregueEm = null; $temOcorrencia = false;
        foreach ($eventos as $e) {
            if ($postadoEm === null && $e['status_interno'] === 'postado') $postadoEm = $e['data_evento'];
            if ($e['status_interno'] === 'entregue') $entregueEm = $e['data_evento'];
            if (in_array($e['status_interno'], ['ocorrencia', 'devolucao'], true)) $temOcorrencia = true;
        }
        $atrasado = 0;
        if ($previsao && $atual !== 'entregue') {
            $atrasado = (strtotime($previsao) !== false && strtotime($previsao) < strtotime(date('Y-m-d'))) ? 1 : 0;
        }
        return [
            'status_interno' => $atual,
            'postado_em'     => $postadoEm,
            'entregue_em'    => $entregueEm,
            'atraso'         => $atrasado,
            'ocorrencia'     => $temOcorrencia ? 1 : 0,
        ];
    }

    /** Reflexo no status da etiqueta quando o rastreio anda. */
    public static function statusEtiquetaPorRastreio(string $statusRastreio): ?string
    {
        return in_array($statusRastreio, ['postado', 'em_transito', 'saiu_entrega', 'entregue'], true) ? 'postada' : null;
    }

    /** View pública sanitizada (sem IDs, custo ou endereço completo). */
    public static function sanitizarPublico(array $r, array $eventos): array
    {
        $evs = array_map(static fn($e) => [
            'data_evento'    => $e['data_evento'] ?? null,
            'status_interno' => $e['status_interno'] ?? null,
            'status_label'   => self::rotulo((string)($e['status_interno'] ?? '')),
            'descricao'      => $e['descricao'] ?? null,
            'local'          => $e['local'] ?? null,
        ], $eventos);

        return [
            'codigo_rastreio'     => $r['codigo_rastreio'] ?? null,
            'transportadora_nome' => $r['transportadora_nome'] ?? null,
            'status_interno'      => $r['status_interno'] ?? null,
            'status_label'        => self::rotulo((string)($r['status_interno'] ?? '')),
            'destino_cidade'      => $r['destino_cidade'] ?? null,
            'destino_uf'          => $r['destino_uf'] ?? null,
            'previsao_entrega'    => $r['previsao_entrega'] ?? null,
            'postado_em'          => $r['postado_em'] ?? null,
            'entregue_em'         => $r['entregue_em'] ?? null,
            'atraso'              => (int)($r['atraso'] ?? 0),
            'ocorrencia'          => (int)($r['ocorrencia'] ?? 0),
            'eventos'             => $evs,
        ];
    }

    private static function normalizarData(string $d): string
    {
        $ts = strtotime($d);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
    }

    /* =================================================================
       ABERTURA (idempotente)
       ================================================================= */

    public function abrir(array $d): array
    {
        $codigo = trim((string)($d['codigo_rastreio'] ?? ''));
        $etiquetaId = !empty($d['etiqueta_id']) ? (int)$d['etiqueta_id'] : null;
        if ($codigo === '' && !$etiquetaId) return ['ok' => false, 'erro' => 'Código de rastreio ou etiqueta é obrigatório.'];

        // Idempotência: já existe rastreio para a etiqueta ou o código?
        try {
            if ($etiquetaId) {
                $st = $this->pdo->prepare("SELECT id, token_publico FROM log_rastreios WHERE etiqueta_id = :e LIMIT 1");
                $st->execute([':e' => $etiquetaId]);
                if ($ex = $st->fetch(PDO::FETCH_ASSOC)) return ['ok' => true, 'id' => (int)$ex['id'], 'token' => $ex['token_publico'], 'existente' => true];
            }
            if ($codigo !== '') {
                $st = $this->pdo->prepare("SELECT id, token_publico FROM log_rastreios WHERE codigo_rastreio = :c LIMIT 1");
                $st->execute([':c' => $codigo]);
                if ($ex = $st->fetch(PDO::FETCH_ASSOC)) return ['ok' => true, 'id' => (int)$ex['id'], 'token' => $ex['token_publico'], 'existente' => true];
            }
        } catch (\Throwable $e) {
            LogService::error('Falha ao checar rastreio existente', ['erro' => $e->getMessage()]);
        }

        $token = $this->gerarToken();
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO log_rastreios
                 (etiqueta_id, pedido_id, transportadora_id, codigo_rastreio, canal, status_interno,
                  destinatario_nome, destino_cidade, destino_uf, previsao_entrega, token_publico)
                 VALUES (:e, :ped, :tid, :cod, :canal, :st, :dn, :dc, :du, :prev, :tok)"
            );
            $st->execute([
                ':e'     => $etiquetaId,
                ':ped'   => !empty($d['pedido_id']) ? (int)$d['pedido_id'] : null,
                ':tid'   => !empty($d['transportadora_id']) ? (int)$d['transportadora_id'] : null,
                ':cod'   => $codigo !== '' ? $codigo : ('SEMCOD-' . ($etiquetaId ?? uniqid())),
                ':canal' => (string)($d['canal'] ?? 'site'),
                ':st'    => in_array($d['status_interno'] ?? '', array_keys(self::LABELS), true) ? $d['status_interno'] : 'etiqueta_emitida',
                ':dn'    => $d['destinatario_nome'] ?? null,
                ':dc'    => $d['destino_cidade'] ?? null,
                ':du'    => $d['destino_uf'] ?? null,
                ':prev'  => $d['previsao_entrega'] ?? null,
                ':tok'   => $token,
            ]);
            $id = (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            LogService::error('Falha ao abrir rastreio', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao abrir rastreio.'];
        }

        LogService::audit('Rastreio aberto', ['rastreio_id' => $id, 'etiqueta_id' => $etiquetaId]);
        return ['ok' => true, 'id' => $id, 'token' => $token];
    }

    /** Cria rastreios para etiquetas emitidas/postadas que ainda não têm um (worker). */
    public function abrirPendentesDeEtiquetas(int $limite = 100): int
    {
        $criados = 0;
        try {
            $sql = "SELECT e.id, e.pedido_id, e.transportadora_id, e.codigo_rastreio, e.canal, e.destinatario, e.external_id
                    FROM log_etiquetas e
                    LEFT JOIN log_rastreios r ON r.etiqueta_id = e.id
                    WHERE e.status IN ('emitida','postada') AND r.id IS NULL
                      AND (e.codigo_rastreio IS NOT NULL OR e.external_id IS NOT NULL)
                    ORDER BY e.id ASC LIMIT " . max(1, min(500, $limite));
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao buscar etiquetas sem rastreio', ['erro' => $e->getMessage()]);
            return 0;
        }
        foreach ($rows as $e) {
            $dest = json_decode((string)$e['destinatario'], true) ?: [];
            $r = $this->abrir([
                'etiqueta_id'       => (int)$e['id'],
                'pedido_id'         => $e['pedido_id'],
                'transportadora_id' => $e['transportadora_id'],
                'codigo_rastreio'   => $e['codigo_rastreio'] ?? '',
                'canal'             => $e['canal'] ?? 'site',
                'destinatario_nome' => $dest['nome'] ?? $dest['name'] ?? null,
                'destino_cidade'    => $dest['cidade'] ?? $dest['city'] ?? null,
                'destino_uf'        => $dest['uf'] ?? $dest['state_abbr'] ?? null,
            ]);
            if (!empty($r['ok']) && empty($r['existente'])) $criados++;
        }
        return $criados;
    }

    /* =================================================================
       ATUALIZAÇÃO (consulta + dedup + resumo)
       ================================================================= */

    public function atualizar(int $id): array
    {
        $r = $this->obter($id);
        if (!$r) return ['ok' => false, 'erro' => 'Rastreio não encontrado.'];
        if (empty($r['transportadora_id'])) return ['ok' => false, 'erro' => 'Rastreio sem transportadora.'];

        $adapter = $this->resolverAdapter((int)$r['transportadora_id']);
        if (!$adapter) return ['ok' => false, 'erro' => 'Transportadora indisponível.'];

        // Chave de consulta: order id (ME) quando houver, senão o código.
        $consulta = (string)($r['external_id'] ?? '');
        if ($consulta === '') $consulta = (string)$r['codigo_rastreio'];

        $res = $adapter->rastrear($consulta);
        if (empty($res['ok'])) {
            $this->tocarAtualizacao($id);
            return ['ok' => false, 'erro' => $res['erro'] ?? 'Falha ao consultar a transportadora.'];
        }

        $eventos = self::normalizarEventos($res);
        $novos = $this->inserirEventos($id, $eventos);

        $previsao = $res['previsao_entrega'] ?? ($r['previsao_entrega'] ?? null);
        $resumo = self::resumoDoRastreio($eventos, $previsao ? substr((string)$previsao, 0, 10) : null);
        $this->aplicarResumo($id, $resumo, $res, (int)($r['etiqueta_id'] ?? 0));

        return ['ok' => true, 'novos_eventos' => $novos, 'status' => $resumo['status_interno'] ?? $r['status_interno']];
    }

    public function atualizarPorEtiqueta(int $etiquetaId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT id FROM log_rastreios WHERE etiqueta_id = :e LIMIT 1");
            $st->execute([':e' => $etiquetaId]);
            $id = (int)$st->fetchColumn();
        } catch (\Throwable $e) { $id = 0; }
        if (!$id) return ['ok' => false, 'erro' => 'Sem rastreio para a etiqueta.'];
        return $this->atualizar($id);
    }

    /** Seleciona não-finais elegíveis pela cadência adaptativa e atualiza (worker). */
    public function atualizarPendentes(int $limite = 50): array
    {
        try {
            $sql = "SELECT r.id, r.status_interno, r.ultima_atualizacao
                    FROM log_rastreios r
                    WHERE r.status_interno <> 'entregue'
                      AND r.criado_em >= (NOW() - INTERVAL " . self::MAX_DIAS_POLLING . " DAY)
                    ORDER BY (r.ultima_atualizacao IS NULL) DESC, r.ultima_atualizacao ASC
                    LIMIT " . max(1, min(500, $limite * 3));
            $cand = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao selecionar rastreios pendentes', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'processados' => 0, 'atualizados' => 0];
        }

        $agora = time();
        $proc = 0; $atualizados = 0; $comNovidade = 0;
        foreach ($cand as $c) {
            if ($proc >= $limite) break;
            $status = (string)$c['status_interno'];
            $ult = $c['ultima_atualizacao'] ? strtotime((string)$c['ultima_atualizacao']) : 0;
            $intervalo = self::intervaloPorStatus($status) * 60;
            if ($ult && ($agora - $ult) < $intervalo) continue; // ainda não é hora

            $proc++;
            $r = $this->atualizar((int)$c['id']);
            if (!empty($r['ok'])) { $atualizados++; if (!empty($r['novos_eventos'])) $comNovidade++; }
        }
        return ['ok' => true, 'processados' => $proc, 'atualizados' => $atualizados, 'com_novidade' => $comNovidade];
    }

    /* =================================================================
       LEITURA
       ================================================================= */

    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $where = []; $p = [];
        if (!empty($filtros['status']))            { $where[] = 'r.status_interno = :st'; $p[':st'] = $filtros['status']; }
        if (!empty($filtros['transportadora_id'])) { $where[] = 'r.transportadora_id = :tid'; $p[':tid'] = (int)$filtros['transportadora_id']; }
        if (!empty($filtros['atraso']))            { $where[] = 'r.atraso = 1'; }
        if (!empty($filtros['ocorrencia']))        { $where[] = 'r.ocorrencia = 1'; }
        if (!empty($filtros['busca'])) {
            $where[] = '(r.codigo_rastreio LIKE :q OR r.pedido_id = :qexato OR r.destinatario_nome LIKE :q)';
            $p[':q'] = '%' . $filtros['busca'] . '%';
            $p[':qexato'] = ctype_digit((string)$filtros['busca']) ? (int)$filtros['busca'] : 0;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagina = max(1, $pagina); $porPagina = max(1, min(100, $porPagina));
        $off = ($pagina - 1) * $porPagina;

        try {
            $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM log_rastreios r$sqlWhere");
            $cnt->execute($p);
            $total = (int)$cnt->fetchColumn();

            $sql = "SELECT r.*, t.nome AS transportadora_nome
                    FROM log_rastreios r LEFT JOIN log_transportadoras t ON t.id = r.transportadora_id
                    $sqlWhere ORDER BY r.atualizado_em DESC LIMIT $porPagina OFFSET $off";
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar rastreios', ['erro' => $e->getMessage()]);
            return ['itens' => [], 'total' => 0, 'pagina' => $pagina, 'por_pagina' => $porPagina];
        }
        foreach ($rows as &$r) $r['status_label'] = self::rotulo((string)$r['status_interno']);
        return ['itens' => $rows, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    public function obter(int $id): ?array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT r.*, t.nome AS transportadora_nome, t.slug AS transportadora_slug, e.external_id
                 FROM log_rastreios r
                 LEFT JOIN log_transportadoras t ON t.id = r.transportadora_id
                 LEFT JOIN log_etiquetas e ON e.id = r.etiqueta_id
                 WHERE r.id = :id LIMIT 1"
            );
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $r['status_label'] = self::rotulo((string)$r['status_interno']);
            return $r;
        } catch (\Throwable $e) {
            LogService::error('Falha ao obter rastreio', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    public function timeline(int $id): array
    {
        try {
            $st = $this->pdo->prepare("SELECT data_evento, status_transportadora, status_interno, descricao, local FROM log_rastreio_eventos WHERE rastreio_id = :id ORDER BY data_evento DESC, id DESC");
            $st->execute([':id' => $id]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
        foreach ($rows as &$e) $e['status_label'] = self::rotulo((string)$e['status_interno']);
        return $rows;
    }

    /** View pública por token (sanitizada). */
    public function porToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !ctype_alnum($token)) return null;
        try {
            $st = $this->pdo->prepare(
                "SELECT r.*, t.nome AS transportadora_nome
                 FROM log_rastreios r LEFT JOIN log_transportadoras t ON t.id = r.transportadora_id
                 WHERE r.token_publico = :tok LIMIT 1"
            );
            $st->execute([':tok' => $token]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return self::sanitizarPublico($r, $this->timeline((int)$r['id']));
        } catch (\Throwable $e) {
            LogService::error('Falha ao consultar rastreio público', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * View pública do rastreio de UM pedido.
     *
     * Usada pela tela de acompanhamento do cliente, que já provou ser dono do
     * pedido — por isso entra por `pedido_id` e não por token. Sai pelo mesmo
     * sanitizador das outras: a tela do comprador não vê mais do que a
     * página pública veria.
     */
    public function porPedido(int $pedidoId): ?array
    {
        if ($pedidoId <= 0) return null;

        try {
            $st = $this->pdo->prepare(
                "SELECT r.*, t.nome AS transportadora_nome
                   FROM log_rastreios r
                   LEFT JOIN log_transportadoras t ON t.id = r.transportadora_id
                  WHERE r.pedido_id = :p
                  ORDER BY r.id DESC LIMIT 1"
            );
            $st->execute([':p' => $pedidoId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;

            $dados = self::sanitizarPublico($r, $this->timeline((int)$r['id']));
            // O token deixa o cliente abrir a pagina publica completa e
            // compartilhar com quem vai receber, sem expor id interno.
            $dados['token_publico'] = $r['token_publico'] ?? null;
            return $dados;
        } catch (\Throwable $e) {
            LogService::error('Falha ao consultar rastreio do pedido', [
                'pedido_id' => $pedidoId, 'erro' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** View sanitizada por código de rastreio (usada pela API). */
    public function porCodigo(string $codigo): ?array
    {
        $codigo = trim($codigo);
        if ($codigo === '') return null;
        try {
            $st = $this->pdo->prepare(
                "SELECT r.*, t.nome AS transportadora_nome
                 FROM log_rastreios r LEFT JOIN log_transportadoras t ON t.id = r.transportadora_id
                 WHERE r.codigo_rastreio = :c ORDER BY r.id DESC LIMIT 1"
            );
            $st->execute([':c' => $codigo]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return self::sanitizarPublico($r, $this->timeline((int)$r['id']));
        } catch (\Throwable $e) {
            LogService::error('Falha ao consultar rastreio por código', ['erro' => $e->getMessage()]);
            return null;
        }
    }

    /* =================================================================
       Internos
       ================================================================= */

    protected function resolverAdapter(int $transportadoraId): ?TransportadoraInterface
    {
        $row = TransportadoraManager::porId($transportadoraId);
        if (!$row) return null;
        try {
            return TransportadoraManager::resolver($row);
        } catch (\Throwable $e) {
            LogService::error('Falha ao resolver adapter (rastreio)', ['transportadora_id' => $transportadoraId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** INSERT IGNORE com dedup por (rastreio_id, hash_evento). Retorna quantos novos. */
    private function inserirEventos(int $rastreioId, array $eventos): int
    {
        if (!$eventos) return 0;
        $novos = 0;
        try {
            $st = $this->pdo->prepare(
                "INSERT IGNORE INTO log_rastreio_eventos
                 (rastreio_id, data_evento, status_transportadora, status_interno, descricao, local, hash_evento)
                 VALUES (:r, :d, :stx, :si, :desc, :loc, :h)"
            );
            foreach ($eventos as $e) {
                $st->execute([
                    ':r'    => $rastreioId,
                    ':d'    => $e['data_evento'],
                    ':stx'  => $e['status_transportadora'],
                    ':si'   => $e['status_interno'],
                    ':desc' => $e['descricao'] !== null ? mb_substr((string)$e['descricao'], 0, 500) : null,
                    ':loc'  => $e['local'],
                    ':h'    => $e['hash'],
                ]);
                $novos += $st->rowCount();
            }
        } catch (\Throwable $e) {
            LogService::error('Falha ao inserir eventos de rastreio', ['rastreio_id' => $rastreioId, 'erro' => $e->getMessage()]);
        }
        return $novos;
    }

    private function aplicarResumo(int $id, array $resumo, array $res, int $etiquetaId): void
    {
        $sets = ['ultima_atualizacao = NOW()'];
        $vals = [':id' => $id];
        if ($resumo['status_interno'] !== null) { $sets[] = 'status_interno = :st'; $vals[':st'] = $resumo['status_interno']; }
        if (!empty($resumo['postado_em']))  { $sets[] = 'postado_em = :pe';  $vals[':pe'] = $resumo['postado_em']; }
        if (!empty($resumo['entregue_em'])) { $sets[] = 'entregue_em = :ee'; $vals[':ee'] = $resumo['entregue_em']; }
        if (!empty($res['previsao_entrega'])) { $sets[] = 'previsao_entrega = :prev'; $vals[':prev'] = substr((string)$res['previsao_entrega'], 0, 10); }
        $sets[] = 'atraso = :atr';       $vals[':atr'] = (int)$resumo['atraso'];
        $sets[] = 'ocorrencia = :oco';   $vals[':oco'] = (int)$resumo['ocorrencia'];

        try {
            $this->pdo->prepare("UPDATE log_rastreios SET " . implode(', ', $sets) . " WHERE id = :id")->execute($vals);
        } catch (\Throwable $e) {
            LogService::error('Falha ao aplicar resumo de rastreio', ['id' => $id, 'erro' => $e->getMessage()]);
            return;
        }

        // Reflexo no status da etiqueta (emitida -> postada), sem regredir.
        if ($etiquetaId && $resumo['status_interno'] !== null) {
            $novo = self::statusEtiquetaPorRastreio($resumo['status_interno']);
            if ($novo) {
                try {
                    $this->pdo->prepare("UPDATE log_etiquetas SET status = :s WHERE id = :e AND status = 'emitida'")
                              ->execute([':s' => $novo, ':e' => $etiquetaId]);
                } catch (\Throwable $e) { /* silencioso */ }
            }
        }
    }

    private function tocarAtualizacao(int $id): void
    {
        try { $this->pdo->prepare("UPDATE log_rastreios SET ultima_atualizacao = NOW() WHERE id = :id")->execute([':id' => $id]); }
        catch (\Throwable $e) { /* ignore */ }
    }

    private function gerarToken(): string
    {
        try { return bin2hex(random_bytes(20)); } // 40 hex, cabe em CHAR(40)
        catch (\Throwable $e) { return substr(sha1(uniqid('', true) . mt_rand()), 0, 40); }
    }
}
