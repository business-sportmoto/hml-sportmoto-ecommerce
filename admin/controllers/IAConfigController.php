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
        $this->exigirPermissao('marketing_ia_config');

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
        $this->exigirPermissao('marketing_ia_config');

        $html = $this->partial('_provedores_rows', ['provedores' => (new IAProvedor())->listar()]);
        $this->json(['ok' => true, 'html' => $html, 'kpis' => $this->montarKpis()]);
    }

    public function provedorForm()
    {
        $this->exigirPermissao('marketing_ia_config');

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
        $this->exigirPermissao('marketing_ia_config');
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
        $this->exigirPermissao('marketing_ia_config');
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
        $this->exigirPermissao('marketing_ia_config');
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
        $this->exigirPermissao('marketing_ia_config');

        $html = $this->partial('_modelos_rows', [
            'modelos'     => (new IAModelo())->listar(),
            'capacidades' => IAModelo::capacidades(),
        ]);
        $this->json(['ok' => true, 'html' => $html, 'kpis' => $this->montarKpis()]);
    }

    public function modeloForm()
    {
        $this->exigirPermissao('marketing_ia_config');

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
        $this->exigirPermissao('marketing_ia_config');
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
        if (!$this->validarCustoConfig($custoConfig, $capacidade)) {
            return; // json de erro já enviado
        }

        $paramsPadrao = $this->validarJsonCampo('params_padrao');
        if ($paramsPadrao === false) {
            return;
        }
        if (!$this->validarParamsPadrao($paramsPadrao)) {
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
        $this->exigirPermissao('marketing_ia_config');
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

        // Mesma régua do provedor sem chave: não se liga um modelo que gasta
        // sem aparecer. Sem esta trava o custo_config obrigatório do salvar
        // seria contornável por um clique no toggle.
        if ($novo === 1 && trim((string) ($atual['custo_config'] ?? '')) === '') {
            $this->json(['ok' => false, 'msg' => 'Configure o custo do modelo antes de ativá-lo — sem isso o gasto não entra no rollup nem nos tetos.']);
            return;
        }

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
        $this->exigirPermissao('marketing_ia_config');
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
        $this->exigirPermissao('marketing_ia_config');

        $html = $this->partial('_limites_rows', ['limites' => (new IALimite())->listar()]);
        $this->json(['ok' => true, 'html' => $html, 'kpis' => $this->montarKpis()]);
    }

    public function limiteForm()
    {
        $this->exigirPermissao('marketing_ia_config');

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
        $this->exigirPermissao('marketing_ia_config');
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
        $this->exigirPermissao('marketing_ia_config');
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
        $caminho = __DIR__ . '/../views/ia/config/' . $arquivo . '.php';

        if (!is_file($caminho)) {
            LogService::error('ia_partial_inexistente', ['arquivo' => $arquivo]);
            return '';
        }

        extract($dados, EXTR_SKIP);
        ob_start();
        include $caminho;
        return (string) ob_get_clean();
    }

    /**
     * Guard de permissão do módulo.
     *
     * Faz o que o AuthHelper::requirePermission() faz, com duas diferenças:
     * a decisão de acesso passa pelo IAPermissaoService (permissão granular
     * primeiro, cargo de cobertura depois), e a negação distingue Ajax de
     * navegação como o requireAdminLevel — a Central é toda Ajax, e um 403
     * em HTML chegava aos $.post como markup onde o JS esperava JSON: o
     * usuário via "Falha de comunicação" no lugar do motivo real.
     *
     * A resposta de navegação é montada aqui de propósito: no painel a base
     * de views é admin/views, onde não existem errors/403 nem o layout
     * minimal que o AuthHelper chama — por lá a negação vira RuntimeException.
     */
    private function exigirPermissao(string $permissao): void
    {
        AuthHelper::requireAdmin();
        if ((new IAPermissaoService())->pode($permissao)) {
            return;
        }

        http_response_code(403);
        if (AuthHelper::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sem permissão para esta ação.'], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Sem permissão</title>'
               . '<p style="font:16px system-ui;padding:2rem">Você não tem permissão para acessar a Central de IA.</p>';
        }
        exit;
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
     * custo_config é OBRIGATÓRIO e precisa ter forma válida.
     *
     * Era opcional, e modelo sem custo escapava inteiro do controle: o
     * IACustoService estima 0, o custo real volta null, o rollup não registra
     * e os tetos diário/mensal nunca são atingidos. Dois modelos de imagem
     * ficaram assim — gastavam de verdade sem aparecer em lugar nenhum.
     *
     * Devolve false com a resposta JSON de erro já enviada.
     */
    private function validarCustoConfig(?string $json, string $capacidade): bool
    {
        if ($json === null) {
            $this->json(['ok' => false, 'msg' => 'Informe o custo do modelo — sem ele o gasto não entra no rollup nem nos tetos.']);
            return false;
        }

        $cfg  = json_decode($json, true);
        $tipo = is_array($cfg) ? (string) ($cfg['tipo'] ?? '') : '';

        // Cada tipo tem o seu campo de valor; token é o único com dois.
        $campos = [
            'por_token'    => ['usd_in_1m', 'usd_out_1m'],
            'por_imagem'   => ['usd_imagem'],
            'por_execucao' => ['usd_execucao'],
        ];

        if (!isset($campos[$tipo])) {
            $this->json(['ok' => false, 'msg' => 'custo_config: "tipo" deve ser por_token, por_imagem ou por_execucao.']);
            return false;
        }

        // Texto cobra por token; mídia cobra por unidade. Trocar isso faz o
        // IACustoService devolver null e o gasto some do rollup.
        $esperado = ($capacidade === 'texto') ? 'por_token' : ['por_imagem', 'por_execucao'];
        $ok = is_array($esperado) ? in_array($tipo, $esperado, true) : $tipo === $esperado;
        if (!$ok) {
            $this->json(['ok' => false, 'msg' => ($capacidade === 'texto')
                ? 'Capacidade texto cobra por token — use "tipo": "por_token".'
                : 'Esta capacidade cobra por unidade — use "por_imagem" ou "por_execucao".']);
            return false;
        }

        foreach ($campos[$tipo] as $campo) {
            if (!isset($cfg[$campo]) || !is_numeric($cfg[$campo]) || (float) $cfg[$campo] < 0) {
                $this->json(['ok' => false, 'msg' => "custo_config: informe \"{$campo}\" como número maior ou igual a zero."]);
                return false;
            }
        }

        return true;
    }

    /** Valida o bloco reservado `ia` do params_padrao (ver IAModelo::meta). */
    private function validarParamsPadrao(?string $json): bool
    {
        if ($json === null) {
            return true;
        }

        $cfg = json_decode($json, true);
        $ia  = is_array($cfg) && is_array($cfg['ia'] ?? null) ? $cfg['ia'] : null;
        if ($ia === null) {
            return true;
        }

        if (array_key_exists('proporcoes', $ia)) {
            if (!is_array($ia['proporcoes']) || $ia['proporcoes'] === []) {
                $this->json(['ok' => false, 'msg' => 'params_padrao.ia.proporcoes deve ser uma lista não vazia, ex.: ["1:1","16:9"].']);
                return false;
            }
            foreach ($ia['proporcoes'] as $p) {
                if (!is_string($p) || !preg_match('/^\d{1,2}:\d{1,2}$/', $p)) {
                    $this->json(['ok' => false, 'msg' => 'params_padrao.ia.proporcoes: use o formato "L:A", ex.: "16:9".']);
                    return false;
                }
            }
        }

        if (array_key_exists('ref_param', $ia) && (!is_string($ia['ref_param']) || trim($ia['ref_param']) === '')) {
            $this->json(['ok' => false, 'msg' => 'params_padrao.ia.ref_param deve ser o nome do input, ex.: "input_images".']);
            return false;
        }

        return true;
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
     * Token CSRF para os formulários — o mesmo do resto do painel
     * (SecurityHelper grava em CSRF_TOKEN_NAME, que é o que verifyCsrf lê).
     */
    private function tokenCsrf(): string
    {
        return SecurityHelper::generateCsrf();
    }
}
