<?php
/**
 * admin/controllers/SeoUrlAdminController.php
 *
 * Duas telas: os endereços que estão dando 404 e o mapa de redirecionamentos.
 *
 * Cargo: **só super** (decisão do dono, 11/09/2026). Um redirecionamento leva
 * o visitante para onde quem criou quiser — inclusive para fora do site —, e
 * a tela de 404 mostra IP de visitante.
 */
class SeoUrlAdminController extends Controller
{
    private Erro404Service $erros;
    private RedirecionamentoService $redir;

    public function __construct()
    {
        AuthHelper::requireAdminLevel('super');

        $this->erros = new Erro404Service();
        $this->redir = new RedirecionamentoService();
    }

    // ════════════════════════════════════════════════════════════════
    // Tela 1 — endereços quebrados
    // ════════════════════════════════════════════════════════════════

    public function erros(): void
    {
        // O detalhe do acesso guarda IP: some sozinho depois de 90 dias.
        // Aqui é o lugar barato de fazer isso — índice em criado_em, e na
        // maioria das visitas não apaga nada.
        try { $this->erros->purgarAcessos(90); } catch (Throwable $e) { /* nunca trava a tela */ }

        $filtros = [
            'busca'  => SecurityHelper::sanitizeString($_GET['busca']  ?? ''),
            'status' => SecurityHelper::sanitizeString($_GET['status'] ?? ''),
            'origem' => SecurityHelper::sanitizeString($_GET['origem'] ?? ''),
            'robos'  => !empty($_GET['robos'])  ? 1 : 0,
            'ruido'  => !empty($_GET['ruido'])  ? 1 : 0,
        ];

        $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina = 30;

        $this->render('seo/404', [
            'titulo'    => 'Endereços com erro 404',
            'urls'      => $this->erros->listar($filtros, $pagina, $porPagina),
            'total'     => $this->erros->contar($filtros),
            'resumo'    => $this->erros->resumo(),
            'filtros'   => $filtros,
            'pagina'    => $pagina,
            'porPagina' => $porPagina,
        ], 'admin');
    }

    /** Os últimos acessos de um endereço — data, origem, IP, user agent. */
    public function acessos(int $id): void
    {
        $url = $this->erros->porId($id);
        if (!$url) $this->json(['ok' => false, 'msg' => 'Endereço não encontrado.'], 404);

        $this->json([
            'ok'      => true,
            'caminho' => $url['caminho'],
            'acessos' => $this->erros->acessos($id, 50),
        ]);
    }

    /** Palpites de destino — quem decide é o admin; isto só preenche o campo. */
    public function sugestoes(int $id): void
    {
        $url = $this->erros->porId($id);
        if (!$url) $this->json(['ok' => false, 'msg' => 'Endereço não encontrado.'], 404);

        $this->json([
            'ok'        => true,
            'caminho'   => $url['caminho'],
            'sugestoes' => $this->redir->sugerirDestinos((string) $url['caminho'], 6),
        ]);
    }

    public function marcarStatus(): void
    {
        $this->verifyCsrf();

        $id     = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $status = SecurityHelper::sanitizeString($_POST['status'] ?? '');

        if ($id <= 0) $this->json(['ok' => false, 'msg' => 'Endereço inválido.']);

        $this->json($this->erros->marcarStatus($id, $status));
    }

    /**
     * Cria o redirecionamento a partir da linha do 404 — o caminho mais curto
     * entre ver o problema e resolvê-lo.
     */
    public function redirecionarDe404(): void
    {
        $this->verifyCsrf();

        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $url = $this->erros->porId($id);
        if (!$url) $this->json(['ok' => false, 'msg' => 'Endereço não encontrado.']);

        $r = $this->redir->salvar([
            'origem'     => $url['caminho'],
            'destino'    => (string) ($_POST['destino'] ?? ''),
            'tipo'       => (int) ($_POST['tipo'] ?? 301),
            'motivo'     => 'manual',
            'observacao' => 'Criado pela tela de 404.',
            'ativo'      => 1,
        ], AuthHelper::usuarioId());

        if (!empty($r['ok'])) {
            $this->erros->marcarStatus($id, 'resolvido', (int) $r['id']);
        }

        $this->json($r);
    }

    // ════════════════════════════════════════════════════════════════
    // Tela 2 — mapa de redirecionamentos
    // ════════════════════════════════════════════════════════════════

    public function redirecionamentos(): void
    {
        $filtros = [
            'busca' => SecurityHelper::sanitizeString($_GET['busca'] ?? ''),
            'tipo'  => (int) ($_GET['tipo'] ?? 0),
            'ativo' => isset($_GET['ativo']) && $_GET['ativo'] !== '' ? (int) $_GET['ativo'] : '',
        ];

        $pagina    = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina = 30;

        $this->render('seo/redirecionamentos', [
            'titulo'    => 'Redirecionamentos',
            'regras'    => $this->redir->listar($filtros, $pagina, $porPagina),
            'total'     => $this->redir->contar($filtros),
            'filtros'   => $filtros,
            'pagina'    => $pagina,
            'porPagina' => $porPagina,
        ], 'admin');
    }

    public function obter(int $id): void
    {
        $r = $this->redir->porId($id);
        if (!$r) $this->json(['ok' => false, 'msg' => 'Redirecionamento não encontrado.'], 404);

        $this->json(['ok' => true, 'regra' => $r]);
    }

    public function salvar(): void
    {
        $this->verifyCsrf();

        $this->json($this->redir->salvar([
            'id'         => SecurityHelper::sanitizeInt($_POST['id'] ?? 0),
            'origem'     => (string) ($_POST['origem'] ?? ''),
            'destino'    => (string) ($_POST['destino'] ?? ''),
            'tipo'       => (int) ($_POST['tipo'] ?? 301),
            'prioridade' => (int) ($_POST['prioridade'] ?? 100),
            'motivo'     => SecurityHelper::sanitizeString($_POST['motivo'] ?? 'manual'),
            'observacao' => SecurityHelper::sanitizeString($_POST['observacao'] ?? ''),
            'ativo'      => isset($_POST['ativo']) ? (int) $_POST['ativo'] : 1,
        ], AuthHelper::usuarioId()));
    }

    /**
     * Importação em massa: uma regra por linha,
     * `origem;destino;tipo;prioridade` (tipo e prioridade opcionais).
     *
     * Passa pelo mesmo `salvar()` da tela — validação, normalização, detecção
     * de cadeia e autoria ficam iguais. Colar SQL direto no banco pularia tudo
     * isso.
     */
    public function importar(): void
    {
        $this->verifyCsrf();

        $linhas = preg_split('/\R/', (string) ($_POST['linhas'] ?? '')) ?: [];
        $autor  = AuthHelper::usuarioId();
        $feitas = 0;
        $erros  = [];

        foreach ($linhas as $n => $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#')) continue;

            $campos = array_map('trim', explode(';', $linha));

            $r = $this->redir->salvar([
                'origem'     => $campos[0] ?? '',
                'destino'    => $campos[1] ?? '',
                'tipo'       => (int) ($campos[2] ?? 301) ?: 301,
                'prioridade' => isset($campos[3]) && $campos[3] !== '' ? (int) $campos[3] : 100,
                'motivo'     => 'migracao_tray',
                'observacao' => 'Importado em massa.',
                'ativo'      => 1,
            ], $autor);

            if (!empty($r['ok'])) $feitas++;
            else $erros[] = 'linha ' . ($n + 1) . ': ' . ($r['msg'] ?? 'erro');
        }

        $this->json([
            'ok'         => true,
            'importados' => $feitas,
            'erros'      => array_slice($erros, 0, 50),
            'msg'        => $feitas . ' regra(s) importada(s).'
                          . ($erros ? ' ' . count($erros) . ' linha(s) com problema.' : ''),
        ]);
    }

    public function alternar(): void
    {
        $this->verifyCsrf();

        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $this->json($this->redir->alternarAtivo($id));
    }

    public function excluir(): void
    {
        $this->verifyCsrf();

        $id = SecurityHelper::sanitizeInt($_POST['id'] ?? 0);
        $this->json($this->redir->excluir($id));
    }
}
