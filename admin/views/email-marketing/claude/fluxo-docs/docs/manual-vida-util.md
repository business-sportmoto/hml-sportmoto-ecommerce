# Manual — Vida Útil / Dicas de Cuidado

Subsistema que lembra o cliente de cuidar da peça comprada, meses depois da
entrega — e só faz venda **depois** que ele demonstra interesse clicando.

---

## 1. A filosofia (que explica cada decisão técnica)

A dica é **cuidado, não venda**: *"Já olhou os pneus da Pretinha?"*, nunca
*"hora de trocar seus pneus"*. Prazo conservador. Texto que não vende nada.

O clique é o sinal de interesse — e vira o evento `dica_cuidado_clicada` no
stream. **Aí sim** um fluxo do motor faz a abordagem comercial (Receita 4 do
manual de automações). Quem não clica não recebe mais nada sobre aquilo.

Por isso não é um nó do grafo: o grafo é jornada por-cliente; vida útil é
por-**item comprado**, com prazos de meses. A ponte entre os dois mundos é o
clique.

## 2. A mecânica ponta a ponta

```
pedido vira "entregue" (pedido_status_historico OU pedido_historico)
        │  fase AGENDAR do worker (cursor nas DUAS tabelas)
        ▼
vida_util_agenda: 1 linha por item que tenha regra de categoria
   disparar_em = data da entrega + meses da regra
        │  (meses depois) fase DISPARAR
        ▼
notificação in-app com {{produto_nome}} / {{moto_apelido}} → url /dica/{id}
        │  cliente clica
        ▼
GET /dica/{id}: valida o dono → carimba clicado_em
  → evento `dica_cuidado_clicada` no stream → redireciona pro produto
        │  próximo ciclo do fluxo-worker
        ▼
trigger_evento(dica_cuidado_clicada) dispara o fluxo de venda
```

Status cancelam a dica pendente: `cancelado`, `devolvido`, `em_devolucao`,
`troca_devolucao` — ninguém recebe "como está seu pneu?" de pneu devolvido.

## 3. As tabelas

**`categoria_vida_util`** — a regra (uma por categoria, UNIQUE):
`categoria_id`, `meses` (1–600), `titulo` (≤150), `dica` (≤2000),
`categoria_notif` (categoria do sino), `ativo`.
Título e dica aceitam `{{produto_nome}}` e `{{moto_apelido}}`.

**`vida_util_agenda`** — o agendamento (uma por item entregue com regra):
`UNIQUE (pedido_item_id)` = idempotência de graça (re-varrer não duplica).

| status | significa |
|---|---|
| `agendado` | esperando `disparar_em` |
| `enviado` | notificação saiu (`enviado_em`; `clicado_em` se clicou) |
| `cancelado` | pedido devolvido/cancelado |
| `agrupado` | dedup — outra dica da mesma categoria já cobriu |
| `sem_permissao` | cliente com opt-out de marketing |

## 4. Regras de comportamento (as que evitam constrangimento)

- **Produto em várias categorias → vence o MENOR prazo** (pivot
  `produto_categorias`). Conservador e determinístico.
- **Comprou 2 pneus → 1 dica só.** Dedup por (cliente, categoria) no mesmo
  ciclo E contra os últimos `vida_util_dedup_dias` (30). A segunda vira
  `agrupado`.
- **Frequency cap adia, não descarta.** Cliente saturado (Fase 3B) →
  reagenda +7 dias, até `vida_util_max_adiamentos` (3); depois envia mesmo
  assim. O envio conta no cap com `fluxo_id = 0`.
- **Opt-out respeitado** via `NotifPrefsService` → `sem_permissao`.
- **Só o dono clica.** `/dica/{id}` de outro cliente redireciona sem
  registrar evento.

## 5. Config (`fluxo_motor_config`)

| chave | default | efeito |
|---|---|---|
| `vida_util_cursor_psh` / `_ph` | 0 | cursores das duas tabelas de histórico |
| `vida_util_respeita_cap` | 1 | 0 = ignora o teto semanal |
| `vida_util_max_adiamentos` | 3 | adiamentos por cap antes de enviar assim mesmo |
| `vida_util_dedup_dias` | 30 | janela do "2 pneus = 1 dica" |

## 6. Escrevendo boas regras (a tela `/admin/vida-util`)

A tela mostra o **funil** (Agendadas → Enviadas → Cliques com a taxa) e o
**preview ao vivo** da notificação enquanto você escreve — use-o para manter o
tom. Teste de tom: se o título funcionaria vindo de um mecânico amigo, está
certo; se parece anúncio, reescreva.

Prazos sugeridos (conservadores de propósito):

| categoria | meses | título de exemplo |
|---|---|---|
| Óleos | 6 | "Hora de conferir o óleo" |
| Pastilhas/freio | 8 | "Como estão as pastilhas de freio?" |
| Pneus | 12 | "Já olhou os pneus da {{moto_apelido}}?" |
| Kit relação | 18 | "Uma olhada no kit relação" |
| Capacetes | 36 | "Seu capacete ainda protege como no primeiro dia?" |

Proteções da tela: a **categoria não muda na edição** (agendamentos apontam
pra ela — o backend ignora até POST forçado); **excluir é bloqueado com
histórico** (pause em vez de excluir); uma regra por categoria.

## 7. A ponte com o motor — fechando a venda

Monte a Receita 4 do manual de automações
(`trigger_evento(dica_cuidado_clicada)` → aceita marketing? → `acao_cupom`
CUIDA → `acao_email`). O contexto do clique carrega o `produto_id` — o email
fala exatamente da peça da dica.

**A taxa de clique do funil é o termômetro da tese.** Taxa boa = o tom de
cuidado funciona. Taxa caindo = os textos viraram anúncio; reescreva.

## 8. Primeira rodada em produção (IMPORTANTE)

Os cursores começam em 0: a primeira execução varre **todo** o histórico de
status. Pedidos entregues há anos vão gerar dicas já vencidas.

```bash
# 1. Só agendar, sem enviar nada
php cli/vida-util-worker.php --so-agendar --verbose

# 2. Conferir o que caiu
SELECT status, COUNT(*) FROM vida_util_agenda GROUP BY status;
SELECT COUNT(*) FROM vida_util_agenda
 WHERE status='agendado' AND disparar_em <= CURDATE();

# 3. Volume vencido grande? Cancele o passado distante antes de ligar o envio
UPDATE vida_util_agenda SET status='cancelado'
 WHERE status='agendado' AND disparar_em < DATE_SUB(CURDATE(), INTERVAL 60 DAY);

# 4. Só então deixe o cron completo rodar (diário 09h)
```

## 9. Troubleshooting

| sintoma | causa provável | onde olhar |
|---|---|---|
| Nada é agendado | pedidos não chegam a `entregue`, ou os cursores já passaram do histórico | `SELECT valor FROM fluxo_motor_config WHERE chave LIKE 'vida_util_cursor%'`; histórico das duas tabelas |
| Item não agendou | produto sem categoria com regra, ou `produto_id` NULL no item | `produto_categorias` do produto × `categoria_vida_util` |
| Dica não dispara no dia | worker diário ainda não rodou (09h); ou adiada por cap (`tentativas` > 0) | linha na `vida_util_agenda` |
| Cliente recebeu 2 dicas iguais | dedup_dias muito curto, ou categorias distintas com textos parecidos | `vida_util_dedup_dias`; regras |
| Clique não vira fluxo | fluxo da Receita 4 não publicado; ou clique de não-dono | `eventos` tipo `dica_cuidado_clicada`; timeline do motor |
| `{{moto_apelido}}` saiu "sua moto" | cliente sem moto `principal=1` | garagem do cliente |

O roteiro de teste completo (com SQL passo a passo) está no
`plano-de-testes.md`, rodada R6.
