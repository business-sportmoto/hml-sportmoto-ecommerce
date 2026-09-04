<?php
declare(strict_types=1);

/**
 * app/models/PagamentoAdquirente.php
 *
 * Persistência de pgto_gateways — o cadastro das adquirentes e suas
 * credenciais.
 *
 * SEGREDOS: api_key e webhook_secret nunca saem daqui em claro para a tela.
 * `listarParaTela()` devolve apenas se o campo ESTÁ preenchido, não o valor.
 * Um segredo que aparece num input HTML acaba em cache de navegador, em
 * screenshot de suporte e no histórico do formulário.
 *
 * Gravação é parcial de propósito: campo enviado vazio significa "não mexer",
 * não "apagar". Sem isso, abrir a tela e salvar sem tocar em nada limparia as
 * credenciais — e derrubaria os pagamentos.
 */
class PagamentoAdquirente extends Model
{
    protected string $table = 'pgto_gateways';

    /** Campos tratados como segredo: só gravados quando vêm preenchidos. */
    private const SEGREDOS = ['api_key', 'front_api_key', 'webhook_secret', 'webhook_public_key'];

    /**
     * O QUE CADA ADQUIRENTE PRECISA, campo a campo.
     *
     * POR QUE ISTO EXISTE:
     *   Um formulario fixo servia mal a todo mundo. O Mercado Pago precisa de
     *   chave publica (que o navegador usa para tokenizar) e nao usa
     *   merchant_id; a Safra e o oposto. Mostrar os dois para os dois deixa
     *   metade dos campos sem sentido, e o lojista sem saber quais preencher —
     *   foi assim que a chave publica do Mercado Pago ficou sem lugar na tela
     *   e o checkout de cartao caiu por falta dela.
     *
     *   Declarar aqui mantem a tela, o salvamento e a validacao lendo a MESMA
     *   lista. Adquirente nova = uma entrada aqui, e nada mais.
     *
     * `coluna` é a coluna real em pgto_gateways.
     * `tipo`   texto | segredo | url. Segredo nunca volta preenchido para a tela.
     */
    public const CAMPOS_POR_ADQUIRENTE = [
        'mercadopago' => [
            ['coluna' => 'front_api_key', 'rotulo' => 'Public Key', 'tipo' => 'segredo',
             'obrigatorio' => true,
             'ajuda' => 'Vai para o navegador e tokeniza o cartão. Começa com APP_USR- ou TEST-.'],
            ['coluna' => 'api_key', 'rotulo' => 'Access Token', 'tipo' => 'segredo',
             'obrigatorio' => true,
             'ajuda' => 'Usado no servidor para cobrar. Nunca sai daqui.'],
            ['coluna' => 'webhook_secret', 'rotulo' => 'Segredo do webhook', 'tipo' => 'segredo',
             'ajuda' => 'Assina as notificações. Pegue em Suas integrações > Webhooks.'],
            ['coluna' => 'webhook_endpoint', 'rotulo' => 'URL de notificação', 'tipo' => 'url',
             'ajuda' => 'Cadastre esta URL no painel, marcando o tópico Orders.'],
        ],

        'cielo' => [
            ['coluna' => 'merchant_id', 'rotulo' => 'MerchantId', 'tipo' => 'texto',
             'obrigatorio' => true,
             'ajuda' => 'Identificador da loja na Cielo, no formato UUID.'],
            ['coluna' => 'api_key', 'rotulo' => 'MerchantKey', 'tipo' => 'segredo',
             'obrigatorio' => true,
             'ajuda' => 'Chave de 40 caracteres. Vai no header de toda chamada e nunca sai daqui.'],
            // Sem chave pública: a API 3.0 não tokeniza no navegador. É por
            // isso que rotear cartão para a Cielo passa o PAN pelo servidor.
            ['coluna' => 'webhook_endpoint', 'rotulo' => 'URL de notificação', 'tipo' => 'url',
             'ajuda' => 'Cadastre no painel da Cielo para receber a baixa de Pix e boleto.'],
        ],

        'safrapay' => [
            ['coluna' => 'merchant_id', 'rotulo' => 'Merchant ID', 'tipo' => 'texto',
             'obrigatorio' => true],
            ['coluna' => 'api_key', 'rotulo' => 'Merchant Token', 'tipo' => 'segredo',
             'obrigatorio' => true,
             'ajuda' => 'Trocado por um token de acesso a cada sessão.'],
            ['coluna' => 'webhook_secret', 'rotulo' => 'Segredo do webhook', 'tipo' => 'segredo'],
            ['coluna' => 'webhook_endpoint', 'rotulo' => 'URL de notificação', 'tipo' => 'url'],
        ],

        'cielo' => [
            ['coluna' => 'merchant_id', 'rotulo' => 'MerchantId', 'tipo' => 'texto',
             'obrigatorio' => true, 'ajuda' => 'GUID de 36 caracteres.'],
            ['coluna' => 'api_key', 'rotulo' => 'MerchantKey', 'tipo' => 'segredo',
             'obrigatorio' => true],
            ['coluna' => 'webhook_endpoint', 'rotulo' => 'URL de notificação', 'tipo' => 'url'],
        ],

        'malga' => [
            ['coluna' => 'client_id', 'rotulo' => 'Client ID', 'tipo' => 'texto', 'obrigatorio' => true],
            ['coluna' => 'api_key', 'rotulo' => 'API Key', 'tipo' => 'segredo', 'obrigatorio' => true],
            ['coluna' => 'merchant_id', 'rotulo' => 'Merchant ID', 'tipo' => 'texto'],
            ['coluna' => 'front_client_id', 'rotulo' => 'Client ID do front', 'tipo' => 'texto',
             'ajuda' => 'Usado pelos campos hospedados, no navegador.'],
            ['coluna' => 'front_api_key', 'rotulo' => 'API Key do front', 'tipo' => 'segredo'],
            ['coluna' => 'webhook_secret', 'rotulo' => 'Segredo do webhook', 'tipo' => 'segredo'],
            ['coluna' => 'webhook_endpoint', 'rotulo' => 'URL de notificação', 'tipo' => 'url'],
        ],
    ];

    /** Opções livres, gravadas em config_extra. */
    public const EXTRAS_POR_ADQUIRENTE = [
        'cielo' => [
            ['chave' => 'boleto_provider', 'rotulo' => 'Boleto — banco (Provider)', 'tipo' => 'texto',
             'padrao' => '',
             'ajuda'  => 'Depende do banco contratado com a Cielo (ex.: Bradesco2, Itau2). '
                       . 'Sem isto o boleto é recusado e a mensagem não diz o porquê.'],
            ['chave' => 'boleto_cedente', 'rotulo' => 'Boleto — cedente', 'tipo' => 'texto', 'padrao' => ''],
            ['chave' => 'boleto_cnpj', 'rotulo' => 'Boleto — CNPJ do cedente', 'tipo' => 'texto', 'padrao' => ''],
            ['chave' => 'boleto_dias', 'rotulo' => 'Boleto vence em (dias)', 'tipo' => 'numero', 'padrao' => 3],
            ['chave' => 'boleto_instrucoes', 'rotulo' => 'Boleto — instruções', 'tipo' => 'texto',
             'padrao' => 'Não receber após o vencimento.'],
            ['chave' => 'sop_provider', 'rotulo' => 'Silent Order Post — host do cartão', 'tipo' => 'select',
             'opcoes' => ['braspag' => 'Braspag (pagador.com.br)', 'cielo' => 'Cielo (cieloecommerce.cielo.com.br)'],
             'padrao' => 'braspag',
             'ajuda'  => 'Para onde o navegador manda o cartão ao salvar. No sandbox só o host da '
                       . 'Braspag responde; confirme com a Cielo antes de trocar em produção. '
                       . 'O par OAuth2 vai no .env (CIELO_SOP_CLIENT_ID / CIELO_SOP_CLIENT_SECRET).'],
        ],
        'mercadopago' => [
            ['chave' => 'pix_expira_min', 'rotulo' => 'Pix expira em (min)', 'tipo' => 'numero', 'padrao' => 30],
            ['chave' => 'boleto_dias', 'rotulo' => 'Boleto vence em (dias)', 'tipo' => 'numero', 'padrao' => 3],
            ['chave' => 'tres_ds', 'rotulo' => 'Autenticação 3DS', 'tipo' => 'select',
             'opcoes' => ['never' => 'Desligada', 'on_fraud_risk' => 'Quando houver risco'],
             'padrao' => 'never',
             'ajuda' => 'Ligada, o emissor assume o chargeback de FRAUDE quando autentica '
                      . '(disputa comercial continua sua). Na maioria dos casos ele resolve '
                      . 'sem perguntar nada ao cliente. Quando pede confirmação, o pedido fica '
                      . 'PENDENTE e não é reapresentado em outra adquirente — a tela do desafio '
                      . 'ainda não existe.'],
        ],
    ];

    /**
     * Campos da adquirente. Cai num conjunto genérico quando ela não foi
     * declarada — melhor um formulário aproximado do que nenhum.
     */
    public static function camposDe(string $codigo): array
    {
        return self::CAMPOS_POR_ADQUIRENTE[strtolower($codigo)] ?? [
            ['coluna' => 'merchant_id', 'rotulo' => 'Merchant ID', 'tipo' => 'texto'],
            ['coluna' => 'client_id', 'rotulo' => 'Client ID', 'tipo' => 'texto'],
            ['coluna' => 'api_key', 'rotulo' => 'Chave de API', 'tipo' => 'segredo'],
            ['coluna' => 'webhook_secret', 'rotulo' => 'Segredo do webhook', 'tipo' => 'segredo'],
            ['coluna' => 'webhook_endpoint', 'rotulo' => 'URL de notificação', 'tipo' => 'url'],
        ];
    }

    public static function extrasDe(string $codigo): array
    {
        return self::EXTRAS_POR_ADQUIRENTE[strtolower($codigo)] ?? [];
    }

    /** Campos comuns, sobrescritos normalmente. */
    private const CAMPOS = [
        'nome', 'ativo', 'sandbox', 'client_id', 'front_client_id',
        'merchant_id', 'webhook_id', 'webhook_endpoint', 'config_extra', 'logo_url',
    ];

    /** Adapters que existem no código — o que a tela pode oferecer. */
    public const SUPORTADAS = [
        'safrapay'    => 'Safra Pay',
        'mercadopago' => 'Mercado Pago',
        'cielo'       => 'Cielo',
        'fake'        => 'Fake (testes)',
    ];

    public function listar(): array
    {
        return $this->db->query(
            "SELECT * FROM pgto_gateways ORDER BY ativo DESC, nome ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Versão segura para a view: troca cada segredo por um booleano.
     * A tela mostra "configurado" ou "pendente", nunca o valor.
     */
    public function listarParaTela(): array
    {
        $out = [];
        foreach ($this->listar() as $g) {
            foreach (self::SEGREDOS as $s) {
                $g[$s . '_preenchido'] = !empty($g[$s]);
                unset($g[$s]);
            }
            $g['tem_adapter'] = isset(self::SUPORTADAS[$g['codigo']]);
            $out[] = $g;
        }
        return $out;
    }

    public function porCodigo(string $codigo): ?array
    {
        $st = $this->db->prepare("SELECT * FROM pgto_gateways WHERE codigo = ? LIMIT 1");
        $st->execute([$codigo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Grava a configuração. Segredo vazio = preserva o que já existe.
     */
    public function salvar(int $id, array $dados): bool
    {
        $set = [];
        $par = [];

        foreach (self::CAMPOS as $c) {
            if (!array_key_exists($c, $dados)) continue;
            $set[]   = "`{$c}` = :{$c}";
            $par[$c] = $dados[$c];
        }

        foreach (self::SEGREDOS as $c) {
            // String vazia significa "o lojista não digitou nada agora".
            // Só sobrescreve quando há valor novo de verdade.
            if (!isset($dados[$c]) || trim((string) $dados[$c]) === '') continue;
            $set[]   = "`{$c}` = :{$c}";
            $par[$c] = trim((string) $dados[$c]);
        }

        if (!$set) return false;

        $par['id'] = $id;
        $sql = "UPDATE pgto_gateways SET " . implode(', ', $set) . ", atualizado_em = NOW() WHERE id = :id";
        return $this->db->prepare($sql)->execute($par);
    }

    /**
     * Ativa/desativa. Ativar exige credencial mínima — uma adquirente ativa
     * e sem credencial é pior do que desativada: ela entra no fluxo e falha
     * na hora do pagamento.
     *
     * @return array{ok:bool, msg:string}
     */
    public function alternarAtivo(int $id, bool $ativar): array
    {
        $g = $this->find($id);
        if (!$g) return ['ok' => false, 'msg' => 'Adquirente não encontrada.'];

        if ($ativar) {
            if (!isset(self::SUPORTADAS[$g['codigo']])) {
                return ['ok' => false, 'msg' => 'Não existe adapter no código para esta adquirente.'];
            }
            if (empty($g['merchant_id']) && empty($g['client_id'])) {
                return ['ok' => false, 'msg' => 'Preencha as credenciais antes de ativar.'];
            }
            if (empty($g['api_key'])) {
                return ['ok' => false, 'msg' => 'Chave de API ausente. Preencha antes de ativar.'];
            }
        }

        $this->db->prepare("UPDATE pgto_gateways SET ativo = ?, atualizado_em = NOW() WHERE id = ?")
                 ->execute([$ativar ? 1 : 0, $id]);

        return ['ok' => true, 'msg' => $ativar ? 'Adquirente ativada.' : 'Adquirente desativada.'];
    }

    /**
     * A adquirente está sendo usada por algum fluxo publicado?
     * Desativar uma que está no grafo deixa o roteamento sem saída.
     */
    public function emUsoPorFluxo(string $codigo): array
    {
        $st = $this->db->prepare(
            "SELECT DISTINCT f.id, f.nome, f.metodo_codigo, f.versao
               FROM pgto_fluxo_nos n
               JOIN pgto_fluxos f ON f.id = n.fluxo_id
              WHERE f.status = 'publicado'
                AND n.tipo = 'tentar_adquirente'
                AND JSON_UNQUOTE(JSON_EXTRACT(n.config, '$.adquirente')) = ?"
        );
        $st->execute([$codigo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
