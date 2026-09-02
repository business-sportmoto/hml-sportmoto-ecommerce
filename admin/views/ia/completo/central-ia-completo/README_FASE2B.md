# Central de Marketing IA — Fase 2 · Bloco B: Foto real do produto

A foto do catálogo entra no módulo por dois caminhos, validados com
**25/25 asserções** contra o dump real:

1. **Recorte com cache** — a imagem de `produto_imagens` vira PNG transparente
   (bria via Replicate) e fica guardada em `ia_recortes_produto`. Recortou uma
   vez, **nunca paga de novo** enquanto a foto não mudar. É a fundação do
   compositor fiel da Fase 2C.
2. **Foto como referência** — checkbox na geração criativa envia a foto real
   junto do prompt (FLUX.2 aceita imagens de entrada): a cena sai integrada com
   o produto muito parecido. Bom para posts conceituais; a fidelidade pixel a
   pixel continua sendo papel do recorte + composição (2C).

---

## As decisões tomadas (e por quê)

- **Cache por imagem, não por produto.** `UNIQUE(produto_imagem_id)` +
  `hash_origem` (sha256 do NOME do arquivo — os uploads usam nome aleatório,
  então foto nova = arquivo novo = hash novo = recorte refeito). Refazer é
  UPSERT: a linha atualiza, o histórico fica nas gerações.
- **Recorte reusa 100% da máquina do Bloco A.** É uma geração de sistema
  (`tipo recorte_produto`, grupo `sistema` — invisível na tela) com capacidade
  `remocao_fundo`: mesma fila, mesmo worker, mesma varredura/webhook, mesmo
  watchdog, custo no rollup. Zero máquina nova para manter.
- **`geracao_id` no cache** (migration 2B): o clique em "Remover fundo" devolve
  o uuid da geração — em cache quente o card aparece **instantâneo e concluído**
  pelo mesmo polling de sempre; em cache frio, vira o card "Gerando…" normal.
- **Referência é opt-in e honesta.** Só o Replicate (FLUX.2) recebe imagem de
  entrada por ora: com a referência marcada, modelos sem suporte são PULADOS
  com registro `sem_suporte_referencia` no roteamento — nada de gerar às cegas
  fingindo que usou a foto. O prompt ganha a instrução de fidelidade
  automaticamente.
- **Bria primeiro** (US$ 0,018/execução, licenciado para uso comercial),
  `851-labs` de fallback barato — ordem já seedada na Fase 0.

## Instalação (delta sobre A já instalado)

**1. Banco:**
```bash
mysql -u USER -p BANCO < sql/2026-07-16_ia_fase2b.sql
```
(ALTER no cache + tipo de sistema — rodar UMA vez)

**2. Config — nova constante (obrigatória para este bloco):**
```php
define('IA_PRODUTO_IMG_BASE', 'https://homo-v2.sportmoto.com.br/uploads/produtos');
```
É a base pública de `produto_imagens.arquivo`. O **Replicate baixa a imagem por
essa URL**, então ela precisa ser alcançável da internet (se o Cloudflare tiver
regra de bloqueio a bots, libere o caminho). No Laragon, aponte para a
homolog/produção — os nomes de arquivo do dump são os mesmos.
AJUSTE: confirme o caminho real onde as fotos de produto são servidas.

**3. Arquivos** — copie por cima (mesmos destinos):
`IARecorteService.php` (novo) + os alterados: `IAOrchestrator`,
`IAGeracaoService`, `IAProviderBase`, `ReplicateAdapter`, `IAGeracao`,
`IATipoConteudo`, `IAGeracaoController`, views (`_estilos`, `gerar/index`,
`gerar/_produto_painel`), `routes.ia.php` (28 rotas) e `ia-worker.php`.

## Teste de fumaça

1. Gerar → escolher um produto **com foto** → aparece a faixa "Foto principal
   do produto" com o botão **Remover fundo (recorte)**.
2. Clique: card "Gerando…" → em ~20–60s vira o PNG transparente. No banco,
   `ia_recortes_produto` ganhou a linha.
3. Clique DE NOVO: toast "Recorte recuperado do cache — custo zero" e o card
   aparece já concluído, instantâneo.
4. Tipo "Imagem conceitual do produto" → marque **Usar a foto do produto como
   referência** → Gerar: a cena sai com o produto da foto integrado.
5. Histórico: as gerações de recorte aparecem como "Recorte de produto
   (sistema)", com custo e roteamento completos.

## Observações honestas

- **`ref_param` do FLUX.2**: a referência vai no input `input_images` (lista).
  Se a API do Replicate acusar parâmetro desconhecido para algum modelo, o nome
  é configurável por modelo sem tocar em código:
  `params_padrao = {"ref_param": "image_prompt"}` (confira o schema na página
  do modelo em replicate.com).
- **Bria input**: a foto vai no input `image`. Parâmetros extras (ex.:
  `content_moderation`) entram pelo `params_padrao` normalmente.
- A validação do adapter recusa origem que não seja `http(s)` — erro claro em
  vez de prediction quebrada.

## Próximo

**Bloco C** (aguarda Imagick): compositor com os 7 layouts — recorte (deste
bloco, do cache) + cena de fundo (Bloco A) + preço/logo/headline → publicar
direto em `banners`.
