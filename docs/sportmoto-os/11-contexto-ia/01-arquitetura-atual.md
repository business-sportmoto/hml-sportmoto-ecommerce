# Arquitetura atual — o mapa em uma página

**Atualizado:** 04/09/2026
**Propósito:** contexto obrigatório para assistentes de IA (`CLAUDE.md` §2).
Este arquivo estava com **0 byte** desde a criação do Vault; esta é a primeira
versão, escrita a partir do que foi verificado no código durante os projetos de
BI e de agentes. Corrija o que estiver desatualizado — é melhor errar aqui e
ser corrigido do que cada sessão redescobrir a arquitetura lendo código.

---

## 1. Forma geral

PHP 8.3, MVC próprio, MySQL 8.4, jQuery 4. Sem framework.

```
index.php (loja)  ─┐
admin/index.php   ─┼─► autoloader por LISTA DE PASTAS (3 cópias: loja, admin, CLI)
bootstrap-cli.php ─┘      → classe nova em pasta nova exige adicionar a pasta nas 3

app/controllers · app/models · app/services (+ subpastas) · app/helpers · core/
admin/controllers · admin/views · admin/assets
cli/*.php — workers e crons (lock em storage/locks/, padrão do chat-worker)
sql/ — migrations soltas (*.sql é gitignored) · sql/ia/ — migrations datadas
       da Central de IA, aplicadas por cli/ia-migrar.php
docs/sportmoto-os/ — este Vault (Obsidian; /docs/ é gitignored)
```

**Regra de ouro dos IDs:** `admins.id ≠ usuarios.id ≠ clientes.id`. Autoria e
dono de qualquer coisa = `AuthHelper::usuarioId()` (a pessoa), nunca
`Session::get('admin_id')`. Ver `CLAUDE.md` §4.1.

---

## 2. Camadas que atravessam o sistema

### 2.1 Pagamento, logística, chat — os módulos grandes
Cada um tem services em subpasta (`payment/`, `logistica/`, chat na raiz de
`services/`), adapters por provedor e worker próprio. Não estão documentados
aqui em detalhe; ver [[../02-arquitetura/arquitetura-atual]] quando existir, e
[[../07-workers-cron/mapa-workers-cron]] para os crons.

### 2.2 BI — a camada `bi_*` (setembro/2026)
30 views + 2 tabelas físicas em MySQL, prefixo `bi_`, servindo o painel interno
(`/admin/power-bi`) **e** o Power BI Desktop pelo mesmo usuário read-only.
Uma regra de negócio escrita uma vez. `BiService` lê só views `bi_*`;
`PwbDashboardAnalyticsService` formata; a view renderiza.

- Definição única de "venda": `pedido_status.classe_bi`.
- Custo é snapshot em `pedido_itens.custo_unitario` — NULL, nunca 0.
- Deploy incompleto se explica sozinho: `cli/bi-diagnostico.php`.

→ [[../12-decisoes-tecnicas/bi-indice]] · [[../09-banco-de-dados/camada-bi-dicionario]]

### 2.3 Central de Marketing IA — `app/services/ia/` (julho/2026)
Porta única `IAOrchestrator`: escolhe modelo por **capacidade + prioridade**,
faz fallback entre modelos, respeita teto diário por provedor e `ia_limites`,
registra `ia_roteamento_log`, calcula custo real por token. Adapters em
`ia/providers/` (OpenAI, Gemini, Replicate, Claude). Chaves cifradas em
`ia_provedores.api_key_enc` (`IACriptoService`, `IA_CRYPTO_KEY` no `.env`).
Cada chamada = uma linha em `ia_geracoes`. Tipos de conteúdo em
`ia_tipos_conteudo` (persona em `instrucoes_sistema`, editável na tela).

Quem usa: geração de conteúdo/imagens (Central), SEO (`SeoIaService`), Q&A de
produto (`GeminiQAService`, desde 03/09), chat/Instagram (`ChatIaAgenteService`).

### 2.4 Agentes de BI — sobre as duas anteriores (setembro/2026)
Tool use sobre a camada `bi_*`, via o orquestrador. `IAAgenteGateway`
(ferramentas = wrappers do `BiService`, whitelist por agente, schema fechado,
cache, LGPD), `IAAgenteService` (conversa, pré-carga, guarda de números),
`IAOrchestrator::executarAgente()` (o loop), `ClaudeAdapter::conversar()`.
Capacidade `agente` só tem modelos Claude. Três modos: botão no BI, cron
agendado, por evento sobre `BiService::alertas()`.

Desde 05/09 há também o **catálogo editável** (`ia_agentes`, tela Agentes de
IA), o **Diretor** (`perguntar_agente` → sub-conversas `delegado`) e a **Fase
C**: o BI publica eventos no stream (`BiEventoService` → `eventos` com tipos
`bi_*`/`agenda_*`) e o motor de automação v2 tem nós `agente_ia`,
`cond_prioridade`, `cond_contexto`, `acao_sino_admins`, `acao_email_gestor` —
as regras fixas do worker viraram fluxos do canvas.
→ [[../12-decisoes-tecnicas/ia-agentes-bi]]

---

## 3. Convenções que mais pegam

- **Services, não traits.** Injeção por construtor; testes injetam dublês.
- **Não colocar SQL em views.** (Há uma exceção conhecida em
  `pwb-dashboard.php` — contagem de respostas sem procedência — herdada.)
- **Prepared statements sempre.** Nome de coluna interpolado só via whitelist.
- **Vault primeiro.** `04-bugs` para o que se achou quebrado, `12-decisoes-tecnicas`
  para decisão, `07-workers-cron` para cron.
- **Aplicar `.sql` sempre com `--default-character-set=utf8mb4`.**
- **Distinguir NULL de zero.** "Sem dado" ≠ "indisponível" ≠ "zero" — a tela
  mostra `—` com o motivo, nunca `R$ 0,00` quando não sabe.

---

## 4. Onde está o que ainda não está documentado

- `02-arquitetura/arquitetura-atual.md` e `11-contexto-ia/02-convencoes.md`
  continuam com **0 byte**.
- Inventário de tabelas (240+) nunca foi escrito em `09-banco-de-dados/`; só a
  camada `bi_*` está lá.
