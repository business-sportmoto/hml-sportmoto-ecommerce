# SportMoto — Contexto Técnico para IA

> **Projeto:** e-commerce de peças e acessórios de moto (mercado BR).
> **Stack (essencial, sem segredos):** PHP 8.3 OOP · MVC próprio (sem framework) · MySQL 8.4 LTS · jQuery 4 · Cloudflare (WAF/CDN/R2/Stream).
> **Propósito deste arquivo:** fonte única de contexto para assistentes de IA. **Leia a seção 3 (Regras Fundamentais) antes de propor qualquer alteração.** Infra sensível (IPs, IDs de conta, tokens) **não** mora aqui — vive no Vault e no `.env`.

**Versão:** 1.0 · Consolidado em 25/08/2026

---

## Índice

1. Sobre o Projeto
2. Contexto Obrigatório (Vault)
3. Regras Fundamentais
4. Arquitetura de Permissões do Admin (RBAC)
5. Sistema de Logs — `LogService` v2
6. Notificações In-App
7. Camada de Mídia — Imagens e Vídeos
8. Componente `adminDrawer`

---

## 1. Sobre o Projeto

E-commerce **existente**, em PHP com arquitetura **MVC própria** (sem framework).

### Estrutura MVC

```
app/
├── controllers/
├── models/
├── services/      → toda lógica de domínio; injetada no construtor como $this->svc
└── helpers/
views/
```

### Convenções de stack

- **Banco:** MySQL 8.4 LTS (**não** MariaDB — todo o código foi escrito e testado contra MySQL). Prepared statements via PDO, sempre. Nunca concatenação de strings em SQL.
- **Services, não traits:** dependências injetadas no construtor (`$this->svc`). Se algo chegar como trait, reescrever como service injetado.
- **Frontend:** jQuery 4 + plugins internos prontos (Toast, Lightbox, `adminDrawer`, wrappers de AJAX).
- **Idioma do domínio:** PT-BR (nomes de tabelas, colunas e métodos).

---

## 2. Contexto Obrigatório (Vault)

Referências externas do Vault (`docs/sportmoto-os/`) que a IA deve carregar como contexto:

```
@docs/sportmoto-os/11-contexto-ia/00-leia-primeiro.md
@docs/sportmoto-os/11-contexto-ia/01-arquitetura-atual.md
@docs/sportmoto-os/11-contexto-ia/02-convencoes.md
@docs/sportmoto-os/11-contexto-ia/03-status-atual.md
@docs/sportmoto-os/11-contexto-ia/04-sessao-chat-ia-instagram.md
```

> Estes `@` apontam para arquivos do Vault. Em Claude Code eles são importados; na interface web (claude.ai) são apenas texto — anexe os arquivos manualmente quando necessário.

---

## 3. Regras Fundamentais

**Como a IA deve agir neste projeto.** Esta é a parte operativa do documento.

- Analise o código **existente** antes de propor alterações.
- **Não invente** arquivos, tabelas, colunas, rotas, serviços ou integrações.
- Preserve a arquitetura MVC atual.
- **Não introduza frameworks** sem autorização.
- **Nunca exponha** credenciais, tokens, senhas ou variáveis sensíveis.
- Antes de alterar uma funcionalidade, identifique **todos** os artefatos envolvidos: controllers, models, services, helpers, views e tabelas.
- Prefira alterações **incrementais e compatíveis** com o projeto atual.
- Ao concluir uma alteração relevante, informe **quais documentos do Vault precisam ser atualizados**.
- Registre novas decisões arquiteturais em `12-decisoes-tecnicas`.
- Registre bugs corrigidos em `04-bugs`.
- Registre workers e crons em `07-workers-cron`.

---

## 4. Arquitetura de Permissões do Admin (RBAC)

> Referência do sistema de níveis de acesso do painel. Documenta o que **está no código hoje** (verificado em `AuthHelper.php`, `Cargos.php`, `admin/index.php` e no schema). Itens ainda **não aplicados** estão marcados com `⏳`.

### 4.1 Modelo de identidade — a base de tudo

Uma pessoa é **uma linha em `usuarios`**. Os papéis penduram nela:

```
usuarios (id, nome, email, senha_hash, tipo, ativo)
   │
   ├── admins     (id, usuario_id UNIQUE, nivel, permissoes)   → acesso ao painel
   ├── vendedores (id, usuario_id UNIQUE, codigo, ativo)       → código de venda / comissão
   └── clientes   (id, usuario_id UNIQUE, cpf, telefone…)      → conta de compra
```

**Consequências práticas:**

- A mesma pessoa pode ser **admin e vendedor ao mesmo tempo** (dois papéis, um `usuarios.id`).
- Promover alguém ao painel = criar linha em `admins`. **Não se cria senha** — ele usa a que já tem em `usuarios`.
- Rebaixar vendedor = `vendedores.ativo = 0`. **Nunca DELETE** — preserva o histórico de comissão dos pedidos antigos (`pedidos.codigo_vendedor` → `vendedores.codigo`).

#### ⚠️ Regra de ouro dos IDs

```
admins.id  ≠  usuarios.id  ≠  clientes.id
```

São espaços de numeração **independentes**. `admin_id = 3` e `usuario_id = 3` são pessoas diferentes.

| Preciso de… | Use | Onde |
|---|---|---|
| ID do registro admin | `Session::get('admin_id')` | Domínio de pedidos (`pedido_historico.admin_id`) |
| **Identidade da pessoa** | `AuthHelper::usuarioId()` | Autoria, auditoria, responsável, comissão |

Usar a chave errada **corrompe a trilha de auditoria silenciosamente** — sem erro, sem log, só dado errado. Já aconteceu neste projeto (15 pontos no módulo de recuperação de carrinho).

### 4.2 Os 5 cargos

Fonte única: `app/helpers/Cargos.php`. Alterar cargo = alterar **lá**, nunca duplicar em view ou modal.

| Módulo | super | gerente | vendedor | editor | estoque |
|---|:---:|:---:|:---:|:---:|:---:|
| Dashboard | ✅ | ✅ | ✅ | ✅ | ✅ |
| Pedidos — ver | ✅ | ✅ | ✅ | — | ✅ |
| Pedidos — criar manual | ✅ | ✅ | ✅ | — | — |
| Pedidos — status | ✅ | ✅ | ✅ | — | logístico |
| Pedidos — pagamento / itens | ✅ | ✅ | — | — | — |
| Catálogo (produtos, categorias, marcas) | ✅ | ✅ | — | ✅ | — |
| Estoque | ✅ | ✅ | — | — | ✅ |
| Promoções & Cupons | ✅ | ✅ | — | — | — |
| Central de Recuperação — **operar** | ✅ | ✅ | ✅ | — | — |
| Central de Recuperação — **gerir** | ✅ | ✅ | — | — | — |
| Clientes | ✅ | ✅ | leitura | — | — |
| Config de automação | ✅ | ✅ | — | — | — |
| **Usuários / cargos** | ✅ | — | — | — | — |
| **Integrações (Bling, Tray, chaves)** | ✅ | — | — | — | — |

**Duas decisões de segurança embutidas:**

1. Criar admin é **escalação de privilégio** → só `super`.
2. Promoções e cupons têm **impacto financeiro direto** → `gerente+`, nunca operacional.

#### Estado do ENUM `⏳`

```sql
-- HOJE no banco:
nivel enum('super','admin','editor','estoque')

-- DEPOIS da migration-cargos.sql:
nivel enum('super','gerente','vendedor','editor','estoque')
```

Enquanto a migration não rodar, **`gerente` e `vendedor` não existem** — e todo `requireAdminLevel('super','gerente')` no código deixa passar **só o super**. Ver §4.8.

### 4.3 A API — 6 funções

#### `AuthHelper::requireAdmin(): void`

Exige apenas que **haja admin logado**. Sem checagem de nível. Redireciona para o login se não houver.

```php
public function __construct() {
    AuthHelper::requireAdmin();   // qualquer cargo entra
}
```

#### `AuthHelper::requireAdminLevel(string ...$levels): void`

**Fatal.** Exige um dos níveis listados. Quem não tem, é interrompido ali mesmo.

```php
AuthHelper::requireAdminLevel('super', 'gerente');
```

Comportamento na negação:

- **Requisição Ajax** → HTTP 403 + `{"ok":false,"msg":"Sem permissão para esta ação."}`
- **Navegação normal** → HTTP 403 + view `errors/403`

> Essa distinção existe porque um `$.post` recebendo HTML de erro quebra no parse do JSON e esconde o motivo real.

#### `AuthHelper::hasLevel(string ...$levels): bool`

**Não-fatal.** Retorna `true`/`false` sem redirecionar. Use quando o nível **muda o comportamento** em vez de bloquear.

```php
$ehGestor = AuthHelper::hasLevel('super', 'gerente');

$carrinhos = $this->model->listar(
    $filtros, $page, 25,
    $ehGestor ? null : AuthHelper::usuarioId()   // gestor vê tudo; vendedor vê o dele
);
```

#### `AuthHelper::usuarioId(): int`

Resolve o **`usuarios.id`** do admin logado (via `admins.usuario_id`).

- Lazy com cache em sessão: 1 query por sessão.
- Cobre **todos** os caminhos de autenticação (login, remember-me, futuros) — se a resolução morasse só no login, cada caminho novo reintroduziria `autor = 0`.
- Retorna **0** se o admin não tem vínculo. Quem chama decide se bloqueia.

```php
$autorId = AuthHelper::usuarioId();
if ($autorId <= 0) { /* admin órfão — bloquear */ }
```

#### `AuthHelper::adminDisplay(): array`

Nome + cargo para exibição (topbar).

```php
$adm = AuthHelper::adminDisplay();
// ['nome' => 'João Silva', 'nivel' => 'vendedor', 'label' => 'Vendedor']
```

#### `AuthHelper::requirePermission(string $permissao): void` `⏳`

Camada **granular** por permissão individual, lendo `admins.permissoes` (JSON). Existe no código, mas **não está em uso** — a Fase A protege por cargo. Reservado para exceções pontuais no futuro ("este vendedor também mexe em cupons").

#### `Cargos` — a fonte da verdade

```php
Cargos::existe('vendedor');   // bool — whitelist server-side
Cargos::get('vendedor');      // array com label, cor, bg, descricao, capacidades
Cargos::label('vendedor');    // 'Vendedor'
Cargos::paraJson();           // matriz completa para o modal de capacidades
```

### 4.4 O bypass do `super` — leia isto

```php
public static function hasLevel(string ...$levels): bool {
    if (!Session::isAdminLogado()) return false;
    $nivel = (string) Session::get('admin_nivel');
    return $nivel === 'super' || in_array($nivel, $levels, true);
}
```

**`super` passa em TODA verificação de nível, mesmo sem estar na lista.**

```php
AuthHelper::requireAdminLevel('estoque');   // super TAMBÉM entra
AuthHelper::hasLevel('editor');             // true para super
```

Isso é **intencional** — super é o cargo de última instância. Mas tem duas consequências que confundem em teste:

1. Testar permissão logado como super **não prova nada**. Sempre teste com o cargo real.
2. Não existe "só estoque, nem super" com essas funções. Se algum dia precisar excluir o super de algo, compare o nível diretamente.

### 4.5 Chaves de sessão

| Chave | Contém | Populada por |
|---|---|---|
| `admin_id` | `admins.id` | `Session::loginAdmin()` |
| `admin_nivel` | slug do cargo | `Session::loginAdmin()` |
| `usuario_id` | `usuarios.id` | login `⏳` + lazy do `usuarioId()` |
| `usuario_nome` | nome da pessoa | login `⏳` + lazy do `adminDisplay()` |
| `admin_nome` | nome (legado) | `Session::loginAdmin()` |

> `admin_nome` e `usuario_nome` guardam a mesma coisa por caminhos diferentes — redundância a limpar quando o patch da topbar entrar.

**Sessões antigas não têm `usuario_id`.** Por isso o `usuarioId()` é lazy: um admin que já estava logado no momento do deploy continua funcionando (o helper resolve na primeira chamada).

### 4.6 Receitas práticas

**Proteger um controller inteiro:**

```php
public function __construct() {
    AuthHelper::requireAdminLevel('super', 'gerente');
    // ...
}
```

**Proteger só métodos de escrita:**

```php
public function __construct() {
    AuthHelper::requireAdminLevel('super', 'gerente', 'vendedor');  // leitura
}

public function excluir(int $id): void {
    AuthHelper::requireAdminLevel('super', 'gerente');              // escrita
    // ...
}
```

**Comportamento diferente por cargo (não bloqueio):**

```php
$podeTransferir = AuthHelper::hasLevel('super');
$this->json($this->service->atribuirResponsavel(
    $id, $responsavelId, AuthHelper::usuarioId(), $podeTransferir
));
```

**Gravar autoria correta:**

```php
// ✅ certo — identidade da pessoa
$stmt->execute([AuthHelper::usuarioId(), $registroId]);

// ❌ errado — corrompe a auditoria
$stmt->execute([Session::get('admin_id'), $registroId]);
```

**Mapa de aplicação por controller:**

| Controller | Nível exigido |
|---|---|
| `AdminUsuarioController` | `('super')` |
| Integrações (Bling, Tray) | `('super')` |
| `AdminPromocaoController`, Cupons | `('super','gerente')` |
| Config de automação | `('super','gerente')` |
| `AdminPedidoController` — construtor | `('super','gerente','vendedor','estoque')` |
| `AdminPedidoController` — pagamento/itens | `('super','gerente')` |
| Produtos, Categorias, Marcas | `('super','gerente','editor')` |
| Estoque | `('super','gerente','estoque')` |
| Central de Recuperação — construtor | `requireAdmin()` + guard próprio |
| Central de Recuperação — gestão | `('super','gerente')` |

### 4.7 Visibilidade por linha (Central de Recuperação)

O único módulo com escopo **por registro**, não só por tela. Padrão de três camadas:

```php
// 1. SQL filtra a listagem
$this->model->listar($f, $page, 25, $ehGestor ? null : AuthHelper::usuarioId());

// 2. Guard por ID em TODA action que recebe {id}
private function podeAcessar(array $rec): bool {
    if ($this->ehGestor()) return true;
    $dono = (int)($rec['responsavel_id'] ?? 0);
    return $dono === 0 || $dono === AuthHelper::usuarioId();   // pool ou meu
}

// 3. 404 uniforme, nunca 403
if (!$rec || !$this->podeAcessar($rec)) { /* 404 */ }
```

**Por que 404 e não 403:** confirmar que o recurso existe já vaza informação. Com IDs sequenciais, um 403 permite mapear o pipeline dos colegas por enumeração.

**Por que o guard por ID é obrigatório:** filtrar só a listagem é teatro — basta digitar `/carrinhos-abandonados/123` na URL.

**Regras de atribuição:**

- Atribuir carrinho **sem dono** → `gerente+`
- **Transferir** carrinho com dono → **só `super`** (mexe em comissão alheia)
- Atribuíveis: só `super`, `gerente`, `vendedor` (via JOIN em `admins`)

### 4.8 Armadilhas conhecidas

**4.8.1 `gerente` não existe no ENUM `⏳`**
8 pontos do código chamam `requireAdminLevel('super','gerente')` — 7 no `AdminPedidoController`, 1 no `TrayImportController`. Enquanto a migration não rodar, **só o super passa** (pelo bypass). Um usuário `admin` toma 403 sem entender por quê.

**4.8.2 Nível fantasma `atendimento` `⏳`**
`AdminPedidoController` linhas 137 e 187 citam `'atendimento'` — nível que **não existe** nem no ENUM nem no `Cargos.php`. É inerte: não concede nada. A intenção era o que hoje se chama `vendedor`.

**4.8.3 Relogin obrigatório pós-migration**
`admin_nivel` fica gravado **na sessão**. Depois de renomear `admin` → `gerente`, quem já estava logado carrega o valor morto. **Forçar relogin geral no deploy.**

**4.8.4 Admin sem vínculo**
Se `admins.usuario_id` apontar para nada, `usuarioId()` retorna 0 e grava autor 0. Gate recomendado em módulos que registram autoria:

```php
if (AuthHelper::usuarioId() <= 0) {
    http_response_code(403);
    exit('Acesso não vinculado a um usuário do sistema.');
}
```

Auditoria periódica: `SELECT id FROM admins WHERE usuario_id IS NULL;`

**4.8.5 Ajax vs navegação**
Antes do patch, `requireAdminLevel` sempre renderizava HTML — os `$.post` do painel recebiam markup onde esperavam JSON e falhavam com erro genérico. Hoje o `isAjax()` resolve. **Se criar outro guard, replique esse comportamento.**

### 4.9 Fluxo de concessão de acesso

```
super abre /admin/usuarios/novo
   ↓
busca por e-mail ou CPF (Ajax, rate-limited 30/min)
   ↓
encontra usuário JÁ CADASTRADO na loja
   ↓  (bloqueia se já for admin)
escolhe o cargo  →  se 'vendedor', gera código (iniciais + sufixo: JOÃO SILVA → JS4K7)
   ↓
POST /usuarios/promover  →  revalida TUDO server-side
   ↓
transação: INSERT admins  +  (se vendedor) INSERT/UPDATE vendedores
   ↓
AuthLogService::registrar('admin_create')  — autor, alvo, cargo, código
```

**Nenhuma senha é criada.** A pessoa entra com a senha que já usa na loja.

**Proteções ativas:**

- Busca Ajax é conveniência, **não autorização** — o `promover()` revalida existência, cargo válido e "não é admin ainda".
- Anti-lockout: ninguém edita o próprio cargo nem se desativa.
- E-mail imutável após criação (é a identidade de login).
- `INSERT` transacional com catch 1062 — impossível admin órfão ou código duplicado.

### 4.10 Checklist de deploy `⏳`

- [ ] Rodar `migration-cargos.sql` (`admin` → `gerente`, nasce `vendedor`)
- [ ] Trocar `'atendimento'` → `'vendedor'` (`AdminPedidoController` 137 e 187)
- [ ] Aplicar `requireAdminLevel` no mapa da §4.6
- [ ] `Session::set('usuario_id', (int)$user['id'])` no login do admin
- [ ] Logout via POST + CSRF
- [ ] **Forçar relogin geral**
- [ ] Testar com 1 login por cargo — nunca só como super
- [ ] `SELECT id FROM admins WHERE usuario_id IS NULL;` → vincular órfãos

---

## 5. Sistema de Logs — `LogService` v2

Arquivo: `app/services/LogService.php`

### 5.1 Drop-in replacement

Preserva **100%** da API anterior. Todas as chamadas existentes seguem funcionando **sem alteração**:

```php
LogService::info($msg, $ctx)
LogService::error($msg, $ctx)
LogService::warning($msg, $ctx)
LogService::audit($msg, $ctx)
```

O log redundante em **arquivo** (`storage/logs/AAAA-MM-DD.log`) foi **mantido** — se o banco cair, o arquivo ainda registra. É a rede de segurança do logger.

### 5.2 O que foi adicionado

1. **Redação de segredos** — stack traces do PHP contêm **argumentos de função**: `authenticate('a@b.com', 'senha123')`. Sem redação, o log grava a senha em texto puro (LGPD/PCI). Mensagem, contexto **e** trace são redigidos.
2. **Deduplicação (fingerprint)** — um erro em loop gravava N linhas; agora incrementa `ocorrencias` na linha existente. Disco cheio = origem fora.
3. **`request_id`** — correlaciona todos os logs da **mesma** requisição.
4. **`exception()`** — captura classe, arquivo, linha e trace. É o que faz o "500 sem pista nenhuma" virar um log completo.
5. **IP real** — pós-Cloudflare o Nginx reescreve `REMOTE_ADDR` (`real_ip`). Antes, o log podia registrar o IP da CF em vez do visitante.

### 5.3 Bug corrigido (integridade de auditoria)

A versão anterior fazia:

```php
$userId = Session::isAdminLogado() ? getAdminId() : getClienteId();
```

Isto grava `admin_id` **OU** `cliente_id` numa coluna chamada `usuario_id` — chaves de **tabelas diferentes**. `admin_id=3` e `cliente_id=3` viram o mesmo valor, indistinguíveis: trilha de auditoria ambígua.

Agora resolve para `usuarios.id` real, via `AuthHelper::usuarioId()` (o bridge que já existe no projeto), com fallback seguro.

> **Atenção:** registros **antigos** na tabela mantêm a ambiguidade — trate a atribuição de usuário deles como **não-confiável**.

### 5.4 Assinaturas

```php
info(string $msg, array $ctx = [], string $canal = 'app'): void
warning(string $msg, array $ctx = [], string $canal = 'app'): void
error(string $msg, array $ctx = [], string $canal = 'app'): void
audit(string $msg, array $ctx = []): void
debug(string $msg, array $ctx = [], string $canal = 'app'): void
critical(string $msg, array $ctx = [], string $canal = 'app'): void

exception(
    Throwable $e,
    string $nivel = 'error',
    string $canal = 'app',
    array $ctx = []
): void
```

---

## 6. Notificações In-App

Notificações unificadas para clientes e admins, com categorização, modal com filtros, badge no sino, envio manual pelo admin (com imagem e link) e envio em massa via worker de fan-out. **Testado: 28/28 asserções.**

### 6.1 Arquitetura

```
notificacoes           → mensagem (1 linha por envio)
notificacao_usuarios   → destinatário + estado de leitura (1 linha por pessoa)
```

- **Individual/selecionados** → filhas criadas na hora.
- **Broadcast (todos)** → mãe nasce `fanout_status=pendente`; o worker materializa as filhas em batches de 1000 com `INSERT IGNORE` (snapshot do momento — quem se cadastrar depois não recebe).
- **Discriminador** `destinatario_tipo (cliente|admin)` + `destinatario_id` resolve a colisão de IDs entre as tabelas `clientes` e `usuarios`.
- **Polling em 3 velocidades:** modal aberto 10s · aba ativa 30s · background pausado · refresh imediato ao abrir modal / voltar à aba.

### 6.2 Arquivos

```
sql/notificacoes_migration.sql              → rodar no banco
services/NotificacaoService.php             → app/services/
controllers/NotificacaoController.php       → app/controllers/       (modal+badge, cliente E admin)
controllers/NotificacaoAdminController.php  → admin/controllers/     (composição/envio)
cli/notificacao-worker.php                  → cli/
views/admin/index.php                       → views/admin/notificacoes/
js/sino-notificacoes.html                   → incluir no header (layout cliente e admin)
```

### 6.3 Instalação

**1. Banco:**

```bash
mysql -u USER -p BANCO < sql/notificacoes_migration.sql
```

**2. Arquivos:** copie para as pastas indicadas.

**3. Rotas:**

```php
// Comuns (cliente e admin — o controller resolve pela sessão)
Router::get ('/notificacoes/contador',     'NotificacaoController@contador');
Router::get ('/notificacoes/listar',       'NotificacaoController@listar');
Router::post('/notificacoes/marcar-lida',  'NotificacaoController@marcarLida');
Router::post('/notificacoes/marcar-todas', 'NotificacaoController@marcarTodas');

// Admin
AdminRouter::get ('/admin/notificacoes',                       'NotificacaoAdminController@index');
AdminRouter::post('/admin/notificacoes/enviar',                'NotificacaoAdminController@enviar');
AdminRouter::post('/admin/notificacoes/upload-img',            'NotificacaoAdminController@uploadImg');
AdminRouter::get ('/admin/notificacoes/buscar-destinatarios',  'NotificacaoAdminController@buscarDestinatarios');
```

**4. Cron** (`crontab -u www-data -e`):

```cron
* * * * * cd /home/homo-v2.sportmoto.com.br/public_html && /usr/local/lsws/lsphp82/bin/php cli/notificacao-worker.php --verbose >> storage/logs/notificacao-worker.log 2>&1
```

**5. Sino no header:** inclua o conteúdo de `js/sino-notificacoes.html` no layout do cliente e do admin (o mesmo bloco serve os dois). Requer `window.BASE_URL` e `window.CSRF_TOKEN` definidos na página.

### 6.4 Pontos para AJUSTAR ao código

1. **`NotificacaoController::destinatario()`** — confira as chaves de sessão reais (`admin_id`, `cliente_id`).
2. **Fontes do broadcast** em `NotificacaoService::materializarBroadcast()` — a query de admins assume `usuarios.nivel IN ('admin','super')`. Ajuste ao seu esquema de níveis.
3. **`buscarDestinatarios()`** no admin controller — mesma observação.
4. **Permissão** `notificacoes` no `requirePermission()` — cadastre no sistema de permissões ou deixe cair no fallback `requireAdminLevel`.

### 6.5 Como disparar dos gatilhos

```php
// Pedido enviado (individual)
NotificacaoService::criar([
    'categoria' => 'pedido',
    'tipo'      => 'pedido_enviado',
    'titulo'    => "Seu pedido #{$codigo} foi enviado!",
    'mensagem'  => 'Toque para acompanhar o rastreio.',
    'url'       => "/conta/pedidos/{$pedidoId}",
], [['tipo' => 'cliente', 'id' => $clienteId]]);

// Queda de preço (do ProdutoGatilhoService)
NotificacaoService::criar([
    'categoria'  => 'promocao',
    'tipo'       => 'queda_preco',
    'titulo'     => "Baixou! {$produtoNome} está {$descontoPct}% off",
    'url'        => $produtoUrl,
    'imagem_url' => $produtoImg,
], [['tipo' => 'cliente', 'id' => $clienteId]]);

// Novo pedido (avisa todos os admins)
NotificacaoService::criarBroadcast([
    'categoria' => 'pedido',
    'tipo'      => 'novo_pedido_admin',
    'titulo'    => "Novo pedido #{$codigo} — R$ {$total}",
    'url'       => "/admin/pedidos/{$pedidoId}",
], 'todos_admins');
```

### 6.6 Segurança

- Destinatário resolvido **pela sessão** — nunca por parâmetro (um usuário não lê notificações de outro).
- `marcarLida` valida a **posse** do registro antes do UPDATE.
- Upload de imagem: MIME real verificado (`mime_content_type`), máx 2 MB, nome aleatório; **URLs externas são recusadas** no campo imagem.
- CSRF em todos os POSTs; XSS: renderização via `.text()` no jQuery (escape).
- `LogService::audit` em broadcast e envio a selecionados.

### 6.7 Campo reservado

`expira_em` já existe no schema (NULL, sem uso). Quando quiser expiração, basta preencher e adicionar `AND (n.expira_em IS NULL OR n.expira_em > NOW())` na query de listagem — **sem ALTER TABLE**.

---

## 7. Camada de Mídia — Imagens e Vídeos

Camada de mídia via **injeção** de dependência. Dois services **separados**.

### 7.1 Os dois services

| Service | Fluxo | Métodos principais |
|---|---|---|
| `ImageUploadService` | imagem passa **pelo servidor** (síncrono) | `upload`, `uploadUnica`, `delete`, `deleteMany` |
| `StreamService` | vídeo vai **direto do browser** (assíncrono) | `createDirectUpload`, `uidFromPost`, `hlsUrl`, `thumbnailUrl`, `deleteVideo` |

Cada controller injeta **só o que precisa**: módulo de imagem injeta o `ImageUploadService`; módulo de vídeo injeta o `StreamService`; módulo com os dois injeta ambos.

### 7.2 Por que services separados > fachada única

Imagem e vídeo têm fluxos **opostos** (servidor vs browser-direto; síncrono vs assíncrono). Dois services coesos, cada um dono do seu fluxo, é mais limpo que uma fachada que mistura os dois. E você injeta só o necessário — o controller de produto (só imagem) não carrega dependência de vídeo.

### 7.3 Como injetar (padrão do projeto: construtor)

```php
class ClassName extends Controller
{
    private ImageUploadService $img;
    private StreamService $stream;

    public function __construct()
    {
        AuthHelper::requireAdmin();

        $this->img    = ImageUploadService::fromEnv();
        $this->stream = new StreamService(
            env('CF_ACCOUNT_ID'),
            env('CF_STREAM_TOKEN'),
            env('CF_STREAM_CUSTOMER_CODE') ?? ''
        );
    }

    public function salvar(): void
    {
        // imagem (passa pelo servidor)
        $imgUrls   = $this->img->upload($_FILES['imagem'] ?? [], 'banners', ['b' => 1920]);
        $imagemUrl = $imgUrls['b'] ?? null;

        // vídeo (UID já veio do browser via StreamUpload.js)
        $videoUid = $this->stream->uidFromPost('arquivo_video');

        // ... persiste $imagemUrl e $videoUid ...

        // limpeza ao trocar:
        // $this->img->delete($imagemAntiga);
        // $this->stream->deleteIfUid($uidAntigo);
    }
}
```

> As chamadas `env('CF_*')` referenciam **nomes** de variáveis de ambiente. Os valores reais vivem no `.env` — nunca neste documento.

---

## 8. Componente `adminDrawer`

Componente global `adminDrawer(options)` para abrir drawers laterais no painel administrativo. Use sempre que precisar exibir conteúdo lateral **sem redirecionar** o usuário.

### 8.1 Abertura

A abertura deve ser armazenada em uma variável:

```javascript
const drawer = adminDrawer({
    titulo: 'Título',
    subtitulo: 'Subtítulo opcional',
    conteudo: '<p>Conteúdo</p>',
    acoes: '<button>Ação</button>',
    tamanho: 'md'
});
```

### 8.2 Opções disponíveis

- `titulo` — título principal.
- `subtitulo` — texto complementar.
- `conteudo` — HTML, elemento DOM, fragmento ou objeto jQuery.
- `acoes` — conteúdo exibido no cabeçalho.
- `tamanho` — `sm`, `md`, `lg` ou `xl`.
- `classe` — classes CSS adicionais.
- `fecharNoEsc` — permite fechar com Escape.
- `fecharNoOverlay` — permite fechar clicando fora.
- `focoInicial` — seletor, elemento ou função para definir o foco inicial.
- `beforeClose` — função síncrona ou assíncrona que pode impedir o fechamento.
- `onOpen` — callback executado após abrir.
- `onClose` — callback executado após fechar.

### 8.3 Métodos da instância

```javascript
drawer.setTitulo('Novo título');
drawer.setSubtitulo('Novo subtítulo');
drawer.setConteudo('<p>Novo conteúdo</p>');
drawer.setTexto('Texto sem interpretar HTML');
drawer.appendConteudo('<p>Adicionar ao final</p>');
drawer.prependConteudo('<p>Adicionar no início</p>');
drawer.limparConteudo();
drawer.setAcoes('<button>Ação</button>');
drawer.setTamanho('lg');
drawer.setCarregando('Carregando...');
drawer.atualizar({
    titulo: 'Título',
    subtitulo: 'Subtítulo',
    conteudo: '<p>Conteúdo</p>',
    acoes: '',
    tamanho: 'md'
});
```

Acesso ao estado:

```javascript
drawer.corpo();
drawer.elemento();
drawer.sinal();
drawer.estaAberto();
drawer.estaNoTopo();
```

### 8.4 Eventos em conteúdo dinâmico

```javascript
drawer.escutar('click', '.seletor', function (event, drawerAtual) {
    // ação
});
```

### 8.5 Fechamento

```javascript
await drawer.fechar();
await drawer.close();

// informando o motivo:
await drawer.fechar('registro-salvo');

// ignorando o beforeClose:
await drawer.fechar('forcado', { force: true });
```

### 8.6 Métodos globais

```javascript
adminDrawer.fecharTopo();
adminDrawer.fecharTodos();
adminDrawer.fecharTodos({ force: true });
adminDrawer.quantidade();
adminDrawer.topo();
```

O componente suporta múltiplos drawers empilhados, controle de foco, fechamento por Escape ou overlay, bloqueio de scroll e cancelamento de requisições `fetch` através de `drawer.sinal()`.

> **Regra:** utilize apenas os métodos públicos. Não manipule diretamente classes, overlay ou pilha interna do componente.