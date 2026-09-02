# Central de Marketing IA — Migração Gemini + SEO

O Gemini vira o terceiro provedor do orquestrador e o SEO por IA passa a rodar
por dentro da Central — **26/26 asserções verdes**, com o contrato público
intacto: `SeoIaController` e as telas de produto/categoria/marca/página **não
mudam uma linha**.

---

## O que muda (e os dois bugs que morrem)

- **`GeminiAdapter`** (novo, em `providers/`): auth pelo header
  `x-goog-api-key` — a chave **sai da URL**, onde o service antigo a espalhava
  por access logs e mensagens de erro. Sem retry interno cego: quem retenta e
  faz fallback é o orquestrador, com tudo em `ia_roteamento_log`.
- **O bug do SEO salvo em branco morreu.** No service antigo, quando o Google
  respondia "high demand", o fallback devolvia o envelope cru da API — o
  `SeoIaService` lia `meta_title` de onde não existia e salvava tudo vazio,
  em silêncio. Agora o fallback cai no gpt-5.4-mini **com
  `response_format: json_object`** e devolve o mesmo pacote JSON (T4 prova).
- **JSON nativo por tipo**: coluna `ia_tipos_conteudo.saida` — o Gemini usa
  `responseMimeType`, o OpenAI usa `response_format`; parse garantido nas
  duas pontas.
- **SEO registrado e limitado**: cada clique em "Gerar SEO" vira uma linha em
  `ia_geracoes` (tipo de sistema `seo_pacote`, invisível na tela de gerar) —
  custo real por token no rollup (~US$ 0,0008/pacote no 3 Flash), roteamento
  auditável no histórico, e os **limites de gasto passam a valer para o SEO**.
- **Gemini-first preservado**: `seo_pacote` nasce pinado no `gemini-3-flash`
  (US$ 0,50/3,00 por 1M — preços de 15/07/2026). Na cadeia GLOBAL de texto,
  os Gemini entram DEPOIS dos OpenAI (prioridades 40/50/60) — o marketing
  continua como está, só ganha fallback extra.
- **Prompt saneado**: regra fantasma `seo_text` removida, bloco de regras
  duplicado consolidado (agora vive em `instrucoes_sistema` do tipo), dados
  da loja mantidos.

## Instalação (ordem importa — zero downtime)

**1. Banco:**
```bash
mysql -u USER -p BANCO < sql/2026-07-16_ia_gemini_seo.sql
```

**2. Arquivos** (mesmos destinos de sempre):
`providers/GeminiAdapter.php` (novo) · `app/services/SeoIaService.php`
(**substitui** o atual) · `cli/migrar-chave-gemini.php` (novo) ·
alterados: `IAOrchestrator`, `providers/OpenAIAdapter`, `models/IAGeracao`,
`ia-worker.php`. Autoloader: nada novo — `providers/` já está registrado.

**3. Chave** — um dos dois caminhos:
   - Tela: `/admin/ia/config` → Gemini → colar a chave → ⚡ Testar → ativar; **ou**
   - CLI (migra a do `.env`):
     `/usr/local/lsws/lsphp82/bin/php cli/migrar-chave-gemini.php`
     (AJUSTE no topo: espelhe os requires do email-worker, como no ia-worker)

**4. Teste de fumaça**: abrir um produto no admin → Gerar SEO → campos
preenchem como antes. Depois: `/admin/ia/historico` mostra a geração
"Pacote SEO (sistema)" com custo e roteamento.

**5. Faxina** (depois do teste): remover `GEMINI_API_KEY`, `GEMINI_MODEL` e
`GEMINI_FALLBACK_MODEL` do `.env` e **apagar `app/services/GeminiService.php`**
— nada mais o referencia.

## Notas operacionais

- Trocar o modelo do SEO = editar o `modelo_id` do tipo `seo_pacote`
  (por ora via SQL: `UPDATE ia_tipos_conteudo SET modelo_id = (SELECT id FROM
  ia_modelos WHERE codigo_modelo='gemini-3.5-flash') WHERE codigo='seo_pacote';`).
  Sem pino (`NULL`), o SEO segue a cadeia global (OpenAI primeiro).
- Preços dos modelos: editáveis na tela de config, como os demais.
- `content_filter` (SAFETY) interrompe a cadeia de propósito — outro modelo
  tende a bloquear igual; a tela recebe a mensagem clara.
- Regenerar SEO é sempre permitido (dedup por unicidade, não por minuto) —
  o freio contra abuso são os limites de gasto, que agora cobrem o SEO.
