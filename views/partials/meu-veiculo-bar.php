<?php
// Só renderiza se o cliente estiver logado
if (!Session::isClienteLogado()) return;

$veiculoAtivo = Session::get('meu_veiculo') ?? null;

if (is_null($veiculoAtivo)) {
    $VeiculoService = new VeiculoService();
    $veiculoAtivos  = $VeiculoService->listarPorCliente(Session::get('cliente_id'));

    if (!empty($veiculoAtivos)) {
        // Define o primeiro veículo como ativo por padrão
        foreach ($veiculoAtivos as $v) {
            if ($v['principal'] == 1) {
                $veiculoAtivo = $v;
                Session::set('meu_veiculo', $veiculoAtivo);
                break;
            }
        }
    }
}

// ── Link do nome da moto → categoria atual filtrada pela moto ──
// $motoUrlOverride permite que outras páginas (ex: catálogo de motos)
// definam a URL diretamente, sem depender do contexto de categoria.
// $category vem do CategoryController::show(); nas demais páginas o
// link cai no fallback /categoria/pecas (ajuste a rota padrão se preciso).
$motoUrl = $motoUrlOverride ?? (BASE_URL . '/categoria/pecas');
if (empty($motoUrlOverride) && $veiculoAtivo) {
    $catSlug = !empty($category['slug']) ? $category['slug'] : 'pecas';
    $query   = http_build_query(array_filter([
        'montadora_id' => $veiculoAtivo['montadora_id'] ?? null,
        'modelo_id'    => $veiculoAtivo['modelo_id']    ?? null,
        'ano'          => $veiculoAtivo['ano']           ?? null,
    ]));
    $motoUrl = BASE_URL . '/categoria/' . $catSlug . ($query ? '?' . $query : '');
}
?>

<div class="header-veiculo-bar">
  <div class="container header-veiculo-inner">

    <?php if ($veiculoAtivo): ?>
    <div class="hv-ativo <?= !empty($ehMotoAtual) ? 'hv-ativo--atual' : '' ?>">

      <?php if (!empty($ehMotoAtual)): ?>
      <!-- Modo "moto atual": confirma que esta página já é a moto
           cadastrada do cliente, sem repetir o link (seria redundante
           navegar para a página em que ele já está). -->
      <span class="hv-info hv-info--atual">
        <span class="hv-dot"
              style="background:<?= View::e($veiculoAtivo['cor'] ?? '#22c55e') ?>"></span>
        <?= IconLibrary::render('motorcycle', 'icon icon--md') ?>
        <span class="hv-label">
          <strong>Você está vendo peças da sua moto</strong>
          <em><?= View::e($veiculoAtivo['apelido'] ?: $veiculoAtivo['label']) ?></em>
        </span>
      </span>
      <?php else: ?>
      <!-- Nome da moto → peças compatíveis -->
      <a href="<?= View::e($motoUrl) ?>" class="hv-info">
        <span class="hv-dot"
              style="background:<?= View::e($veiculoAtivo['cor'] ?? '#22c55e') ?>"></span>
        <?= IconLibrary::render('motorcycle', 'icon icon--md') ?>
        <span class="hv-label">
          <strong><?= View::e($veiculoAtivo['apelido'] ?: $veiculoAtivo['label']) ?></strong>
          <?php if ($veiculoAtivo['apelido'] && $veiculoAtivo['label']): ?>
          <em><?= View::e($veiculoAtivo['label']) ?></em>
          <?php endif; ?>
        </span>
      </a>
      <?php endif; ?>

      <!-- Trocar → garagem do cliente (trocar moto principal) -->
      <a href="<?= BASE_URL ?>/minha-conta/garagem" class="hv-trocar">
        Trocar
      </a>
    </div>
    <?php else: ?>
    <a href="<?= BASE_URL ?>/minha-conta/garagem" class="hv-vazio">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <circle cx="5.5" cy="17.5" r="3.5"/>
        <circle cx="18.5" cy="17.5" r="3.5"/>
        <path d="M15 6h-2l-3 8H5.5"/>
      </svg>
      <span>Cadastre sua moto na <strong>Minha Garagem</strong></span>
    </a>
    <?php endif; ?>

  </div>
</div>

<style>
/* hv-ativo agora é container, não link — recria o clicável visual nos filhos */
.header-veiculo-bar .hv-ativo {
  display: flex;
  align-items: center;
  width: 100%;
}
.header-veiculo-bar .hv-info {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
  text-decoration: none;
  color: inherit;
}

/* Modo "moto atual" — confirma em vez de navegar */
.header-veiculo-bar .hv-ativo--atual {
  background: rgba(34, 197, 94, .06);
  border-radius: 8px;
}
.header-veiculo-bar .hv-info--atual {
  cursor: default;
}
.header-veiculo-bar .hv-info--atual .hv-label strong {
  font-weight: 600;
}

.header-veiculo-bar .hv-trocar {
  flex-shrink: 0;
  text-decoration: none;
}
</style>