<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/ClearSalePedidoMontador.php
 *
 * Monta, a partir do BANCO, a entrada de ClearSaleService::analisar().
 *
 * ── POR QUE EXISTE ───────────────────────────────────────────────
 *
 * No checkout, quem monta o pedido para a ClearSale é o AntifraudeExecutor,
 * a partir do contexto em memória do pagamento — que só existe naquele
 * instante. Depois disso, para consultar um pedido já gravado (o botão da
 * tela de análise) ou para testar a integração com todos os pedidos (cli/
 * teste-clearsale.php todos), é preciso remontar a mesma coisa do banco.
 *
 * ── O DOCUMENTO VEM DO CLIENTE ───────────────────────────────────
 *
 * `cliente.documento` é o CPF de `clientes.cpf`. O executor do checkout
 * pegava o documento do CARTÃO, que o fluxo da Safra não traz — e a
 * ClearSale respondia 400 `shipping.primaryDocument: String '' is invalid`.
 * As duas chamadas reais registradas em pgto_antifraude morreram disso.
 *
 * ── O QUE NÃO DÁ PARA REMONTAR ───────────────────────────────────
 *
 * - `session_id` do fingerprint: só existe na sessão do navegador e não é
 *   gravado. Vai vazio; o ClearSaleService troca por `sem-fp-{codigo}` e
 *   registra aviso. A análise sai sem dados de dispositivo.
 * - BIN, validade e titular do cartão: não são guardados. Vão só a bandeira
 *   e os últimos 4.
 */
final class ClearSalePedidoMontador
{
    private PDO $db;

    /** forma_pagamento do pedido → método que o ClearSaleService entende. */
    private const METODOS = [
        'cartao'         => 'cartao_credito',
        'cartao_credito' => 'cartao_credito',
        'credito'        => 'cartao_credito',
        'pix'            => 'pix',
        'boleto'         => 'boleto',
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * @return array|null  entrada de ClearSaleService::analisar(), ou null
     *                     quando o pedido não existe.
     */
    public function montar(int $pedidoId): ?array
    {
        $st = $this->db->prepare(
            "SELECT p.id, p.codigo, p.cliente_id, p.total, p.frete, p.parcelas,
                    p.forma_pagamento, p.cartao_bandeira, p.cartao_ultimos_4, p.ip_origem,
                    p.endereco_entrega_snapshot, p.endereco_entrega, p.endereco_cobranca_snapshot,
                    c.cpf, c.celular, c.telefone,
                    u.nome, u.email,
                    e.logradouro        AS e_logradouro,
                    e.numero            AS e_numero,
                    e.complemento       AS e_complemento,
                    e.bairro            AS e_bairro,
                    e.cidade            AS e_cidade,
                    e.estado            AS e_estado,
                    e.cep               AS e_cep,
                    e.nome_destinatario AS e_destinatario
               FROM pedidos p
          LEFT JOIN clientes  c ON c.id = p.cliente_id
          LEFT JOIN usuarios  u ON u.id = c.usuario_id
          LEFT JOIN enderecos e ON e.id = p.endereco_entrega_id
              WHERE p.id = ?
              LIMIT 1"
        );
        $st->execute([$pedidoId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;

        $entrega  = $this->enderecoEntrega($p);
        $cobranca = self::json($p['endereco_cobranca_snapshot']) ?: $entrega;

        $formaBruta = strtolower((string) ($p['forma_pagamento'] ?? ''));
        $metodo     = self::METODOS[$formaBruta] ?? ($formaBruta !== '' ? $formaBruta : 'cartao_credito');

        $cartao = [];
        if ($metodo === 'cartao_credito') {
            $cartao = array_filter([
                'ultimos4' => (string) ($p['cartao_ultimos_4'] ?? ''),
                'bandeira' => (string) ($p['cartao_bandeira'] ?? ''),
            ], static fn($v) => $v !== '');
        }

        return [
            'codigo'         => (string) $p['codigo'],
            'session_id'     => '',
            'cliente_id'     => (int) ($p['cliente_id'] ?? 0),
            'valor_centavos' => (int) round((float) $p['total'] * 100),
            'frete_centavos' => (int) round((float) $p['frete'] * 100),
            'parcelas'       => max(1, (int) ($p['parcelas'] ?? 1)),
            'metodo'         => $metodo,
            'ip'             => (string) ($p['ip_origem'] ?? ''),
            'cliente'        => [
                'nome'      => (string) ($p['nome'] ?? ''),
                'email'     => (string) ($p['email'] ?? ''),
                'documento' => (string) ($p['cpf'] ?? ''),
                'telefone'  => (string) (($p['celular'] ?? '') ?: ($p['telefone'] ?? '')),
                'endereco'  => $cobranca,
            ],
            'entrega'        => $entrega
                ? $entrega + ['nome' => (string) ($entrega['destinatario'] ?? $p['nome'] ?? '')]
                : [],
            'itens'          => $this->itens((int) $p['id']),
            'cartao'         => $cartao,
        ];
    }

    /**
     * O que a ClearSale vai recusar, antes de gastar a chamada.
     *
     * Espelha os campos obrigatórios do contrato. Lista vazia = pode enviar.
     *
     * @return string[]
     */
    public static function problemas(?array $p): array
    {
        if (!$p) return ['pedido não encontrado'];

        $erros = [];
        $cli   = $p['cliente'] ?? [];
        $doc   = preg_replace('/\D/', '', (string) ($cli['documento'] ?? '')) ?? '';

        if (!in_array(strlen($doc), [11, 14], true)) $erros[] = 'CPF/CNPJ do cliente ausente ou inválido';
        if (trim((string) ($cli['nome'] ?? '')) === '') $erros[] = 'nome do cliente vazio';
        if (!filter_var((string) ($cli['email'] ?? ''), FILTER_VALIDATE_EMAIL)) $erros[] = 'e-mail inválido';

        $end = $p['entrega'] ?: ($cli['endereco'] ?? []);
        if (!$end) {
            $erros[] = 'sem endereço de entrega nem de cobrança';
        } else {
            $cep = preg_replace('/\D/', '', (string) ($end['cep'] ?? '')) ?? '';
            if (strlen($cep) !== 8)                               $erros[] = 'CEP inválido';
            if (trim((string) ($end['logradouro'] ?? '')) === '') $erros[] = 'logradouro vazio';
            if (trim((string) ($end['cidade'] ?? '')) === '')     $erros[] = 'cidade vazia';
            if (strlen(trim((string) ($end['estado'] ?? $end['uf'] ?? ''))) !== 2) $erros[] = 'UF inválida';
        }

        if (empty($p['itens']))                   $erros[] = 'pedido sem itens';
        if ((int) ($p['valor_centavos'] ?? 0) <= 0) $erros[] = 'valor total zerado';

        return $erros;
    }

    // =====================================================================

    /**
     * Endereço de entrega, na ordem de confiança:
     *   1. snapshot gravado no checkout (o que o cliente viu ao comprar)
     *   2. coluna json `endereco_entrega` (pedidos importados da Tray)
     *   3. o endereço cadastrado, pelo id — pode ter sido editado depois
     */
    private function enderecoEntrega(array $p): array
    {
        $snap = self::json($p['endereco_entrega_snapshot']);
        if ($snap) return $snap;

        $tray = self::json($p['endereco_entrega']);
        if ($tray) return $tray;

        if (!empty($p['e_logradouro']) || !empty($p['e_cep'])) {
            return [
                'logradouro'   => (string) $p['e_logradouro'],
                'numero'       => (string) $p['e_numero'],
                'complemento'  => (string) $p['e_complemento'],
                'bairro'       => (string) $p['e_bairro'],
                'cidade'       => (string) $p['e_cidade'],
                'estado'       => (string) $p['e_estado'],
                'cep'          => (string) $p['e_cep'],
                'destinatario' => (string) $p['e_destinatario'],
            ];
        }
        return [];
    }

    private function itens(int $pedidoId): array
    {
        $st = $this->db->prepare(
            "SELECT id, sku, nome_produto, preco_unitario, quantidade
               FROM pedido_itens WHERE pedido_id = ? ORDER BY id"
        );
        $st->execute([$pedidoId]);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $out[] = [
                'id'             => (int) $i['id'],
                'sku'            => (string) ($i['sku'] ?? ''),
                'nome'           => (string) ($i['nome_produto'] ?? ''),
                'valor_centavos' => (int) round((float) $i['preco_unitario'] * 100),
                'quantidade'     => max(1, (int) $i['quantidade']),
            ];
        }
        return $out;
    }

    private static function json($v): array
    {
        if ($v === null || $v === '') return [];
        $d = is_array($v) ? $v : json_decode((string) $v, true);
        return is_array($d) ? $d : [];
    }
}
