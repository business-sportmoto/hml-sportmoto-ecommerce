<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/helpers/ClienteBadges.php
// FONTE ÚNICA dos indicadores de origem/status do cliente.
// Consumido pela LISTAGEM (badges em massa) e pelo DETALHE
// (bloco de origem/sincronização). Duplicar a lógica entre
// as duas telas faria elas divergirem no 1º ajuste — mesmo
// princípio do Cargos.php.
//
// Estrutura de retorno segue a convenção do projeto
// (calcularRiscos): ['tipo' => classe, 'label' => texto,
// 'icone' => emoji, 'titulo' => tooltip].
// ════════════════════════════════════════════════════════

final class ClienteBadges {

    /**
     * Calcula os 3 indicadores a partir de UMA linha de cliente.
     *
     * A linha DEVE conter (via JOIN no model):
     *   - email_verificado  (usuarios.email_verificado) — NÃO
     *     clientes.verificado, que nunca é preenchida (sempre 0)
     *   - tray_id           (clientes.tray_id)
     *   - bling_id          (clientes.bling_id)
     *   - bling_sincronizado_em, atualizado_em (para o frescor)
     *
     * Chaves ausentes são tratadas como "não" — nunca dispara
     * warning por falta de dado.
     */
    public static function para(array $c): array {
        return [
            'verificado' => self::verificado($c),
            'origem'     => self::origem($c),
            'bling'      => self::bling($c),
        ];
    }

    /** E-mail verificado — lê usuarios.email_verificado. */
    private static function verificado(array $c): array {
        $ok = !empty($c['email_verificado']);
        return $ok
            ? ['tipo'=>'success', 'icone'=>'✓', 'label'=>'Verificado',
               'titulo'=>'E-mail confirmado pelo cliente']
            : ['tipo'=>'warning', 'icone'=>'⚠', 'label'=>'Não verificado',
               'titulo'=>'Cliente ainda não confirmou o e-mail'];
    }

    /** Origem do cadastro — Tray (importado) vs nativo. */
    private static function origem(array $c): array {
        $daTray = !empty($c['tray_id']);
        return $daTray
            ? ['tipo'=>'info', 'icone'=>'↧', 'label'=>'Tray',
               'titulo'=>'Importado da Tray (ID '.self::e($c['tray_id']).')']
            : ['tipo'=>'neutral', 'icone'=>'★', 'label'=>'Nativo',
               'titulo'=>'Cadastro nativo na loja'];
    }

    /**
     * Sincronização com o Bling — TRÊS estados, não dois:
     *   - nunca sincronizado (sem bling_id)
     *   - sincronizado e em dia
     *   - sincronizado PORÉM defasado (cliente mudou depois da
     *     última sync) → o estado que um badge só-de-presença
     *     esconderia, mostrando verde para dado velho
     */
    private static function bling(array $c): array {
        if (empty($c['bling_id'])) {
            return ['tipo'=>'neutral', 'icone'=>'○', 'label'=>'Sem Bling',
                    'titulo'=>'Cliente ainda não sincronizado com o Bling'];
        }

        $sync = $c['bling_sincronizado_em'] ?? null;
        $mod  = $c['atualizado_em'] ?? null;

        if ($sync && $mod && strtotime($mod) > strtotime($sync)) {
            return ['tipo'=>'warning', 'icone'=>'⟳', 'label'=>'Bling desatualizado',
                    'titulo'=>'Cliente alterado após a última sincronização ('
                              .date('d/m/Y H:i', strtotime($sync)).')'];
        }

        return ['tipo'=>'success', 'icone'=>'✓', 'label'=>'Bling',
                'titulo'=>'Sincronizado com o Bling'
                          .($sync ? ' em '.date('d/m/Y H:i', strtotime($sync)) : '')];
    }

    /**
     * HTML dos badges para a LISTAGEM (compacto, só ícone+label).
     * Escapado aqui — a view só ecoa.
     */
    public static function html(array $c): string {
        $cores = [
            'success' => '#16a34a;background:#f0fdf4;border-color:#bbf7d0',
            'warning' => '#d97706;background:#fffbeb;border-color:#fde68a',
            'info'    => '#1d4ed8;background:#eff6ff;border-color:#bfdbfe',
            'neutral' => '#64748b;background:#f8fafc;border-color:#e2e8f0',
            'danger'  => '#dc2626;background:#fef2f2;border-color:#fecaca',
        ];
        $out = '';
        foreach (self::para($c) as $b) {
            $cor = $cores[$b['tipo']] ?? $cores['neutral'];
            $out .= '<span class="cli-badge" title="'.self::e($b['titulo']).'"'
                  . ' style="display:inline-flex;align-items:center;gap:3px;'
                  . 'font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;'
                  . 'border:1px solid;color:'.$cor.';">'
                  . self::e($b['icone']).' '.self::e($b['label']).'</span> ';
        }
        return $out;
    }

    private static function e(string|int|null $v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}