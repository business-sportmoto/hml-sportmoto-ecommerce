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

    /* =================================================================
       ESCRITAS
       ================================================================= */

    /**
     * POST /api/app/v1/clips/{id}/like
     *
     * Alterna. Funciona sem conta — a curtida anônima é chaveada pela sessão
     * do dispositivo, igual ao ClipController da loja. Exigir login aqui
     * mataria o gesto mais barato do feed.
     */
    public function curtir(string $id = '0'): void
    {
        $this->bootPublico();

        $clipId = (int)$id;
        if ($clipId <= 0) {
            $this->falha(422, 'clip_invalido', 'Clip inválido.');
        }

        // O clip precisa existir. Sem esta checagem, toggleLike() inseria uma
        // linha em clip_likes para qualquer id — dava para encher a tabela com
        // curtidas em clips que nunca existiram, e o contador respondia 200.
        if (!$this->clipExiste($clipId)) {
            $this->falha(404, 'nao_encontrado', 'Clip não encontrado.');
        }

        $modelo = new Clip();
        $ip     = (string)$this->ipReal();

        if (!$modelo->checarRateLimit($ip, 'like', 30)) {
            $this->falha(429, 'muitas_acoes', 'Muitas ações seguidas. Espere um instante.');
        }

        $sessao = session_id();
        $curtiu = $modelo->toggleLike($clipId, $this->clienteId, $sessao, $ip);

        $st = $this->db()->prepare("SELECT total_likes FROM clips WHERE id = ? LIMIT 1");
        $st->execute([$clipId]);

        $this->liberarSessao();
        $this->ok(['curtiu' => $curtiu, 'total' => (int)$st->fetchColumn()]);
    }

    /**
     * POST /api/app/v1/clips/{id}/visualizar
     *
     * INSERT IGNORE por (clip, sessão): reabrir o mesmo clip dez vezes não
     * infla a contagem.
     */
    public function visualizar(string $id = '0'): void
    {
        $this->bootPublico();

        $clipId = (int)$id;
        if ($clipId > 0) {
            try {
                (new Clip())->registrarView($clipId, session_id(), (string)$this->ipReal());
            } catch (\Throwable $e) {
                // Métrica não derruba a reprodução.
            }
        }

        $this->liberarSessao();
        $this->ok(['registrado' => true]);
    }

    /**
     * POST /api/app/v1/clips/{id}/compartilhar
     * Só contabiliza — o link é montado no app pela folha do sistema.
     */
    public function compartilhar(string $id = '0'): void
    {
        $this->bootPublico();
        $this->liberarSessao();

        $clipId = (int)$id;
        if ($clipId > 0) {
            try {
                (new Clip())->registrarShare($clipId);
            } catch (\Throwable $e) { /* métrica */ }
        }

        $this->ok(['registrado' => true]);
    }

    /**
     * GET /api/app/v1/clips/{id}/comentarios
     *
     * Só os aprovados, do mais antigo para o mais novo — é uma conversa, e
     * conversa se lê na ordem em que aconteceu.
     */
    public function comentarios(string $id = '0'): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $clipId = (int)$id;
        if ($clipId <= 0) {
            $this->falha(422, 'clip_invalido', 'Clip inválido.');
        }

        $pagina = $this->pagina(20, 50);

        try {
            $linhas = (new Clip())->getComentarios($clipId, $pagina['page']);

            $st = $this->db()->prepare(
                "SELECT COUNT(*) FROM clip_comentarios WHERE clip_id = ? AND status = 'aprovado'"
            );
            $st->execute([$clipId]);
            $total = (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'clip_comentarios', 'clip' => $clipId]);
            $this->falha(500, 'falha_comentarios', 'Não foi possível carregar os comentários.');
        }

        $this->okPaginado(
            'comentarios',
            ComentarioPresenter::colecao($linhas, $this->contexto()),
            $total,
            $pagina
        );
    }

    /**
     * POST /api/app/v1/clips/{id}/comentarios
     * Corpo: { texto, nome? }
     *
     * Comentário de quem está logado entra aprovado; de visitante, vai para
     * moderação. É a mesma regra de Clip::addComentario() — o app não decide
     * isso, só informa ao usuário o que aconteceu.
     */
    public function comentar(string $id = '0'): void
    {
        $this->bootPublico();

        $clipId = (int)$id;
        $corpo  = $this->exigirCampos(['texto']);
        $texto  = trim((string)$corpo['texto']);

        if ($clipId <= 0 || !$this->clipExiste($clipId)) {
            $this->falha(404, 'nao_encontrado', 'Clip não encontrado.');
        }
        if (mb_strlen($texto) < 2) {
            $this->falha(422, 'texto_curto', 'Escreva seu comentário.');
        }
        if (mb_strlen($texto) > 500) {
            $this->falha(422, 'texto_longo', 'O comentário passou de 500 caracteres.');
        }

        $nome = $this->nomeDoAutor($corpo['nome'] ?? null);

        $modelo = new Clip();
        $ip     = (string)$this->ipReal();

        if (!$modelo->checarRateLimit($ip, 'comentario', 5)) {
            $this->falha(429, 'muitos_comentarios', 'Muitos comentários seguidos. Espere um instante.');
        }

        try {
            $novo = $modelo->addComentario($clipId, $nome, $texto, $this->clienteId, $ip);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'clip_comentar', 'clip' => $clipId]);
            $this->falha(500, 'falha_comentar', 'Não foi possível publicar seu comentário.');
        }

        $ctx = $this->contexto();
        $this->liberarSessao();

        $this->ok([
            'aprovado'   => !empty($novo['aprovado']),
            'mensagem'   => !empty($novo['aprovado'])
                ? null
                : 'Comentário enviado. Aparece assim que passar pela moderação.',
            'comentario' => ComentarioPresenter::um($novo, $ctx),
        ], 201);
    }

    /** O clip existe e está publicado? */
    private function clipExiste(int $clipId): bool
    {
        if ($clipId <= 0) {
            return false;
        }

        try {
            $st = $this->db()->prepare(
                "SELECT 1 FROM clips WHERE id = ? AND ativo = 1 AND status = 'ativo' LIMIT 1"
            );
            $st->execute([$clipId]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * O nome que assina o comentário.
     *
     * Logado: sempre o da conta, nunca o que veio no corpo — senão dava para
     * assinar com o nome de outra pessoa.
     */
    private function nomeDoAutor($informado): string
    {
        if ($this->clienteId) {
            try {
                $st = $this->db()->prepare(
                    "SELECT u.nome FROM usuarios u
                     JOIN clientes c ON c.usuario_id = u.id
                     WHERE c.id = ? LIMIT 1"
                );
                $st->execute([$this->clienteId]);
                $nome = trim((string)$st->fetchColumn());
                if ($nome !== '') {
                    return $nome;
                }
            } catch (\Throwable $e) { /* cai no informado */ }
        }

        $nome = trim((string)$informado);
        return $nome !== '' ? mb_substr($nome, 0, 80) : 'Visitante';
    }
}
