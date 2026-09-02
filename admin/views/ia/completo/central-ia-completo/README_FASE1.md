# Central de Marketing IA — Fase 1 (Texto: orquestrador, worker e telas)

Pacote de instalação para `homo-v2.sportmoto.com.br`. Pré-requisito: **Fase 0 aplicada**
(migration das 13 tabelas `ia_`, `IA_CRYPTO_KEY` no config, autoload registrado).

A Fase 1 entrega o ciclo completo de TEXTO: enfileirar na tela do produto → worker
processa via orquestrador com fallback → polling mostra o resultado em 2–4s percebidos →
curadoria (aprovar/reprovar/arquivar) e refazer no histórico.

---

## 1. Conteúdo do pacote

```
central-ia-fase1/
├── ia-worker.php                              # worker CLI (cron 1min, loop interno ~55s, flock)
├── app/
│   ├── services/ia/
│   │   ├── IAResultado.php                    # DTO único de retorno
│   │   ├── IAOrchestrator.php                 # roteamento por capacidade + fallback + stats
│   │   ├── IAPromptBuilder.php                # contexto real do produto + prompt final
│   │   ├── IACustoService.php                 # estimativa, custo real, limites, rollup
│   │   ├── IAGeracaoService.php               # enfileirar, concluir, falhar, refazer
│   │   └── providers/
│   │       ├── IAProviderBase.php             # cURL endurecido (lições Malga/Vindi)
│   │       ├── OpenAIAdapter.php              # /chat/completions + testar conexão
│   │       └── ReplicateAdapter.php           # testar conexão (geração na Fase 2)
│   ├── models/
│   │   ├── IAGeracao.php                      # fila + histórico + claim do worker + watchdog
│   │   ├── IATipoConteudo.php
│   │   └── IAPromptTemplate.php
│   ├── controllers/
│   │   ├── IAGeracaoController.php            # NOVO — gerar/histórico (11 rotas)
│   │   └── IAConfigController.php             # SUBSTITUI o da Fase 0 (+ provedorTestar)
│   └── views/ia/
│       ├── _estilos.php                       # CSS .ia_ compartilhado (gerar/histórico)
│       ├── gerar/index.php                    # tela de geração (busca, briefing, polling)
│       ├── gerar/_produto_painel.php
│       ├── historico/index.php                # KPIs, filtros, drawer de detalhe
│       ├── historico/_linhas.php
│       ├── historico/_detalhe.php
│       └── config/
│           ├── index.php                      # SUBSTITUI (botão testar + spinner + URL)
│           └── _provedores_rows.php           # SUBSTITUI (botão bi-lightning-charge)
└── README_FASE1.md
```

Os três arquivos marcados como **SUBSTITUI** são os da Fase 0 com o patch do
"testar conexão" — pode sobrescrever direto.

## 2. Instalação

### 2.1 Arquivos
Copiar tudo respeitando os caminhos. O `ia-worker.php` vai na raiz do `public_html`
(ao lado do `email-worker.php`).

### 2.2 Storage das respostas brutas
Cada geração concluída grava o JSON bruto do provedor (auditoria) em
`IA_STORAGE_PATH/respostas/AAAA/MM/{uuid}.json` e indexa em `ia_arquivos`.

```php
define('IA_STORAGE_PATH', '/home/homo-v2.sportmoto.com.br/public_html/storage/ia');
```

Atenção (lição do incidente do `.env`): `storage/` fica dentro do public root —
garanta a regra de negar acesso web ao diretório no LiteSpeed/CyberPanel, como já
deve existir para `storage/logs`.

### 2.3 Rotas

| Método | URL                                   | Controller@ação                        |
|--------|----------------------------------------|----------------------------------------|
| GET    | `/admin/ia/gerar`                      | `IAGeracaoController@gerar`            |
| GET    | `/admin/ia/gerar/produto-busca`        | `IAGeracaoController@produtoBusca`     |
| GET    | `/admin/ia/gerar/produto-painel`       | `IAGeracaoController@produtoPainel`    |
| POST   | `/admin/ia/gerar/preview`              | `IAGeracaoController@preview`          |
| POST   | `/admin/ia/gerar/enfileirar`           | `IAGeracaoController@enfileirar`       |
| GET    | `/admin/ia/gerar/status`               | `IAGeracaoController@status`           |
| GET    | `/admin/ia/historico`                  | `IAGeracaoController@historico`        |
| GET    | `/admin/ia/historico/linhas`           | `IAGeracaoController@historicoLinhas`  |
| GET    | `/admin/ia/historico/detalhe`          | `IAGeracaoController@historicoDetalhe` |
| POST   | `/admin/ia/historico/aprovacao`        | `IAGeracaoController@aprovacao`        |
| POST   | `/admin/ia/historico/refazer`          | `IAGeracaoController@refazer`          |
| POST   | `/admin/ia/config/provedor/testar`     | `IAConfigController@provedorTestar`    |

### 2.4 Permissões e menu
Já registradas na Fase 0: `marketing_ia` (gerar/histórico), `marketing_ia_aprovar`
(botões de curadoria), `marketing_ia_config`. Menu sugerido: "Marketing IA" →
Gerar (`/admin/ia/gerar`), Histórico (`/admin/ia/historico`), Configurações.

### 2.5 Cron (crontab do www-data, padrão dos demais workers)

```
* * * * * /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/ia-worker.php --loop=55 --verbose >> /home/homo-v2.sportmoto.com.br/public_html/storage/logs/ia-worker.log 2>&1
```

Flock interno em `storage/locks/ia-worker.lock` — sem flock externo.

### 2.6 Primeiro uso
1. `/admin/ia/config` → colar a chave da OpenAI → botão ⚡ **Testar** → ativar o provedor.
2. `/admin/ia/gerar` → buscar um produto → escolher tipo/ângulo → Gerar.
3. Acompanhar o card (polling 2,5s) e aprovar/reprovar/refazer.

## 3. Como funciona (resumo do desenho)

Enfileirar valida tipo/produto, monta o contexto real (marca, categoria principal,
características, compatibilidades "HONDA CG 160 (2016–2023)", promo vigente por DATE,
média de avaliações aprovadas + resumo do `produto_review_summary`), congela tudo como
snapshot JSON em `ia_geracoes.contexto`, estima o custo pelo modelo primário e **barra
antes de gastar** (taxa/min → teto do usuário → teto global). O worker reivindica o lote,
o orquestrador percorre os modelos da capacidade por prioridade (pulando provedor que
estourou teto próprio), loga cada tentativa em `ia_roteamento_log`, atualiza média móvel
80/20 e devolve custo real por tokens. Dedup por hash impede clique duplo; watchdog
devolve à fila jobs presos (falha definitiva após 3 tentativas).

## 4. Pontos marcados com `// AJUSTE:`

1. **`IAGeracaoController::usuarioAtualId()`** — lê `$_SESSION['usuario_id']`
   (fallback `user_id`). Alinhar com o AuthHelper.
2. **`tokenCsrf()`** (mesmo padrão da Fase 0, nos dois controllers).
3. **Bootstrap do `ia-worker.php`** — espelhar o cabeçalho de includes do
   `email-worker.php` (cadeia defines → config → database → autoload).
4. **`drawerAbrir()` no histórico** — usa `window.adminDrawer(titulo, html)` se existir;
   fallback interno funciona sozinho.
5. Opcional: thumb real do produto no painel (hoje mostra ícone; o caminho público das
   imagens não estava no dump).

## 5. Validação executada (neste pacote)

- `php -l` limpo em todos os arquivos.
- **Harness de integração com 52 asserções, todas verdes**, rodando contra o
  `homo_ecommerce_db.sql` real (136 tabelas) + migration Fase 0 + produto de amostra
  completo: contexto do builder coluna a coluna, prompt montado, placeholders,
  cifragem da chave + last4, estimativa de custo, enfileiramento, dedup no mesmo
  minuto, variações ×3, bloqueio por teto diário e por taxa/minuto (sem inserir),
  claim do worker, **fallback real do orquestrador** (1º modelo falha → 2º conclui,
  roteamento logado, stats móveis), conclusão com custo real US$ 0,005275 + rollup +
  resposta bruta em storage + `ia_arquivos`, falha com rollup, polling em lote,
  refazer vinculado à origem, KPIs, filtros e watchdog.
- Dois bugs reais capturados e corrigidos pelos testes:
  `LIMIT :n` com bind quebra sob `ATTR_EMULATE_PREPARES` (agora int interpolado e
  comentado) e colisão de dedup em refazer no mesmo minuto (origem agora entra no hash).

## 6. Pendências

- **Chave do `produto_review_summary`**: confirmado no dump que **não está no banco**
  (único `api_key` é do `pgto_gateways`) — está hardcoded em algum service PHP.
  Localizar o arquivo e migrar para `ia_provedores` (aí o consumo entra no rollup).
- **Imagick** (Fase 2, standby): `/usr/local/lsws/lsphp82/bin/php -m | grep -i imagick`.

## 7. Próxima fase

Fase 2 — imagem: predictions no Replicate com webhook + download imediato (URLs expiram),
`gerarImagem`/remoção de fundo no adapter, cache de recortes em `ia_recortes_produto`,
compositor Imagick com os 7 layouts, biblioteca visual e publicar como banner do site.
