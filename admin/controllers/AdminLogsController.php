<?php
declare(strict_types=1);

// admin/controllers/AdminLogsController.php

/**
 * Dashboard de logs e erros.
 *
 * ACESSO RESTRITO A `super`.
 * POR QUÊ: logs contêm URLs, IPs, IDs de usuário, contexto de requisição e
 * stack traces — a superfície mais sensível do painel. Um `vendedor` ou
 * `editor` não tem razão de ver isso (least privilege, OWASP A01).
 *
 * O rendering escapa TUDO: mensagem, URL e user-agent são parcialmente
 * CONTROLADOS PELO ATACANTE (um scanner injeta payload na URL, e isso vira
 * uma linha na tabela). Renderizar sem escape = stored XSS no próprio painel.
 */
final class AdminLogsController extends Controller
{
    private const POR_PAGINA = 40;

    public function __construct()
    {
        AuthHelper::requireAdmin();

        if (Session::get('admin_nivel') !== 'super') {
            Session::flash('error', 'Acesso restrito.');
            $this->redirect(ADMIN_URL . '/dashboard');
        }
    }

    // ── Listagem + filtros ───────────────────────────────────────────────

    public function index(): void
    {
        $f = $this->filtros();

        [$where, $params] = $this->montarWhere($f);

        $db = Database::getInstance()->getConnection();

        // Total (para paginação)
        $stmt = $db->prepare("SELECT COUNT(*) FROM logs WHERE {$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Página
        $offset = ($f['page'] - 1) * self::POR_PAGINA;
        $stmt = $db->prepare(
            "SELECT id, nivel, canal, mensagem, tipo, arquivo, linha,
                    usuario_id, ip, url, metodo, request_id,
                    ocorrencias, resolvido, criado_em, visto_em
               FROM logs
              WHERE {$where}
              ORDER BY {$this->orderBy($f['ordem'])}
              LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', self::POR_PAGINA, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        if (!empty($_GET['json'])) {
            $this->json([
                'ok'       => true,
                'logs'     => $logs,
                'total'    => $total,
                'page'     => $f['page'],
                'has_more' => ($f['page'] * self::POR_PAGINA) < $total,
            ]);
        }

        $this->render('logs/index', [
            'logs'    => $logs,
            'total'   => $total,
            'filtros' => $f,
            'stats'   => $this->stats(),
            'canais'  => $this->canaisDisponiveis(),
            'páginas' => (int) ceil($total / self::POR_PAGINA),
        ], 'admin');
    }

    // ── Detalhe (JSON, para o painel lateral) ────────────────────────────

    public function detalhe(): void
    {
        $id = SecurityHelper::sanitizeInt($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->json(['ok' => false, 'msg' => 'ID inválido.']);
        }

        $stmt = Database::getInstance()->getConnection()
            ->prepare("SELECT * FROM logs WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $log = $stmt->fetch();

        if (!$log) {
            $this->json(['ok' => false, 'msg' => 'Log não encontrado.']);
        }

        // Contexto vem como JSON string — devolve decodificado.
        $log['contexto'] = $log['contexto']
            ? json_decode((string) $log['contexto'], true)
            : null;

        // Logs irmãos: mesma requisição. É o que reconstrói a história do erro.
        $irmaos = [];
        if (!empty($log['request_id'])) {
            $st = Database::getInstance()->getConnection()->prepare(
                "SELECT id, nivel, canal, mensagem, criado_em
                   FROM logs
                  WHERE request_id = ? AND id <> ?
                  ORDER BY id ASC LIMIT 20"
            );
            $st->execute([$log['request_id'], $id]);
            $irmaos = $st->fetchAll();
        }

        $this->json(['ok' => true, 'log' => $log, 'irmaos' => $irmaos]);
    }

    // ── Triagem ──────────────────────────────────────────────────────────

    /** Marca/desmarca como resolvido. */
    public function resolver(): void
    {
        $this->verifyCsrf();

        $id    = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $valor = !empty($_POST['resolvido']) ? 1 : 0;
        if ($id <= 0) {
            $this->json(['ok' => false]);
        }

        Database::getInstance()->getConnection()
            ->prepare("UPDATE logs SET resolvido = ? WHERE id = ?")
            ->execute([$valor, $id]);

        $this->json(['ok' => true, 'resolvido' => $valor]);
    }

    /** Remove logs. Sem filtro = purge por idade (padrão do cron). */
    public function limpar(): void
    {
        $this->verifyCsrf();

        $escopo = $_POST['escopo'] ?? 'antigos';
        $db     = Database::getInstance()->getConnection();

        $removidos = match ($escopo) {
            // Só o ruído: debug/info com mais de 7 dias
            'antigos'   => $this->exec($db,
                "DELETE FROM logs
                  WHERE nivel IN ('debug','info')
                    AND criado_em < (UTC_TIMESTAMP() - INTERVAL 7 DAY)"),
            // Erros já triados
            'resolvidos' => $this->exec($db,
                "DELETE FROM logs WHERE resolvido = 1"),
            default     => 0,
        };

        LogService::info('Limpeza de logs executada', [
            'escopo'    => $escopo,
            'removidos' => $removidos,
        ], 'admin');

        $this->json(['ok' => true, 'removidos' => $removidos]);
    }

    // ── internos ─────────────────────────────────────────────────────────

    /** @return array{nivel:string,canal:string,q:string,periodo:string,status:string,ordem:string,page:int} */
    private function filtros(): array
    {
        $niveis = ['debug', 'info', 'warning', 'error', 'critical'];

        $nivel = (string) ($_GET['nivel'] ?? '');
        if (!in_array($nivel, $niveis, true)) {
            $nivel = '';
        }

        $status = (string) ($_GET['status'] ?? 'abertos');
        if (!in_array($status, ['abertos', 'resolvidos', 'todos'], true)) {
            $status = 'abertos';
        }

        $periodo = (string) ($_GET['periodo'] ?? '7d');
        if (!in_array($periodo, ['1h', '24h', '7d', '30d', 'tudo'], true)) {
            $periodo = '7d';
        }

        $ordem = (string) ($_GET['ordem'] ?? 'recentes');
        if (!in_array($ordem, ['recentes', 'frequentes'], true)) {
            $ordem = 'recentes';
        }

        return [
            'nivel'   => $nivel,
            'canal'   => SecurityHelper::sanitizeString($_GET['canal'] ?? ''),
            'q'       => SecurityHelper::sanitizeString($_GET['q'] ?? ''),
            'periodo' => $periodo,
            'status'  => $status,
            'ordem'   => $ordem,
            'page'    => max(1, (int) ($_GET['page'] ?? 1)),
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function montarWhere(array $f): array
    {
        $where  = ['1=1'];
        $params = [];

        if ($f['nivel'] !== '') {
            $where[]          = 'nivel = :nivel';
            $params[':nivel'] = $f['nivel'];
        }
        if ($f['canal'] !== '') {
            $where[]          = 'canal = :canal';
            $params[':canal'] = mb_substr($f['canal'], 0, 50);
        }
        if ($f['q'] !== '') {
            // Busca em mensagem, arquivo e URL — os três campos que você usa
            // para achar um erro. Prepared statement: sem risco de injeção.
            $where[]      = '(mensagem LIKE :q OR arquivo LIKE :q OR url LIKE :q OR tipo LIKE :q)';
            $params[':q'] = '%' . $f['q'] . '%';
        }

        $status = match ($f['status']) {
            'abertos'    => 'resolvido = 0',
            'resolvidos' => 'resolvido = 1',
            default      => null,
        };
        if ($status !== null) {
            $where[] = $status;
        }

        $intervalo = match ($f['periodo']) {
            '1h'    => 'INTERVAL 1 HOUR',
            '24h'   => 'INTERVAL 24 HOUR',
            '7d'    => 'INTERVAL 7 DAY',
            '30d'   => 'INTERVAL 30 DAY',
            default => null,
        };
        if ($intervalo !== null) {
            $where[] = "visto_em >= (UTC_TIMESTAMP() - {$intervalo})";
        }

        return [implode(' AND ', $where), $params];
    }

    /** Whitelist rígida — nunca interpolar entrada do usuário em ORDER BY. */
    private function orderBy(string $ordem): string
    {
        return match ($ordem) {
            'frequentes' => 'ocorrencias DESC, visto_em DESC',
            default      => 'visto_em DESC, id DESC',
        };
    }

    /** Contadores do topo — visão de saúde do sistema nas últimas 24h. */
    private function stats(): array
    {
        $db = Database::getInstance()->getConnection();

        $row = $db->query(
            "SELECT
                COALESCE(SUM(nivel = 'critical' AND resolvido = 0), 0) AS criticos,
                COALESCE(SUM(nivel = 'error'    AND resolvido = 0), 0) AS erros,
                COALESCE(SUM(nivel = 'warning'  AND resolvido = 0), 0) AS avisos,
                COALESCE(SUM(ocorrencias), 0)                          AS eventos
               FROM logs
              WHERE visto_em >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)"
        )->fetch();

        return [
            'criticos' => (int) ($row['criticos'] ?? 0),
            'erros'    => (int) ($row['erros'] ?? 0),
            'avisos'   => (int) ($row['avisos'] ?? 0),
            'eventos'  => (int) ($row['eventos'] ?? 0),
        ];
    }

    /** @return string[] */
    private function canaisDisponiveis(): array
    {
        $rows = Database::getInstance()->getConnection()
            ->query("SELECT DISTINCT canal FROM logs ORDER BY canal ASC")
            ->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows ?: []);
    }

    private function exec(PDO $db, string $sql): int
    {
        $stmt = $db->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }
}