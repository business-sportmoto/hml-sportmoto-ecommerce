---
tipo: contexto-ia
status: ativo
atualizado_em: 2026-09-03
---

# Status atual

> Antes deste arquivo estava com "A validar" em todos os campos, desde
> 16/07/2026. Os itens abaixo foram verificados contra código e banco em
> 03/09/2026 — não são planejamento.

## Em desenvolvimento

- **Painel — conteúdo editável.** Rodapé (`/admin/configuracoes/rodape`) e
  criador de páginas (`/admin/paginas`). Ver
  [[../12-decisoes-tecnicas/conteudo-editavel-rodape-e-paginas]].
- **Log de operações do Bling** — filtro, paginação e detalhe em drawer em
  `/admin/configuracoes/bling`. Ver
  [[../12-decisoes-tecnicas/bling-log-operacoes]].
- **Expedição** — etiqueta na página do pedido, bipagem por `sku_legado`, três
  botões de impressão. Ver
  [[../12-decisoes-tecnicas/expedicao-etiqueta-e-impressao]].
- **Produtos** — topbar fixa, ordem das fotos por arrastar, vínculo de Clips.
  Ver [[../12-decisoes-tecnicas/admin-produtos-midia-e-clips]].

- **Central de Marketing IA** (`/admin/ia`) — Fases 0, 1, 2A, 2B, 2C, 3A e 3B
  instaladas; provedor Claude instalado e inativo; SEO do admin passando pela
  Central com procedência. Ver
  [[../12-decisoes-tecnicas/ia-indice|índice da Central de IA]].
- **BI / Power BI** — ver [[../12-decisoes-tecnicas/bi-indice]].
- **Chat / Atendimento (WhatsApp + Instagram)** — ver
  [[../12-decisoes-tecnicas/modulo-chat-whatsapp]].
- **Pagamentos** — motor de fluxos multi-adquirente. Mercado Pago e Cielo
  integrados, 3DS pronto e desligado, antifraude ClearSale retendo. Ver
  [[../12-decisoes-tecnicas/pagamentos-indice]].
- **Integração Bling / estoque / catálogo** — ver
  [[../12-decisoes-tecnicas/bling-indice]].

## ⚠ Modelo de estoque — leia antes de mexer em saldo

**O Bling é o dono do estoque. O site é espelho e nunca escreve saldo.**

Ele baixa quando o pedido entra e propaga para todos os canais (site +
marketplaces). Cancelar pedido no painel do site **não** devolve estoque —
cancele no Bling. `AdminPedidoService` foi limpo de toda movimentação de
propósito; reintroduzir causa contagem dupla.

Contexto de lançamento: `www.sportmoto.com.br` ainda é a **Tray**, o site novo
está em `hml`. Na virada de DNS os slugs precisam bater com os da Tray — é o
que dispensa tabela de 301. Ver
[[../12-decisoes-tecnicas/catalogo-url-canonica]].

## Em homologação

- **Newsletter com cupom de boas-vindas** — fluxo testado ponta a ponta e
  aprovado. **Não chega ao cliente real** enquanto o provedor de e-mail padrão
  for o sandbox do Mailgun. Ver
  [[../12-decisoes-tecnicas/newsletter-cupom-boas-vindas]].

- **Chat / Instagram / IA** — no hml. Falta subir o lote de
  [[chat-ia-instagram-checklist]]; sem `index.php` e `cli/chat-worker.php`
  novos a IA fica muda no Instagram. Contexto: [[04-sessao-chat-ia-instagram]].

- Nada da Central de IA subiu para o servidor ainda. As migrations existem em
  `sql/ia/` e o runner (`php cli/ia-migrar.php`) sabe o que falta. Ordem de
  subida em [[../12-decisoes-tecnicas/ia-migrations-e-deploy]].
- **Bling**: espelho de estoque (webhook + cron horário, **crons ligados**),
  fila de envio de pedidos com retentativa e alerta crítico, catálogo em
  `/admin/bling/produtos` com importação sob demanda e sync por diff, URL
  travada para produto vindo da Tray, job de sobrescrita de URL em massa.
  Cobertura de vínculo: **zero produtos sem vínculo**.

## Bugs prioritários

Do ciclo de painel de 03/09/2026 (detalhe em
[[../04-bugs/Bugs para resolver]]):

- **O provedor de e-mail padrão é o sandbox do Mailgun** — só entrega para
  endereços autorizados. Bloqueia a newsletter. Existe conta AWS SES com
  `news.sportmoto.com.br`, com `padrao = 0`.
- **`.toggle-slider::after` sem escopo** em `admin.css:5293` e `pages.css:181` —
  quem usa `.toggle-switch` herda um segundo botãozinho. 7 views afetadas.
- **Handler "Sincronizar informações do Bling" dentro da IIFE do editor** em
  `admin/views/produtos/form.php` — se o `.pe-rte` sair da página o botão para
  de funcionar **em silêncio**.
- **`/contato` publicada com texto de exemplo.**

Nenhum bloqueador conhecido na Central de IA — 15 defeitos foram corrigidos em
03/09/2026 e estão registrados em
[[../04-bugs/resolvidos/Bugs resolvidos]]. O que segue aberto é falta de
verificação, não defeito:

- **Recorte (2B)** nunca produziu um PNG. `ia_recortes_produto` está vazia.
- **Compositor (2C)** nunca executou — Imagick ausente no ambiente de dev.
- **Campanhas (3A/3B)** exercitadas em transação, nunca com o worker gerando de
  verdade.

Em **pagamentos**, há um defeito aberto:

- **`processing_error` do cartão no Mercado Pago.** Não é recusa de emissor —
  é falha de processamento deles, sem causa identificada. O log já captura o
  `errors` da resposta. O teste que separa as hipóteses: pagar com cartão
  **digitado na hora**, não salvo. Ver
  [[../12-decisoes-tecnicas/pagamentos-adquirentes]].
- **Salvar cartão no Mercado Pago falha com `Invalid credentials`** (03/09)
  com o access token válido. É a *public key*: precisa ser da mesma aplicação
  (`1294894783204295`) do access token. Conferir no painel e colar a atual em
  `pgto_gateways.front_api_key`. Detalhe e finais das chaves em
  [[../12-decisoes-tecnicas/pagamentos-checklist-producao#Cartão]].

## Próximos passos

0. **Chat/IA** — deploy do lote ([[chat-ia-instagram-checklist]]); ativar um
   modelo de texto em `ia_modelos` no hml; no "Fluxo de preço" ligar a porta
   **"não"** de "É cliente da loja?" à Etapa de IA; preencher o **modelo da
   mensagem** do bloco de IA (vazio = resposta seca) e publicar.

1. **Subir a Central de IA para o servidor** — `php cli/ia-migrar.php` e depois
   `--aplicar`; em seguida `php cli/migrar-chave-gemini.php --aplicar`.
2. **Confirmar Imagick no VPS** (`php -m | grep imagick`) e fazer o teste de
   fumaça do banner.
3. **Agendar o cron do `ia-worker.php`** — sem ele nada sai da fila. Ver
   [[../07-workers-cron/mapa-workers-cron]].
4. **Verificação visual do painel logado** — `/admin/ia/gerar`,
   `/admin/ia/historico`, `/admin/ia/config`, `/admin/ia/campanhas` e o botão
   de SEO em `/admin/produtos`. Nenhuma dessas telas foi vista logada.
5. **Mover as suítes de teste da IA para o repositório** — hoje vivem em
   diretório temporário de sessão. 401 asserções em risco de se perderem.
6. **Completar o catálogo** — `imagem` tem 1 modelo ativo de 7;
   `imagem_edicao` e `upscale` estão com zero.

### Bling / catálogo — antes da virada de DNS

7. **Rodar as migrations do Bling em produção**, na ordem de
   [[../09-banco-de-dados/migrations-pendentes]].
8. **Reimportar o CSV de produtos** com o mapeamento por header corrigido —
   traz `ativo` e categoria, que nunca funcionaram por índice deslocado.
9. **Reparar produtos importados antes da correção de `produto_skus.bling_id`**
   (checagem 3 em [[../09-banco-de-dados/migrations-pendentes]]) — eles vendem
   sem baixar estoque.
10. **Confirmar `APP_ENV=homologation` no `.env` do hml** — sem isso o
    `noindex` não dispara e a homologação disputa ranking com o `www`.
11. **Avisar a operação:** cancelamento de pedido agora é feito no Bling.

Em **pagamentos**, a lista completa está em
[[../12-decisoes-tecnicas/pagamentos-checklist-producao]]. O caminho mais curto
para faturar é **Pix e boleto pela Cielo** — sem questão de PCI e sem depender
do impasse do Mercado Pago.

## Riscos conhecidos

### Fundamentos (valem para todo o projeto)

- **Os dois autoloaders divergem.** `admin/index.php` **não** carrega
  `app/controllers/`. Já causou dois fatais. Lógica compartilhada entre loja e
  painel mora em service. Ver [[02-convencoes#Os dois autoloaders]].
- **Harness de teste mais permissivo que produção** já aprovou código quebrado
  duas vezes (autoloader do painel; `window.BASE_URL`). Ver
  [[02-convencoes#Paridade de harness]].
- **`*.sql` está no `.gitignore`** (linha 57): as migrations não são
  versionadas. Ambiente novo não reconstrói o schema pelo repositório.
- **O Vault inteiro está no `.gitignore`** (linhas 31–32, `/docs/`). São 9
  arquivos rastreados contra 70 no disco: **61 notas existem só nesta máquina**.
  A regra é deliberada — evita que a documentação vá para o servidor — mas o
  efeito colateral é que o Vault não tem histórico nem backup. Um `rm` acidental
  ou disco perdido leva tudo. Alternativas: repositório próprio para o Vault, ou
  excluir `docs/` no deploy em vez de no git.
- **`UNIQUE` com coluna anulável não protege** no MySQL (NULL é sempre distinto
  de NULL). Os índices do projeto não foram auditados sob essa ótica.

- **Chat/IA:** token do Instagram expira e a resposta pública falha em
  silêncio (`chat-ig-check` seção 5 mostra); Reel viral consome a cota do dia —
  teto por fluxo existe mas precisa ser preenchido no bloco; a receita "por
  fluxo" não arma a régua do cupom (sem bloco de link rastreado).

- **`flux-2-max` é ponto único de falha** na capacidade `imagem`. Se ele cair,
  a capacidade cai — não há fallback real.
- **Preços do Gemini são captura de 15/07.** O cálculo de custo, os tetos e o
  rollup são tão bons quanto eles.
- **`gemini-3.5-flash`, `3.6-flash` e `3-flash-preview` respondem 503
  persistente.** Pode ser característica do tier da conta, não instabilidade
  passageira — vale checar no console do Google.
- **Fora do módulo, sinalizado e não corrigido:** `errors/403` e
  `layouts/minimal` não existem em `admin/views/`, então **toda negação de
  permissão em navegação normal, no painel inteiro, vira `RuntimeException`**.
- **`storage/ia-worker.lock` versionado** no git.
- **O pacote `admin/views/ia/completo/` está commitado dentro do webroot** do
  painel.
- **Divergência com o `CLAUDE.md`:** a §4.8.1 diz que `gerente` e `vendedor`
  não existem no ENUM `admins.nivel`. **Já existem** — a `migration-cargos.sql`
  rodou no dev. O documento está desatualizado nesse ponto.

### Bling / estoque

- **Produto ativo sem `bling_id`** não recebe saldo *e* não dá baixa — o item
  vai ao Bling como texto livre e tudo reporta sucesso. O card "Saúde da
  integração" mostra o número; antes do DNS tem que ser zero.
- **Janela de propagação:** alguns segundos entre a compra e o webhook. Dois
  clientes na última unidade nessa janela geram oversell. Risco aceito.
- **A primeira execução do cron em produção vai corrigir muitos saldos para
  baixo**, inclusive zerando produtos. É o comportamento correto, mas sem
  contexto parece defeito.
- **4 rotas do admin apontam para métodos inexistentes** (`/usuarios/novo`,
  2 de banners, 1 de clips) — a primeira é o POST de criação de usuário.
  Fora do escopo da rodada do Bling; listadas em
  [[../04-bugs/resolvidos/2026-09-bling-catalogo-estoque]].
- **`pedido_itens.sku` é `VARCHAR` guardando o *id*** de `produto_skus`.
  Mitigado com aliases (`sku_id` / `sku`); renomear exige migration.

### Pagamentos

- **`pgto_gateways.sandbox = 1` em produção** manda pedido real para o
  ambiente de teste da adquirente — o cliente recebe um Pix que ninguém pode
  pagar. A linha da Cielo nasce com `0`; não alterar sem conferir o servidor.
- **Cartão pela Cielo muda o escopo PCI da loja** (SAQ A → SAQ A-EP/D): a API
  3.0 recebe o PAN no corpo da requisição, sem tokenização no navegador.
  Decisão de negócio ainda aberta. Pix e boleto não têm essa implicação.
- **Cartão salvo vale só na adquirente onde foi salvo.** Fazer valer em todas
  (como a Malga faz sendo cofre) exige tokenizar no navegador em cada
  adquirente ao salvar — possível com MP e Cielo (Silent Order Post), mas
  tira a loja do SAQ A. Decisão aberta em
  [[../12-decisoes-tecnicas/pagamentos-cartao-multi-adquirente]].
- **Sem o cron da ClearSale, pedido retido fica retido** indefinidamente.
- **Credenciais em texto puro** no banco (Mercado Pago e Cielo). O
  `PagamentoCredencialService::salvar()` cifra — essas linhas não passaram por
  ele.
- **Registros antigos de `logs`** têm atribuição de usuário ambígua
  (`admin_id` e `cliente_id` gravados na mesma coluna `usuario_id`). Tratar
  como não-confiável.

## Índices do módulo

- [[chat-indice]] — Chat, Instagram e IA (sessão 31/08–03/09/2026)
