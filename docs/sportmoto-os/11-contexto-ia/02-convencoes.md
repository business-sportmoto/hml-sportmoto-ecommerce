---
tipo: contexto-ia
status: ativo
atualizado_em: 2026-09-03
---

# Convenções

Como o código deste projeto é escrito, e as armadilhas que já cobraram tempo.
Cada item abaixo nasceu de um erro real — nenhum é preferência estética.

---

## Os dois autoloaders

O projeto tem **duas entradas** com listas de diretórios **diferentes**:

| Entrada | Inclui |
|---|---|
| `index.php` (loja) | `core/`, `app/controllers/`, `app/models/`, `app/helpers/`, `app/services/` + 15 subpastas, `app/presenters/` |
| `admin/index.php` (painel) | `core/`, `app/models/`, `app/helpers/`, `app/services/` + subpastas, **`admin/controllers/`** |

**O painel não carrega `app/controllers/`.** Um controller do admin que instancia
uma classe de `app/controllers/` compila, passa no lint, e morre em runtime com
`Class "X" not found`.

Isso já aconteceu duas vezes:

1. `payment/adquirentes/` e `payment/antifraude/` faltavam no painel — funcionava
   no checkout e no CLI, quebrava ao consultar transação. O comentário da correção
   está no próprio `admin/index.php`.
2. `PageAdminController` chamava `PageController` (que é da loja). Fatal na
   primeira abertura da tela.

**Regra:** lógica compartilhada entre loja e painel mora em **service ou model**,
nunca em controller. Se um controller precisa do que outro controller faz, o que
falta é um service.

**Consequência para teste:** um harness que registre `app/controllers/` no
autoloader aprova código que quebra no navegador. Ver §"Paridade de harness".

---

## Paridade de harness

Todo teste fora do navegador tem de reproduzir o ambiente real **exatamente**.
Duas falhas passaram por harness permissivo:

- O autoloader do harness incluía `app/controllers/` — a tela passou no teste e
  fatalou no navegador.
- O harness definia `window.BASE_URL` explicitamente; a página real define
  `BASE_URL` como global solta. O `window.BASE_URL` do JS era `undefined` em
  produção e não no teste.

**Regra:** copiar a lista de paths do `admin/index.php` literalmente, e testar JS
na página real, não numa página montada para o teste.

---

## Banco e PDO

- **MySQL 8.4 LTS**, não MariaDB. Prepared statements sempre, nunca concatenação.
- **Emulação de prepare está desligada.** O PDO exige **um valor por ocorrência
  de placeholder**. Num `UNION` que repete a mesma condição dos dois lados, cada
  lado precisa de nome próprio (`:pid_a`, `:pid_b`). `array_merge($par, $par)`
  com chave string **sobrescreve em vez de duplicar** e o erro que aparece é
  `SQLSTATE[HY093] Invalid parameter number`. Já aconteceu duas vezes (log do
  Bling e `IAOrchestrator::atualizarEstatisticas()`); a segunda ficou meses
  invisível porque um `catch` engolia. **Antes de subir, rode
  `php tests/lint-pdo-placeholders.php`** — ele varre todo `prepare()` da base
  atrás de placeholder repetido.
- **`UNIQUE` com coluna anulável não protege nada.** No MySQL, NULL é sempre
  distinto de NULL num índice único. Se o UNIQUE inclui coluna que aceita NULL,
  ele deixa passar duplicata. Solução usada em `bi_metas`: coluna gerada não-nula
  (`alvo_chave`). Vale auditar os outros.
- **Colação `PAD SPACE` esconde espaço à direita.** `WHERE chave = 'x'` casa com
  `'x '` gravado no banco, mas o PHP indexa array por chave byte-exata — então a
  linha existe, a query acha, e `ConfigHelper::get('x')` devolve o default.
  Aconteceu com `social_instagram ` (espaço no fim da **chave**), que sumiu do
  site inteiro sem erro nenhum.

---

## Views e JSON

**Nunca use `htmlspecialchars()` dentro de `<script>`.** O escape de HTML produz
`&quot;` e `&amp;`, que dentro de um bloco JS é `SyntaxError: Unexpected token '&'`
— e o erro derruba o **bloco inteiro**, não só a linha. A tela abre vazia sem
mensagem no PHP.

Forma correta de mandar dado do PHP para o JS:

```php
window.MEU_DADO = <?= json_encode($dado,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
```

Os quatro `JSON_HEX_*` são o que impede que o conteúdo feche a tag `<script>`.

---

## Um template só

Quando o servidor pinta a primeira página e o JS pinta as seguintes, **os dois
têm de usar o mesmo template**. Dois templates — um em PHP, outro em JS —
divergem no primeiro ajuste, e a divergência aparece só depois de filtrar.

Padrão adotado (ver [[bling-log-operacoes|log do Bling]]): o servidor manda a
página 1 já resolvida num `window.X.inicial`, e o JS pinta tudo, inclusive a
carga inicial.

---

## Ícones

`IconLibrary::render($key, $class, $attrs)` **não redimensiona**. Passar
`width`/`height` em `$attrs` gera atributo duplicado no SVG, e o primeiro vence —
o ícone sai no tamanho original.

Para mudar tamanho, envolva num elemento com `font-size` (os Material Symbols
respondem a isso) em vez de brigar com os atributos.

---

## Strings e acentos

**Não use `iconv('UTF-8', 'ASCII//TRANSLIT', $s)` para gerar slug.** O resultado
depende da libiconv da plataforma: no Windows `ç` vira `c'`, produzindo
`trocas-e-devoluc-oes`. Use mapa explícito de acentos
(`PaginaService::ACENTOS`).

---

## CSS

- **Regra de componente precisa ser escopada no componente.** Dois sistemas de
  toggle dividiam a classe `.toggle-slider`; as regras de `.toggle-switch`
  estavam soltas com `position:absolute; inset:0`, mesma especificidade e mais
  abaixo no arquivo — então venciam, e o botão de um deles ia parar no canto do
  card. Escopar (`.toggle-switch .toggle-slider`) resolveu.
- **Coluna flex com `overflow:auto` precisa de `min-height: 0`.** Sem isso o item
  se recusa a encolher abaixo do próprio conteúdo: cresce em vez de rolar e
  empurra os irmãos para fora da tela.

---

## Regra aplicada em um caminho só

Se uma validação existe em `salvar()` mas não em `alternarAtivo()`, ela não
existe. Aconteceu com a regra de conteúdo mínimo das páginas: o formulário
barrava página vazia, o toggle de publicar não — e `/contato` foi ao ar com 32
caracteres.

**Regra:** validação de estado vira método (`temConteudo()`), e todo caminho que
muda esse estado chama o método.

---

## Segredo em formulário: ausente ≠ vazio

**O Chrome ignora `autocomplete="off"` em campo `type="password"`.** É
deliberado desde 2014, para não atrapalhar gerenciador de senha. O navegador
preenche com a senha que ele tem guardada **para o domínio** — no painel, a
senha de login do próprio admin.

Em 08/09/2026 isso substituiu as duas credenciais dos Correios
(`codigo_acesso` e `reversa_ws_senha`) pela senha do painel. O formulário
guardava segredo com a regra "campo vazio mantém o que está salvo" — e o valor
autopreenchido **não chega vazio**. Passou pela regra, gravou por cima, e não
houve erro, aviso nem como recuperar o valor anterior.

**A regra certa é ausente = mantém, não vazio = mantém.**

- Segredo já salvo **não vira input**. Vira um chip "Salvo" com um botão
  "Alterar". Sem campo na tela, não há o que autopreencher.
- Ao clicar em Alterar, o nome do campo entra numa lista explícita
  (`config_alterar[]`) que o backend exige para gravar. Segredo que chega sem
  estar declarado é **ignorado**, não gravado.
- O input que nasce é `type="text"` mascarado por CSS
  (`-webkit-text-security: disc`), com botão Mostrar/Ocultar. Não é
  `type="password"` justamente para o gerenciador de senha não se interessar.
  `autocomplete="new-password"`, `data-lpignore`, `data-1p-ignore` ajudam, mas
  são reforço — a garantia é não existir o campo.
- Para apagar de fato, lista própria (`config_remover[]`). Intenção explícita
  nos dois sentidos.

Implementação de referência: `TransportadoraAdminService::mesclarConfig()` e
`camposCredencial()` em `admin/assets/js/logistica.js`.

## Campo que o formulário não repovoa é campo que ele apaga

O mesmo formulário renderizava os campos **de texto** sem `value`. Na edição
eles nasciam vazios, e como texto vazio é gravado normalmente, salvar sem
tocar em nada limpava 18 campos de configuração de uma vez.

Formulário de edição que não carrega o valor atual não é "incompleto": ele é
**destrutivo**. Se o campo entra no POST, ele precisa entrar preenchido.

## Chave interna de adapter não viaja para o navegador

Adapter que cacheia credencial na própria config (o `CorreiosAdapter` grava
`_token` e `_token_exp`, um bearer JWT vivo) cria uma chave que **não está no
catálogo** — então nenhuma redação baseada em "campos secret" a cobre, e ela
saía em texto puro no JSON de `/obter` e `/dados`.

Convenção: prefixo `_` marca chave interna. `TransportadoraManager::ehCampoInterno()`
é o teste; a redação remove essas chaves na leitura e a mesclagem as ignora na
escrita, para o formulário nunca sobrescrever cache do adapter.

## Fim de linha

Os arquivos **variam** entre CRLF e LF, inclusive dentro da mesma pasta. Detecte
antes de editar e preserve — reescrever o arquivo inteiro com o outro EOL produz
um diff de centenas de linhas que esconde a alteração real.

Confira sempre com `git diff --stat` depois de mexer.

---

## Domínio

- Nomes de tabela, coluna e método em **PT-BR**.
- Lógica de domínio em **service**, injetado no construtor. Não usar trait.
- `AuthHelper::usuarioId()` para autoria/auditoria; `Session::get('admin_id')`
  só no domínio de pedidos. Ver `CLAUDE.md` §4.1 — trocar os dois corrompe a
  trilha em silêncio.


## Ícones: IconLibrary, nunca Bootstrap Icons

**O admin não carrega Bootstrap Icons.** Não há `<link>`, não há `@font-face`,
nunca houve. Todo `<i class="bi bi-x"></i>` no projeto é resquício de antes da
migração e **desenha nada** — silenciosamente, que é o pior tipo de falha
visual: nenhum erro no console, só um espaço vazio.

Em 05/09/2026 havia **131** dessas tags no admin. Em 08/09 o admin foi varrido
por completo: **zero** restantes. A varredura separou tres situacoes, e cada uma
pedia coisa diferente —

| situacao | como estava | o que era |
|---|---|---|
| quebrado | `<i class="bi bi-x"></i>` vazio | nao desenhava nada |
| vestigial | `<i class="bi bi-x">` em volta de um `IconLibrary::render()` | desenhava; a classe era lixo |
| morto | mapa `bi-* => rotulo` declarado e nunca lido | 19 chaves sem efeito |

**A LOJA tambem nao carrega Bootstrap Icons.** Os icones da FAQ
(`views/help/`) estavam quebrados para o CLIENTE, nao so no admin — e o banco
ja guardava chave do IconLibrary (`truck`, `card`, `undo`), enquanto a view
renderizava `<i class="bi truck">`. Os que vem de dado foram corrigidos;
sobraram **12 icones de interface** nas duas paginas da FAQ da loja.

**Em PHP:**

```php
<?= IconLibrary::render('package', 'icon icon--sm') ?>
```

**Em JavaScript** — que não pode chamar o helper — o layout publica um
subconjunto do catálogo:

```js
window.icone('package', 'icon icon--sm')   // devolve o SVG, ou '' se faltar
```

A lista de chaves publicadas fica em `admin/views/layouts/admin.php`, e é
**explícita de propósito**: sem filtro, `IconLibrary::paraJs()` serializa os 155
ícones do catálogo em toda página do admin. Ícone novo usado por script precisa
entrar naquela lista.

> **As chaves são as de `assets/icons.json`, e não dá para adivinhá-las pelo
> nome do Bootstrap.** `bi-hand-index` e `bi-person-badge` parecem virar
> `hand-index` e `person-badge` — nenhum dos dois existe. Conferir contra o
> arquivo antes de usar; ícone ausente sai como string vazia e o defeito volta
> a ser invisível.
