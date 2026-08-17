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
     * Catálogo dos adapters: rótulo, descrição, ambientes suportados e os
     * CAMPOS de configuração que a UI deve pedir por adapter. Campos com
     * tipo 'secret' nunca devem ser devolvidos preenchidos ao navegador.
     *
     * @return array<string,array{label:string,descricao:string,ambientes:array<int,string>,campos:array<int,array{nome:string,label:string,tipo:string,obrigatorio:bool}>}>
     */
    public static function catalogo(): array
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
                    ['nome' => 'token',         'label' => 'Token (Bearer OAuth2)',            'tipo' => 'secret', 'obrigatorio' => true],
                    ['nome' => 'app_nome',      'label' => 'Nome da aplicação (User-Agent)',   'tipo' => 'text',   'obrigatorio' => true],
                    ['nome' => 'email_contato', 'label' => 'E-mail de contato (User-Agent)',   'tipo' => 'text',   'obrigatorio' => true],
                ],
            ],
            'CorreiosAdapter' => [
                'label'     => 'Correios (contrato próprio — reversa ativa; cotação/etiqueta em breve)',
                'descricao' => 'Reversa via Logística Reversa (web service SOAP) com o seu contrato — preencha o bloco "Reversa" abaixo. Cotação/etiqueta diretas (CWS REST) ainda são stub; para cotar Correios sem contrato, use o Melhor Envio.',
                'ambientes' => ['homologacao', 'producao'],
                'campos'    => [
                    ['nome' => 'usuario',         'label' => 'Usuário CWS (cotação/etiqueta — opcional)',       'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'codigo_acesso',   'label' => 'Código de acesso CWS (opcional)',                 'tipo' => 'secret', 'obrigatorio' => false],
                    ['nome' => 'cartao_postagem', 'label' => 'Cartão de postagem',                              'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'contrato',        'label' => 'Número do contrato',                              'tipo' => 'text',   'obrigatorio' => false],
                    // --- Reversa (Logística Reversa SOAP) ---
                    ['nome' => 'reversa_endpoint',           'label' => 'Reversa · endpoint do Web Service',        'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_ws_user',            'label' => 'Reversa · usuário do WS',                  'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_ws_senha',           'label' => 'Reversa · senha do WS',                    'tipo' => 'secret', 'obrigatorio' => false],
                    ['nome' => 'reversa_cod_administrativo', 'label' => 'Reversa · código administrativo',          'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_codigo_servico',     'label' => 'Reversa · código do serviço (ex.: 03301)', 'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_cartao',             'label' => 'Reversa · cartão (vazio = usa o de cima)', 'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_tipo',               'label' => 'Reversa · tipo (A=agência, C=coleta)',     'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_nome',          'label' => 'Reversa/Loja · nome (destinatário da volta)', 'tipo' => 'text', 'obrigatorio' => false],
                    ['nome' => 'reversa_loja_logradouro',    'label' => 'Reversa/Loja · logradouro',                'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_numero',        'label' => 'Reversa/Loja · número',                    'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_bairro',        'label' => 'Reversa/Loja · bairro',                    'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_cidade',        'label' => 'Reversa/Loja · cidade',                    'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_uf',            'label' => 'Reversa/Loja · UF',                        'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_cep',           'label' => 'Reversa/Loja · CEP',                       'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_telefone',      'label' => 'Reversa/Loja · telefone',                  'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'reversa_loja_email',         'label' => 'Reversa/Loja · e-mail',                    'tipo' => 'text',   'obrigatorio' => false],
                ],
            ],
            'LogManagerAdapter' => [
                'label'     => 'LogManager (entrega D+1 — cidades próximas)',
                'descricao' => 'Last-mile rápido em cidades próximas. Não cota frete: o preço vem do preço-base + suas regras de frete. Usada para etiqueta e rastreio.',
                'ambientes' => ['sandbox', 'producao'],
                'campos'    => [
                    ['nome' => 'token',          'label' => 'Token / API key (painel do vendedor)',  'tipo' => 'secret', 'obrigatorio' => true],
                    ['nome' => 'ceps_atendidos', 'label' => 'CEPs atendidos: prefixos e/ou faixas, por vírgula (ex.: 90000000-91999999, 92, 94900000-94999999)', 'tipo' => 'text', 'obrigatorio' => true],
                    ['nome' => 'd1_valor_base',  'label' => 'Preço-base do D+1 (R$)',                 'tipo' => 'text',   'obrigatorio' => true],
                    ['nome' => 'cutoff_hora',    'label' => 'Hora de corte (0-23, ex.: 12)',         'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'd1_nome',        'label' => 'Nome exibido do serviço',               'tipo' => 'text',   'obrigatorio' => false],
                    ['nome' => 'base_url',       'label' => 'URL base (vazio = app.logmanager.com.br)', 'tipo' => 'text', 'obrigatorio' => false],
                ],
            ],
        ];
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