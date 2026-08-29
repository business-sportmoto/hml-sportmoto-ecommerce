<?php
declare(strict_types=1);

/**
 * admin/controllers/AdminPagamentoConfigController.php
 *
 * Duas telas de configuração de pagamento:
 *   /admin/pagamentos/formas       taxa, desconto, parcelamento (política da loja)
 *   /admin/pagamentos/adquirentes  credenciais das adquirentes
 *
 * PERMISSÕES — seguem o mapa da §4.6 do CLAUDE.md:
 *   Formas de pagamento → super, gerente
 *       Mexe em taxa e desconto: impacto financeiro direto, mesma régua de
 *       Promoções e Cupons.
 *   Adquirentes         → super
 *       Mexe em credencial de recebimento. Mesma régua de Bling e Tray:
 *       quem configura para onde o dinheiro vai é só o super.
 *
 * O controller fica fino de propósito: validação e regra moram nos models
 * (PagamentoMetodo, PagamentoAdquirente), conforme as regras do projeto.
 */
class AdminPagamentoConfigController extends Controller
{
    private PagamentoMetodo $metodos;
    private PagamentoAdquirente $adquirentes;

    public function __construct()
    {
        AuthHelper::requireAdmin();
        $this->metodos     = new PagamentoMetodo();
        $this->adquirentes = new PagamentoAdquirente();
    }

    // =========================================================================
    // FORMAS DE PAGAMENTO
    // =========================================================================

    /** GET /admin/pagamentos/formas */
    public function formas(): void
    {
        AuthHelper::requireAdminLevel('super', 'gerente');

        $metodos = $this->metodos->listar();

        // Simulação de R$ 500 ao lado de cada método: o lojista vê o efeito
        // da regra antes de salvar, em vez de descobrir no checkout.
        $simulacoes = [];
        foreach ($metodos as $m) {
            $simulacoes[$m['codigo']] = PagamentoMetodo::simular($m, 50000);
        }

        SeoHelper::setTitle('Formas de pagamento');
        $this->render('pagamentos/formas', [
            'metodos'    => $metodos,
            'simulacoes' => $simulacoes,
        ], 'admin');
    }

    /** POST /admin/pagamentos/formas/salvar */
    public function salvarForma(): void
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->json(['ok' => false, 'msg' => 'Forma de pagamento inválida.']);
        }

        $atual = $this->metodos->find($id);
        if (!$atual) {
            $this->json(['ok' => false, 'msg' => 'Forma de pagamento não encontrada.']);
        }

        $dados = [
            'nome'                 => SecurityHelper::sanitizeString($_POST['nome'] ?? $atual['nome']),
            'ativo'                => !empty($_POST['ativo']) ? 1 : 0,
            'ordem'                => (int) ($_POST['ordem'] ?? $atual['ordem']),
            'taxa_percentual'      => self::decimal($_POST['taxa_percentual'] ?? 0),
            'taxa_fixa_centavos'   => self::centavos($_POST['taxa_fixa'] ?? 0),
            'desconto_percentual'  => self::decimal($_POST['desconto_percentual'] ?? 0),
            'desconto_max_percent' => self::decimal($_POST['desconto_max_percent'] ?? 0),
            'parcelas_max'         => (int) ($_POST['parcelas_max'] ?? 1),
            'parcelas_sem_juros'   => (int) ($_POST['parcelas_sem_juros'] ?? 1),
            'parcela_min_centavos' => self::centavos($_POST['parcela_min'] ?? 0),
            'valor_min_centavos'   => self::centavos($_POST['valor_min'] ?? 0),
            'valor_max_centavos'   => trim((string) ($_POST['valor_max'] ?? '')) === ''
                                      ? null : self::centavos($_POST['valor_max']),
        ];

        $erros = PagamentoMetodo::validar($dados);
        if ($erros) {
            $this->json(['ok' => false, 'msg' => $erros[0], 'erros' => $erros]);
        }

        $this->metodos->salvar($id, $dados);

        // [LOG] audit: taxa e desconto mexem em receita. Quem mudou e para
        // quanto precisa ficar registrado.
        LogService::audit('Forma de pagamento alterada', [
            'metodo'      => $atual['codigo'],
            'por'         => AuthHelper::usuarioId(),
            'antes'       => self::resumoComercial($atual),
            'depois'      => self::resumoComercial($dados),
        ]);

        $novo = $this->metodos->find($id);
        $this->json([
            'ok'        => true,
            'msg'       => 'Forma de pagamento atualizada.',
            'simulacao' => PagamentoMetodo::simular($novo, 50000),
        ]);
    }

    /** POST /admin/pagamentos/formas/simular — pré-visualização sem salvar */
    public function simularForma(): void
    {
        AuthHelper::requireAdminLevel('super', 'gerente');
        $this->verifyCsrf();

        $valor = self::centavos($_POST['valor'] ?? 50000);
        if ($valor <= 0) $valor = 50000;

        $this->json(['ok' => true, 'parcelas' => PagamentoMetodo::simular([
            'parcelas_max'         => (int) ($_POST['parcelas_max'] ?? 1),
            'parcelas_sem_juros'   => (int) ($_POST['parcelas_sem_juros'] ?? 1),
            'parcela_min_centavos' => self::centavos($_POST['parcela_min'] ?? 0),
            'taxa_percentual'      => self::decimal($_POST['taxa_percentual'] ?? 0),
        ], $valor)]);
    }

    // =========================================================================
    // ADQUIRENTES
    // =========================================================================

    /** GET /admin/pagamentos/adquirentes */
    public function adquirentes(): void
    {
        AuthHelper::requireAdminLevel('super');

        $lista = $this->adquirentes->listarParaTela();

        // Onde cada adquirente é usada. Desativar uma que está num fluxo
        // publicado deixa o roteamento sem saída — a tela avisa antes.
        foreach ($lista as &$a) {
            $a['fluxos'] = $this->adquirentes->emUsoPorFluxo($a['codigo']);
        }
        unset($a);

        SeoHelper::setTitle('Adquirentes');
        $this->render('pagamentos/adquirentes', [
            'adquirentes' => $lista,
            'suportadas'  => PagamentoAdquirente::SUPORTADAS,
        ], 'admin');
    }

    /** POST /admin/pagamentos/adquirentes/salvar */
    public function salvarAdquirente(): void
    {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $g  = $id > 0 ? $this->adquirentes->find($id) : null;
        if (!$g) {
            $this->json(['ok' => false, 'msg' => 'Adquirente não encontrada.']);
        }

        // Salva pelo MESMO esquema que a tela desenhou. Antes a lista de
        // campos era fixa aqui, e diferente da lista da view — foi assim que
        // a chave publica do Mercado Pago nao tinha onde ser digitada nem
        // como ser gravada.
        $dados = [
            'nome'    => SecurityHelper::sanitizeString($_POST['nome'] ?? $g['nome']),
            'sandbox' => !empty($_POST['sandbox']) ? 1 : 0,
        ];

        $trocouSegredo = [];

        foreach (PagamentoAdquirente::camposDe($g['codigo']) as $campo) {
            $col = $campo['coluna'];
            if (!array_key_exists($col, $_POST)) continue;

            if ($campo['tipo'] === 'segredo') {
                // Segredo vai CRU: sanitizeString escaparia caracteres e
                // corromperia a chave. A protecao dele e nunca voltar a tela.
                // Vazio significa "manter o atual" — quem decide e o model.
                $dados[$col] = (string) $_POST[$col];
                if (trim($dados[$col]) !== '') $trocouSegredo[] = $col;
            } else {
                $dados[$col] = trim((string) $_POST[$col]);
            }
        }

        // Opcoes livres da adquirente, preservando o que ja existia.
        $extras = PagamentoAdquirente::extrasDe($g['codigo']);
        if ($extras && isset($_POST['extra']) && is_array($_POST['extra'])) {
            $cfg = json_decode((string) ($g['config_extra'] ?? ''), true) ?: [];

            foreach ($extras as $ex) {
                if (!array_key_exists($ex['chave'], $_POST['extra'])) continue;
                $v = $_POST['extra'][$ex['chave']];

                // So aceita valor previsto: um select nao pode virar campo livre.
                if ($ex['tipo'] === 'select') {
                    if (!isset($ex['opcoes'][$v])) continue;
                    $cfg[$ex['chave']] = (string) $v;
                } else {
                    $cfg[$ex['chave']] = max(0, (int) $v);
                }
            }

            $dados['config_extra'] = json_encode($cfg, JSON_UNESCAPED_UNICODE);
        }

        $this->adquirentes->salvar($id, $dados);

        // [LOG] audit: NUNCA o valor das credenciais — só o fato de terem
        // sido trocadas, para dar rastro sem virar vazamento.
        LogService::audit('Adquirente configurada', [
            'adquirente' => $g['codigo'],
            'por'        => AuthHelper::usuarioId(),
            'sandbox'    => $dados['sandbox'],
            // Quais segredos mudaram, nunca o valor deles.
            'trocou'     => $trocouSegredo,
        ]);

        $this->json(['ok' => true, 'msg' => 'Adquirente atualizada.']);
    }

    /**
     * POST /admin/pagamentos/adquirentes/logo
     *
     * Sobe o logo da adquirente para o Cloudflare R2.
     *
     * Passa pelo ImageUploadService, nao pelo R2MediaService direto: e ele que
     * valida o $_FILES, confere o MIME real, redimensiona e converte para
     * WebP. Falar com o R2 direto daqui pularia tudo isso e deixaria a porta
     * aberta para subir qualquer arquivo com extensao de imagem.
     */
    public function logoAdquirente(): void
    {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $g  = $id > 0 ? $this->adquirentes->find($id) : null;
        if (!$g) {
            $this->json(['ok' => false, 'msg' => 'Adquirente não encontrada.']);
        }

        // Remover: veio o pedido explicito de limpar.
        if (!empty($_POST['remover'])) {
            $img = ImageUploadService::fromEnv();
            $img->delete($g['logo_url'] ?? null);
            $this->adquirentes->salvar($id, ['logo_url' => null]);

            LogService::audit('Logo da adquirente removido', [
                'adquirente' => $g['codigo'], 'por' => AuthHelper::usuarioId(),
            ]);

            $this->json(['ok' => true, 'msg' => 'Logo removido.', 'url' => null]);
        }

        if (empty($_FILES['logo']['name'] ?? '')) {
            $this->json(['ok' => false, 'msg' => 'Escolha um arquivo de imagem.']);
        }

        try {
            $img = ImageUploadService::fromEnv();

            // 320px basta: o logo aparece em 44px no card e ~64px no drawer.
            // Guardar maior so gasta banda de quem abre a tela.
            $url = $img->uploadUnica($_FILES['logo'], 'adquirentes', 320);

            if (!$url) {
                $this->json(['ok' => false, 'msg' => 'Não foi possível ler a imagem.']);
            }

            // O anterior sai depois do novo entrar: se o upload falhar, a
            // adquirente continua com o logo que tinha.
            $anterior = $g['logo_url'] ?? null;
            $this->adquirentes->salvar($id, ['logo_url' => $url]);
            if ($anterior) $img->delete($anterior);

            LogService::audit('Logo da adquirente atualizado', [
                'adquirente' => $g['codigo'], 'por' => AuthHelper::usuarioId(),
            ]);

            $this->json(['ok' => true, 'msg' => 'Logo atualizado.', 'url' => $url]);

        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', [
                'acao' => 'logo_adquirente', 'adquirente' => $g['codigo'],
            ]);
            $this->json(['ok' => false, 'msg' => 'Falha ao enviar a imagem. Tente outro arquivo.']);
        }
    }

    /** POST /admin/pagamentos/adquirentes/alternar */
    public function alternarAdquirente(): void
    {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $id     = (int) ($_POST['id'] ?? 0);
        $ativar = !empty($_POST['ativar']);
        $g      = $this->adquirentes->find($id);

        if (!$g) {
            $this->json(['ok' => false, 'msg' => 'Adquirente não encontrada.']);
        }

        // Desativar algo que está num fluxo publicado quebra o roteamento.
        // Exige confirmação explícita em vez de recusar: pode ser justamente
        // o que o lojista quer (adquirente caiu, tirar do ar agora).
        if (!$ativar) {
            $fluxos = $this->adquirentes->emUsoPorFluxo($g['codigo']);
            if ($fluxos && empty($_POST['confirmado'])) {
                $nomes = implode(', ', array_column($fluxos, 'nome'));
                $this->json([
                    'ok'      => false,
                    'confirmar' => true,
                    'msg'     => "Esta adquirente está em uso nos fluxos: {$nomes}. "
                               . 'Desativar pode interromper pagamentos. Confirma?',
                ]);
            }
        }

        $r = $this->adquirentes->alternarAtivo($id, $ativar);

        if ($r['ok']) {
            LogService::audit('Adquirente ' . ($ativar ? 'ativada' : 'desativada'), [
                'adquirente' => $g['codigo'],
                'por'        => AuthHelper::usuarioId(),
            ]);
        }

        $this->json($r);
    }

    /**
     * POST /admin/pagamentos/adquirentes/testar
     * Testa a credencial de verdade contra a adquirente.
     */
    public function testarAdquirente(): void
    {
        AuthHelper::requireAdminLevel('super');
        $this->verifyCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $g  = $this->adquirentes->find($id);
        if (!$g) {
            $this->json(['ok' => false, 'msg' => 'Adquirente não encontrada.']);
        }

        try {
            $r = match ($g['codigo']) {
                'safrapay' => $this->testarSafra(),
                'fake'     => ['ok' => true, 'msg' => 'Adapter de teste — sempre disponível.'],
                default    => ['ok' => false, 'msg' => 'Sem teste implementado para esta adquirente.'],
            };
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'pagamento', [
                'adquirente' => $g['codigo'], 'acao' => 'testar_credencial',
            ]);
            $r = ['ok' => false, 'msg' => 'Falha ao testar: ' . $e->getMessage()];
        }

        $this->json($r);
    }

    /**
     * Autentica na Safra. A mensagem distingue os três desfechos, porque são
     * problemas completamente diferentes: credencial errada, bloqueio de
     * rede/WAF, ou falta de configuração.
     */
    private function testarSafra(): array
    {
        $client = new SafraPayClient();
        if (!$client->configurado()) {
            return ['ok' => false, 'msg' => 'Credenciais ausentes no .env (SAFRAPAY_MERCHANT_TOKEN/ID).'];
        }

        SafraPayClient::limparTokenCache();
        try {
            $client->accessToken();
            return ['ok' => true, 'msg' => 'Conexão OK — ambiente ' . $client->ambiente() . '.'];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '403')) {
                return ['ok' => false, 'msg' =>
                    'HTTP 403 — o IP deste servidor está bloqueado no WAF da adquirente. '
                    . 'Não é problema de credencial: peça liberação do IP de saída.'];
            }
            if (str_contains($msg, '401')) {
                return ['ok' => false, 'msg' => 'HTTP 401 — credencial inválida ou expirada.'];
            }
            return ['ok' => false, 'msg' => $msg];
        }
    }

    // =========================================================================

    /** "1.99" e "1,99" viram 1.99. */
    private static function decimal($v): float
    {
        return (float) str_replace(',', '.', trim((string) $v));
    }

    /** "12,50" vira 1250 centavos. Evita float em dinheiro. */
    private static function centavos($v): int
    {
        $s = trim((string) $v);
        if ($s === '') return 0;
        $s = str_replace(['.', ' '], '', $s);   // separador de milhar
        $s = str_replace(',', '.', $s);
        return (int) round(((float) $s) * 100);
    }

    /** Só o que interessa no log de auditoria comercial. */
    private static function resumoComercial(array $m): array
    {
        return [
            'taxa'         => $m['taxa_percentual']      ?? null,
            'desconto'     => $m['desconto_percentual']  ?? null,
            'desconto_max' => $m['desconto_max_percent'] ?? null,
            'parcelas_max' => $m['parcelas_max']         ?? null,
            'sem_juros'    => $m['parcelas_sem_juros']   ?? null,
            'ativo'        => $m['ativo']                ?? null,
        ];
    }
}
