<?php
/**
 * Resolve o adapter correto para uma transportadora e centraliza o acesso
 * às transportadoras ativas — equivalente ao GatewayRouter dos pagamentos.
 *
 * A instanciação passa por ALLOWLIST: o valor da coluna `adapter` só é
 * aceito se estiver mapeado aqui. Isso evita instanciar classe arbitrária
 * a partir de dado do banco (mesma postura de allowlist do resto do projeto).
 *
 * Para registrar uma nova transportadora real, basta: (1) criar a classe
 * XxxAdapter extends TransportadoraBase e (2) adicioná-la em self::ADAPTERS.
 * Nada mais no módulo precisa mudar.
 */
class TransportadoraManager
{
    /** Allowlist slug-do-adapter => classe. */
    private const ADAPTERS = [
        'TransportadoraSimulada' => TransportadoraSimulada::class,
        'MelhorEnvioAdapter'     => MelhorEnvioAdapter::class,
        'CorreiosAdapter'        => CorreiosAdapter::class,
        'LogManagerAdapter'      => LogManagerAdapter::class, // D+1 (cidades próximas) — cotação via regras
        // 'JadlogAdapter'       => JadlogAdapter::class,        // futuro (direto)
        // 'KanguAdapter'        => KanguAdapter::class,         // futuro (direto)
    ];

    /** Instancia o adapter a partir da linha de log_transportadoras. */
    public static function resolver(array $transportadora): TransportadoraInterface
    {
        $classe = (string)($transportadora['adapter'] ?? '');
        if (!isset(self::ADAPTERS[$classe])) {
            throw new RuntimeException("Adapter de transportadora não registrado: {$classe}");
        }
        $fqcn = self::ADAPTERS[$classe];
        return new $fqcn($transportadora);
    }

    /** Carrega a linha da transportadora por id. */
    public static function porId(int $id): ?array
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT * FROM log_transportadoras WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Todas as transportadoras ativas, já ordenadas por prioridade —
     * a ordem que o CotacaoService usa para cotar e para o fallback.
     * @return array<int,array>
     */
    public static function ativas(): array
    {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->query(
            "SELECT * FROM log_transportadoras
             WHERE status = 'ativo'
             ORDER BY prioridade ASC, nome ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Lista dos adapters conhecidos (para o select do cadastro). */
    public static function disponiveis(): array
    {
        return array_keys(self::ADAPTERS);
    }

    /**
     * Grupos de campos por adapter: slug => rótulo da seção.
     *
     * Existe porque os Correios pedem 20 campos. Num grid único, achar
     * "cartão de postagem" no meio de dezoito irmãos é caça ao tesouro —
     * e campo difícil de achar é campo preenchido errado.
     *
     * Campo sem `grupo` cai em 'geral', que é o comportamento de antes.
     */
    public static function grupos(string $adapter): array
    {
        return [
            'CorreiosAdapter' => [
                'cws'          => 'CWS · cotação e etiqueta',
                'reversa_ws'   => 'Reversa · acesso ao web service',
                'reversa_loja' => 'Reversa · endereço de retorno',
            ],
        ][$adapter] ?? [];
    }

    /**
     * Catálogo dos adapters: rótulo, descrição, ambientes suportados e os
     * CAMPOS de configuração que a UI deve pedir por adapter. Campos com
     * tipo 'secret' nunca devem ser devolvidos preenchidos ao navegador.
     *
     * Chaves opcionais de cada campo:
     *   grupo  — seção da UI (ver self::grupos()); ausente = 'geral'
     *   ajuda  — texto curto abaixo do campo, para o que o rótulo não diz
     *   col    — largura no grid: 'curta' (UF, nº, hora) ou 'larga' (URL)
     *
     * @return array<string,array{label:string,descricao:string,ambientes:array<int,string>,campos:array<int,array{nome:string,label:string,tipo:string,obrigatorio:bool}>}>
     */
    public static function catalogo(): array
    {
        $cat = self::catalogoBruto();
        // Os grupos viajam junto: a UI monta as seções a partir do catálogo e
        // não deve precisar de um segundo endpoint para saber como agrupar.
        foreach ($cat as $slug => &$a) { $a['grupos'] = self::grupos($slug); }
        return $cat;
    }

    /** @return array<string,array> definição crua, sem os grupos injetados. */
    private static function catalogoBruto(): array
    {
        return [
            'TransportadoraSimulada' => [
                'label'     => 'Simulada (sandbox interno)',
                'descricao' => 'Gera cotações/etiquetas/rastreios fictícios. Para testar o fluxo sem integração real. Não usar em produção.',
                'ambientes' => ['sandbox'],
                'campos'    => [],
            ],
            'MelhorEnvioAdapter' => [
                'label'     => 'Melhor Envio',
                'descricao' => 'Correios (PAC/SEDEX/Mini), Jadlog, Loggi, Azul, LATAM e J&T por uma única integração. Recomendado.',
                'ambientes' => ['sandbox', 'producao'],
                'campos'    => [
                    ['nome' => 'token',         'label' => 'Token (Bearer OAuth2)', 'tipo' => 'secret', 'obrigatorio' => true, 'ajuda' => 'Gerado no painel do Melhor Envio, em Tokens de acesso.'],
                    ['nome' => 'app_nome',      'label' => 'Nome da aplicação',     'tipo' => 'text',   'obrigatorio' => true, 'ajuda' => 'Vai no User-Agent das chamadas.'],
                    ['nome' => 'email_contato', 'label' => 'E-mail de contato',     'tipo' => 'text',   'obrigatorio' => true, 'ajuda' => 'Também vai no User-Agent — o Melhor Envio exige.'],
                ],
            ],
            'CorreiosAdapter' => [
                'label'     => 'Correios (contrato próprio — reversa ativa; cotação/etiqueta em breve)',
                'descricao' => 'Reversa via Logística Reversa (web service SOAP) com o seu contrato — preencha o bloco "Reversa" abaixo. Cotação/etiqueta diretas (CWS REST) ainda são stub; para cotar Correios sem contrato, use o Melhor Envio.',
                'ambientes' => ['homologacao', 'producao'],
                'campos'    => [
                    ['nome' => 'usuario',         'label' => 'Usuário CWS',        'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'cws', 'ajuda' => 'Costuma ser o CNPJ da loja, só dígitos.'],
                    ['nome' => 'codigo_acesso',   'label' => 'Código de acesso',   'tipo' => 'secret', 'obrigatorio' => false, 'grupo' => 'cws', 'ajuda' => 'Gerado no Meu Correios. Não é a senha do portal.'],
                    ['nome' => 'cartao_postagem', 'label' => 'Cartão de postagem', 'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'cws', 'col' => 'curta'],
                    ['nome' => 'contrato',        'label' => 'Número do contrato', 'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'cws', 'col' => 'curta'],

                    // --- Reversa (Logística Reversa SOAP) ---
                    ['nome' => 'reversa_endpoint',           'label' => 'Endpoint do Web Service', 'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'reversa_ws', 'col' => 'larga', 'ajuda' => 'URL do WSDL da Logística Reversa.'],
                    ['nome' => 'reversa_ws_user',            'label' => 'Usuário do WS',           'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'reversa_ws'],
                    ['nome' => 'reversa_ws_senha',           'label' => 'Senha do WS',             'tipo' => 'secret', 'obrigatorio' => false, 'grupo' => 'reversa_ws', 'ajuda' => 'É a senha do web service de reversa — não é o código de acesso do CWS.'],
                    ['nome' => 'reversa_cod_administrativo', 'label' => 'Código administrativo',   'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'reversa_ws', 'col' => 'curta'],
                    ['nome' => 'reversa_codigo_servico',     'label' => 'Código do serviço',       'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'reversa_ws', 'col' => 'curta', 'ajuda' => 'Ex.: 03301.'],
                    ['nome' => 'reversa_cartao',             'label' => 'Cartão da reversa',       'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'reversa_ws', 'col' => 'curta', 'ajuda' => 'Vazio = usa o cartão de postagem do CWS.'],
                    ['nome' => 'reversa_tipo',               'label' => 'Tipo',                    'tipo' => 'text',   'obrigatorio' => false, 'grupo' => 'reversa_ws', 'col' => 'curta', 'ajuda' => 'A = agência · C = coleta.'],

                    ['nome' => 'reversa_loja_nome',       'label' => 'Nome',       'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja', 'ajuda' => 'Quem recebe a mercadoria de volta.'],
                    ['nome' => 'reversa_loja_logradouro', 'label' => 'Logradouro', 'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja', 'col' => 'larga'],
                    ['nome' => 'reversa_loja_numero',     'label' => 'Número',     'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja', 'col' => 'curta'],
                    ['nome' => 'reversa_loja_bairro',     'label' => 'Bairro',     'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja'],
                    ['nome' => 'reversa_loja_cidade',     'label' => 'Cidade',     'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja'],
                    ['nome' => 'reversa_loja_uf',         'label' => 'UF',         'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja', 'col' => 'curta'],
                    ['nome' => 'reversa_loja_cep',        'label' => 'CEP',        'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja', 'col' => 'curta'],
                    ['nome' => 'reversa_loja_telefone',   'label' => 'Telefone',   'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja', 'col' => 'curta'],
                    ['nome' => 'reversa_loja_email',      'label' => 'E-mail',     'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'reversa_loja'],
                ],
            ],
            'LogManagerAdapter' => [
                'label'     => 'LogManager (entrega D+1 — cidades próximas)',
                'descricao' => 'Last-mile rápido em cidades próximas. Não cota frete: o preço vem do preço-base + suas regras de frete. Usada para etiqueta e rastreio.',
                'ambientes' => ['sandbox', 'producao'],
                'campos'    => [
                    ['nome' => 'token',          'label' => 'Token / API key',      'tipo' => 'secret', 'obrigatorio' => true,  'ajuda' => 'Painel do vendedor LogManager.'],
                    ['nome' => 'ceps_atendidos', 'label' => 'CEPs atendidos',       'tipo' => 'text',   'obrigatorio' => true,  'col' => 'larga', 'ajuda' => 'Prefixos e/ou faixas separados por vírgula. Ex.: 90000000-91999999, 92, 94900000-94999999'],
                    ['nome' => 'd1_valor_base',  'label' => 'Preço-base do D+1',    'tipo' => 'text',   'obrigatorio' => true,  'col' => 'curta', 'ajuda' => 'Em reais. Suas regras de frete somam a partir daqui.'],
                    ['nome' => 'cutoff_hora',    'label' => 'Hora de corte',        'tipo' => 'text',   'obrigatorio' => false, 'col' => 'curta', 'ajuda' => '0 a 23. Ex.: 12.'],
                    ['nome' => 'd1_nome',        'label' => 'Nome exibido',         'tipo' => 'text',   'obrigatorio' => false, 'ajuda' => 'Como o cliente vê o serviço no checkout.'],
                    ['nome' => 'base_url',       'label' => 'URL base',             'tipo' => 'text',   'obrigatorio' => false, 'col' => 'larga', 'ajuda' => 'Vazio = app.logmanager.com.br'],
                ],
            ],
        ];
    }

    /**
     * A chave de config é de uso interno do adapter (cache), não do formulário?
     *
     * Convenção: prefixo "_". O CorreiosAdapter grava `_token` e `_token_exp`
     * ali — um bearer JWT vivo. Não é campo do catálogo, então não entra em
     * camposSecretos(); sem esta checagem ele era devolvido em texto puro
     * para o navegador em /obter e /dados.
     */
    public static function ehCampoInterno(string $chave): bool
    {
        return str_starts_with($chave, '_');
    }

    /** Nomes dos campos 'secret' de um adapter (para redigir/preservar no salvamento). */
    public static function camposSecretos(string $adapter): array
    {
        $cat = self::catalogo()[$adapter] ?? null;
        if (!$cat) return [];
        $out = [];
        foreach ($cat['campos'] as $c) {
            if (($c['tipo'] ?? '') === 'secret') $out[] = $c['nome'];
        }
        return $out;
    }
}