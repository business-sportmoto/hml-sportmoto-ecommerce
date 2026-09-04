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

    /**
     * Schema das configurações: whitelist implícita (chave fora
     * daqui é ignorada no save) + bounds que são PROTEÇÃO
     * OPERACIONAL — janela_abandono_h=0 marcaria todo carrinho
     * ativo como abandonado no próximo cron.
     */
    public const CONFIG_SCHEMA = [
        'janela_abandono_h' => [
            'label' => 'Janela de abandono (horas)',
            'hint'  => 'Tempo sem atividade para o carrinho entrar na central',
            'min' => 1, 'max' => 72, 'step' => 1, 'default' => 3,
        ],
        'valor_minimo' => [
            'label' => 'Valor mínimo do carrinho (R$)',
            'hint'  => 'Carrinhos abaixo deste valor são ignorados',
            'min' => 0, 'max' => 10000, 'step' => 0.01, 'default' => 30,
        ],
        'sugerir_whatsapp_h' => [
            'label' => 'Sugerir WhatsApp após (horas)',
            'hint'  => 'Tempo do abandono até sugerir o 1º contato por WhatsApp',
            'min' => 1, 'max' => 168, 'step' => 1, 'default' => 24,
        ],
        'sugerir_email_h' => [
            'label' => 'Sugerir e-mail após (horas)',
            'hint'  => 'Tempo do abandono até sugerir o envio de e-mail',
            'min' => 1, 'max' => 336, 'step' => 1, 'default' => 48,
        ],
        'max_tentativas' => [
            'label' => 'Máximo de tentativas de contato',
            'hint'  => 'Após este nº sem resposta, sugere marcar como perdido',
            'min' => 1, 'max' => 10, 'step' => 1, 'default' => 3,
        ],
        'dias_sugerir_perdido' => [
            'label' => 'Dias parado para sugerir "perdido"',
            'hint'  => 'Combinado com o máximo de tentativas',
            'min' => 1, 'max' => 60, 'step' => 1, 'default' => 5,
        ],
        'cooldown_email_h' => [
            'label' => 'Intervalo mínimo entre e-mails (horas)',
            'hint'  => 'Anti-spam: bloqueia reenvio ao mesmo carrinho',
            'min' => 1, 'max' => 168, 'step' => 1, 'default' => 24,
        ],
        'token_validade_dias' => [
            'label' => 'Validade do link de retorno (dias)',
            'hint'  => 'Após expirar, um novo link é gerado no próximo envio',
            'min' => 1, 'max' => 30, 'step' => 1, 'default' => 7,
        ],
        'captura_expira_dias' => [
            'label' => 'Expiração da captura (dias sem interação)',
            'hint'  => 'Carrinho capturado sem nenhuma ação neste prazo volta ao pool geral.',
            'min' => 1, 'max' => 30, 'step' => 1, 'default' => 3,
        ],
        // A chave já existia em recuperacao_config (seed da migration
        // chat-ia-cupom) e o ChatCupomCarrinhoService a lê — mas faltava
        // aqui, então não aparecia na tela e o configSalvar() a descartava.
        // Ajustar as 20h exigia UPDATE na mão.
        //
        // Teto de 144h (6 dias) não é estético: fora dos 7 dias da tag de
        // atendimento humano o Instagram recusa a mensagem.
        'chat_cupom_h' => [
            'label' => 'Cupom pelo chat após (horas)',
            'hint'  => 'Espera até oferecer cupom no direct a quem veio de um link do chat.',
            'min' => 1, 'max' => 144, 'step' => 1, 'default' => 20,
        ],
    ];

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
    public function listar(array $f, int $page = 1, int $porPagina = 25,
                           ?int $apenasVisiveisPara = null): array {
        [$where, $params] = $this->buildWhere($f);
 
        if ($apenasVisiveisPara !== null) {
            $where   .= ' AND (cr.responsavel_id IS NULL OR cr.responsavel_id = ?)';
            $params[] = $apenasVisiveisPara;
        }

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

        // O mesmo JOIN do SELECT: o WHERE referencia `uc` (nome e e-mail
        // do cliente moram em `usuarios`, não em `clientes`), e sem o join
        // aqui o COUNT quebra em toda busca.
        $stmtCount = $this->db->prepare(
            "SELECT COUNT(*) FROM carrinho_recuperacao cr
             LEFT JOIN clientes c  ON c.id = cr.cliente_id
             LEFT JOIN usuarios uc ON uc.id = c.usuario_id {$where}"
        );
        $stmtCount->execute($params);

        return ['rows' => $rows, 'total' => (int)$stmtCount->fetchColumn()];
    }

    private function buildWhere(array $f): array {
        $w = ['1=1'];
        $p = [];

        if (!empty($f['q'])) {
            // Busca por nome/telefone/email/cpf do cliente ou produto no carrinho
            // nome/email vêm de `usuarios` (uc); telefone/cpf de `clientes` (c).
            // Estavam os quatro em `c.` — 1054 em toda busca digitada.
            $w[] = "(uc.nome LIKE ? OR uc.email LIKE ? OR c.telefone LIKE ? OR c.cpf LIKE ?
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
            if ($f['responsavel_id'] === 'pool') {
                $w[] = 'cr.responsavel_id IS NULL';
            } else {
                $w[] = 'cr.responsavel_id = ?';
                $p[] = (int)$f['responsavel_id'];
            }
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
                'com_email'    => "uc.email IS NOT NULL AND uc.email != ''",
                'sem_contato'  => "(c.id IS NULL OR ((c.telefone IS NULL OR c.telefone='')
                                    AND (uc.email IS NULL OR uc.email='')))",
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
            "SELECT u.id, u.nome, a.nivel
             FROM admins a
             JOIN usuarios u ON u.id = a.usuario_id
             WHERE u.ativo = 1
               AND a.nivel IN ('super','gerente','vendedor')
             ORDER BY
               FIELD(a.nivel,'vendedor','gerente','super'), u.nome"
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

    /** Config vigente: defaults do schema sobrescritos pelo banco.
     *  Resiliente a deploy order — tabela ausente = defaults. */
    public function configListar(): array {
        $out = [];
        foreach (self::CONFIG_SCHEMA as $k => $def) {
            $out[$k] = (float)$def['default'];
        }
        try {
            $rows = $this->db->query(
                "SELECT chave, valor FROM recuperacao_config"
            )->fetchAll();
            foreach ($rows as $r) {
                if (isset($out[$r['chave']])) $out[$r['chave']] = (float)$r['valor'];
            }
        } catch (\Throwable $e) { /* migração ainda não rodou — defaults */ }
        return $out;
    }
 
    /**
     * Valida contra o schema (bounds) e persiste via UPSERT.
     * Sintaxe `AS novo` — VALUES() em ODKU é deprecated no MySQL 8.4.
     * Mudanças são logadas com admin_id: alterar automação é ação
     * sensível (afeta detecção global de carrinhos).
     */
    public function configSalvar(array $post, int $adminId): array {
        $erros    = [];
        $mudancas = [];
        $atuais   = $this->configListar();
 
        $stmt = $this->db->prepare(
            "INSERT INTO recuperacao_config (chave, valor, atualizado_por)
             VALUES (?,?,?) AS novo
             ON DUPLICATE KEY UPDATE
                valor = novo.valor, atualizado_por = novo.atualizado_por"
        );
 
        foreach (self::CONFIG_SCHEMA as $chave => $def) {
            if (!isset($post[$chave])) continue;
 
            $valor = (float)str_replace(',', '.', (string)$post[$chave]);
            if (!is_finite($valor) || $valor < $def['min'] || $valor > $def['max']) {
                $erros[] = "{$def['label']}: valor entre {$def['min']} e {$def['max']}.";
                continue;
            }
            if (abs($atuais[$chave] - $valor) > 0.001) {
                $mudancas[] = "{$chave}: {$atuais[$chave]}→{$valor}";
            }
            $stmt->execute([$chave, $valor, $adminId]);
        }
 
        if (!empty($mudancas)) {
            error_log('[recuperacao-config] admin#' . $adminId
                . ' alterou automação: ' . implode(' | ', $mudancas));
        }
 
        return empty($erros)
            ? ['ok' => true]
            : ['ok' => false, 'msg' => implode(' ', $erros)];
    }
 
    // ══════════════════════════════════════════════════
    // RELATÓRIO — conversão por template (last-touch)
    // ══════════════════════════════════════════════════
 
    /**
     * Atribuição LAST-TOUCH: a conversão pertence ao ÚLTIMO template
     * enviado antes do momento da recuperação. Evita supercontar
     * quando o operador dispara 3 templates no mesmo carrinho.
     * `envios`/`carrinhos` são contagem bruta (participação);
     * `conversoes`/`valor_recuperado` são last-touch.
     * Período aplicado sobre a data do ENVIO.
     * CTE + ROW_NUMBER(): MySQL 8.4 nativo.
     */
    public function relatorioConversaoTemplates(string $de, string $ate): array {
        $stmt = $this->db->prepare(
            "WITH envios AS (
                SELECT e.recuperacao_id,
                       CAST(e.meta->>'$.template_id' AS UNSIGNED) AS template_id,
                       e.criado_em
                FROM carrinho_recuperacao_eventos e
                WHERE e.tipo IN ('whatsapp_enviado','email_enviado')
                  AND e.meta->>'$.template_id' IS NOT NULL
                  AND e.criado_em BETWEEN ? AND ?
            ),
            recuperacoes AS (
                SELECT recuperacao_id, MIN(criado_em) AS recuperado_em
                FROM carrinho_recuperacao_eventos
                WHERE tipo = 'recuperado'
                GROUP BY recuperacao_id
            ),
            last_touch AS (
                SELECT en.recuperacao_id, en.template_id,
                       ROW_NUMBER() OVER (
                           PARTITION BY en.recuperacao_id
                           ORDER BY en.criado_em DESC
                       ) AS rn
                FROM envios en
                JOIN recuperacoes r
                  ON r.recuperacao_id = en.recuperacao_id
                 AND en.criado_em <= r.recuperado_em
            )
            SELECT t.id, t.nome, t.canal,
                   COALESCE(ag.envios, 0)           AS envios,
                   COALESCE(ag.carrinhos, 0)        AS carrinhos,
                   COALESCE(cv.conversoes, 0)       AS conversoes,
                   COALESCE(cv.valor_recuperado, 0) AS valor_recuperado
            FROM recuperacao_templates t
            LEFT JOIN (
                SELECT template_id,
                       COUNT(*)                       AS envios,
                       COUNT(DISTINCT recuperacao_id) AS carrinhos
                FROM envios GROUP BY template_id
            ) ag ON ag.template_id = t.id
            LEFT JOIN (
                SELECT lt.template_id,
                       COUNT(*) AS conversoes,
                       SUM(COALESCE(cr.valor_recuperado, cr.valor_snapshot))
                           AS valor_recuperado
                FROM last_touch lt
                JOIN carrinho_recuperacao cr ON cr.id = lt.recuperacao_id
                WHERE lt.rn = 1
                GROUP BY lt.template_id
            ) cv ON cv.template_id = t.id
            WHERE ag.envios IS NOT NULL OR cv.conversoes IS NOT NULL
            ORDER BY conversoes DESC, envios DESC"
        );
        $stmt->execute([$de . ' 00:00:00', $ate . ' 23:59:59']);
        return $stmt->fetchAll();
    }
}