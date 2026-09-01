<?php
// app/controllers/AppCepController.php
// CEP ativo e frete na vitrine.
//
// Espelha o comportamento da loja (CepController + FreteVitrineController):
// o visitante informa um CEP, todo produto passa a mostrar frete e prazo, e ao
// entrar na conta o CEP do endereço principal assume o lugar.
//
// A ORDEM DE PRIORIDADE é a mesma de CepController::getCepAtivo():
//
//   1. endereço principal do cliente logado
//   2. CEP do dispositivo   (na web: o cookie `ec_cep`)
//   3. nenhum
//
// O CEP do dispositivo NÃO é apagado ao logar. É o que a web faz com o cookie,
// e é o comportamento certo: se a pessoa sair da conta, o CEP que ela digitou
// continua valendo em vez de o site voltar a fingir que não sabe para onde
// entrega. Como a conta vence na ordem, não há ambiguidade enquanto logada.

class AppCepController extends AppApiController
{
    /**
     * GET /api/app/v1/cep
     * Resolve o CEP ativo. Público: é justamente o visitante anônimo que mais
     * precisa dele.
     */
    public function ativo(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $this->ok(['cep' => $this->resolver()]);
    }

    /**
     * GET /api/app/v1/cep/{cep}
     *
     * Só CONSULTA — não salva nada. É o que preenche rua, bairro, cidade e UF
     * no formulário de endereço, para a pessoa digitar 8 dígitos em vez de
     * quatro campos.
     *
     * Separado de `salvar` de propósito: aquele grava o CEP do dispositivo e
     * muda o frete que aparece na vitrine inteira. Quem está preenchendo um
     * endereço de presente para outra cidade não quer que a vitrine passe a
     * calcular frete para lá.
     *
     * Público: cadastrar endereço no checkout de visitante também passa por
     * aqui, e o ViaCEP não é segredo de ninguém.
     */
    public function consultar(string $cep = ''): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $digitos = preg_replace('/\D/', '', $cep) ?? '';

        if (strlen($digitos) !== 8) {
            $this->falha(422, 'cep_invalido', 'Informe um CEP com 8 dígitos.');
        }

        // consultarViaCep() já tem cache de 24h: dois clientes do mesmo bairro
        // não geram duas chamadas externas.
        $dados = CepController::consultarViaCep($digitos);

        if (!$dados) {
            $this->falha(404, 'cep_nao_encontrado',
                'Não encontramos esse CEP. Confira ou preencha o endereço à mão.');
        }

        // `bairro` volta vazio em cidade com CEP único — e a coluna é NOT NULL.
        // Mandar o campo vazio é honesto: o formulário pede que a pessoa
        // complete, em vez de o salvamento estourar depois.
        $this->ok(['endereco' => [
            'cep'        => substr($digitos, 0, 5) . '-' . substr($digitos, 5),
            'logradouro' => trim((string)($dados['logradouro'] ?? '')),
            'bairro'     => trim((string)($dados['bairro'] ?? '')),
            'cidade'     => trim((string)($dados['localidade'] ?? '')),
            'estado'     => strtoupper(trim((string)($dados['uf'] ?? ''))),
        ]]);
    }

    /**
     * POST /api/app/v1/cep    Corpo: { cep, salvar_endereco? }
     */
    public function salvar(): void
    {
        $this->bootPublico();
        $corpo = $this->exigirCampos(['cep']);

        $cep = preg_replace('/\D/', '', (string)$corpo['cep']);

        if (strlen((string)$cep) !== 8) {
            $this->falha(422, 'cep_invalido', 'Informe um CEP com 8 dígitos.');
        }

        // O ViaCEP é a validação de verdade: 99999999 tem 8 dígitos e não
        // existe. Sem esta consulta o cliente só descobriria no checkout.
        $dados = CepController::consultarViaCep($cep);
        if (!$dados) {
            $this->falha(422, 'cep_nao_encontrado', 'CEP não encontrado.');
        }

        try {
            $this->db()->prepare(
                "UPDATE app_dispositivos SET cep = :cep, cep_em = NOW() WHERE id = :id"
            )->execute([':cep' => $cep, ':id' => (int)$this->dispositivo['id']]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'salvar_cep']);
            $this->falha(500, 'falha_cep', 'Não foi possível salvar seu CEP.');
        }

        // Cliente logado e sem endereço nenhum: o CEP vira o primeiro endereço,
        // igual a CepController::salvarEnderecoLogado(). Só sob pedido
        // explícito — criar endereço à revelia bagunçaria o checkout.
        if ($this->clienteId && !empty($corpo['salvar_endereco'])) {
            $this->criarEnderecoDoCep($cep, $dados);
        }

        $this->liberarSessao();

        $this->ok(['cep' => $this->resolver()]);
    }

    /**
     * DELETE /api/app/v1/cep
     */
    public function remover(): void
    {
        $this->bootPublico();

        try {
            $this->db()->prepare(
                "UPDATE app_dispositivos SET cep = NULL, cep_em = NULL WHERE id = :id"
            )->execute([':id' => (int)$this->dispositivo['id']]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'remover_cep']);
        }

        $this->liberarSessao();

        $this->ok(['cep' => $this->resolver()]);
    }

    /**
     * GET /api/app/v1/frete/produto?produto_id=&cep=&subtotal_atual=
     *
     * Uma unidade do produto, com cache e fallback — o mesmo motor da vitrine
     * da web (FreteVitrineService), inclusive o CTA de "adicione mais X e ganhe
     * frete grátis". Sem `cep` na query, usa o CEP ativo do cliente: é isso que
     * faz o frete aparecer sozinho ao abrir o produto.
     */
    public function produto(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        $produtoId = (int)$this->query('produto_id', 0);
        if ($produtoId <= 0) {
            $this->falha(422, 'dados_invalidos', 'Informe produto_id.');
        }

        $cep = preg_replace('/\D/', '', (string)$this->query('cep', ''));
        if (strlen((string)$cep) !== 8) {
            $ativo = $this->resolver();
            $cep   = $ativo['cep'] ?? null;
        }

        if (!$cep) {
            // Não é erro: é o estado normal de quem ainda não informou CEP. A
            // tela mostra o campo em vez de uma mensagem vermelha.
            $this->ok(['tem_cep' => false, 'opcoes' => [], 'destaques' => []]);
        }

        $produto = (new Product())->find($produtoId);
        if (!$produto) {
            $this->falha(404, 'nao_encontrado', 'Produto não encontrado.');
        }

        try {
            $res = (new FreteVitrineService())->cotar([
                'cep_destino'      => $cep,
                'itens'            => [$this->itemDoProduto($produto)],
                'valor_mercadoria' => $this->precoDoProduto($produto),
                'produto_id'       => $produtoId,
                'cta'              => [
                    'subtotal_atual' => (float)$this->query('subtotal_atual', 0),
                    'preco_produto'  => $this->precoDoProduto($produto),
                ],
            ]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'frete_produto', 'produto_id' => $produtoId]);
            $this->falha(502, 'falha_frete', 'Não foi possível calcular o frete agora.');
        }

        if (empty($res['ok'])) {
            $this->falha(422, 'falha_frete', (string)($res['erro'] ?? 'Não foi possível calcular o frete.'));
        }

        $this->ok(FretePresenter::vitrine($res, $cep));
    }

    /* =================================================================
       Internos
       ================================================================= */

    /**
     * O CEP ativo, na ordem de prioridade documentada no topo.
     * @return array{tem_cep:bool,cep:?string,cep_fmt:?string,localidade:?string,uf:?string,origem:?string}
     */
    private function resolver(): array
    {
        // 1. Endereço principal do cliente logado.
        if ($this->clienteId) {
            try {
                $end = (new Endereco())->principal((int)$this->clienteId);
            } catch (\Throwable $e) {
                $end = null;
            }

            if ($end && !empty($end['cep'])) {
                $cep = preg_replace('/\D/', '', (string)$end['cep']);
                return [
                    'tem_cep'    => true,
                    'cep'        => $cep,
                    'cep_fmt'    => CepController::formatCep($cep),
                    'localidade' => $end['cidade'] ?? null,
                    'uf'         => $end['estado'] ?? null,
                    'origem'     => 'endereco',
                    'editavel'   => false, // muda trocando o endereço, não o CEP
                ];
            }
        }

        // 2. CEP do dispositivo.
        $cep = $this->cepDoDispositivo();
        if ($cep !== null) {
            // Localidade vem do ViaCEP com cache de 24h; se a consulta falhar,
            // o CEP ainda vale para cotar frete — só o rótulo fica sem cidade.
            $dados = CepController::consultarViaCep($cep) ?? [];
            return [
                'tem_cep'    => true,
                'cep'        => $cep,
                'cep_fmt'    => CepController::formatCep($cep),
                'localidade' => $dados['localidade'] ?? null,
                'uf'         => $dados['uf'] ?? null,
                'origem'     => 'dispositivo',
                'editavel'   => true,
            ];
        }

        // 3. Nenhum.
        return [
            'tem_cep'    => false,
            'cep'        => null,
            'cep_fmt'    => null,
            'localidade' => null,
            'uf'         => null,
            'origem'     => null,
            'editavel'   => true,
        ];
    }

    /**
     * O CEP gravado neste dispositivo, ou null.
     *
     * Vai ao banco em vez de ler `$this->dispositivo['cep']`: aquele array é
     * uma lista fixa de campos montada a partir do JOIN que valida o token
     * (AppApiController::resolverDispositivo) e não inclui o CEP. Ler de lá
     * devolveria null sempre — inclusive logo depois de salvar.
     *
     * É uma consulta por chave primária, e só nos endpoints de CEP e frete.
     */
    private function cepDoDispositivo(): ?string
    {
        if (empty($this->dispositivo['id'])) {
            return null;
        }

        try {
            $st = $this->db()->prepare("SELECT cep FROM app_dispositivos WHERE id = :id LIMIT 1");
            $st->execute([':id' => (int)$this->dispositivo['id']]);
            $cep = preg_replace('/\D/', '', (string)($st->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            return null;
        }

        return strlen((string)$cep) === 8 ? $cep : null;
    }

    private function criarEnderecoDoCep(string $cep, array $via): void
    {
        try {
            $modelo = new Endereco();
            $jaTem  = $modelo->listarPorCliente((int)$this->clienteId);
            if ($jaTem) {
                return; // já tem endereço: não é papel do CEP criar outro
            }

            $modelo->salvar([
                'cliente_id'        => (int)$this->clienteId,
                'nome_destinatario' => (string)(Session::get('cliente_nome') ?? ''),
                'cep'               => $cep,
                'logradouro'        => $via['logradouro'] ?? '',
                'bairro'            => $via['bairro'] ?? '',
                'cidade'            => $via['localidade'] ?? '',
                'estado'            => $via['uf'] ?? '',
                'numero'            => 'S/N',
                'principal'         => 1,
            ]);
        } catch (\Throwable $e) {
            // Falhar aqui não invalida o CEP, que é o que a pessoa pediu.
            AppLog::exception($e, ['acao' => 'endereco_do_cep']);
        }
    }

    private function precoDoProduto(array $p): float
    {
        return (float)($p['preco_final'] ?? $p['preco_venda'] ?? $p['preco'] ?? $p['valor'] ?? 0);
    }

    /** Mesmo mapeamento de FreteVitrineController::itemDoProduto(). */
    private function itemDoProduto(array $p): array
    {
        $pesoG = (int)round((float)($p['peso_kg'] ?? 0) * 1000);
        if ($pesoG <= 0) {
            $pesoG = (int)($p['peso_g'] ?? 500); // fallback de peso
        }

        return [
            'produto_id'     => (int)($p['id'] ?? 0),
            'quantidade'     => 1,
            'valor'          => $this->precoDoProduto($p),
            'peso_g'         => $pesoG,
            'altura_cm'      => (float)($p['altura_cm'] ?? 0),
            'largura_cm'     => (float)($p['largura_cm'] ?? 0),
            'comprimento_cm' => (float)($p['comprimento_cm'] ?? 0),
            'categoria_id'   => $p['categoria_id'] ?? null,
            'marca_id'       => $p['marca_id'] ?? null,
        ];
    }
}
