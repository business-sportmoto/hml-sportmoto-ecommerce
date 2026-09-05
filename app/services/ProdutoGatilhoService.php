<?php
/**
 * app/services/ProdutoGatilhoService.php
 *
 * Detecta dois gatilhos de produto e enfileira automações:
 *   1. QUEDA DE PREÇO  → quem tem o produto na wishlist recebe email (+1h)
 *   2. VOLTOU AO ESTOQUE → quem pediu "avise-me" recebe email
 *
 * COMO USAR (detecção ativa):
 *
 *   // Queda de preço — chame nos pontos que alteram preço, ANTES do UPDATE:
 *   $gatilho = new ProdutoGatilhoService();
 *   $precoAntigo = $gatilho->lerPrecoAtual($produtoId);   // antes do update
 *   // ... seu UPDATE de preço ...
 *   $gatilho->verificarQuedaPreco($produtoId, $precoAntigo, $precoNovo);
 *
 *   // Voltou ao estoque — chamado de dentro do EstoqueService::mover():
 *   $gatilho->verificarVoltaEstoque($produtoId, $saldoAnterior, $novoSaldo, $skuId);
 *
 * Nunca lança exceção — falha de gatilho não pode quebrar o save do produto.
 */
class ProdutoGatilhoService
{
    /** @var PDO */
    private $db;

    /** Delay padrão da automação de queda de preço (horas) */
    private const DELAY_QUEDA_PRECO_H = 1;

    /** Queda mínima de preço (%) para disparar — evita disparo por centavos */
    private const QUEDA_MINIMA_PCT = 1.0;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // =========================================================================
    // LEITURA DE PREÇO (chamar ANTES do update)
    // =========================================================================

    /**
     * Lê o preço vigente atual do produto (considera promoção ativa).
     * Chame isto ANTES de fazer o UPDATE para capturar o preço antigo.
     */
    public function lerPrecoAtual(int $produtoId): ?float
    {
        try {
            $st = $this->db->prepare(
                "SELECT preco, preco_promo, promo_inicio, promo_fim
                 FROM produtos WHERE id = :id LIMIT 1"
            );
            $st->execute([':id' => $produtoId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) return null;
            return $this->precoVigente($p);
        } catch (Throwable $e) {
            return null;
        }
    }

    // =========================================================================
    // GATILHO 1 — QUEDA DE PREÇO
    // =========================================================================

    /**
     * Verifica se houve queda de preço e enfileira automação para quem
     * tem o produto na wishlist.
     *
     * @return int Quantos clientes foram enfileirados
     */
    public function verificarQuedaPreco(int $produtoId, ?float $precoAntigo, ?float $precoNovo): int
    {
        try {
            if ($precoAntigo === null || $precoNovo === null) return 0;
            if ($precoAntigo <= 0 || $precoNovo <= 0)         return 0;

            // Só dispara se o preço CAIU além do mínimo percentual
            if ($precoNovo >= $precoAntigo) return 0;
            $quedaPct = (($precoAntigo - $precoNovo) / $precoAntigo) * 100;
            if ($quedaPct < self::QUEDA_MINIMA_PCT) return 0;

            // Busca quem tem o produto na wishlist
            $clientes = $this->clientesComProdutoNaWishlist($produtoId);
            if (empty($clientes)) return 0;

            $produto = $this->dadosProduto($produtoId);
            if (!$produto) return 0;

            $imgPro = ImageHelper::getCartItemImage($produto['produto_id']);

            $enfileirados = 0;
            foreach ($clientes as $c) {
                $ok = $this->enfileirarAutomacao('queda_preco', [
                    'cliente_id'    => (int)$c['cliente_id'],
                    'email'         => $c['email'],
                    'primeiro_nome' => $this->primeiroNome($c['nome'] ?? ''),
                    'produto_id'    => $produtoId,
                    'delay_horas'   => self::DELAY_QUEDA_PRECO_H,
                    'contexto'      => [
                        'produto_nome'  => $produto['nome'],
                        'produto_url'   => $this->urlProduto($produto),
                        'produto_img'   => $imgPro,
                        'preco_antigo'  => $this->fmtMoeda($precoAntigo),
                        'preco_novo'    => $this->fmtMoeda($precoNovo),
                        'desconto_pct'  => (int)round($quedaPct),
                    ],
                ]);
                if ($ok) $enfileirados++;
            }

            if ($enfileirados > 0 && class_exists('LogService')) {
                try { LogService::info("gatilho queda_preco: produto {$produtoId}, {$enfileirados} clientes enfileirados (queda " . round($quedaPct) . "%)"); } catch (Throwable $e) {}
            }
            return $enfileirados;

        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error("verificarQuedaPreco: " . $e->getMessage()); } catch (Throwable $x) {}
            }
            return 0;
        }
    }

    // =========================================================================
    // GATILHO 2 — VOLTOU AO ESTOQUE
    // =========================================================================

    /**
     * Verifica se o produto voltou ao estoque (0 → positivo) e notifica
     * quem pediu "avise-me".
     *
     * Chame de dentro do EstoqueService::mover() após calcular os saldos.
     *
     * @return int Quantos avisos foram enfileirados
     */
    public function verificarVoltaEstoque(int $produtoId, int $saldoAnterior, int $novoSaldo, ?int $skuId = null): int
    {
        try {
            // Só dispara na transição de esgotado (0) para disponível (>0)
            if ($saldoAnterior > 0 || $novoSaldo <= 0) return 0;

            // Busca inscrições "avise-me" pendentes deste produto
            $avisos = $this->avisosPendentes($produtoId, $skuId);
            if (empty($avisos)) return 0;

            $produto = $this->dadosProduto($produtoId);
            if (!$produto) return 0;

            $enfileirados = 0;
            $idsNotificados = [];

            $imgPro = ImageHelper::getCartItemImage($produto['produto_id']);

            foreach ($avisos as $a) {
                $ok = $this->enfileirarAutomacao('volta_estoque', [
                    'cliente_id'    => $a['cliente_id'] ? (int)$a['cliente_id'] : null,
                    'email'         => $a['email'],
                    'primeiro_nome' => $this->primeiroNome($a['nome'] ?? ''),
                    'produto_id'    => $produtoId,
                    'delay_horas'   => 0, // imediato
                    'contexto'      => [
                        'produto_nome' => $produto['nome'],
                        'produto_url'  => $this->urlProduto($produto),
                        'produto_img'  => $imgPro,
                        'produto_preco'=> $this->fmtMoeda($this->precoVigente($produto)),
                    ],
                ]);
                if ($ok) {
                    $enfileirados++;
                    $idsNotificados[] = (int)$a['id'];
                }
            }

            // Marca os avisos como notificados (não notifica de novo)
            if (!empty($idsNotificados)) {
                $in = implode(',', array_fill(0, count($idsNotificados), '?'));
                $st = $this->db->prepare(
                    "UPDATE aviso_estoque SET status='notificado', notificado_em=NOW()
                     WHERE id IN ($in)"
                );
                $st->execute($idsNotificados);
            }

            if ($enfileirados > 0 && class_exists('LogService')) {
                try { LogService::info("gatilho volta_estoque: produto {$produtoId}, {$enfileirados} avisos enviados"); } catch (Throwable $e) {}
            }
            return $enfileirados;

        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error("verificarVoltaEstoque: " . $e->getMessage()); } catch (Throwable $x) {}
            }
            return 0;
        }
    }

    // =========================================================================
    // INSCRIÇÃO "AVISE-ME"
    // =========================================================================

    /**
     * Registra uma inscrição de "avise-me quando chegar".
     * Chamado pelo endpoint público.
     *
     * @return array{ok:bool, msg:string}
     */
    public function inscreverAviso(int $produtoId, string $email, ?string $nome = null, ?int $clienteId = null, ?int $skuId = null): array
    {
        try {
            $email = trim(mb_strtolower($email));
            if ($produtoId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'msg' => 'Dados inválidos.'];
            }

            // Já está inscrito e pendente?
            $st = $this->db->prepare(
                "SELECT id FROM aviso_estoque
                 WHERE produto_id = :p AND email = :e AND status = 'pendente'
                   AND (sku_id = :s OR (sku_id IS NULL AND :s2 IS NULL))
                 LIMIT 1"
            );
            $st->execute([':p' => $produtoId, ':e' => $email, ':s' => $skuId, ':s2' => $skuId]);
            if ($st->fetchColumn()) {
                return ['ok' => true, 'msg' => 'Você já será avisado quando este produto chegar.'];
            }

            $ins = $this->db->prepare(
                "INSERT INTO aviso_estoque (produto_id, sku_id, cliente_id, email, nome, status)
                 VALUES (:p, :s, :c, :e, :n, 'pendente')"
            );
            $ins->execute([
                ':p' => $produtoId,
                ':s' => $skuId,
                ':c' => $clienteId,
                ':e' => $email,
                ':n' => $nome,
            ]);

            return ['ok' => true, 'msg' => 'Pronto! Avisaremos assim que o produto voltar ao estoque.'];

        } catch (Throwable $e) {
            // Pode cair aqui por causa do UNIQUE — tratamos como sucesso silencioso
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                return ['ok' => true, 'msg' => 'Você já será avisado quando este produto chegar.'];
            }
            return ['ok' => false, 'msg' => 'Erro ao registrar. Tente novamente.'];
        }
    }

    // =========================================================================
    // ENFILEIRAMENTO NA AUTOMAÇÃO
    // Usa exatamente o mesmo padrão dos métodos detectar* do AutomacaoService:
    //   $this->model->enfileirar(['fluxo_id','passo_id','cliente_id',
    //                             'contexto','disparo_em','chave_dedup'])
    // =========================================================================

    /**
     * Enfileira na automação.
     * INSERT direto nas colunas exatas da tabela automacao_fila:
     *   fluxo_id, passo_id, cliente_id, contexto_json, disparo_em, chave_dedup
     *
     * @param string $tipo  'queda_preco' | 'volta_estoque'
     * @param array  $dados cliente_id, produto_id, delay_horas, contexto, chave_dedup
     */
    private function enfileirarAutomacao(string $tipo, array $dados): bool
    {
        // ── MOTOR v1 APOSENTADO (04/09/2026) ──────────────────────────────
        // Sem este guard o servico seguiria escrevendo em automacao_fila a
        // cada mudanca de preco e cada volta de estoque, enchendo uma fila
        // que nenhum worker drena.
        //
        // O que NAO se perde: a inscricao do cliente continua sendo gravada
        // normalmente em `aviso_estoque` (inscreverAviso) e a wishlist em
        // `wishlist_itens`. Perde-se a ENTREGA, nunca a LISTA — quando o v2
        // ganhar os eventos `queda_preco` e `volta_estoque`, os interessados
        // ainda estarao la.
        if (!defined('AUTOMACAO_V1_PERMITIDO')) {
            if (class_exists('LogService')) {
                try {
                    LogService::info('gatilho: v1 aposentado, envio nao enfileirado', [
                        'tipo'       => $tipo,
                        'produto_id' => $dados['produto_id'] ?? null,
                        'cliente_id' => $dados['cliente_id'] ?? null,
                    ]);
                } catch (Throwable $x) { /* log nunca quebra o gatilho */ }
            }
            return false;
        }

        try {
            // ── 1. Acha o fluxo ativo ────────────────────────────────────────
            $fluxo = $this->fluxoAtivoPorTipo($tipo);
            if (!$fluxo) {
                if (class_exists('LogService')) {
                    try { LogService::warning("gatilho[$tipo]: fluxo '$tipo' não encontrado ou inativo"); } catch (Throwable $x) {}
                }
                return false;
            }

            // ── 2. Acha o primeiro passo do fluxo ───────────────────────────
            $stPasso = $this->db->prepare(
                "SELECT id, delay_horas FROM automacao_passos
                 WHERE fluxo_id = :fid ORDER BY ordem ASC LIMIT 1"
            );
            $stPasso->execute([':fid' => $fluxo['id']]);
            $passo = $stPasso->fetch(PDO::FETCH_ASSOC);

            if (!$passo) {
                if (class_exists('LogService')) {
                    try { LogService::warning("gatilho[$tipo]: fluxo {$fluxo['id']} sem passos cadastrados"); } catch (Throwable $x) {}
                }
                return false;
            }

            // ── 3. Monta campos ─────────────────────────────────────────────
            // isset() retorna true mesmo com null — usa array_key_exists
            // para distinguir "chave ausente" de "chave existe com valor null"
            $clienteId = (array_key_exists('cliente_id', $dados) && $dados['cliente_id'] !== null)
                ? (int)$dados['cliente_id']
                : null;

            $delayH    = (int)($dados['delay_horas'] ?? $passo['delay_horas'] ?? 0);
            $disparoEm = date('Y-m-d H:i:s', time() + $delayH * 3600);

            // chave_dedup: max 64 chars (UNIQUE KEY da tabela)
            $dedup = mb_substr(
                $dados['chave_dedup']
                    ?? ($tipo . '_p' . ($dados['produto_id'] ?? 0)
                        . '_c' . ($clienteId ?? 'anon')
                        . '_s' . $passo['id']),
                0, 64
            );

            // contexto: inclui email/nome para visitantes sem cliente_id
            // o AutomacaoDispatchService usa como fallback para buscar o destinatario
            $contexto = $dados['contexto'] ?? [];
            if ($clienteId === null && !empty($dados['email'])) {
                $contexto['email']         = $dados['email'];
                $contexto['primeiro_nome'] = $dados['primeiro_nome'] ?? '';
            }
            $contextoJson = json_encode(
                $contexto,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            // ── 4. INSERT nas colunas exatas da tabela ───────────────────────
            // Colunas: fluxo_id, passo_id, cliente_id, contexto_json,
            //          disparo_em, chave_dedup
            // (status, tentativas, criado_em têm DEFAULT; cupom_id é NULL)
            $st = $this->db->prepare(
                "INSERT IGNORE INTO automacao_fila
                 (fluxo_id, passo_id, cliente_id, contexto_json, disparo_em, chave_dedup)
                 VALUES (:fid, :pid, :cid, :ctx, :disp, :dedup)"
            );
            $st->execute([
                ':fid'   => (int)$fluxo['id'],
                ':pid'   => (int)$passo['id'],
                ':cid'   => $clienteId,          // pode ser NULL (visitante anônimo)
                ':ctx'   => $contextoJson,
                ':disp'  => $disparoEm,
                ':dedup' => $dedup,
            ]);

            $inserido = $st->rowCount() > 0;

            if ($inserido && class_exists('LogService')) {
                try { LogService::info("gatilho[$tipo]: enfileirado cliente=$clienteId dedup=$dedup disparo=$disparoEm"); } catch (Throwable $x) {}
            }

            return $inserido; // false se caiu no IGNORE (dedup duplicado — normal)

        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error("enfileirarAutomacao [$tipo]: " . $e->getMessage()); } catch (Throwable $x) {}
            }
            return false;
        }
    }

    /**
     * Busca o fluxo ativo de um tipo. Usa o model se tiver método, senão
     * cai num SELECT direto na tabela automacao_fluxos.
     */
    private function fluxoAtivoPorTipo(string $tipo): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM automacao_fluxos WHERE tipo = :t AND ativo = 1 LIMIT 1"
        );
        $st->execute([':t' => $tipo]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        return $f ?: null;
    }

    // =========================================================================
    // CONSULTAS AUXILIARES
    // =========================================================================

    private function clientesComProdutoNaWishlist(int $produtoId): array
    {
        $st = $this->db->prepare(
            "SELECT DISTINCT c.id AS cliente_id, u.email, u.nome, u.id AS usuario_id
             FROM wishlist_itens wi
             JOIN wishlist w  ON w.id = wi.wishlist_id
             JOIN clientes c  ON c.id = w.cliente_id
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE wi.produto_id = :p
               AND u.email IS NOT NULL AND u.email <> ''"
        );
        $st->execute([':p' => $produtoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function avisosPendentes(int $produtoId, ?int $skuId): array
    {
        if ($skuId !== null) {
            $st = $this->db->prepare(
                "SELECT id, cliente_id, email, nome FROM aviso_estoque
                 WHERE produto_id = :p AND status = 'pendente'
                   AND (sku_id = :s OR sku_id IS NULL)"
            );
            $st->execute([':p' => $produtoId, ':s' => $skuId]);
        } else {
            $st = $this->db->prepare(
                "SELECT id, cliente_id, email, nome FROM aviso_estoque
                 WHERE produto_id = :p AND status = 'pendente'"
            );
            $st->execute([':p' => $produtoId]);
        }
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function dadosProduto(int $produtoId): ?array
    {
        $st = $this->db->prepare(
            "SELECT p.id, p.nome, p.slug, p.preco, p.preco_promo, p.promo_inicio, p.promo_fim,
                    (SELECT pi.arquivo FROM produto_imagens pi
                     WHERE pi.produto_id = p.id
                     ORDER BY pi.principal DESC, pi.ordem ASC LIMIT 1) AS imagem,
                     p.id AS produto_id
             FROM produtos p WHERE p.id = :id LIMIT 1"
        );
        $st->execute([':id' => $produtoId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        return $p ?: null;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Replica a lógica do PriceHelper::currentPrice sem depender de ConfigHelper. */
    private function precoVigente(array $produto): float
    {
        if (!empty($produto['preco_promo']) && $produto['preco_promo'] > 0) {
            $now    = time();
            $inicio = !empty($produto['promo_inicio']) ? strtotime($produto['promo_inicio']) : 0;
            $fim    = !empty($produto['promo_fim'])    ? strtotime($produto['promo_fim'])    : PHP_INT_MAX;
            if ($now >= $inicio && $now <= $fim) {
                return (float)$produto['preco_promo'];
            }
        }
        return (float)($produto['preco'] ?? 0);
    }

    private function urlProduto(array $produto): string
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $slug = $produto['slug'] ?? $produto['id'];
        return $base . '/produto/' . $slug;
    }

    private function fmtMoeda(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    private function primeiroNome(string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '') return 'Cliente';
        $partes = explode(' ', $nome);
        return $partes[0];
    }
}