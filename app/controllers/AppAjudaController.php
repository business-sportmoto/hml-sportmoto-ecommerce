<?php
// app/controllers/AppAjudaController.php
//
// A central de ajuda do app. Espelha HelpCenterController da loja, com uma
// diferença de forma: a web tem três telas (índice, busca e categoria) porque
// cada uma é um GET com recarga de página. O app recebe o FAQ INTEIRO numa
// requisição só e filtra localmente.
//
// Por quê: o FAQ é conteúdo curado — hoje são 5 categorias e 6 perguntas, e
// mesmo uma central madura fica na casa das dezenas. Buscar no servidor a cada
// tecla custaria uma ida por letra digitada, com debounce, estados de carga e
// uma tela que pisca. Buscando local, o resultado aparece no mesmo quadro, sem
// rede, e continua funcionando no elevador. HelpFaq::search() segue servindo a
// web, onde a recarga de página é o modelo.
//
// O limite de LIMITE_PERGUNTAS existe para essa escolha não virar um payload
// gigante em silêncio se alguém cadastrar mil perguntas: passando disso, o
// corte é explícito no meta e visível em teste.

class AppAjudaController extends AppApiController
{
    /**
     * Teto do payload. Bem acima do tamanho real de uma central de ajuda; é
     * rede de segurança, não paginação.
     */
    private const LIMITE_PERGUNTAS = 400;

    /**
     * GET /api/app/v1/ajuda
     *
     * Categorias + todas as perguntas ativas + canais de atendimento.
     */
    public function index(): void
    {
        // Ajuda é pública: alguém que não consegue entrar na conta é justamente
        // quem mais precisa abrir "Esqueci minha senha, o que faço?".
        $this->bootOpcional();
        $this->liberarSessao();

        $categorias = (new HelpFaqCategoria())->getAllAtivas();
        $agrupadas  = (new HelpFaq())->getAllAtivasAgrupadas();

        // getAllAtivasAgrupadas() devolve slug => perguntas[]. O app trabalha
        // com uma lista só (filtra por categoria_id), o que evita ter duas
        // fontes de verdade para "quantas perguntas esta categoria tem".
        $perguntas = [];
        foreach ($agrupadas as $doGrupo) {
            foreach ($doGrupo as $p) {
                $perguntas[] = $p;
            }
        }

        $total     = count($perguntas);
        $truncado  = $total > self::LIMITE_PERGUNTAS;
        if ($truncado) {
            $perguntas = array_slice($perguntas, 0, self::LIMITE_PERGUNTAS);
        }

        $this->ok([
            'categorias' => AjudaPresenter::categorias($categorias),
            'perguntas'  => AjudaPresenter::perguntas($perguntas),
            'contato'    => AjudaPresenter::contato(),
        ], 200, [
            'total'    => $total,
            'truncado' => $truncado,
        ]);
    }

    /**
     * POST /api/app/v1/ajuda/perguntas/{id}/visualizacao
     *
     * Conta que a resposta foi aberta. `help_perguntas.visualizacoes` existe
     * desde o começo e nunca foi incrementada por ninguém — a web tem o método
     * no model e não o chama. Sem isto não há como saber quais respostas
     * resolvem e quais só ocupam espaço.
     *
     * Melhor esforço: responde 200 mesmo quando a escrita falha. Um contador
     * não pode fazer a tela mostrar erro.
     */
    public function visualizar(string $id = '0'): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $perguntaId = (int)$id;

        if ($perguntaId > 0) {
            try {
                (new HelpFaq())->incrementVisualizacao($perguntaId);
            } catch (\Throwable $e) {
                AppLog::exception($e, ['acao' => 'ajuda_visualizar', 'pergunta' => $perguntaId]);
            }
        }

        // 200 e não 204: AppApiController::emitir() sempre escreve o envelope,
        // e um 204 com corpo é resposta malformada — parte das pilhas HTTP
        // descarta o corpo e o cliente recebe JSON vazio para interpretar.
        $this->ok(['registrado' => true]);
    }
}
