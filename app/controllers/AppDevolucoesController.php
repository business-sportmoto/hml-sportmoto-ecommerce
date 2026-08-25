<?php
// app/controllers/AppDevolucoesController.php
// Trocas e devoluções.
//
// Toda a regra vive em DevolucaoService — prazo do CDC (7 dias), validação de
// itens, geração de código de postagem reversa, histórico de status. Este
// controller só traduz para JSON e garante que o cliente só enxerga o que é
// dele.
//
// O que o app precisa e a web resolve na view: saber ANTES de abrir o
// formulário se o pedido ainda é elegível. Por isso `/pedidos/{codigo}/pode`.

class AppDevolucoesController extends AppApiController
{
    /**
     * GET /api/app/v1/conta/devolucoes
     */
    public function index(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $servico = new DevolucaoService();
        $pagina  = $this->pagina(10, 30);

        try {
            $lista = $servico->listar(['cliente_id' => $this->clienteId], $pagina['page'], $pagina['limit']);
            $total = $servico->contar(['cliente_id' => $this->clienteId]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'listar_devolucoes']);
            $this->falha(500, 'falha_devolucoes', 'Não foi possível carregar suas solicitações.');
        }

        $this->okPaginado(
            'devolucoes',
            array_map(static fn(array $d) => DevolucaoPresenter::resumo($d), $lista),
            $total,
            $pagina
        );
    }

    /**
     * GET /api/app/v1/conta/devolucoes/motivos
     * Alimenta o seletor do formulário. Só os ativos.
     */
    public function motivos(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $motivos = (new DevolucaoService())->getMotivos(true);
        } catch (\Throwable $e) {
            $motivos = [];
        }

        $this->ok(['motivos' => array_values(array_map(static fn(array $m) => [
            'id'    => (int)$m['id'],
            'label' => $m['label'] ?? '',
            // 'devolucao' | 'troca' | 'ambos' — o app filtra pelo que o cliente
            // escolheu antes, para não oferecer motivo de troca numa devolução.
            'tipo'  => $m['tipo'] ?? 'ambos',
            // Motivos como "produto avariado" exigem foto.
            'exige_foto' => !empty($m['exige_foto']),
            // Quem paga o frete reverso muda a expectativa do cliente e
            // precisa aparecer antes dele confirmar.
            'responsavel_frete' => $m['responsavel_frete'] ?? null,
        ], $motivos))]);
    }

    /**
     * GET /api/app/v1/conta/devolucoes/{id}
     */
    public function show(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $servico = new DevolucaoService();
        $sol = $servico->findById((int)$id);

        // A checagem de posse é nossa: findById() não filtra por cliente.
        if (!$sol || (int)($sol['cliente_id'] ?? 0) !== (int)$this->clienteId) {
            $this->falha(404, 'nao_encontrada', 'Solicitação não encontrada.');
        }

        $this->ok(['devolucao' => DevolucaoPresenter::detalhe(
            $sol,
            $servico->getItens((int)$id),
            $servico->getHistorico((int)$id),
            $this->contexto()
        )]);
    }

    /**
     * GET /api/app/v1/conta/pedidos/{codigo}/devolucao
     *
     * O pedido pode ser devolvido? Devolve também os itens elegíveis, para o
     * app montar o formulário sem uma segunda chamada.
     */
    public function elegibilidade(string $codigo = ''): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $modelo = new Order();
        $pedido = $modelo->findByCode(trim($codigo), (int)$this->clienteId);

        if (!$pedido) {
            $this->falha(404, 'nao_encontrado', 'Pedido não encontrado.');
        }

        $entregue = ($pedido['status_pedido'] ?? '') === 'entregue';
        $prazo    = DevolucaoService::PRAZO_CDC_DIAS;

        // O prazo do CDC (Art. 49) conta da ENTREGA, e a data real é o primeiro
        // evento 'entregue' em pedido_historico — a tabela `pedidos` não tem
        // coluna de entrega. É a mesma referência que
        // CustomerDevolucaoController::novaForm() usa (:62-70); calcular a
        // partir de `enviado_em` encurtaria o prazo legal do cliente.
        $referencia = $this->dataDeEntrega((int)$pedido['id']) ?? ($pedido['atualizado_em'] ?? null);
        $diasDesde  = $referencia ? (int)floor((time() - strtotime((string)$referencia)) / 86400) : null;
        $dentroDoPrazo = $diasDesde === null || $diasDesde <= $prazo;

        $motivo = !$entregue
            ? 'Só é possível solicitar depois que o pedido for entregue.'
            : (!$dentroDoPrazo ? "O prazo de {$prazo} dias para solicitar já passou." : null);

        $this->ok([
            'pedido_id'     => (int)$pedido['id'],
            'pode'          => $entregue && $dentroDoPrazo,
            'motivo'        => $motivo,
            'prazo_dias'    => $prazo,
            'dias_desde'    => $diasDesde,
            'entregue_em'   => $referencia ? date(DATE_ATOM, strtotime((string)$referencia)) : null,
            'itens'         => array_values(array_map(static fn(array $i) => [
                'pedido_item_id' => (int)$i['id'],
                'nome'           => $i['produto_nome'] ?? $i['nome_produto'] ?? '',
                'quantidade'     => (int)($i['quantidade'] ?? 1),
                'preco'          => PrecoPresenter::dec($i['preco_unitario'] ?? 0),
            ], $modelo->getItemsWithVariacoes((int)$pedido['id']))),
        ]);
    }

    /**
     * POST /api/app/v1/conta/devolucoes
     * Corpo: { pedido_id, tipo, motivo_id, itens: [{pedido_item_id, quantidade}], descricao? }
     */
    public function criar(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['pedido_id', 'tipo', 'motivo_id', 'itens']);
        $this->liberarSessao();

        $tipo = (string)$corpo['tipo'];
        if (!in_array($tipo, ['devolucao', 'troca'], true)) {
            $this->falha(422, 'dados_invalidos', 'Tipo deve ser devolucao ou troca.');
        }

        $itens = array_values(array_filter(array_map(static fn($i) => [
            'pedido_item_id' => (int)($i['pedido_item_id'] ?? 0),
            'quantidade'     => max(1, (int)($i['quantidade'] ?? 1)),
        ], (array)$corpo['itens']), static fn(array $i) => $i['pedido_item_id'] > 0));

        if (!$itens) {
            $this->falha(422, 'dados_invalidos', 'Selecione ao menos um item.');
        }

        try {
            $r = (new DevolucaoService())->criar(
                (int)$this->clienteId,
                (int)$corpo['pedido_id'],
                $tipo,
                (int)$corpo['motivo_id'],
                $itens,
                (string)($corpo['descricao'] ?? ''),
                // Mídias enviadas antes por /devolucoes/midias. Motivos como
                // "produto avariado" exigem foto, e sem isso o service recusa.
                $this->midiasDoCorpo($corpo['midias'] ?? [])
            );
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'criar_devolucao']);
            $this->falha(500, 'falha_devolucao', 'Não foi possível abrir a solicitação.');
        }

        if (empty($r['ok'])) {
            $this->falha(422, 'nao_permitido', $r['msg'] ?? 'Não foi possível abrir a solicitação.');
        }

        AppLog::info('Devolução aberta pelo app', [
            'pedido_id' => (int)$corpo['pedido_id'],
            'tipo'      => $tipo,
        ]);

        $this->ok($r, 201);
    }

    /**
     * POST /api/app/v1/conta/devolucoes/midias   (multipart: midia)
     *
     * Sobe UMA mídia e devolve o nome do arquivo. O app envia uma por vez e
     * junta os nomes no `midias[]` de /devolucoes.
     *
     * Duas etapas em vez de um multipart gigante de propósito: no celular a
     * conexão cai, e perder um formulário inteiro com cinco fotos porque a
     * quinta falhou é a pior experiência possível. Assim cada foto tem seu
     * próprio destino e sua própria tentativa.
     *
     * Regras copiadas de CustomerDevolucaoController::criar() (:97-124):
     * 10 MB, extensões permitidas, e validação de MIME real para imagem —
     * checar só a extensão deixaria passar um .php renomeado.
     */
    public function enviarMidia(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $arquivo = $_FILES['midia'] ?? null;
        if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->falha(422, 'arquivo_ausente', 'Nenhuma mídia recebida.');
        }

        if (($arquivo['size'] ?? 0) > 10 * 1024 * 1024) {
            $this->falha(422, 'arquivo_grande', 'A mídia excede o limite de 10 MB.');
        }

        $extensoes = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'm4v'];
        $ext = strtolower(pathinfo((string)$arquivo['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $extensoes, true)) {
            $this->falha(422, 'formato_invalido', 'Envie imagem (JPG, PNG, WEBP) ou vídeo (MP4, MOV).');
        }

        // MIME real para imagem — extensão é dado do cliente, não prova nada.
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, (string)$arquivo['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $this->falha(422, 'formato_invalido', 'O arquivo não é uma imagem válida.');
            }
        }

        $dir = ROOT_PATH . '/uploads/devolucoes/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->falha(500, 'falha_upload', 'Não foi possível preparar o envio.');
        }

        $nome = 'dev_' . bin2hex(random_bytes(8)) . '.' . $ext;

        if (!move_uploaded_file((string)$arquivo['tmp_name'], $dir . $nome)) {
            AppLog::error('Falha ao mover mídia de devolução', ['nome' => $nome]);
            $this->falha(500, 'falha_upload', 'Não foi possível salvar a mídia.');
        }

        $this->ok([
            'arquivo' => $nome,
            'url'     => rtrim(UPLOAD_URL, '/') . '/devolucoes/' . $nome,
            'tipo'    => in_array($ext, ['mp4', 'mov', 'm4v'], true) ? 'video' : 'imagem',
        ], 201);
    }

    /**
     * Aceita só nomes que este endpoint gerou. Sem essa checagem, o cliente
     * poderia mandar "../../config/.env" e anexá-lo à solicitação.
     *
     * @param mixed $midias
     * @return string[]
     */
    private function midiasDoCorpo($midias): array
    {
        if (!is_array($midias)) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', $midias),
            static fn(string $n) => (bool)preg_match('/^dev_[0-9a-f]{16}\.(jpg|jpeg|png|webp|mp4|mov|m4v)$/i', $n)
        ));
    }

    /**
     * Data real da entrega — primeiro evento 'entregue' no histórico do pedido.
     * É a referência do prazo do CDC.
     */
    private function dataDeEntrega(int $pedidoId): ?string
    {
        try {
            $st = $this->db()->prepare(
                "SELECT criado_em FROM pedido_historico
                 WHERE pedido_id = :p AND status_novo = 'entregue'
                 ORDER BY criado_em ASC LIMIT 1"
            );
            $st->execute([':p' => $pedidoId]);
            $valor = $st->fetchColumn();
            return $valor ? (string)$valor : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * POST /api/app/v1/conta/devolucoes/{id}/cancelar
     */
    public function cancelar(string $id = '0'): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $r = (new DevolucaoService())->cancelarPorCliente((int)$id, (int)$this->clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'cancelar_devolucao']);
            $this->falha(500, 'falha_devolucao', 'Não foi possível cancelar.');
        }

        if (empty($r['ok'])) {
            $this->falha(422, 'nao_permitido', $r['msg'] ?? 'Não foi possível cancelar.');
        }

        $this->ok($r);
    }

    /**
     * POST /api/app/v1/conta/devolucoes/{id}/rastreio   Corpo: { codigo }
     * O cliente informa o código de postagem depois de despachar.
     */
    public function rastreio(string $id = '0'): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['codigo']);
        $this->liberarSessao();

        try {
            $r = (new DevolucaoService())->informarRastreio(
                (int)$id,
                (int)$this->clienteId,
                trim((string)$corpo['codigo'])
            );
        } catch (\Throwable $e) {
            $this->falha(500, 'falha_devolucao', 'Não foi possível registrar o código.');
        }

        if (empty($r['ok'])) {
            $this->falha(422, 'nao_permitido', $r['msg'] ?? 'Código não aceito.');
        }

        $this->ok($r);
    }
}
