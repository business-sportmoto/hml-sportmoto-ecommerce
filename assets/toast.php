<script>
// ── Atalhos rápidos ──────────────────────────────────
Toast.success('Produto adicionado ao carrinho!');
Toast.error('Erro ao processar pagamento.');
Toast.warning('Apenas 2 unidades em estoque.');
Toast.info('Frete grátis acima de R$ 299.');
Toast.loading('Calculando frete...');

// ── Controle total ───────────────────────────────────
Toast.show({
  type:     'success',
  title:    'Pedido criado',
  message:  '#12345 confirmado. Aguarde o e-mail.',
  duration:  6000,          // 0 = não fecha sozinho
  position: 'bottom-center',
  progress:  true,          // barra de tempo
  closable:  true,
});

// ── Toast com ações (ex: desfazer, confirmar) ────────
Toast.action('Endereço removido.', [
  { label: 'Desfazer', action: () => restaurarEndereco() },
  { label: 'OK', primary: true },
]);

// ── Loading → atualiza para sucesso ──────────────────
const id = Toast.loading('Enviando pedido...');
// ... após concluir:
Toast.update(id, {
  type:    'success',
  message: 'Pedido enviado com sucesso!',
  duration: 4000,
});

// ── Fechar programaticamente ─────────────────────────
const id = Toast.show({ ... });
Toast.dismiss(id);      // fecha um
Toast.dismissAll();     // fecha todos

// ── Configuração global ──────────────────────────────
Toast.configure({
  position:  'bottom-right',  // padrão do site inteiro
  duration:   5000,
  maxVisible: 4,
});

</script>


