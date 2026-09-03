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
  `SQLSTATE[HY093] Invalid parameter number`.
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
