<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  cond_perfil — nova condição (patch do FluxoNoRegistry.php)
 * ═══════════════════════════════════════════════════════════════════════════
 *  Ramifica pelo PERFIL do cliente: gênero, saldo disponível, newsletter,
 *  verificado. Cobre "campanha só para mulheres" E "inativo E tem saldo" numa
 *  condição só — com allowlist de campo e operador (nada de SQL arbitrário).
 *
 *  2 edições, ambas em app/services/FluxoNoRegistry.php.
 * ═══════════════════════════════════════════════════════════════════════════
 */


/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 1 — cole a classe abaixo junto das outras condições
   (depois de FluxoNoCondTemMoto, antes do bloco "// AÇÕES").
────────────────────────────────────────────────────────────────────────────── */

class FluxoNoCondPerfil extends FluxoNo
{
    // config: {"campo":"genero","operador":"=","valor":"F"}
    //         {"campo":"saldo_disponivel","operador":">=","valor":50}
    //
    // Allowlist de campo → coluna em `clientes` (nada fora disto roda).
    // Campos numéricos aceitam >=,>,<=,<,=,!= ; os demais aceitam =,!=.
    private const CAMPOS = [
        'genero'           => ['coluna' => 'genero',           'tipo' => 'texto'],
        'saldo_disponivel' => ['coluna' => 'saldo_disponivel', 'tipo' => 'numero'],
        'newsletter'       => ['coluna' => 'newsletter',       'tipo' => 'numero'],
        'verificado'       => ['coluna' => 'verificado',       'tipo' => 'numero'],
    ];

    public function portas(): array { return ['true', 'false']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        if (!$cid) return 'false';

        $campo = (string)($config['campo'] ?? '');
        if (!isset(self::CAMPOS[$campo])) return 'false';   // fora da allowlist
        $meta = self::CAMPOS[$campo];

        $op = (string)($config['operador'] ?? '=');
        $opsPermitidos = $meta['tipo'] === 'numero'
            ? ['=', '!=', '>=', '>', '<=', '<']
            : ['=', '!='];
        if (!in_array($op, $opsPermitidos, true)) return 'false';

        try {
            // Coluna vem da allowlist (segura para interpolar); valor é bind
            $st = $db->prepare("SELECT `{$meta['coluna']}` FROM clientes WHERE id = :c LIMIT 1");
            $st->execute([':c' => $cid]);
            if ($st->rowCount() === 0) { /* SQLite: rowCount pode ser 0 */ }
            $atual = $st->fetchColumn();
            if ($atual === false) return 'false';
        } catch (Throwable $e) {
            return 'false';
        }

        if ($meta['tipo'] === 'numero') {
            $a = (float)$atual;
            $v = (float)($config['valor'] ?? 0);
            $ok = match ($op) {
                '='  => abs($a - $v) < 0.00001,
                '!=' => abs($a - $v) >= 0.00001,
                '>=' => $a >= $v,
                '>'  => $a >  $v,
                '<=' => $a <= $v,
                '<'  => $a <  $v,
                default => false,
            };
        } else {
            $a = (string)$atual;
            $v = (string)($config['valor'] ?? '');
            $ok = ($op === '!=') ? ($a !== $v) : ($a === $v);
        }

        return $ok ? 'true' : 'false';
    }
}


/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 2 — registre no MAPA do FluxoNoRegistry.

   ACHE (a última condição no MAPA):
        'cond_tem_moto'         => FluxoNoCondTemMoto::class,

   ADICIONE logo abaixo:
        'cond_perfil'           => FluxoNoCondPerfil::class,
────────────────────────────────────────────────────────────────────────────── */
