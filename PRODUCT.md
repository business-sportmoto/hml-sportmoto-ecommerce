# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

**Principal — motociclista dono da moto.** Consumidor final comprando peça ou
acessório para a própria moto. Sabe o modelo da moto; nem sempre sabe o nome
técnico da peça. Compra esporádica (manutenção ou desejo), decide sozinho,
compara preço antes de fechar. É ele quem orienta as decisões de produto: se o
site funciona bem para ele, o negócio vai bem.

Nenhum outro público comprador foi confirmado como central. Oficinas e
mecânicos podem comprar, mas não são o usuário que define prioridade.

**Usuários internos (painel admin).** Cinco cargos com escopos distintos —
`super`, `gerente`, `vendedor`, `editor`, `estoque`. O vendedor tem código
próprio e comissão atrelada ao pedido, e opera a Central de Recuperação de
carrinho. O painel é uma superfície de trabalho diária, não um acessório.

## Product Purpose

Vender peças e acessórios de moto no Brasil, online, como extensão de uma loja
física que já existe e já tem reputação.

Sucesso é o motociclista encontrar a peça que **serve** na moto dele e comprar
com confiança de que ela serve e de que está disponível de verdade.

## Positioning

Quatro coisas que uma loja de peças vizinha não copiaria honestamente:

1. **Compatibilidade confiável** — achar a peça certa para a moto exata sem
   erro. A base de compatibilidade produto↔moto e a navegação por
   montadora/modelo são o ativo.
2. **Estoque real e amplo** — catálogo grande com saldo verdadeiro: o que
   aparece disponível está disponível.
3. **Atendimento humano que fecha** — vendedor com código, WhatsApp,
   Instagram e recuperação de carrinho. A venda é assistida, não só
   self-service.
4. **Loja física e autoridade técnica** — ponto físico, anos de mercado, gente
   que entende de moto. A loja online é extensão de uma reputação existente.

## Operating Context

- **Replatform em curso.** `www.sportmoto.com.br` ainda roda na **Tray**; o
  site novo está em homologação (`hml` / `homo-v2`) esperando a virada de DNS.
  Na virada os slugs precisam bater com os da Tray — é o que dispensa tabela
  de 301.
- **O Bling é o dono do estoque.** O site é espelho e nunca escreve saldo. O
  Bling baixa quando o pedido entra e propaga para todos os canais (site +
  marketplaces). Cancelar pedido no painel do site não devolve estoque.
- **Venda assistida.** Vendedores com código próprio, Central de Recuperação
  de carrinho abandonado, atendimento por WhatsApp e Instagram.
- **Painel admin extenso.** Pedidos, catálogo, estoque, promoções e cupons,
  clientes, e-mail marketing, automações (fluxos), notificações in-app,
  BI/Power BI, Central de Marketing IA, logística e etiquetas de envio.
- **Pagamentos.** Cartão (Mercado Pago, Cielo, Malga), PIX e boleto.
  Antifraude ClearSale retém pedidos. 3DS pronto e desligado.
- **Consentimento LGPD fail-closed** governa marketing e analytics: sem
  consentimento, o evento não sai.

## Capabilities and Constraints

**Confirmado no código:** catálogo com variações e SKUs; compatibilidade
produto↔moto com páginas por montadora e modelo; marcas; busca; central de
ajuda; avaliações de clientes com upload de mídia; lista de desejos; aviso de
estoque; carrinho compartilhável; rastreio por token; PWA; tema claro/escuro.

**Restrições duráveis:**

- Slugs do catálogo amarrados aos da Tray até a virada de DNS.
- Saldo de estoque é somente-leitura no site.
- Escala alvo do cutover: 6k–20k SKUs (load test ainda pendente).
- Terminologia de domínio em PT-BR (tabelas, colunas, métodos).

**Fatos explicitamente em aberto — não assumir resolvidos:**

- DPO não nomeado; política de privacidade ainda em revisão jurídica.
- Provedor de e-mail padrão ainda é o sandbox do Mailgun: e-mail não chega a
  cliente real.
- GA4, Google Search Console e Merchant Center não existem.
- Voz e tom da marca nunca foram formalizados.

## Brand Commitments

**Obrigatório preservar na virada da Tray:** nome, logo e as cores atuais. O
cliente que já compra não pode achar que caiu em outra loja.

As cores vivem hoje nos tokens `:root` de `assets/css/main.css`, com a camada
de tema claro/escuro em `assets/css/tema.css`. Esses arquivos são a referência
factual do que está no ar — não uma direção visual decidida aqui.

**Também obrigatório preservar:** catálogo, preços e prazos como estão. Nada
de promessa de frete, garantia ou prazo que a operação não cumpra.

Voz e personalidade da marca: não confirmadas.

## Evidence on Hand

- **Loja física e anos de mercado** — a autoridade técnica é real, não alegada.
- **Avaliações de clientes** — o sistema existe (`ReviewController`,
  `/avaliacoes`, mídia enviada pelo cliente). Volume real não verificado.
- **Base de compatibilidade produto↔moto** — ativo real do negócio.
- **Catálogo, preços e prazos reais** vindos do Bling.

**Não existe — não fabricar:** depoimentos, número de clientes atendidos,
prêmios, selos, benchmarks, parcerias ou certificações que não estejam no
banco. O `AggregateRating` só renderiza com avaliação real (`>0`); manter
assim.

## Product Principles

1. **Se serve na moto dele, o site tem que provar.** Compatibilidade é a
   promessa central; errar aqui custa devolução e confiança.
2. **Disponível quer dizer disponível.** O saldo vem do Bling. Nunca prometer
   o que o estoque não tem.
3. **A venda pode ser assistida.** Vendedor, WhatsApp e recuperação de
   carrinho são parte do funil, não remendo — o site não precisa fechar tudo
   sozinho.
4. **A virada não pode assustar quem já compra.** Identidade, catálogo,
   preços, prazos e URLs continuam do outro lado do DNS.
5. **Não inventar prova.** Só o que está no banco ou na operação.

## Accessibility & Inclusion

Nenhum requisito ou padrão de acessibilidade foi estabelecido pelo usuário —
tratar como decisão em aberto, não como ausência de necessidade.

Sinais já presentes no código: tema claro/escuro respeitando
`prefers-color-scheme` e `prefers-reduced-motion` no banner de cookies.
