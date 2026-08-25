<?php
// app/services/app/CartMergeService.php
// Junta o carrinho de visitante ao carrinho do cliente no momento do login.
//
// ── Por que este serviço existe ─────────────────────────────────────────────
// Hoje o projeto tem DUAS implementações e usa a menos correta:
//
//   1. Cart::getOrCreate() (app/models/Cart.php:111-114) apenas ADOTA o
//      carrinho da sessão, gravando cliente_id nele. Se o cliente já tinha um
//      carrinho de outro dispositivo, aquele fica órfão — os itens antigos
//      simplesmente somem da vista do usuário.
//
//   2. AuthController::mergeGuestCart() (:1620) faz a junção correta, somando
//      quantidades em caso de item repetido. Está definido e NUNCA é chamado —
//      é código morto desde que foi escrito.
//
// Este serviço é a lógica de (2), extraída e corrigida, para ser o caminho
// único. O app usa daqui em diante. A loja web continua no comportamento atual
// até que alguém decida trocar conscientemente — ligar isto lá muda o que o
// usuário vê ao logar e não é mudança para se fazer de passagem.

class CartMergeService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * Junta o carrinho anônimo ao do cliente.
     *
     * @param  int      $clienteId
     * @param  int|null $carrinhoAnonimoId Carrinho da sessão antes do login.
     * @return array{carrinho_id:int|null, mesclado:bool, itens_somados:int}
     */
    public function mesclar(int $clienteId, ?int $carrinhoAnonimoId): array
    {
        if (!$carrinhoAnonimoId) {
            return ['carrinho_id' => $this->carrinhoDoCliente($clienteId), 'mesclado' => false, 'itens_somados' => 0];
        }

        // Só mescla carrinho que é de fato anônimo. Sem esta checagem, um id
        // adulterado permitiria puxar o carrinho de outra pessoa.
        $st = $this->pdo->prepare(
            "SELECT id FROM carrinhos
             WHERE id = :id AND cliente_id IS NULL AND status <> 'finalizado'
             LIMIT 1"
        );
        $st->execute([':id' => $carrinhoAnonimoId]);

        if (!$st->fetchColumn()) {
            return ['carrinho_id' => $this->carrinhoDoCliente($clienteId), 'mesclado' => false, 'itens_somados' => 0];
        }

        $carrinhoCliente = $this->carrinhoDoCliente($clienteId);

        // Cliente ainda não tinha carrinho: o anônimo passa a ser dele.
        if (!$carrinhoCliente) {
            $this->pdo->prepare("UPDATE carrinhos SET cliente_id = :c WHERE id = :id")
                ->execute([':c' => $clienteId, ':id' => $carrinhoAnonimoId]);

            return ['carrinho_id' => $carrinhoAnonimoId, 'mesclado' => false, 'itens_somados' => 0];
        }

        // Mesmo carrinho — nada a fazer.
        if ($carrinhoCliente === $carrinhoAnonimoId) {
            return ['carrinho_id' => $carrinhoCliente, 'mesclado' => false, 'itens_somados' => 0];
        }

        return $this->juntarItens($carrinhoAnonimoId, $carrinhoCliente);
    }

    /**
     * Move os itens de um carrinho para o outro somando quantidades.
     *
     * Feito item a item em vez de com ON DUPLICATE KEY UPDATE (como fazia o
     * mergeGuestCart original): aquele INSERT dependia de existir um índice
     * único em (carrinho_id, produto_id, estoque_id), e um produto com
     * variação tem o mesmo produto_id em SKUs diferentes. Sem a chave certa,
     * o "somar" silenciosamente virava "duplicar".
     */
    private function juntarItens(int $origem, int $destino): array
    {
        $somados = 0;

        try {
            $this->pdo->beginTransaction();

            $itens = $this->pdo->prepare(
                "SELECT id, produto_id, sku_id, quantidade, preco_unitario
                 FROM carrinho_itens WHERE carrinho_id = :c"
            );
            $itens->execute([':c' => $origem]);

            $existente = $this->pdo->prepare(
                "SELECT id, quantidade FROM carrinho_itens
                 WHERE carrinho_id = :c
                   AND produto_id = :p
                   AND (sku_id <=> :s)
                 LIMIT 1"
            );

            $somar = $this->pdo->prepare(
                "UPDATE carrinho_itens SET quantidade = quantidade + :q WHERE id = :id"
            );

            $mover = $this->pdo->prepare(
                "UPDATE carrinho_itens SET carrinho_id = :c WHERE id = :id"
            );

            foreach ($itens->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $existente->execute([
                    ':c' => $destino,
                    ':p' => (int)$item['produto_id'],
                    ':s' => $item['sku_id'] !== null ? (int)$item['sku_id'] : null,
                ]);
                $alvo = $existente->fetch(PDO::FETCH_ASSOC);

                if ($alvo) {
                    $somar->execute([':q' => (int)$item['quantidade'], ':id' => (int)$alvo['id']]);
                    $somados++;
                } else {
                    $mover->execute([':c' => $destino, ':id' => (int)$item['id']]);
                }
            }

            // O carrinho de origem some: manter dois ativos confundiria
            // getOrCreateCarrinhoId() na próxima requisição.
            $this->pdo->prepare("DELETE FROM carrinho_itens WHERE carrinho_id = :c")->execute([':c' => $origem]);
            $this->pdo->prepare("DELETE FROM carrinhos WHERE id = :c")->execute([':c' => $origem]);

            $this->pdo->prepare("UPDATE carrinhos SET atualizado_em = NOW() WHERE id = :c")
                ->execute([':c' => $destino]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            LogService::error('Falha ao mesclar carrinhos', [
                'erro' => $e->getMessage(),
                'origem' => $origem,
                'destino' => $destino,
            ]);
            // Falhar aqui não pode impedir o login: o cliente fica com o
            // carrinho que já era dele.
            return ['carrinho_id' => $destino, 'mesclado' => false, 'itens_somados' => 0];
        }

        return ['carrinho_id' => $destino, 'mesclado' => true, 'itens_somados' => $somados];
    }

    private function carrinhoDoCliente(int $clienteId): ?int
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT id FROM carrinhos
                 WHERE cliente_id = :c AND status <> 'finalizado'
                 ORDER BY atualizado_em DESC LIMIT 1"
            );
            $st->execute([':c' => $clienteId]);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
