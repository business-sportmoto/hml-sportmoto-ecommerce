<?php
// app/controllers/AppAvaliacoesController.php
//
// Avaliações de produto: ler, escrever, anexar mídia e votar "útil".
//
// A leitura substitui AppProdutoController::avaliacoes(), que trazia só nota e
// texto — sem foto, sem selo de compra verificada e sem saber se este cliente
// já votou. Aqui a listagem custa 4 queries fixas para a página inteira:
// linhas, mídias em lote (Review::listar), votos em lote e a contagem.
//
// A escrita exige login. Não é escolha de produto: avaliacoes.cliente_id é
// NOT NULL com FK para clientes, então uma avaliação anônima simplesmente não
// entra na tabela.

class AppAvaliacoesController extends AppApiController
{
    private const MIN_COMENTARIO = 10;
    private const MAX_COMENTARIO = 2000;
    private const MAX_TITULO     = 150;

    /**
     * GET /api/app/v1/produtos/{id}/avaliacoes
     * ?filtro=todas|fotos|videos|5..1  &ordem=recentes|uteis|maior|menor
     *
     * A página 1 traz também o resumo e a galeria de mídias do produto — são os
     * dois blocos que aparecem antes da lista na tela, e pedi-los em requests
     * separados só adicionaria latência.
     */
    public function index(string $id = '0'): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $produtoId = (int)$id;
        if ($produtoId <= 0) {
            $this->falha(422, 'produto_invalido', 'Produto inválido.');
        }

        $pagina = $this->pagina(10, 30);
        $filtro = $this->filtroValido((string)$this->query('filtro', 'todas'));
        $ordem  = $this->ordemValida((string)$this->query('ordem', 'recentes'));

        $review = new Review();
        $ctx    = $this->contexto();

        $rows  = $review->listar($produtoId, $pagina['page'], $pagina['limit'], $filtro, $ordem);
        $total = $review->countFiltrado($produtoId, $filtro);

        $votos = $review->votosEmLote(
            array_column($rows, 'id'),
            $this->clienteId,
            $ctx->sessaoKey
        );

        $extra = [];

        if ($pagina['page'] === 1) {
            $extra['resumo'] = AvaliacaoPresenter::resumo(
                $review->getResumo($produtoId),
                $review->contarComMidia($produtoId)
            );
            $extra['galeria'] = AvaliacaoPresenter::galeria(
                $review->getMidiasGlobal($produtoId, 20),
                $ctx
            );
            // O app precisa saber se oferece o botão "avaliar" e por quê.
            $extra['posso_avaliar'] = $this->podeAvaliar($produtoId);
        }

        $this->okPaginado(
            'avaliacoes',
            AvaliacaoPresenter::colecao($rows, $ctx, $votos),
            $total,
            $pagina,
            $extra
        );
    }

    /**
     * POST /api/app/v1/produtos/{id}/avaliacoes
     * Corpo: { nota, comentario, titulo?, midia_token? }
     *
     * Publica direto quando há compra aprovada do produto; caso contrário fica
     * em moderação, como na loja.
     */
    public function criar(string $id = '0'): void
    {
        $this->bootCliente();

        $produtoId = (int)$id;
        $corpo     = $this->exigirCampos(['nota', 'comentario']);

        $nota       = (int)$corpo['nota'];
        $comentario = trim((string)$corpo['comentario']);
        $titulo     = trim((string)($corpo['titulo'] ?? ''));
        $midiaToken = $this->tokenDeMidia($corpo['midia_token'] ?? '');

        if ($nota < 1 || $nota > 5) {
            $this->falha(422, 'nota_invalida', 'A nota vai de 1 a 5 estrelas.');
        }

        $tamanho = mb_strlen($comentario);
        if ($tamanho < self::MIN_COMENTARIO) {
            $this->falha(422, 'comentario_curto',
                'Conte um pouco mais sobre sua experiência (mínimo ' . self::MIN_COMENTARIO . ' caracteres).');
        }
        if ($tamanho > self::MAX_COMENTARIO) {
            $this->falha(422, 'comentario_longo', 'O comentário passou de ' . self::MAX_COMENTARIO . ' caracteres.');
        }
        if (mb_strlen($titulo) > self::MAX_TITULO) {
            $titulo = mb_substr($titulo, 0, self::MAX_TITULO);
        }

        $produto = (new Product())->find($produtoId);
        if (!$produto) {
            $this->falha(404, 'nao_encontrado', 'Produto não encontrado.');
        }

        $review = new Review();

        if ($review->jaAvaliou($produtoId, (int)$this->clienteId)) {
            $this->falha(409, 'ja_avaliou', 'Você já avaliou este produto.');
        }

        // Mesmo teto da loja: 3 envios por hora por IP.
        if (!$review->checarRateLimit((string)$this->ipReal())) {
            $this->falha(429, 'muitas_avaliacoes', 'Muitas avaliações recentes. Tente de novo mais tarde.');
        }

        $pedidoId = $this->pedidoAprovadoCom($produtoId);
        $aprovado = $pedidoId ? 1 : 0;

        try {
            $avaliacaoId = $review->salvar([
                'produto_id'   => $produtoId,
                'cliente_id'   => (int)$this->clienteId,
                'pedido_id'    => $pedidoId,
                'cliente_nome' => null,     // vem do JOIN com usuarios na leitura
                'nota'         => $nota,
                'titulo'       => $titulo !== '' ? $titulo : null,
                'comentario'   => $comentario,
                'aprovado'     => $aprovado,
                'ip'           => $this->ipReal(),
            ]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'avaliacao_criar', 'produto' => $produtoId]);
            $this->falha(500, 'falha_salvar', 'Não foi possível registrar sua avaliação.');
        }

        if ($midiaToken !== '') {
            try {
                $review->vincularMidias($avaliacaoId, $midiaToken);
            } catch (\Throwable $e) {
                // A avaliação já existe; perder a foto não justifica desfazê-la.
                AppLog::exception($e, ['acao' => 'avaliacao_midias', 'avaliacao' => $avaliacaoId]);
            }
        }

        $this->ok([
            'id'       => $avaliacaoId,
            'aprovada' => (bool)$aprovado,
            'mensagem' => $aprovado
                ? 'Avaliação publicada. Obrigado!'
                : 'Avaliação enviada. Ela aparece assim que passar pela moderação.',
        ], 201);
    }

    /**
     * POST /api/app/v1/avaliacoes/midias   (multipart: midia, token?)
     *
     * Sobe uma foto ou vídeo ANTES de a avaliação existir. O `token` devolvido
     * amarra os arquivos; mande-o de volta em midia_token ao publicar.
     */
    public function enviarMidia(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $arquivo = $_FILES['midia'] ?? null;
        if (!is_array($arquivo)) {
            $this->falha(422, 'arquivo_ausente', 'Nenhuma mídia recebida.');
        }

        $token   = $this->tokenDeMidia($_POST['token'] ?? '');
        $servico = new AvaliacaoMidiaService();

        $r = $servico->guardar($arquivo, $token, $this->ipReal());

        if (empty($r['ok'])) {
            $this->falha(422, 'midia_recusada', (string)($r['erro'] ?? 'Mídia recusada.'));
        }

        $midia = $r['midia'];
        $ctx   = $this->contexto();

        $this->ok([
            'token'   => $midia['token'],
            'arquivo' => $midia['arquivo'],
            'tipo'    => $midia['tipo'],
            'url'     => $ctx->url('avaliacoes/' . $midia['arquivo']),
            'thumb'   => $midia['thumb'] ? $ctx->url('avaliacoes/' . $midia['thumb']) : null,
            'restantes' => max(0, AvaliacaoMidiaService::MAX_POR_AVALIACAO - $servico->contar($midia['token'])),
        ], 201);
    }

    /**
     * DELETE /api/app/v1/avaliacoes/midias
     * Corpo: { token, arquivo }
     */
    public function removerMidia(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $corpo   = $this->exigirCampos(['token', 'arquivo']);
        $token   = $this->tokenDeMidia($corpo['token']);
        $arquivo = (string)$corpo['arquivo'];

        if ($token === '') {
            $this->falha(422, 'token_invalido', 'Token de mídia inválido.');
        }

        // O dono aqui é o cliente autenticado, não o IP: num celular o IP muda
        // entre o Wi-Fi e a rede móvel no meio do próprio formulário.
        $ok = (new AvaliacaoMidiaService())->remover($token, $arquivo, null, (int)$this->clienteId);

        if (!$ok) {
            $this->falha(422, 'arquivo_invalido', 'Arquivo inválido.');
        }

        $this->ok(['removido' => true]);
    }

    /**
     * POST /api/app/v1/avaliacoes/{id}/util
     * Alterna o voto. Funciona sem login — o escopo é a sessão do dispositivo.
     */
    public function util(string $id = '0'): void
    {
        $this->bootPublico();

        $avaliacaoId = (int)$id;
        if ($avaliacaoId <= 0) {
            $this->falha(422, 'avaliacao_invalida', 'Avaliação inválida.');
        }

        $ctx    = $this->contexto();
        $review = new Review();

        $votei = $review->toggleUtil(
            $avaliacaoId,
            $this->clienteId,
            $ctx->sessaoKey,
            (string)$this->ipReal()
        );

        $st = $this->db()->prepare("SELECT util_sim FROM avaliacoes WHERE id = ? LIMIT 1");
        $st->execute([$avaliacaoId]);

        $this->ok(['votei' => $votei, 'total' => (int)$st->fetchColumn()]);
    }

    /* ================================================================= */

    /**
     * Por que o cliente pode (ou não) avaliar este produto.
     *
     * A loja decide isso na hora do envio e devolve um erro; no app o botão
     * precisa saber ANTES, senão o cliente escreve o texto inteiro para levar
     * um 409 no fim.
     */
    private function podeAvaliar(int $produtoId): array
    {
        if (!$this->clienteId) {
            return ['pode' => false, 'motivo' => 'nao_logado'];
        }

        if ((new Review())->jaAvaliou($produtoId, (int)$this->clienteId)) {
            return ['pode' => false, 'motivo' => 'ja_avaliou'];
        }

        return [
            'pode'   => true,
            'motivo' => null,
            // Compra verificada publica na hora; sem ela vai para moderação.
            'verificada' => $this->pedidoAprovadoCom($produtoId) !== null,
        ];
    }

    /** Pedido aprovado deste cliente que contém o produto — o selo "compra verificada". */
    private function pedidoAprovadoCom(int $produtoId): ?int
    {
        try {
            $st = $this->db()->prepare(
                "SELECT ped.id
                 FROM pedidos ped
                 JOIN pedido_itens pi ON pi.pedido_id = ped.id
                 WHERE ped.cliente_id = :c AND pi.produto_id = :p
                   AND ped.status_pagamento = 'aprovado'
                 ORDER BY ped.criado_em DESC
                 LIMIT 1"
            );
            $st->execute([':c' => $this->clienteId, ':p' => $produtoId]);
            $id = $st->fetchColumn();
            return $id ? (int)$id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Token de mídia é hex de 32 chars gerado por AvaliacaoMidiaService. */
    private function tokenDeMidia($valor): string
    {
        $v = trim((string)$valor);
        return preg_match('/^[0-9a-f]{32}$/i', $v) ? strtolower($v) : '';
    }

    private function filtroValido(string $f): string
    {
        return in_array($f, ['todas', 'fotos', 'videos', '5', '4', '3', '2', '1'], true)
            ? $f
            : 'todas';
    }

    private function ordemValida(string $o): string
    {
        return in_array($o, ['recentes', 'uteis', 'maior', 'menor'], true)
            ? $o
            : 'recentes';
    }
}
