<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/VendedorAdmin.php
// Suporte à gestão de vendedores no painel: busca de usuário
// existente, geração de código por iniciais e agregação de
// vendas para o dashboard de comissão.
//
// vendedores.codigo é a MESMA chave usada em pedidos.codigo_
// vendedor e carrinhos.codigo_vendedor — fonte única, nunca
// duplicada em admins.
// ════════════════════════════════════════════════════════

final class VendedorAdmin {

    /**
     * ⚠ AJUSTE ÚNICO: nome da coluna de valor total em `pedidos`.
     * Não localizei no código enviado — confirme e ajuste aqui
     * (candidatos comuns: valor_total, total, valor_final).
     * Todo o dashboard usa esta constante.
     */
    private const COL_VALOR_PEDIDO = 'total';

    /** Status que contam como venda efetivada para comissão. */
    private const STATUS_PAGO = ['aprovado', 'pago'];

    /** Slug de entrega em pedido_historico.status_novo. */
    private const STATUS_ENTREGA = 'entregue';
 
    /** Dias de carência após a entrega para liberar a comissão.
     *  Protege contra devolução/cancelamento no prazo legal. */
    private const CARENCIA_DIAS = 7;
 
    /** Status ATUAIS que anulam a comissão, mesmo após entrega. */
    private const STATUS_PEDIDO_EXCLUI    = ['cancelado', 'troca_devolucao'];
    private const STATUS_PAGTO_EXCLUI     = ['estornado', 'reembolsado', 'recusado', 'falhou'];

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // BUSCA DE USUÁRIO (Ajax) — por e-mail ou CPF
    // ══════════════════════════════════════════════════

    /**
     * Localiza um usuário existente por e-mail (usuarios) ou CPF
     * (clientes). Retorna o estado de vínculo para o controller
     * decidir: promover, bloquear (já é admin) ou informar.
     *
     * NUNCA retorna senha_hash ou dados sensíveis — só o mínimo
     * para a tela de confirmação.
     */
    public function buscarUsuario(string $termo): ?array {
        $termo = trim($termo);
        if ($termo === '') return null;

        // Heurística: contém @ → e-mail; senão, dígitos de CPF
        if (str_contains($termo, '@')) {
            $stmt = $this->db->prepare(
                "SELECT u.id, u.nome, u.email, u.tipo, u.ativo
                 FROM usuarios u
                 WHERE u.email = ? AND u.deleted_at IS NULL LIMIT 1"
            );
            $stmt->execute([mb_strtolower($termo)]);
        } else {
            $cpf = preg_replace('/\D/', '', $termo);
            if (strlen($cpf) !== 11) return null;
            // CPF pode estar formatado no banco — compara sem máscara
            $stmt = $this->db->prepare(
                "SELECT u.id, u.nome, u.email, u.tipo, u.ativo
                 FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE REPLACE(REPLACE(REPLACE(c.cpf,'.',''),'-',''),' ','') = ?
                   AND u.deleted_at IS NULL LIMIT 1"
            );
            $stmt->execute([$cpf]);
        }

        $u = $stmt->fetch();
        if (!$u) return null;

        // Enriquece com estado de vínculo (admin? vendedor?)
        $u['ja_admin']    = $this->jaEhAdmin((int)$u['id']);
        $u['ja_vendedor'] = $this->codigoVendedorDe((int)$u['id']);
        return $u;
    }

    public function jaEhAdmin(int $usuarioId): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM admins WHERE usuario_id = ? LIMIT 1");
        $stmt->execute([$usuarioId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Código de vendedor do usuário, se já tiver (ativo ou não). */
    public function codigoVendedorDe(int $usuarioId): ?string {
        $stmt = $this->db->prepare(
            "SELECT codigo FROM vendedores WHERE usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        return $stmt->fetchColumn() ?: null;
    }

    // ══════════════════════════════════════════════════
    // CÓDIGO DE VENDEDOR — iniciais + sufixo aleatório
    // ══════════════════════════════════════════════════

    /**
     * Gera código a partir das iniciais do nome + sufixo curto.
     * Ex: "João Silva" → "JS" + "4K7" → "JS4K7". Sem caracteres
     * ambíguos (0/O, 1/I). Garante unicidade por retry contra o
     * UNIQUE de vendedores.codigo.
     */
    public function gerarCodigo(string $nome): string {
        $iniciais = $this->iniciais($nome);
        $chars    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0,O,1,I

        for ($tentativa = 0; $tentativa < 20; $tentativa++) {
            $sufixo = '';
            for ($i = 0; $i < 3; $i++) {
                $sufixo .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $codigo = $iniciais . $sufixo;

            $stmt = $this->db->prepare("SELECT 1 FROM vendedores WHERE codigo = ? LIMIT 1");
            $stmt->execute([$codigo]);
            if (!$stmt->fetchColumn()) return $codigo;
        }
        // Fallback improvável: iniciais + 5 chars
        return $iniciais . substr(bin2hex(random_bytes(3)), 0, 5);
    }

    private function iniciais(string $nome): string {
        $partes = preg_split('/\s+/', trim($nome)) ?: [];
        $ini = '';
        foreach ($partes as $p) {
            $c = mb_substr($p, 0, 1);
            if (ctype_alpha($c)) $ini .= mb_strtoupper($c);
            if (mb_strlen($ini) >= 2) break;
        }
        return $ini !== '' ? $ini : 'VD'; // nome sem letras → prefixo padrão
    }

    /** Valida formato de código editado à mão. */
    public function codigoValido(string $codigo): bool {
        return (bool)preg_match('/^[A-Z0-9]{3,12}$/', $codigo);
    }

    public function codigoEmUso(string $codigo, ?int $excetoUsuarioId = null): bool {
        $sql = "SELECT 1 FROM vendedores WHERE codigo = ?";
        $par = [$codigo];
        if ($excetoUsuarioId !== null) {
            $sql .= " AND usuario_id != ?";
            $par[] = $excetoUsuarioId;
        }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($par);
        return (bool)$stmt->fetchColumn();
    }

    // ══════════════════════════════════════════════════
    // VÍNCULO DE VENDEDOR (ativar/atualizar/desativar)
    // ══════════════════════════════════════════════════

    /**
     * Garante registro de vendedor ATIVO para o usuário com o
     * código dado. UPSERT: reativa registro antigo (preservando
     * o código histórico se o admin não trocou) ou cria novo.
     */
    public function ativarVendedor(int $usuarioId, string $nome, string $codigo): void {
        $existente = $this->codigoVendedorDe($usuarioId);

        if ($existente !== null) {
            $this->db->prepare(
                "UPDATE vendedores SET codigo = ?, nome = ?, ativo = 1
                 WHERE usuario_id = ?"
            )->execute([$codigo, $nome, $usuarioId]);
        } else {
            $this->db->prepare(
                "INSERT INTO vendedores (usuario_id, codigo, nome, ativo)
                 VALUES (?,?,?,1)"
            )->execute([$usuarioId, $codigo, $nome]);
        }
    }

    /**
     * Desativa o vendedor SEM apagar — preserva o vínculo
     * histórico de pedidos.codigo_vendedor para comissão de
     * vendas antigas (decisão do produto).
     */
    public function desativarVendedor(int $usuarioId): void {
        $this->db->prepare(
            "UPDATE vendedores SET ativo = 0 WHERE usuario_id = ?"
        )->execute([$usuarioId]);
    }

    /**
     * Fragmento SQL + params da regra de comissão elegível.
     * Centraliza a lógica nas 3 queries (uma fonte de verdade):
     *
     *   1. Pagamento aprovado
     *   2. Existe entrega em pedido_historico há >= CARENCIA_DIAS
     *      (data REAL da entrega, não atualizado_em do pedido —
     *      que qualquer edição posterior resetaria)
     *   3. Status atual NÃO é cancelado/devolvido (status_pedido)
     *   4. Pagamento atual NÃO é estornado/reembolsado
     *
     * Retorna [sqlWhere, params] para concatenar após um WHERE já
     * existente. O alias do pedido é 'p'.
     */
    private function condElegivel(): array {
        $pedidoExclui = "'" . implode("','", self::STATUS_PEDIDO_EXCLUI) . "'";
        $pagtoExclui  = "'" . implode("','", self::STATUS_PAGTO_EXCLUI)  . "'";
 
        $sql = "
            AND p.status_pagamento = 'aprovado'
            AND p.status_pedido    NOT IN ({$pedidoExclui})
            AND p.status_pagamento NOT IN ({$pagtoExclui})
            AND EXISTS (
                SELECT 1 FROM pedido_historico ph
                WHERE ph.pedido_id   = p.id
                  AND ph.status_novo = ?
                  AND ph.criado_em  <= DATE_SUB(NOW(), INTERVAL ? DAY)
            )";
        // params na ORDEM em que os '?' aparecem
        return [$sql, [self::STATUS_ENTREGA, self::CARENCIA_DIAS]];
    }

    // ══════════════════════════════════════════════════
    // DASHBOARD DE VENDAS / COMISSÃO
    // ══════════════════════════════════════════════════

    /**
     * Comissão elegível de UM vendedor no período. O período
     * filtra a DATA DA ENTREGA (não a do pedido) — comissão
     * "caiu" no mês em que completou a carência.
     */
    public function vendasDoVendedor(string $codigo, string $de, string $ate): array {
        $col = self::COL_VALOR_PEDIDO;
        [$condSql, $condParams] = $this->condElegivel();
 
        // A data de referência para o período é a entrega + carência.
        // Subconsulta pega o momento exato da entrega para filtrar.
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)               AS pedidos,
                    COALESCE(SUM(p.{$col}),0) AS total,
                    COALESCE(AVG(p.{$col}),0) AS ticket
             FROM pedidos p
             WHERE p.codigo_vendedor = ?
               {$condSql}
               AND (SELECT MIN(ph.criado_em) FROM pedido_historico ph
                    WHERE ph.pedido_id = p.id AND ph.status_novo = ?)
                   BETWEEN ? AND ?"
        );
        $stmt->execute(array_merge(
            [$codigo], $condParams,
            [self::STATUS_ENTREGA, $de . ' 00:00:00', $ate . ' 23:59:59']
        ));
        return $stmt->fetch() ?: ['pedidos' => 0, 'total' => 0, 'ticket' => 0];
    }

    /**
     * Ranking de comissão elegível por vendedor no período.
     * Inclui inativos que têm comissão a receber (histórico
     * preservado). LEFT JOIN condicional garante que vendedores
     * sem comissão no período apareçam com zero.
     */
    public function rankingVendedores(string $de, string $ate): array {
        $col = self::COL_VALOR_PEDIDO;
        [$condSql, $condParams] = $this->condElegivel();
 
        // O JOIN condicional embute a regra de elegibilidade e a
        // janela do período (pela data de entrega). $condSql usa
        // alias 'p' — bate com o LEFT JOIN pedidos p abaixo.
        $stmt = $this->db->prepare(
            "SELECT v.codigo, v.ativo,
                    MIN(u.nome)             AS vendedor_nome,
                    COUNT(p.id)             AS pedidos,
                    COALESCE(SUM(p.{$col}),0) AS total,
                    COALESCE(AVG(p.{$col}),0) AS ticket
             FROM vendedores v
             JOIN usuarios u ON u.id = v.usuario_id
             LEFT JOIN pedidos p
                    ON p.codigo_vendedor = v.codigo
                   {$condSql}
                   AND (SELECT MIN(ph.criado_em) FROM pedido_historico ph
                        WHERE ph.pedido_id = p.id AND ph.status_novo = ?)
                       BETWEEN ? AND ?
             GROUP BY v.codigo, v.ativo
             ORDER BY total DESC"
        );
        $stmt->execute(array_merge(
            $condParams,
            [self::STATUS_ENTREGA, $de . ' 00:00:00', $ate . ' 23:59:59']
        ));
        return $stmt->fetchAll();
    }


    /**
     * Série diária da comissão elegível — agrupada pela DATA DA
     * ENTREGA (dia em que a comissão foi liberada), não pela data
     * do pedido. Coerente com o período do dashboard.
     */
    public function seriePorDia(string $codigo, string $de, string $ate): array {
        $col = self::COL_VALOR_PEDIDO;
        [$condSql, $condParams] = $this->condElegivel();
 
        $stmt = $this->db->prepare(
            "SELECT DATE((SELECT MIN(ph.criado_em) FROM pedido_historico ph
                          WHERE ph.pedido_id = p.id AND ph.status_novo = ?)) AS dia,
                    COALESCE(SUM(p.{$col}),0) AS total
             FROM pedidos p
             WHERE p.codigo_vendedor = ?
               {$condSql}
               AND (SELECT MIN(ph.criado_em) FROM pedido_historico ph
                    WHERE ph.pedido_id = p.id AND ph.status_novo = ?)
                   BETWEEN ? AND ?
             GROUP BY dia
             ORDER BY dia"
        );
        $stmt->execute(array_merge(
            [self::STATUS_ENTREGA, $codigo], $condParams,
            [self::STATUS_ENTREGA, $de . ' 00:00:00', $ate . ' 23:59:59']
        ));
        return $stmt->fetchAll();
    }
}