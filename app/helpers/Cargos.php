<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/helpers/Cargos.php
// FONTE ÚNICA DE VERDADE dos cargos do painel: labels, cores
// e matriz de capacidades. Consumida por (1) badges + modal,
// (2) página de criação de usuários, (3) topbar, e (4) futura
// camada granular via admins.permissoes JSON.
//
// Alterar um cargo = alterar AQUI. Se a matriz vivesse
// duplicada em views/modais, dessincronizaria na primeira
// mudança — e documentação de permissão errada é falha de
// segurança operacional (gente pedindo acesso pelo cargo
// errado, gestor concedendo além do necessário).
// ════════════════════════════════════════════════════════

final class Cargos {

    public const LISTA = [
        'super' => [
            'label'     => 'Super Admin',
            'cor'       => '#7c3aed',
            'bg'        => '#f5f3ff',
            'descricao' => 'Acesso total ao sistema, incluindo gestão de usuários e integrações.',
            'capacidades' => [
                'Sistema'      => ['Tudo que os demais cargos podem fazer',
                                   'Criar, editar e desativar usuários do painel',
                                   'Definir o cargo de cada usuário'],
                'Integrações'  => ['Bling, Tray, chaves de API e configurações de sistema'],
            ],
        ],
        'gerente' => [
            'label'     => 'Gerente',
            'cor'       => '#1d4ed8',
            'bg'        => '#eff6ff',
            'descricao' => 'Gestão completa da operação comercial — sem gestão de usuários e integrações.',
            'capacidades' => [
                'Pedidos'               => ['Ver todos os pedidos', 'Alterar status, pagamento e itens'],
                'Catálogo'              => ['Produtos, categorias, marcas e características'],
                'Estoque'               => ['Saldos e movimentações'],
                'Promoções & Cupons'    => ['Criar, editar, ativar e desativar'],
                'Central de Recuperação'=> ['Ver TODOS os carrinhos (inclusive capturados por outros)',
                                            'Atribuir e transferir responsáveis',
                                            'Templates de WhatsApp/e-mail',
                                            'Configurações de automação',
                                            'Relatórios de conversão e exportação CSV'],
                'Clientes'              => ['Ver e editar cadastros'],
            ],
        ],
        'vendedor' => [
            'label'     => 'Vendedor',
            'cor'       => '#16a34a',
            'bg'        => '#f0fdf4',
            'descricao' => 'Operação de vendas: recuperação de carrinhos e acompanhamento de pedidos.',
            'capacidades' => [
                'Central de Recuperação'=> ['Ver o pool (carrinhos sem responsável) e os SEUS',
                                            'Capturar carrinhos (⚡ ou automático no 1º contato)',
                                            'Enviar WhatsApp e e-mail de recuperação',
                                            'Anotar, agendar contato e alterar status dos seus carrinhos'],
                'Pedidos'               => ['Ver pedidos',
                            'Criar pedido manual (venda assistida)',
                            'Alterar status'],
                'Clientes'              => ['Consultar cadastros (leitura)'],
            ],
        ],
        'editor' => [
            'label'     => 'Editor',
            'cor'       => '#d97706',
            'bg'        => '#fffbeb',
            'descricao' => 'Gestão de catálogo e conteúdo da loja.',
            'capacidades' => [
                'Catálogo' => ['Produtos: criar, editar, imagens e preços',
                               'Categorias, marcas e características',
                               'Compatibilidade de motos'],
            ],
        ],
        'estoque' => [
            'label'     => 'Estoque',
            'cor'       => '#0891b2',
            'bg'        => '#ecfeff',
            'descricao' => 'Controle de estoque e apoio logístico aos pedidos.',
            'capacidades' => [
                'Estoque' => ['Saldos, entradas e movimentações'],
                'Pedidos' => ['Ver pedidos', 'Alterar status logístico (separação/envio)'],
            ],
        ],
    ];

    public static function existe(string $nivel): bool {
        return isset(self::LISTA[$nivel]);
    }

    public static function get(string $nivel): ?array {
        return self::LISTA[$nivel] ?? null;
    }

    public static function label(string $nivel): string {
        return self::LISTA[$nivel]['label'] ?? ucfirst($nivel);
    }

    /** Payload seguro para o front (modal de capacidades). */
    public static function paraJson(): array {
        return self::LISTA; // sem dados sensíveis: é documentação de papéis
    }
}