<?php
// app/services/ShippingService.php
// Calcula frete consultando a API dos Correios (simulação estruturada).
// Em produção: substituir por SDK dos Correios ou gateway como Melhor Envio.

class ShippingService {

    /**
     * Calcula opções de frete para um CEP e dimensões do produto.
     * Retorna array de opções com nome, prazo e valor.
     */
    public function calculate(string $cep, array $dimensions): array {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            return ['ok' => false, 'msg' => 'CEP inválido.'];
        }

        // Busca localidade do CEP (via ViaCEP — gratuito e sem autenticação)
        $localidade = $this->fetchCepInfo($cep);
        if (!$localidade) {
            return ['ok' => false, 'msg' => 'CEP não encontrado.'];
        }

        // Simula cálculo de frete (substituir por chamada real à API dos Correios)
        // Em produção: usar cURL para https://ws.correios.com.br/calculador/CalcPreco.asmx
        $opcoes = $this->simulateFreight($cep, $dimensions);

        // Aplica frete grátis se configurado
        $freteGratisMin = (float) ConfigHelper::get('frete_gratis_min', 0);
        if ($freteGratisMin > 0 && !empty($dimensions['valor_carrinho'])
            && $dimensions['valor_carrinho'] >= $freteGratisMin) {
            foreach ($opcoes as &$opt) {
                if ($opt['servico'] === 'PAC') {
                    $opt['valor'] = 0;
                    $opt['nome'] .= ' (Grátis)';
                }
            }
        }

        return [
            'ok'         => true,
            'opcoes'     => $opcoes,
            'localidade' => $localidade['localidade'] ?? '',
            'uf'         => $localidade['uf'] ?? '',
        ];
    }

    private function fetchCepInfo(string $cep): ?array {
        $url = "https://viacep.com.br/ws/{$cep}/json/";
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);

        if (!$res) return null;

        $data = json_decode($res, true);
        if (!$data || isset($data['erro'])) return null;

        return $data;
    }

    private function simulateFreight(string $cep, array $dim): array {
        // Simulação baseada no DDD/região do CEP
        // Substituir por integração real em produção
        $prefix = (int)substr($cep, 0, 2);

        // Prazo base por região (simplificado)
        $prazoBase = match(true) {
            $prefix >= 1  && $prefix <= 19  => 1,  // SP capital
            $prefix >= 20 && $prefix <= 28  => 3,  // RJ
            $prefix >= 40 && $prefix <= 48  => 5,  // BA
            $prefix >= 60 && $prefix <= 63  => 7,  // CE
            $prefix >= 69 && $prefix <= 69  => 10, // AM
            default                         => 4,
        };

        $peso  = max(0.3, (float)($dim['peso_kg'] ?? 0.5));
        $valor = max(1.0, (float)($dim['valor_carrinho'] ?? 50));

        // Cálculo simplificado de preço
        $basePac    = 10 + ($peso * 3.5) + ($valor * 0.01);
        $baseSedex  = 18 + ($peso * 6.0) + ($valor * 0.015);
        $baseMini   = 8  + ($peso * 2.0);

        return [
            [
                'servico' => 'PAC',
                'nome'    => 'PAC (Correios)',
                'prazo'   => $prazoBase + 3,
                'valor'   => round($basePac, 2),
            ],
            [
                'servico' => 'SEDEX',
                'nome'    => 'SEDEX (Correios)',
                'prazo'   => $prazoBase,
                'valor'   => round($baseSedex, 2),
            ],
            [
                'servico' => 'MINI',
                'nome'    => 'Carta Registrada',
                'prazo'   => $prazoBase + 5,
                'valor'   => round($baseMini, 2),
            ],
        ];
    }
}