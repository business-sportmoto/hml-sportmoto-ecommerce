<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/BlingLogService.php
//
// Consulta do log de operações do Bling.
//
// ── Sobre filtrar por produto ─────────────────────────────────────────────
// A tabela não guarda produto_id nem sku_legado — ela guarda `referencia_id`,
// que é o CAMINHO da chamada à API: "/produtos/16700340150". O número no fim é
// o id do produto no Bling.
//
// A ponte de volta existe: `produtos.bling_id` e `produto_skus.bling_id` são
// preenchidos pelo vínculo diário. Então filtrar por produto vira: resolver o
// produto para os bling_ids dele (o do produto e o de cada SKU) e comparar com
// o último segmento da referência.
//
// Isso é mais preciso do que procurar o número dentro do payload — que casaria
// com qualquer id parecido em qualquer posição do JSON.
// ════════════════════════════════════════════════════════

class BlingLogService
{
    public const POR_PAGINA = 25;

    /** Valores válidos, espelhando os ENUMs da tabela. */
    public const TIPOS    = ['pedido', 'estoque', 'produto', 'nfe', 'cliente', 'webhook', 'ignorado'];
    public const DIRECOES = ['push', 'pull', 'webhook'];
    public const STATUS   = ['ok', 'erro', 'pendente'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       LISTAGEM
       ================================================================= */

    /**
     * Lista paginada. Devolve ['itens', 'total', 'pagina', 'paginas', 'filtros'].
     */
    public function listar(array $f, int $pagina = 1): array
    {
        $f      = $this->normalizarFiltros($f);
        $pagina = max(1, $pagina);

        [$where, $par] = $this->montarWhere($f);

        $total = (int) $this->umValor(
            "SELECT COUNT(*) FROM bling_sync_log l WHERE {$where}", $par
        );

        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina  = min($pagina, $paginas);
        $offset  = ($pagina - 1) * self::POR_PAGINA;

        $st = $this->db->prepare(
            "SELECT l.id, l.tipo, l.direcao, l.referencia_id, l.status,
                    l.msg_erro, l.criado_em,
                    CHAR_LENGTH(COALESCE(l.payload, ''))  AS tam_payload,
                    CHAR_LENGTH(COALESCE(l.resposta, '')) AS tam_resposta
               FROM bling_sync_log l
              WHERE {$where}
           ORDER BY l.criado_em DESC, l.id DESC
              LIMIT " . self::POR_PAGINA . " OFFSET {$offset}"
        );
        // payload e resposta ficam de fora da listagem de propósito: são JSON
        // que pode ter alguns KB, e 25 linhas com dois deles cada é meio mega
        // trafegado para mostrar uma tabela de seis colunas.
        $st->execute($par);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'itens'   => array_map([$this, 'decorar'], $itens),
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
            'de'      => $total ? $offset + 1 : 0,
            'ate'     => min($offset + self::POR_PAGINA, $total),
            'filtros' => $f,
        ];
    }

    /** Uma entrada completa, com payload e resposta já formatados. */
    public function detalhe(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM bling_sync_log WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $log = $st->fetch(PDO::FETCH_ASSOC);
        if (!$log) return null;

        $log = $this->decorar($log);

        $log['payload_fmt']  = self::formatarJson($log['payload']  ?? null);
        $log['resposta_fmt'] = self::formatarJson($log['resposta'] ?? null);
        $log['produto']      = $this->produtoDaReferencia((string) $log['referencia_id']);

        return $log;
    }

    /* =================================================================
       FILTROS
       ================================================================= */

    private function normalizarFiltros(array $f): array
    {
        $limpo = [
            'de'          => $this->data($f['de']  ?? ''),
            'ate'         => $this->data($f['ate'] ?? ''),
            'tipo'        => in_array($f['tipo']    ?? '', self::TIPOS, true)    ? $f['tipo']    : '',
            'direcao'     => in_array($f['direcao'] ?? '', self::DIRECOES, true) ? $f['direcao'] : '',
            'status'      => in_array($f['status']  ?? '', self::STATUS, true)   ? $f['status']  : '',
            'produto_id'  => max(0, (int) ($f['produto_id'] ?? 0)),
            'sku_legado'  => trim((string) ($f['sku_legado'] ?? '')),
            'referencia'  => trim((string) ($f['referencia'] ?? '')),
        ];

        // Período invertido é erro de digitação, não filtro vazio: trocar em
        // silêncio devolve o que a pessoa quis ver.
        if ($limpo['de'] && $limpo['ate'] && $limpo['de'] > $limpo['ate']) {
            [$limpo['de'], $limpo['ate']] = [$limpo['ate'], $limpo['de']];
        }

        return $limpo;
    }

    private function montarWhere(array $f): array
    {
        $where = ['1=1'];
        $par   = [];

        if ($f['de'])      { $where[] = 'l.criado_em >= :de';   $par[':de']  = $f['de'] . ' 00:00:00'; }
        if ($f['ate'])     { $where[] = 'l.criado_em <= :ate';  $par[':ate'] = $f['ate'] . ' 23:59:59'; }
        if ($f['tipo'])    { $where[] = 'l.tipo = :tipo';       $par[':tipo'] = $f['tipo']; }
        if ($f['direcao']) { $where[] = 'l.direcao = :dir';     $par[':dir']  = $f['direcao']; }
        if ($f['status'])  { $where[] = 'l.status = :st';       $par[':st']   = $f['status']; }

        if ($f['referencia'] !== '') {
            $where[] = 'l.referencia_id LIKE :ref';
            $par[':ref'] = '%' . $f['referencia'] . '%';
        }

        // Produto e SKU viram uma lista de bling_ids; a comparação é contra o
        // último segmento da referência.
        $blingIds = $this->blingIdsDoFiltro($f);
        if ($blingIds !== null) {
            if (!$blingIds) {
                // Filtro pedido, produto sem vínculo: nenhuma linha pode casar.
                // Devolver tudo aqui seria pior que devolver nada — daria a
                // impressão de que o produto tem 852 operações.
                $where[] = '1=0';
            } else {
                $marc = [];
                foreach (array_values($blingIds) as $i => $bid) {
                    $marc[] = ":b{$i}";
                    $par[":b{$i}"] = $bid;
                }
                $where[] = 'SUBSTRING_INDEX(l.referencia_id, "/", -1) IN (' . implode(',', $marc) . ')';
            }
        }

        return [implode(' AND ', $where), $par];
    }

    /**
     * bling_ids que representam o produto filtrado.
     *
     * Devolve null quando não há filtro de produto (não restringe), array vazio
     * quando há filtro mas o produto não tem vínculo nenhum.
     */
    private function blingIdsDoFiltro(array $f): ?array
    {
        if (!$f['produto_id'] && $f['sku_legado'] === '') return null;

        // Cada lado do UNION tem os próprios nomes de parâmetro.
        //
        // Não dá para repetir `:pid` nos dois: sem emulação, o PDO exige um
        // valor por OCORRÊNCIA do placeholder, e array_merge com chave string
        // sobrescreve em vez de duplicar — o resultado é "Invalid parameter
        // number" só quando alguém usa o filtro de produto.
        $par = [];
        $cond = function (string $sufixo) use ($f, &$par): string {
            $c = [];
            if ($f['produto_id']) {
                $c[] = "p.id = :pid{$sufixo}";
                $par[":pid{$sufixo}"] = $f['produto_id'];
            }
            if ($f['sku_legado'] !== '') {
                $c[] = "p.sku_legado = :sku{$sufixo}";
                $par[":sku{$sufixo}"] = $f['sku_legado'];
            }
            return implode(' AND ', $c);
        };

        $st = $this->db->prepare(
            "SELECT p.bling_id AS b FROM produtos p
              WHERE " . $cond('a') . " AND p.bling_id IS NOT NULL AND p.bling_id <> ''
              UNION
             SELECT ps.bling_id FROM produto_skus ps
               JOIN produtos p ON p.id = ps.produto_id
              WHERE " . $cond('b') . " AND ps.bling_id IS NOT NULL AND ps.bling_id <> ''"
        );
        $st->execute($par);

        return array_values(array_unique($st->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /* =================================================================
       DECORAÇÃO
       ================================================================= */

    /** Acrescenta o que a tela mostra e o banco não guarda. */
    private function decorar(array $log): array
    {
        $ref = (string) ($log['referencia_id'] ?? '');

        $log['bling_id']  = self::idDaReferencia($ref);
        $log['recurso']   = self::recursoDaReferencia($ref);
        $log['resumo']    = self::resumo($log);
        $log['criado_br'] = $log['criado_em'] ? date('d/m/Y H:i:s', strtotime((string) $log['criado_em'])) : '';

        return $log;
    }

    /** Último segmento da referência, quando é um id numérico. */
    public static function idDaReferencia(string $ref): ?string
    {
        $ultimo = trim(substr(strrchr('/' . rtrim($ref, '/'), '/') ?: '', 1));
        return ($ultimo !== '' && ctype_digit($ultimo)) ? $ultimo : null;
    }

    /** Primeiro segmento: "produtos", "contatos", "pedidos"… */
    public static function recursoDaReferencia(string $ref): string
    {
        $partes = array_values(array_filter(explode('/', $ref), fn($p) => $p !== ''));
        return $partes[0] ?? '';
    }

    /**
     * Uma linha que diz o que aconteceu, sem precisar abrir.
     *
     * A tabela mostrava só a referência crua ("/estoques/saldos/14887274365"),
     * que não responde à pergunta que se faz olhando um log.
     */
    private static function resumo(array $log): string
    {
        if (!empty($log['msg_erro'])) {
            $m = trim(preg_replace('/\s+/', ' ', (string) $log['msg_erro']) ?? '');
            return mb_strlen($m) > 120 ? mb_substr($m, 0, 117) . '…' : $m;
        }

        $verbo = match ($log['direcao'] ?? '') {
            'push'    => 'Enviado ao Bling',
            'pull'    => 'Lido do Bling',
            'webhook' => 'Recebido do Bling',
            default   => 'Operação',
        };

        $id = self::idDaReferencia((string) ($log['referencia_id'] ?? ''));
        return $verbo . ($id ? ' · ' . $id : '');
    }

    /** O produto por trás da referência, quando dá para resolver. */
    private function produtoDaReferencia(string $ref): ?array
    {
        $bid = self::idDaReferencia($ref);
        if (!$bid) return null;

        $st = $this->db->prepare(
            "SELECT p.id, p.nome, p.sku_legado, p.slug, 'produto' AS via
               FROM produtos p WHERE p.bling_id = :b
              UNION
             SELECT p.id, p.nome, p.sku_legado, p.slug, 'sku' AS via
               FROM produto_skus ps JOIN produtos p ON p.id = ps.produto_id
              WHERE ps.bling_id = :b2
              LIMIT 1"
        );
        $st->execute([':b' => $bid, ':b2' => $bid]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* =================================================================
       AUXILIARES
       ================================================================= */

    /**
     * JSON legível.
     *
     * A coluna guarda a string crua; muitas linhas têm o literal "null", que
     * não é ausência de dado e sim o que a API devolveu. Mostrar isso é
     * informação — apagar não.
     */
    public static function formatarJson(?string $bruto): string
    {
        $bruto = trim((string) $bruto);
        if ($bruto === '') return '';

        $dec = json_decode($bruto, true);
        if ($dec === null && strtolower($bruto) !== 'null') {
            return $bruto;   // não era JSON válido: mostra como veio
        }

        return json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function data(string $v): string
    {
        $v = trim($v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    private function umValor(string $sql, array $par): mixed
    {
        $st = $this->db->prepare($sql);
        $st->execute($par);
        return $st->fetchColumn();
    }
}
