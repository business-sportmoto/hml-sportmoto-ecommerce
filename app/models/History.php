<?php
// app/models/History.php

class History extends Model {

    protected string $table = 'historico_navegacao';

    // ── Registro ──────────────────────────────────────────────

    /**
     * Registra uma visita.
     * Evita duplicatas em janela de 30 minutos para o mesmo item.
     */
    public function record(string $tipo, ?int $referenciaId, array $extra = [], $uri = null): void {
        $clienteId = Session::getClienteId();
        $sessaoId  = session_id();

        $uri = $uri ?? mb_substr($_SERVER['REQUEST_URI'] ?? '', 0, 500);

        // Evita spam: ignora se o mesmo item foi registrado nos últimos 30 min
        if ($referenciaId && $this->recentlyViewed($clienteId, $sessaoId, $tipo, $referenciaId)) {
            return;
        }

        $this->insert([
            'cliente_id'    => $clienteId,
            'sessao_id'     => $sessaoId,
            'tipo'          => $tipo,
            'referencia_id' => $referenciaId,
            'termo_busca'   => $extra['termo_busca'] ?? null,
            'url'           => $uri,
            'tempo_pagina'  => null, // atualizado depois via Ajax
        ]);
    }

    /**
     * Atualiza o tempo gasto na página (chamado via Ajax ao sair).
     */
    public function updateTime(int $id, int $segundos): void {
        $this->db->prepare(
            "UPDATE historico_navegacao
             SET tempo_pagina = ?
             WHERE id = ?
               AND sessao_id = ?"
        )->execute([min($segundos, 3600), $id, session_id()]);
    }

    private function recentlyViewed(?int $clienteId, string $sessaoId,
                                      string $tipo, int $refId): bool {
        $col    = $clienteId ? 'cliente_id' : 'sessao_id';
        $val    = $clienteId ?: $sessaoId;

        $stmt = $this->db->prepare(
            "SELECT id FROM historico_navegacao
             WHERE {$col} = ?
               AND tipo = ?
               AND referencia_id = ?
               AND criado_em >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             LIMIT 1"
        );
        $stmt->execute([$val, $tipo, $refId]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Consultas do cliente ──────────────────────────────────

    /**
     * Histórico completo paginado para exibir no perfil.
     */
    public function getClienteHistory(int $clienteId, int $limit = 30,
                                   int $offset = 0): array {
        $stmt = $this->db->prepare(
            "SELECT h.*, 
                    p.nome     AS produto_nome,
                    p.slug     AS produto_slug,
                    pi.arquivo AS produto_img,

                    c.nome     AS categoria_nome,
                    c.slug     AS categoria_slug,

                    cl.titulo  AS clip_nome,
                    cl.id      AS clip_id,
                    cl.arquivo_poster AS clip_poster,

                    m.nome     AS marca_nome,
                    m.slug     AS marca_slug,
                    m.logo     AS marca_logo,
                    m.bg_cor   AS marca_bg_cor

            FROM historico_navegacao h

            LEFT JOIN produtos p
                    ON p.id = h.referencia_id AND h.tipo = 'produto'
            LEFT JOIN produto_imagens pi
                    ON pi.produto_id = p.id AND pi.principal = 1

            LEFT JOIN categorias c
                    ON c.id = h.referencia_id AND h.tipo = 'categoria'

            LEFT JOIN marcas m
                    ON m.id = h.referencia_id AND h.tipo = 'marca'
            
            LEFT JOIN clips cl
                    ON cl.id = h.referencia_id AND h.tipo = 'clip'

            WHERE h.cliente_id = ?
            ORDER BY h.criado_em DESC
            LIMIT ? OFFSET ?"
        );
        $stmt->execute([$clienteId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function countClienteHistory(int $clienteId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM historico_navegacao WHERE cliente_id = ?"
        );
        $stmt->execute([$clienteId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Produtos mais vistos pelo cliente (para recomendações).
     */
    // public function getMaisVistos(int $clienteId, int $limit = 8): array {
    //     $stmt = $this->db->prepare(
    //         "SELECT p.*, pi.arquivo AS imagem_principal,
    //                 c.nome AS categoria_nome, c.slug AS categoria_slug,
    //                 m.nome AS marca_nome,
    //                 COUNT(h.id) AS vezes_visto,
    //                 MAX(h.criado_em) AS ultima_visita
    //          FROM historico_navegacao h
    //          JOIN produtos p  ON p.id = h.referencia_id
    //          LEFT JOIN produto_imagens pi ON pi.produto_id = p.id AND pi.principal = 1
    //          LEFT JOIN categorias c ON c.id = p.categoria_id
    //          LEFT JOIN marcas m     ON m.id = p.marca_id
    //          WHERE h.cliente_id = ?
    //            AND h.tipo = 'produto'
    //            AND p.ativo = 1
    //          GROUP BY p.id
    //          ORDER BY vezes_visto DESC, ultima_visita DESC
    //          LIMIT ?"
    //     );
    //     $stmt->execute([$clienteId, $limit]);
    //     return $stmt->fetchAll();
    // }

    public function getMaisVistos(int $clienteId, int $limit = 8): array
    {
        $sql = "
            SELECT 
                p.id,
                p.nome,
                p.slug,
                p.preco,                            
                p.categoria_id,
                p.marca_id,
                p.ativo,
                MAX(pi.arquivo) AS imagem_principal,
                c.nome AS categoria_nome,
                c.slug AS categoria_slug,
                m.nome AS marca_nome,
                COUNT(h.id) AS vezes_visto,
                MAX(h.criado_em) AS ultima_visita
            FROM historico_navegacao h
            JOIN produtos p ON p.id = h.referencia_id
            LEFT JOIN produto_imagens pi 
                ON pi.produto_id = p.id 
            AND pi.principal = 1
            LEFT JOIN categorias c ON c.id = p.categoria_id
            LEFT JOIN marcas m ON m.id = p.marca_id
            WHERE h.cliente_id = :cliente_id
            AND h.tipo = 'produto'
            AND p.ativo = 1
            GROUP BY 
                p.id,
                p.nome,
                p.slug,
                p.preco,                                
                p.categoria_id,
                p.marca_id,
                p.ativo,
                c.nome,
                c.slug,
                m.nome
            ORDER BY vezes_visto DESC, ultima_visita DESC
            LIMIT :limite
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':cliente_id', $clienteId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Categorias favoritas do cliente (para campanhas).
     */
    public function getCategoriasFavoritas(int $clienteId, int $limit = 5): array {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.nome, c.slug,
                    COUNT(h.id) AS visualizacoes,
                    MAX(h.criado_em) AS ultima_visita
             FROM historico_navegacao h
             JOIN categorias c ON c.id = h.referencia_id
             WHERE h.cliente_id = ?
               AND h.tipo = 'categoria'
               AND c.ativo = 1
             GROUP BY c.id
             ORDER BY visualizacoes DESC
             LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Marcas favoritas do cliente (para campanhas).
     */
    public function getMarcasFavoritas(int $clienteId, int $limit = 5): array {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.nome, c.slug,
                    COUNT(h.id) AS visualizacoes,
                    MAX(h.criado_em) AS ultima_visita
             FROM historico_navegacao h
             JOIN marcas c ON c.id = h.referencia_id
             WHERE h.cliente_id = ?
               AND h.tipo = 'marca'
               AND c.ativo = 1
             GROUP BY c.id
             ORDER BY visualizacoes DESC
             LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Termos de busca mais usados pelo cliente.
     */
    public function getTermosBusca(int $clienteId, int $limit = 10): array {
        $stmt = $this->db->prepare(
            "SELECT termo_busca, COUNT(*) AS total,
                    MAX(criado_em) AS ultima_vez
             FROM historico_navegacao
             WHERE cliente_id = ?
               AND tipo = 'busca'
               AND termo_busca IS NOT NULL
               AND termo_busca != ''
             GROUP BY termo_busca
             ORDER BY total DESC
             LIMIT ?"
        );
        $stmt->execute([$clienteId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Limpa histórico do cliente.
     */
    public function clearHistory(int $clienteId): void {
        $this->db->prepare(
            "DELETE FROM historico_navegacao WHERE cliente_id = ?"
        )->execute([$clienteId]);
    }

    // ── Dados para campanhas (uso admin) ─────────────────────

    /**
     * Clientes interessados em um produto mas que não compraram.
     */
    public function getInteressadosSemCompra(int $productId): array {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT h.cliente_id, u.nome, u.email,
                    COUNT(h.id) AS visualizacoes,
                    MAX(h.criado_em) AS ultima_visita
             FROM historico_navegacao h
             JOIN clientes c ON c.id = h.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE h.tipo = 'produto'
               AND h.referencia_id = ?
               AND h.cliente_id IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM pedido_itens pi
                   JOIN pedidos ped ON ped.id = pi.pedido_id
                   WHERE pi.produto_id = ? AND ped.cliente_id = h.cliente_id
                     AND ped.status_pagamento = 'aprovado'
               )
             GROUP BY h.cliente_id
             ORDER BY visualizacoes DESC"
        );
        $stmt->execute([$productId, $productId]);
        return $stmt->fetchAll();
    }

    /**
     * Segmento de clientes por categoria de interesse.
     */
    public function getSegmentoPorCategoria(int $categoryId): array {
        $stmt = $this->db->prepare(
            "SELECT h.cliente_id, u.nome, u.email,
                    COUNT(h.id) AS visualizacoes,
                    MAX(h.criado_em) AS ultima_visita
             FROM historico_navegacao h
             JOIN clientes c ON c.id = h.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE h.tipo = 'categoria'
               AND h.referencia_id = ?
               AND h.cliente_id IS NOT NULL
             GROUP BY h.cliente_id
             ORDER BY visualizacoes DESC"
        );
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    }
}