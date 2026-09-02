<?php
/**
 * IAConfigController — Central de Marketing IA · Configurações (Fase 0).
 *
 * Rotas sugeridas (ajuste ao formato do seu router):
 *   GET  /admin/ia/config                     -> index
 *   GET  /admin/ia/config/provedores/linhas   -> provedoresLinhas
 *   GET  /admin/ia/config/provedor/form       -> provedorForm        (?id=)
 *   POST /admin/ia/config/provedor/salvar     -> provedorSalvar
 *   POST /admin/ia/config/provedor/alternar   -> provedorAlternar
 *   POST /admin/ia/config/provedor/testar     -> provedorTestar
 *   GET  /admin/ia/config/modelos/linhas      -> modelosLinhas
 *   GET  /admin/ia/config/modelo/form         -> modeloForm          (?id= | vazio = novo)
 *   POST /admin/ia/config/modelo/salvar       -> modeloSalvar
 *   POST /admin/ia/config/modelo/alternar     -> modeloAlternar
 *   POST /admin/ia/config/modelo/excluir      -> modeloExcluir
 *   GET  /admin/ia/config/limites/linhas      -> limitesLinhas
 *   GET  /admin/ia/config/limite/form         -> limiteForm          (?id= | vazio = novo)
 *   POST /admin/ia/config/limite/salvar       -> limiteSalvar
 *   POST /admin/ia/config/limite/excluir      -> limiteExcluir
 *
 * Permissão: marketing_ia_config (cascade do AuthHelper cobre admins plenos).
 */
class IAConfigController extends Controller
{
    /* ------------------------------------------------------------------ */
    /* Página principal                                                    */
    /* ------------------------------------------------------------------ */

    public function index()
    {
        AuthHelper::requirePermission('marketing_ia_config');

        $dados = [
            'provedores'   => (new IAProvedor())->listar(),
            'modelos'      => (new IAModelo())->listar(),
            'limites'      => (new IALimite())->listar(),
            'capacidades'  => IAModelo::capacidades(),
            'kpis'         => $this->montarKpis(),
            'csrf'         => $this->tokenCsrf(),
        ];

        $this->render('ia/config/index', $dados, 'admin');
    }

    /* ------------------------------------------------------------------ */
    /* Provedores                                                          */
    /* ------------------------------------------------------------------ */

    public function provedoresLinhas()
    {
        AuthHelper::requirePermission('marketing_ia_config');

        $html = $this->partial('_provedores_rows', ['provedores' => (new IAProvedor())->listar()]);
        $this->json(['ok' => true, 'html' => $html, 'kpis' => $this->montarKpis()]);
    }

    public function provedorForm()
    {
        AuthHelper::requirePermission('marketing_ia_config');

        $id       = (int) ($_GET['id'] ?? 0);
        $provedor = ($id > 0) ? (new IAProvedor())->buscar($id) : null;

        if ($provedor === null) {
            $this->json(['ok' => false, 'msg' => 'Provedor não encontrado.']);
            return;
        }

        $html = $this->partial('provedor_form', ['prov' => $provedor, 'csrf' => $this->tokenCsrf()]);
        $this->json(['ok' => true, 'titulo' => 'Editar provedor', 'html' => $html]);
    }

    public function provedorSalvar()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $modelo   = new IAProvedor();
        $id       = (int) ($_POST['id'] ?? 0);
        $provedor = ($id > 0) ? $modelo->buscar($id) : null;

        if ($provedor === null) {
            $this->json(['ok' => false, 'msg' => 'Provedor não encontrado.']);
            return;
        }

        // ----- validação -----
        $nome    = trim((string) ($_POST['nome'] ?? ''));
        $baseUrl = trim((string) ($_POST['base_url'] ?? ''));
        $timeout = (int) ($_POST['timeout_padrao_s'] ?? 0);
        $limite  = trim((string) ($_POST['limite_diario_usd'] ?? ''));
        $ativo   = isset($_POST['ativo']) ? 1 : 0;
        $apiKey  = trim((string) ($_POST['api_key'] ?? ''));

        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 100) {
            $this->json(['ok' => false, 'msg' => 'Informe um nome entre 2 e 100 caracteres.']);
            return;
        }
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || stripos($baseUrl, 'https://') !== 0) {
            $this->json(['ok' => false, 'msg' => 'A base URL deve ser válida e começar com https://.']);
            return;
        }
        if ($timeout < 5 || $timeout > 900) {
            $this->json(['ok' => false, 'msg' => 'Timeout deve estar entre 5 e 900 segundos.']);
            return;
        }

        $limiteUsd = null;
        if ($limite !== '') {
            $limiteUsd = (float) str_replace(',', '.', $limite);
            if ($limiteUsd < 0 || $limiteUsd > 999999) {
                $this->json(['ok' => false, 'msg' => 'Limite diário inválido.']);
                return;
            }
        }

        // Não permitir ativar sem chave configurada (nem a atual, nem uma nova no mesmo POST)
        $teraChave = ((int) $provedor['tem_chave'] === 1) || ($apiKey !== '');
        if ($ativo === 1 && !$teraChave) {
            $this->json(['ok' => false, 'msg' => 'Configure a chave de API antes de ativar o provedor.']);
            return;
        }

        // ----- persistência -----
        $dados = [
            'nome'              => $nome,
            'base_url'          => rtrim($baseUrl, '/'),
            'timeout_padrao_s'  => $timeout,
            'limite_diario_usd' => $limiteUsd,
            'ativo'             => $ativo,
        ];

        if (!$modelo->atualizar($id, $dados)) {
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar o provedor.']);
            return;
        }

        if ($apiKey !== '') {
            if (!$modelo->definirChave($id, $apiKey)) {
                $this->json(['ok' => false, 'msg' => 'Dados salvos, mas houve erro ao gravar a chave de API.']);
                return;
            }
            // Auditoria da troca de chave — NUNCA logar o valor, apenas last4.
            LogService::audit('ia_provedor_chave_alterada', [
                'provedor_id' => $id,
                'codigo'      => $provedor['codigo'],
                'last4'       => IACriptoService::last4($apiKey),
            ]);
        }

        LogService::audit('ia_provedor_atualizado', [
            'provedor_id' => $id,
            'codigo'      => $provedor['codigo'],
            'campos'      => array_keys($dados),
        ]);

        $this->json(['ok' => true, 'msg' => 'Provedor salvo.']);
    }

    public function provedorAlternar()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $modelo   = new IAProvedor();
        $id       = (int) ($_POST['id'] ?? 0);
        $provedor = ($id > 0) ? $modelo->buscar($id) : null;

        if ($provedor === null) {
            $this->json(['ok' => false, 'msg' => 'Provedor não encontrado.']);
            return;
        }

        $novo = ((int) $provedor['ativo'] === 1) ? 0 : 1;

        if ($novo === 1 && (int) $provedor['tem_chave'] !== 1) {
            $this->json(['ok' => false, 'msg' => 'Configure a chave de API antes de ativar o provedor.']);
            return;
        }

        if (!$modelo->atualizar($id, ['ativo' => $novo])) {
            $this->json(['ok' => false, 'msg' => 'Erro ao alterar o status.']);
            return;
        }

        LogService::audit('ia_provedor_status', [
            'provedor_id' => $id,
            'codigo'      => $provedor['codigo'],
            'ativo'       => $novo,
        ]);

        $this->json(['ok' => true, 'ativo' => $novo, 'msg' => $novo ? 'Provedor ativado.' : 'Provedor desativado.']);
    }

    /** POST /admin/ia/config/provedor/testar — valida chave e conectividade (Fase 1). */
    public function provedorTestar()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);

        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->prepare(
                'SELECT id, codigo, nome, base_url, config_extra, api_key_enc
                   FROM ia_provedores WHERE id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $provedor = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            LogService::error('ia_provedor_testar_erro', ['erro' => $e->getMessage()]);
            $provedor = false;
        }

        if (!$provedor) {
            $this->json(['ok' => false, 'msg' => 'Provedor não encontrado.']);
            return;
        }
        if ($provedor['api_key_enc'] === null) {
            $this->json(['ok' => false, 'msg' => 'Configure a chave de API antes de testar.']);
            return;
        }

        $r = (new IAOrchestrator())->testarProvedor($provedor);

        LogService::info('ia_provedor_teste', [
            'provedor_id' => $id,
            'codigo'      => $provedor['codigo'],
            'ok'          => $r->ok,
            'erro_codigo' => $r->erroCodigo,
            'tempo_ms'    => $r->tempoMs,
        ]);

        $this->json([
            'ok'  => $r->ok,
            'msg' => $r->ok
                ? (string) ($r->texto ?? 'Conexão OK.')
                : 'Falha no teste: [' . $r->erroCodigo . '] ' . $r->erro,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Modelos                                                             */
    /* ------------------------------------------------------------------ */

    public function modelosLinhas()
    {
        AuthHelper::requirePermission('marketing_ia_config');

        $html = $this->partial('_modelos_rows', [
            'modelos'     => (new IAModelo())->listar(),
            'capacidades' => IAModelo::capacidades(),
        ]);
        $this->json(['ok' => true, 'html' => $html, 'kpis' => $this->montarKpis()]);
    }

    public function modeloForm()
    {
        AuthHelper::requirePermission('marketing_ia_config');

        $id  = (int) ($_GET['id'] ?? 0);
        $mod = null;

        if ($id > 0) {
            $mod = (new IAModelo())->buscar($id);
            if ($mod === null) {
                $this->json(['ok' => false, 'msg' => 'Modelo não encontrado.']);
                return;
            }
        }

        $html = $this->partial('modelo_form', [
            'mod'         => $mod,
            'provedores'  => (new IAProvedor())->listar(),
            'capacidades' => IAModelo::capacidades(),
            'csrf'        => $this->tokenCsrf(),
        ]);
        $this->json(['ok' => true, 'titulo' => $mod ? 'Editar modelo' : 'Novo modelo', 'html' => $html]);
    }

    public function modeloSalvar()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $modelo = new IAModelo();
        $id     = (int) ($_POST['id'] ?? 0);

        // ----- validação -----
        $provedorId = (int) ($_POST['provedor_id'] ?? 0);
        $capacidade = (string) ($_POST['capacidade'] ?? '');
        $codigo     = trim((string) ($_POST['codigo_modelo'] ?? ''));
        $nome       = trim((string) ($_POST['nome'] ?? ''));
        $prioridade = (int) ($_POST['prioridade'] ?? 100);
        $timeout    = (int) ($_POST['timeout_s'] ?? 120);
        $ativo      = isset($_POST['ativo']) ? 1 : 0;

        if ((new IAProvedor())->buscar($provedorId) === null) {
            $this->json(['ok' => false, 'msg' => 'Selecione um provedor válido.']);
            return;
        }
        if (!array_key_exists($capacidade, IAModelo::capacidades())) {
            $this->json(['ok' => false, 'msg' => 'Capacidade inválida.']);
            return;
        }
        if ($codigo === '' || mb_strlen($codigo) > 150) {
            $this->json(['ok' => false, 'msg' => 'Informe o código do modelo (até 150 caracteres).']);
            return;
        }
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
            $this->json(['ok' => false, 'msg' => 'Informe um nome entre 2 e 150 caracteres.']);
            return;
        }
        if ($prioridade < 1 || $prioridade > 999) {
            $this->json(['ok' => false, 'msg' => 'Prioridade deve estar entre 1 e 999.']);
            return;
        }
        if ($timeout < 10 || $timeout > 900) {
            $this->json(['ok' => false, 'msg' => 'Timeout deve estar entre 10 e 900 segundos.']);
            return;
        }

        $custoConfig = $this->validarJsonCampo('custo_config');
        if ($custoConfig === false) {
            return; // json de erro já enviado
        }
        $paramsPadrao = $this->validarJsonCampo('params_padrao');
        if ($paramsPadrao === false) {
            return;
        }

        $dados = [
            'provedor_id'   => $provedorId,
            'capacidade'    => $capacidade,
            'codigo_modelo' => $codigo,
            'nome'          => $nome,
            'prioridade'    => $prioridade,
            'timeout_s'     => $timeout,
            'ativo'         => $ativo,
            'custo_config'  => $custoConfig,
            'params_padrao' => $paramsPadrao,
        ];

        // ----- persistência -----
        if ($id > 0) {
            if ($modelo->buscar($id) === null) {
                $this->json(['ok' => false, 'msg' => 'Modelo não encontrado.']);
                return;
            }
            $okOp = $modelo->atualizar($id, $dados);
            $acao = 'ia_modelo_atualizado';
        } else {
            $id   = $modelo->criar($dados);
            $okOp = ($id > 0);
            $acao = 'ia_modelo_criado';
        }

        if (!$okOp) {
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar (código já cadastrado para este provedor/capacidade?).']);
            return;
        }

        LogService::audit($acao, [
            'modelo_id'     => $id,
            'codigo_modelo' => $codigo,
            'capacidade'    => $capacidade,
            'prioridade'    => $prioridade,
            'ativo'         => $ativo,
        ]);

        $this->json(['ok' => true, 'msg' => 'Modelo salvo.']);
    }

    public function modeloAlternar()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $modelo = new IAModelo();
        $id     = (int) ($_POST['id'] ?? 0);
        $atual  = ($id > 0) ? $modelo->buscar($id) : null;

        if ($atual === null) {
            $this->json(['ok' => false, 'msg' => 'Modelo não encontrado.']);
            return;
        }

        $novo = ((int) $atual['ativo'] === 1) ? 0 : 1;

        if (!$modelo->atualizar($id, ['ativo' => $novo])) {
            $this->json(['ok' => false, 'msg' => 'Erro ao alterar o status.']);
            return;
        }

        LogService::audit('ia_modelo_status', [
            'modelo_id'     => $id,
            'codigo_modelo' => $atual['codigo_modelo'],
            'ativo'         => $novo,
        ]);

        $this->json(['ok' => true, 'ativo' => $novo, 'msg' => $novo ? 'Modelo ativado.' : 'Modelo desativado.']);
    }

    public function modeloExcluir()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $id        = (int) ($_POST['id'] ?? 0);
        $resultado = (new IAModelo())->excluir($id);

        if ($resultado['ok']) {
            LogService::audit('ia_modelo_excluido', ['modelo_id' => $id]);
        }

        $this->json($resultado);
    }

    /* ------------------------------------------------------------------ */
    /* Limites                                                             */
    /* ------------------------------------------------------------------ */

    public function limitesLinhas()
    {
        AuthHelper::requirePermission('marketing_ia_config');

        $html = $this->partial('_limites_rows', ['limites' => (new IALimite())->listar()]);
        $this->json(['ok' => true, 'html' => $html, 'kpis' => $this->montarKpis()]);
    }

    public function limiteForm()
    {
        AuthHelper::requirePermission('marketing_ia_config');

        $id  = (int) ($_GET['id'] ?? 0);
        $lim = null;

        if ($id > 0) {
            $lim = (new IALimite())->buscar($id);
            if ($lim === null) {
                $this->json(['ok' => false, 'msg' => 'Limite não encontrado.']);
                return;
            }
        }

        $html = $this->partial('limite_form', ['lim' => $lim, 'csrf' => $this->tokenCsrf()]);
        $this->json(['ok' => true, 'titulo' => $lim ? 'Editar limite' : 'Novo limite', 'html' => $html]);
    }

    public function limiteSalvar()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $escopo     = (string) ($_POST['escopo'] ?? '');
        $referencia = (int) ($_POST['referencia_id'] ?? 0);
        $alerta     = (int) ($_POST['alerta_percentual'] ?? 70);
        $ativo      = isset($_POST['ativo']) ? 1 : 0;

        if (!in_array($escopo, ['global', 'usuario'], true)) {
            $this->json(['ok' => false, 'msg' => 'Escopo inválido.']);
            return;
        }
        if ($escopo === 'usuario' && $referencia <= 0) {
            $this->json(['ok' => false, 'msg' => 'Informe o ID do usuário para escopo por usuário.']);
            return;
        }
        if ($escopo === 'global') {
            $referencia = 0;
        }
        if ($alerta < 1 || $alerta > 100) {
            $this->json(['ok' => false, 'msg' => 'Percentual de alerta deve estar entre 1 e 100.']);
            return;
        }

        $diario = $this->lerDecimalOpcional('limite_diario_usd');
        $mensal = $this->lerDecimalOpcional('limite_mensal_usd');
        if ($diario === false || $mensal === false) {
            $this->json(['ok' => false, 'msg' => 'Valores de limite inválidos.']);
            return;
        }

        $minuto = trim((string) ($_POST['limite_geracoes_minuto'] ?? ''));
        $minuto = ($minuto === '') ? null : (int) $minuto;
        if ($minuto !== null && ($minuto < 1 || $minuto > 600)) {
            $this->json(['ok' => false, 'msg' => 'Gerações por minuto deve estar entre 1 e 600.']);
            return;
        }

        $okOp = (new IALimite())->salvar([
            'escopo'                 => $escopo,
            'referencia_id'          => $referencia,
            'limite_diario_usd'      => $diario,
            'limite_mensal_usd'      => $mensal,
            'limite_geracoes_minuto' => $minuto,
            'alerta_percentual'      => $alerta,
            'ativo'                  => $ativo,
        ]);

        if (!$okOp) {
            $this->json(['ok' => false, 'msg' => 'Erro ao salvar o limite.']);
            return;
        }

        LogService::audit('ia_limite_salvo', [
            'escopo'        => $escopo,
            'referencia_id' => $referencia,
            'diario_usd'    => $diario,
            'mensal_usd'    => $mensal,
        ]);

        $this->json(['ok' => true, 'msg' => 'Limite salvo.']);
    }

    public function limiteExcluir()
    {
        AuthHelper::requirePermission('marketing_ia_config');
        if (!$this->exigirPost()) {
            return;
        }
        $this->verifyCsrf();

        $id        = (int) ($_POST['id'] ?? 0);
        $resultado = (new IALimite())->excluir($id);

        if ($resultado['ok']) {
            LogService::audit('ia_limite_excluido', ['limite_id' => $id]);
        }

        $this->json($resultado);
    }

    /* ------------------------------------------------------------------ */
    /* Auxiliares                                                          */
    /* ------------------------------------------------------------------ */

    /** KPIs do topo da tela (também retornados junto das linhas para update inline). */
    private function montarKpis(): array
    {
        $provedores = (new IAProvedor())->listar();
        $modelos    = (new IAModelo())->listar();
        $global     = (new IALimite())->globalVigente();

        $provAtivos = count(array_filter($provedores, fn($p) => (int) $p['ativo'] === 1));
        $modAtivos  = count(array_filter($modelos, fn($m) => (int) $m['ativo'] === 1));

        return [
            'provedores_ativos' => $provAtivos,
            'provedores_total'  => count($provedores),
            'modelos_ativos'    => $modAtivos,
            'modelos_total'     => count($modelos),
            'gasto_hoje_usd'    => (new IACustoDiario())->gastoHoje(),
            'limite_diario_usd' => $global['limite_diario_usd'] ?? null,
        ];
    }

    /** Renderiza um partial de app/views/ia/config/ e devolve o HTML como string. */
    private function partial(string $arquivo, array $dados = []): string
    {
        $arquivo = basename($arquivo); // sem traversal

        // Controller pode viver em admin/controllers/ (árvore do admin) ou
        // app/controllers/ — tenta as views ao lado e, depois, em app/views.
        $candidatos = [
            __DIR__ . '/../views/ia/config/' . $arquivo . '.php',
            dirname(__DIR__, 2) . '/app/views/ia/config/' . $arquivo . '.php',
        ];

        $caminho = null;
        foreach ($candidatos as $c) {
            if (is_file($c)) { $caminho = $c; break; }
        }

        if ($caminho === null) {
            LogService::error('ia_partial_inexistente', ['arquivo' => $arquivo]);
            return '';
        }

        extract($dados, EXTR_SKIP);
        ob_start();
        include $caminho;
        return (string) ob_get_clean();
    }

    /** Garante método POST em endpoints mutáveis. */
    private function exigirPost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->json(['ok' => false, 'msg' => 'Método não permitido.']);
            return false;
        }
        return true;
    }

    /**
     * Lê e valida um campo JSON opcional do POST.
     * Retorna: null (vazio) | string JSON normalizada | false (inválido — resposta já enviada).
     */
    private function validarJsonCampo(string $campo)
    {
        $bruto = trim((string) ($_POST[$campo] ?? ''));
        if ($bruto === '') {
            return null;
        }

        $dec = json_decode($bruto, true);
        if (!is_array($dec)) {
            $this->json(['ok' => false, 'msg' => "Campo {$campo}: JSON inválido."]);
            return false;
        }

        return json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Lê um decimal opcional do POST (aceita vírgula).
     * Retorna: null (vazio) | float | false (inválido).
     */
    private function lerDecimalOpcional(string $campo)
    {
        $bruto = trim((string) ($_POST[$campo] ?? ''));
        if ($bruto === '') {
            return null;
        }

        $valor = (float) str_replace(',', '.', $bruto);
        return ($valor < 0 || $valor > 999999) ? false : $valor;
    }

    /**
     * Token CSRF para os formulários.
     * AJUSTE: se o Controller base já expõe um helper próprio, use-o aqui.
     */
    private function tokenCsrf(): string
    {
        return (string) ($_SESSION['csrf_token'] ?? ($_SESSION['csrf'] ?? ''));
    }
}
