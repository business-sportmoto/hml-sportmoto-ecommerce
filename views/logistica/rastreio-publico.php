<?php
/**
 * Página pública de rastreio (autossuficiente — não usa layout admin).
 * Recebe: $rastreio (array sanitizado de RastreioService::sanitizarPublico) ou null.
 * Estilo próprio inline para funcionar independente de assets do painel.
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$r = $rastreio ?? null;

// cor do status
$corDe = static function (?string $s): string {
    return match ($s) {
        'entregue'      => 'ok',
        'saiu_entrega'  => 'warn',
        'devolucao', 'ocorrencia' => 'danger',
        'postado', 'em_transito', 'etiqueta_emitida' => 'info',
        default         => 'neutral',
    };
};
$dataBR = static function (?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    return $ts ? date('d/m/Y H:i', $ts) : (string)$d;
};
$pin = class_exists('IconLibrary') ? IconLibrary::render('globe-location') : '';
if ($pin === '') $pin = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Rastreio do pedido<?= $r ? ' · ' . $e($r['codigo_rastreio']) : '' ?></title>
<style>
:root{
    --brand:#2563eb; --bg:#f4f6fb; --surface:#fff; --border:#e6e9f0;
    --ink:#0f172a; --ink2:#475569; --ink3:#94a3b8;
    --ok:#16a34a; --warn:#d97706; --danger:#dc2626; --info:#2563eb; --neutral:#64748b;
    --radius:16px;
}
@media (prefers-color-scheme: dark){
    :root{ --bg:#0b1020; --surface:#131a2b; --border:#243049; --ink:#e8edf7; --ink2:#aab6cc; --ink3:#6b7890; }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.5;-webkit-font-smoothing:antialiased}
.wrap{max-width:640px;margin:0 auto;padding:24px 18px 60px}
.brand{display:flex;align-items:center;gap:10px;color:var(--brand);font-weight:700;font-size:15px;margin-bottom:20px}
.brand svg{width:22px;height:22px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 8px 24px rgba(15,23,42,.05)}
.status-line{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}
.pill{display:inline-flex;align-items:center;padding:5px 12px;border-radius:999px;font-size:13px;font-weight:600}
.pill.ok{background:color-mix(in srgb,var(--ok) 14%,transparent);color:var(--ok)}
.pill.warn{background:color-mix(in srgb,var(--warn) 16%,transparent);color:var(--warn)}
.pill.danger{background:color-mix(in srgb,var(--danger) 14%,transparent);color:var(--danger)}
.pill.info{background:color-mix(in srgb,var(--info) 13%,transparent);color:var(--info)}
.pill.neutral{background:color-mix(in srgb,var(--neutral) 14%,transparent);color:var(--neutral)}
.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:var(--ink2)}
.meta{display:flex;gap:22px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.meta div{font-size:13px}
.meta .k{color:var(--ink3);font-size:11px;text-transform:uppercase;letter-spacing:.03em}
.meta .v{color:var(--ink);font-weight:600;margin-top:2px}
.alert{margin-top:14px;padding:10px 14px;border-radius:12px;font-size:13px}
.alert.warn{background:color-mix(in srgb,var(--warn) 12%,transparent);color:var(--warn)}
h2{font-size:14px;margin:26px 4px 12px;color:var(--ink2)}
.tl{list-style:none;margin:0;padding:0}
.tl li{position:relative;padding:0 0 20px 26px;border-left:2px solid var(--border)}
.tl li:last-child{border-left-color:transparent;padding-bottom:4px}
.tl li::before{content:'';position:absolute;left:-7px;top:3px;width:12px;height:12px;border-radius:50%;background:var(--brand);border:3px solid var(--surface)}
.tl li.done::before{background:var(--ok)}
.tl .t{font-weight:600;font-size:14px}
.tl .d{font-size:12px;color:var(--ink3);margin-top:1px}
.tl .desc{font-size:13px;color:var(--ink2);margin-top:3px}
.empty{text-align:center;color:var(--ink3);padding:50px 20px}
.empty svg{width:40px;height:40px;color:var(--ink3);margin-bottom:12px}
.foot{text-align:center;color:var(--ink3);font-size:12px;margin-top:26px}
</style>
</head>
<body>
<div class="wrap">
    <div class="brand"><?= $pin ?> SportMoto · Rastreio</div>

<?php if (!$r): ?>
    <div class="card">
        <div class="empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <div style="font-weight:600;color:var(--ink2)">Rastreio não encontrado</div>
            <div>O link pode estar incorreto ou expirado. Confira com a loja.</div>
        </div>
    </div>
<?php else:
    $cor = $corDe($r['status_interno'] ?? null);
    $eventos = $r['eventos'] ?? [];
?>
    <div class="card">
        <div class="status-line">
            <span class="pill <?= $cor ?>"><?= $e($r['status_label'] ?? '—') ?></span>
            <span class="code"><?= $e($r['codigo_rastreio'] ?? '') ?></span>
        </div>
        <?php if (!empty($r['transportadora_nome'])): ?><div style="color:var(--ink2);font-size:13px">via <?= $e($r['transportadora_nome']) ?></div><?php endif; ?>

        <div class="meta">
            <?php if (!empty($r['destino_cidade']) || !empty($r['destino_uf'])): ?>
                <div><div class="k">Destino</div><div class="v"><?= $e(trim(($r['destino_cidade'] ?? '') . ' / ' . ($r['destino_uf'] ?? ''), ' /')) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($r['previsao_entrega'])): ?>
                <div><div class="k">Previsão</div><div class="v"><?= $e($dataBR($r['previsao_entrega'])) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($r['entregue_em'])): ?>
                <div><div class="k">Entregue em</div><div class="v"><?= $e($dataBR($r['entregue_em'])) ?></div></div>
            <?php elseif (!empty($r['postado_em'])): ?>
                <div><div class="k">Postado em</div><div class="v"><?= $e($dataBR($r['postado_em'])) ?></div></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($r['atraso']) && empty($r['entregue_em'])): ?>
            <div class="alert warn">A entrega está além da previsão inicial. Se precisar, fale com a loja.</div>
        <?php endif; ?>
    </div>

    <h2>Movimentações</h2>
    <?php if (!$eventos): ?>
        <div class="card"><div class="empty" style="padding:30px 20px">Ainda sem movimentações registradas.</div></div>
    <?php else: ?>
        <ul class="tl">
            <?php foreach ($eventos as $ev): $done = ($ev['status_interno'] ?? '') === 'entregue'; ?>
                <li class="<?= $done ? 'done' : '' ?>">
                    <div class="t"><?= $e($ev['status_label'] ?? '') ?></div>
                    <div class="d"><?= $e($dataBR($ev['data_evento'] ?? '')) ?><?= !empty($ev['local']) ? ' · ' . $e($ev['local']) : '' ?></div>
                    <?php if (!empty($ev['descricao']) && $ev['descricao'] !== ($ev['status_label'] ?? '')): ?>
                        <div class="desc"><?= $e($ev['descricao']) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

    <div class="foot">Atualizado automaticamente • SportMoto</div>
</div>
</body>
</html>
