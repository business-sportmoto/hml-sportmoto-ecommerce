<?php
/**
 * app/models/EmailTemplate.php
 */
// class EmailTemplate
// {
//     /** @var PDO */
//     private $db;

//     public function __construct()
//     {
//         $this->db = Database::getInstance()->getConnection();
//     }

//     public function all($apenasAtivos = false)
//     {
//         $sql = "SELECT id, nome, tipo, assunto, status, versao, atualizado_em
//                 FROM email_templates";
//         if ($apenasAtivos) $sql .= " WHERE status = 'ativo'";
//         $sql .= " ORDER BY atualizado_em DESC";
//         return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
//     }

//     public function find($id)
//     {
//         $st = $this->db->prepare("SELECT * FROM email_templates WHERE id = :id LIMIT 1");
//         $st->execute([':id' => (int)$id]);
//         return $st->fetch(PDO::FETCH_ASSOC) ?: null;
//     }

//     public function save(array $data)
//     {
//         $id = isset($data['id']) ? (int)$data['id'] : 0;
//         $vars = $data['variaveis_json'] ?? null;
//         if (is_array($vars)) $vars = json_encode($vars, JSON_UNESCAPED_UNICODE);

//         if ($id > 0) {
//             $st = $this->db->prepare("UPDATE email_templates SET
//                 nome=:nome, tipo=:tipo, assunto=:assunto, preheader=:preheader,
//                 html=:html, texto=:texto, variaveis_json=:vars, status=:status,
//                 versao = versao + 1
//                 WHERE id=:id");
//             $st->execute([
//                 ':nome' => $data['nome'],
//                 ':tipo' => $data['tipo'] ?? 'marketing',
//                 ':assunto' => $data['assunto'],
//                 ':preheader' => $data['preheader'] ?? null,
//                 ':html' => $data['html'],
//                 ':texto' => $data['texto'] ?? null,
//                 ':vars' => $vars,
//                 ':status' => $data['status'] ?? 'rascunho',
//                 ':id' => $id,
//             ]);
//             return $id;
//         }

//         $st = $this->db->prepare("INSERT INTO email_templates
//             (nome,tipo,assunto,preheader,html,texto,variaveis_json,status,versao)
//             VALUES
//             (:nome,:tipo,:assunto,:preheader,:html,:texto,:vars,:status,1)");
//         $st->execute([
//             ':nome' => $data['nome'],
//             ':tipo' => $data['tipo'] ?? 'marketing',
//             ':assunto' => $data['assunto'],
//             ':preheader' => $data['preheader'] ?? null,
//             ':html' => $data['html'],
//             ':texto' => $data['texto'] ?? null,
//             ':vars' => $vars,
//             ':status' => $data['status'] ?? 'rascunho',
//         ]);
//         return (int)$this->db->lastInsertId();
//     }

//     public function delete($id)
//     {
//         $st = $this->db->prepare("DELETE FROM email_templates WHERE id = :id");
//         return $st->execute([':id' => (int)$id]);
//     }
// }

/**
 * app/models/EmailTemplate.php  (v2)
 *
 * SUBSTITUI o EmailTemplate.php existente.
 * Adiciona suporte a:
 *   - campo formato (manual/visual/mjml)
 *   - source_json (estrutura GrapesJS)
 *   - source_css (CSS do builder)
 *   - render_status / render_log
 *   - snapshot automático a cada save() via EmailTemplateVersionService
 */
class EmailTemplate
{
    /** @var PDO */
    /** Espelham os ENUM da tabela. Servem de whitelist para o que vem da URL. */
    public const TIPOS    = ['marketing', 'transacional'];
    public const FORMATOS = ['manual', 'visual', 'mjml'];
    public const STATUS   = ['rascunho', 'ativo', 'arquivado'];

    private $db;

    /** A Central de IA está instalada? Memorizado: a consulta é sempre a mesma. */
    private ?bool $temIa = null;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all(bool $somenteAtivos = false): array
    {
        $sql = "SELECT * FROM email_templates";
        if ($somenteAtivos) $sql .= " WHERE status = 'ativo'";
        $sql .= " ORDER BY atualizado_em DESC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista com filtro, busca e paginação.
     *
     * A lista cresce sozinha — cada campanha montada pela Central de IA deixa
     * um template novo — e sem filtro vira um paredão de linhas onde ninguém
     * acha nada.
     *
     * @param array $filtros busca, tipo, formato, status, origem (ia|humano), ordenar
     * @return array{itens:array, total:int, pagina:int, por_pagina:int, resumo:array}
     */
    public function listarPaginado(array $filtros = [], int $pagina = 1, int $porPagina = 20): array
    {
        $pagina    = max(1, $pagina);
        $porPagina = max(5, min(100, $porPagina));

        // Procedência de IA sai de tabelas do módulo de IA. Se a Central não
        // estiver instalada, as colunas viram 0 em vez de derrubar a tela —
        // o e-mail marketing não pode depender dela para funcionar.
        $temIa = $this->temTabelasIa();
        $selIa = $temIa
            ? "EXISTS(SELECT 1 FROM ia_email_layout_geracao   il WHERE il.template_id       = t.id) AS ia_layout,
               EXISTS(SELECT 1 FROM ia_email_conteudo_geracao ic WHERE ic.template_final_id = t.id) AS ia_conteudo"
            : '0 AS ia_layout, 0 AS ia_conteudo';

        [$where, $params] = $this->montarFiltro($filtros, $temIa);

        $total = (int) $this->execUm(
            "SELECT COUNT(*) FROM email_templates t {$where}", $params
        );

        $ordem = $this->ordenacao((string) ($filtros['ordenar'] ?? ''));
        $off   = ($pagina - 1) * $porPagina;

        // LIMIT/OFFSET por interpolação de INTEIRO já saneado acima: com
        // EMULATE_PREPARES = false o MySQL recusa placeholder em LIMIT.
        $st = $this->db->prepare(
            "SELECT t.*, {$selIa} FROM email_templates t {$where} ORDER BY {$ordem} LIMIT {$porPagina} OFFSET {$off}"
        );
        $st->execute($params);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'itens'      => $itens,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina,
            'resumo'     => $this->resumo($temIa),
        ];
    }

    /** Contagens do topo — a leitura rápida antes de filtrar. */
    private function resumo(bool $temIa): array
    {
        $r = ['total' => 0, 'marketing' => 0, 'transacional' => 0, 'ativos' => 0, 'por_ia' => 0];
        try {
            $l = $this->db->query(
                "SELECT COUNT(*) total,
                        SUM(tipo = 'marketing')    marketing,
                        SUM(tipo = 'transacional') transacional,
                        SUM(status = 'ativo')      ativos
                   FROM email_templates"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $r = array_merge($r, array_map('intval', array_filter($l, 'is_numeric')));

            if ($temIa) {
                $r['por_ia'] = (int) $this->db->query(
                    "SELECT COUNT(DISTINCT id) FROM (
                        SELECT template_id AS id       FROM ia_email_layout_geracao
                        UNION SELECT template_final_id FROM ia_email_conteudo_geracao
                     ) x"
                )->fetchColumn();
            }
        } catch (Throwable $e) {
            if (class_exists('LogService')) { LogService::error('email_tpl_resumo: ' . $e->getMessage()); }
        }
        return $r;
    }

    /** @return array{0:string, 1:array} WHERE montado e os parâmetros */
    private function montarFiltro(array $f, bool $temIa): array
    {
        $cond = [];
        $p    = [];

        $busca = trim((string) ($f['busca'] ?? ''));
        if ($busca !== '') {
            // Nome e assunto: são os dois campos por onde alguém procura.
            $cond[] = '(t.nome LIKE :busca OR t.assunto LIKE :busca2)';
            $p[':busca']  = '%' . $busca . '%';
            $p[':busca2'] = '%' . $busca . '%';
        }
        foreach (['tipo' => self::TIPOS, 'formato' => self::FORMATOS, 'status' => self::STATUS] as $campo => $valores) {
            $v = (string) ($f[$campo] ?? '');
            // Whitelist: o valor vem da URL e vai para o WHERE.
            if ($v !== '' && in_array($v, $valores, true)) {
                $cond[]         = "t.{$campo} = :{$campo}";
                $p[":{$campo}"] = $v;
            }
        }

        $origem = (string) ($f['origem'] ?? '');
        if ($temIa && ($origem === 'ia' || $origem === 'humano')) {
            $existe = "EXISTS(SELECT 1 FROM ia_email_layout_geracao   il WHERE il.template_id       = t.id)
                    OR EXISTS(SELECT 1 FROM ia_email_conteudo_geracao ic WHERE ic.template_final_id = t.id)";
            $cond[] = $origem === 'ia' ? "({$existe})" : "NOT ({$existe})";
        }

        return [$cond === [] ? '' : 'WHERE ' . implode(' AND ', $cond), $p];
    }

    /** Whitelist de ordenação — o valor vem da URL e entra no ORDER BY. */
    private function ordenacao(string $chave): string
    {
        return [
            'nome'     => 't.nome ASC',
            'tipo'     => "t.tipo ASC, t.formato ASC, t.nome ASC",
            'formato'  => 't.formato ASC, t.nome ASC',
            'status'   => "FIELD(t.status,'ativo','rascunho','arquivado'), t.nome ASC",
            'antigos'  => 't.atualizado_em ASC',
        ][$chave] ?? 't.atualizado_em DESC';
    }

    private function temTabelasIa(): bool
    {
        if ($this->temIa !== null) { return $this->temIa; }
        try {
            $st = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name IN (?, ?)'
            );
            $st->execute(['ia_email_layout_geracao', 'ia_email_conteudo_geracao']);
            $this->temIa = (int) $st->fetchColumn() === 2;
        } catch (Throwable $e) {
            $this->temIa = false;
        }
        return $this->temIa;
    }

    private function execUm(string $sql, array $params)
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM email_templates WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Salva template — versão original + nova versão.
     * Snapshot é criado a cada salvar (se a tabela de versões existir).
     *
     * @param array $dados
     * @param int|null $userId — autor da alteração (pra histórico)
     */
    public function save(array $dados, ?int $userId = null): int
    {
        $id = (int)($dados['id'] ?? 0);

        if ($id > 0) {
            // Snapshot antes de sobrescrever
            $atual = $this->find($id);
            if ($atual && class_exists('EmailTemplateVersionService')) {
                try {
                    (new EmailTemplateVersionService())->snapshot(
                        $atual, 'Snapshot automático antes do salvar', $userId
                    );
                } catch (Throwable $e) {
                    // não interrompe o salvar
                    if (class_exists('LogService')) {
                        LogService::warning('email_tpl_snapshot: ' . $e->getMessage());
                    }
                }
            }

            $sql = "UPDATE email_templates SET
                        nome = :nome, tipo = :tipo, formato = :fmt,
                        assunto = :ass, preheader = :pre,
                        html = :html, source_json = :sj, source_css = :sc,
                        texto = :txt, status = :sts,
                        render_status = :rst, render_log = :rlog,
                        versao = versao + 1, atualizado_em = NOW()
                    WHERE id = :id";
            $st = $this->db->prepare($sql);
            $st->execute([
                ':nome' => $dados['nome'],
                ':tipo' => $dados['tipo'] ?? 'marketing',
                ':fmt'  => $dados['formato'] ?? 'manual',
                ':ass'  => $dados['assunto'],
                ':pre'  => $dados['preheader'] ?? null,
                ':html' => $dados['html'] ?? '',
                ':sj'   => $dados['source_json'] ?? null,
                ':sc'   => $dados['source_css'] ?? null,
                ':txt'  => $dados['texto'] ?? null,
                ':sts'  => $dados['status'] ?? 'rascunho',
                ':rst'  => $dados['render_status'] ?? 'ok',
                ':rlog' => $dados['render_log'] ?? null,
                ':id'   => $id,
            ]);
            return $id;
        }

        // Insert
        $sql = "INSERT INTO email_templates
                (nome, tipo, formato, assunto, preheader, html, source_json, source_css,
                 texto, status, render_status, render_log, versao, criado_em, atualizado_em)
                VALUES
                (:nome, :tipo, :fmt, :ass, :pre, :html, :sj, :sc,
                 :txt, :sts, :rst, :rlog, 1, NOW(), NOW())";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':nome' => $dados['nome'],
            ':tipo' => $dados['tipo'] ?? 'marketing',
            ':fmt'  => $dados['formato'] ?? 'manual',
            ':ass'  => $dados['assunto'],
            ':pre'  => $dados['preheader'] ?? null,
            ':html' => $dados['html'] ?? '',
            ':sj'   => $dados['source_json'] ?? null,
            ':sc'   => $dados['source_css'] ?? null,
            ':txt'  => $dados['texto'] ?? null,
            ':sts'  => $dados['status'] ?? 'rascunho',
            ':rst'  => $dados['render_status'] ?? 'ok',
            ':rlog' => $dados['render_log'] ?? null,
        ]);
        $newId = (int)$this->db->lastInsertId();

        // Snapshot inicial v1
        if (class_exists('EmailTemplateVersionService')) {
            try {
                $tpl = $this->find($newId);
                (new EmailTemplateVersionService())->snapshot(
                    $tpl, 'Criação do template', $userId
                );
            } catch (Throwable $e) {
                // ignora
            }
        }

        return $newId;
    }

    public function delete(int $id): void
    {
        $this->db->prepare("DELETE FROM email_templates WHERE id = :id")
                 ->execute([':id' => $id]);
    }

    /**
     * Duplica um template (útil pra A/B test ou variações).
     */
    public function duplicar(int $id, ?int $userId = null): int
    {
        $tpl = $this->find($id);
        if (!$tpl) throw new RuntimeException('Template não encontrado');

        return $this->save([
            'nome'     => $tpl['nome'] . ' (cópia)',
            'tipo'     => $tpl['tipo'],
            'formato'  => $tpl['formato'],
            'assunto'  => $tpl['assunto'],
            'preheader' => $tpl['preheader'],
            'html'     => $tpl['html'],
            'source_json' => $tpl['source_json'],
            'source_css'  => $tpl['source_css'],
            'texto'    => $tpl['texto'],
            'status'   => 'rascunho',
        ], $userId);
    }
}


