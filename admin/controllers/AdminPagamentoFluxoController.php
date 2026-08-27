<?php
declare(strict_types=1);

/**
 * admin/controllers/AdminPagamentoFluxoController.php
 *
 * Editor visual dos fluxos de pagamento (Drawflow).
 *
 * PERMISSÃO: super, gerente — o fluxo decide por onde o dinheiro passa,
 * mesma régua das Formas de pagamento.
 *
 * VERSIONAMENTO: publicar NÃO edita a versão em produção. Cria uma versão
 * nova e arquiva a anterior. Sem isso, salvar um rascunho às 15h mudaria o
 * roteamento de quem está com o checkout aberto naquele instante — e o
 * histórico em pgto_tentativas (que guarda fluxo_versao) ficaria mentindo
 * sobre qual desenho tomou cada decisão.
 */
class AdminPagamentoFluxoController extends Controller
{
    private PDO $db;

    public function __construct()
    {
        AuthHelper::requireAdmin();
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /admin/pagamentos/fluxos */
    public function index(): void
    {
        $metodos = (new PagamentoMetodo())->listar();

        // Um cartão publicado + um rascunho contam como um fluxo só na lista:
        // o que importa é o estado por método.
        $fluxos = $this->db->query(
            "SELECT f.*,
                    (SELECT COUNT(*) FROM pgto_fluxo_nos n WHERE n.fluxo_id = f.id) AS total_nos
               FROM pgto_fluxos f
              ORDER BY f.metodo_codigo, f.versao DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $porMetodo = [];
        foreach ($fluxos as $f) $porMetodo[$f['metodo_codigo']][] = $f;

        SeoHelper::setTitle('Fluxos de pagamento');
        $this->render('pagamentos/fluxos', [
            'metodos'   => $metodos,
            'porMetodo' => $porMetodo,
        ], 'admin');
    }

    /** GET /admin/pagamentos/fluxos/editor?metodo=cartao_credito[&id=] */
    public function editor(): void
    {
        $metodo = SecurityHelper::sanitizeString($_GET['metodo'] ?? '');
        $id     = (int) ($_GET['id'] ?? 0);

        $fluxo = $id > 0 ? $this->carregarFluxo($id) : $this->rascunhoDe($metodo);
        if (!$fluxo) {
            $this->render('errors/404', [], 'admin');
            return;
        }

        $adquirentes = array_values(array_filter(
            (new PagamentoAdquirente())->listarParaTela(),
            static fn($a) => !empty($a['tem_adapter'])
        ));

        SeoHelper::setTitle('Editor de fluxo — ' . $fluxo['nome']);
        $this->render('pagamentos/fluxo-editor', [
            'fluxo'       => $fluxo,
            'grafo'       => $this->carregarGrafo((int) $fluxo['id']),
            'catalogo'    => PagamentoNoCatalogo::todos(),
            'adquirentes' => $adquirentes,
        ], 'admin');
    }

    /** POST /admin/pagamentos/fluxos/salvar — grava o rascunho */
    public function salvar(): void
    {
        $this->verifyCsrf();

        $id  = (int) ($_POST['fluxo_id'] ?? 0);
        $fx  = $this->carregarFluxo($id);
        if (!$fx) $this->json(['ok' => false, 'msg' => 'Fluxo não encontrado.']);

        if ($fx['status'] === 'publicado') {
            // Editar o publicado direto mudaria o roteamento em produção sem
            // aviso. O caminho é criar rascunho a partir dele.
            $this->json(['ok' => false, 'msg' =>
                'Este fluxo está publicado. Crie um rascunho para editar.']);
        }

        [$nos, $conexoes] = $this->lerGrafoDoPost();
        $validacao = PagamentoNoCatalogo::validarGrafo($nos, $conexoes);

        $this->gravarGrafo($id, $nos, $conexoes, $_POST['canvas'] ?? null);

        $this->json([
            'ok'     => true,
            'msg'    => 'Rascunho salvo.',
            'erros'  => $validacao['erros'],
            'avisos' => $validacao['avisos'],
        ]);
    }

    /** POST /admin/pagamentos/fluxos/publicar */
    public function publicar(): void
    {
        $this->verifyCsrf();

        $id = (int) ($_POST['fluxo_id'] ?? 0);
        $fx = $this->carregarFluxo($id);
        if (!$fx) $this->json(['ok' => false, 'msg' => 'Fluxo não encontrado.']);

        [$nos, $conexoes] = $this->lerGrafoDoPost();
        $validacao = PagamentoNoCatalogo::validarGrafo($nos, $conexoes);

        // Erro barra a publicação. Publicar grafo quebrado significa cliente
        // no checkout sem roteamento — pior do que não publicar.
        if ($validacao['erros']) {
            $this->json([
                'ok'     => false,
                'msg'    => 'Corrija os erros antes de publicar.',
                'erros'  => $validacao['erros'],
                'avisos' => $validacao['avisos'],
            ]);
        }

        $this->db->beginTransaction();
        try {
            $this->gravarGrafo($id, $nos, $conexoes, $_POST['canvas'] ?? null);

            // Arquiva a versão que está no ar para o mesmo método.
            $this->db->prepare(
                "UPDATE pgto_fluxos SET status = 'arquivado'
                  WHERE metodo_codigo = ? AND status = 'publicado' AND id <> ?"
            )->execute([$fx['metodo_codigo'], $id]);

            $this->db->prepare(
                "UPDATE pgto_fluxos
                    SET status = 'publicado', publicado_em = NOW(), publicado_por = ?
                  WHERE id = ?"
            )->execute([AuthHelper::usuarioId() ?: null, $id]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            LogService::exception($e, 'error', 'pagamento', ['acao' => 'publicar_fluxo', 'fluxo_id' => $id]);
            $this->json(['ok' => false, 'msg' => 'Falha ao publicar: ' . $e->getMessage()]);
        }

        LogService::audit('Fluxo de pagamento publicado', [
            'fluxo_id' => $id,
            'metodo'   => $fx['metodo_codigo'],
            'versao'   => $fx['versao'],
            'por'      => AuthHelper::usuarioId(),
            'nos'      => count($nos),
        ]);

        $this->json([
            'ok'     => true,
            'msg'    => 'Fluxo publicado. Passa a valer nos próximos pagamentos.',
            'avisos' => $validacao['avisos'],
        ]);
    }

    /**
     * POST /admin/pagamentos/fluxos/rascunho
     * Clona o publicado numa versão nova para edição segura.
     */
    public function novoRascunho(): void
    {
        $this->verifyCsrf();

        $metodo = SecurityHelper::sanitizeString($_POST['metodo'] ?? '');
        if ($metodo === '') $this->json(['ok' => false, 'msg' => 'Método inválido.']);

        $existente = $this->rascunhoExistente($metodo);
        if ($existente) {
            $this->json(['ok' => true, 'id' => (int) $existente['id'], 'msg' => 'Já havia um rascunho — abrindo.']);
        }

        $base = $this->db->prepare(
            "SELECT * FROM pgto_fluxos WHERE metodo_codigo = ? AND status = 'publicado'
              ORDER BY versao DESC LIMIT 1"
        );
        $base->execute([$metodo]);
        $base = $base->fetch(PDO::FETCH_ASSOC);

        $novoId = $this->criarFluxo($metodo, $base);
        if ($base) $this->clonarGrafo((int) $base['id'], $novoId);

        $this->json(['ok' => true, 'id' => $novoId, 'msg' => 'Rascunho criado.']);
    }

    // =========================================================================

    private function carregarFluxo(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM pgto_fluxos WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function rascunhoExistente(string $metodo): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM pgto_fluxos WHERE metodo_codigo = ? AND status = 'rascunho'
              ORDER BY versao DESC LIMIT 1"
        );
        $st->execute([$metodo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Abre o rascunho do método, criando um vazio se não houver. */
    private function rascunhoDe(string $metodo): ?array
    {
        if ($metodo === '') return null;
        $r = $this->rascunhoExistente($metodo);
        if ($r) return $r;

        $id = $this->criarFluxo($metodo, null);
        return $this->carregarFluxo($id);
    }

    private function criarFluxo(string $metodo, ?array $base): int
    {
        $st = $this->db->prepare("SELECT COALESCE(MAX(versao), 0) + 1 FROM pgto_fluxos WHERE metodo_codigo = ?");
        $st->execute([$metodo]);
        $versao = (int) $st->fetchColumn();

        $nome = $base['nome'] ?? ('Fluxo — ' . $metodo);

        $this->db->prepare(
            "INSERT INTO pgto_fluxos (metodo_codigo, nome, versao, status, criado_em)
             VALUES (?, ?, ?, 'rascunho', NOW())"
        )->execute([$metodo, $nome, $versao]);

        return (int) $this->db->lastInsertId();
    }

    private function clonarGrafo(int $de, int $para): void
    {
        $nos = $this->db->prepare("SELECT no_ref, tipo, config, pos_x, pos_y FROM pgto_fluxo_nos WHERE fluxo_id = ?");
        $nos->execute([$de]);
        $ins = $this->db->prepare(
            "INSERT INTO pgto_fluxo_nos (fluxo_id, no_ref, tipo, config, pos_x, pos_y) VALUES (?,?,?,?,?,?)"
        );
        foreach ($nos->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $ins->execute([$para, $n['no_ref'], $n['tipo'], $n['config'], $n['pos_x'], $n['pos_y']]);
        }

        $cx = $this->db->prepare("SELECT no_origem, porta_origem, no_destino FROM pgto_fluxo_conexoes WHERE fluxo_id = ?");
        $cx->execute([$de]);
        $ins = $this->db->prepare(
            "INSERT INTO pgto_fluxo_conexoes (fluxo_id, no_origem, porta_origem, no_destino) VALUES (?,?,?,?)"
        );
        foreach ($cx->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $ins->execute([$para, $c['no_origem'], $c['porta_origem'], $c['no_destino']]);
        }
    }

    private function carregarGrafo(int $fluxoId): array
    {
        $nos = $this->db->prepare("SELECT no_ref, tipo, config, pos_x, pos_y FROM pgto_fluxo_nos WHERE fluxo_id = ?");
        $nos->execute([$fluxoId]);

        $cx = $this->db->prepare("SELECT no_origem, porta_origem, no_destino FROM pgto_fluxo_conexoes WHERE fluxo_id = ?");
        $cx->execute([$fluxoId]);

        return [
            'nos' => array_map(static function (array $n): array {
                $n['config'] = json_decode((string) $n['config'], true) ?: [];
                return $n;
            }, $nos->fetchAll(PDO::FETCH_ASSOC)),
            'conexoes' => $cx->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** Lê o grafo enviado pelo canvas, descartando o que não está no catálogo. */
    private function lerGrafoDoPost(): array
    {
        $bruto = json_decode((string) ($_POST['grafo'] ?? ''), true);
        if (!is_array($bruto)) return [[], []];

        $nos = [];
        foreach ($bruto['nos'] ?? [] as $n) {
            $tipo = (string) ($n['tipo'] ?? '');
            if (!PagamentoNoCatalogo::existe($tipo)) continue;
            $nos[] = [
                'no_ref' => substr((string) ($n['no_ref'] ?? ''), 0, 40),
                'tipo'   => $tipo,
                'config' => is_array($n['config'] ?? null) ? $n['config'] : [],
                'pos_x'  => (int) ($n['pos_x'] ?? 0),
                'pos_y'  => (int) ($n['pos_y'] ?? 0),
            ];
        }

        $refs = array_column($nos, 'no_ref');
        $cx   = [];
        foreach ($bruto['conexoes'] ?? [] as $c) {
            $o = substr((string) ($c['no_origem'] ?? ''), 0, 40);
            $d = substr((string) ($c['no_destino'] ?? ''), 0, 40);
            $p = substr((string) ($c['porta_origem'] ?? ''), 0, 40);
            // Aresta para nó que não veio no payload é lixo do canvas.
            if (!in_array($o, $refs, true) || !in_array($d, $refs, true)) continue;
            $cx[] = ['no_origem' => $o, 'porta_origem' => $p, 'no_destino' => $d];
        }

        return [$nos, $cx];
    }

    /** Regrava o grafo inteiro. Mais simples e seguro que diff incremental. */
    private function gravarGrafo(int $fluxoId, array $nos, array $conexoes, ?string $canvas): void
    {
        $this->db->prepare("DELETE FROM pgto_fluxo_nos WHERE fluxo_id = ?")->execute([$fluxoId]);
        $this->db->prepare("DELETE FROM pgto_fluxo_conexoes WHERE fluxo_id = ?")->execute([$fluxoId]);

        $ins = $this->db->prepare(
            "INSERT INTO pgto_fluxo_nos (fluxo_id, no_ref, tipo, config, pos_x, pos_y) VALUES (?,?,?,?,?,?)"
        );
        foreach ($nos as $n) {
            $ins->execute([$fluxoId, $n['no_ref'], $n['tipo'],
                           json_encode($n['config'], JSON_UNESCAPED_UNICODE), $n['pos_x'], $n['pos_y']]);
        }

        $ins = $this->db->prepare(
            "INSERT INTO pgto_fluxo_conexoes (fluxo_id, no_origem, porta_origem, no_destino) VALUES (?,?,?,?)"
        );
        foreach ($conexoes as $c) {
            $ins->execute([$fluxoId, $c['no_origem'], $c['porta_origem'], $c['no_destino']]);
        }

        $this->db->prepare("UPDATE pgto_fluxos SET canvas_json = ?, atualizado_em = NOW() WHERE id = ?")
                 ->execute([$canvas ?: null, $fluxoId]);
    }
}
