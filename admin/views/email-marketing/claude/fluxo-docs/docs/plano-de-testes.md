# Plano de Testes — pré-homologação

Rodadas executáveis, em ordem. Cada caso: **como executar → resultado
esperado → onde conferir**. Ferramentas: `cli/fluxo-testar.php`,
`cli/fluxo-simular-evento.php`, SQL direto, e as telas de observabilidade.

Convenções: use um **cliente de teste** dedicado (aqui, `#5`) com email seu.
Eventos simulados carregam o token `simulacao0000000000000000000000` — limpe
com `DELETE FROM eventos WHERE visitante_token='simulacao0000000000000000000000';`

---

## R0 — Pré-voo (instalação)

```sql
-- R0-1: tabelas existem?
SHOW TABLES LIKE 'fluxo%';        -- fluxo_v2, fluxo_nos, fluxo_conexoes,
                                  -- fluxo_execucoes, fluxo_envios,
                                  -- fluxo_motor_config, fluxo_passos_log
SHOW TABLES LIKE 'vida_util%';    -- categoria_vida_util, vida_util_agenda
SHOW TABLES LIKE 'eventos';

-- R0-2: config semeada?
SELECT * FROM fluxo_motor_config ORDER BY chave;

-- R0-3: crons registrados? (como www-data)
--   crontab -u www-data -l | grep -E "fluxo-worker|vida-util|fluxo-sunset"

-- R0-4: worker roda limpo na mão?
--   php cli/fluxo-worker.php --verbose      → sem erro, fases A/A2/A3/B logadas
```
Rotas: abra `/admin/fluxos`, `/admin/fluxos/atividade` e `/admin/vida-util`
logado como admin. **As três abrem sem erro** (se "atividade" cair num fluxo
inexistente, a ordem das rotas está errada — `/fluxos/atividade` antes de
`/fluxos/{id}`).

## R1 — Smoke do motor (5 minutos)

Crie no canvas: `trigger_manual → acao_tag (adicionar "smoke")`. Publique.
```bash
php cli/fluxo-testar.php --fluxo=ID --cliente=5
```
**Esperado:** saída com `▶ __inicio`, `t1 → saida`, `tag → saida`,
`✔ __fim → concluido`; e:
```sql
SELECT * FROM cliente_tags WHERE cliente_id=5 AND tag='smoke';   -- 1 linha
```
Depois: balão do nó de tag no canvas mostra `1`; a jornada aparece na timeline.

## R2 — Um caso por nó (a tabela de cobertura)

Monte um fluxo `trigger_manual → [nó em teste] → acao_tag(ok-X)` por caso (ou
um fluxo grande com condições em série). Rode com `fluxo-testar.php`.

| # | nó | cenário | esperado |
|---|---|---|---|
| R2-1 | `esperar` | `{"minutos":2}` | status `dormindo`; `--acordar` atravessa e conclui |
| R2-2 | `esperar_evento` | ver R3 | — |
| R2-3 | `split_ab` | `[50,50]`, rode 10 clientes | balão mostra racha ~a/b; mesma jornada re-testada mantém o lado |
| R2-4 | `cond_evento_ocorreu` | simule `produto_visto` antes; teste com e sem | porta `true` / `false` corretas no log |
| R2-5 | `cond_tem_tag` | cliente com/sem a tag | idem |
| R2-6 | `cond_aceita_marketing` | descadastre o cliente de email e teste | `false` após opt-out |
| R2-7 | `cond_tem_moto` | cliente com/sem moto principal | `true`/`false` |
| R2-8 | `cond_total_gasto` | **crie um pedido `cancelado` de R$ 5.000** para o cliente; condição `>= 1000` | ⚠ se der `true`, a soma conta cancelado — **bug a corrigir na query do nó** |
| R2-9 | `cond_veio_de_vendedor` | pedido com `codigo_vendedor` válido; código órfão; vendedor `ativo=0` | `true` / `false` / `false` |
| R2-10 | `acao_email` | template real, seu email no cliente 5 | email chega com `{{vars}}` interpoladas; passo `saida` no log |
| R2-11 | `acao_notificacao` | título com `{{primeiro_nome}}` | sino do cliente mostra interpolado |
| R2-12 | `acao_whatsapp` | template HSM válido (e um inválido) | válido envia; inválido **loga e segue** (jornada não vira erro) |
| R2-13 | `acao_tag` | adicionar e remover | `cliente_tags` reflete |
| R2-14 | `acao_webhook` | ver R7-1/R7-2 | — |
| R2-15 | `acao_cupom` | `pct:15, prefixo:TESTE` | linha em `cupons` exclusiva do cliente; email seguinte mostra `{{cupom_codigo}}`; **rodar 2× a mesma jornada não cria 2º cupom** |
| R2-16 | `acao_notificar_vendedor` | vendedor com conta admin / só email / sem nada | sino do admin / email / loga e segue — jornada sempre conclui |
| R2-17 | `trigger_evento` | ver R3-1 | — |
| R2-18 | `encerrar` | no meio do grafo | `__fim concluido` no ponto |
| R2-19 | `trigger_manual` | usado em todos acima | — |

## R3 — Esperas e eventos (o coração)

**R3-1 · Trigger por evento com contagem:**
fluxo `trigger_evento(produto_visto, min_ocorrencias:3, janela:7)` → tag.
```bash
php cli/fluxo-simular-evento.php --cliente=5 --tipo=produto_visto \
  --entidade-tipo=produto --entidade-id=123 --repetir=2 --detectar   # NÃO dispara
php cli/fluxo-simular-evento.php --cliente=5 --tipo=produto_visto \
  --entidade-tipo=produto --entidade-id=123 --detectar               # 3º: dispara
```
**Esperado:** jornada só nasce no 3º; contexto tem `produto_id:123`.

**R3-2 · esperar_evento pela porta `evento`:**
fluxo com `esperar_evento(produto_visto, mesma_entidade:true, timeout 2d)`.
Rode `fluxo-testar` com `--contexto='{"produto_id":123}'` → status
`aguardando_evento`. Então:
```bash
php cli/fluxo-simular-evento.php --cliente=5 --tipo=produto_visto \
  --entidade-tipo=produto --entidade-id=123 --detectar
```
**Esperado:** jornada acorda pela porta **`evento`** (log). Simular produto
**456** antes NÃO acorda (mesma_entidade).

**R3-3 · esperar_evento por `timeout`:**
mesma configuração, mas `php cli/fluxo-testar.php --execucao=ID --acordar`.
**Esperado:** sai pela porta **`timeout`**.

**R3-4 · O evento que disparou não resolve a própria espera:**
fluxo `trigger_evento(produto_visto)` → `esperar_evento(produto_visto,
mesma_entidade:true)`. Simule 1 evento com `--detectar`.
**Esperado:** a jornada nasce E fica `aguardando_evento` (o mesmo evento não
conta duas vezes — `>` estrito). Um **segundo** evento acorda.

## R4 — Guard-rails

**R4-1 · Frequency capping:**
```sql
UPDATE fluxo_motor_config SET valor='2' WHERE chave='cap_max_semana';
```
Rode 3 jornadas de email para o cliente 5.
**Esperado:** 2 emails chegam; o 3º passo aparece com `detalhe='cap'` na
timeline; KPI "envios hoje" = 2. Volte `cap_max_semana='0'` no fim.

**R4-2 · Quiet hours:** nó com `quiet_hours:true` fora da janela (ou aperte a
janela: `quiet_hours_inicio=23`). **Esperado:** jornada `dormindo` até a
janela. Restaure depois.

**R4-3 · Reentrada:** fluxo `reentrada:nunca` → 2ª chamada do `fluxo-testar`
para o mesmo cliente **falha ao iniciar** (mensagem do script).
`apos_dias:30` idem dentro da janela.

**R4-4 · Exit condition:** fluxo com `sair_se_eventos:["produto_visto"]` e um
`esperar 1 dia` no meio. Inicie, simule `produto_visto` do cliente, rode
`--execucao=ID --acordar`. **Esperado:** status **`saiu`** (não concluído) —
`__fim saiu` no log. *(Com `pedido_criado` só após instrumentar §11-A do
manual.)*

**R4-5 · Sunset:** para um cliente de teste, insira na `email_eventos` **3
eventos `enviado` recentes (dentro dos últimos 90 dias) e nenhum `aberto`**,
e rode `php cli/fluxo-sunset.php --verbose`. **Esperado:** tag `sunset` +
descadastro de marketing; `cond_aceita_marketing` passa a dar `false` para
esse cliente.

## R5 — Pontes

**R5-1 · email_aberto (campanha):** insira na `email_eventos` um `aberto` de
campanha para um contato ligado ao cliente 5; rode o worker.
**Esperado:** evento `email_aberto` na tabela `eventos` com `cliente_id=5`;
um fluxo `trigger_evento(email_aberto)` dispara.

**R5-2 · dica_cuidado_clicada:** coberto na R6.

**R5-3 · pedido_criado (após instrumentar):** faça um pedido de teste no
checkout de homologação. **Esperado:** linha em `eventos` tipo
`pedido_criado`; a Receita 6 dispara; um fluxo com
`sair_se_eventos:["pedido_criado"]` aborta. **Enquanto não instrumentar, este
teste FALHA — é o gap §11-A do manual.**

## R6 — Vida útil ponta a ponta (roteiro SQL)

```sql
-- 1. Regra de teste: categoria X com 1 mês (rápido de vencer)
INSERT INTO categoria_vida_util (categoria_id, meses, titulo, dica)
VALUES (ID_CAT, 1, 'Teste: já olhou o {{produto_nome}}?', 'Dica de teste da {{moto_apelido}}.');

-- 2. Pedido fake entregue há 2 meses (cliente 5, produto da categoria X)
INSERT INTO pedidos (cliente_id, codigo, status_pedido, criado_em)
VALUES (5, 'TESTE-VU-1', 'entregue', DATE_SUB(NOW(), INTERVAL 2 MONTH));
SET @ped = LAST_INSERT_ID();
INSERT INTO pedido_itens (pedido_id, produto_id, nome_produto, quantidade, preco_unitario)
VALUES (@ped, ID_PRODUTO, 'Produto teste', 1, 100);
INSERT INTO pedido_status_historico (pedido_id, status_novo, criado_em)
VALUES (@ped, 'entregue', DATE_SUB(NOW(), INTERVAL 2 MONTH));
```
```bash
php cli/vida-util-worker.php --so-agendar --verbose
```
```sql
-- 3. Esperado: 1 linha agendada, disparar_em = entrega + 1 mês (JÁ vencida)
SELECT * FROM vida_util_agenda WHERE pedido_id=@ped;
```
```bash
php cli/vida-util-worker.php --so-disparar --verbose
```
**Esperado:** status `enviado`; sino do cliente 5 com o título interpolado.
Clique no sino (logado como o cliente 5) → redireciona pro produto e:
```sql
SELECT * FROM eventos WHERE tipo='dica_cuidado_clicada' ORDER BY id DESC LIMIT 1;
SELECT clicado_em FROM vida_util_agenda WHERE pedido_id=@ped;   -- carimbado
```
Com a Receita 4 publicada, o próximo ciclo do fluxo-worker dispara a venda.

**R6-b · Devolução cancela:** repita 1–2 com outro pedido, adicione
`INSERT INTO pedido_status_historico (pedido_id, status_novo, criado_em)
VALUES (@ped2, 'devolvido', NOW());`, rode `--so-agendar`.
**Esperado:** linha vira `cancelado`, nada dispara.

**R6-c · Dois pneus = uma dica:** pedido com 2 itens da mesma categoria →
`--so-agendar` cria 2 linhas; `--so-disparar` envia **1** e marca a outra
`agrupado`.

**R6-d · Clique de não-dono:** logado como outro cliente, abra `/dica/{id}` da
dica do cliente 5. **Esperado:** redireciona, **sem** evento novo.

## R7 — Segurança

**R7-1 · SSRF do webhook:** `acao_webhook` com `url:"http://127.0.0.1/x"` (e
`http://169.254.169.254/`). **Esperado:** o nó falha com erro de URL bloqueada
no log (`__erro` se `parar_se_falhar`, senão detalhe no aviso) — **nunca** faz
a requisição.

**R7-2 · HMAC:** endpoint seu conferindo `X-Signature-SHA256` — assinatura
bate com `hash_hmac('sha256', corpo, secret)`.

**R7-3 · CSRF:** `curl -X POST /admin/vida-util/salvar` sem token → recusado.
Idem `/admin/fluxos/...` de escrita.

**R7-4 · Permissões:** usuário admin sem a permissão do módulo → telas
bloqueadas (cascata `requirePermission → requireAdminLevel`).

**R7-5 · Posse da dica:** coberto em R6-d.

## R8 — Observabilidade (conferida DEPOIS das rodadas acima)

- Balões: totais batem com o que você rodou? Racha das condições coerente?
- Painel do nó: erros do R2-12/R7-1 aparecem com a mensagem real?
- Timeline: filtro por `cliente_id=5` reconta suas jornadas passo a passo?
  Filtro "só erros" mostra exatamente os erros provocados?
- KPIs: "envios hoje" **não** conta os pulados por cap (R4-1)?
- Purge: `UPDATE fluxo_motor_config SET valor='' WHERE
  chave='fluxo_log_purge_ultimo';` + inserir linha com `criado_em` de 120 dias
  atrás + rodar o worker → linha some, recentes ficam.

## R9 — Carga leve (sanidade, não benchmark)

```bash
for i in $(seq 1 50); do php cli/fluxo-testar.php --fluxo=ID --cliente=5 >/dev/null; done
time php cli/fluxo-worker.php
```
**Esperado:** worker termina em segundos; nenhum lock preso
(`storage/locks/`); `fluxo_passos_log` cresce ~50×(nós+2) linhas.

---

## Matriz sintoma → onde olhar

| sintoma | primeiro lugar a olhar |
|---|---|
| Trigger não dispara | `eventos` (o evento existe? `cliente_id` certo?) → cursor `trigger_cursor_evento_id` → fluxo publicado? `apenas_logados`? |
| Jornada travada | `fluxo_execucoes.status` + `dormir_ate`/`timeout_em`; timeline da execução |
| Email não chegou | timeline (foi `cap`? `__erro`? porta false de `cond_aceita_marketing`?) → provedor no módulo de email |
| Var saiu vazia | a chave existe no contexto **antes** do nó? (cupom/vendedor vêm de nós anteriores) |
| Balão zerado | fluxo republicado (stats são POR VERSÃO — versão nova começa do zero) |
| Nada roda | cron do www-data; lock preso em `storage/locks/`; `php cli/fluxo-worker.php --verbose` na mão |
