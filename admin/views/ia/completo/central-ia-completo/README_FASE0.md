# Central de Marketing IA — Fase 0 (Fundação)

Pacote de instalação para `hml.sportmoto.com.br`. Esta fase entrega o alicerce do módulo:
schema completo (13 tabelas `ia_`), cifragem de chaves, e a tela de Configurações
(provedores, catálogo de modelos e limites de uso). O orquestrador, o worker e a tela
de produto chegam na Fase 1.

---

## 1. Conteúdo do pacote

```
central-ia-fase0/
├── sql/
│   └── 2026-07-02_ia_fase0.sql          # 13 tabelas + seeds (provedores, modelos, tipos, ângulos, layouts, limite global)
├── app/
│   ├── services/ia/
│   │   ├── IACriptoService.php          # AES-256-GCM para chaves de API
│   │   └── providers/                   # vazio nesta fase — adapters entram na Fase 1
│   ├── models/
│   │   ├── IAProvedor.php
│   │   ├── IAModelo.php
│   │   ├── IALimite.php
│   │   └── IACustoDiario.php
│   ├── controllers/
│   │   └── IAConfigController.php
│   └── views/ia/config/
│       ├── index.php                    # página + CSS (.ia_) + JS
│       ├── _provedores_rows.php         # partials devolvidos pelos endpoints de refresh
│       ├── _modelos_rows.php
│       ├── _limites_rows.php
│       ├── provedor_form.php            # formulários do drawer
│       ├── modelo_form.php
│       └── limite_form.php
└── README_FASE0.md
```

---

## 2. Instalação

### 2.1 Banco

```bash
mysql -u USUARIO -p NOME_DO_BANCO < sql/2026-07-02_ia_fase0.sql
```

A migration tem FKs para `produtos`, `produto_imagens` e `usuarios` (todas `INT UNSIGNED`,
como no schema atual). Rodar no banco da homologação onde essas tabelas existem.

### 2.2 Autoload (crítico — lição do módulo de pagamento)

Adicionar os dois diretórios novos ao array do `spl_autoload_register`:

```php
$diretorios = [
    'core',
    'app/controllers',
    'app/models',
    'app/helpers',
    'app/services',
    'app/services/email',
    'app/services/email/providers',
    'app/services/ia',            // <- NOVO
    'app/services/ia/providers',  // <- NOVO (usado a partir da Fase 1, registrar já)
];
```

### 2.3 Chave mestra de cifragem

Gerar (uma única vez) e adicionar ao `config.php`:

```bash
/usr/local/lsws/lsphp82/bin/php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

```php
define('IA_CRYPTO_KEY', 'COLE_AQUI_OS_64_CARACTERES_HEX');
```

Regras (lembrando o incidente do `.env` exposto no staging):
- nunca versionar essa constante;
- conferir que `config.php` não é servível via web;
- se a chave vazar, trocar a `IA_CRYPTO_KEY` invalida TODAS as chaves gravadas —
  será preciso recadastrá-las na tela (comportamento intencional).

### 2.4 Rotas

Registrar no router (formato do seu core):

| Método | URL                                      | Controller@ação                      |
|--------|------------------------------------------|--------------------------------------|
| GET    | `/admin/ia/config`                       | `IAConfigController@index`           |
| GET    | `/admin/ia/config/provedores/linhas`     | `IAConfigController@provedoresLinhas`|
| GET    | `/admin/ia/config/provedor/form`         | `IAConfigController@provedorForm`    |
| POST   | `/admin/ia/config/provedor/salvar`       | `IAConfigController@provedorSalvar`  |
| POST   | `/admin/ia/config/provedor/alternar`     | `IAConfigController@provedorAlternar`|
| GET    | `/admin/ia/config/modelos/linhas`        | `IAConfigController@modelosLinhas`   |
| GET    | `/admin/ia/config/modelo/form`           | `IAConfigController@modeloForm`      |
| POST   | `/admin/ia/config/modelo/salvar`         | `IAConfigController@modeloSalvar`    |
| POST   | `/admin/ia/config/modelo/alternar`       | `IAConfigController@modeloAlternar`  |
| POST   | `/admin/ia/config/modelo/excluir`        | `IAConfigController@modeloExcluir`   |
| GET    | `/admin/ia/config/limites/linhas`        | `IAConfigController@limitesLinhas`   |
| GET    | `/admin/ia/config/limite/form`           | `IAConfigController@limiteForm`      |
| POST   | `/admin/ia/config/limite/salvar`         | `IAConfigController@limiteSalvar`    |
| POST   | `/admin/ia/config/limite/excluir`        | `IAConfigController@limiteExcluir`   |

Se preferir outras URLs, ajustar também o objeto `URLS` no `<script>` de
`app/views/ia/config/index.php` e o `data-acao` dos 3 formulários.

### 2.5 Permissões e menu

- Registrar as chaves de permissão no catálogo do admin: `marketing_ia` (acesso ao módulo),
  `marketing_ia_config` (esta tela), `marketing_ia_aprovar` (curadoria — usada da Fase 1 em diante).
  O cascade do `AuthHelper` já libera admins plenos.
- Adicionar item de menu apontando para `/admin/ia/config`
  (sugestão de ícone: `bi-stars`).

---

## 3. Pontos marcados com `// AJUSTE:` no código

Três pontos dependem de detalhes do core que podem variar — todos comentados no código:

1. **`IAConfigController::tokenCsrf()`** — lê `$_SESSION['csrf_token']` (fallback `csrf`).
   Se o Controller base tiver helper próprio, usar ele.
2. **`IALimite::listar()`** — junta `usuarios u` e exibe `u.nome`.
   Se a coluna de nome for outra, ajustar ali.
3. **`drawerAbrir()` no JS do index** — chama `window.adminDrawer(titulo, html)` quando existir;
   se a assinatura do seu `adminDrawer` for diferente, adaptar essa chamada (e `drawerFechar()`,
   que tenta `window.adminDrawerClose`). Sem o helper do projeto, o fallback interno
   (drawer com blur, Esc, clique no véu) assume — a tela funciona de qualquer forma.

---

## 4. Seeds e preços (capturados em 02/07/2026)

Preços mudam — por isso moram em `ia_modelos.custo_config` (editável na tela), nunca em código.
Estado do mercado na data da captura:

- **OpenAI texto**: família GPT-5.4 substituiu GPT-5/GPT-5-mini na tabela oficial.
  Seeds: `gpt-5.4-mini` (US$ 0,75/4,50 · 1M tokens, prioridade 10), `gpt-5.4` (2,50/15, prio 20),
  `gpt-5.4-nano` (0,20/1,25, prio 30).
- **OpenAI imagem**: `gpt-image-1.5` (≈ US$ 0,04 medium 1024) para geração;
  `gpt-image-2` (≈ US$ 0,053 medium) para **edição** por instrução.
  `gpt-image-1` ficou FORA do seed — depreciação anunciada para 23/10/2026. DALL·E saiu da API.
- **Replicate imagem**: `flux-2-dev` (≈ US$ 0,012/MP, prio 10 — melhor custo),
  `flux-2-pro` (≈ 0,03, prio 30), `flux-schnell` (0,003, prio 40 — rascunho),
  `flux-kontext-pro` (edição, prio 20).
- **Remoção de fundo**: `bria/remove-background` (RMBG 2.0, ≈ US$ 0,018/execução, prio 10) —
  treinado para e-commerce e **licenciado para uso comercial via API** (os pesos abertos do
  RMBG são não-comerciais; via Replicate/API está coberto). Fallback: `851-labs/background-remover`.
- **Upscale**: `nightmareai/real-esrgan` (estimativa ~US$ 0,01 por tempo de hardware).

Limite global seedado: **US$ 5/dia · US$ 60/mês · 6 gerações/min · alerta a 70%** —
calibrado para 10–50 gerações/dia. Editável na tela.

Também são seedados os **11 tipos de conteúdo** da Fase 1 (com system prompts de formato e
regra anti-invenção), os **14 ângulos criativos** e os **7 layouts** de formato do compositor.

---

## 5. Segurança implementada nesta fase

- Chaves de API cifradas em repouso (AES-256-GCM, IV aleatório por registro, tag autenticada).
- Chave nunca retorna em AJAX/HTML; UI mostra apenas os últimos 4 caracteres; campo é write-only.
- `LogService::audit` em toda mutação (provedor, chave — só last4 —, modelo, limite), sempre com `array $ctx`.
- CSRF verificado em todo POST; endpoints mutáveis recusam métodos não-POST.
- Allowlist de campos em todo INSERT/UPDATE; validação de entrada no controller
  (URL https obrigatório, ranges numéricos, JSON validado e normalizado).
- Provedor não pode ser ativado sem chave configurada.
- Modelo com histórico de uso não pode ser excluído (apenas desativado); limite global não pode ser excluído.
- Escape de saída (`htmlspecialchars`) em todas as views.

---

## 6. O que NÃO está nesta fase (vem na Fase 1)

`IAOrchestrator`, adapters OpenAI/Replicate (em `app/services/ia/providers/`), `ia-worker.php`
(cron 1 min + loop interno ~55s + flock), montador de prompt com dados de
`produtos`/`caracteristicas`/`compatibilidade`, tela de geração no produto, histórico,
polling jQuery e botão "testar conexão" do provedor (depende dos adapters).

## 7. Pendências em aberto

- **Chave do resumo de avaliações**: `produto_review_summary.tokens_usados` indica uma
  integração LLM já ativa. Localizar onde essa chave vive hoje (hardcoded? `configuracoes`?)
  e migrá-la para `ia_provedores` na Fase 1, para o consumo entrar no controle de custo.
- **Imagick (Fase 2, em standby)**: quando for mexer no servidor —
  `/usr/local/lsws/lsphp82/bin/php -m | grep -i imagick`; ausente →
  `apt install lsphp82-imagick && systemctl restart lsws`.

## 8. Verificação pós-instalação

1. Abrir `/admin/ia/config` — KPIs devem mostrar 0/2 provedores ativos, 12/12 modelos, limite US$ 5,00.
2. Editar OpenAI → colar a chave → salvar → badge da chave vira `•••• xxxx` → ativar provedor.
3. Conferir na tabela de auditoria os eventos `ia_provedor_chave_alterada` / `ia_provedor_status`.
4. Criar um modelo de teste, desativar, excluir — deve funcionar; tentar excluir com uso retorna bloqueio.
