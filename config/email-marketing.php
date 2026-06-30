<?php
/**
 * config/email-marketing.php
 * Configurações do módulo de Email Marketing.
 *
 * IMPORTANTE: a chave EMAIL_MARKETING_KEY deve ser definida em variável de
 * ambiente ou no config principal do projeto (config/config.php) ANTES deste
 * arquivo ser carregado. Nunca commitar a chave em texto puro.
 */

return [
    // Chave para criptografia simétrica de credenciais de provedores.
    // Use 32 bytes (256 bits) gerados via openssl_random_pseudo_bytes(32).
    // Pode vir de getenv('EMAIL_MARKETING_KEY') ou de uma constante já definida.
    'encryption_key' => defined('EMAIL_MARKETING_KEY')
        ? EMAIL_MARKETING_KEY
        : (getenv('EMAIL_MARKETING_KEY') ?: ''),

    // Lock file global do worker (impede execuções concorrentes descontroladas)
    'worker_lock_file' => defined('ROOT_PATH')
        ? ROOT_PATH . '/storage/locks/email-worker.lock'
        : __DIR__ . '/../storage/locks/email-worker.lock',

    // Tempo máximo de execução de UMA rodada do worker (segundos)
    'worker_max_runtime' => 50,

    // Quantidade máxima de campanhas processadas por rodada
    'worker_max_campanhas_por_rodada' => 5,

    // Lock token expira em N segundos (registros presos voltam para 'fila')
    'lock_expira_segundos' => 600,

    // Backoff exponencial para falhas temporárias
    'backoff_minutos' => [1, 5, 15, 60, 180],
    'max_tentativas' => 5,

    // Limites globais (o provedor também pode ter os seus)
    'limite_global_por_minuto' => 600,
    'limite_global_por_dia'    => 200000,

    // Whitelist de campos permitidos no construtor de segmentos.
    // Qualquer chave fora desta lista é IGNORADA — não há SQL livre.
    'segment_whitelist' => [
        'newsletter_ativa',         // bool
        'email_verificado',         // bool
        'comprou_ultimos_dias',     // int (dias)
        'nao_compra_ha_dias',       // int (dias)
        'comprou_produto_id',       // int
        'comprou_categoria_id',     // int
        'comprou_marca_id',         // int
        'wishlist_produto_id',      // int
        'visualizou_produto_id',    // int
        'visualizou_categoria_id',  // int
        'genero',                   // M|F|Outro
        'mes_aniversario',          // 1..12
        'valor_comprado_min',       // decimal
        'valor_comprado_max',       // decimal
        'pedido_status',            // string
        'origem',                   // ENUM email_contatos.origem
        'status_contato',           // ENUM email_contatos.status
    ],

    // Variáveis seguras que podem ser usadas em templates
    'template_vars' => [
        'ano',
        'nome',
        'primeiro_nome',
        'email',
        'cupom',
        'site_nome',
        'url_site',
        'url_descadastro',
        'data_atual',
        'pedido_codigo',
        'rastreio_codigo',
        'rastreio_url',
        'pedido_url',
        'cor_padrao',
        'cor_secundaria',
        'logo_loja',

        'produto_preco', 
        'produto_nome', 
        'produto_url', 
        'produto_img',

        'quantidade',
        'preco_unitario',
        'preco_antigo',
        'preco_novo',
        'desconto_pct',

        'codigo',
        'carrinho_itens',
        'carrinho_url',
        'carrinho_total',
        'categoria_nome',
        
        'status_pedido',
        'observacao_pedido',
        
    ],

    // Domínios bloqueados para envio (lixo conhecido)
    'dominios_bloqueados' => [
        'example.com', 'example.org', 'test.com', 'mailinator.com',
    ],
];
