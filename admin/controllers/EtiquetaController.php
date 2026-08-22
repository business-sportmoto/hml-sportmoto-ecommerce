<?php
/**
 * EtiquetaController — emissão, impressão, cancelamento e manifesto de etiquetas.
 *
 * Permissão em cascata; CSRF em todo POST; o custo real (valor) só sai na
 * listagem/detalhe se o admin tem 'logistica.custos'.
 */
class EtiquetaController extends Controller
{
    private EtiquetaService $etiquetas;

    public function __construct()
    {
        AuthHelper::requirePermission('logistica');
        $this->etiquetas = new EtiquetaService();
    }

    /* ============================ TELA ============================ */

    /** Busca de endereço por CEP (ViaCEP) para autopreencher destinatário/remetente. */
    public function buscarCep(): void
    {
        $cep = preg_replace('/\D/', '', (string)($_GET['cep'] ?? '')) ?? '';
        if (strlen($cep) !== 8) { $this->json(['ok' => false, 'erro' => 'CEP inválido.']); return; }
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $res = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
            $d = $res ? json_decode($res, true) : null;
            if (!$d || !empty($d['erro'])) { $this->json(['ok' => false, 'erro' => 'CEP não encontrado.']); return; }
            $this->json(['ok' => true, 'endereco' => [
                'cep'        => $cep,
                'logradouro' => $d['logradouro'] ?? '',
                'complemento' => $d['complemento'] ?? '',
                'bairro'     => $d['bairro'] ?? '',
                'cidade'     => $d['localidade'] ?? '',
                'uf'         => $d['uf'] ?? '',
            ]]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'erro' => 'Falha ao consultar o CEP.']);
        }
    }

    /** Busca cliente por CPF (mesmo mecanismo da reversa) para autopreencher os dados. */
    public function buscarCliente(): void
    {
        $cpf = trim((string)($_GET['cpf'] ?? ''));
        $clientes = $cpf !== '' && class_exists('ClienteBuscaService') ? (new ClienteBuscaService())->buscarPorCpf($cpf) : [];
        $this->json(['ok' => true, 'clientes' => $clientes]);
    }

    /** POST /admin/logistica/etiquetas/ar — busca o AR Eletrônico (imagem) de um objeto entregue. */
    public function ar(): void
    {
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { $this->json(['ok' => false, 'erro' => 'Etiqueta inválida.']); return; }
        $this->json($this->etiquetas->arDaEtiqueta($id));
    }

    /**
     * Faz o stream do PDF do rótulo direto no navegador (Content-Disposition: inline),
     * sem depender de pasta pública. Requer permissão de logística (já no construtor).
     */
    public function rotulo(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $pdf = $id ? $this->etiquetas->pdfDoRotulo($id) : null;
        if ($pdf === null || $pdf === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Rótulo não encontrado. Gere a etiqueta novamente.';
            return;
        }
        if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="etiqueta-' . $id . '.pdf"'); // inline = abre no site
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    }

    public function index(): void
    {
        $this->render('logistica/etiquetas', [
            'titulo'          => 'Etiquetas',
            'transportadoras' => $this->transportadorasComServicos(),
            'filtros'         => $this->filtros(),
        ], 'admin');
    }

    public function dados(): void
    {
        $f = $this->filtros();
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $res = $this->etiquetas->listar($f, $pagina);
        $res['itens'] = $this->limparCustos($res['itens']);
        $res['pode_ver_custos'] = $this->podeVerCustos();
        $res['ok'] = true;
        $this->json($res);
    }

    public function obter(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $e = $id > 0 ? $this->etiquetas->obter($id) : null;
        if (!$e) { $this->json(['ok' => false, 'erro' => 'Etiqueta não encontrada.']); return; }
        if (!$this->podeVerCustos()) unset($e['valor']);
        $this->json(['ok' => true, 'etiqueta' => $e, 'eventos' => $this->etiquetas->eventos($id), 'pode_ver_custos' => $this->podeVerCustos()]);
    }

    /* ============================ AÇÕES ============================ */

    public function criar(): void
    {
        $this->verifyCsrf();
        $dados = [
            'pedido_id'       => $_POST['pedido_id'] ?? null,
            'cotacao_id'      => $_POST['cotacao_id'] ?? null,
            'transportadora_id' => (int)($_POST['transportadora_id'] ?? 0),
            'servico_codigo'  => $_POST['servico_codigo'] ?? '',
            'servico_nome'    => $_POST['servico_nome'] ?? null,
            'canal'           => $_POST['canal'] ?? 'site',
            'formato'         => $_POST['formato'] ?? 'pdf',
            'valor_declarado' => $_POST['valor_declarado'] ?? 0,
            'nota_fiscal_chave' => $_POST['nota_fiscal_chave'] ?? null,
            'remetente'       => is_array($_POST['remetente'] ?? null) ? $_POST['remetente'] : [],
            'destinatario'    => is_array($_POST['destinatario'] ?? null) ? $_POST['destinatario'] : [],
            'volumes'         => $this->volumesDoPost(),
            'produtos'        => is_array($_POST['produtos'] ?? null) ? array_values($_POST['produtos']) : [],
        ];
        $this->json($this->etiquetas->criar($dados, $this->usuarioId()));
    }

    public function comprar(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->comprar((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function comprarLote(): void
    {
        $this->verifyCsrf();
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $this->json($this->etiquetas->comprarLote($ids, $this->usuarioId()));
    }

    public function imprimir(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->imprimir((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function cancelar(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->cancelar((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    public function manifesto(): void
    {
        $this->verifyCsrf();
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $this->json($this->etiquetas->gerarManifesto($ids, $this->usuarioId()));
    }

    public function remover(): void
    {
        $this->verifyCsrf();
        $this->json($this->etiquetas->remover((int)($_POST['id'] ?? 0), $this->usuarioId()));
    }

    /* ============================ helpers ============================ */

    private function volumesDoPost(): array
    {
        $out = [];
        foreach ((is_array($_POST['volumes'] ?? null) ? $_POST['volumes'] : []) as $v) {
            $out[] = [
                'altura_cm'      => (float)($v['altura_cm'] ?? 0),
                'largura_cm'     => (float)($v['largura_cm'] ?? 0),
                'comprimento_cm' => (float)($v['comprimento_cm'] ?? 0),
                'peso_g'         => (int)($v['peso_g'] ?? 0),
            ];
        }
        return $out;
    }

    private function filtros(): array
    {
        $out = [];
        if (!empty($_GET['status']))            $out['status'] = (string)$_GET['status'];
        if (!empty($_GET['transportadora_id'])) $out['transportadora_id'] = (int)$_GET['transportadora_id'];
        if (!empty($_GET['busca']))             $out['busca'] = trim((string)$_GET['busca']);
        return $out;
    }

    private function limparCustos(array $itens): array
    {
        if ($this->podeVerCustos()) return $itens;
        foreach ($itens as &$i) unset($i['valor']);
        return $itens;
    }

    private function transportadorasComServicos(): array
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $ts = $pdo->query("SELECT id, nome, slug FROM log_transportadoras WHERE status = 'ativo' ORDER BY prioridade ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$ts) return [];
            $in = implode(',', array_map(static fn($t) => (int)$t['id'], $ts));
            $sv = $pdo->query("SELECT transportadora_id, codigo, nome FROM log_transportadora_servicos WHERE transportadora_id IN ($in) AND habilitado = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $porT = [];
            foreach ($sv as $s) $porT[$s['transportadora_id']][] = ['codigo' => $s['codigo'], 'nome' => $s['nome']];
            foreach ($ts as &$t) $t['servicos'] = $porT[$t['id']] ?? [];
            return $ts;
        } catch (\Throwable $e) {
            LogService::error('Falha ao carregar transportadoras para etiquetas', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    private function podeVerCustos(): bool
    {
        foreach (['pode', 'temPermissao', 'can'] as $m) {
            if (method_exists('AuthHelper', $m)) {
                try { return (bool)AuthHelper::$m('logistica.custos'); } catch (\Throwable $e) { /* próximo */ }
            }
        }
        return true;
    }

    private function usuarioId(): ?int
    {
        foreach (['usuarioId', 'idUsuario', 'id'] as $m) {
            if (method_exists('AuthHelper', $m)) {
                $v = AuthHelper::$m();
                if (is_numeric($v)) return (int)$v;
            }
        }
        if (!empty($_SESSION['usuario_id'])) return (int)$_SESSION['usuario_id'];
        if (!empty($_SESSION['admin']['id'])) return (int)$_SESSION['admin']['id'];
        return null;
    }
}



$data = array(
    'cod' 			=> '41355', #STRING #Sku do produto
    'data_time'   	=> '2024-09-14 10:03:25', #STRING #data no formato Y-m-d H:i:s
    'tipo'     		=> 'AJUSTE DE ESTOQUE PRO',#STRING #Tipo de movimentação conforme syscar
    'tipo_cod'      => 'entrada_nfe', //alteracao_estoque, pedido, ordem_servico
	'cliente'     	=> [], #ARRAY #dados do cliente em array
	#$['cliente']['nome']	= "Fulano de tal da silva"; //Específico do syscar - Max 150 caracteres
	#$['cliente']['cpf'] 	= "88888888888"; //111.222.333-44 ou 11.222.333-4444/55
	#$['cliente']['tipo'] 	= "F"; // F = Físico - J = Juridico
	#$['cliente']['fone'] 	= "99999999999";
	#$['cliente']['email'] 	= "email@email.com.br";
	#$['cliente']['sexo'] 	= "M"; //M = Masculino - F = Feminino
	's_anterior'    => 0, #INT //Saldo anterior
	'movimento'     => 2, #INT //Saldo que foi movimentado
	'n_saldo'     	=> 2, #INT //Novo saldo calculando o anterior e o movimentado
	'lancamento'	=> 'E', #STRING // IMPORTANTE!! Preciso saber quando for um lançamento (E) ou remoção de esquete (S); E = Entrada - S = Saida; 
	'preco_mov'		=> 'R$ 699,98', #STRING ('R$ 699,98') OU (699.98)INT//Valor da comppra/venda
	'local'			=> '', //Local
	'justifica'		=> '' //Justificativa	
);