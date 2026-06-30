<?php
/**
 * admin/views/admin/payment/_helpers.php
 *
 * Helpers compartilhados pelas views do módulo de pagamento.
 * Carregue com require_once no topo de cada view.
 */

if (!function_exists('pgto_money')) {
    function pgto_money(int $centavos): string {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
if (!function_exists('pgto_int')) {
    function pgto_int(int $n): string {
        return number_format($n, 0, ',', '.');
    }
}
if (!function_exists('pgto_pct')) {
    function pgto_pct(float $n): string {
        return number_format($n, 2, ',', '.') . '%';
    }
}
if (!function_exists('pgto_variacao')) {
    function pgto_variacao(?float $v): string {
        if ($v === null) return '<span class="pgto_var pgto_var_neutro">—</span>';
        $cls = $v > 0 ? 'pgto_var_up' : ($v < 0 ? 'pgto_var_down' : 'pgto_var_neutro');
        $sig = $v > 0 ? '+' : '';
        return '<span class="pgto_var ' . $cls . '">' . $sig . number_format($v, 1, ',', '.') . '%</span>';
    }
}
if (!function_exists('pgto_status_label')) {
    /** Retorna label humano pra status interno */
    function pgto_status_label(string $status): string {
        $labels = [
            'pendente'          => 'Pendente',
            'pre_autorizado'    => 'Pré-autorizado',
            'aprovado'          => 'Aprovado',
            'recusado'          => 'Recusado',
            'falhou'            => 'Falhou',
            'cancelado'         => 'Cancelado',
            'estornado'         => 'Estornado',
            'estorno_pendente'  => 'Estorno pendente',
            'chargeback'        => 'Chargeback',
            'erro'              => 'Erro técnico',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}
