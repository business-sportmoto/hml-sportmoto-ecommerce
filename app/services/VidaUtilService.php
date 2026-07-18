<?php
/**
 * app/services/VidaUtilService.php
 *
 * Dica de cuidado por vida útil de peça.
 *
 * TRÊS ETAPAS:
 *   1. agendar()        — pedido virou "entregue" → agenda 1 dica por item que
 *                         tenha regra de categoria. Status de devolução/
 *                         cancelamento cancelam as dicas pendentes do pedido.
 *   2. disparar()       — chegou o dia → manda a notificação in-app.
 *   3. registrarClique() — o clique na dica vira `dica_cuidado_clicada` no
 *                         event stream, e AÍ um fluxo do motor faz a venda.
 *
 * A dica é de CUIDADO, não de venda: "dá uma olhada no desgaste", não "hora de
 * trocar". Quem clica está demonstrando interesse — esse é o sinal que o
 * fluxo espera. Quem não clica não é incomodado.
 *
 * Toda aritmética de data é feita em PHP (não em SQL) — o serviço roda igual
 * em MySQL, MariaDB ou SQLite.
 */
class VidaUtilService
{
    /** Status que agenda as dicas. */
    private const STATUS_ENTREGUE = 'entregue';

    /** Status que cancelam dicas pendentes (peça voltou / venda desfeita). */
    private const STATUS_CANCELA = ['cancelado', 'devolvido', 'em_devolucao', 'troca_devolucao'];

    /**
     * As duas tabelas de histórico de status do projeto. Varremos AS DUAS: a
     * UNIQUE de vida_util_agenda (pedido_item_id) torna a dupla varredura
     * inofensiva, e assim não importa em qual delas o seu código escreve.
     */
    private const TABELAS_HISTORICO = [
        'pedido_status_historico' => 'vida_util_cursor_psh',
        'pedido_historico'        => 'vida_util_cursor_ph',
    ];

    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. AGENDAR
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Varre o histórico de status e agenda (ou cancela) dicas.
     * @return array{lidos:int, pedidos:int, agendadas:int, canceladas:int}
     */
    public function agendar(int $lote = 500): array
    {
        $stats = ['lidos' => 0, 'pedidos' => 0, 'agendadas' => 0, 'canceladas' => 0];
        $limite = max(50, min(5000, $lote));

        foreach (self::TABELAS_HISTORICO as $tabela => $chaveCursor) {
            try {
                $cursor = (int)$this->getCfg($chaveCursor, '0');

                // $tabela vem de uma const do próprio arquivo — nunca de input
                $st = $this->db->prepare(
                    "SELECT id, pedido_id, status_novo, criado_em
                     FROM {$tabela}
                     WHERE id > :c
                     ORDER BY id ASC
                     LIMIT {$limite}"
                );
                $st->bindValue(':c', $cursor, PDO::PARAM_INT);
                $st->execute();
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);

                $ultimo = $cursor;
                foreach ($rows as $r) {
                    $ultimo = (int)$r['id'];   // cursor avança sobre todo o lote
                    $stats['lidos']++;

                    $status = (string)$r['status_novo'];
                    if ($status === self::STATUS_ENTREGUE) {
                        $stats['pedidos']++;
                        $stats['agendadas'] += $this->agendarPedido(
                            (int)$r['pedido_id'], (string)$r['criado_em']
                        );
                    } elseif (in_array($status, self::STATUS_CANCELA, true)) {
                        $stats['canceladas'] += $this->cancelarPendentes((int)$r['pedido_id']);
                    }
                }

                if ($ultimo > $cursor) $this->setCfg($chaveCursor, (string)$ultimo);

            } catch (Throwable $e) {
                // Tabela ausente ou erro pontual não pode derrubar a outra varredura
                $this->log('warning', "VidaUtil agendar({$tabela}): " . $e->getMessage());
            }
        }

        return $stats;
    }

    /** Agenda as dicas dos itens de um pedido entregue. @return int quantas */
    private function agendarPedido(int $pedidoId, string $entregueEm): int
    {
        $clienteId = (int)$this->col("SELECT cliente_id FROM pedidos WHERE id = :p LIMIT 1",
                                     [':p' => $pedidoId]);
        if ($clienteId <= 0) return 0;

        $st = $this->db->prepare(
            "SELECT id, produto_id FROM pedido_itens
             WHERE pedido_id = :p AND produto_id IS NOT NULL"
        );
        $st->execute([':p' => $pedidoId]);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$itens) return 0;

        $ins = $this->db->prepare(
            "INSERT IGNORE INTO vida_util_agenda
             (cliente_id, pedido_id, pedido_item_id, produto_id, categoria_id,
              entregue_em, disparar_em)
             VALUES (:cli, :ped, :item, :prod, :cat, :ent, :disp)"
        );

        $n = 0;
        foreach ($itens as $item) {
            $regra = $this->regraDoProduto((int)$item['produto_id']);
            if (!$regra) continue; // categoria sem regra → sem dica

            $disparo = date('Y-m-d', strtotime($entregueEm . ' +' . (int)$regra['meses'] . ' months'));

            $ins->execute([
                ':cli'  => $clienteId,
                ':ped'  => $pedidoId,
                ':item' => (int)$item['id'],
                ':prod' => (int)$item['produto_id'],
                ':cat'  => (int)$regra['categoria_id'],
                ':ent'  => $entregueEm,
                ':disp' => $disparo,
            ]);
            if ($ins->rowCount() > 0) $n++;
        }
        return $n;
    }

    /**
     * Regra de vida útil do produto.
     * Um produto pode estar em VÁRIAS categorias (pivot produto_categorias).
     * Vence a de MENOR prazo — a mais conservadora, coerente com a filosofia
     * de lembrar cedo em vez de tarde.
     */
    private function regraDoProduto(int $produtoId): ?array
    {
        try {
            $st = $this->db->prepare(
                "SELECT v.categoria_id, v.meses, v.titulo, v.dica, v.categoria_notif
                 FROM produto_categorias pc
                 JOIN categoria_vida_util v ON v.categoria_id = pc.categoria_id AND v.ativo = 1
                 WHERE pc.produto_id = :p
                 ORDER BY v.meses ASC
                 LIMIT 1"
            );
            $st->execute([':p' => $produtoId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Cancela dicas ainda não enviadas de um pedido devolvido/cancelado. */
    private function cancelarPendentes(int $pedidoId): int
    {
        try {
            $st = $this->db->prepare(
                "UPDATE vida_util_agenda SET status = 'cancelado'
                 WHERE pedido_id = :p AND status = 'agendado'"
            );
            $st->execute([':p' => $pedidoId]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. DISPARAR
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Envia as dicas que venceram.
     * @return array{devidas:int, enviadas:int, agrupadas:int, adiadas:int, sem_permissao:int}
     */
    public function disparar(int $lote = 200): array
    {
        $stats = ['devidas'=>0,'enviadas'=>0,'agrupadas'=>0,'adiadas'=>0,'sem_permissao'=>0];

        try {
            $hoje       = date('Y-m-d');
            $dedupDias  = max(0, (int)$this->getCfg('vida_util_dedup_dias', '30'));
            $respeitaCap= (int)$this->getCfg('vida_util_respeita_cap', '1') === 1;
            $maxAdia    = max(0, (int)$this->getCfg('vida_util_max_adiamentos', '3'));
            $limite     = max(20, min(2000, $lote));

            $st = $this->db->prepare(
                "SELECT a.*, v.titulo, v.dica, v.categoria_notif, p.nome AS produto_nome, p.slug AS produto_slug
                 FROM vida_util_agenda a
                 JOIN categoria_vida_util v ON v.categoria_id = a.categoria_id
                 LEFT JOIN produtos p ON p.id = a.produto_id
                 WHERE a.status = 'agendado' AND a.disparar_em <= :hoje
                 ORDER BY a.cliente_id ASC, a.categoria_id ASC, a.id ASC
                 LIMIT {$limite}"
            );
            $st->execute([':hoje' => $hoje]);
            $devidas = $st->fetchAll(PDO::FETCH_ASSOC);
            $stats['devidas'] = count($devidas);
            if (!$devidas) return $stats;

            $jaNestaRodada = []; // "cliente|categoria" → evita 2 dicas iguais no mesmo ciclo

            foreach ($devidas as $a) {
                $cid  = (int)$a['cliente_id'];
                $cat  = (int)$a['categoria_id'];
                $par  = $cid . '|' . $cat;

                // ── Dedup: comprou 2 pneus → 1 dica só ──
                if (isset($jaNestaRodada[$par]) || ($dedupDias > 0 && $this->enviouRecente($cid, $cat, $dedupDias))) {
                    $this->marcar((int)$a['id'], 'agrupado');
                    $stats['agrupadas']++;
                    continue;
                }

                // ── Preferências do cliente ──
                if (!$this->clientePermite($cid)) {
                    $this->marcar((int)$a['id'], 'sem_permissao');
                    $stats['sem_permissao']++;
                    continue;
                }

                // ── Frequency cap (Fase 3B): adia em vez de atropelar ──
                if ($respeitaCap && (int)$a['tentativas'] < $maxAdia
                    && class_exists('FluxoGuard')
                    && FluxoGuard::capAtingido($cid, 'notificacao', $this->db)) {
                    $this->adiar((int)$a['id'], 7);
                    $stats['adiadas']++;
                    continue;
                }

                if ($this->enviarDica($a)) {
                    $this->marcar((int)$a['id'], 'enviado', true);
                    $jaNestaRodada[$par] = true;
                    $stats['enviadas']++;

                    // Conta no cap dos fluxos (fluxo_id = 0 → "não veio de fluxo")
                    if (class_exists('FluxoGuard')) {
                        try { FluxoGuard::registrarEnvio($cid, 'notificacao', 0, $this->db); }
                        catch (Throwable $e) {}
                    }
                }
            }
        } catch (Throwable $e) {
            $this->log('error', 'VidaUtil disparar: ' . $e->getMessage());
        }

        return $stats;
    }

    /** Monta e envia a notificação in-app da dica. */
    private function enviarDica(array $a): bool
    {
        if (!class_exists('NotificacaoService')) return false;

        $vars = [
            'produto_nome' => (string)($a['produto_nome'] ?? 'sua peça'),
            'moto_apelido' => $this->motoApelido((int)$a['cliente_id']),
        ];
        $base = defined('BASE_URL') ? BASE_URL : '';

        try {
            NotificacaoService::criar([
                'categoria' => (string)($a['categoria_notif'] ?? 'sistema'),
                'tipo'      => 'dica_cuidado',
                'titulo'    => $this->interpolar((string)$a['titulo'], $vars),
                'mensagem'  => $this->interpolar((string)$a['dica'], $vars),
                'url'       => '/dica/' . (int)$a['id'],
            ], [['tipo' => 'cliente', 'id' => (int)$a['cliente_id']]]);
            return true;
        } catch (Throwable $e) {
            $this->log('warning', 'VidaUtil enviarDica: ' . $e->getMessage());
            return false;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. CLIQUE — a ponte para o motor de fluxos
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Registra o clique na dica como evento do stream.
     * É ESTE evento que um fluxo escuta para fazer a venda — quem clicou
     * demonstrou interesse; quem não clicou fica em paz.
     *
     * @return array|null a linha da agenda (para o controller redirecionar), ou null
     */
    public function registrarClique(int $agendaId, int $clienteId, ?string $visitanteToken = null): ?array
    {
        try {
            $st = $this->db->prepare(
                "SELECT a.*, p.slug AS produto_slug, p.nome AS produto_nome
                 FROM vida_util_agenda a
                 LEFT JOIN produtos p ON p.id = a.produto_id
                 WHERE a.id = :id LIMIT 1"
            );
            $st->execute([':id' => $agendaId]);
            $a = $st->fetch(PDO::FETCH_ASSOC);
            if (!$a) return null;

            // A dica é pessoal: só o dono registra o evento
            if ((int)$a['cliente_id'] !== $clienteId) return $a;

            if (empty($a['clicado_em'])) {
                $this->db->prepare("UPDATE vida_util_agenda SET clicado_em = :ag WHERE id = :id")
                         ->execute([':ag' => date('Y-m-d H:i:s'), ':id' => $agendaId]);
            }

            $ctx = json_encode([
                'agenda_id'    => $agendaId,
                'categoria_id' => (int)$a['categoria_id'],
                'produto_id'   => $a['produto_id'] ? (int)$a['produto_id'] : null,
            ], JSON_UNESCAPED_UNICODE);

            // TrackingService quando disponível (cuida do token/sessão);
            // senão, INSERT direto — mesmo padrão da EmailEngajamentoBridge.
            if (class_exists('TrackingService') && method_exists('TrackingService', 'registrar')) {
                try {
                    TrackingService::registrar('dica_cuidado_clicada', 'produto',
                        $a['produto_id'] ? (int)$a['produto_id'] : null,
                        ['agenda_id' => $agendaId, 'categoria_id' => (int)$a['categoria_id']]);
                    return $a;
                } catch (Throwable $e) { /* cai no INSERT direto */ }
            }

            $this->db->prepare(
                "INSERT INTO eventos
                 (visitante_token, cliente_id, tipo, entidade_tipo, entidade_id, contexto_json, criado_em)
                 VALUES (:tok, :cid, 'dica_cuidado_clicada', :et, :ei, :ctx, :cr)"
            )->execute([
                ':tok' => $visitanteToken ?: str_pad('vidautil', 32, '0'),
                ':cid' => $clienteId,
                ':et'  => $a['produto_id'] ? 'produto' : null,
                ':ei'  => $a['produto_id'] ? (int)$a['produto_id'] : null,
                ':ctx' => $ctx,
                ':cr'  => date('Y-m-d H:i:s'),
            ]);
            return $a;

        } catch (Throwable $e) {
            $this->log('warning', 'VidaUtil registrarClique: ' . $e->getMessage());
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Auxiliares
    // ═════════════════════════════════════════════════════════════════════════

    private function enviouRecente(int $clienteId, int $categoriaId, int $dias): bool
    {
        $desde = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
        return (bool)$this->col(
            "SELECT 1 FROM vida_util_agenda
             WHERE cliente_id = :c AND categoria_id = :k AND status = 'enviado'
               AND enviado_em > :d LIMIT 1",
            [':c' => $clienteId, ':k' => $categoriaId, ':d' => $desde]
        );
    }

    /** Cliente aceita receber? (a dica é de serviço, mas respeitamos o opt-out) */
    private function clientePermite(int $clienteId): bool
    {
        if (!class_exists('NotifPrefsService') || !method_exists('NotifPrefsService', 'pode')) {
            return true;
        }
        try {
            return (bool)NotifPrefsService::pode($clienteId, 'notificacao', 'marketing');
        } catch (Throwable $e) {
            return true; // preferência indisponível não bloqueia
        }
    }

    private function marcar(int $id, string $status, bool $carimbarEnvio = false): void
    {
        try {
            if ($carimbarEnvio) {
                $this->db->prepare("UPDATE vida_util_agenda SET status = :s, enviado_em = :e WHERE id = :id")
                         ->execute([':s' => $status, ':e' => date('Y-m-d H:i:s'), ':id' => $id]);
            } else {
                $this->db->prepare("UPDATE vida_util_agenda SET status = :s WHERE id = :id")
                         ->execute([':s' => $status, ':id' => $id]);
            }
        } catch (Throwable $e) {}
    }

    private function adiar(int $id, int $dias): void
    {
        try {
            $this->db->prepare(
                "UPDATE vida_util_agenda SET disparar_em = :d, tentativas = tentativas + 1 WHERE id = :id"
            )->execute([':d' => date('Y-m-d', strtotime("+{$dias} days")), ':id' => $id]);
        } catch (Throwable $e) {}
    }

    private function motoApelido(int $clienteId): string
    {
        try {
            $st = $this->db->prepare(
                "SELECT cv.apelido, cv.ano, mm.nome AS montadora, mo.nome AS modelo
                 FROM cliente_veiculos cv
                 JOIN moto_montadoras mm ON mm.id = cv.montadora_id
                 LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
                 WHERE cv.cliente_id = :c AND cv.principal = 1 LIMIT 1"
            );
            $st->execute([':c' => $clienteId]);
            if ($m = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($m['apelido'])) return (string)$m['apelido'];
                $partes = array_filter([$m['montadora'], $m['modelo'], $m['ano']]);
                if ($partes) return implode(' ', $partes);
            }
        } catch (Throwable $e) {}
        return 'sua moto';
    }

    private function interpolar(string $texto, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            fn($m) => (string)($vars[$m[1]] ?? ''), $texto);
    }

    private function col(string $sql, array $params)
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            $v = $st->fetchColumn();
            return ($v === false) ? null : $v;
        } catch (Throwable $e) { return null; }
    }

    private function getCfg(string $chave, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT valor FROM fluxo_motor_config WHERE chave = :k");
            $st->execute([':k' => $chave]);
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null) ? (string)$v : $default;
        } catch (Throwable $e) { return $default; }
    }

    private function setCfg(string $chave, string $valor): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO fluxo_motor_config (chave, valor) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE valor = :v2"
            )->execute([':k' => $chave, ':v' => $valor, ':v2' => $valor]);
        } catch (Throwable $e) {}
    }

    private function log(string $nivel, string $msg): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::$nivel($msg, ['servico' => 'VidaUtilService']); } catch (Throwable $e) {}
    }
}
