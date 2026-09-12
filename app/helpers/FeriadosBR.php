<?php
/**
 * Calendário de feriados brasileiros — cálculo puro, sem banco.
 *
 * Existe para a tela de operação da transportadora poder listar "os próximos
 * feriados" sem que ninguém precise cadastrar datas todo mês de dezembro.
 * O banco guarda só a EXCEÇÃO (opera ou não naquele dia); o calendário em si
 * é calculado.
 *
 * Móveis saem da Páscoa pelo algoritmo gregoriano anônimo — `easter_date()` do
 * PHP depende da extensão `calendar`, que não está garantida no servidor.
 *
 * Estadual: só RS, que é onde a loja opera. Outro estado entra em ESTADUAIS.
 */
class FeriadosBR
{
    /** Fixos nacionais: 'm-d' => nome. */
    private const NACIONAIS_FIXOS = [
        '01-01' => 'Confraternização Universal',
        '04-21' => 'Tiradentes',
        '05-01' => 'Dia do Trabalho',
        '09-07' => 'Independência do Brasil',
        '10-12' => 'Nossa Senhora Aparecida',
        '11-02' => 'Finados',
        '11-15' => 'Proclamação da República',
        '11-20' => 'Consciência Negra',   // nacional desde a Lei 14.759/2023
        '12-25' => 'Natal',
    ];

    /** Fixos estaduais por UF. */
    private const ESTADUAIS = [
        'RS' => ['09-20' => 'Revolução Farroupilha'],
    ];

    /**
     * Feriados de um ano, ordenados por data.
     *
     * @return array<int,array{data:string,nome:string,tipo:string}> data = Y-m-d
     */
    public static function doAno(int $ano, string $uf = 'RS'): array
    {
        $out = [];
        foreach (self::NACIONAIS_FIXOS as $md => $nome) {
            $out[] = ['data' => sprintf('%04d-%s', $ano, $md), 'nome' => $nome, 'tipo' => 'nacional'];
        }
        foreach (self::ESTADUAIS[mb_strtoupper($uf)] ?? [] as $md => $nome) {
            $out[] = ['data' => sprintf('%04d-%s', $ano, $md), 'nome' => $nome, 'tipo' => 'estadual'];
        }

        $pascoa = self::pascoa($ano);
        foreach ([
            [-48, 'Carnaval (segunda)'],
            [-47, 'Carnaval'],
            [-2,  'Sexta-feira Santa'],
            [60,  'Corpus Christi'],
        ] as [$delta, $nome]) {
            $d = (new DateTimeImmutable($pascoa))->modify(($delta >= 0 ? '+' : '') . $delta . ' days');
            $out[] = ['data' => $d->format('Y-m-d'), 'nome' => $nome, 'tipo' => 'movel'];
        }

        usort($out, static fn($a, $b) => strcmp($a['data'], $b['data']));
        return $out;
    }

    /**
     * Os próximos N feriados a partir de hoje (inclusive), atravessando o ano.
     *
     * @return array<int,array{data:string,nome:string,tipo:string,dia_semana:string}>
     */
    public static function proximos(int $quantos = 4, string $uf = 'RS', ?string $hoje = null): array
    {
        $hoje = $hoje ?: date('Y-m-d');
        $ano  = (int) substr($hoje, 0, 4);

        $lista = array_merge(self::doAno($ano, $uf), self::doAno($ano + 1, $uf));
        $lista = array_values(array_filter($lista, static fn($f) => $f['data'] >= $hoje));
        $lista = array_slice($lista, 0, max(1, $quantos));

        foreach ($lista as &$f) {
            $f['dia_semana'] = self::DIAS[(int) (new DateTimeImmutable($f['data']))->format('w')];
        }
        return $lista;
    }

    /** É feriado? Devolve o nome, ou null. */
    public static function nomeDe(string $data, string $uf = 'RS'): ?string
    {
        $ano = (int) substr($data, 0, 4);
        foreach (self::doAno($ano, $uf) as $f) {
            if ($f['data'] === $data) return $f['nome'];
        }
        return null;
    }

    public const DIAS = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira',
                         'Quinta-feira', 'Sexta-feira', 'Sábado'];

    /**
     * Domingo de Páscoa (algoritmo gregoriano anônimo / Meeus-Jones-Butcher).
     * Conferido contra as datas oficiais de 2024 a 2030.
     */
    public static function pascoa(int $ano): string
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
    }
}
