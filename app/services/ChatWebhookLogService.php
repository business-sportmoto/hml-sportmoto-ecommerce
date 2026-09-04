<?php
declare(strict_types=1);

// ═════════════════════════════════════════════════════════════════════════════
// app/services/ChatWebhookLogService.php
//
// Consulta do log de chamadas do webhook (WhatsApp e Instagram).
//
// ── Por que a coluna `erro` não vira "deu erro" na tela ────────────────────
// `logWebhook()` grava em `erro` o motivo de a chamada não ter virado ação —
// e a maior parte desses motivos NÃO é falha: "nenhuma regra casou",
// "comentário da própria conta", "comentário já processado". São decisões
// corretas do sistema. Pintar tudo isso de vermelho é gritar lobo: em duas
// semanas ninguém olha mais o log.
//
// Então o estado sai de três booleanos, sem depender do texto:
//
//   assinatura_ok = 0                    → recusado   (vermelho: segredo errado)
//   processado    = 1                    → ok         (verde)
//   processado = 0 e erro IS NULL        → ignorado   (cinza: registrado, sem ação)
//   processado = 0 e erro IS NOT NULL    → sem_acao   (âmbar: não agiu, e diz por quê)
//
// O motivo aparece sempre, na íntegra, na linha e no detalhe.
//
// ── Canal ─────────────────────────────────────────────────────────────────
// A tabela não tem coluna de canal. O evento carrega a origem: tudo que vem do
// Instagram é gravado com prefixo `ig_`. `recusado` e `invalido` acontecem
// ANTES de saber de quem é a chamada — por isso são canal próprio ("entrada"),
// e não WhatsApp por omissão.
// ═════════════════════════════════════════════════════════════════════════════

class ChatWebhookLogService
{
    public const POR_PAGINA = 25;

    /** Estados derivados. A ordem é a da severidade decrescente. */
    public const ESTADOS = ['recusado', 'sem_acao', 'ignorado', 'ok'];

    public const CANAIS = ['whatsapp', 'instagram', 'entrada'];

    /** Nome de gente para os eventos que o sistema grava. */
    private const ROTULOS = [
        'messages'                      => 'Mensagem recebida',
        'statuses'                      => 'Status de entrega',
        'message_template_status_update' => 'Template aprovado/reprovado',
        'ig_comentario'                 => 'Comentário no Instagram',
        'ig_dm'                         => 'Direct do Instagram',
        'ig_comments'                   => 'Comentário (evento cru)',
        'ig_live_comments'              => 'Comentário em live',
        'ig_messages'                   => 'Direct (evento cru)',
        'ig_mentions'                   => 'Menção no Instagram',
        'recusado'                      => 'Assinatura recusada',
        'invalido'                      => 'Corpo inválido',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // LISTAGEM
    // =========================================================================

    /** @return array{itens:array,total:int,pagina:int,paginas:int,de:int,ate:int,filtros:array} */
    public function listar(array $f, int $pagina = 1): array
    {
        $f      = $this->normalizarFiltros($f);
        $pagina = max(1, $pagina);

        [$where, $par] = $this->montarWhere($f);

        $total   = (int)$this->umValor("SELECT COUNT(*) FROM chat_webhook_log l WHERE {$where}", $par);
        $paginas = max(1, (int)ceil($total / self::POR_PAGINA));
        $pagina  = min($pagina, $paginas);
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        // payload_json fica de fora da listagem: é um LONGTEXT que chega a
        // 60 KB por linha, e 25 deles é meio mega para desenhar seis colunas.
        // Só o tamanho vem, para o detalhe saber se há o que abrir.
        $st = $this->db->prepare(
            "SELECT l.id, l.evento, l.wamid, l.assinatura_ok, l.processado,
                    l.erro, l.ip, l.criado_em,
                    CHAR_LENGTH(COALESCE(l.payload_json, '')) AS tam_payload
               FROM chat_webhook_log l
              WHERE {$where}
           ORDER BY l.criado_em DESC, l.id DESC
              LIMIT " . self::POR_PAGINA . " OFFSET {$offset}"
        );
        $st->execute($par);

        return [
            'itens'   => array_map([$this, 'decorar'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
            'de'      => $total ? $offset + 1 : 0,
            'ate'     => min($offset + self::POR_PAGINA, $total),
            'filtros' => $f,
        ];
    }

    /** Uma chamada inteira, com o payload já formatado para leitura. */
    public function detalhe(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_webhook_log WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $log = $st->fetch(PDO::FETCH_ASSOC);
        if (!$log) return null;

        $log['tam_payload']  = mb_strlen((string)($log['payload_json'] ?? ''));
        $log                 = $this->decorar($log);
        $log['payload_fmt']  = self::formatarJson($log['payload_json'] ?? null);
        $log['resumo_dados'] = $this->resumoDoPayload((string)($log['payload_json'] ?? ''), (string)$log['evento']);

        return $log;
    }

    /**
     * Eventos que existem na tabela, com contagem.
     *
     * Sai do banco em vez de uma constante porque a Meta acrescenta campo de
     * webhook sem avisar: uma lista fixa esconderia do filtro justamente o
     * evento novo que ninguém sabe o que é.
     */
    public function eventos(): array
    {
        try {
            return $this->db->query(
                "SELECT evento, COUNT(*) AS n
                   FROM chat_webhook_log
                  WHERE evento IS NOT NULL AND evento <> ''
               GROUP BY evento
               ORDER BY n DESC, evento ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Contagem por estado no período filtrado — o resumo acima da tabela. */
    public function resumo(array $f): array
    {
        $f = $this->normalizarFiltros($f);
        // O próprio estado sai do filtro: o resumo mostra a divisão do período,
        // não da seleção. Filtrar por "recusado" e ver "recusado: 12 de 12" não
        // informa nada.
        $f['estado'] = '';

        [$where, $par] = $this->montarWhere($f);

        $st = $this->db->prepare(
            "SELECT " . self::SQL_ESTADO . " AS estado, COUNT(*) AS n
               FROM chat_webhook_log l
              WHERE {$where}
           GROUP BY estado"
        );
        $st->execute($par);

        $out = array_fill_keys(self::ESTADOS, 0);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['estado']] = (int)$r['n'];
        }
        return $out;
    }

    // =========================================================================
    // FILTROS
    // =========================================================================

    /** A mesma regra do `estado()`, em SQL — para filtrar e agrupar no banco. */
    private const SQL_ESTADO = "
        CASE WHEN l.assinatura_ok = 0 THEN 'recusado'
             WHEN l.processado    = 1 THEN 'ok'
             WHEN l.erro IS NOT NULL AND l.erro <> '' THEN 'sem_acao'
             ELSE 'ignorado' END";

    private function normalizarFiltros(array $f): array
    {
        $limpo = [
            'de'     => $this->data($f['de']  ?? ''),
            'ate'    => $this->data($f['ate'] ?? ''),
            'evento' => preg_match('/^[a-z0-9_]{1,40}$/i', (string)($f['evento'] ?? ''))
                          ? (string)$f['evento'] : '',
            'canal'  => in_array($f['canal']  ?? '', self::CANAIS,  true) ? $f['canal']  : '',
            'estado' => in_array($f['estado'] ?? '', self::ESTADOS, true) ? $f['estado'] : '',
            'busca'  => trim((string)($f['busca'] ?? '')),
        ];

        // Período invertido é erro de digitação, não filtro vazio.
        if ($limpo['de'] && $limpo['ate'] && $limpo['de'] > $limpo['ate']) {
            [$limpo['de'], $limpo['ate']] = [$limpo['ate'], $limpo['de']];
        }

        return $limpo;
    }

    private function montarWhere(array $f): array
    {
        $where = ['1=1'];
        $par   = [];

        if ($f['de'])     { $where[] = 'l.criado_em >= :de';  $par[':de']  = $f['de']  . ' 00:00:00'; }
        if ($f['ate'])    { $where[] = 'l.criado_em <= :ate'; $par[':ate'] = $f['ate'] . ' 23:59:59'; }
        if ($f['evento']) { $where[] = 'l.evento = :ev';      $par[':ev']  = $f['evento']; }

        if ($f['canal']) {
            $where[] = match ($f['canal']) {
                'instagram' => "l.evento LIKE 'ig\\_%'",
                'entrada'   => "l.evento IN ('recusado','invalido')",
                default     => "l.evento NOT LIKE 'ig\\_%' AND l.evento NOT IN ('recusado','invalido')",
            };
        }

        if ($f['estado']) {
            $where[] = self::SQL_ESTADO . ' = :est';
            $par[':est'] = $f['estado'];
        }

        // A busca cobre wamid (indexado), IP e evento — campos curtos.
        // O payload NÃO entra: é LONGTEXT sem índice, e varrer 60 KB por linha
        // para achar um trecho custa mais do que a tela vale. Para achar por
        // conteúdo, filtre por data e evento e abra o detalhe.
        if ($f['busca'] !== '') {
            $where[] = '(l.wamid LIKE :q OR l.ip LIKE :q2 OR l.evento LIKE :q3)';
            $termo = '%' . $f['busca'] . '%';
            $par[':q'] = $par[':q2'] = $par[':q3'] = $termo;
        }

        return [implode(' AND ', $where), $par];
    }

    // =========================================================================
    // DECORAÇÃO
    // =========================================================================

    private function decorar(array $log): array
    {
        $evento = (string)($log['evento'] ?? '');

        $log['estado']     = self::estado($log);
        $log['canal']      = self::canal($evento);
        $log['rotulo']     = self::rotulo($evento);
        $log['criado_br']  = !empty($log['criado_em'])
            ? date('d/m/Y H:i:s', strtotime((string)$log['criado_em'])) : '';
        $log['tam_legivel'] = self::tamanho((int)($log['tam_payload'] ?? 0));

        return $log;
    }

    /** Ver o cabeçalho do arquivo: por que não é só "tem erro ou não". */
    public static function estado(array $log): string
    {
        if ((int)($log['assinatura_ok'] ?? 1) === 0) return 'recusado';
        if ((int)($log['processado'] ?? 0) === 1)    return 'ok';
        return trim((string)($log['erro'] ?? '')) !== '' ? 'sem_acao' : 'ignorado';
    }

    public static function canal(string $evento): string
    {
        if ($evento === 'recusado' || $evento === 'invalido') return 'entrada';
        return str_starts_with($evento, 'ig_') ? 'instagram' : 'whatsapp';
    }

    public static function rotulo(string $evento): string
    {
        if ($evento === '') return 'sem evento';
        if (isset(self::ROTULOS[$evento])) return self::ROTULOS[$evento];

        // Evento que a Meta passou a mandar e ainda não tem nome aqui: mostra o
        // slug legível em vez de esconder.
        $limpo = str_replace('_', ' ', preg_replace('/^ig_/', '', $evento));
        return ucfirst($limpo);
    }

    /** JSON indentado; se não for JSON, o texto cru (é o que a Meta mandou). */
    public static function formatarJson(?string $bruto): ?string
    {
        $bruto = trim((string)$bruto);
        if ($bruto === '') return null;

        $dados = json_decode($bruto, true);
        if (json_last_error() !== JSON_ERROR_NONE) return $bruto;

        return json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * O que interessa no payload, em três ou quatro linhas.
     *
     * Abrir 60 KB de JSON para descobrir quem mandou "quanto custa?" é o tipo
     * de trabalho que faz ninguém abrir o log. Isto responde antes.
     */
    private function resumoDoPayload(string $bruto, string $evento): array
    {
        $d = json_decode($bruto, true);
        if (!is_array($d)) return [];

        $out = [];
        $pegar = function (array $caminhos) use ($d) {
            foreach ($caminhos as $c) {
                $v = $d;
                foreach (explode('.', $c) as $k) {
                    if (!is_array($v) || !array_key_exists($k, $v)) { $v = null; break; }
                    $v = $v[$k];
                }
                if (is_scalar($v) && (string)$v !== '') return (string)$v;
            }
            return null;
        };

        if ($de = $pegar(['from', 'from.id', 'value.from.id', 'sender.id']))        $out['De'] = $de;
        if ($us = $pegar(['from.username', 'value.from.username', 'username']))     $out['Perfil'] = '@' . $us;
        if ($tx = $pegar(['text.body', 'text', 'value.text', 'message.text']))      $out['Texto'] = $tx;
        if ($tp = $pegar(['type', 'value.item']))                                   $out['Tipo'] = $tp;
        if ($md = $pegar(['value.media.id', 'media.id']))                           $out['Mídia'] = $md;
        if ($st = $pegar(['status', 'value.status']))                               $out['Status'] = $st;

        return $out;
    }

    private static function tamanho(int $bytes): string
    {
        if ($bytes <= 0)    return '';
        if ($bytes < 1024)  return $bytes . ' B';
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    // =========================================================================
    // AUXILIARES
    // =========================================================================

    private function data(string $v): string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    private function umValor(string $sql, array $par)
    {
        $st = $this->db->prepare($sql);
        $st->execute($par);
        return $st->fetchColumn();
    }
}
