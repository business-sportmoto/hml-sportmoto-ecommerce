<?php
// app/controllers/AppGaragemController.php
// Garagem de motos e os selects em cascata montadora → modelo → ano.
//
// A moto ATIVA muda o catálogo inteiro (é ela que define o que é "compatível"
// em cada card e o que o filtro "serve na minha moto" devolve), então ativar
// uma moto invalida o cache de catálogo no app.
//
// VeiculoService é reusado inteiro — ele grava $_SESSION['meu_veiculo'], que
// funciona no app graças à ponte de sessão.

class AppGaragemController extends AppApiController
{
    /* =================================================================
       Cascata pública — não exige login
       ================================================================= */

    /**
     * GET /api/app/v1/motos/montadoras
     * ?com_produtos=1 → só montadoras que têm peça ativa na loja
     *
     * Os dois casos são legítimos e diferentes:
     *
     *   sem o filtro → cadastrar a moto na GARAGEM. O cliente tem a moto que
     *                  tem; esconder a montadora porque a loja ainda não vende
     *                  peça para ela impediria o cadastro.
     *   com o filtro → BUSCAR peça pela moto. Oferecer uma montadora que leva
     *                  a zero resultados é uma promessa quebrada.
     *
     * O EXISTS é o mesmo de views/partials/hero-busca-moto.php:4-14 — é dele
     * que este bloco veio, e divergir faria o app oferecer montadoras que o
     * site esconde.
     */
    public function montadoras(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $comProdutos = (bool)$this->query('com_produtos', false);

        $sql = "SELECT mm.id, mm.nome, mm.slug, mm.logo, mm.thumb
                FROM moto_montadoras mm
                WHERE mm.ativo = 1";

        if ($comProdutos) {
            $sql .= " AND EXISTS (
                          SELECT 1 FROM produto_compatibilidade pc
                          JOIN produtos p ON p.id = pc.produto_id
                          WHERE pc.montadora_id = mm.id AND p.ativo = 1
                      )";
        }

        $sql .= " ORDER BY mm.ordem ASC, mm.nome ASC";

        try {
            $rows = $this->db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'montadoras']);
            $rows = [];
        }

        $this->ok(['montadoras' => MotoPresenter::montadoras($rows, $this->contexto())]);
    }

    /**
     * GET /api/app/v1/motos/modelos?montadora_id=
     */
    public function modelos(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $montadoraId = (int)$this->query('montadora_id', 0);
        if (!$montadoraId) {
            $this->falha(422, 'dados_invalidos', 'Informe montadora_id.');
        }

        try {
            $st = $this->db()->prepare(
                "SELECT id, nome, slug, thumb, cilindrada, tipo
                 FROM moto_modelos
                 WHERE montadora_id = :m AND ativo = 1
                 ORDER BY nome ASC"
            );
            $st->execute([':m' => $montadoraId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = [];
        }

        $this->ok(['modelos' => MotoPresenter::modelos($rows, $this->contexto())]);
    }

    /**
     * GET /api/app/v1/motos/anos?modelo_id=
     *
     * Os anos saem da tabela de compatibilidade, não de um intervalo fixo: só
     * faz sentido oferecer anos para os quais a loja realmente tem peça.
     */
    public function anos(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $modeloId = (int)$this->query('modelo_id', 0);
        if (!$modeloId) {
            $this->falha(422, 'dados_invalidos', 'Informe modelo_id.');
        }

        $anos = [];
        try {
            $st = $this->db()->prepare(
                "SELECT MIN(ano_inicio) AS de, MAX(ano_fim) AS ate
                 FROM produto_compatibilidade
                 WHERE modelo_id = :m AND ano_inicio IS NOT NULL"
            );
            $st->execute([':m' => $modeloId]);
            $faixa = $st->fetch(PDO::FETCH_ASSOC);

            $de  = (int)($faixa['de'] ?? 0);
            $ate = (int)($faixa['ate'] ?? 0);

            // Sem compatibilidade cadastrada, oferecemos os últimos 30 anos —
            // melhor deixar o cliente registrar a moto dele do que travar o
            // cadastro por falta de dado do catálogo.
            if ($de <= 0) {
                $atual = (int)date('Y');
                $de = $atual - 30;
                $ate = $atual + 1;
            }
            if ($ate < $de) {
                $ate = $de;
            }

            for ($a = $ate; $a >= $de; $a--) {
                $anos[] = $a;
            }
        } catch (\Throwable $e) {
            $atual = (int)date('Y');
            for ($a = $atual + 1; $a >= $atual - 30; $a--) {
                $anos[] = $a;
            }
        }

        $this->ok(['anos' => $anos]);
    }

    /* =================================================================
       Garagem — exige login
       ================================================================= */

    /**
     * GET /api/app/v1/garagem
     */
    public function index(): void
    {
        $this->bootCliente();

        $veiculos = (new VeiculoService())->listarPorCliente((int)$this->clienteId);
        $ctx = $this->contexto();
        $this->liberarSessao();

        $this->ok([
            'motos'  => MotoPresenter::colecao($veiculos, $ctx),
            'ativa'  => $ctx->veiculoAtivo['id'] ?? null,
            'vazia'  => count($veiculos) === 0,
        ]);
    }

    /**
     * POST /api/app/v1/garagem
     * Corpo: { montadora_id, modelo_id?, ano?, apelido?, cor?, placa?, ativar? }
     */
    public function adicionar(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['montadora_id']);

        try {
            $veiculo = (new VeiculoService())->adicionar(
                (int)$this->clienteId,
                (int)$corpo['montadora_id'],
                !empty($corpo['modelo_id']) ? (int)$corpo['modelo_id'] : null,
                !empty($corpo['ano']) ? (int)$corpo['ano'] : null,
                (string)($corpo['apelido'] ?? ''),
                $corpo['cor'] ?? null,
                $corpo['placa'] ?? null,
                // A primeira moto vira a ativa por padrão: quem cadastra
                // acabou de dizer qual é a moto dele.
                !isset($corpo['ativar']) || (bool)$corpo['ativar']
            );
        } catch (\InvalidArgumentException $e) {
            $this->falha(422, 'dados_invalidos', $e->getMessage());
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'adicionar_moto']);
            $this->falha(500, 'falha_garagem', 'Não foi possível adicionar a moto.');
        }

        AppLog::info('Moto adicionada à garagem pelo app', [
            'montadora_id' => (int)$corpo['montadora_id'],
        ]);

        // O contexto precisa ser montado DEPOIS de adicionar: se a moto virou
        // a ativa, o veiculoAtivo mudou nesta mesma requisição.
        $ctx = $this->contexto();
        $this->liberarSessao();

        $this->ok(['moto' => MotoPresenter::uma($veiculo, $ctx)], 201);
    }

    /**
     * PATCH /api/app/v1/garagem/{id}
     * Corpo: { apelido?, cor?, placa?, observacoes? }
     */
    public function atualizar(string $id = '0'): void
    {
        $this->bootCliente();
        $corpo = $this->corpo();

        if (!$corpo) {
            $this->falha(422, 'dados_invalidos', 'Nada para atualizar.');
        }

        $ok = (new VeiculoService())->atualizar((int)$this->clienteId, (int)$id, $corpo);
        $this->liberarSessao();

        if (!$ok) {
            $this->falha(404, 'nao_encontrada', 'Moto não encontrada na sua garagem.');
        }

        $this->ok(['atualizada' => true]);
    }

    /**
     * POST /api/app/v1/garagem/{id}/ativar
     *
     * Devolve `invalidar_catalogo` para o app descartar o cache de catálogo:
     * a compatibilidade de TODO card muda com a moto ativa, e mostrar a lista
     * antiga faria o selo mentir.
     */
    public function ativar(string $id = '0'): void
    {
        $this->bootCliente();

        $ok = (new VeiculoService())->ativar((int)$this->clienteId, (int)$id);

        if (!$ok) {
            $this->liberarSessao();
            $this->falha(404, 'nao_encontrada', 'Moto não encontrada na sua garagem.');
        }

        $ctx = $this->contexto();
        $this->liberarSessao();

        $this->ok([
            'ativa'  => $ctx->veiculoAtivo,
            'invalidar_catalogo' => true,
        ]);
    }

    /* =================================================================
       FOTOS DA MOTO
       ================================================================= */

    /**
     * GET /api/app/v1/garagem/{id}/fotos
     */
    public function fotos(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $fotos = (new VeiculoFotoService())->listarPorVeiculo((int)$this->clienteId, (int)$id);

        $this->ok(['fotos' => MotoFotoPresenter::colecao($fotos, $this->contexto())]);
    }

    /**
     * POST /api/app/v1/garagem/{id}/fotos   (multipart: foto)
     *
     * Campo `foto` em multipart/form-data. O React Native manda FormData com
     * { uri, type, name }, que chega em $_FILES como qualquer upload.
     *
     * A foto entra SEMPRE como privada — VeiculoFotoService decide isso, e
     * está certo: publicar por padrão a moto de alguém seria uma escolha que
     * cabe ao dono, não ao app.
     */
    public function enviarFoto(string $id = '0'): void
    {
        $this->bootCliente();

        if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->liberarSessao();
            $this->falha(422, 'arquivo_ausente', $this->erroUpload($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE));
        }

        try {
            $foto = (new VeiculoFotoService())->upload(
                (int)$this->clienteId,
                (int)$id,
                $_FILES['foto'],
                ['legenda' => $this->campo('legenda')]
            );
        } catch (\RuntimeException $e) {
            $this->liberarSessao();
            $this->falha(422, 'upload_recusado', $e->getMessage());
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'upload_foto_moto', 'veiculo_id' => (int)$id]);
            $this->liberarSessao();
            $this->falha(500, 'falha_upload', 'Não foi possível enviar a foto.');
        }

        $ctx = $this->contexto();
        $this->liberarSessao();

        $this->ok(['foto' => MotoFotoPresenter::uma($foto, $ctx)], 201);
    }

    /**
     * POST /api/app/v1/garagem/fotos/{id}/capa
     */
    public function definirCapa(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $ok = (new VeiculoFotoService())->definirCapa((int)$this->clienteId, (int)$id);

        if (!$ok) {
            $this->falha(404, 'nao_encontrada', 'Foto não encontrada.');
        }

        $this->ok(['capa_definida' => true]);
    }

    /**
     * DELETE /api/app/v1/garagem/fotos/{id}
     */
    public function removerFoto(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $ok = (new VeiculoFotoService())->remover((int)$this->clienteId, (int)$id);

        if (!$ok) {
            $this->falha(404, 'nao_encontrada', 'Foto não encontrada.');
        }

        $this->ok(['removida' => true]);
    }

    /** Traduz o código de erro do PHP para algo que o usuário entenda. */
    private function erroUpload(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'A foto é maior que o limite permitido.',
            UPLOAD_ERR_PARTIAL   => 'O envio foi interrompido. Tente de novo.',
            UPLOAD_ERR_NO_FILE   => 'Nenhuma foto foi enviada.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Falha no servidor ao gravar a foto.',
            default              => 'Não foi possível receber a foto.',
        };
    }

    /**
     * DELETE /api/app/v1/garagem/{id}
     */
    public function remover(string $id = '0'): void
    {
        $this->bootCliente();

        $ok = (new VeiculoService())->remover((int)$this->clienteId, (int)$id);

        if (!$ok) {
            $this->liberarSessao();
            $this->falha(404, 'nao_encontrada', 'Moto não encontrada na sua garagem.');
        }

        // remover() já promove outra moto a ativa quando a removida era a
        // atual — por isso o contexto é lido depois.
        $ctx = $this->contexto();
        $this->liberarSessao();

        $this->ok([
            'removida' => true,
            'ativa'    => $ctx->veiculoAtivo,
            'invalidar_catalogo' => true,
        ]);
    }
}
