<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/FreteService.php
//
// Integração com a API Tray de cálculo de frete.
//
// Lê configurações da tabela `configuracoes`:
//   - loja_cep_origem
//   - frete_api_url
//   - frete_api_token
// ════════════════════════════════════════════════════════

class FreteService {

    private const CACHE_TTL = 600;     // 10 min — opcional em sessão
    private const TIMEOUT   = 8;       // segundos

    private PDO $db;
    private string $cepOrigem;
    private string $apiUrl;
    private string $apiToken;

    public function __construct() {
        $this->db        = Database::getInstance()->getConnection();
        $this->cepOrigem = $this->configValue('loja_cep_origem', '');
        $this->apiUrl    = $this->configValue('frete_api_url',   '');
        $this->apiToken  = $this->configValue('frete_api_token', '');
    }

    /**
     * Calcula opções de frete para um CEP de destino e os itens do carrinho.
     *
     * @param string $cepDestino    Apenas dígitos.
     * @param array  $itens         Itens do carrinho com SKU (vide montarPayloadProds).
     * @param int    $pedidoNum     Número do pedido para a API (pode ser o carrinho_id).
     * @return array {
     *   ok: bool,
     *   opcoes: [{id,nome,prazo,valor,tipo,tag,poster,carrier,observacao,frete_gratis}],
     *   erro?: string
     * }
     */
    public function calcular(string $cepDestino, array $itens, int $pedidoNum = 0): array {
        $cepDestino = preg_replace('/\D/', '', $cepDestino);
        if (strlen($cepDestino) !== 8) {
            return ['ok' => false, 'opcoes' => [], 'erro' => 'CEP inválido.'];
        }

        if (empty($this->cepOrigem) || empty($this->apiUrl) || empty($this->apiToken)) {
            return ['ok' => false, 'opcoes' => [], 'erro' => 'Configuração de frete incompleta.'];
        }

        if (empty($itens)) {
            return ['ok' => false, 'opcoes' => [], 'erro' => 'Carrinho vazio.'];
        }

        $prods = $this->montarPayloadProds($itens);
        if (empty($prods)) {
            return ['ok' => false, 'opcoes' => [], 'erro' => 'Nenhum produto com dimensões.'];
        }

        $sessionId = session_id() ?: bin2hex(random_bytes(8));

        $query = http_build_query([
            'token'         => $this->apiToken,
            'cep'           => $this->cepOrigem,
            'cep_destino'   => $cepDestino,
            'envio'         => 1,
            'num_ped'       => $pedidoNum ?: time(),
            'session_id'    => $sessionId,
            'prods'         => 'prods=' . $prods,
            'ecommerce-v2'  => 'yes',
        ]);

        $url = $this->apiUrl . '?' . $query;

        $resposta = $this->httpGet($url);
        if (!$resposta['ok']) {
            return ['ok' => false, 'opcoes' => [], 'erro' => $resposta['erro']];
        }

        $data = json_decode($resposta['body'], true);
        if (!is_array($data) || empty($data['list_logistic'])) {
            return ['ok' => false, 'opcoes' => [], 'erro' => 'Sem opções disponíveis.'];
        }

        return [
            'ok'     => true,
            'opcoes' => $this->normalizarOpcoes($data['list_logistic']),
        ];
    }

    // ── Helpers privados ───────────────────────────────

    /**
     * Monta string `prods` no formato da API Tray:
     *   comprimento;largura;altura;cubagem;quantidade;peso;codigo;valor
     *   separando produtos por '/'
     *
     * Espera itens com chaves: comprimento, largura, altura, peso, sku_codigo,
     * valor_unitario, quantidade.
     */
    private function montarPayloadProds(array $itens): string {
        $partes = [];
        foreach ($itens as $item) {
            $comp = (float)($item['comprimento']    ?? 0.20);
            $larg = (float)($item['largura']        ?? 0.20);
            $alt  = (float)($item['altura']         ?? 0.10);
            $cub  = round($comp * $larg * $alt, 6);
            $qtd  = (int)($item['quantidade']       ?? 1);
            $peso = (float)($item['peso']           ?? 0.5);
            $cod  = (string)($item['sku_codigo']    ?? $item['produto_id'] ?? '');
            $val  = (float)($item['valor_unitario'] ?? $item['preco']      ?? 0);

            if (empty($cod) || $val <= 0) continue;

            $partes[] = "{$comp};{$larg};{$alt};{$cub};{$qtd};{$peso};{$cod};{$val}";
        }
        return implode('/', $partes);
    }

    /**
     * Converte resposta da API no formato padronizado consumido pelo JS.
     */
    private function normalizarOpcoes(array $list): array {
        $opcoes = [];
        foreach ($list as $opt) {
            if (isset($opt['available']) && $opt['available'] === false) continue;
            if (isset($opt['blocked'])   && $opt['blocked']   === true)  continue;

            $codigo    = (string)($opt['codigo']    ?? '');
            $descricao = (string)($opt['descricao'] ?? '');
            if (empty($codigo) || empty($descricao)) continue;

            $valor = $this->parseValor($opt);
            $tag   = (string)($opt['tag'] ?? '');
            $isFree = ($opt['frete_gratis'] ?? false) === true
                   || ($opt['is_free_shipping'] ?? false) === true
                   || $valor === 0.0;

            $isPickup = $this->detectarRetirada($descricao, $codigo);

            $opcoes[] = [
                'id'           => $codigo,
                'nome'         => $descricao,
                'prazo'        => (int)($opt['prazo'] ?? 0),
                'prazo_texto'  => (string)($opt['prazo_texto'] ?? ''),
                'valor'        => $isFree ? 0.0 : $valor,
                'tipo'         => $isPickup ? 'retirada' : 'entrega',
                'tag'          => $tag,
                'poster'       => (string)($opt['poster']     ?? ''),
                'carrier'      => (string)($opt['carrier']    ?? ''),
                'observacao'   => (string)($opt['observacao'] ?? ''),
                'frete_gratis' => $isFree,
            ];
        }

        // Ordena: grátis primeiro, depois por preço crescente
        usort($opcoes, function ($a, $b) {
            if ($a['frete_gratis'] && !$b['frete_gratis']) return -1;
            if (!$a['frete_gratis'] && $b['frete_gratis']) return  1;
            return $a['valor'] <=> $b['valor'];
        });

        return $opcoes;
    }

    private function parseValor(array $opt): float {
        if (isset($opt['valor_final']) && is_numeric($opt['valor_final'])) {
            return (float)$opt['valor_final'];
        }
        if (isset($opt['table_price']) && is_numeric($opt['table_price'])) {
            return (float)$opt['table_price'];
        }
        if (isset($opt['valor'])) {
            // pode vir "50,13" ou 50.13
            $v = is_string($opt['valor'])
                ? str_replace(',', '.', preg_replace('/[^\d,]/', '', $opt['valor']))
                : (float)$opt['valor'];
            return (float)$v;
        }
        return 0.0;
    }

    private function detectarRetirada(string $descricao, string $codigo): bool {
        return (bool)preg_match('/retir|pickup|loja|store/i', $descricao . ' ' . $codigo);
    }

    private function httpGet(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'erro' => 'Falha de conexão: ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'erro' => "API retornou HTTP {$code}"];
        }
        return ['ok' => true, 'body' => (string)$body];
    }

    private function configValue(string $chave, string $default = ''): string {
        try {
            $stmt = $this->db->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
            $stmt->execute([$chave]);
            $v = $stmt->fetchColumn();
            return $v !== false ? (string)$v : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}