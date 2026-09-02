# Central de Marketing IA — Fase 2 · Bloco A: Geração de Imagem

Imagem ponta a ponta com a mesma disciplina do texto: fila, orquestrador com
fallback, custo real no rollup, curadoria no histórico. **32/32 asserções
verdes** contra o dump real (fluxo assíncrono completo, idempotência,
assinatura svix, fallback síncrono, watchdog).

---

## As decisões tomadas (e por quê)

1. **Replicate assíncrono, OpenAI síncrono, no mesmo orquestrador.**
   flux-2-dev (prioridade 10) cria uma *prediction* e devolve na hora — a
   geração fica em `aguardando_provedor` com o `external_id`. gpt-image-1.5
   (prioridade 20) responde base64 no corpo — conclui no mesmo job. O
   fallback entre modelos continua valendo ANTES do aceite; depois que um
   provedor assíncrono aceita, a cadeia para ali de propósito (retentar
   noutro modelo enquanto o primeiro roda geraria custo duplo). Fallback
   pós-aceite fica para a Fase 2C.

2. **Webhook é otimização; varredura é o contrato.** Sem `IA_WEBHOOK_BASE`
   definida, nada de webhook: o worker consulta as predictions pendentes a
   cada iteração (`listarAguardando(5, 20s)`). Com a constante definida, o
   Replicate avisa na hora E a varredura segue como rede de segurança.
   Os dois caminhos convergem em `processarRetornoProvedor()` — **idempotente**
   (guard por status): webhook + varredura simultâneos não duplicam nada.

3. **Download imediato, sem exceção.** URLs de entrega expiram em ~1h.
   O binário é baixado no ato, gravado em `IA_STORAGE_PATH/imagens/AAAA/MM/`
   e indexado em `ia_arquivos` com hash — só então a geração vira concluída.
   Se o download falhar, nada é marcado e a próxima varredura refaz.

4. **Imagem servida SÓ por endpoint autenticado.** `GET /admin/ia/arquivo?id=`
   com permissão `marketing_ia`, trava de `realpath` dentro do storage
   (`&download=1` força download). O storage segue negado ao público.

5. **Proporções nativas: 1:1, 3:2, 2:3** (mapeadas por provedor: `size` na
   OpenAI, `aspect_ratio` no Replicate), gravadas em `ia_geracoes.formato`.
   Formatos exatos de banner (1920×800 etc.) são responsabilidade do
   compositor na Fase 2C — forçar no modelo gera distorção.

6. **Prompt de imagem é outro animal.** `montarPromptImagem()`: diretrizes
   visuais do tipo + identidade do produto (nome/marca/categoria) + direção
   do briefing + regra anti-texto/logotipo. Nada dos blocos TAREFA/DADOS do
   texto. Máximo de 3 variações por vez (cada uma custa de verdade).

7. **Custo flat por imagem** (`por_imagem`/`por_execucao` do `custo_config`),
   estimado no enfileiramento pelo modelo primário e realizado pelo modelo
   que executou — inclusive na conclusão assíncrona (o `modelo_id` fica
   gravado no aceite justamente para isso).

## O fluxo assíncrono (o que aparece na tela)

```
Gerar → na_fila → worker despacha → Replicate aceita
      → aguardando_provedor (card continua "Gerando…")
      → [webhook chega OU varredura consulta]
      → download → ia_arquivos → concluida (card mostra a imagem)
```

Watchdog: `aguardando_provedor` sem resposta em 15 min → `falhou` com erro
claro. Falha remota (`failed`/`canceled`, ex.: NSFW) → `falhou` com o motivo
do provedor preservado: `[provedor_failed] ...`.

---

## Instalação (delta sobre Fases 0+1 já instaladas)

**1. Banco:**
```bash
mysql -u USER -p BANCO < sql/2026-07-15_ia_fase2a.sql
```
(1 ALTER de enum + 2 tipos de imagem — `INSERT IGNORE`, idempotente)

**2. Arquivos** — copie por cima (mesmos destinos de antes):

| Arquivo | Destino |
|---|---|
| `app/services/ia/*.php` (5 alterados) | `app/services/ia/` |
| `app/services/ia/providers/*.php` (3 alterados) | `app/services/ia/providers/` |
| `app/models/IAGeracao.php` | `app/models/` |
| `app/controllers/IAGeracaoController.php` | `admin/controllers/` |
| `app/controllers/IAWebhookController.php` **(novo, PÚBLICO)** | `app/controllers/` da LOJA |
| `app/views/ia/**` (4 alterados) | onde estão suas views ia/ |
| `routes.ia.php` (27 rotas — +`/ia/arquivo`) | `admin/config/` |
| `ia-worker.php` | raiz (substitui) |

**3. Rota pública do webhook** (router da LOJA, não do admin):
```php
Router::post('/webhooks/ia/replicate', 'IAWebhookController@replicate');
```
O controller novo precisa estar visível ao autoloader da loja (`app/controllers`
já registrado). Sem CSRF — é endpoint de máquina, autenticado por assinatura.

**4. Chave do Replicate:** cole na tela de config (⚡ Testar → ativar).

**5. Webhook em produção (OPCIONAL — pode pular no Laragon):**
   - No config: `define('IA_WEBHOOK_BASE', 'https://homo-v2.sportmoto.com.br');`
   - Em replicate.com → Account → Webhooks → copie o *Signing secret* (`whsec_...`)
   - Grave no provedor: `UPDATE ia_provedores SET config_extra =
     JSON_SET(COALESCE(config_extra,'{}'), '$.webhook_secret', 'whsec_SEU')
     WHERE codigo='replicate';`
   Sem nada disso, tudo funciona pela varredura (latência extra de ~20–60s).

## Teste de fumaça

1. Config → Replicate com chave ativa.
2. Gerar → produto → **Imagem conceitual do produto** → proporção 3:2 → Gerar.
3. Card fica "Gerando…" e, em ~30–90s (varredura), mostra a imagem com Baixar.
4. Histórico → drawer da geração mostra a imagem, o custo real e o roteamento
   com a linha `aguardando`.
5. Sem chave Replicate (ou inativo): mesma geração cai no gpt-image-1.5 e
   conclui síncrona — teste do fallback.

## Segurança

- svix validado quando houver secret (HMAC do `id.timestamp.body`, janela
  anti-replay de 5 min, comparação constante `hash_equals`); sem secret,
  aceita com warning — idempotência + varredura seguram o forte.
- Webhook responde 200 até para predictions desconhecidas (evita retry storm)
  e loga tudo (`ia_webhook_*`).
- Endpoint de arquivo: permissão + realpath dentro do storage + 404 genérico.
- Prompt de imagem herda a regra anti-texto/logotipo do tipo (o compositor da
  Fase 2C é quem escreve por cima, com fonte e preço certos).

## O que fica para os próximos blocos

- **B** — remoção de fundo (bria) com cache em `ia_recortes_produto`.
- **C** — compositor Imagick, 7 layouts, pipeline recorte→cena→composição,
  publicar direto em `banners` (aguarda `apt install lsphp82-imagick`).
