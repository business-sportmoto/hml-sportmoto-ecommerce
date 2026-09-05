# Tela de regras — Dicas de cuidado

Interface de administração para `categoria_vida_util`. Fecha o ciclo do
subsistema de vida útil: as regras deixam de viver em SQL.

Testado: **49/49 asserções** no controller (listagem, validação, escrita,
proteções, CSRF).

## A tela

**O funil no topo** — `Agendadas → Enviadas → Cliques`, com a taxa de clique
em destaque. Escolhi mostrar como percurso e não como quatro cartões soltos
porque o subsistema *é* uma sequência, e porque a taxa de clique é o número
que valida a tese inteira: a dica não vende, ela só oferece; quem clica é quem
tem interesse. Se essa taxa for boa, a abordagem está funcionando.

**A tabela** — ordenada por prazo crescente, então lê como uma cadência: óleo
(6m), pastilhas (8m), pneus (12m), relação (18m), capacete (36m). Cada linha
mostra os números daquela categoria e a dica já interpolada, para você ver o
texto como ele sai. Regra pausada fica esmaecida com um selo.

**O preview ao vivo** — no drawer de edição, enquanto você escreve, aparece a
notificação montada exatamente como o cliente vê: ícone, título, mensagem,
com `{{produto_nome}}` e `{{moto_apelido}}` já substituídos por valores de
exemplo. É o único lugar onde gastei ousadia visual, e não é enfeite: o
subsistema inteiro depende do tom ser de *cuidado* e não de *venda*, e essa
diferença é fácil de perder escrevendo num campo de texto vazio. Ver o
resultado enquanto escreve mantém o tom honesto.

O drawer também traduz o prazo em data concreta enquanto você digita —
*"Entrega hoje, dica em 18/07/2027 (12 meses depois)"*.

## Decisões de comportamento

**A categoria não muda na edição.** Os agendamentos existentes apontam para
ela; trocar deixaria histórico órfão. O campo aparece desabilitado com a
explicação, e o backend ignora a tentativa mesmo que alguém force o POST
(está sob teste).

**Excluir é bloqueado quando há histórico.** Mesma proteção do seu
`PedidoStatus::excluir()`: se existem dicas ligadas à regra, a resposta diz
quantas e sugere pausar. Pausar interrompe agendamentos novos e deixa os
existentes valendo — o aviso do toast diz isso.

**Uma regra por categoria**, garantido pela UNIQUE do schema e por uma
checagem amigável antes do INSERT (em vez de deixar estourar o erro de banco).
O select de criação só oferece categorias que ainda não têm regra.

## Componentes do painel usados

`adminDrawer` para o formulário lateral (sem sair da listagem), `Toast` para
retorno — inclusive na confirmação de exclusão, que usa `Toast.show` com
`actions` e `duration: 0` em vez de um `confirm()` nativo. jQuery puro,
Bootstrap Icons, sem emoji. Conteúdo de usuário renderizado com `.text()`.

Visual no sistema do painel: vars `--em-*`, cantos de 16px, botões pill,
`tabular-nums` nos números, dark mode automático. Prefixo `.vu_`, mesma
convenção do módulo de IA.

## Arquivos

```
admin/controllers/VidaUtilAdminController.php  → admin/controllers/
views/admin/vida-util/index.php                → views/admin/vida-util/
css/vida-util-admin.css                        → public/css/
js/vida-util-admin.js                          → public/js/
```

## Instalação

**1. Arquivos:** copie para as pastas indicadas.

**2. Rotas** em `admin/config/routes.php`:

```php
// ── Dicas de cuidado (vida útil) ──────────────────────────────────
AdminRouter::get ('/vida-util',          'VidaUtilAdminController@index');
AdminRouter::get ('/vida-util/listar',   'VidaUtilAdminController@listar');
AdminRouter::post('/vida-util/salvar',   'VidaUtilAdminController@salvar');
AdminRouter::post('/vida-util/pausar',   'VidaUtilAdminController@pausar');
AdminRouter::post('/vida-util/excluir',  'VidaUtilAdminController@excluir');
```

**3. Menu:** adicione o link para `/admin/vida-util` onde fizer sentido no seu
menu lateral.

**4. Permissão:** cadastre `vida_util` no seu sistema de permissões, ou deixe
cair no fallback `requireAdminLevel` (a cascata já está no construtor).

## Pontos para conferir

**A tabela de categorias.** As queries assumem `categorias (id, nome)` —
mesma suposição da migration da vida útil, que você não corrigiu, então
provavelmente está certa. Se o nome da coluna for outro, são dois lugares no
`montarDados()`.

**O caminho da view.** Usei `$this->render('admin/vida-util/index', ...)` com
o arquivo em `views/admin/vida-util/index.php` — mesmo padrão do editor de
fluxos que já está rodando aí. Se o seu admin resolve views por outra raiz,
ajuste a string.

## O que falta no ciclo

O **frete-quase-grátis**, que continua parado esperando uma informação: onde o
`cardFreteGratis()` do `PromocaoService` é consumido — endpoint AJAX do
`CartController` ou montado na view do carrinho. É lá que emito o evento
`carrinho_quase_frete_gratis`, reaproveitando o cálculo que já respeita escopo
de promoção em vez de reimplementar no worker.
