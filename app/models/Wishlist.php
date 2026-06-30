<?php
class Wishlist extends Model {

    protected string $table = 'wishlist';

    /**
     * Retorna a lista padrão do cliente.
     * Cria automaticamente se não existir.
     */
    public function getListaPadrao(int $clienteId): int {
        $stmt = $this->db->prepare(
            "SELECT id FROM wishlist
             WHERE cliente_id = ? AND padrao = 1
             LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $id = $stmt->fetchColumn();

        if ($id) return (int)$id;

        // Cria se não existir
        $this->db->prepare(
            "INSERT INTO wishlist (cliente_id, nome, padrao, criado_em)
             VALUES (?, 'Meus favoritos', 1, NOW())"
        )->execute([$clienteId]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Verifica se um produto está na lista padrão.
     */
    public function isProdutoFavorito(int $clienteId, int $produtoId): bool {
        $stmt = $this->db->prepare(
            "SELECT wi.id
             FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id = ?
               AND w.padrao     = 1
               AND wi.produto_id = ?
             LIMIT 1"
        );
        $stmt->execute([$clienteId, $produtoId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Verifica favoritos de vários produtos de uma vez (batch).
     * Retorna array de produto_ids que estão na lista padrão.
     */
    public function getFavoritosBatch(int $clienteId, array $produtoIds): array {
        if (empty($produtoIds)) return [];

        $placeholders = implode(',', array_fill(0, count($produtoIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT wi.produto_id
             FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id  = ?
               AND w.padrao      = 1
               AND wi.produto_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$clienteId], $produtoIds));
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Adiciona à lista padrão.
     */
    public function favoritar(int $clienteId, int $produtoId): bool {
        $listaId = $this->getListaPadrao($clienteId);

        // Verifica se já existe
        $stmt = $this->db->prepare(
            "SELECT id FROM wishlist_itens
             WHERE wishlist_id = ? AND produto_id = ? LIMIT 1"
        );
        $stmt->execute([$listaId, $produtoId]);
        if ($stmt->fetchColumn()) return false; // já favoritado

        $this->db->prepare(
            "INSERT INTO wishlist_itens (wishlist_id, produto_id, adicionado_em)
             VALUES (?, ?, NOW())"
        )->execute([$listaId, $produtoId]);

        return true;
    }

    /**
     * Remove da lista padrão.
     */
    public function desfavoritar(int $clienteId, int $produtoId): void {
        $this->db->prepare(
            "DELETE wi FROM wishlist_itens wi
             JOIN wishlist w ON w.id = wi.wishlist_id
             WHERE w.cliente_id  = ?
               AND w.padrao      = 1
               AND wi.produto_id = ?"
        )->execute([$clienteId, $produtoId]);
    }
}