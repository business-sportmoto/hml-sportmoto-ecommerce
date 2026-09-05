# Observabilidade do Motor de Automação

Responde a pergunta "tá funcionando?" em três camadas: **números em cada balão**
do canvas, **atividade do nó** no painel lateral, e uma **linha do tempo geral**
de tudo que o motor faz.

Testado: **47/47 asserções** — do marco de início ao purge, incluindo o teste
mais importante de todos: a tabela de log é dropada e o motor continua
processando jornadas normalmente.

## A decisão de arquitetura

**Uma tabela como fonte única: `fluxo_passos_log`.** Cada passo executado vira
uma linha (nó, porta de saída, duração em ms, detalhe), mais dois marcos por
jornada (`__inicio` com o tipo do trigger, `__fim` com o status final). Os
contadores dos balões, a timeline e os KPIs são **queries sobre ela** — nada de
contadores paralelos que podem dessincronizar do que de fato aconteceu.

**Regra de ouro: o log nunca derruba o motor.** Toda escrita é try/catch total
dentro do `FluxoLogService`. Se a migração não rodou, se a tabela sumiu, se o
disco encheu — a jornada do cliente continua. Está sob teste (o teste 11 dropa
a tabela no meio e verifica que a execução conclui).

## O que cada linha registra

| campo | exemplo |
|---|---|
| `no_chave` / `tipo_no` | `c1` / `cond_tem_tag` |
| `porta` | `true`, `false`, `saida`, `evento`, `timeout` — ou `__dormir`, `__aguardar`, `__erro` |
| `detalhe` | erro truncado, ou `cap` quando o envio foi pulado pelo teto semanal |
| `duracao_ms` | tempo do nó (pega webhook lento, template pesado) |

O `detalhe = 'cap'` merece nota: o balão do email mostra que o nó "rodou", mas
a timeline e o KPI de envios distinguem **enviado de verdade** de **pulado pelo
frequency capping** — sem isso, o cap da Fase 3B seria invisível e você nunca
saberia por que um cliente não recebeu.

## As três telas

### 1. Balões no canvas
Cada nó da versão publicada ganha um rodapé discreto: total de execuções,
e nos nós de 2+ portas o racha percentual (`true 67% · false 33%`) — o dado
que diz se a condição está calibrada. Nó com erro ganha um contador âmbar.

### 2. Painel do nó
Clicou no balão, a seção **Atividade** aparece no painel lateral junto da
configuração: execuções, contagem por porta, tempo médio, e os últimos 3 erros
com a mensagem real.

### 3. Linha do tempo geral (`/admin/fluxos/atividade`)
Cada passo do motor em ordem: jornadas iniciadas (com o trigger que disparou),
envios por canal, condições com a porta tomada, cupons gerados, vendedores
avisados, esperas, erros em destaque. Filtros por fluxo, por cliente e "só
erros"; KPIs no topo (jornadas hoje, envios hoje, erros 24h, jornadas em curso
com o racha dormindo/aguardando); paginação por cursor; **atualiza a cada 15s**
com o padrão do projeto (aba em background pausa, voltar à aba recarrega).

## Volume e retenção

Fluxos típicos de 5-10 nós × milhares de execuções/dia cabem com folga nos
índices. A retenção (padrão **90 dias**, `fluxo_log_retencao_dias`) é aplicada
por um purge diário embutido no worker — em lotes de 5000 para não segurar
lock, com proteção contra config quebrada (retenção < 7 volta para 90).

## Arquivos

```
sql/fluxo_observabilidade_migration.sql        → rodar no banco
services/FluxoLogService.php                   → app/services/
services/FluxoMotor-OBSERVABILIDADE-PATCH.php  → 4 edições (motor + worker)
controllers/FluxoAdminController-OBS-PATCH.php → 2 métodos + 3 rotas
js/fluxo-canvas-OBS-PATCH.js                   → 3 blocos no fluxo-canvas.js + CSS
js/fluxo-atividade.js                          → public/js/
css/fluxo-atividade.css                        → public/css/
views/admin/fluxos/atividade.php               → views/admin/fluxos/
```

## Instalação

**1. Banco:**
```bash
mysql -u USER -p BANCO < sql/fluxo_observabilidade_migration.sql
```

**2. Service:** `FluxoLogService.php` → `app/services/` (arquivo plano, o
autoloader já encontra).

**3. Motor + worker:** as 4 edições do
`FluxoMotor-OBSERVABILIDADE-PATCH.php` (marco de início, passo cronometrado,
marco de fim, purge diário no worker). Âncoras consideram 3A e 3B aplicadas.

**4. Controller + rotas:** os 2 métodos do patch no `FluxoAdminController`, e:

```php
AdminRouter::get('/fluxos/atividade',       'FluxoAdminController@atividade');
AdminRouter::get('/fluxos/atividade/dados', 'FluxoAdminController@atividadeDados');
AdminRouter::get('/fluxos/{id}/stats',      'FluxoAdminController@stats');
```

**ATENÇÃO À ORDEM:** `/fluxos/atividade` precisa vir **antes** de
`/fluxos/{id}` na tabela de rotas, senão "atividade" casa como `{id}`.

**5. Canvas:** os 3 blocos do `fluxo-canvas-OBS-PATCH.js` no
`fluxo-canvas.js` + o CSS indicado no fim do arquivo no `fluxo-canvas.css`.

**6. Tela de atividade:** view, JS e CSS para as pastas indicadas. Adicione o
link no menu (ou pelo botão "Fluxos" ↔ "Atividade" entre as duas telas).

## O que os números respondem

- *"O fluxo de carrinho abandonado tá rodando?"* → balão do trigger com o total.
- *"As pessoas estão caindo no ramo certo?"* → racha `true/false` da condição.
- *"Por que o cliente 512 não recebeu?"* → timeline filtrada por cliente:
  ou a condição mandou pro outro ramo, ou `cap`, ou erro — está escrito.
- *"Algum nó está lento?"* → `duracao_ms` médio no painel; passos acima de
  500ms ganham destaque âmbar na timeline.
- *"Teve erro essa semana?"* → KPI + filtro "só erros" com a mensagem real.
