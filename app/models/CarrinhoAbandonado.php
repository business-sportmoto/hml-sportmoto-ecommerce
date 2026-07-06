<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/CarrinhoAbandonado.php
// Leitura da camada comercial de recuperação. Todas as
// queries com prepared statements; agregações compatíveis
// com ONLY_FULL_GROUP_BY (MySQL 8.4).
// ════════════════════════════════════════════════════════

class CarrinhoAbandonado {

    private PDO $db;

    /** Whitelist de ordenação — nunca interpolar input do usuário em ORDER BY */
    private const SORT_MAP = [
        'valor'      => 'cr.valor_snapshot DESC',
        'data'       => 'cr.abandonado_em DESC',
        'prioridade' => "FIELD(cr.prioridade,'imediata','alta','media','baixa'), cr.score DESC",
        'interacao'  => 'cr.ultima_acao_em DESC',
        'score'      => 'cr.score DESC',
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // LISTAGEM COM FILTROS
    // ══════════════════════════════════════════════════

    /**
     * @return array{rows: array, total: int}
     */
    public function listar(array $f, int $page = 1, int $porPagina = 25): array {
        [$where, $params] = $this->buildWhere($f);

        $orderBy = self::SORT_MAP[$f['ordenar'] ?? ''] ?? self::SORT_MAP['prioridade'];
        $offset  = max(0, ($page - 1) * $porPagina);

        $sql = "SELECT cr.*,
                       uc.nome  AS cliente_nome,
                       uc.email AS cliente_email,
                       c.telefone AS cliente_telefone,
                       c.cpf   AS cliente_cpf,
                       c.newsletter,
                       u.nome  AS responsavel_nome,
                       (SELECT COUNT(*) FROM pedidos pd
                         WHERE pd.cliente_id = cr.cliente_id
                           AND pd.status_pagamento = 'aprovado') AS pedidos_anteriores,
                       (SELECT cre.tipo FROM carrinho_recuperacao_eventos cre
                         WHERE cre.recuperacao_id = cr.id
                         ORDER BY cre.criado_em DESC LIMIT 1) AS ultima_acao_tipo
                FROM carrinho_recuperacao cr
                LEFT JOIN clientes c  ON c.id = cr.cliente_id
                LEFT JOIN usuarios uc ON uc.id = c.usuario_id
                LEFT JOIN usuarios u  ON u.id = cr.responsavel_id
                {$where}
                ORDER BY {$orderBy}
                LIMIT {$porPagina} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $stmtCount = $this->db->prepare(
            "SELECT COUNT(*) FROM carrinho_recuperacao cr
             LEFT JOIN clientes c ON c.id = cr.cliente_id {$where}"
        );
        $stmtCount->execute($params);

        return ['rows' => $rows, 'total' => (int)$stmtCount->fetchColumn()];
    }

    private function buildWhere(array $f): array {
        $w = ['1=1'];
        $p = [];

        if (!empty($f['q'])) {
            // Busca por nome/telefone/email/cpf do cliente ou produto no carrinho
            $w[] = "(c.nome LIKE ? OR c.email LIKE ? OR c.telefone LIKE ? OR c.cpf LIKE ?
                     OR EXISTS (SELECT 1 FROM carrinho_itens ci
                                JOIN produtos pr ON pr.id = ci.produto_id
                                WHERE ci.carrinho_id = cr.carrinho_id
                                  AND pr.nome LIKE ?))";
            $like = '%' . $f['q'] . '%';
            array_push($p, $like, $like, $like, $like, $like);
        }
        if (!empty($f['status'])) {
            $w[] = 'cr.status = ?';
            $p[] = $f['status'];
        }
        if (!empty($f['prioridade'])) {
            $w[] = 'cr.prioridade = ?';
            $p[] = $f['prioridade'];
        }
        if (!empty($f['responsavel_id'])) {
            $w[] = 'cr.responsavel_id = ?';
            $p[] = (int)$f['responsavel_id'];
        }
        if (!empty($f['data_de'])) {
            $w[] = 'cr.abandonado_em >= ?';
            $p[] = $f['data_de'] . ' 00:00:00';
        }
        if (!empty($f['data_ate'])) {
            $w[] = 'cr.abandonado_em <= ?';
            $p[] = $f['data_ate'] . ' 23:59:59';
        }
        if (!empty($f['valor_min'])) {
            $w[] = 'cr.valor_snapshot >= ?';
            $p[] = (float)$f['valor_min'];
        }
        if (!empty($f['valor_max'])) {
            $w[] = 'cr.valor_snapshot <= ?';
            $p[] = (float)$f['valor_max'];
        }
        // Filtros de contato
        if (!empty($f['contato'])) {
            $w[] = match ($f['contato']) {
                'com_telefone' => "c.telefone IS NOT NULL AND c.telefone != ''",
                'com_email'    => "c.email IS NOT NULL AND c.email != ''",
                'sem_contato'  => "(c.id IS NULL OR ((c.telefone IS NULL OR c.telefone='')
                                    AND (c.email IS NULL OR c.email='')))",
                default        => '1=1',
            };
        }

        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    // ══════════════════════════════════════════════════
    // DETALHE
    // ══════════════════════════════════════════════════

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT cr.*,
                    uc.nome AS cliente_nome, uc.email AS cliente_email,
                    c.telefone AS cliente_telefone, c.cpf AS cliente_cpf,
                    c.newsletter,
                    u.nome AS responsavel_nome
             FROM carrinho_recuperacao cr
             LEFT JOIN clientes c ON c.id = cr.cliente_id
             LEFT JOIN usuarios uc ON uc.id = c.usuario_id
             LEFT JOIN usuarios u ON u.id = cr.responsavel_id
             WHERE cr.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Itens do carrinho vinculado, com imagem e SKU. */
    public function getItens(int $carrinhoId): array {
        $stmt = $this->db->prepare(
            "SELECT ci.produto_id, ci.quantidade, ci.preco_unitario,
                    ci.quantidade * ci.preco_unitario AS subtotal,
                    ci.opcoes_selecionadas,
                    p.nome AS produto_nome, p.slug,
                    ps.sku AS sku_codigo,
                    pi.arquivo AS imagem
             FROM carrinho_itens ci
             JOIN produtos p ON p.id = ci.produto_id
             LEFT JOIN produto_skus ps ON ps.id = ci.sku_id
             LEFT JOIN produto_imagens pi
                    ON pi.produto_id = ci.produto_id AND pi.principal = 1
             WHERE ci.carrinho_id = ?
             ORDER BY ci.id"
        );
        $stmt->execute([$carrinhoId]);
        return $stmt->fetchAll();
    }

    public function getEventos(int $recuperacaoId): array {
        $stmt = $this->db->prepare(
            "SELECT e.*, u.nome AS admin_nome
             FROM carrinho_recuperacao_eventos e
             LEFT JOIN usuarios u ON u.id = e.admin_id
             WHERE e.recuperacao_id = ?
             ORDER BY e.criado_em DESC"
        );
        $stmt->execute([$recuperacaoId]);
        return array_map(function (array $e): array {
            $e['meta'] = !empty($e['meta']) ? (json_decode($e['meta'], true) ?? []) : [];
            return $e;
        }, $stmt->fetchAll());
    }

    // ══════════════════════════════════════════════════
    // DASHBOARD — agregações do período
    // ══════════════════════════════════════════════════

    public function dashboard(string $de, string $ate): array {
        $p = [$de . ' 00:00:00', $ate . ' 23:59:59'];

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                                                    AS total,
                SUM(status IN ('abandonado','em_recuperacao','msg_enviada',
                               'aguardando_resposta','respondeu','negociacao')) AS em_aberto,
                SUM(status = 'recuperado')                                  AS recuperados,
                SUM(status = 'perdido')                                     AS perdidos,
                SUM(status = 'sem_contato')                                 AS sem_contato,
                COALESCE(SUM(CASE WHEN status NOT IN ('recuperado')
                                  THEN valor_snapshot END), 0)              AS valor_aberto,
                COALESCE(SUM(CASE WHEN status = 'recuperado'
                                  THEN COALESCE(valor_recuperado, valor_snapshot) END), 0)
                                                                            AS valor_recuperado,
                COALESCE(AVG(valor_snapshot), 0)                            AS ticket_medio,
                SUM(cliente_id IS NOT NULL)                                 AS identificados,
                SUM(cliente_id IS NULL)                                     AS anonimos
             FROM carrinho_recuperacao
             WHERE abandonado_em BETWEEN ? AND ?"
        );
        $stmt->execute($p);
        $kpi = $stmt->fetch() ?: [];

        $kpi['taxa_recuperacao'] = ((int)$kpi['total']) > 0
            ? round((int)$kpi['recuperados'] / (int)$kpi['total'] * 100, 1)
            : 0.0;

        // Produtos mais abandonados — MIN() para ONLY_FULL_GROUP_BY
        $stmtProd = $this->db->prepare(
            "SELECT ci.produto_id,
                    MIN(p.nome)          AS produto_nome,
                    COUNT(DISTINCT cr.id) AS carrinhos,
                    SUM(ci.quantidade)    AS unidades,
                    SUM(ci.quantidade * ci.preco_unitario) AS valor
             FROM carrinho_recuperacao cr
             JOIN carrinho_itens ci ON ci.carrinho_id = cr.carrinho_id
             JOIN produtos p        ON p.id = ci.produto_id
             WHERE cr.abandonado_em BETWEEN ? AND ?
               AND cr.status != 'recuperado'
             GROUP BY ci.produto_id
             ORDER BY carrinhos DESC, valor DESC
             LIMIT 10"
        );
        $stmtProd->execute($p);

        // Performance por responsável
        $stmtResp = $this->db->prepare(
            "SELECT cr.responsavel_id,
                    MIN(u.nome) AS responsavel_nome,
                    COUNT(*)    AS atribuidos,
                    SUM(cr.status = 'recuperado') AS recuperados,
                    COALESCE(SUM(CASE WHEN cr.status = 'recuperado'
                        THEN COALESCE(cr.valor_recuperado, cr.valor_snapshot) END),0)
                        AS valor_recuperado,
                    SUM(cr.status IN ('em_recuperacao','msg_enviada',
                        'aguardando_resposta','negociacao')) AS pendentes
             FROM carrinho_recuperacao cr
             JOIN usuarios u ON u.id = cr.responsavel_id
             WHERE cr.responsavel_id IS NOT NULL
               AND cr.abandonado_em BETWEEN ? AND ?
             GROUP BY cr.responsavel_id
             ORDER BY valor_recuperado DESC"
        );
        $stmtResp->execute($p);

        return [
            'kpi'          => $kpi,
            'top_produtos' => $stmtProd->fetchAll(),
            'por_usuario'  => $stmtResp->fetchAll(),
        ];
    }

    /** Lista de admins ativos para atribuição de responsável. */
    public function getResponsaveis(): array {
        return $this->db->query(
            "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome"
        )->fetchAll();
    }

    public const VARIAVEIS = [
        '{nome}'          => 'Nome completo do cliente',
        '{primeiro_nome}' => 'Primeiro nome do cliente',
        '{loja}'          => 'Nome da loja',
        '{valor}'         => 'Valor total do carrinho (formatado)',
        '{produtos}'      => 'Até 3 nomes de produtos, separados por vírgula',
        '{link}'          => 'Link de retorno ao carrinho',
        '{vendedor}'      => 'Nome do responsável pelo atendimento',
        '{telefone_loja}' => 'Telefone da loja',
    ];
 
    /** Exclusiva de e-mail: tabela HTML com os produtos. */
    public const VARIAVEIS_EMAIL = [
        '{produtos_html}' => 'Tabela HTML com produtos e valores',
    ];
 
    public function templatesListar(?string $canal = null): array {
        if ($canal !== null && in_array($canal, ['whatsapp', 'email'], true)) {
            $stmt = $this->db->prepare(
                "SELECT * FROM recuperacao_templates
                 WHERE canal = ? ORDER BY ativo DESC, canal, nome"
            );
            $stmt->execute([$canal]);
            return $stmt->fetchAll();
        }
        return $this->db->query(
            "SELECT * FROM recuperacao_templates
             ORDER BY ativo DESC, canal, nome"
        )->fetchAll();
    }
 
    public function templateFindById(int $id): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM recuperacao_templates WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
 
    /**
     * Template já usado em algum envio? Consulta o meta JSON dos
     * eventos. Sem índice funcional: scan aceitável — eventos são
     * operação comercial manual (baixo volume); se escalar, criar
     * índice funcional em (CAST(meta->>'$.template_id' AS UNSIGNED)).
     */
    public function templateFoiUsado(int $id): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM carrinho_recuperacao_eventos
             WHERE tipo IN ('whatsapp_enviado','email_enviado')
               AND CAST(meta->>'$.template_id' AS UNSIGNED) = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }
 
    /**
     * Valida e persiste template. Lança InvalidArgumentException
     * com mensagem exibível ao operador.
     *
     * @return int ID do template (novo ou existente)
     */
    public function templateSalvar(array $data, ?int $id = null): int {
        $nome    = trim($data['nome'] ?? '');
        $canal   = $data['canal'] ?? '';
        $assunto = trim($data['assunto'] ?? '');
        $conteudo= trim($data['conteudo'] ?? '');
        $uso     = trim($data['uso_recomendado'] ?? '');
        $ativo   = (int)($data['ativo'] ?? 0);
 
        // Na edição o canal é IMUTÁVEL: migrar whatsapp↔email criaria
        // estado inválido (assunto órfão / HTML em texto puro)
        if ($id !== null) {
            $atual = $this->templateFindById($id);
            if (!$atual) throw new \InvalidArgumentException('Template não encontrado.');
            $canal = $atual['canal'];
        }
 
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 80) {
            throw new \InvalidArgumentException('Nome deve ter entre 3 e 80 caracteres.');
        }
        if (!in_array($canal, ['whatsapp', 'email'], true)) {
            throw new \InvalidArgumentException('Canal inválido.');
        }
        if ($canal === 'email') {
            if (mb_strlen($assunto) < 3 || mb_strlen($assunto) > 150) {
                throw new \InvalidArgumentException('Assunto deve ter entre 3 e 150 caracteres.');
            }
            // E-mail de recuperação sem link de retorno é inútil
            // comercialmente — bloqueio de qualidade, não capricho
            if (!str_contains($conteudo, '{link}')) {
                throw new \InvalidArgumentException('Templates de e-mail devem conter a variável {link}.');
            }
        }
        if (mb_strlen($conteudo) < 10 || mb_strlen($conteudo) > 10000) {
            throw new \InvalidArgumentException('Conteúdo deve ter entre 10 e 10.000 caracteres.');
        }
        if (mb_strlen($uso) > 150) {
            throw new \InvalidArgumentException('Uso recomendado: máximo 150 caracteres.');
        }
 
        $this->validarVariaveisTemplate($conteudo . ' ' . $assunto, $canal);
 
        if ($id !== null) {
            $this->db->prepare(
                "UPDATE recuperacao_templates
                 SET nome = ?, assunto = ?, conteudo = ?,
                     uso_recomendado = ?, ativo = ?
                 WHERE id = ?"
            )->execute([
                $nome, $canal === 'email' ? $assunto : null,
                $conteudo, $uso ?: null, $ativo, $id,
            ]);
            return $id;
        }
 
        $this->db->prepare(
            "INSERT INTO recuperacao_templates
                (nome, canal, assunto, conteudo, uso_recomendado, ativo)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $nome, $canal, $canal === 'email' ? $assunto : null,
            $conteudo, $uso ?: null, $ativo,
        ]);
        return (int)$this->db->lastInsertId();
    }
 
    /**
     * Rejeita variáveis fora da whitelist. Um typo ({nome_cliente})
     * chegaria literal na mensagem do cliente — pior tipo de erro:
     * silencioso no admin, visível só para o cliente final.
     */
    private function validarVariaveisTemplate(string $texto, string $canal): void {
        preg_match_all('/\{([a-z_]+)\}/', $texto, $m);
        if (empty($m[0])) return;
 
        $permitidas = array_keys(self::VARIAVEIS);
        if ($canal === 'email') {
            $permitidas = array_merge($permitidas, array_keys(self::VARIAVEIS_EMAIL));
        }
 
        $invalidas = array_diff(array_unique($m[0]), $permitidas);
        if (!empty($invalidas)) {
            throw new \InvalidArgumentException(
                'Variáveis desconhecidas: ' . implode(', ', $invalidas)
                . '. Use apenas as variáveis listadas.'
            );
        }
    }
 
    public function templateToggleAtivo(int $id): bool {
        return $this->db->prepare(
            "UPDATE recuperacao_templates SET ativo = 1 - ativo WHERE id = ?"
        )->execute([$id]);
    }
 
    /**
     * Hard delete APENAS se nunca usado — preservar o registro de
     * templates usados protege a rastreabilidade dos relatórios de
     * conversão (eventos referenciam template_id no meta JSON).
     */
    public function templateExcluir(int $id): array {
        if ($this->templateFoiUsado($id)) {
            return ['ok' => false,
                    'msg' => 'Template já foi usado em envios — desative-o em vez de excluir (preserva o histórico de relatórios).'];
        }
        $this->db->prepare(
            "DELETE FROM recuperacao_templates WHERE id = ?"
        )->execute([$id]);
        return ['ok' => true];
    }
}