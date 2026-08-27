<?php
declare(strict_types=1);

/**
 * app/models/PagamentoMetodo.php
 *
 * Persistência de pgto_metodos — a política COMERCIAL por forma de pagamento:
 * taxa, desconto, parcelamento e limites de valor.
 *
 * Não confundir com pgto_gateways: aquilo é a adquirente que processa; isto é
 * a regra da loja. A mesma regra de parcelamento vale independente de quem
 * processar, e é por isso que as duas coisas moram em tabelas separadas.
 */
class PagamentoMetodo extends Model
{
    protected string $table = 'pgto_metodos';

    /** Campos gravávels pela tela. `codigo` fica de fora: é a identidade. */
    private const CAMPOS = [
        'nome', 'ativo', 'ordem',
        'taxa_percentual', 'taxa_fixa_centavos',
        'desconto_percentual', 'desconto_max_percent',
        'parcelas_max', 'parcelas_sem_juros', 'parcela_min_centavos',
        'valor_min_centavos', 'valor_max_centavos',
    ];

    public function listar(): array
    {
        return $this->db->query(
            "SELECT * FROM pgto_metodos ORDER BY ordem ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function porCodigo(string $codigo): ?array
    {
        $st = $this->db->prepare("SELECT * FROM pgto_metodos WHERE codigo = ? LIMIT 1");
        $st->execute([$codigo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Só os métodos que o checkout pode oferecer. */
    public function ativos(): array
    {
        return $this->db->query(
            "SELECT * FROM pgto_metodos WHERE ativo = 1 ORDER BY ordem ASC, id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function salvar(int $id, array $dados): bool
    {
        $set = [];
        $par = [];
        foreach (self::CAMPOS as $c) {
            if (!array_key_exists($c, $dados)) continue;
            $set[]      = "`{$c}` = :{$c}";
            $par[$c]    = $dados[$c];
        }
        if (!$set) return false;

        $par['id'] = $id;
        $sql = "UPDATE pgto_metodos SET " . implode(', ', $set) . ", atualizado_em = NOW() WHERE id = :id";
        return $this->db->prepare($sql)->execute($par);
    }

    /**
     * Valida antes de gravar. Devolve lista de erros (vazia = ok).
     *
     * As regras existem porque cada uma delas, se passar, vira problema no
     * checkout ou dinheiro perdido:
     *   - parcelas_sem_juros > parcelas_max: a loja absorveria juros de
     *     parcelas que nem podem ser escolhidas.
     *   - desconto acima do teto: o desconto do método somado a cupom pode
     *     zerar a margem.
     *   - parcela_min alta demais frente ao valor mínimo: o cliente vê a
     *     opção de parcelar e ela nunca aparece.
     */
    public static function validar(array $d): array
    {
        $erros = [];

        $parcelasMax  = (int) ($d['parcelas_max'] ?? 1);
        $semJuros     = (int) ($d['parcelas_sem_juros'] ?? 1);
        $descPercent  = (float) ($d['desconto_percentual'] ?? 0);
        $descMax      = (float) ($d['desconto_max_percent'] ?? 0);
        $taxaPercent  = (float) ($d['taxa_percentual'] ?? 0);
        $valorMin     = (int) ($d['valor_min_centavos'] ?? 0);
        $valorMax     = $d['valor_max_centavos'] !== null && $d['valor_max_centavos'] !== ''
                        ? (int) $d['valor_max_centavos'] : null;

        if ($parcelasMax < 1 || $parcelasMax > 24) {
            $erros[] = 'Parcelamento máximo deve ficar entre 1 e 24.';
        }
        if ($semJuros < 1 || $semJuros > $parcelasMax) {
            $erros[] = 'Parcelas sem juros não pode passar do parcelamento máximo.';
        }
        if ($descPercent < 0 || $descPercent > 100) {
            $erros[] = 'Desconto deve ficar entre 0 e 100%.';
        }
        if ($descMax < 0 || $descMax > 100) {
            $erros[] = 'Teto de desconto deve ficar entre 0 e 100%.';
        }
        if ($descMax > 0 && $descPercent > $descMax) {
            $erros[] = 'O desconto do método não pode ser maior que o próprio teto.';
        }
        if ($taxaPercent < 0 || $taxaPercent > 100) {
            $erros[] = 'Taxa deve ficar entre 0 e 100%.';
        }
        if ($valorMin < 0) {
            $erros[] = 'Valor mínimo não pode ser negativo.';
        }
        if ($valorMax !== null && $valorMax > 0 && $valorMax < $valorMin) {
            $erros[] = 'Valor máximo não pode ser menor que o mínimo.';
        }

        return $erros;
    }

    /**
     * Simula o parcelamento para a tela — o lojista vê o efeito da regra
     * antes de salvar, em vez de descobrir no checkout.
     *
     * @return array<int,array{parcela:int, valor_parcela:int, total:int, com_juros:bool}>
     */
    public static function simular(array $metodo, int $valorCentavos): array
    {
        $max        = max(1, (int) ($metodo['parcelas_max'] ?? 1));
        $semJuros   = max(1, (int) ($metodo['parcelas_sem_juros'] ?? 1));
        $minParcela = (int) ($metodo['parcela_min_centavos'] ?? 0);
        $taxa       = (float) ($metodo['taxa_percentual'] ?? 0) / 100;

        $out = [];
        for ($n = 1; $n <= $max; $n++) {
            $comJuros = $n > $semJuros;
            // Juros simples por parcela excedente — a adquirente calcula o
            // seu próprio; aqui é só a projeção que o cliente vê.
            $total    = $comJuros
                ? (int) round($valorCentavos * (1 + $taxa * ($n - $semJuros)))
                : $valorCentavos;
            $porParcela = (int) round($total / $n);

            // Abaixo da parcela mínima a opção não é ofertada.
            if ($n > 1 && $minParcela > 0 && $porParcela < $minParcela) break;

            $out[] = [
                'parcela'       => $n,
                'valor_parcela' => $porParcela,
                'total'         => $total,
                'com_juros'     => $comJuros,
            ];
        }
        return $out;
    }
}
