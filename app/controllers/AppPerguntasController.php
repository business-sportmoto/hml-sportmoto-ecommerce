<?php
// app/controllers/AppPerguntasController.php
//
// Perguntas e respostas na página do produto.
//
// Espelha PerguntaController da loja: mesma tabela, mesmos limites diários,
// mesma tentativa de resposta pela IA antes de cair na fila do atendimento.
//
// Duas diferenças, ambas deliberadas:
//
//  1. Pergunta repetida não vai para a IA. produto_perguntas.pergunta_hash
//     existe desde o começo e nunca foi consultada — na web, digitar de novo
//     "serve na minha CB 500?" gerava outra linha e outra chamada paga. No app,
//     onde repetir é um toque, isso pesa mais.
//
//  2. O voto "útil" chega em lote, não uma query por pergunta.

class AppPerguntasController extends AppApiController
{
    private const LIMITE_DIA_CLIENTE = 20;
    private const LIMITE_DIA_IP      = 50;

    private const MIN_PERGUNTA = 10;
    private const MAX_PERGUNTA = 500;

    /**
     * GET /api/app/v1/produtos/{id}/perguntas
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
        $modelo = new Pergunta();
        $ctx    = $this->contexto();

        $rows  = $modelo->listarPorProduto($produtoId, $this->emailDoCliente(), $pagina['page'], $pagina['limit']);
        $total = $modelo->contarPorProduto($produtoId);

        $votos = $modelo->votosEmLote(
            array_column($rows, 'id'),
            $this->clienteId,
            $ctx->sessaoKey
        );

        $this->okPaginado(
            'perguntas',
            PerguntaPresenter::colecao($rows, $ctx, $votos),
            $total,
            $pagina,
            ['restantes_hoje' => $this->restantesHoje()]
        );
    }

    /**
     * POST /api/app/v1/produtos/{id}/perguntas
     * Corpo: { pergunta, nome?, email? }
     *
     * Nome e e-mail só são exigidos de quem não está logado — é por e-mail que
     * a resposta do atendente chega quando a IA não sabe responder.
     */
    public function criar(string $id = '0'): void
    {
        $this->bootPublico();

        $produtoId = (int)$id;
        $corpo     = $this->exigirCampos(['pergunta']);
        $pergunta  = trim((string)$corpo['pergunta']);

        $tamanho = mb_strlen($pergunta);
        if ($tamanho < self::MIN_PERGUNTA || $tamanho > self::MAX_PERGUNTA) {
            $this->falha(422, 'pergunta_invalida',
                'A pergunta deve ter entre ' . self::MIN_PERGUNTA . ' e ' . self::MAX_PERGUNTA . ' caracteres.');
        }

        if (!(new Product())->find($produtoId)) {
            $this->falha(404, 'nao_encontrado', 'Produto não encontrado.');
        }

        $autor = $this->autor($corpo);

        // Libera a sessão só depois de ler o cliente: daqui para baixo é tudo
        // banco e HTTP externo, e a chamada à IA pode levar segundos — segurar
        // o lock durante ela enfileiraria os outros requests do mesmo device.
        $this->liberarSessao();

        $modelo = new Pergunta();

        if ($this->restantesHoje() <= 0) {
            $this->falha(429, 'limite_diario',
                'Você atingiu o limite de perguntas por hoje. Tente novamente amanhã.');
        }

        // Alguém já perguntou exatamente isto e já foi respondido: devolve a
        // resposta na hora, sem gravar nem gastar chamada de IA.
        $repetida = $modelo->jaRespondida($produtoId, $pergunta);
        if ($repetida) {
            $this->ok([
                'pergunta' => PerguntaPresenter::uma($repetida, $this->contexto()),
                'fonte'    => 'existente',
                'mensagem' => 'Esta pergunta já foi respondida.',
            ]);
        }

        try {
            $perguntaId = $modelo->criar([
                'produto_id'     => $produtoId,
                'cliente_id'     => $this->clienteId,
                'autor_nome'     => $autor['nome'],
                'autor_email'    => $autor['email'],
                'autor_telefone' => $autor['telefone'],
                'pergunta'       => $pergunta,
                'ip'             => $this->ipReal(),
                'user_agent'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'app'), 0, 255),
            ]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'pergunta_criar', 'produto' => $produtoId]);
            $this->falha(500, 'falha_salvar', 'Não foi possível registrar sua pergunta.');
        }

        $modelo->registrarRateLimit(
            $this->clienteId ? (string)$this->clienteId : (string)$this->ipReal(),
            $this->clienteId ? 'cliente' : 'ip'
        );

        $resposta = $this->tentarIA($modelo, $perguntaId, $produtoId, $pergunta);

        $this->ok([
            'id'       => $perguntaId,
            'fonte'    => $resposta['fonte'],
            'resposta' => $resposta['texto'],
            'mensagem' => $resposta['mensagem'],
        ], 201);
    }

    /**
     * POST /api/app/v1/perguntas/{id}/util
     */
    public function util(string $id = '0'): void
    {
        $this->bootPublico();

        $perguntaId = (int)$id;
        if ($perguntaId <= 0) {
            $this->falha(422, 'pergunta_invalida', 'Pergunta inválida.');
        }

        $ctx = $this->contexto();

        $r = (new Pergunta())->toggleUtil(
            $perguntaId,
            $this->clienteId,
            $ctx->sessaoKey,
            (string)$this->ipReal()
        );

        $this->ok(['votei' => (bool)$r['votou'], 'total' => (int)$r['util_count']]);
    }

    /* ================================================================= */

    /**
     * Tenta responder pela IA; se ela não souber, manda para o atendimento.
     *
     * @return array{fonte:string,texto:?string,mensagem:string}
     */
    private function tentarIA(Pergunta $modelo, int $perguntaId, int $produtoId, string $pergunta): array
    {
        try {
            $contexto = GeminiQAService::montarContexto($produtoId);
            // O meta não entra no prompt — serve para auditar a geração na
            // Central (quem perguntou, de onde, sobre qual produto).
            $r        = (new GeminiQAService())->responder($contexto, $pergunta, [
                'origem'     => 'app',
                'produto_id' => $produtoId,
                'cliente_id' => (int)$this->clienteId,
                'ip'         => (string)$this->ipReal(),
            ]);

            if (!empty($r['ok']) && ($r['fonte'] ?? '') === 'ia' && !empty($r['resposta'])) {
                $modelo->salvarRespostaIA($perguntaId, (string)$r['resposta'], $r['geracao_id'] ?? null);

                return [
                    'fonte'    => 'ia',
                    'texto'    => (string)$r['resposta'],
                    'mensagem' => 'Resposta gerada.',
                ];
            }
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'pergunta_ia', 'pergunta' => $perguntaId]);
        }

        $modelo->marcarParaAdmin($perguntaId);

        return [
            'fonte'    => 'admin',
            'texto'    => null,
            'mensagem' => 'Sua pergunta foi enviada a um especialista. A resposta chega por e-mail em até 24h.',
        ];
    }

    /**
     * Identidade de quem pergunta. Logado: sempre os dados da conta — nunca o
     * que veio no corpo, ou dava para assinar a pergunta com o nome de outro.
     *
     * @return array{nome:string,email:string,telefone:?string}
     */
    private function autor(array $corpo): array
    {
        if ($this->clienteId) {
            try {
                $st = $this->db()->prepare(
                    "SELECT u.nome, u.email, c.telefone
                     FROM clientes c
                     JOIN usuarios u ON u.id = c.usuario_id
                     WHERE c.id = ? LIMIT 1"
                );
                $st->execute([$this->clienteId]);
                $c = $st->fetch();

                if ($c) {
                    return [
                        'nome'     => (string)$c['nome'],
                        'email'    => mb_strtolower((string)$c['email']),
                        'telefone' => $c['telefone'] ?: null,
                    ];
                }
            } catch (\Throwable $e) {
                AppLog::exception($e, ['acao' => 'pergunta_autor']);
            }
        }

        $nome  = trim((string)($corpo['nome']  ?? ''));
        $email = mb_strtolower(trim((string)($corpo['email'] ?? '')));

        if (mb_strlen($nome) < 2) {
            $this->falha(422, 'nome_invalido', 'Informe seu nome.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->falha(422, 'email_invalido', 'Informe um e-mail válido para receber a resposta.');
        }

        return [
            'nome'     => $nome,
            'email'    => $email,
            'telefone' => trim((string)($corpo['telefone'] ?? '')) ?: null,
        ];
    }

    private function restantesHoje(): int
    {
        $modelo = new Pergunta();

        if ($this->clienteId) {
            $usado = $modelo->contarPerguntasDia((string)$this->clienteId, 'cliente');
            return max(0, self::LIMITE_DIA_CLIENTE - $usado);
        }

        $usado = $modelo->contarPerguntasDia((string)$this->ipReal(), 'ip');
        return max(0, self::LIMITE_DIA_IP - $usado);
    }

    /** E-mail do cliente logado — é por ele que o model marca "sua pergunta". */
    private function emailDoCliente(): ?string
    {
        if (!$this->clienteId) {
            return null;
        }

        try {
            $st = $this->db()->prepare(
                "SELECT u.email FROM usuarios u
                 JOIN clientes c ON c.usuario_id = u.id
                 WHERE c.id = ? LIMIT 1"
            );
            $st->execute([$this->clienteId]);
            return $st->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
