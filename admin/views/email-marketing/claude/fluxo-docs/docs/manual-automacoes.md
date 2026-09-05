# Manual do Motor de Automação v2

Referência completa: arquitetura, catálogo dos 19 nós, variáveis, guard-rails,
eventos, receitas prontas e limites conhecidos. A seção final lista os **gaps
conhecidos** — leia antes de homologar.

---

## 1. Arquitetura em um diagrama

```
 SITE / EMAIL / DICAS                    MOTOR                         SAÍDAS
┌─────────────────────┐   ┌──────────────────────────────────┐   ┌─────────────┐
│ navegação, buscas,  │   │ fluxo-worker (a cada 1 min):     │   │ email       │
│ banners, login      ├──▶│  A  detecção de triggers (cursor)│──▶│ whatsapp    │
│        │            │   │  A2 resolução de esperas         │   │ notificação │
│        ▼            │   │  A3 ponte de email (campanhas)   │   │ tag         │
│ tabela `eventos`    │   │  B  processamento das jornadas   │   │ cupom       │
│ (INSERT-only,       │   │  +  purge diário do log          │   │ webhook     │
│  keyed por cliente/ │   └──────────────────────────────────┘   │ vendedor    │
│  visitante_token)   │      cada passo grava em                 └─────────────┘
└─────────────────────┘      `fluxo_passos_log` (observabilidade)
```

Princípios que explicam todo o comportamento:

- **Tudo é por varredura com cursor**, nunca por push. Triggers, esperas por
  evento e pontes leem a partir do último id processado. Consequência: o efeito
  de um evento aparece no **próximo ciclo do worker** (até 1 min), não no
  mesmo segundo.
- **Versionamento imutável.** Publicar cria a versão N+1 congelada dos nós e
  conexões. Jornadas em andamento continuam na versão em que nasceram; edições
  no rascunho não afetam ninguém até a próxima publicação.
- **1 porta → 1 destino.** Cada porta de saída conecta a no máximo um nó.
  Porta sem conexão = fim natural da jornada (concluída).
- **O log nunca derruba o motor.** Toda a observabilidade é try/catch total.

## 2. Ciclo de vida de uma jornada (`fluxo_execucoes`)

| status | significa | quem tira dela |
|---|---|---|
| `ativo` | pronta para o próximo passo | fase B do worker |
| `dormindo` | nó `esperar` marcou `dormir_ate` | fase B (quando vence) |
| `aguardando_evento` | nó `esperar_evento` armado | fase A2 (evento ou timeout) |
| `concluido` | chegou a porta sem conexão ou a um `encerrar` | — |
| `saiu` | exit condition (`sair_se_eventos`) disparou | — |
| `erro` | nó devolveu ERRO | — (fica com `erro_detalhe`) |

Proteções da fase B: claim otimista (dois workers não pegam a mesma jornada) e
**máximo de 50 passos por ciclo** por jornada (anti-loop).

## 3. Cadências

| worker | frequência | faz |
|---|---|---|
| `fluxo-worker.php` | 1 min | fases A, A2, A3, B + purge diário do log |
| `fluxo-sunset.php` | semanal (seg 04h) | sunset policy |
| `vida-util-worker.php` | diário (09h) | dicas de cuidado (manual próprio) |

---

## 4. Catálogo dos 19 nós

Formato: **o que faz · config (todos os campos e defaults) · portas ·
variáveis · pegadinhas**. Os nomes abaixo são os aceitos pelo `config_json` —
o painel do canvas gera exatamente isto.

### TRIGGERS (verde) — como a jornada nasce

#### 4.1 `trigger_evento` — Evento do site
Dispara quando um evento do stream casa com a condição.
```json
{ "evento": "produto_visto", "entidade_tipo": "produto",
  "min_ocorrencias": 2, "janela_dias": 7, "apenas_logados": true }
```
- `evento` (obrigatório): qualquer `tipo` da tabela `eventos` (§8).
- `entidade_tipo` (opcional): exige a entidade (ex.: só eventos de produto).
- `min_ocorrencias` (1): "viu **N vezes** na janela" — conta eventos do mesmo
  tipo/cliente com `id <=` o atual, dentro de `janela_dias` (7).
- `apenas_logados` (true): ignora anônimos. Com `false`, a jornada nasce keyed
  pelo `visitante_token` — mas **nós que precisam de cliente** (email, cupom,
  tag, gasto) não terão em quem agir.
- **Contexto herdado:** evento com `entidade_tipo=produto` põe o `produto_id`
  no contexto da jornada → `{{produto_nome}}` etc.
- Porta: `saida`.
- **Pegadinhas:** reentrada é da config do fluxo (§6), não do trigger. Dois
  fluxos publicados escutando o mesmo evento disparam **ambos** (ordem por
  `prioridade`).

#### 4.2 `trigger_manual` — Disparo manual
Sem config. A jornada só nasce por chamada explícita — hoje, pelo
`cli/fluxo-testar.php` (§12). Perfeito para testes e integrações futuras.

### FLUXO (cinza) — tempo e estrutura

#### 4.3 `esperar`
```json
{ "minutos": 0, "horas": 0, "dias": 2 }
```
Dorme a soma dos três. Porta: `saida`. Zero total = segue imediato.
**Obrigatório em qualquer ciclo do grafo** — a publicação rejeita ciclo sem
`esperar` (loop infinito).

#### 4.4 `esperar_evento` — o nó mais poderoso
Dorme até o cliente **reagir**, ou até o timeout — e ramifica pela reação.
```json
{ "evento": "produto_visto", "mesma_entidade": true,
  "entidade_tipo": null, "timeout": { "dias": 2, "horas": 0, "minutos": 0 } }
```
- `mesma_entidade: true` fixa o produto do contexto ("espere ELE voltar a
  olhar ESTE produto").
- `timeout` ausente/zerado = **24h**.
- Portas: `evento` | `timeout`.
- **Mecânica:** a janela é `(início_da_espera, timeout_em]` com `>` **estrito**
  no início — o evento que disparou o fluxo nunca resolve a própria espera.
- **Pegadinhas:** jornada sem cliente e sem token vai direto de timeout (nada
  a observar). A resolução é na fase A2 → efeito no próximo ciclo do worker.

#### 4.5 `split_ab`
```json
{ "pesos": [70, 30] }
```
Portas: `a` | `b`. **Escolha persistida** por jornada: reprocessamento não
troca o lado. Com a observabilidade, o racha real aparece no balão (receita 7).

#### 4.6 `encerrar`
Sem config, sem saída. Fim explícito (porta sem conexão também encerra —
`encerrar` é documentacional).

### CONDIÇÕES (âmbar) — sempre portas `true` | `false`

#### 4.7 `cond_evento_ocorreu`
```json
{ "evento": "produto_visto", "janela_dias": 7, "min": 1, "mesma_entidade": false }
```
O cliente fez X nos últimos N dias? `mesma_entidade` fixa o produto do contexto.

#### 4.8 `cond_total_gasto`
```json
{ "operador": ">=", "valor": 500, "janela_dias": null }
```
Soma pedidos do cliente (janela vazia = desde sempre). Operadores:
`>=`, `>`, `<=`, `<`, `=`.
**⚠ Confira antes de produção:** quais `status_pedido` entram na soma (query
do nó em `FluxoNoRegistry.php`). Cancelado contando = número mentiroso.
Teste R2-8 do plano cobre.

#### 4.9 `cond_tem_tag`
```json
{ "tag": "vip" }
```
Tags são globais (`cliente_tags`) — qualquer fluxo enxerga tag de qualquer
fluxo. É a ferramenta de **coordenação entre fluxos** (um marca, outro filtra).

#### 4.10 `cond_aceita_marketing`
```json
{ "canal": "email" }
```
`email` | `whatsapp` | `sms`, via `NotifPrefsService`. Use antes de todo envio
de marketing; o sunset (§7) descadastra por aqui, então este nó também filtra
os sunsetados.

#### 4.11 `cond_tem_moto`
Sem config. Existe moto com `principal = 1` na garagem? O worker roda sem
sessão — a moto vem **do banco**.

#### 4.12 `cond_veio_de_vendedor`
```json
{ "escopo": "auto", "codigo": "" }
```
| escopo | comportamento |
|---|---|
| `auto` | contexto (codigo_vendedor / pedido_id / carrinho_id) → senão o **último** pedido/carrinho do cliente com código |
| `contexto` | só a jornada atual — o mais preciso |
| `cliente_ultimo` | ignora contexto, vendedor mais recente |
| `cliente_primeiro` | **atribuição sticky** — quem trouxe o cliente |

`codigo` restringe a um vendedor específico (case-insensitive). Código órfão
ou vendedor `ativo=0` → `false`. Na porta `true` publica
`{{vendedor_nome}}` e `{{vendedor_codigo}}`.

### AÇÕES (azul) — todas com porta `saida`

#### 4.13 `acao_email`
```json
{ "template_id": 21, "quiet_hours": false }
```
Template do módulo de email, renderizado com as `{{vars}}` (§5), enviado pelo
provider padrão (Mailgun). `quiet_hours: true` respeita 8h–21h (dorme até a
janela). Sujeito ao **frequency capping** (§7).

#### 4.14 `acao_notificacao`
```json
{ "categoria": "promocao", "titulo": "Oi {{primeiro_nome}}!",
  "mensagem": "...", "url": "/produto/..." }
```
Notificação in-app (sino). Categorias: `promocao`, `pedido`, `sistema`,
`estoque`, `financeiro`, `conta`. Sujeita ao cap.

#### 4.15 `acao_whatsapp`
```json
{ "template": "oferta_produto", "body_params": ["{{primeiro_nome}}", "{{produto_nome}}"],
  "header_param": "", "botao_url_param": "", "quiet_hours": true }
```
HSM (template aprovado). `body_params` na ordem dos placeholders do template.
**Falha de envio não mata a jornada** (loga e segue). Sujeito ao cap.
`quiet_hours` default **true**.

#### 4.16 `acao_tag`
```json
{ "acao": "adicionar", "tag": "quente" }
```
`adicionar` | `remover`. Barato, idempotente, e a melhor ferramenta de
**teste** (marca por onde a jornada passou) e de coordenação entre fluxos.

#### 4.17 `acao_webhook`
```json
{ "url": "https://exemplo.com/hook", "headers": {"X-Chave": "abc"},
  "hmac_secret": null, "parar_se_falhar": false }
```
POST JSON com cliente, contexto, produto e moto. **Anti-SSRF**: recusa
loopback, redes privadas, link-local/metadata; sem redirect; timeout 8s.
`hmac_secret` assina o corpo em `X-Signature-SHA256`. Falha loga e segue;
`parar_se_falhar: true` marca a jornada como erro.

#### 4.18 `acao_cupom`
```json
{ "pct": 10, "dias_validade": 15, "prefixo": "VOLTA",
  "nome": "Volte e ganhe 10%", "valor_minimo": 0 }
```
Cupom **exclusivo do cliente** (`escopo_clientes`, `limite_por_cliente=1`) via
`AutomacaoCupomService::gerarParaFluxo()`. Publica `{{cupom_codigo}}`,
`{{cupom_valor}}` ("10%"), `{{cupom_validade}}` ("27/07/2026").
**Idempotente por jornada.** ⚠ As variáveis só existem para nós **depois**
dele no grafo. Sem cliente identificado, segue sem cupom.

#### 4.19 `acao_notificar_vendedor`
```json
{ "canal": "auto", "escopo": "auto", "categoria": "sistema",
  "titulo": "{{primeiro_nome}} deixou o carrinho",
  "mensagem": "Cliente seu — vale um contato.", "url": "/admin/clientes/..." }
```
Entrega em cascata (`auto`): conta admin → conta cliente → email do cadastro →
loga e segue. As `{{vars}}` são as **do cliente**, mais `{{vendedor_nome}}` e
`{{vendedor_codigo}}`. **Fora do frequency capping** (o cap protege o cliente;
o vendedor não é o cliente). Nunca trava a jornada.

---

## 5. Variáveis (`{{vars}}`)

Disponíveis em título/mensagem de notificação, params de WhatsApp e templates
de email renderizados pelo motor:

| var | vem de |
|---|---|
| `{{nome}}`, `{{primeiro_nome}}`, `{{email}}` | cadastro do cliente |
| `{{produto_nome}}`, `{{produto_url}}` | `produto_id` no contexto (herdado do trigger ou fixado por `mesma_entidade`) |
| `{{moto_apelido}}`, `{{moto_label}}` | moto `principal=1` da garagem |
| `{{cupom_codigo}}`, `{{cupom_valor}}`, `{{cupom_validade}}` | nó `acao_cupom` **anterior** |
| `{{vendedor_nome}}`, `{{vendedor_codigo}}` | `cond_veio_de_vendedor` porta true **anterior** |

Regra geral: **qualquer chave do contexto que não comece com `_`** vira
variável automaticamente — é assim que cupom e vendedor funcionam, e é assim
que um disparo manual com `--contexto` injeta vars custom.

## 6. Config do fluxo (botão "Guard-rails" na toolbar)

```json
{ "reentrada": "apos_dias:30", "sair_se_eventos": ["pedido_criado"] }
```
- `reentrada`: `nunca` (1× por cliente, para sempre) · `sempre` ·
  `apos_dias:N`.
- `sair_se_eventos`: eventos que **abortam a jornada** (status `saiu`) se
  ocorrerem após o início. O clássico: recuperação sai se `pedido_criado`
  chegar — comprou, para de cutucar.
- `prioridade` (coluna do fluxo): ordem quando vários fluxos escutam o mesmo
  evento.

## 7. Guard-rails globais (`fluxo_motor_config`)

| chave | default | efeito |
|---|---|---|
| `cap_max_semana` | **0 (desligado)** | teto de mensagens/cliente em 7 dias, somando todos os canais e fluxos. Estourou → envio pulado (`detalhe='cap'` no log), jornada segue |
| `quiet_hours_inicio/fim` | 8 / 21 | janela dos nós com `quiet_hours: true` |
| `sunset_janela_dias` / `sunset_min_enviados` | 90 / 3 | recebeu ≥3 emails em 90d e abriu zero → descadastra marketing + tag `sunset` |

Fora do cap: `acao_notificar_vendedor` e transacionais fora dos fluxos.
As dicas de cuidado **respeitam** o cap, adiando (manual próprio).

## 8. Catálogo de eventos do stream

**Instrumentados no site (Fase 0):**

| tipo | entidade | dedup | contexto útil |
|---|---|---|---|
| `produto_visto` | produto | 30 min | produto_id |
| `categoria_vista` | categoria | 30 min | — |
| `catalogo_moto_visto` | — | 30 min | montadora/modelo (navegação ↔ garagem) |
| `busca` | — | sem dedup | `termo`, `resultados` (0 = frustrada!) |
| `banner_click` / `banner_visto` | banner | 5 s | — |
| `pagina_vista` | — | — | url |
| `sessao_iniciada` | — | 1×/sessão | UTM, referer, device |

**De pontes (server-side):**

| tipo | origem |
|---|---|
| `email_aberto` / `email_clicado` | campanhas do email marketing (ponte A3) — **não** cobre emails do próprio fluxo |
| `dica_cuidado_clicada` | clique na dica de vida útil (`GET /dica/{id}`) |

**⚠ NÃO instrumentado (gap — §11-A):** `pedido_criado`. Aparece nas listas do
canvas e é o exit-condition mais importante, mas o site ainda não o emite.

## 9. Regras de publicação (o que o validador rejeita)

Exatamente **1 trigger** · nenhum nó órfão (BFS a partir do trigger) · toda
conexão usa porta declarada pelo tipo · **ciclo sem `esperar` é rejeitado**
(DFS) · publicar congela a versão N+1. Erros aparecem na toolbar ao publicar.

---

## 10. Receitas prontas

Cada JSON é o formato exato do rascunho (`{nos, conexoes}`) — monte no canvas
seguindo o desenho, ou confira contra o que o canvas salva. Depois:
**Publicar** e testar com os scripts (§12 / plano de testes).

### Receita 1 — Interesse em produto (a jornada completa)
*Viu o mesmo produto 3× na semana → espera 1h → aceita email? → manda o
produto → reagiu em 2 dias? → quente + vendedor / frio → cupom.*

```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"produto_visto","entidade_tipo":"produto","min_ocorrencias":3,"janela_dias":7,"apenas_logados":true}},
    {"chave":"esp","tipo":"esperar","config":{"horas":1}},
    {"chave":"mkt","tipo":"cond_aceita_marketing","config":{"canal":"email"}},
    {"chave":"mail","tipo":"acao_email","config":{"template_id":21}},
    {"chave":"ee","tipo":"esperar_evento","config":{"evento":"produto_visto","mesma_entidade":true,"timeout":{"dias":2}}},
    {"chave":"quente","tipo":"acao_tag","config":{"acao":"adicionar","tag":"quente"}},
    {"chave":"vend","tipo":"acao_notificar_vendedor","config":{"titulo":"{{primeiro_nome}} está de olho em {{produto_nome}}","mensagem":"Voltou a olhar depois do email. Vale um toque."}},
    {"chave":"cup","tipo":"acao_cupom","config":{"pct":10,"dias_validade":7,"prefixo":"VOLTA","nome":"Volte e ganhe 10%"}},
    {"chave":"mail2","tipo":"acao_email","config":{"template_id":22}}
  ],
  "conexoes": [
    {"de":"t1","porta":"saida","para":"esp"},
    {"de":"esp","porta":"saida","para":"mkt"},
    {"de":"mkt","porta":"true","para":"mail"},
    {"de":"mail","porta":"saida","para":"ee"},
    {"de":"ee","porta":"evento","para":"quente"},
    {"de":"quente","porta":"saida","para":"vend"},
    {"de":"ee","porta":"timeout","para":"cup"},
    {"de":"cup","porta":"saida","para":"mail2"}
  ]
}
```
Config do fluxo: `{"reentrada":"apos_dias:30","sair_se_eventos":["pedido_criado"]}`
(o exit só funciona após §11-A). Template 22 usa `{{cupom_codigo}}`/
`{{cupom_valor}}`/`{{cupom_validade}}`. `mkt:false` sem conexão = quem não
aceita email sai em paz.

### Receita 2 — Busca no site
*Buscou → notificação convidando a pedir ajuda + webhook pro seu ERP/Slack.*
```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"busca","min_ocorrencias":1,"janela_dias":1,"apenas_logados":true}},
    {"chave":"n1","tipo":"acao_notificacao","config":{"categoria":"sistema","titulo":"Não achou o que procurava?","mensagem":"Conta pra gente qual peça você buscou — a gente corre atrás.","url":"/contato"}},
    {"chave":"wh","tipo":"acao_webhook","config":{"url":"https://SEU-ENDPOINT/busca"}}
  ],
  "conexoes": [
    {"de":"t1","porta":"saida","para":"n1"},
    {"de":"n1","porta":"saida","para":"wh"}
  ]
}
```
⚠ Dispara em **toda** busca — o trigger não filtra `resultados=0` do contexto
(limitação real, §11-C). Use `reentrada: apos_dias:7` para não virar spam.

### Receita 3 — Primeiro acesso logado
```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"sessao_iniciada","min_ocorrencias":1,"janela_dias":365,"apenas_logados":true}},
    {"chave":"moto","tipo":"cond_tem_moto","config":{}},
    {"chave":"garagem","tipo":"acao_notificacao","config":{"categoria":"conta","titulo":"Cadastre sua moto na garagem","mensagem":"Com a sua moto cadastrada, mostramos só o que serve nela.","url":"/minha-conta/garagem"}}
  ],
  "conexoes": [
    {"de":"t1","porta":"saida","para":"moto"},
    {"de":"moto","porta":"false","para":"garagem"}
  ]
}
```
Config: `{"reentrada":"nunca"}` — **uma vez por cliente na vida**.
Porta `true` sem conexão: quem já tem moto não recebe nada.

### Receita 4 — Dica de cuidado → venda (a ponte da vida útil)
```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"dica_cuidado_clicada","min_ocorrencias":1,"janela_dias":1,"apenas_logados":true}},
    {"chave":"mkt","tipo":"cond_aceita_marketing","config":{"canal":"email"}},
    {"chave":"cup","tipo":"acao_cupom","config":{"pct":10,"dias_validade":10,"prefixo":"CUIDA","nome":"10% pra cuidar da sua moto"}},
    {"chave":"mail","tipo":"acao_email","config":{"template_id":23}}
  ],
  "conexoes": [
    {"de":"t1","porta":"saida","para":"mkt"},
    {"de":"mkt","porta":"true","para":"cup"},
    {"de":"cup","porta":"saida","para":"mail"}
  ]
}
```
O contexto do clique traz `produto_id` → o email fala do produto certo.

### Receita 5 — Engajou com campanha
```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"email_clicado","min_ocorrencias":1,"janela_dias":1,"apenas_logados":true}},
    {"chave":"tg","tipo":"acao_tag","config":{"acao":"adicionar","tag":"engajado-email"}}
  ],
  "conexoes": [{"de":"t1","porta":"saida","para":"tg"}]
}
```
A tag vira filtro em qualquer outro fluxo. Segmentação por engajamento com 2 nós.

### Receita 6 — VIP por gasto *(depende de §11-A)*
```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"pedido_criado","min_ocorrencias":1,"janela_dias":1,"apenas_logados":true}},
    {"chave":"gasto","tipo":"cond_total_gasto","config":{"operador":">=","valor":1000}},
    {"chave":"jatem","tipo":"cond_tem_tag","config":{"tag":"vip"}},
    {"chave":"tg","tipo":"acao_tag","config":{"acao":"adicionar","tag":"vip"}},
    {"chave":"vend","tipo":"acao_notificar_vendedor","config":{"escopo":"cliente_primeiro","titulo":"{{primeiro_nome}} virou VIP","mensagem":"Passou de R$ 1.000 em compras. Cliente da sua carteira."}}
  ],
  "conexoes": [
    {"de":"t1","porta":"saida","para":"gasto"},
    {"de":"gasto","porta":"true","para":"jatem"},
    {"de":"jatem","porta":"false","para":"tg"},
    {"de":"tg","porta":"saida","para":"vend"}
  ]
}
```
O `jatem:false` evita re-marcar e re-avisar a cada compra do VIP.
`cliente_primeiro` = a carteira é de quem trouxe.

### Receita 7 — Split A/B de canal (medido pelos balões)
```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"produto_visto","min_ocorrencias":2,"janela_dias":3,"apenas_logados":true}},
    {"chave":"mkt","tipo":"cond_aceita_marketing","config":{"canal":"email"}},
    {"chave":"ab","tipo":"split_ab","config":{"pesos":[50,50]}},
    {"chave":"mail","tipo":"acao_email","config":{"template_id":21}},
    {"chave":"zap","tipo":"acao_whatsapp","config":{"template":"oferta_produto","body_params":["{{primeiro_nome}}","{{produto_nome}}"]}},
    {"chave":"ee","tipo":"esperar_evento","config":{"evento":"produto_visto","mesma_entidade":true,"timeout":{"dias":3}}},
    {"chave":"voltou","tipo":"acao_tag","config":{"acao":"adicionar","tag":"ab-voltou"}}
  ],
  "conexoes": [
    {"de":"t1","porta":"saida","para":"mkt"},
    {"de":"mkt","porta":"true","para":"ab"},
    {"de":"ab","porta":"a","para":"mail"},
    {"de":"ab","porta":"b","para":"zap"},
    {"de":"mail","porta":"saida","para":"ee"},
    {"de":"zap","porta":"saida","para":"ee"},
    {"de":"ee","porta":"evento","para":"voltou"}
  ]
}
```
Leitura **no próprio canvas**: balão do `ab` mostra o racha real a/b; balão do
`ee` mostra `evento` vs `timeout` = taxa de retorno agregada.

### Receita 8 — Interessado em moto sem garagem
```json
{
  "nos": [
    {"chave":"t1","tipo":"trigger_evento","config":{"evento":"catalogo_moto_visto","min_ocorrencias":2,"janela_dias":14,"apenas_logados":true}},
    {"chave":"moto","tipo":"cond_tem_moto","config":{}},
    {"chave":"n1","tipo":"acao_notificacao","config":{"categoria":"conta","titulo":"Essa moto é sua?","mensagem":"Cadastra ela na garagem que a gente filtra as peças certas pra você.","url":"/minha-conta/garagem"}}
  ],
  "conexoes": [
    {"de":"t1","porta":"saida","para":"moto"},
    {"de":"moto","porta":"false","para":"n1"}
  ]
}
```

---

## 11. Gaps e pontos de atenção conhecidos (leia antes de homologar)

**A) `pedido_criado` não é emitido pelo site.** Está nas listas do canvas e é
o exit-condition mais importante (`sair_se_eventos`), mas a Fase 0
instrumentou navegação, não checkout. **Correção** — no ponto onde o pedido é
criado com sucesso:
```php
if (class_exists('TrackingService')) {
    try {
        TrackingService::registrar('pedido_criado', 'pedido', (int)$pedidoId, [
            'total' => (float)$total,
        ]);
    } catch (Throwable $e) { /* tracking nunca quebra checkout */ }
}
```
Sem isso: a Receita 6 não dispara e `sair_se_eventos:["pedido_criado"]` nunca
aborta jornada. Teste R5-3 do plano verifica.

**B) `cond_total_gasto` — statuses da soma.** Confira na query do nó quais
`status_pedido` contam. Teste R2-8.

**C) Triggers não filtram por contexto.** `trigger_evento` filtra tipo,
entidade, contagem e janela — **não** filtra campos do `contexto_json` (ex.:
`busca` com `resultados=0`). Se busca frustrada virar prioridade, o filtro de
contexto no `FluxoTriggerService` é evolução pequena e localizada.

**D) Abertura dos emails do próprio fluxo.** `email_aberto` cobre
**campanhas**. O `acao_email` do fluxo não passa pela `email_eventos` —
"o email do fluxo foi aberto?" exige pixel próprio (backlog).
`esperar_evento(email_aberto)` após um `acao_email` mede abertura de campanha,
não daquele email.

**E) Disparo manual sem botão no admin.** `trigger_manual` dispara só via
`cli/fluxo-testar.php`. Um botão "Testar com cliente X" no editor é a melhoria
natural.

**F) Anônimos têm alcance curto.** `apenas_logados:false` cria jornadas por
token, mas quase todas as ações exigem cliente. Na prática: mantenha `true`
até existirem ações para anônimos.

## 12. Como testar (resumo — roteiro completo no plano-de-testes.md)

**Injetar eventos como se fosse o cliente:**
```bash
php cli/fluxo-simular-evento.php --cliente=5 --tipo=produto_visto \
    --entidade-tipo=produto --entidade-id=123 --repetir=3 --detectar
```
`--detectar` roda detecção/processamento na hora. `--repetir` testa
`min_ocorrencias`. Limpeza:
`DELETE FROM eventos WHERE visitante_token='simulacao0000000000000000000000';`

**Rodar uma jornada e ver cada passo:**
```bash
php cli/fluxo-testar.php --fluxo=12 --cliente=5 --contexto='{"produto_id":123}'
php cli/fluxo-testar.php --execucao=88 --acordar     # atravessa esperas
```
Imprime a sequência real (`fluxo_passos_log`: nó → porta → detalhe → ms) e o
status final. `--acordar` zera `dormir_ate` e força o timeout de
`esperar_evento` — uma jornada de 3 dias em 10 segundos.

**Ler resultados:** balões no canvas (racha das portas), painel do nó (erros
com mensagem), timeline `/admin/fluxos/atividade` (filtro por cliente = a
resposta de "por que o cliente X não recebeu?").
