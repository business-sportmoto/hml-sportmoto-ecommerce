# Motor de automação v2

* * * * * cd /home/homo-v2.sportmoto.com.br/public_html && /usr/local/lsws/lsphp82/bin/php cli/fluxo-worker.php --verbose >> storage/logs/fluxo-worker.log 2>&1


# Importações CSV
* Worker CLI para processar importações CSV em background.
* Bootstrap idêntico ao email-worker.php (espelha o index.php).
*
* Uso:
*   php cli/csv-import-worker.php
*   php cli/csv-import-worker.php --verbose
*
* Cron sugerido (a cada minuto):
*   * * * * * flock -n /home/ploi/hml.sportmoto.com.br/tmp/sm-csv-worker.lock php /home/ploi/hml.sportmoto.com.br/cli/csv-import-worker.php >> /home/ploi/hml.sportmoto.com.br/storage/logs/csv-worker.log 2>&1


# CLI de envio de email marketing
 * Worker CLI de envio de email marketing — SportMoto.
 * Bootstrap alinhado ao index.php (mesmos defines/config/database/autoload),
 * mas SEM iniciar sessão, despachar rotas ou compartilhar views.
 *
 * Uso:
 *   php cli/email-worker.php
 *   php cli/email-worker.php --verbose
 *
 * Cron sugerido (1x por minuto, com flock externo de defesa em profundidade):
 *   * * * * * flock -n /tmp/sm-email-worker.lock php /caminho/cli/email-worker.php >> /caminho/storage/logs/email-worker.log 2>&1
*


# Módulo Chat (WhatsApp conversacional)

Cuida só do que é temporal. A conversa em si é síncrona: quando o contato
responde, o webhook chama o motor na hora — o worker não participa disso.

Fases por rodada:
  A) resolve timeouts de "esperar resposta"
  B) acorda sessões que estavam dormindo (nó esperar)
  C) consome a fila das campanhas, respeitando ritmo_por_minuto
  D) avisos do sino que não têm evento próprio:
       · cliente esperando resposta há mais de notif_sem_resposta_min
       · N falhas de envio na última hora (notif_falhas_min)
     Sem este cron, esses dois avisos simplesmente não acontecem — são
     ausências, e ausência não dispara webhook. O resto do sino do
     atendimento (mensagem nova, atribuição, campanha concluída) sai do
     próprio evento e não depende do worker.
  E) limpa chat_webhook_log com mais de 15 dias (1x/hora, no minuto :03)

Uso:
  php cli/chat-worker.php
  php cli/chat-worker.php --verbose
  php cli/chat-worker.php --duracao=55   (modo serviço, roda em loop)

Cron (a cada minuto — o lock interno já impede sobreposição):
  * * * * * cd /caminho/do/projeto && php cli/chat-worker.php >> storage/logs/chat-worker.log 2>&1

Lock: storage/locks/chat-worker.lock (flock exclusivo, não-bloqueante).

Instalador do atendimento inicial (roda uma vez, não é cron):
  php cli/chat-seed.php            → cria o fluxo "Menu de atendimento" + gatilhos
  php cli/chat-seed.php --forcar   → recria do zero


---

## ia-agentes-worker — agentes de BI (04/09/2026)

`cli/ia-agentes-worker.php`. Dois modos, lock por modo em `storage/locks/`.

| Linha de cron | O que faz | Custo |
|---|---|---|
| `0 6 * * * … --agente=agente_financeiro` | resumo executivo do dia (uma rodada por agente por dia; repetir não gasta) | ≈ US$ 0,10 |
| `0 7 * * * … --agente=agente_estoque` | idem | ≈ US$ 0,10 |
| `0 8 * * * … --agente=agente_analytics` | idem | ≈ US$ 0,10 |
| `0,30 * * * * … --modo=evento` | só dispara quando `BiService::alertas()` tem alerta **crítico** ainda não tratado hoje | zero sem alerta |

Prioridade **Alta** em qualquer modo → sino de todos os admins
(`NotificacaoService::criarBroadcast`, categoria financeiro/estoque/sistema).

Sai com **0** quando não há o que fazer — já rodou hoje, sem alerta, provedor
Claude inativo/sem chave — e **1** só em erro de execução. Antes de ativar no
cron, `php cli/bi-diagnostico.php` (bloco AGENTES DE IA) tem que estar sem
`[FALTA]`.

`--todos --forcar --verbose` roda os três agora, ignorando a dedup — uso manual.
`--simular` resolve tudo (agente, pré-carga, dedup, teto) **sem chamar o modelo**
— para conferir o cron sem gastar. Os testes usam só esse modo.

→ [[../12-decisoes-tecnicas/ia-agentes-bi]]

---

## Crons que deveriam existir e não existem

Levantado durante o projeto de BI (03/09/2026). Nenhum destes tem entrada no
crontab — a rotina só roda se alguém abrir a tela ou chamar na mão.

| O que | Consequência de não rodar |
|---|---|
| Varredura de `produto_perguntas` em `aguardando_ia` | **17 perguntas paradas hoje.** O `GeminiQAService` é chamado de forma síncrona; se falha, a pergunta fica lá para sempre e o cliente nunca é respondido |
| `ScoreService::recalcularTodos()` | tier e `ltv_total` dos clientes ficam estagnados |
| `ClienteRadarService::varrer()` | idem, o radar nunca dispara sozinho |
| Reconciliação de `log_etiquetas.valor_postado` | o custo **real** do frete nunca chega; a cobertura fica em 0% |
| Alerta de ruptura de estoque | hoje é **passivo** — só aparece se alguém abrir a tela. Ativo seria cron + `NotificacaoService::criarBroadcast` |

Ver [[../12-decisoes-tecnicas/bi-indice|índice do BI]] e
[[../04-bugs/Bugs para resolver]].


# ── Pagamentos ────────────────────────────────────────────────────
# Ver [[../12-decisoes-tecnicas/pagamentos-indice]]

# Antifraude ClearSale — aplica o parecer que chega depois
# A ClearSale é assíncrona: devolve NVO e o veredito vem por polling.
# SEM ESTE CRON, PEDIDO RETIDO FICA RETIDO — ninguém aplica o parecer.
# ⏳ ainda NÃO instalado
*/5 * * * * cd /home/DOMINIO/public_html && php cli/clearsale-worker.php >> storage/logs/clearsale-worker.log 2>&1

# Cartões salvos — preenche titular e validade lendo a adquirente
# Uso único, não é cron. Os cartões antigos ficaram com 'TITULAR' e '12/99'
# cravados; isto lê o dado real. Roda em simulação por padrão.
#   php cli/cartoes-reconciliar.php            # simula
#   php cli/cartoes-reconciliar.php --aplicar  # grava

# Webhooks (não são cron — precisam ser cadastrados nos painéis)
#   POST /webhooks/mercadopago   tópico Orders, com webhook-secret
#   POST /webhooks/cielo         "Post de Notificação", ChangeType 1
#   POST /webhooks/clearsale     enviar a URL para integracao@clear.sale
#
# Nenhum dos três decide nada pelo corpo: o aviso diz que algo mudou e o
# status vem de consultar a API da adquirente.


# Integração Bling — pulso horário

Três tarefas com try/catch ISOLADOS: falha de uma não impede as outras.
Todas respeitam o mesmo teto de 3 req/s, estático e compartilhado no processo.

  1. Fila de PEDIDOS    site → Bling   (é o que faz o estoque baixar)
  2. Espelho de ESTOQUE Bling → site   (reflete o saldo)
  3. Fila de CONTATOS                  (rede de segurança de clientes)

A ordem importa: pedidos primeiro, para que a baixa provocada por eles já
possa aparecer no espelho da mesma execução.

  0 * * * * php /caminho/cli/bling-sync.php >> storage/logs/bling-sync.log 2>&1

Por que DE HORA EM HORA e não a cada 15 min: o webhook 'stock.updated' já dá o
tempo real; este cron é RECONCILIAÇÃO — cobre webhook perdido, downtime e
ajuste manual feito dentro do Bling. Custo com ~6k produtos: sincronizarEstoque()
usa lotes de 50, ~120 chamadas por execução, ~2.900/dia.

NÃO usar sincronizarTudo() aqui — ver a nota no fim deste bloco.


# Integração Bling — vínculo diário

Preenche produtos.bling_id e produto_skus.bling_id casando o código do Bling
com sku_legado / sku do site. No fim, reporta a COBERTURA: quantos produtos e
SKUs vendáveis ficaram sem vínculo, e sai com código 1 se houver algum.

  20 4 * * * php /caminho/cli/bling-vinculos.php >> storage/logs/bling-vinculos.log 2>&1

Produto sem bling_id nunca recebe saldo E nunca dá baixa — o item vai ao Bling
como texto livre. Por isso a cobertura é métrica de lançamento, não enfeite.

Separado do pulso horário porque vincular é tarefa de catálogo, não de saldo:
caro e que não muda de minuto a minuto.


# ⚠ O que derrubou o cron de estoque em julho/2026

BlingEstoqueService::sincronizarTudo() e resolverVinculos() resolviam vínculo
com 1 chamada de API + sleep POR ITEM, sem limite. Com catálogo real isso passa
de 10 minutos por execução — e produto que não existe no Bling nunca resolve,
cobrando o pedágio para sempre.

Ambos foram REMOVIDOS do código, junto com resolverVinculoPais(),
sincronizarProdutoSimples(), sincronizarSku(), saldoDoBling(), resolverBlingId()
e resolverBlingIdProduto().

Substitutos:
  catálogo inteiro → BlingVinculoService::vincularTudo()  (~60 chamadas)
  saldo em massa   → sincronizarEstoque()                 (lotes de 50)
  um produto       → sincronizarProduto()                 (1 lote)

NÃO reintroduza busca por código item a item. Detalhes em
[[bling-estoque-modelo]] e [[bling-api-limitacoes]].

O antigo cron/bling-sync-estoque.php NÃO EXISTE MAIS. Se ainda estiver no
crontab de algum servidor, remova — ele falha silenciosamente.


---

# Central de Marketing IA — `ia-worker.php`

**Atualizado:** 03/09/2026 · Decisões em
[[../12-decisoes-tecnicas/ia-indice|índice da Central de IA]]

Único worker do módulo. Despacha a fila de `ia_geracoes`, resgata jobs presos e
dirige o motor de campanhas.

```bash
php ia-worker.php --verbose
php ia-worker.php --loop=55       # varre por 55s e sai (para cron de 1 min)
```

**Cron sugerido (a cada minuto):**

```cron
* * * * * cd /home/CAMINHO/public_html && /usr/local/lsws/lsphp82/bin/php ia-worker.php --loop=55 --verbose >> storage/logs/ia-worker.log 2>&1
```

> ⚠️ **Não foi verificado se o cron está agendado no servidor.** Sem ele nada
> sai da fila — as gerações ficam em `na_fila` indefinidamente e a tela fica em
> polling.

## O que ele faz por iteração, nesta ordem

1. **Driver de campanhas** (Fase 3A) — enfileira o que as campanhas ativas
   pedem. Roda **antes** do claim, de propósito: o que ele enfileira já
   despacha na mesma iteração.
2. **`reivindicarLote`** — pega jobs `na_fila` e marca `processando` com
   `iniciado_em`.
3. **Despacho por capacidade** — `imagem` e `remocao_fundo` vão para
   `executarImagem()`; `composicao` para o pipeline da 2C; o resto para
   `executarTexto()`.
4. **Varredura dos assíncronos** — o Replicate devolve por polling
   (latência 20–60s) porque `IA_WEBHOOK_BASE` não está definida.
5. **Watchdog** — resgata job parado. Conta como parado também o que tem
   `iniciado_em IS NULL` (ver o bug registrado em
   [[../04-bugs/resolvidos/Bugs resolvidos]]).

## Particularidades

- **Lock de instância única** por `flock`, em `storage/ia-worker.lock`.
  ⚠️ Esse arquivo está **versionado no git** — é artefato de runtime e suja
  todo commit. O certo é `.gitignore` + `git rm --cached`.
- **Bootstrap próprio.** O worker registra `app/services/ia/` no autoloader por
  conta, porque o `bootstrap-cli.php` do projeto **não registra**. Qualquer CLI
  novo do módulo precisa fazer o mesmo.
- **Silêncio quando não há o que dizer.** O driver de campanhas devolve string
  vazia em vez de `"campanhas: nenhuma ativa"` — antes eram ~27 linhas inúteis
  por minuto no log.
- **A notificação do sino não derruba o job.** `avisarConclusao()` tem
  try/catch próprio; antes, uma exceção do `NotificacaoService` transformava em
  falha uma geração já concluída e já cobrada.

## CLIs de apoio do módulo

| Comando | O quê |
|---|---|
| `php cli/ia-migrar.php [--aplicar]` | migrations do módulo, só as que faltam |
| `php cli/migrar-chave-gemini.php --aplicar` | move a chave do `.env` para `ia_provedores`, cifrada |

## Decisão em aberto

**Volume das notificações.** Hoje toda geração concluída dispara broadcast para
**todos** os admins. Três variações de imagem = três avisos por admin, por
clique. As alternativas naturais são avisar só o autor
(`ia_geracoes.usuario_id`) ou uma vez por lote.
