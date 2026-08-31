# Integração Correios + Melhorias de Frete — Guia Completo

Consolidado de tudo o que foi implementado nesta sessão, no módulo de logística do
SportMoto (PHP 8.2 MVC custom, MariaDB/PDO, jQuery). Serve como checklist de
instalação e referência técnica.

> **Convenções do projeto respeitadas:** sem Composer/namespaces; `Controller extends
> Controller`; permissão em cascata no construtor (`AuthHelper::requirePermission('logistica')`);
> CSRF em todo POST (`$this->verifyCsrf()`); respostas via `$this->json()`; PT-BR e
> sem emoji na UI; rotas literais declaradas **antes** das rotas com `{id}`.

---

## 1. Checklist de instalação (faça nesta ordem)

### 1.1. Migrações SQL (rodar uma vez)

```sql
-- (A) Recibo do rótulo assíncrono (para baixar o PDF em 2 etapas)
-- arquivo: sql/etiqueta_recibo_migration.sql
ALTER TABLE `log_etiquetas`
  ADD COLUMN `id_recibo` VARCHAR(80) NULL
  COMMENT 'Recibo do rótulo assíncrono (Correios) para baixar o PDF depois'
  AFTER `url_pdf`;

-- (B) Preço/dados reais da postagem (buscados via cron após a postagem)
-- arquivo: sql/etiqueta_postagem_migration.sql
ALTER TABLE `log_etiquetas`
  ADD COLUMN `valor_postado`   DECIMAL(10,2) NULL COMMENT 'Valor real cobrado na postagem (valorAtendimento)' AFTER `valor`,
  ADD COLUMN `data_postagem`   DATETIME      NULL COMMENT 'Data/hora da postagem real (Correios)'            AFTER `valor_postado`,
  ADD COLUMN `peso_tarifado_g` INT UNSIGNED  NULL COMMENT 'Peso tarifado na postagem, em gramas'              AFTER `data_postagem`;
```

### 1.2. Rotas a registrar no AdminRouter

Todas literais, **antes** de qualquer rota `{id}`:

```php
// Etiquetas — busca de CEP/cliente no formulário de nova etiqueta
AdminRouter::get ('/admin/logistica/etiquetas/buscar-cep',     'EtiquetaController@buscarCep');
AdminRouter::get ('/admin/logistica/etiquetas/buscar-cliente', 'EtiquetaController@buscarCliente');

// Etiquetas — stream do PDF do rótulo (abre inline no navegador, sem baixar)
AdminRouter::get ('/admin/logistica/etiquetas/rotulo',         'EtiquetaController@rotulo');

// Etiquetas — AR Eletrônico (Aviso de Recebimento)
AdminRouter::post('/admin/logistica/etiquetas/ar',             'EtiquetaController@ar');

// Transportadoras — limpar cache de frete manualmente
AdminRouter::post('/admin/logistica/transportadoras/limpar-cache', 'TransportadoraController@limparCache');
```

### 1.3. Egress / allowlist do servidor (VPS)

Liberar os hosts dos Correios no firewall/egress:
- `api.correios.com.br` — token, cotação, prazo, prepostagem, rótulo, SRO, postada.
- `apps.correios.com.br` — **AR Eletrônico** (host diferente do resto).
- `viacep.com.br` — busca de CEP no formulário de etiqueta.

### 1.4. Configuração da transportadora Correios (no painel)

Ative a transportadora **Correios** e preencha:

| Campo | Descrição |
|---|---|
| `usuario` | Usuário CWS (CNPJ, só dígitos) |
| `codigo_acesso` | Código de acesso CWS (segredo) |
| `cartao_postagem` | Cartão de postagem |
| `contrato` | Número do contrato |
| `dr` | DR / unidade gestora (número, ex.: `64`) |
| `tp_objeto` | Tipo de objeto: `1`=envelope, `2`=caixa, `3`=rolo |
| **CEP de origem** | (campo da transportadora) — obrigatório para cotar |
| **Serviços** | Cadastre PAC/SEDEX… — o `codigo` de cada um é o `coProduto` |

Para **reversa** e **remetente da etiqueta de ida**, preencha o bloco `reversa_loja_*`
(nome, documento, logradouro, número, bairro, cidade, uf, cep, telefone, email). O
CNPJ da loja cai em `ConfigHelper::get('site_cnpj')` se `reversa_loja_documento`
estiver vazio.

---

## 2. Cotação (preço + prazo) — `CorreiosAdapter::cotar()`

Entra automaticamente na vitrine/checkout (o `CalculadoraService` já cota toda
transportadora ativa). Fluxo:

1. Autentica (Basic → Bearer, token cacheado na config da transportadora + memória).
2. Prazo por serviço: `GET /prepostagem*` → na verdade `GET /prazo/v1/nacional/{cod}?cepOrigem=&cepDestino=&dtEvento=&dataFinal=`.
3. Preço em lote: `POST /preco/v1/nacional`.

**Detalhes que faziam a cotação falhar (corrigidos):**
- **`psObjeto` em GRAMAS**, como **string** (o manual oficial usa `"300"` para uma
  caixa 20×20×20 → `pcFinal` R$19,92, o que só fecha com 300 g).
- Todos os valores como **string**; `dtEvento` no formato `dd/mm/aaaa`.
- Campos obrigatórios do payload: `coProduto`, `nuRequisicao`, `nuContrato`, `nuDR`,
  `cepOrigem`, `cepDestino`, `psObjeto`, `tpObjeto`, `comprimento`, `largura`, `altura`,
  `dtEvento`. Valor declarado só quando informado (adiciona `servicosAdicionais` 019).
- A chamada de **prazo** precisa de `dtEvento` **e** `dataFinal`.
- O peso do carrinho vem em `peso_g` (o adapter converte); dimensões em cm.

Preço no retorno vem no campo `pcFinal` (formato BR `"1.234,56"` → convertido).

---

## 3. Etiqueta de ida (pré-postagem) — `CorreiosAdapter::gerarEtiqueta()`

> **Emite etiqueta cobrada.** Teste primeiro em homologação apontando
> `config['api_base'] = https://apihom.correios.com.br`.

1. **Cria a pré-postagem**: `POST /prepostagem/v1/prepostagens` — remetente = a **loja**,
   destinatário = o **cliente**, objeto (peso em gramas, dims em cm), `codigoServico`.
   Retorna o `id` (`PR...`).
2. **Gera o rótulo** (assíncrono, 2 etapas — ver seção 4).

**Campos obrigatórios do corpo** (confirmados no OpenAPI, `RequestPrePostagemExternaDTO`):
`cienteObjetoNaoProibido`, `codigoFormatoObjetoInformado`, `codigoServico`,
`destinatario`, `itensDeclaracaoConteudo`, `pesoInformado`, `remetente`.

**Dois erros reais que foram corrigidos:**
- *"Telefone do remetente inválido"* → telefone roteado pelo tamanho:
  **9 dígitos** vão em `celular`/`dddCelular`, **8 dígitos** em `telefone`/`dddTelefone`
  (`telefonesCorreios()`).
- *"PPN-347: obrigatório informar Declaração de Conteúdo"* → corpo inclui
  `itensDeclaracaoConteudo` (`conteudo`/`quantidade`/`valor`, todos string), montado a
  partir dos produtos do pedido (`itensDeclaracao()`).

**Cancelamento** (`cancelarEtiqueta`) distingue automaticamente:
- id `PR...` (pré-postagem) → `DELETE /prepostagem/v1/prepostagens/{id}`.
- número de coleta (reversa) → SOAP `cancelarPedido`.

---

## 4. Rótulo assíncrono (PDF) — 2 etapas

O primeiro clique **solicita** e recebe um `idRecibo`; o PDF é baixado por esse id.

1. **Solicita**: `POST /prepostagem/v1/prepostagens/rotulo/assincrono/pdf` com
   `{idsPrePostagem:[...], tipoRotulo:'P', formatoRotulo:'ET'}` → devolve `idRecibo`.
2. **Baixa**: `GET /prepostagem/v1/prepostagens/rotulo/download/assincrono/{idRecibo}`
   → PDF em **base64** no campo `dados`.
   *(o `/assincrono/` depois de `/download/` era o que faltava — sem ele dava 404.)*

`EtiquetaService::imprimir()` é stateful:
- 1ª chamada: solicita, guarda o `id_recibo`, retorna `processando`.
- 2ª chamada (ou automática): baixa por `id_recibo`, salva o PDF e serve pela rota.

O **JS faz tudo num clique**: solicita, aguarda e tenta baixar (até ~6×, 3s cada).

### Visualização do PDF
- O PDF é salvo em `storage/logistica/rotulos/etiqueta_{id}.pdf` (**diretório NÃO
  público**).
- A rota `EtiquetaController::rotulo()` faz **stream** do PDF com
  `Content-Disposition: inline` → abre no navegador sem baixar.
- O `url_pdf` da etiqueta aponta para essa rota; URLs antigas (que apontavam direto
  para `/storage`) se **autocorrigem** no próximo clique.
- O front abre num **modal com `<iframe>`** (não em nova aba). Fechar por X, Esc ou
  clique fora; há botão "abrir em nova aba" como alternativa.

---

## 5. Rastreio SRO — `CorreiosAdapter::rastrear()`

`GET /srorastro/v1/objetos/{cod}?resultado=T`. Os eventos são mapeados para os estados
internos pela descrição (`statusSro`): entregue, saiu_entrega, postado, devolucao,
ocorrencia, em_transito. Eventos vêm do mais recente para o mais antigo (invertidos
para ordem cronológica).

---

## 6. Logística reversa (SOAP) — conforme spec oficial (Mar/2020)

`solicitarPostagemReversa`: destinatário = **loja**, remetente = **cliente**.

**Pontos do spec que foram acertados:**
- Observação/instruções vão em `<descricao>` (não `<obs>`).
- Tags **obrigatórias**: `<ciencia_conteudo_proibido>N` (destinatário) e
  `<restricao_anac>S` (remetente).
- Ordem das tags conforme o exemplo oficial; `valor_declarado`, `complemento`,
  `ddd`/`celular`/`ddd_celular` e `identificacao` (CPF/CNPJ) incluídos.
- CNPJ da loja via `reversa_loja_documento` ou `ConfigHelper::get('site_cnpj')`.

**Cancelamento** (`cancelarPedido`): ordem `codAdministrativo` → `numeroPedido` →
`tipo`. Sucesso vem **sem** `cod_erro` (com `objeto_postal`/`datahora_cancelamento`).

**Robustez:** ao cancelar a reversa, se a transportadora recusar/indisponível, o
status local é **forçado** para `cancelada` (nunca fica preso em `emitida`) e um aviso
é retornado.

**Peso do registro** nunca fica zero (mínimo de 300 g — a reversa é pesada na agência).

---

## 7. Preço real da postagem (cron) — `EtiquetaService::atualizarPrecosPostagem()`

Depois de postado, o valor cobrado sai em
`GET /prepostagem/v1/prepostagens/postada?codigoObjeto=...` (campo **`valorAtendimento`**).

- `CorreiosAdapter::consultarPostagem($codigo)` → valor, data e peso tarifado. Se ainda
  não foi postado, responde `nao_postada` (sem erro).
- `EtiquetaService::atualizarPrecosPostagem($limite)`: pega etiquetas **Correios**
  (filtro `adapter = 'CorreiosAdapter'`) com rastreio, sem `valor_postado`, não
  canceladas e recentes (120 dias), consulta e grava `valor_postado`, `data_postagem`,
  `peso_tarifado_g`.
- **Já encaixado no `cli/logistica-rastreio-worker.php`** — roda na mesma cadência do
  rastreio, **sem cron novo**. Idempotente (só toca em quem ainda não tem preço).

---

## 8. AR Eletrônico (Aviso de Recebimento) — `CorreiosAdapter::consultarAr()`

Botão **AR** na lista de etiquetas, que só aparece quando o objeto está **entregue**
(`log_rastreios.status_interno = 'entregue'`, juntado na listagem) e é **Correios**.

- `POST https://apps.correios.com.br/areletronico-rs/v1/ars/ultimoevento` com
  `{objetos:[codigo]}` → lê `imagemBase64` do `AREletronico`.
- Se existir, abre a imagem numa **modal do `adminDrawer`**; senão, mostra a mensagem
  de erro dos Correios (ex.: "Imagem não localizada para o objeto").
- Detecta imagem (PNG/JPG) vs PDF pelo cabeçalho base64 (`iVBOR`/`JVBER`).
- Host diferente (`apps.correios.com.br`); usa o **mesmo token Bearer** do CWS. Base
  sobrescrevível em `config['ar_base']`.

---

## 9. Regras de frete — bloqueio por faixa de CEP

Duas ações no `MotorRegras`, ambas com condição de faixa de CEP
(`campo cep_faixa`, operador `between`, ex.: `90000000` a `91999999` — só dígitos):

- **`bloquear_frete_gratis`** — continua vendendo, só remove o frete grátis da faixa.
- **`bloquear_frete`** (nova) — oculta **todas** as opções da faixa e liga o flag
  `bloqueado` no retorno; o checkout mostra "envio indisponível para este CEP".

Escopo opcional por transportadora/serviço (condição `transportadora` ou `modalidade`),
então dá para bloquear só SEDEX numa faixa e deixar o PAC, por exemplo. A ação
`bloquear_frete` é aceita no cadastro (`RegrasAdminService`), entra no resumo
("Bloqueia envio") e conta como efeito válido.

---

## 10. Cache de frete — invalidação automática + manual

`FreteCacheService::invalidar()` faz `DELETE ... WHERE tipo = 'cotacao'` (limpa só
cotações; **preserva o cache de CEP**, que não muda com transportadora/regra).

**Chamado automaticamente** ao alterar o que afeta preço:
- Regras: `RegrasAdminService::salvar / reordenar / remover`.
- Transportadoras: `TransportadoraAdminService::salvar / alternarStatus / reordenar`
  (inclui ativar/pausar, prioridade, config e serviços).

**Botão manual**: "Limpar cache de frete" no cabeçalho da tela de Transportadoras
(reusa o mesmo `invalidar()`, com CSRF e auditoria).

---

## 11. Arquivos alterados/criados nesta sessão

**Services**
- `app/services/logistica/transportadoras/CorreiosAdapter.php` — cotação, etiqueta,
  rótulo, rastreio SRO, reversa (SOAP), postada, AR, helpers de telefone/declaração.
- `app/services/logistica/EtiquetaService.php` — imprimir (2 etapas), stream/save do
  PDF, `arDaEtiqueta`, `atualizarPrecosPostagem`, listagem com `entregue`/`ver_ar`,
  `forcarCancelamentoLocal`.
- `app/services/logistica/ReversaService.php` — cancelar com fallback local + aviso.
- `app/services/logistica/MotorRegras.php` — ação `bloquear_frete` + flag `bloqueado`.
- `app/services/logistica/RegrasAdminService.php` — aceita `bloquear_frete` + invalida cache.
- `app/services/logistica/TransportadoraAdminService.php` — invalida cache nas escritas.
- `app/services/logistica/FreteCacheService.php` — `invalidarCotacoes()` + `invalidar()`.

**Controllers**
- `admin/controllers/EtiquetaController.php` — `buscarCep`, `buscarCliente`, `rotulo`
  (stream), `ar`.
- `admin/controllers/TransportadoraController.php` — `limparCache`.

**Views / assets**
- `admin/views/logistica/transportadoras.php` — botão "Limpar cache de frete".
- `admin/assets/js/logistica.js` — busca CEP/cliente, fluxo do rótulo, modal do PDF,
  botão + modal do AR.
- `admin/assets/js/logistica.js` — handler do limpar cache.
- `admin/assets/css/logistica.css` — modal do PDF, busca de cliente/CEP.

**Cron**
- `cli/logistica-rastreio-worker.php` — chama `atualizarPrecosPostagem` junto do rastreio.

**SQL**
- `sql/etiqueta_recibo_migration.sql`, `sql/etiqueta_postagem_migration.sql`.

---

## 12. Lições / armadilhas (para não repetir)

- **Peso na cotação é GRAMAS** (não kg) — o manual oficial é a fonte da verdade; o
  `peso_kg` da classe legada confundiu. Confirmado por `pcFinal` R$19,92 com `psObjeto "300"`.
- **Endpoint de download do rótulo** é `/rotulo/download/assincrono/{idRecibo}` — o
  `/assincrono/` depois de `/download/` é fácil de esquecer (dava 404).
- **Erros dos Correios são cumulativos** — aparecem um a um; corrigir e testar de novo.
- **AR fica em outro host** (`apps.correios.com.br`) — precisa de allowlist própria.
- **Tudo loga em `log_comunicacoes`** (tipos `correios_preco`, `correios_prazo`,
  `prepostagem`, `rotulo_solicita`, `rotulo_download`, `postada`, `ar_eletronico`,
  `cancelar_reversa`…). Diante de qualquer falha, o request+resposta exatos estão lá.
- **PDF do rótulo vem em base64** — servir por rota que faz stream (o `url_pdf` é
  VARCHAR(500), não cabe data URI; `storage` não precisa ser público).

---

*Testes automatizados: 8 suítes verdes (~500+ asserções). O código lint-passa; as
partes que dependem da API real (cotação, etiqueta, rótulo, postada, AR) foram
validadas quanto a payload/parse offline e devem ser confirmadas no primeiro uso real
via `log_comunicacoes`.*

---

## 13. Front-end: icones e unificacao do JS

> Sessao seguinte a esta doc. Nao alterou regra de negocio nenhuma — so a camada
> de apresentacao.

### 13.1 O bug que motivou tudo

O modulo referenciava **Bootstrap Icons** (`<i class="bi bi-printer">`) em 116
pontos, mas o CSS da biblioteca **nunca foi carregado** — nao ha `bootstrap-icons.css`,
nem CDN, nem `@font-face`, nem arquivo de fonte no repositorio. As unicas regras
`.bi` do projeto eram de `font-size`, dimensionando um glifo que jamais chegava.

Todo `<i class="bi ...">` renderizava **elemento vazio**. Nos 37 botoes so-icone
(`.log_btn--icon`, quadrado de 38px sem rotulo) o resultado eram quadrados
identicos e vazios — na coluna "Acoes" das etiquetas, cinco em sequencia, com
**"Remover" indistinguivel de "Imprimir"**. So o `title` no hover separava os dois,
e cinco botoes nem `title` tinham.

### 13.2 O que passou a valer

**Fonte unica de icone: `IconLibrary` + sprite SVG.**

- `assets/icons.json` foi de 132 para 141 icones. Os 9 novos (`close`, `cancel`,
  `mail`, `save`, `power`, `ink-eraser`, `label`, `whatsapp`, `more-vert`) sao
  Material Symbols, exceto o WhatsApp (marca).
- As 14 chaves PT-BR que ainda eram Feather (traco) — `caminhao`, `etiqueta`,
  `reversa`, `alerta`, `regras`… — tiveram **o SVG trocado in loco** por Material
  Symbols. As chaves nao mudaram, entao nenhum uso fora da logistica quebrou.
  O acervo passou de duas linguagens visuais misturadas para 133/141 em Material
  Symbols; o resto sao marcas (Pix, boleto, Google, WhatsApp).
- Tres chaves que **nao existiam** e renderizavam vazio em silencio foram
  corrigidas: `carrinho` -> `payments`, `manifesto` -> `docs`,
  `localizacao` -> `globe-location` (esta ultima na view publica do cliente).

**Novos metodos em `IconLibrary`:**

| Metodo | Para que |
|---|---|
| `sprite(array $chaves)` | Monta o `<symbol>` de cada chave. Impresso uma vez por pagina. |
| `ref($chave)` | Referencia um simbolo: `<svg><use href="#i-chave"></use></svg>`. |
| `has($chave)` | Diagnostico/testes. |

`render()` e `sprite()` agora chamam `avisarAusente()`, que registra em
`LogService::warning` quando a chave nao existe — antes o metodo devolvia `''`
e o icone sumia sem deixar rastro. Foi assim que as tres chaves acima passaram
despercebidas.

**Como o JS acessa os icones.** O JS nao alcanca o PHP, entao o sprite resolve a
ponte: `admin/views/logistica/_sprite.php` imprime 57 simbolos (26 KB) logo apos
`<body>`, e o JS referencia por `LOG.ico('chave')`. Sem webfont, sem requisicao
extra, sem repetir SVG a cada linha de tabela.

### 13.3 JS unificado

Os **nove** arquivos (`logistica`, `transportadoras`, `frete`, `etiquetas`,
`rastreios`, `reversas`, `divergencias`, `api-keys`, `frete-fallback` — 2.567
linhas) viraram **um so**: `admin/assets/js/logistica.js`, espelhando o que o CSS
ja fazia.

Motivo: o layout carregava **os nove** em qualquer pagina `/admin/logistica`,
mesmo usando so uma. Alem disso, quatro views tinham `<script src="/assets/js/X.js">`
apontando para caminhos que **nao existem** na raiz — 404 silencioso; removidos.

Estrutura do arquivo:

1. `LOG` — nucleo compartilhado (`esc`, `attr`, `comCsrf`, `moeda`, `ajax`, `ico`).
   Antes cada arquivo redefinia os seus: `esc` aparecia nove vezes, `api`/`attr`/
   `comCsrf` oito. Agora cada tela delega ao nucleo.
2. Uma IIFE por tela, guardada pelo seu elemento raiz — so a tela presente na
   pagina se inicializa. O corpo de cada uma foi preservado; mudaram os icones,
   os helpers duplicados e os rotulos acessiveis.

### 13.4 Acessibilidade e alvo de toque (Material 3)

- **37/37** botoes so-icone estavam sem `aria-label` — todos ganharam. Os cinco
  que nem `title` tinham (`js-vol-rm`, `js-item-rm`, `js-mover`) ganharam os dois.
- Alvo de toque: `.log_btn--icon` foi de **38x38 para 44x44**; a variante `--xs`
  de 26px para 36px. O icone renderiza a 20px.
- **Acao destrutiva deixou de ser identica as demais**: variante `.log_btn--danger`
  (vermelha) em `js-remover`, `js-cancelar`, `js-revogar`, `js-vol-rm`,
  `js-item-rm`, `js-cond-rm`, `js-svc-rm`. O `js-a-cancelar` da reversa usava
  `style="color:..."` inline e passou a usar a classe.
- Coluna "Acoes" das etiquetas: 220px -> 260px, para caber com os botoes maiores.

> **Nao feito, proposto:** mover as acoes secundarias para um menu de overflow
> (`more-vert`, ja no acervo). Seria o caminho MD3 para chegar aos 48dp, mas
> `.log_table_wrap` tem `overflow-x:auto` e recortaria o dropdown; renderizar num
> portal quebraria a delegacao `closest('tr')` de que **todos** os handlers
> dependem. Fica como decisao a parte.

### 13.5 Bugs de CSS corrigidos

- **`--log-shadow` era invalido.** Faltava a virgula entre as duas camadas
  (`0 1px 2px #11111a0a 0 4px 16px #11111a0d`), o que anulava o valor inteiro e
  deixava os quatro `box-shadow: var(--log-shadow)` sem pintar nada. Mesmo erro
  em `--log-shadow` e `--log-shadow-lg` da variante escura.
- **Dois blocos de token com o mesmo seletor.** `.log_shell,.admin-drawer` e
  declarado no topo (paleta clara) e de novo na linha ~1990 (escura), sem media
  query. O segundo vence sempre — o painel e escuro de proposito
  (`html{color-scheme:dark}` em `admin.css`). **Cuidado:** o bloco do topo *nao* e
  descartavel: e a unica fonte de `--log-radius`, `--log-radius-sm`,
  `--log-radius-pill` e da `font-family`. Remove-lo quebraria todo o
  arredondamento. Ambos ficaram comentados explicando qual editar.
- As 6 regras `.bi` orfas viraram regras de SVG (`.log_ico`), e a `.log_ico`
  duplicada (uma dormente, uma nova) foi consolidada.

### 13.6 Pendencias fora do escopo

- `admin/assets/js/fluxo-canvas.js` (modulo de Fluxos) tem o mesmo problema:
  `<i class="bi ...">` com a webfont ausente. No admin inteiro sao ~212
  ocorrencias; a logistica era 116.
- Chaves de icone inexistentes em outros modulos: `arrow-right`, `external-link`,
  `list` (`admin/views/help_faq/`), `grid` (`help_faq/perguntas.php`), `upload`
  (`admin/views/produtos/index.php`). Rendem vazio hoje; com o `avisarAusente()`
  passam a aparecer no log.

---

## 14. Diagnostico da cotacao Correios — 29/08/2026

Sessao de depuracao do "Correios nao funciona no simulador" e do "fallback/cache
nao funcionam". Eram **cinco** defeitos distintos, tres de codigo e dois de dado.

### 14.1 O que estava errado

**(1) `dr` nunca foi preenchido na config da transportadora** — o adapter mandava
`nuDR: 0` e os Correios respondiam:

```
PRC-124: O numero do contrato e/ou numero da DR informado nao e o mesmo
apresentado no token de acesso.
```

O valor correto vem da **propria resposta de autenticacao**, que o adapter
descartava:

```json
"cartaoPostagem": { "contrato": "9912635232", "numero": "0078202736", "dr": 64 }
```

Corrigido no banco: `dr = 64`, `tp_objeto = 2`.

**(2) O ENUM de `log_comunicacoes.tipo` rejeitava metade dos tipos.** A coluna
aceitava 9 valores; o codigo grava 15. Faltavam `correios_preco`,
`correios_prazo`, `correios_sro`, `correios_postada`,
`correios_cancelar_prepostagem` e `ar_eletronico`. O MySQL recusava com
`1265 Data truncated for column 'tipo'` e, como `logComunicacao()` engole a
excecao por design, **a cotacao dos Correios nunca apareceu na auditoria** — em
253 linhas de log, zero registros de preco/prazo. Era justamente a tela que a
secao 12 manda consultar quando algo falha.

Migration: `sql/log_comunicacoes_tipo_migration.sql` (ENUM -> VARCHAR(40)). Um
ENUM que exige migration a cada endpoint novo reintroduz o bug na proxima
integracao.

**(3) O adapter ignorava a flag `usa_valor_declarado`.** Com ela em `0`, ele
anexava o servico adicional **019** a todos os produtos sempre que havia valor de
carrinho. Os Correios recusam o produto INTEIRO nesse caso:

```
ERP-054: Servico adicional 019 nao pode ser prestado com o servico informado (03298).
```

Resultado: com carrinho valorizado — ou seja, **sempre** — o unico servico que
sobrava era o reverso. Esse era o motivo real do "nao funciona no simulador".

Agora o 019 so vai quando a transportadora esta configurada para isso **e**, se
ainda assim algum servico recusar o adicional, `precoLote()` recota so aqueles
sem o 019 e mescla o resultado. Uma cotacao nao se perde mais por esse motivo.

**(4) Servicos de reversa entravam na cotacao de ida.** `03247 Pac Reverso` e
`03301 Sedex Reverso` moram na mesma tabela e cotam normalmente na API —
apareciam como opcao de envio, com preco e prazo de devolucao. Pior: o
`codigoServico` da etiqueta vem da opcao escolhida, entao a etiqueta de ida sairia
com codigo de reversa. Filtrado por `modalidade` em tres lugares:
`CorreiosAdapter::servicosContrato()`, `EtiquetaController` (seletor da etiqueta)
e `ReversaController` (que, espelhadamente, listava os servicos de ida).

**(5) `log_frete_fallback` estava vazia.** O servico funciona, mas sem linha
nenhuma `estimar()` devolve vazio e a vitrine cai em "Sem cotacao e sem fallback
de frete" — com a API fora do ar, a loja parava de vender. Seed em
`sql/frete_fallback_seed.sql`, montado a partir de **precos reais coletados da
API** (35 pontos: 5 regioes x 7 pesos), reproduzidos com desvio maximo de 0,1%.

### 14.2 O cache nunca esteve quebrado

`FreteCacheService` grava normalmente. O que confunde e que **cache e fallback so
existem no `FreteVitrineService`** (vitrine/checkout). O simulador do admin chama
`CalculadoraService::cotar()` direto, que nao passa por nenhum dos dois — de
proposito, ja que um simulador deve mostrar o preco vivo, nao o cacheado.

Verificado ponta a ponta apos as correcoes:

```
1a chamada  origem=transportadora  -> Melhor envio R$19,22 | Correios Pac AG R$24,07/5d
            cache de cotacao: 1 -> 2 registros
2a chamada  origem=cache
```

### 14.3 Pendente com os Correios (nao e codigo)

`03140 Sedex AG` responde **ERP-006 "CEP de origem nao pode postar para CEP de
destino"** para todo destino fora de Porto Alegre:

| Destino | 03140 Sedex AG |
|---|---|
| Porto Alegre/RS | R$ 32,85 |
| Sao Paulo/SP | ERP-006 |
| Curitiba/PR | ERP-006 |
| Brasilia/DF | ERP-006 |
| Manaus/AM | ERP-006 |

Na pratica a loja so tem **PAC** pelos Correios hoje. Confirmar com os Correios
qual codigo de SEDEX o contrato 9912635232 cobre (o de contrato costuma ser
`03220`, nao o `03140` de agencia) e cadastrar o codigo certo em Servicos.

### 14.4 Como reproduzir o diagnostico

O erro dos Correios so aparece se voce olhar a resposta crua. Com a migration do
item (2) aplicada, `log_comunicacoes` passa a guardar request+resposta de
`correios_preco` e `correios_prazo`, e o `txErro` por produto fica visivel ali.
`cotar()` tambem devolve agora uma chave `recusados[]` com servico + motivo, em
vez de descartar em silencio.

