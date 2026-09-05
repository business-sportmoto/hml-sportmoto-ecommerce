# Radar de Clientes — Bloco 1 das automações de cliente

Emite eventos baseados em **estados do cliente que amadurecem com o tempo** —
aniversário, inatividade e saldo prestes a expirar — para o motor de automação
transformar em campanhas. Entrega 4 das 5 automações que você pediu, incluindo
a de "último login → saldo pendente".

Testado: **61/61 asserções** (sondas, anti-repetição, edge de 29/02,
sem-retrocesso de limiar, `cond_perfil` e segurança).

## A ideia

O motor só reage a eventos de navegação (cliques). "Faz 30 dias que não loga",
"hoje é aniversário", "tem crédito expirando" não são cliques — são estados que
passam a ser verdade sozinhos. O **radar** (`cli/cliente-radar.php`, diário)
varre o cadastro procurando quem cruzou essas linhas e emite eventos no stream.
O motor existente cuida do resto: jornadas, guard-rails, cupom, A/B.

Filosofia: **o radar DETECTA, o motor DECIDE e AGE.** Nenhuma lógica de
campanha vive no radar.

## Eventos emitidos

| evento | quando | variáveis no contexto |
|---|---|---|
| `aniversario` | aniversário hoje (do `nascimento`) | `{{aniversario_idade}}` |
| `inativo_30d` / `inativo_60d` / `inativo_90d` | `ultimo_login` há N dias | `{{dias_inativo}}`, `{{dias_marco}}`, `{{saldo_disponivel}}` |
| `saldo_expirando` | crédito expira em ≤ 7 dias (configurável) | `{{saldo_expira_em}}`, `{{saldo_expira_valor}}`, `{{saldo_disponivel}}` |

As variáveis fluem sozinhas: o radar as põe no `contexto_json` do evento, o
`FluxoTriggerService` copia pra execução, e o `montarVars()` do motor as expõe
como `{{var}}`. **Nenhuma alteração no motor.**

## Decisões de design (e por quê)

**Limiares de inatividade fixos (30/60/90).** Limiares configuráveis gerariam
nomes de evento dinâmicos que o dropdown do canvas não conheceria — e
esbarrariam no gap conhecido "triggers não filtram contexto". Nomes fixos são
previsíveis e vão direto pro seletor.

**Sem retrocesso de limiar.** Um cliente detectado já aos 65 dias recebe
`inativo_60d` e **nunca** `inativo_30d` depois (aquele ponto já passou). Ao
emitir o maior limiar cruzado, o radar consome as chaves dos menores da mesma
sessão. Isso não impede o avanço legítimo: conforme o tempo passa, 30→60→90
disparam em ordem. (Este era um bug que a suíte pegou — está sob teste.)

**Anti-repetição com âncora de reset embutida na chave** (`cliente_radar_emissoes`):
- aniversário → `aniversario:{ano}` — reseta todo ano
- inatividade → `inativo_30d:{epoch_do_ultimo_login}` — **reseta quando o
  cliente loga de novo** (novo `ultimo_login` = chave nova)
- saldo → `saldo_expirando:{transacao_id}` — uma vez por crédito

`INSERT IGNORE` na chave; se criou a linha, emite; se já existia, pula. Atômico.

**"Inativo" pressupõe que já foi ativo** → só clientes com `ultimo_login`
preenchido. Quem nunca logou é caso do Bloco 2 (incentivo de cadastro).

**Saldo expirando mostra o saldo ATUAL** do cliente + a data de expiração mais
próxima — mais honesto que o valor da transação (que pode já ter sido gasto). E
só alerta quem ainda tem saldo (`saldo_disponivel >= 0,01`).

**Gênero é CONDIÇÃO, não evento.** Gênero não "acontece" nem "amadurece", é um
atributo. O nó novo `cond_perfil` ramifica por ele — e cobre também saldo,
newsletter e verificação, com allowlist de campo e operador (nada de SQL
arbitrário). É assim que se faz "campanha só para mulheres" (evento de data +
`cond_perfil` gênero=F) e "inativo E tem saldo" (`inativo_30d` +
`cond_perfil` saldo_disponivel >= 0.01).

## O catálogo do motor vai a 20 nós

`cond_perfil` (âmbar, portas true/false). Config:
`{"campo":"genero","operador":"=","valor":"F"}` ou
`{"campo":"saldo_disponivel","operador":">=","valor":50}`. Allowlist de campo:
`genero`, `saldo_disponivel`, `newsletter`, `verificado`. Numéricos aceitam
`>=,>,<=,<,=,!=`; texto aceita `=,!=`.

## Arquivos

```
sql/cliente_radar_migration.sql          → rodar no banco
services/ClienteRadarService.php         → app/services/
cli/cliente-radar.php                    → cli/
patches/cond_perfil-PATCH.php            → 2 edições em FluxoNoRegistry.php
patches/canvas-eventos-radar-PATCH.js    → eventos no dropdown + cond_perfil na paleta
```

## Instalação

**1. Banco:**
```bash
mysql -u USER -p BANCO < sql/cliente_radar_migration.sql
```

**2. Service:** `ClienteRadarService.php` → `app/services/` (arquivo plano, o
autoloader já encontra).

**3. cond_perfil:** aplique as 2 edições do `cond_perfil-PATCH.php` no
`FluxoNoRegistry.php` (cole a classe junto das condições + 1 linha no MAPA).

**4. Canvas:** aplique o `canvas-eventos-radar-PATCH.js` (eventos no dropdown
do trigger, `cond_perfil` na paleta + formulário).

**5. Worker (cron, www-data), 1×/dia de manhã — depois do fluxo-worker:**
```cron
30 8 * * *  cd /home/homo-v2.sportmoto.com.br/public_html && /usr/local/lsws/lsphp82/bin/php cli/cliente-radar.php --verbose >> storage/logs/cliente-radar.log 2>&1
```

## Primeira execução (IMPORTANTE)

Rode em dry-run antes de ligar o cron — a primeira varredura vê **todo** o
cadastro de uma vez (todo mundo inativo há 90 dias, todos os aniversariantes de
hoje):

```bash
php cli/cliente-radar.php --dry-run --verbose
```

Isso conta quantos eventos seriam emitidos, sem emitir nada. Se o volume de
inativos for grande, considere publicar os fluxos de inatividade **já com o
frequency cap ligado** (`cap_max_semana`) para não disparar uma enxurrada de
emails no primeiro dia.

## As automações que isto destrava

**Último login → saldo pendente** (a que você destacou):
`trigger_evento(inativo_30d)` → `cond_perfil(saldo_disponivel >= 0.01)` →
true → `acao_email` "Você tem {{saldo_disponivel}} esperando na sua conta".
O `inativo_30d` já traz o saldo no contexto; a condição garante que só quem tem
saldo recebe.

**Aniversário:** `trigger_evento(aniversario)` → `acao_cupom` (PARABENS, 15%) →
`acao_email` "Feliz aniversário! Aqui vão {{cupom_valor}} de presente".

**Campanha por gênero:** um evento de data (ou disparo manual) →
`cond_perfil(genero = F)` → conteúdo específico.

**Saldo expirando:** `trigger_evento(saldo_expirando)` → `acao_notificacao` +
`acao_email` "Seu saldo de {{saldo_disponivel}} expira em {{saldo_expira_em}}".

## Como testar

```bash
# Simular: force um cliente a parecer inativo e rode o radar
UPDATE usuarios SET ultimo_login = DATE_SUB(NOW(), INTERVAL 35 DAY) WHERE id = SEU_USER_ID;
php cli/cliente-radar.php --verbose

# Conferir os eventos emitidos
SELECT * FROM eventos WHERE visitante_token = 'radar00000000000000000000000000' ORDER BY id DESC;

# O fluxo-worker pega no próximo ciclo. Limpeza dos eventos de teste:
DELETE FROM eventos WHERE visitante_token = 'radar00000000000000000000000000';
DELETE FROM cliente_radar_emissoes WHERE cliente_id = SEU_CLIENTE_ID;
```

Para uma jornada completa, use o `fluxo-testar.php` do pacote de documentação
(dispara e imprime cada passo).

## Próximo: Bloco 2

Incentivo de cadastro (conta aguardando confirmação de email), com o mecanismo
isolado `cadastro_convite` + rota `/confirmar-cadastro/{token}` que já
combinamos — sem tocar no seu token transacional de 30 minutos.
