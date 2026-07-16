<?php
/**
 * app/services/NotificacaoService.php
 *
 * Núcleo do sistema de notificações in-app.
 *
 * CRIAÇÃO:
 *   // Individual (gatilhos automáticos)
 *   NotificacaoService::criar([
 *       'categoria' => 'pedido',
 *       'tipo'      => 'pedido_enviado',
 *       'titulo'    => 'Seu pedido #1042 foi enviado!',
 *       'mensagem'  => 'Acompanhe o rastreio...',
 *       'url'       => '/conta/pedidos/1042',
 *   ], [['tipo' => 'cliente', 'id' => 55]]);
 *
 *   // Selecionados
 *   NotificacaoService::criar([...], [
 *       ['tipo' => 'cliente', 'id' => 55],
 *       ['tipo' => 'cliente', 'id' => 89],
 *   ]);
 *
 *   // Broadcast (worker materializa)
 *   NotificacaoService::criarBroadcast([...], 'todos_clientes');
 *
 * LEITURA (modal e badge):
 *   NotificacaoService::listar('cliente', 55, ['categoria' => 'pedido']);
 *   NotificacaoService::contarNaoLidas('cliente', 55);
 *   NotificacaoService::marcarLida($notifUsuarioId, 'cliente', 55);
 *   NotificacaoService::marcarTodasLidas('cliente', 55);
 */
class NotificacaoService
{
    public const CATEGORIAS = ['pedido','promocao','sistema','estoque','financeiro','conta'];

    public const LABELS_CATEGORIA = [
        'pedido'     => 'Pedidos',
        'promocao'   => 'Promoções',
        'sistema'    => 'Sistema',
        'estoque'    => 'Estoque',
        'financeiro' => 'Financeiro',
        'conta'      => 'Conta',
    ];

    /** Ícone (Bootstrap Icons) e cor por categoria — usado no modal */
    public const ESTILO_CATEGORIA = [
        'pedido'     => ['icone' => 'bi-box-seam',      'cor' => '#0a66c2'],
        'promocao'   => ['icone' => 'bi-megaphone',     'cor' => '#e53935'],
        'sistema'    => ['icone' => 'bi-gear',          'cor' => '#71717a'],
        'estoque'    => ['icone' => 'bi-clipboard-data','cor' => '#f59e0b'],
        'financeiro' => ['icone' => 'bi-cash-coin',     'cor' => '#16a34a'],
        'conta'      => ['icone' => 'bi-person',        'cor' => '#6366f1'],
    ];

    /** Batch do fan-out de broadcast */
    public const FANOUT_BATCH = 1000;

    // =========================================================================
    // CRIAÇÃO
    // =========================================================================

    /**
     * Cria uma notificação para destinatários específicos (1..N).
     *
     * @param array $dados  categoria, tipo, titulo, mensagem?, url?, imagem_url?,
     *                      contexto?, criado_por_tipo?, criado_por_id?
     * @param array $destinatarios  [['tipo' => 'cliente'|'admin', 'id' => int], ...]
     * @return int|null id da notificação criada
     */
    public static function criar(array $dados, array $destinatarios): ?int
    {
        if (empty($destinatarios)) return null;

        try {
            $db = Database::getInstance()->getConnection();

            $alvo = count($destinatarios) === 1 ? 'individual' : 'selecionados';
            $id = self::inserirMae($db, $dados, $alvo, 'nao_aplica');
            if (!$id) return null;

            // Insere filhas em batch
            $criadas = self::inserirFilhas($db, $id, $destinatarios);

            $db->prepare("UPDATE notificacoes SET fanout_total = :t WHERE id = :id")
               ->execute([':t' => $criadas, ':id' => $id]);

            return $id;

        } catch (Throwable $e) {
            self::logErro('criar', $e);
            return null;
        }
    }

    /**
     * Cria uma notificação broadcast — as filhas serão materializadas
     * pelo worker (cli/notificacao-worker.php) em batches.
     *
     * @param array  $dados
     * @param string $alvo  'todos_clientes' | 'todos_admins' | 'todos'
     * @return int|null id da notificação
     */
    public static function criarBroadcast(array $dados, string $alvo = 'todos_clientes'): ?int
    {
        if (!in_array($alvo, ['todos_clientes','todos_admins','todos'], true)) {
            return null;
        }
        try {
            $db = Database::getInstance()->getConnection();
            return self::inserirMae($db, $dados, $alvo, 'pendente');
        } catch (Throwable $e) {
            self::logErro('criarBroadcast', $e);
            return null;
        }
    }

    // =========================================================================
    // FAN-OUT (chamado pelo worker)
    // =========================================================================

    /**
     * Processa broadcasts pendentes, materializando as filhas em batches.
     * Retorna estatísticas.
     */
    public static function processarFanout(int $maxSegundos = 120): array
    {
        $stats = ['processadas' => 0, 'filhas_criadas' => 0, 'erros' => 0];
        $inicio = time();

        try {
            $db = Database::getInstance()->getConnection();

            $st = $db->query(
                "SELECT * FROM notificacoes
                 WHERE fanout_status = 'pendente'
                 ORDER BY id ASC LIMIT 10"
            );
            $pendentes = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pendentes as $notif) {
                if ((time() - $inicio) >= $maxSegundos) break;

                // Marca processando (evita corrida entre workers)
                $upd = $db->prepare(
                    "UPDATE notificacoes SET fanout_status='processando'
                     WHERE id = :id AND fanout_status='pendente'"
                );
                $upd->execute([':id' => $notif['id']]);
                if ($upd->rowCount() === 0) continue;

                try {
                    $total = self::materializarBroadcast($db, $notif, $maxSegundos - (time() - $inicio));
                    $db->prepare(
                        "UPDATE notificacoes
                         SET fanout_status='concluido', fanout_total = :t
                         WHERE id = :id"
                    )->execute([':t' => $total, ':id' => $notif['id']]);

                    $stats['processadas']++;
                    $stats['filhas_criadas'] += $total;

                } catch (Throwable $e) {
                    $db->prepare(
                        "UPDATE notificacoes
                         SET fanout_status='erro', fanout_erro = :e
                         WHERE id = :id"
                    )->execute([':e' => mb_substr($e->getLine().' - '.$e->getFile().' - '.$e->getMessage(), 0, 500), ':id' => $notif['id']]);
                    $stats['erros']++;
                }
            }
        } catch (Throwable $e) {
            self::logErro('processarFanout', $e);
            $stats['erros']++;
        }

        return $stats;
    }

    /**
     * Materializa as filhas de um broadcast em batches com INSERT IGNORE.
     */
    private static function materializarBroadcast(PDO $db, array $notif, int $tempoRestante): int
    {
        $alvo    = $notif['alvo_tipo'];
        $notifId = (int)$notif['id'];
        $inicio  = time();
        $total   = 0;

        $fontes = [];
        if ($alvo === 'todos_clientes' || $alvo === 'todos') {
            $fontes[] = ['tipo' => 'cliente', 'sql' => "SELECT id FROM clientes WHERE ativo = '1'"];
        }
        if ($alvo === 'todos_admins' || $alvo === 'todos') {
            // AJUSTE: adapte à sua tabela real de admins
            $fontes[] = ['tipo' => 'admin', 'sql' => "SELECT u.id FROM usuarios u JOIN admins a ON a.usuario_id = u.id WHERE a.nivel IN ('super','gerente')"];
        }

        foreach ($fontes as $fonte) {
            $ultimoId = 0;
            while (true) {
                if ((time() - $inicio) >= $tempoRestante) {
                    // Tempo esgotado — volta para pendente para continuar no próximo ciclo
                    $db->prepare("UPDATE notificacoes SET fanout_status='pendente' WHERE id = :id")
                       ->execute([':id' => $notifId]);
                    return $total;
                }

                // Adiciona a condição de paginação por id (com ou sem WHERE prévio)
                $sqlFinal = (stripos($fonte['sql'], 'WHERE') !== false)
                    ? $fonte['sql'] . " AND u.id > :ultimo ORDER BY u.id ASC LIMIT " . self::FANOUT_BATCH
                    : $fonte['sql'] . " WHERE u.id > :ultimo ORDER BY u.id ASC LIMIT " . self::FANOUT_BATCH;
                $st = $db->prepare($sqlFinal);
                $st->execute([':ultimo' => $ultimoId]);
                $ids = $st->fetchAll(PDO::FETCH_COLUMN);

                if (empty($ids)) break;

                // INSERT IGNORE em batch
                $values = [];
                $params = [];
                foreach ($ids as $i => $uid) {
                    $values[] = "(:n{$i}, :t{$i}, :d{$i})";
                    $params[":n{$i}"] = $notifId;
                    $params[":t{$i}"] = $fonte['tipo'];
                    $params[":d{$i}"] = (int)$uid;
                }
                $ins = $db->prepare(
                    "INSERT IGNORE INTO notificacao_usuarios
                     (notificacao_id, destinatario_tipo, destinatario_id)
                     VALUES " . implode(',', $values)
                );
                $ins->execute($params);
                $total += $ins->rowCount();

                $ultimoId = (int)end($ids);
                if (count($ids) < self::FANOUT_BATCH) break;
            }
        }

        return $total;
    }

    // =========================================================================
    // LEITURA (modal e badge)
    // =========================================================================

    /**
     * Lista notificações de um destinatário, mais recentes primeiro.
     *
     * @param string $destTipo 'cliente' | 'admin'
     * @param int    $destId
     * @param array  $filtros  ['categoria' => ?, 'apenas_nao_lidas' => bool,
     *                          'limite' => int, 'offset' => int]
     */
    public static function listar(string $destTipo, int $destId, array $filtros = []): array
    {
        try {
            $db = Database::getInstance()->getConnection();

            $limite = max(1, min(100, (int)($filtros['limite'] ?? 20)));
            $offset = max(0, (int)($filtros['offset'] ?? 0));

            $where  = "nu.destinatario_tipo = :dt AND nu.destinatario_id = :di";
            $params = [':dt' => $destTipo, ':di' => $destId];

            if (!empty($filtros['categoria']) && in_array($filtros['categoria'], self::CATEGORIAS, true)) {
                $where .= " AND n.categoria = :cat";
                $params[':cat'] = $filtros['categoria'];
            }
            if (!empty($filtros['apenas_nao_lidas'])) {
                $where .= " AND nu.lida = 0";
            }

            $st = $db->prepare(
                "SELECT nu.id AS nu_id, nu.lida, nu.lida_em, nu.criado_em AS recebido_em,
                        n.id AS notificacao_id, n.categoria, n.tipo, n.titulo,
                        n.mensagem, n.url, n.imagem_url, n.criado_em
                 FROM notificacao_usuarios nu
                 JOIN notificacoes n ON n.id = nu.notificacao_id
                 WHERE $where
                 ORDER BY nu.criado_em DESC, nu.id DESC
                 LIMIT $limite OFFSET $offset"
            );
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Anexa estilo da categoria
            foreach ($rows as &$r) {
                $est = self::ESTILO_CATEGORIA[$r['categoria']] ?? ['icone' => 'bi-bell', 'cor' => '#71717a'];
                $r['icone']           = $est['icone'];
                $r['cor']             = $est['cor'];
                $r['categoria_label'] = self::LABELS_CATEGORIA[$r['categoria']] ?? $r['categoria'];
            }
            unset($r);

            return $rows;

        } catch (Throwable $e) {
            self::logErro('listar', $e);
            return [];
        }
    }

    /**
     * Conta não lidas — query do badge (usa índice idx_dest_lida).
     */
    public static function contarNaoLidas(string $destTipo, int $destId): int
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT COUNT(*) FROM notificacao_usuarios
                 WHERE destinatario_tipo = :dt AND destinatario_id = :di AND lida = 0"
            );
            $st->execute([':dt' => $destTipo, ':di' => $destId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // =========================================================================
    // ESCRITA DE ESTADO
    // =========================================================================

    /**
     * Marca uma notificação como lida (valida que pertence ao destinatário).
     */
    public static function marcarLida(int $nuId, string $destTipo, int $destId): bool
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "UPDATE notificacao_usuarios
                 SET lida = 1, lida_em = NOW()
                 WHERE id = :id AND destinatario_tipo = :dt AND destinatario_id = :di AND lida = 0"
            );
            $st->execute([':id' => $nuId, ':dt' => $destTipo, ':di' => $destId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function marcarTodasLidas(string $destTipo, int $destId): int
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "UPDATE notificacao_usuarios
                 SET lida = 1, lida_em = NOW()
                 WHERE destinatario_tipo = :dt AND destinatario_id = :di AND lida = 0"
            );
            $st->execute([':dt' => $destTipo, ':di' => $destId]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // =========================================================================
    // INTERNOS
    // =========================================================================

    private static function inserirMae(PDO $db, array $dados, string $alvo, string $fanoutStatus): ?int
    {
        $categoria = in_array($dados['categoria'] ?? '', self::CATEGORIAS, true)
            ? $dados['categoria'] : 'sistema';

        $st = $db->prepare(
            "INSERT INTO notificacoes
             (categoria, tipo, titulo, mensagem, url, imagem_url, contexto_json,
              alvo_tipo, fanout_status, criado_por_tipo, criado_por_id, expira_em)
             VALUES
             (:cat, :tipo, :tit, :msg, :url, :img, :ctx,
              :alvo, :fst, :cpt, :cpi, :exp)"
        );
        $st->execute([
            ':cat'  => $categoria,
            ':tipo' => mb_substr(trim($dados['tipo'] ?? 'geral'), 0, 60),
            ':tit'  => mb_substr(trim($dados['titulo'] ?? ''), 0, 160),
            ':msg'  => $dados['mensagem'] ?? null,
            ':url'  => $dados['url'] ?? null,
            ':img'  => $dados['imagem_url'] ?? null,
            ':ctx'  => isset($dados['contexto'])
                        ? json_encode($dados['contexto'], JSON_UNESCAPED_UNICODE) : null,
            ':alvo' => $alvo,
            ':fst'  => $fanoutStatus,
            ':cpt'  => $dados['criado_por_tipo'] ?? 'sistema',
            ':cpi'  => $dados['criado_por_id'] ?? null,
            ':exp'  => $dados['expira_em'] ?? null,
        ]);
        return (int)$db->lastInsertId() ?: null;
    }

    private static function inserirFilhas(PDO $db, int $notifId, array $destinatarios): int
    {
        $values = [];
        $params = [];
        $i = 0;
        foreach ($destinatarios as $d) {
            $tipo = $d['tipo'] ?? '';
            $id   = (int)($d['id'] ?? 0);
            if (!in_array($tipo, ['cliente','admin'], true) || $id <= 0) continue;
            $values[] = "(:n{$i}, :t{$i}, :d{$i})";
            $params[":n{$i}"] = $notifId;
            $params[":t{$i}"] = $tipo;
            $params[":d{$i}"] = $id;
            $i++;
        }
        if (!$values) return 0;

        $ins = $db->prepare(
            "INSERT IGNORE INTO notificacao_usuarios
             (notificacao_id, destinatario_tipo, destinatario_id)
             VALUES " . implode(',', $values)
        );
        $ins->execute($params);
        return $ins->rowCount();
    }

    private static function logErro(string $onde, Throwable $e): void
    {
        if (class_exists('LogService')) {
            try { LogService::error("NotificacaoService::$onde: " . $e->getMessage()); } catch (Throwable $x) {}
        }
    }

    
}
