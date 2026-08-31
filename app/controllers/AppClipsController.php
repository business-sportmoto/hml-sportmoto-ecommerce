<?php
// app/controllers/AppClipsController.php
// Feed de clips — leitura. As escritas (like, comentário, share) entram na Fase 4.

class AppClipsController extends AppApiController
{
    /**
     * GET /api/app/v1/clips/feed?page=&destaque=
     *
     * Paginado para o scroll infinito vertical. Cada item já vem com a URL HLS,
     * o poster e os produtos vinculados — o app não deve precisar de uma
     * segunda chamada para montar o card que flutua sobre o vídeo.
     */
    public function feed(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $pagina   = $this->pagina(10, 20);
        $destaque = (bool)$this->query('destaque', false);
        // `inicial` abre o feed num clip específico — é o que liga "tocar neste
        // vídeo" ao vídeo certo, em vez de mandar a pessoa para o começo.
        $inicial  = (int)$this->query('inicial', 0);

        $modelo = new Clip();
        $clips  = $modelo->getFeed($pagina['page'], $pagina['limit'], $destaque, $inicial);
        $total  = $modelo->countFeed($destaque);

        $clips = $this->marcarCurtidos($clips);

        $this->okPaginado(
            'clips',
            ClipPresenter::colecao($clips, $this->contexto()),
            $total,
            $pagina
        );
    }

    /**
     * GET /api/app/v1/clips/{id}
     * Alvo do deep link de um clip compartilhado.
     */
    public function detalhe(string $id = '0'): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $clip = (new Clip())->getComProdutos((int)$id);

        if (!$clip) {
            $this->falha(404, 'nao_encontrado', 'Clip não encontrado.');
        }

        [$clip] = $this->marcarCurtidos([$clip]);

        $this->ok(['clip' => ClipPresenter::um($clip, $this->contexto())]);
    }

    /**
     * Resolve "eu já curti?" para o lote inteiro numa query.
     * Curtida anônima é chaveada por sessão, igual ao ClipController da web.
     */
    private function marcarCurtidos(array $clips): array
    {
        if (!$clips) {
            return $clips;
        }

        $ids    = array_map(static fn(array $c) => (int)$c['id'], $clips);
        $in     = implode(',', array_fill(0, count($ids), '?'));
        $params = $ids;

        if ($this->clienteId) {
            $where    = "clip_id IN ({$in}) AND cliente_id = ?";
            $params[] = $this->clienteId;
        } else {
            $sessao = session_id();
            if ($sessao === '') {
                return $clips;
            }
            $where    = "clip_id IN ({$in}) AND session_key = ?";
            $params[] = $sessao;
        }

        try {
            $st = $this->db()->prepare("SELECT clip_id FROM clip_likes WHERE {$where}");
            $st->execute($params);
            $curtidos = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        } catch (\Throwable $e) {
            return $clips;
        }

        foreach ($clips as &$c) {
            $c['_curtiu'] = isset($curtidos[(int)$c['id']]);
        }
        unset($c);

        return $clips;
    }
}
