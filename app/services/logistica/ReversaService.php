<?php
/**
 * ReversaService — logística reversa (retorno cliente -> loja).
 *
 * Máquina de estados: solicitada -> autorizada -> etiqueta_gerada ->
 * em_transito -> recebida (ou -> cancelada em quase qualquer ponto).
 *
 * Reuso: ao gerar a etiqueta reversa (adapter.gerarReversa, que inverte
 * remetente/destinatário), o resultado é registrado como um log_etiquetas
 * (canal 'reversa') via EtiquetaService::registrarReversa — assim ganhamos
 * reimpressão de graça — e abrimos um rastreio (RastreioService::abrir) para
 * o worker da Fase 5 acompanhar a VOLTA, com link público para o cliente.
 *
 * "Instruções por e-mail/WhatsApp" = texto pronto (montarInstrucoes) com o PDF
 * da etiqueta + o link de rastreio; a tela oferece copiar / mailto / WhatsApp.
 * Vínculo com troca/reembolso pela coluna `processo`.
 *
 * Métodos de DECISÃO (transicaoValida, acoesPermitidas, processoSugerido,
 * montarInstrucoes, statusPorRastreio, rótulos) são PUROS — testáveis sem banco.
 */
class ReversaService
{
    private PDO $pdo;
    private EtiquetaService $etiquetas;
    private RastreioService $rastreios;

    private const TRANSICOES = [
        'solicitada'      => ['autorizada', 'cancelada'],
        'autorizada'      => ['etiqueta_gerada', 'cancelada'],
        'etiqueta_gerada' => ['em_transito', 'recebida', 'cancelada'],
        'em_transito'     => ['recebida', 'cancelada'],
        'recebida'        => [],
        'cancelada'       => [],
    ];

    private const MOTIVOS = [
        'devolucao' => 'Devolução', 'troca' => 'Troca', 'defeito' => 'Defeito',
        'arrependimento' => 'Arrependimento', 'avaria' => 'Avaria', 'outro' => 'Outro',
    ];
    private const STATUS_LABELS = [
        'solicitada' => 'Solicitada', 'autorizada' => 'Autorizada', 'etiqueta_gerada' => 'Etiqueta gerada',
        'em_transito' => 'Em trânsito', 'recebida' => 'Recebida', 'cancelada' => 'Cancelada',
    ];

    public function __construct(?PDO $pdo = null, ?EtiquetaService $etiquetas = null, ?RastreioService $rastreios = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->etiquetas = $etiquetas ?? new EtiquetaService($this->pdo);
        $this->rastreios = $rastreios ?? new RastreioService($this->pdo);
    }

    /* =================================================================
       DECISÃO (puro)
       ================================================================= */

    public static function transicaoValida(string $de, string $para): bool
    {
        return in_array($para, self::TRANSICOES[$de] ?? [], true);
    }

    public static function acoesPermitidas(string $status): array
    {
        return match ($status) {
            'solicitada'      => ['autorizar', 'cancelar'],
            'autorizada'      => ['gerar_etiqueta', 'cancelar'],
            'etiqueta_gerada' => ['imprimir', 'sincronizar', 'marcar_recebida', 'cancelar'],
            'em_transito'     => ['imprimir', 'sincronizar', 'marcar_recebida'],
            'recebida'        => ['definir_processo'],
            'cancelada'       => ['remover'],
            default           => [],
        };
    }

    public static function motivoRotulo(string $m): string { return self::MOTIVOS[$m] ?? ucfirst($m); }
    public static function statusRotulo(string $s): string { return self::STATUS_LABELS[$s] ?? ucfirst(str_replace('_', ' ', $s)); }

    /** Sugere o processo (troca/reembolso) a partir do motivo. */
    public static function processoSugerido(string $motivo): string
    {
        return match ($motivo) {
            'troca', 'defeito', 'avaria' => 'troca',
            default                      => 'reembolso',
        };
    }

    /** Reflexo do status do rastreio da VOLTA no status da reversa. */
    public static function statusPorRastreio(string $statusRastreio): ?string
    {
        return match ($statusRastreio) {
            'postado', 'em_transito', 'saiu_entrega' => 'em_transito',
            'entregue'                               => 'recebida',
            default                                  => null,
        };
    }

    /** Texto de instruções para o cliente (com PDF + link de rastreio). */
    public static function montarInstrucoes(array $rev, array $links): string
    {
        $motivo = self::motivoRotulo((string)($rev['motivo'] ?? 'devolucao'));
        $pedido = !empty($rev['pedido_id']) ? '#' . $rev['pedido_id'] : '';
        $tipo = (string)($rev['tipo'] ?? 'postagem');
        $validade = !empty($links['validade']) ? date('d/m/Y', strtotime((string)$links['validade'])) : null;

        $l = [];
        $l[] = "Olá! Sua solicitação de {$motivo} do pedido {$pedido} foi autorizada.";
        $l[] = '';
        if (!empty($links['url_pdf'])) $l[] = "1) Imprima a etiqueta de retorno: {$links['url_pdf']}";
        $l[] = '2) Embale o(s) item(ns) com cuidado, de preferência na embalagem original.';
        if ($tipo === 'coleta') {
            $l[] = '3) Aguarde a coleta no endereço informado.';
        } else {
            $l[] = '3) Cole a etiqueta na parte externa e poste em uma agência da transportadora'
                 . ($validade ? " até {$validade}." : '.');
        }
        if (!empty($links['rastreio'])) {
            $l[] = '';
            $l[] = "Acompanhe o retorno: {$links['rastreio']}";
        }
        $l[] = '';
        $l[] = 'Qualquer dúvida, é só falar com a gente. Obrigado!';
        return implode("\n", $l);
    }

    /* =================================================================
       FLUXO
       ================================================================= */

    public function solicitar(array $d, ?int $usuarioId = null): array
    {
        $pedidoId = !empty($d['pedido_id']) ? (int)$d['pedido_id'] : null;
        $motivo = in_array($d['motivo'] ?? '', array_keys(self::MOTIVOS), true) ? $d['motivo'] : 'devolucao';
        $tipo = in_array($d['tipo'] ?? '', ['coleta', 'postagem'], true) ? $d['tipo'] : 'postagem';

        // Evita reversa duplicada ativa para o mesmo pedido.
        if ($pedidoId) {
            try {
                $st = $this->pdo->prepare("SELECT id, status FROM log_reversas WHERE pedido_id = :p AND status <> 'cancelada' ORDER BY id DESC LIMIT 1");
                $st->execute([':p' => $pedidoId]);
                if ($ex = $st->fetch(PDO::FETCH_ASSOC)) {
                    return ['ok' => true, 'id' => (int)$ex['id'], 'status' => $ex['status'], 'existente' => true];
                }
            } catch (\Throwable $e) { /* segue */ }
        }

        $processo = in_array($d['processo'] ?? '', ['nenhum', 'troca', 'reembolso'], true)
            ? $d['processo'] : self::processoSugerido($motivo);

        try {
            $st = $this->pdo->prepare(
                "INSERT INTO log_reversas
                 (pedido_id, cliente_id, etiqueta_id, motivo, tipo, itens, endereco_coleta, status, processo, usuario_id)
                 VALUES (:ped, :cli, :etq, :mot, :tipo, :itens, :end, 'solicitada', :proc, :usr)"
            );
            $st->execute([
                ':ped'   => $pedidoId,
                ':cli'   => !empty($d['cliente_id']) ? (int)$d['cliente_id'] : null,
                ':etq'   => !empty($d['etiqueta_id']) ? (int)$d['etiqueta_id'] : null,
                ':mot'   => $motivo,
                ':tipo'  => $tipo,
                ':itens' => json_encode(is_array($d['itens'] ?? null) ? $d['itens'] : [], JSON_UNESCAPED_UNICODE),
                ':end'   => json_encode(is_array($d['endereco_coleta'] ?? null) ? $d['endereco_coleta'] : [], JSON_UNESCAPED_UNICODE),
                ':proc'  => $processo,
                ':usr'   => $usuarioId,
            ]);
            $id = (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            LogService::error('Falha ao solicitar reversa', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao registrar a solicitação de reversa.'];
        }

        LogService::audit('Reversa solicitada', ['reversa_id' => $id, 'pedido_id' => $pedidoId, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id, 'status' => 'solicitada', 'processo' => $processo];
    }

    public function autorizar(int $id, ?int $usuarioId = null): array
    {
        return $this->transicionar($id, 'autorizada', $usuarioId, 'Reversa autorizada');
    }

    /**
     * Gera a etiqueta reversa e prepara instruções + rastreio.
     * $extras: servico_codigo, transportadora_id, volumes[], valor_declarado,
     *          remetente (endereço do cliente; fallback = endereco_coleta), formato.
     */
    public function gerarEtiqueta(int $id, array $extras, ?int $usuarioId = null): array
    {
        $rev = $this->obter($id);
        if (!$rev) return ['ok' => false, 'erro' => 'Reversa não encontrada.'];
        if (!empty($rev['etiqueta_id'])) {
            return ['ok' => true, 'id' => $id, 'etiqueta_id' => (int)$rev['etiqueta_id'], 'reutilizada' => true];
        }
        if (!in_array('gerar_etiqueta', self::acoesPermitidas((string)$rev['status']), true)) {
            return ['ok' => false, 'erro' => 'A reversa precisa estar autorizada para gerar a etiqueta.'];
        }

        $transportadoraId = (int)($extras['transportadora_id'] ?? 0);
        $servico = trim((string)($extras['servico_codigo'] ?? ''));
        $volumes = is_array($extras['volumes'] ?? null) ? array_values($extras['volumes']) : [];
        $cliente = is_array($extras['remetente'] ?? null) && $extras['remetente']
            ? $extras['remetente'] : ($rev['endereco_coleta_json'] ?? []);

        if ($transportadoraId <= 0) return ['ok' => false, 'erro' => 'Selecione a transportadora.'];
        if ($servico === '')       return ['ok' => false, 'erro' => 'Selecione o serviço da transportadora.'];
        if (empty($volumes))       return ['ok' => false, 'erro' => 'Informe ao menos um volume.'];
        if (empty($cliente))       return ['ok' => false, 'erro' => 'Endereço do cliente (remetente da volta) ausente.'];

        $adapter = $this->resolverAdapter($transportadoraId);
        if (!$adapter) return ['ok' => false, 'erro' => 'Transportadora indisponível.'];

        $res = $adapter->gerarReversa([
            'servico_codigo' => $servico,
            'cliente'        => $cliente,          // remetente da volta
            'volumes'        => $volumes,
            'valor'          => (float)($extras['valor_declarado'] ?? 0),
            'produtos'       => [],
        ]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'erro' => $res['erro'] ?? 'Falha ao gerar a etiqueta reversa.', 'etapa' => $res['etapa'] ?? null];
        }

        // 1) Persiste a etiqueta reversa (reuso de log_etiquetas).
        $etq = $this->etiquetas->registrarReversa([
            'pedido_id'       => $rev['pedido_id'],
            'transportadora_id' => $transportadoraId,
            'servico_codigo'  => $servico,
            'servico_nome'    => $extras['servico_nome'] ?? 'Reversa',
            'remetente'       => $cliente,
            'destinatario'    => [], // loja (o adapter usa a config da conta)
            'volumes'         => $volumes,
            'valor_declarado' => (float)($extras['valor_declarado'] ?? 0),
            'external_id'     => $res['external_id'] ?? '',
            'codigo_rastreio' => $res['codigo_rastreio'] ?? null,
            'url_pdf'         => $res['url_pdf'] ?? null,
            'valor'           => $res['valor'] ?? null,
            'contrato'        => $res['contrato'] ?? null,
            'formato'         => $extras['formato'] ?? 'pdf',
        ], $usuarioId);
        $etiquetaId = $etq['id'] ?? 0;

        // 2) Abre o rastreio da volta (link público + worker acompanha).
        $tokenRastreio = null;
        if ($etiquetaId) {
            $ras = $this->rastreios->abrir([
                'etiqueta_id'       => $etiquetaId,
                'pedido_id'         => $rev['pedido_id'],
                'transportadora_id' => $transportadoraId,
                'codigo_rastreio'   => $res['codigo_rastreio'] ?? '',
                'canal'             => 'reversa',
                'destinatario_nome' => $cliente['nome'] ?? $cliente['name'] ?? null,
            ]);
            $tokenRastreio = $ras['token'] ?? null;
        }

        // 3) Monta instruções (PDF + link de rastreio) e fecha o estado.
        $links = [
            'url_pdf'   => $res['url_pdf'] ?? null,
            'rastreio'  => $tokenRastreio ? ('/rastreio/' . $tokenRastreio) : null,
            'validade'  => $res['validade'] ?? null,
        ];
        $instrucoes = self::montarInstrucoes($rev, $links);

        try {
            $this->pdo->prepare(
                "UPDATE log_reversas SET etiqueta_id = :etq, transportadora_id = :tid, codigo_rastreio = :cod,
                 validade_em = :val, instrucoes = :ins, status = 'etiqueta_gerada' WHERE id = :id"
            )->execute([
                ':etq' => $etiquetaId ?: null,
                ':tid' => $transportadoraId,
                ':cod' => $res['codigo_rastreio'] ?? null,
                ':val' => !empty($res['validade']) ? substr((string)$res['validade'], 0, 10) : null,
                ':ins' => $instrucoes,
                ':id'  => $id,
            ]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao concluir geração da reversa', ['reversa_id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Etiqueta gerada, mas falhou ao salvar a reversa.'];
        }

        LogService::audit('Etiqueta reversa gerada', ['reversa_id' => $id, 'etiqueta_id' => $etiquetaId, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id, 'etiqueta_id' => $etiquetaId, 'url_pdf' => $res['url_pdf'] ?? null, 'rastreio' => $links['rastreio']];
    }

    public function cancelar(int $id, ?int $usuarioId = null): array
    {
        $rev = $this->obter($id);
        if (!$rev) return ['ok' => false, 'erro' => 'Reversa não encontrada.'];
        if (!self::transicaoValida((string)$rev['status'], 'cancelada')) {
            return ['ok' => false, 'erro' => 'Reversa não pode ser cancelada neste estado.'];
        }
        // Cancela a etiqueta reversa junto, se já emitida.
        if (!empty($rev['etiqueta_id'])) {
            try { $this->etiquetas->cancelar((int)$rev['etiqueta_id'], $usuarioId); } catch (\Throwable $e) { /* não bloqueia */ }
        }
        return $this->transicionar($id, 'cancelada', $usuarioId, 'Reversa cancelada', false);
    }

    public function marcarRecebida(int $id, ?int $usuarioId = null): array
    {
        return $this->transicionar($id, 'recebida', $usuarioId, 'Item recebido na loja');
    }

    public function definirProcesso(int $id, string $processo, ?int $usuarioId = null): array
    {
        if (!in_array($processo, ['nenhum', 'troca', 'reembolso'], true)) {
            return ['ok' => false, 'erro' => 'Processo inválido.'];
        }
        try {
            $this->pdo->prepare("UPDATE log_reversas SET processo = :p WHERE id = :id")->execute([':p' => $processo, ':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao definir o processo.'];
        }
        LogService::audit('Processo da reversa definido', ['reversa_id' => $id, 'processo' => $processo, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'processo' => $processo];
    }

    /** Reflete o status do rastreio da volta na reversa (on-demand). */
    public function sincronizarComRastreio(int $id, ?int $usuarioId = null): array
    {
        $rev = $this->obter($id);
        if (!$rev || empty($rev['etiqueta_id'])) return ['ok' => false, 'erro' => 'Reversa sem etiqueta/rastreio.'];

        // Atualiza o rastreio da volta e lê o status resultante.
        $upd = $this->rastreios->atualizarPorEtiqueta((int)$rev['etiqueta_id']);
        $statusRas = $upd['status'] ?? null;
        if (!$statusRas) return ['ok' => true, 'reversa_status' => $rev['status'], 'rastreio_status' => null];

        $novo = self::statusPorRastreio((string)$statusRas);
        if ($novo && self::transicaoValida((string)$rev['status'], $novo)) {
            $this->transicionar($id, $novo, $usuarioId, 'Atualizado pelo rastreio da volta', false);
            return ['ok' => true, 'reversa_status' => $novo, 'rastreio_status' => $statusRas];
        }
        return ['ok' => true, 'reversa_status' => $rev['status'], 'rastreio_status' => $statusRas];
    }

    /* =================================================================
       LEITURA
       ================================================================= */

    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $where = []; $p = [];
        if (!empty($filtros['status']))   { $where[] = 'r.status = :st';   $p[':st'] = $filtros['status']; }
        if (!empty($filtros['motivo']))   { $where[] = 'r.motivo = :mot';  $p[':mot'] = $filtros['motivo']; }
        if (!empty($filtros['processo'])) { $where[] = 'r.processo = :pr'; $p[':pr'] = $filtros['processo']; }
        if (!empty($filtros['busca'])) {
            $where[] = '(r.codigo_rastreio LIKE :q OR r.pedido_id = :qexato)';
            $p[':q'] = '%' . $filtros['busca'] . '%';
            $p[':qexato'] = ctype_digit((string)$filtros['busca']) ? (int)$filtros['busca'] : 0;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $pagina = max(1, $pagina); $porPagina = max(1, min(100, $porPagina));
        $off = ($pagina - 1) * $porPagina;

        try {
            $cnt = $this->pdo->prepare("SELECT COUNT(*) FROM log_reversas r$sqlWhere");
            $cnt->execute($p);
            $total = (int)$cnt->fetchColumn();

            $sql = "SELECT r.*, t.nome AS transportadora_nome
                    FROM log_reversas r LEFT JOIN log_transportadoras t ON t.id = r.transportadora_id
                    $sqlWhere ORDER BY r.id DESC LIMIT $porPagina OFFSET $off";
            $st = $this->pdo->prepare($sql);
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar reversas', ['erro' => $e->getMessage()]);
            return ['itens' => [], 'total' => 0, 'pagina' => $pagina, 'por_pagina' => $porPagina];
        }
        foreach ($rows as &$r) {
            $r['status_label'] = self::statusRotulo((string)$r['status']);
            $r['motivo_label'] = self::motivoRotulo((string)$r['motivo']);
            $r['acoes'] = self::acoesPermitidas((string)$r['status']);
        }
        return ['itens' => $rows, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    public function obter(int $id): ?array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT r.*, t.nome AS transportadora_nome,
                        e.url_pdf AS etiqueta_url_pdf, e.external_id AS etiqueta_external_id
                 FROM log_reversas r
                 LEFT JOIN log_transportadoras t ON t.id = r.transportadora_id
                 LEFT JOIN log_etiquetas e ON e.id = r.etiqueta_id
                 WHERE r.id = :id LIMIT 1"
            );
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            foreach (['itens', 'endereco_coleta'] as $k) $r[$k . '_json'] = json_decode((string)$r[$k], true) ?: [];
            $r['status_label'] = self::statusRotulo((string)$r['status']);
            $r['motivo_label'] = self::motivoRotulo((string)$r['motivo']);
            $r['acoes'] = self::acoesPermitidas((string)$r['status']);
            // token do rastreio da volta (se houver), para montar o link público
            if (!empty($r['etiqueta_id'])) {
                try {
                    $t = $this->pdo->prepare("SELECT token_publico FROM log_rastreios WHERE etiqueta_id = :e LIMIT 1");
                    $t->execute([':e' => (int)$r['etiqueta_id']]);
                    $r['rastreio_token'] = $t->fetchColumn() ?: null;
                } catch (\Throwable $e) { $r['rastreio_token'] = null; }
            }
            return $r;
        } catch (\Throwable $e) {
            LogService::error('Falha ao obter reversa', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    public function remover(int $id, ?int $usuarioId = null): array
    {
        $rev = $this->obter($id);
        if (!$rev) return ['ok' => false, 'erro' => 'Reversa não encontrada.'];
        if ((string)$rev['status'] !== 'cancelada') {
            return ['ok' => false, 'erro' => 'Só é possível remover reversas canceladas.'];
        }
        try {
            $this->pdo->prepare("DELETE FROM log_reversas WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Não foi possível remover.'];
        }
        LogService::audit('Reversa removida', ['reversa_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
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
            LogService::error('Falha ao resolver adapter (reversa)', ['transportadora_id' => $transportadoraId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    private function transicionar(int $id, string $para, ?int $usuarioId, string $auditMsg, bool $validar = true): array
    {
        $rev = $this->obter($id);
        if (!$rev) return ['ok' => false, 'erro' => 'Reversa não encontrada.'];
        if ($validar && !self::transicaoValida((string)$rev['status'], $para)) {
            return ['ok' => false, 'erro' => 'Transição não permitida a partir de "' . self::statusRotulo((string)$rev['status']) . '".'];
        }
        try {
            $this->pdo->prepare("UPDATE log_reversas SET status = :s, usuario_id = COALESCE(:u, usuario_id) WHERE id = :id")
                      ->execute([':s' => $para, ':u' => $usuarioId, ':id' => $id]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao transicionar reversa', ['id' => $id, 'para' => $para, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Falha ao atualizar a reversa.'];
        }
        LogService::audit($auditMsg, ['reversa_id' => $id, 'status' => $para, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'status' => $para];
    }
}
