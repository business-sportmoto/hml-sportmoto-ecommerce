/**
 * Frete na página de produto (vitrine) — jQuery v4.
 *
 * - Se o cliente já tem CEP (cookie `ec_cep`), busca o frete ao abrir a página
 *   e mostra APENAS a opção mais barata + botão "ver todas".
 * - Se não tem CEP, mostra um botão que abre a SUA modal de localização
 *   (.btn-open-location) para o cliente informar/salvar o CEP.
 * - "Ver todas as opções" abre uma modal própria com todos os fretes (preço + prazo).
 *
 * Requer: window.BASE_URL. Opcional: window.EC_CART_SUBTOTAL (subtotal atual do
 * carrinho, para o CTA "adicione e ganhe frete grátis").
 *
 * Integração com o seu fluxo de CEP (uma linha, opcional mas recomendado):
 *   - após salvar o CEP:   window.FreteProduto && FreteProduto.atualizar(res.cep);
 *   - após remover o CEP:  window.FreteProduto && FreteProduto.atualizar(null);
 * (Sem isso, o widget se atualiza sozinho relendo o cookie após o submit.)
 */
