# Central de Marketing IA — Fase 2 · Bloco C: Compositor de Banners

A peça final da Fase 2: foto real recortada + cena de IA + preço/headline/logo,
montados pelo **Imagick** no formato exato de cada layout e publicados direto
na tabela `banners` — **30/30 asserções verdes**, incluindo sondas de pixel na
arte final e o pipeline assíncrono completo contra o schema real.

---

## O desenho (como debatido)

- **Geração ÚNICA com etapas.** Um card, um polling. A coluna
  `ia_geracoes.etapa` diz onde o pipeline está (`recorte` → `cena`) e o pill da
  UI mostra "Gerando… (recortando produto)" / "(criando cena)". Webhook e
  varredura convergem no mesmo `processarRetorno`, idempotente como no Bloco A.
- **Custo por etapa, sem dupla contagem.** Cada passo entra no rollup pela SUA
  capacidade (`remocao_fundo`, `imagem`); `custo_real_usd` da geração é a soma;
  `composicao` nunca aparece no rollup (T4 prova). Cache de recorte quente =
  etapa pulada = estimativa e custo menores (T5).
- **Fallback PÓS-ACEITE** — a promessa da 2C. Se a prediction falhar no
  provedor (NSFW filter, capacity), a etapa retenta no próximo modelo da
  capacidade, registrando em `modelos_tentados` — inclusive caindo no
  **gpt-image síncrono**, que compõe na hora (T6).
- **Layouts = dados, não código.** `ia_layouts.camadas` guarda tudo em frações
  do canvas (x/y/larg sobre a largura, fonte sobre a altura) — mudar um banner
  de lugar é um UPDATE, sem deploy. **10 layouts ativos**: os 5 novos + os 5
  códigos úteis da Fase 0 que ganharam config (story, feed_quadrado,
  whatsapp_card, thumbnail, email_header); `banner_site` e `feed_vertical`
  saíram por duplicarem dimensões exatas.
- **A cena nunca contém o produto nem texto.** As instruções do tipo
  `banner_produto` proíbem; o prompt ainda pede respiro no lado onde o layout
  ancora o produto (`cx` decide). Fidelidade total: o produto é a foto real
  recortada (cache do Bloco B), o preço é o do banco, o texto é do compositor.
- **Publicar nasce INATIVO.** A zona é casada automaticamente: explícita >
  dimensões ideais iguais às do layout > primeira ativa. O arquivo vai para o
  diretório público, a linha entra em `banners` com `ativo = 0` e ordem no fim
  da fila — revisão humana na tela de banners antes de ir ao ar. A geração é
  marcada `aprovado`.

## Instalação (delta sobre A+B+Gemini)

**0. Pré-requisitos:** Imagick no PHP (feito no seu VPS) e, para PUBLICAR,
pelo menos **uma zona ativa** em `banner_zonas` (sem zona, o publicar responde
com erro claro).

**1. Banco:**
```bash
mysql -u USER -p BANCO < sql/2026-07-20_ia_fase2c.sql
```

**2. Arquivos** (mesmos destinos): novos `IACompositorService.php` e
`IAComposicaoService.php` em `app/services/ia/`; alterados: `IAOrchestrator`,
`IAGeracaoService`, `models/IAGeracao`, `IAGeracaoController`, `ia-worker.php`,
`routes.ia.php` (**29 rotas**), views `gerar/_produto_painel`, `gerar/index`,
`historico/_detalhe`, `historico/index`. Autoloader: nada novo.

**3. Constantes** — todas opcionais, no config:
```php
// AJUSTE: diretório PÚBLICO onde os banners são servidos
// (padrão: RAIZ_DO_PROJETO/uploads/banners)
define('IA_BANNER_DIR', '/home/homo-v2.sportmoto.com.br/public_html/uploads/banners');

// AJUSTE: prefixo gravado em banners.arquivo_imagem, se a tela de banners
// esperar caminho relativo (abra um banner existente e copie o padrão).
// Vazio = só o nome do arquivo, como em produto_imagens.
define('IA_BANNER_VALOR_PREFIXO', '');

define('IA_LOGO_PATH',  '/caminho/logo-branca.png');   // opcional: logo nas artes
define('IA_FONTE_PATH', '/caminho/fonte-marca.ttf');   // opcional: fonte da marca
```
Sem `IA_LOGO_PATH`, a camada de logo é pulada; sem `IA_FONTE_PATH`, usa
DejaVu Sans Bold do sistema.

## Teste de fumaça

1. Gerar → produto com foto → tipo **"Banner do produto (compositor)"** →
   aparece o select de **layout** + headline/subtítulo opcionais.
2. Gerar: o pill mostra as etapas; em cache frio são duas esperas (recorte e
   cena, ~1–3 min no total), em cache quente só a cena.
3. Card final: a arte no formato exato do layout, com botão
   **"Publicar banner"**. Publicando: toast confirma a zona e o estado INATIVO.
4. Tela de banners: a linha nova está lá, inativa, no fim da ordem da zona —
   ative quando aprovar. Histórico: geração `aprovado`, custo = soma das
   etapas, roteamento com cada passo.

## Notas operacionais

- Custo típico do banner frio: bria + flux (~US$ 0,05); quente: só a cena.
  Retry no gpt-image usa o preço dele — tudo visível no rollup por capacidade.
- Ajustar um layout (posição do produto, tamanho do preço) = editar o JSON de
  `camadas` — as frações valem para qualquer resolução.
- ImageMagick 6 e 7 são compatíveis com as chamadas usadas (resize/crop/
  gradiente/annotate/roundRectangle) — validado no IM6 do ambiente de teste.
- Cron: nada novo — o mesmo `ia-worker.php` a cada minuto cuida do pipeline.
  AJUSTE pendente da homolog: confirme o binário PHP real do cron
  (`crontab -l` dos workers existentes) — se a stack for nginx+FPM da Ploi,
  é `php`/`/usr/bin/php8.2`, não o caminho do lsphp.

## Fase 2 — encerrada

A (imagem assíncrona) + B (foto real com cache) + C (compositor e publicação)
+ migração Gemini/SEO: **a Central gera texto, imagem, recorte e banner final
com custo, roteamento e limites auditáveis em cada passo.** Próximos degraus
naturais: Fase 3 (campanhas em lote sobre `ia_campanhas`) e distribuição.
