<?php
// views/partials/verified-badge.php
// Uso: View::partial('partials/verified-badge', ['verificado' => $verificado])
// Ou:  View::partial('partials/verified-badge', ['cliente_id' => $clienteId])
//
// Parâmetros opcionais:
//   $verificado  bool   — passa direto se já tiver o valor
//   $cliente_id  int    — busca do banco se não tiver o valor
//   $size        string — 'sm' | 'md' (padrão) | 'lg'
//   $show_text   bool   — mostra o texto "Verificado" ao lado (padrão: false)
$isLogado  = Session::isClienteLogado();
$cliente_id = $isLogado ? (int)Session::get('cliente_id') : null;
// Resolve o estado de verificação
if (!isset($verificado) && !empty($cliente_id)) {
    $stmt = Database::getInstance()->getConnection()->prepare(
        "SELECT verificado FROM clientes WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$cliente_id]);
    $verificado = (bool) $stmt->fetchColumn();

    Session::set('cliente_verificado', $verificado);
}

if (empty($verificado)) return; // Não renderiza nada se não verificado

$size      = $size      ?? 'md';
$show_text = $show_text ?? false;

$sizes = [
    'sm' => ['icon' => 14, 'badge' => 18, 'font' => 11],
    'md' => ['icon' => 16, 'badge' => 22, 'font' => 12],
    'lg' => ['icon' => 20, 'badge' => 28, 'font' => 13],
];
$s = $sizes[$size] ?? $sizes['md'];
?>
<span class="verified-badge verified-badge--<?= $size ?>"
      title="Perfil verificado"
      aria-label="Perfil verificado"
      role="img">
  <svg width="<?= $s['icon'] ?>" height="<?= $s['icon'] ?>"
       viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <!-- Escudo de fundo -->
    <path d="M12 2L3 6v6c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V6L12 2z"
          fill="#1d9bf0"/>
    <!-- Check -->
    <polyline points="8 12 11 15 16 9"
              stroke="#fff" stroke-width="2.2"
              stroke-linecap="round" stroke-linejoin="round"/>
  </svg>
  <?php if ($show_text): ?>
    <span class="verified-badge-text" style="font-size:<?= $s['font'] ?>px">Verificado</span>
  <?php endif; ?>
</span>