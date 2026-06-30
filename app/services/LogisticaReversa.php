<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/LogisticaReversa.php
//
// Encapsula a API de logística reversa.
// Resposta esperada:
//   { "res": { "status": true, "cod": 123456789, "validate": 7 } }
// ════════════════════════════════════════════════════════

class LogisticaReversa {

    // Configurar em config.php:
    // define('LOGISTICA_REVERSA_URL',   'https://api.seugateway.com/reversa');
    // define('LOGISTICA_REVERSA_TOKEN', 'seu-token-aqui');

    private string $url;
    private string $token;

    public function __construct() {
        $this->url   = defined('LOGISTICA_REVERSA_URL')   ? LOGISTICA_REVERSA_URL   : '';
        $this->token = defined('LOGISTICA_REVERSA_TOKEN') ? LOGISTICA_REVERSA_TOKEN : '';
    }

    /**
     * Gera o código de postagem reversa.
     *
     * @param array $cliente  ['nome', 'email', 'cpf', 'telefone', 'cep', 'logradouro', 'numero', 'cidade', 'estado']
     * @param array $itens    [ ['nome', 'sku', 'quantidade', 'peso_gramas'] ]
     * @param array $pedido   ['codigo', 'id']
     *
     * @return array ['ok' => true, 'cod' => '123456789', 'validate' => 7]
     *           ou  ['ok' => false, 'msg' => 'mensagem de erro']
     */
    public function gerarCodigoReversa(
        array $cliente,
        array $itens,
        array $pedido
    ): array {

        // Modo desenvolvimento / sem URL configurada
        if (empty($this->url)) {
            error_log('[LogisticaReversa] URL não configurada — usando modo fake.');
            return [
                'ok'       => true,
                'cod'      => 'FAKE' . rand(100000, 999999),
                'validate' => 7,
                'fake'     => true,
            ];
        }

        $payload = [
            'pedido_referencia' => $pedido['codigo'],
            'remetente' => [
                'nome'      => $cliente['nome'],
                'email'     => $cliente['email'],
                'cpf'       => preg_replace('/\D/', '', $cliente['cpf'] ?? ''),
                'telefone'  => preg_replace('/\D/', '', $cliente['telefone'] ?? ''),
                'cep'       => preg_replace('/\D/', '', $cliente['cep'] ?? ''),
                'endereco'  => $cliente['logradouro'] ?? '',
                'numero'    => $cliente['numero'] ?? '',
                'cidade'    => $cliente['cidade'] ?? '',
                'estado'    => $cliente['estado'] ?? '',
            ],
            'itens' => array_map(fn($i) => [
                'nome'       => $i['nome'],
                'sku'        => $i['sku']        ?? '',
                'quantidade' => (int)$i['quantidade'],
                'peso_gramas'=> (int)($i['peso_gramas'] ?? 300),
            ], $itens),
        ];

        try {
            $ch = curl_init($this->url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->token,
                ],
            ]);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($err) {
                throw new \RuntimeException("cURL error: {$err}");
            }
            if ($code !== 200) {
                throw new \RuntimeException("HTTP {$code}: {$raw}");
            }

            $data = json_decode($raw, true);

            // Formato esperado: { "res": { "status": true, "cod": 123456789, "validate": 7 } }
            if (empty($data['res']['status'])) {
                throw new \RuntimeException("API retornou status false: {$raw}");
            }

            return [
                'ok'       => true,
                'cod'      => (string)$data['res']['cod'],
                'validate' => (int)$data['res']['validate'],
            ];

        } catch (\Throwable $e) {
            error_log('[LogisticaReversa] gerarCodigoReversa: ' . $e->getMessage());
            return [
                'ok'  => false,
                'msg' => 'Não foi possível gerar o código de postagem: ' . $e->getMessage(),
            ];
        }
    }
}