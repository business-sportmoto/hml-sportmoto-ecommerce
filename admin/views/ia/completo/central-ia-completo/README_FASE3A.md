# Central de Marketing IA — Fase 3 · Bloco A: Motor de Campanhas

Campanhas em lote sobre as tabelas que esperavam desde a Fase 0 —
**26/26 asserções verdes** contra o schema real. Este bloco é o MOTOR
(service + driver no worker); as telas chegam no **Bloco B**.

---

## O desenho aprovado

- **O plano É o cross join.** Faltante = `(ia_campanha_produtos ×
  ia_campanha_tipos)` sem geração do par — nada de tabela de jobs, nada de
  cursor. Progresso é COUNT; retomar depois de pausa é gratuito por
  construção.
- **Idempotência por dedup determinístico.** `hash('campanha|cid|pid|tid|rN')`
  — re-rodar o driver nunca duplica (T4 prova com duas rodadas seguidas).
  `rN` = número da tentativa, então "refazer falhas" cria `r1`, `r2`…
  vinculadas por `geracao_origem_id`.
- **O driver só ENFILEIRA.** Roda dentro do `ia-worker` (mesmo cron, mesmo
  lock), logo antes do `reivindicarLote` — o que ele enfileira, a mesma
  rodada já executa. Ritmo: até **4 gerações em voo por campanha**
  (`RITMO_POR_CAMPANHA`, AJUSTE no topo do service). Quem executa é a máquina
  de sempre: texto, imagem e o pipeline de banner do 2C funcionam em lote sem
  uma linha nova.
- **Limites globais continuam sendo A trava.** `podeGerar` barrou → o driver
  encerra a rodada com log e volta no próximo minuto (T6). Campanha não é
  furo de teto.
- **Orçamento opcional por campanha.** Projeção conservadora (real das
  terminais + estimado das em-voo) ≥ teto → `pausada` + broadcast no sino
  (T7). Retomar exige mexer no teto ou aceitar o gasto.
- **Falha-de-plano não loopa.** Produto sem foto num tipo de banner vira
  geração `falhou` com erro `[plano] …` e o dedup DO PAR — o driver nunca
  tenta o impossível de novo (T5). A estimativa já avisa esses produtos
  ANTES do início.
- **Conclusão honesta.** Sem pares faltando e nada em voo → `concluida`,
  mesmo com falhas (elas viram badge + "refazer falhas"). O sino avisa os
  admins, mencionando quantas falhas há para revisar (T8).

## Estados

```
rascunho ──iniciar──▶ gerando ──(tudo terminal)──▶ concluida ──▶ arquivada
              ▲          │  ▲                          │
              │       pausar│retomar               refazerFalhas
              │          ▼  │                          ▼
              └────── pausada (manual ou orçamento)  gerando
                     cancelar → cancelada (na_fila da campanha cancelam junto)
```

## Instalação (delta)

**1. Banco:**
```bash
mysql -u USER -p BANCO < sql/2026-07-20_ia_fase3a.sql
```

**2. Arquivos:** novo `app/services/ia/IACampanhaService.php`; alterados:
`IAGeracaoService.php`, `IAComposicaoService.php` (propagação de
`campanha_id` + dedup determinístico) e `ia-worker.php` (uma linha: o driver
antes do lote). Autoloader: nada novo. **Sem rotas novas neste bloco** — a
API pública do service já está pronta para o 3B consumir.

**3. Notificações:** integração automática se o módulo do sino estiver
instalado (`class_exists('NotificacaoService')`); sem ele, o driver segue
normal e só loga.

## O que o Bloco B entrega

As três telas sobre este motor: **lista** (cards com progresso X/Y, custo
real, falhas), **wizard** (dados+briefing → produtos com busca múltipla e
atalho por categoria → tipos com layout quando banner → revisão da
estimativa → "Aprovar e gerar"), e **detalhe** com a grade produto × tipo
(cada célula abre o drawer do histórico existente) + ações em massa:
pausar/retomar, refazer falhas, aprovar concluídas, arquivar.
