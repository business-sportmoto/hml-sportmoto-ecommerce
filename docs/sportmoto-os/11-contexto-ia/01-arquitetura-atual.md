---
tipo: contexto-ia
status: ativo
atualizado_em: 2026-09-03
---

# Arquitetura atual

MVC próprio, PHP 8.3, **sem framework e sem namespaces**. Classes são resolvidas
por autoloader que varre uma lista fixa de diretórios.

> Este documento descreve o que está **no código hoje**. Convenções e armadilhas
> em [[02-convencoes]].

---

## As três entradas

| Entrada | Serve | Rotas |
|---|---|---|
| `index.php` | loja | `config/routes.php` (~253) |
| `admin/index.php` | painel | `admin/config/routes.php` (~353) |
| `cli/` | workers e crons | 33 scripts |

Cada entrada registra **o próprio autoloader**, e as listas **não são iguais**.
Essa diferença já causou dois fatais — ver
[[02-convencoes#Os dois autoloaders]].

---

## Diretórios

```
core/           8 arquivos — Router, AppRouter, Controller, Model,
                Session, View, ErrorHandler, HandlesStreamVideo
config/         defines, config, database, env, rotas (loja / app / e-mail mkt)

app/
  controllers/  71  — apenas a LOJA (o painel não os carrega)
  models/       58  — acesso a dados
  services/    112  — lógica de domínio, + subpastas:
                       payment/{adquirentes,antifraude}, email/{providers},
                       sms/{providers}, ia/{providers},
                       logistica/{transportadoras}, conversion/, app/
  helpers/      19  — AuthHelper, SecurityHelper, ConfigHelper,
                       HtmlHelper, IconLibrary, PerformanceHelper…
  presenters/   29  — formatação para a view
  views/

admin/
  controllers/  89  — painel
  config/routes.php
  views/
  assets/{css,js}

views/          loja
assets/         loja
cli/            33  — workers e crons
sql/            migrations (IGNORADAS pelo git — ver 04-bugs)
storage/logs/
docs/sportmoto-os/   este Vault
```

---

## Camadas

**Controller** recebe a requisição, valida CSRF, exige nível de acesso, delega e
responde (`render()` ou `json()`). Não contém regra de negócio.

**Service** é onde a regra mora. Injetado no construtor como dependência, nunca
trait. Lógica que loja e painel compartilham mora **obrigatoriamente** aqui — é o
único lugar que os dois autoloaders alcançam.

**Model** encapsula SQL. PDO com prepared statement, emulação desligada.

**Presenter** formata para exibição.

---

## Autenticação e permissão

Uma pessoa é uma linha em `usuarios`; os papéis penduram nela (`admins`,
`vendedores`, `clientes`). `admins.id ≠ usuarios.id ≠ clientes.id`.

API em `AuthHelper`: `requireAdmin()`, `requireAdminLevel(...$niveis)`,
`hasLevel(...)`, `usuarioId()`, `adminDisplay()`, `requirePermission()`.
Cargos em `app/helpers/Cargos.php` — fonte única.

Detalhamento completo (os 5 cargos, o bypass do `super`, visibilidade por linha,
receitas): **`CLAUDE.md` §4**.

---

## Serviços transversais

| Serviço | Papel |
|---|---|
| `LogService` | log em banco + arquivo, com redação de segredo, dedup por fingerprint e `request_id`. `CLAUDE.md` §5 |
| `NotificacaoService` | notificações in-app, cliente e admin. `CLAUDE.md` §6 |
| `ImageUploadService` / `StreamService` | mídia — imagem pelo servidor, vídeo direto do browser. `CLAUDE.md` §7 |
| `ConfigHelper` | chave/valor em `configuracoes`, por grupo |
| `SecurityHelper` | CSRF (`CSRF_TOKEN_NAME`), sanitização |
| `HtmlHelper::sanitizeRich()` | HTML Purifier, para conteúdo vindo de RTE |

## Front-end

jQuery 4 (`$.trim` não existe mais) com plugins internos: Toast, Lightbox,
`adminDrawer` (`CLAUDE.md` §8), wrappers de AJAX. Ícones por `IconLibrary` +
sprite.

## Módulo Chat — visão de 1 minuto

Motor **próprio**, separado da automação v2 de propósito
([[modulo-chat-whatsapp#2. Motor próprio em vez de reusar o `FluxoMotor` (automação v2)|§2]]).
Contexto vivo em [[04-sessao-chat-ia-instagram]]; índice em [[chat-indice]].

```
webhook (index.php) ──► ChatWebhookService / ChatInstagramService
                              │  casa automação (chat_ig_regras)
                              │  cria o contato · responde o comentário
                              ▼
                        ChatFluxoMotor::iniciar()  ──► chat_sessoes
                              │  anda pelo grafo publicado (chat_fluxo_nos/conexoes)
                              ▼
                        ChatNoRegistry  (36 tipos de bloco, um arquivo só)
                              │  ia_responder ─► ChatIaAgenteService ─► IAOrchestrator
                              ▼
                        ChatEnvioService  (janela 24h, assinatura, private reply)
```

- **Private reply** é o único jeito de mandar direct para quem só comentou:
  sai na primeira mensagem da conversa, uma vez por comentário.
- **O contato nasce quando a automação precisa dele** (DM, tag ou fluxo).
- **A IA só fala do produto fixo no bloco**, com guarda de números; a
  mensagem final é composta por um modelo do operador (`{{resposta}}`).
- **`portas()` é o máximo declarado; `portasAtivas($config)` é o que vale.**
- `app/services/ia/` e `ia/providers/` precisam estar nos **três** autoloaders
  — faltavam no `index.php` (webhook) e no `chat-worker` até 02/09/2026.

| Preciso de… | Arquivo |
|---|---|
| tipos de bloco | `app/services/ChatNoRegistry.php` (+ `rotulo()`) |
| paleta / painel do editor | `admin/assets/js/chat-fluxo.js` |
| entrada do Instagram | `app/services/ChatInstagramService.php` |
| envio (WA e IG) | `app/services/ChatEnvioService.php` |
| agente de IA | `app/services/ChatIaAgenteService.php` |
| diagnóstico | `cli/chat-ig-check.php` · `cli/chat-fluxo-check.php` · `cli/chat-ia-teste.php` |
| testes | `tests/chat/` (17 suítes, 569 asserções) |

## Infra

Cloudflare (WAF, CDN, R2, Stream) na frente. O Nginx reescreve `REMOTE_ADDR`
com o IP real — sem isso o log registraria o IP da Cloudflare.
