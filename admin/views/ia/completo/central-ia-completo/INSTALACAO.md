# Central de Marketing IA — Pacote CONSOLIDADO (Fases 0 + 1)

Este zip substitui os dois anteriores (`central-ia-fase0.zip` e `central-ia-fase1.zip`).
Tem TUDO: migration, os 7 models, os 6 services, os 2 adapters, os 2 controllers
(já com o patch do testar conexão), as 13 views, o worker, o `routes.ia.php` e os
READMEs detalhados das duas fases.

Os arquivos que faltaram na sua instalação (`IAProvedor.php`, `IAModelo.php`,
`IALimite.php`, `IACustoDiario.php`, `IACriptoService.php`, as views de formulário
e a migration) estavam no zip da Fase 0 — agora está tudo junto.

---

## Mapa de instalação (checklist na SUA árvore)

A coluna "Destino" já reflete a estrutura real do projeto
(`admin/` com controllers e rotas próprios, como no módulo de notificações).

| # | Arquivo(s) no pacote                                   | Destino no projeto                              |
|---|--------------------------------------------------------|-------------------------------------------------|
| 1 | `sql/2026-07-02_ia_fase0.sql`, depois `2026-07-15_ia_fase2a.sql`, `2026-07-16_ia_fase2b.sql`, `2026-07-16_ia_gemini_seo.sql`, `2026-07-20_ia_fase2c.sql` e `2026-07-20_ia_fase3a.sql` | rodar no banco (`mysql -u USER -p BANCO < ...`) |
| 2 | `app/models/*.php` (7 arquivos)                        | `app/models/`                                   |
| 3 | `app/services/ia/*.php` (6 arquivos)                   | `app/services/ia/`                              |
| 4 | `app/services/ia/providers/*.php` (3 arquivos)         | `app/services/ia/providers/`                    |
| 5 | `app/controllers/IAConfigController.php`               | `admin/controllers/` (como você já fez)         |
| 6 | `app/controllers/IAGeracaoController.php`              | `admin/controllers/`                            |
| 7 | `app/views/ia/**` (13 arquivos, com subpastas)         | ver "Views" abaixo                              |
| 8 | `ia-worker.php`                                        | raiz do `public_html` (ao lado do email-worker) |
| 9 | `routes.ia.php`                                        | `admin/config/` (o require já existe)           |

### Models (passo 2) — os que faltaram estão aqui
`IAProvedor.php` · `IAModelo.php` · `IALimite.php` · `IACustoDiario.php` (Fase 0)
`IAGeracao.php` · `IATipoConteudo.php` · `IAPromptTemplate.php` (Fase 1)

### Services (passos 3 e 4)
`IACriptoService.php` (Fase 0 — o próximo "Class not found" seria ele)
`IAResultado.php` · `IAOrchestrator.php` · `IAPromptBuilder.php` ·
`IACustoService.php` · `IAGeracaoService.php` · providers: `IAProviderBase.php`,
`OpenAIAdapter.php`, `ReplicateAdapter.php`

### Views (passo 7)
Os controllers agora procuram as views em DOIS lugares, nesta ordem:
`admin/views/ia/` (ao lado do controller) e depois `app/views/ia/`.
Escolha UM destino e copie a árvore inteira de `app/views/ia/` para lá:

```
ia/
├── _estilos.php
├── config/  (index, _provedores_rows, _modelos_rows, _limites_rows,
│             provedor_form, modelo_form, limite_form)
├── gerar/   (index, _produto_painel)
└── historico/ (index, _linhas, _detalhe)
```

Atenção ao `render()`: os controllers chamam `$this->render('ia/gerar/index', ..., 'admin')`
— o caminho `ia/...` precisa existir sob a base de views que o SEU Controller core
usa para o layout admin. Se o core do admin lê de `admin/views/`, coloque lá
(recomendado); se lê de `app/views/`, coloque lá. O `partial()` acha em qualquer um.

---

## Autoloader — a causa raiz do seu Fatal error

O `admin/index.php` carrega um autoloader. Garanta que a lista de diretórios dele
inclui (com caminho absoluto via `__DIR__`, não relativo):

```php
// exemplos de entradas — adapte ao formato do seu array
__DIR__ . '/../app/models',
__DIR__ . '/../app/services',
__DIR__ . '/../app/services/ia',            // NOVO
__DIR__ . '/../app/services/ia/providers',  // NOVO
```

Se a loja e o admin têm autoloaders SEPARADOS, registre os dois diretórios novos
NOS DOIS — foi exatamente essa assimetria que produziu o
`Class "IAProvedor" not found` (o admin achou o controller em `admin/controllers`,
mas não sabia procurar models/services em `app/`).

Cuidado extra no Laragon/Windows: caminhos relativos tipo `'app/models'` resolvidos
contra o diretório de trabalho quebram quando o script roda de `admin/` — use
sempre `__DIR__`.

---

## Config, permissões e cron (resumo — detalhes nos READMEs)

1. `IA_CRYPTO_KEY` no config (64 hex):
   `/usr/local/lsws/lsphp82/bin/php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
   No Laragon: `php -r "echo bin2hex(random_bytes(32));"`
2. `IA_STORAGE_PATH` no config (ex.: `.../public_html/storage/ia`) — negar acesso web.
2b. `IA_PRODUTO_IMG_BASE` no config (Fase 2B): base pública de produto_imagens.arquivo.
3. Permissões: `marketing_ia`, `marketing_ia_config`, `marketing_ia_aprovar`.
4. Cron (produção):
   `* * * * * /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/ia-worker.php --loop=55 --verbose >> storage/logs/ia-worker.log 2>&1`
   No Laragon, para testar sem cron: `php ia-worker.php --loop=10 --verbose`.
5. Menu: Gerar (`/admin/ia/gerar`) · Histórico (`/admin/ia/historico`) · Config (`/admin/ia/config`).

## Verificação rápida

1. `/admin/ia/config` abre com KPIs (0/2 provedores ativos, 12 modelos, limite US$ 5) —
   se abriu, autoloader e models OK.
2. Colar chave OpenAI → ⚡ Testar → ativar.
3. `/admin/ia/gerar` → buscar produto → Gerar → card conclui em segundos
   (worker rodando).

Os READMEs das fases (inclusos) têm o restante: pontos `// AJUSTE:`, seeds e
preços com data de captura, segurança, e o desenho completo de cada camada.
