<?php
/**
 * app/services/CanalLogService.php
 *
 * Serviço genérico de log para qualquer canal de comunicação.
 * Plugável: WhatsApp, Email, SMS, Push Notification — tudo passa por aqui.
 *
 * USO BÁSICO:
 *   CanalLogService::gravar('whatsapp', 'pedido_confirmado', [
 *       'destinatario'  => '5547999998888',
 *       'status'        => 'enviado',
 *       'pedido_id'     => 1234,
 *       'pedido_codigo' => 'SM-001',
 *       'preview'       => '✅ Pedido confirmado! Olá João...',
 *       'cliente_id'    => 567,
 *       'via'           => 'worker',
 *   ]);
 *
 * NUNCA lança exceção — falha de log não pode derrubar o sistema.
 */
class CanalLogService
{
    /** @var int Limite do preview gravado no banco */
    private const PREVIEW_LEN = 220;

    // =========================================================================
    // GRAVAÇÃO
    // =========================================================================

    /**
     * Grava uma entrada de log no canal_log.
     *
     * @param string $canal       'whatsapp' | 'email' | 'sms' | 'push'
     * @param string $tipo        Tipo do evento (ex: 'pedido_confirmado')
     * @param array  $dados       Campos aceitos:
     *   - destinatario  string  (obrigatório) telefone, email, token
     *   - status        string  'enviado'|'erro'|'sem_canal'|'pendente'|'cancelado'
     *   - cliente_id    int
     *   - pedido_id     int
     *   - pedido_codigo string
     *   - assunto       string  (email)
     *   - preview       string  primeiros chars da mensagem
     *   - mensagem      string  (alias de preview — será truncada)
     *   - template_id   int
     *   - provider_msg_id string
     *   - erro_detalhe  string
     *   - dedup_chave   string
     *   - contexto      array   dados extras (serializados como JSON)
     *   - via           string  'api'|'worker'|'manual'|'template'
     * @return int|null ID inserido ou null em caso de falha
     */
    public static function gravar(string $canal, string $tipo, array $dados): ?int
    {
        try {
            $db = Database::getInstance()->getConnection();

            $preview = $dados['preview'] ?? $dados['mensagem'] ?? '';
            $preview = self::truncarPreview((string)$preview);

            $contexto = null;
            if (!empty($dados['contexto']) && is_array($dados['contexto'])) {
                $sem = array_diff_key($dados['contexto'], array_flip(['senha','token','secret','password']));
                $contexto = json_encode($sem, JSON_UNESCAPED_UNICODE);
            }

            $st = $db->prepare(
                "INSERT INTO canal_log
                 (canal, tipo, cliente_id, pedido_id, pedido_codigo,
                  destinatario, assunto, preview, template_id,
                  status, provider_msg_id, erro_detalhe,
                  dedup_chave, contexto_json, via)
                 VALUES
                 (:canal,:tipo,:cid,:pid,:pcod,
                  :dest,:ass,:prev,:tid,
                  :st,:pmid,:err,
                  :dk,:ctx,:via)"
            );
            $st->execute([
                ':canal' => mb_substr($canal, 0, 30),
                ':tipo'  => mb_substr($tipo,  0, 60),
                ':cid'   => isset($dados['cliente_id'])    ? (int)$dados['cliente_id']   : null,
                ':pid'   => isset($dados['pedido_id'])     ? (int)$dados['pedido_id']    : null,
                ':pcod'  => isset($dados['pedido_codigo']) ? mb_substr((string)$dados['pedido_codigo'], 0, 40) : null,
                ':dest'  => mb_substr((string)($dados['destinatario'] ?? ''), 0, 180),
                ':ass'   => isset($dados['assunto'])        ? mb_substr((string)$dados['assunto'], 0, 255) : null,
                ':prev'  => $preview ?: null,
                ':tid'   => isset($dados['template_id'])   ? (int)$dados['template_id'] : null,
                ':st'    => $dados['status'] ?? 'enviado',
                ':pmid'  => isset($dados['provider_msg_id']) ? mb_substr((string)$dados['provider_msg_id'], 0, 255) : null,
                ':err'   => $dados['erro_detalhe'] ?? null,
                ':dk'    => isset($dados['dedup_chave'])   ? mb_substr((string)$dados['dedup_chave'], 0, 190) : null,
                ':ctx'   => $contexto,
                ':via'   => isset($dados['via'])           ? mb_substr((string)$dados['via'], 0, 30) : null,
            ]);
            return (int)$db->lastInsertId() ?: null;

        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error("CanalLogService::gravar [{$canal}/{$tipo}]: " . $e->getMessage()); } catch (Throwable $x) {}
            }
            return null;
        }
    }

    // =========================================================================
    // CONSULTAS
    // =========================================================================

    /**
     * Busca registros com filtros opcionais e paginação.
     *
     * @param array $filtros canal, tipo, status, cliente_id, pedido_id,
     *                       pedido_codigo, destinatario, busca (texto livre),
     *                       data_inicio, data_fim
     * @param int   $limit
     * @param int   $offset
     * @return array{itens: array, total: int}
     */
    public static function buscar(array $filtros = [], int $limit = 50, int $offset = 0): array
    {
        try {
            $db     = Database::getInstance()->getConnection();
            $where  = ['1=1'];
            $params = [];

            $mapa = [
                'canal'         => ['campo' => 'canal',         'op' => '='],
                'tipo'          => ['campo' => 'tipo',          'op' => '='],
                'status'        => ['campo' => 'status',        'op' => '='],
                'cliente_id'    => ['campo' => 'cliente_id',    'op' => '='],
                'pedido_id'     => ['campo' => 'pedido_id',     'op' => '='],
                'pedido_codigo' => ['campo' => 'pedido_codigo', 'op' => 'LIKE'],
                'destinatario'  => ['campo' => 'destinatario',  'op' => 'LIKE'],
                'via'           => ['campo' => 'via',           'op' => '='],
            ];

            foreach ($mapa as $filtro => $cfg) {
                if (isset($filtros[$filtro]) && $filtros[$filtro] !== '') {
                    $v = $filtros[$filtro];
                    $p = ':f_' . $filtro;
                    if ($cfg['op'] === 'LIKE') {
                        $where[] = "{$cfg['campo']} LIKE $p";
                        $params[$p] = '%' . $v . '%';
                    } else {
                        $where[] = "{$cfg['campo']} $p";
                        $params[$p] = $v;
                    }
                }
            }

            // Busca textual livre
            if (!empty($filtros['busca'])) {
                $b = '%' . $filtros['busca'] . '%';
                $where[] = '(destinatario LIKE :bq OR pedido_codigo LIKE :bq2
                              OR preview LIKE :bq3 OR tipo LIKE :bq4)';
                $params[':bq'] = $params[':bq2'] = $params[':bq3'] = $params[':bq4'] = $b;
            }

            // Intervalo de datas
            if (!empty($filtros['data_inicio'])) {
                $where[] = 'criado_em >= :di';
                $params[':di'] = $filtros['data_inicio'] . ' 00:00:00';
            }
            if (!empty($filtros['data_fim'])) {
                $where[] = 'criado_em <= :df';
                $params[':df'] = $filtros['data_fim'] . ' 23:59:59';
            }

            $whereStr = implode(' AND ', $where);
            $limit    = max(1, min(500, $limit));
            $offset   = max(0, $offset);

            $stCount = $db->prepare("SELECT COUNT(*) FROM canal_log WHERE $whereStr");
            $stCount->execute($params);
            $total = (int)$stCount->fetchColumn();

            $stItens = $db->prepare(
                "SELECT * FROM canal_log WHERE $whereStr
                 ORDER BY id DESC LIMIT $limit OFFSET $offset"
            );
            $stItens->execute($params);
            $itens = $stItens->fetchAll(PDO::FETCH_ASSOC);

            return ['itens' => $itens, 'total' => $total];

        } catch (Throwable $e) {
            return ['itens' => [], 'total' => 0];
        }
    }

    /**
     * KPIs agregados por canal e período.
     *
     * @param int    $dias    Janela em dias (padrão 30)
     * @param string $canal   Filtrar por canal (vazio = todos)
     */
    public static function kpis(int $dias = 30, string $canal = ''): array
    {
        try {
            $db     = Database::getInstance()->getConnection();
            $where  = 'criado_em > DATE_SUB(NOW(), INTERVAL :dias DAY)';
            $params = [':dias' => $dias];
            if ($canal !== '') { $where .= ' AND canal = :canal'; $params[':canal'] = $canal; }

            $r = $db->prepare(
                "SELECT
                    COUNT(*)                                AS total,
                    SUM(status = 'enviado')                 AS enviados,
                    SUM(status = 'erro')                    AS erros,
                    SUM(status = 'sem_canal')               AS sem_canal,
                    SUM(status = 'cancelado')               AS cancelados,
                    COUNT(DISTINCT cliente_id)              AS clientes_distintos,
                    COUNT(DISTINCT pedido_id)               AS pedidos_distintos,
                    ROUND(SUM(status='enviado') / NULLIF(COUNT(*),0) * 100, 1) AS taxa_sucesso
                 FROM canal_log WHERE $where"
            );
            $r->execute($params);
            return $r->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Breakdown por canal nos últimos N dias.
     */
    public static function porCanal(int $dias = 30): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT canal,
                        COUNT(*)               AS total,
                        SUM(status='enviado')  AS enviados,
                        SUM(status='erro')     AS erros,
                        MAX(criado_em)         AS ultimo_envio
                 FROM canal_log
                 WHERE criado_em > DATE_SUB(NOW(), INTERVAL :dias DAY)
                 GROUP BY canal ORDER BY total DESC"
            );
            $st->execute([':dias' => $dias]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Todos os envios de um pedido (qualquer canal).
     */
    public static function porPedido(int $pedidoId): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT * FROM canal_log
                 WHERE pedido_id = :pid
                 ORDER BY criado_em ASC"
            );
            $st->execute([':pid' => $pedidoId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Todos os envios de um cliente (qualquer canal).
     */
    public static function porCliente(int $clienteId, int $limit = 50): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare(
                "SELECT * FROM canal_log
                 WHERE cliente_id = :cid
                 ORDER BY id DESC LIMIT $limit"
            );
            $st->execute([':cid' => $clienteId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Tipos distintos gravados (útil para construir filtros dinâmicos).
     */
    public static function tiposDistintos(?string $canal = null): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            if ($canal) {
                $st = $db->prepare("SELECT DISTINCT tipo FROM canal_log WHERE canal = :c ORDER BY tipo");
                $st->execute([':c' => $canal]);
            } else {
                $st = $db->query("SELECT DISTINCT tipo FROM canal_log ORDER BY tipo");
            }
            return $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Canais distintos gravados.
     */
    public static function canaisDistintos(): array
    {
        try {
            $db = Database::getInstance()->getConnection();
            return $db->query(
                "SELECT DISTINCT canal FROM canal_log ORDER BY canal"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function truncarPreview(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r", "\n"], ' ', $texto);
        $texto = preg_replace('/\s{2,}/', ' ', $texto);
        $texto = trim($texto);
        if (function_exists('mb_strlen') && mb_strlen($texto, 'UTF-8') > self::PREVIEW_LEN) {
            return mb_substr($texto, 0, self::PREVIEW_LEN - 1, 'UTF-8') . '…';
        }
        return strlen($texto) > self::PREVIEW_LEN
            ? substr($texto, 0, self::PREVIEW_LEN - 1) . '…'
            : $texto;
    }
}
