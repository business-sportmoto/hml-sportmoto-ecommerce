<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/ClearSaleApresentacao.php
 *
 * Mora em services, não em helpers: decide "o que fazer" por status, e isso
 * é regra de negócio (.claude/rules/php-mvc.md). Fica ao lado do
 * ClearSaleService, na pasta que as três frentes carregam.
 *
 * Como o parecer da ClearSale aparece para quem decide.
 *
 * ── DOIS NÍVEIS DE CONFIANÇA ─────────────────────────────────────
 *
 * O STATUS (AMA, APA, RPM…) é documentado pela ClearSale — tem significado
 * oficial. Por isso é dele que sai a recomendação "o que fazer".
 *
 * O SCORE não é. A documentação pública do Total / Total Garantido só mostra
 * exemplos (`"score": 99.99`), sem dizer faixa nem direção. Ele aparece no
 * medidor, com cor, como apoio visual — mas NÃO decide nada, e o card avisa
 * enquanto a escala não for confirmada. Ver pagamentos-antifraude no Vault.
 *
 * Trocar a direção da escala é mudar UMA constante: SCORE_MAIOR_E_MAIS_RISCO.
 */
final class ClearSaleApresentacao
{
    /**
     * true  = quanto maior o score, maior o risco de fraude.
     * false = quanto maior, mais confiável.
     */
    public const SCORE_MAIOR_E_MAIS_RISCO = true;

    /** Vira true quando a ClearSale confirmar a escala por escrito. */
    public const ESCALA_CONFIRMADA = false;

    public const SCORE_MAX = 100;

    /**
     * Faixas VISUAIS, em "pontos de risco" (0 = nenhum, 100 = máximo). Não são
     * limites de decisão da ClearSale — ela não publica nenhum.
     */
    private const FAIXAS = [
        [30,  'Risco baixo',    'ok'],
        [70,  'Risco moderado', 'alerta'],
        [101, 'Risco alto',     'ruim'],
    ];

    /**
     * Os 11 status da documentação oficial, em português de quem decide.
     * [título, o que significa, tom]
     */
    private const STATUS = [
        'APA' => ['Aprovado automaticamente', 'A ClearSale aprovou por regra automática.', 'ok'],
        'APM' => ['Aprovado por analista', 'Um analista da ClearSale revisou e aprovou.', 'ok'],
        'APP' => ['Aprovado por política', 'Aprovado por uma política da ClearSale ou da loja.', 'ok'],
        'NVO' => ['Recebido, ainda sem parecer', 'A ClearSale recebeu o pedido e ainda vai classificar.', 'espera'],
        'AMA' => ['Na fila de análise manual', 'O pedido está na fila para um analista da ClearSale revisar.', 'espera'],
        'SUS' => ['Suspenso por suspeita de fraude', 'Um analista suspendeu o pedido por suspeita de fraude.', 'ruim'],
        'FRD' => ['Fraude confirmada', 'A operadora ou o titular do cartão confirmou que não reconhece a compra.', 'ruim'],
        'RPA' => ['Reprovado automaticamente', 'Reprovado por regra automática da ClearSale.', 'ruim'],
        'RPP' => ['Reprovado por política', 'Reprovado por política da ClearSale ou da loja.', 'ruim'],
        'RPM' => ['Reprovado sem suspeita', 'Reprovado por falta de contato com o cliente ou por restrição no CPF.', 'ruim'],
        'CAN' => ['Cancelado', 'Cancelado a pedido do cliente, ou por pedido em duplicidade.', 'neutro'],
    ];

    /** @return array{codigo:string, titulo:string, explicacao:string, tom:string} */
    public static function status(?string $codigo): array
    {
        $cod = strtoupper(trim((string) $codigo));
        if (isset(self::STATUS[$cod])) {
            [$titulo, $explicacao, $tom] = self::STATUS[$cod];
            return compact('titulo', 'explicacao', 'tom') + ['codigo' => $cod];
        }
        return [
            'codigo'     => $cod !== '' ? $cod : '—',
            'titulo'     => 'Status não documentado',
            'explicacao' => $cod !== ''
                ? "A ClearSale devolveu “{$cod}”, que não consta na documentação dela."
                : 'A ClearSale respondeu sem status.',
            'tom'        => 'alerta',
        ];
    }

    /**
     * O próximo passo, pelo STATUS. Nunca pelo score (ver cabeçalho).
     *
     * Segue a regra do projeto: aprovação da ClearSale pode liberar; reprovação
     * vai para decisão humana, porque recusar pedido capturado é estorno.
     */
    public static function oQueFazer(?string $codigo, bool $homologacao): string
    {
        $cod = strtoupper(trim((string) $codigo));
        return match ($cod) {
            'APA', 'APM', 'APP' => 'A ClearSale aprovou. Pode liberar o pedido.',
            'NVO'               => 'Aguarde a classificação e consulte de novo em alguns minutos.',
            'AMA'               => $homologacao
                ? 'Em homologação não há analista: o pedido fica nesta fila. Para testar a decisão, use os dados do cliente e as tentativas de pagamento abaixo.'
                : 'Aguarde o parecer do analista. Se precisar decidir antes, confira os dados do cliente e as tentativas de pagamento abaixo.',
            'SUS'               => 'Não libere sem falar com o cliente. Se não confirmar a compra, recuse.',
            'FRD'               => 'Recuse o pedido e marque como fraude confirmada.',
            'RPA', 'RPP', 'RPM' => 'O recomendado é recusar. Só libere se falar com o cliente e tiver motivo claro.',
            'CAN'               => 'O próprio cliente cancelou, ou o pedido está duplicado. Recuse.',
            default             => 'O pedido fica retido. Decida pelos dados do cliente e, se puder, pergunte à ClearSale o que o status significa.',
        };
    }

    /**
     * Faixa visual do score, ou null sem score.
     *
     * @return array{risco:float, rotulo:string, tom:string}|null
     */
    public static function faixa(?float $score): ?array
    {
        if ($score === null) return null;
        $risco = self::risco($score);
        foreach (self::FAIXAS as [$teto, $rotulo, $tom]) {
            if ($risco < $teto) return compact('risco', 'rotulo', 'tom');
        }
        return ['risco' => $risco, 'rotulo' => 'Risco alto', 'tom' => 'ruim'];
    }

    /** Score → pontos de risco (0 = nenhum, 100 = máximo), pela direção configurada. */
    public static function risco(float $score): float
    {
        $s = max(0.0, min((float) self::SCORE_MAX, $score));
        return self::SCORE_MAIOR_E_MAIS_RISCO ? $s : self::SCORE_MAX - $s;
    }

    // ── Geometria do medidor (SVG, viewBox 0 0 120 68) ─────────────────

    /** Ponto do arco na fração t (0 = esquerda, 1 = direita). */
    public static function ponto(float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        $a = M_PI * (1 - $t);
        return [round(60 + 50 * cos($a), 2), round(60 - 50 * sin($a), 2)];
    }

    /** `d` do trecho do arco entre as frações t0 e t1. */
    public static function arco(float $t0, float $t1): string
    {
        [$x0, $y0] = self::ponto($t0);
        [$x1, $y1] = self::ponto($t1);
        return "M {$x0} {$y0} A 50 50 0 0 1 {$x1} {$y1}";
    }

    /**
     * Os trechos coloridos do fundo do medidor, da esquerda para a direita,
     * já na direção da escala: o lado "arriscado" é sempre o vermelho.
     *
     * @return array<int, array{d:string, tom:string}>
     */
    public static function trechos(): array
    {
        $cortes = [[0.0, 0.30, 'ok'], [0.30, 0.70, 'alerta'], [0.70, 1.0, 'ruim']];
        if (!self::SCORE_MAIOR_E_MAIS_RISCO) {
            $cortes = [[0.0, 0.30, 'ruim'], [0.30, 0.70, 'alerta'], [0.70, 1.0, 'ok']];
        }
        return array_map(static fn($c) => ['d' => self::arco($c[0], $c[1]), 'tom' => $c[2]], $cortes);
    }

    /** Texto da escala, com o aviso enquanto ela não for confirmada. */
    public static function legendaEscala(): string
    {
        $dir = self::SCORE_MAIOR_E_MAIS_RISCO
            ? 'quanto maior, maior o risco de fraude'
            : 'quanto maior, mais confiável';
        return 'Score de 0 a ' . self::SCORE_MAX . ": {$dir}."
             . (self::ESCALA_CONFIRMADA ? '' : ' A ClearSale não publica a escala — confirmar com a integração. Até lá, decida pelo status, não pelo número.');
    }
}
