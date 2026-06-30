# SportMoto × Malga — Etapa 4: Webhook receiver

Recebe e processa eventos assíncronos da Malga (PIX pago, boleto compensado, chargeback, etc.). Crítico para PIX e boleto: sem isso, pedidos pagos ficam pra sempre como "aguardando_pagamento".

## O que está aqui

```
app/services/payment/
├── MalgaWebhookSignatureValidator.php  Validação Ed25519 + replay protection
└── MalgaWebhookProcessor.php           Aplica o evento no domínio

app/controllers/
└── WebhookController.php               Endpoint público POST /webhooks/malga

scripts/
└── register_malga_webhook.php          Registra webhook na Malga (one-shot)

workers/
└── webhook-retry-worker.php            Reprocessa logs com erro (cron 1min)

migrations/
└── pgto_003_webhook_fields.sql         Adiciona campos em pgto_gateways

tests/
├── run_tests.php                       24 testes (validator + processor)
└── run_e2e.php                          6 testes de fluxo completo
```

## Arquitetura

```
                    Malga
                      │
                      ▼ POST /webhooks/malga
              WebhookController
                      │
        ┌─────────────┼─────────────┐
        ▼             ▼             ▼
   Validator    pgto_webhook    Processor
   (Ed25519)    _log (UNIQUE     (DB-only)
                event_id)
                                      │
                            ┌─────────┼─────────┐
                            ▼         ▼         ▼
                       pgto_         pedidos  estoque
                       transacoes              cupons
```

## Detalhes de segurança

**Assinatura Ed25519** (não HMAC). A Malga assina cada evento com a chave privada Ed25519 dela; nós validamos com a chave pública que ela retorna ao registrar o webhook. Usa libsodium nativo do PHP (`sodium_crypto_sign_verify_detached`) — sem dependência externa.

**Replay protection**: header `X-Plug-Date` (timestamp Unix em ms) é parte da mensagem assinada. Rejeitamos eventos com mais de 5 minutos. Janela ajustável no construtor.

**Idempotência**: `X-Idempotency-Key` (= `payload.id`) tem UNIQUE em `pgto_webhook_log.event_id`. Receber o mesmo evento 2x retorna `200 duplicate` sem reprocessar.

**Body bruto**: a validação usa `file_get_contents('php://input')` antes de qualquer parsing. Se passasse por `json_decode` + `json_encode`, a ordem de chaves mudaria e a assinatura quebraria.

**Política de resposta**:

| Situação | HTTP | Comportamento |
|----------|------|---------------|
| Assinatura/timestamp inválido | 401 | Nada persistido (e Malga retentativa, OK pra problema transitório) |
| Body malformado | 400 | Não gera retentativa útil |
| Evento duplicado | 200 | `{status: duplicate}` |
| Erro no processamento de domínio | 200 | Log persistido com erro; worker reprocessa |
| Sucesso | 200 | `{status: processed}` |

## Como rodar

### 1. Migration

```bash
mysql -u USER -p sportmoto_homo < migrations/pgto_003_webhook_fields.sql
```

### 2. Subir arquivos novos

```bash
cp app/services/payment/MalgaWebhook*.php  /home/homo-v2.sportmoto.com.br/public_html/app/services/payment/
cp app/controllers/WebhookController.php   /home/homo-v2.sportmoto.com.br/public_html/app/controllers/
cp scripts/register_malga_webhook.php      /home/homo-v2.sportmoto.com.br/public_html/scripts/
cp workers/webhook-retry-worker.php        /home/homo-v2.sportmoto.com.br/public_html/workers/
```

### 3. Adicionar rota

No router do SportMoto, adicionar a rota pública:

```php
// SEM auth, SEM CSRF
$router->post('/webhooks/malga', 'WebhookController@malga');
```

⚠ Importante: essa rota tem que ficar fora de qualquer middleware que valide CSRF, sessão ou login.

### 4. Registrar o webhook na Malga

```bash
export MALGA_CLIENT_ID="..."
export MALGA_API_KEY="..."

# Recomendado: rodar primeiro com --first-only pra validar
/usr/local/lsws/lsphp82/bin/php script/register_malga_webhook.php \
    --endpoint=https://homo-v2.sportmoto.com.br/webhooks/malga \
    --events=transaction.authorized \
    --first-only

# Se OK, registrar os outros eventos:
/usr/local/lsws/lsphp82/bin/php script/register_malga_webhook.php \ --endpoint=https://homo-v2.sportmoto.com.br/webhooks/malga \ --events=transaction.pending,transaction.pre_authorized,transaction.failed,transaction.canceled,transaction.voided,transaction.charged_back,transaction.refund_pending
```

O script salva a chave pública Ed25519 em `pgto_gateways.webhook_public_key`. Em `--dry-run` ele só mostra o que faria.

⚠ Atenção: a Malga cria 1 webhook por evento, e em alguns cenários cada um pode vir com uma chave pública diferente. O script avisa se isso acontecer — me passa o output e a gente refatora pra guardar 1 chave por evento. Em geral retorna a mesma.

### 5. Configurar o cron do worker

Na conta `www-data` (mesmo padrão dos outros workers do projeto):

```bash
crontab -u www-data -e
```

Adicionar:

```
* * * * * /usr/local/lsws/lsphp82/bin/php /home/homo-v2.sportmoto.com.br/public_html/workers/webhook-retry-worker.php --verbose >> /home/homo-v2.sportmoto.com.br/public_html/storage/logs/webhook-retry-worker.log 2>&1
```

Roda a cada minuto. Tem flock interno — múltiplos disparos não conflitam.

### 6. Testar end-to-end no sandbox

```bash
# 1. Cria uma cobrança PIX (que vai ficar pending no sandbox)
/usr/local/lsws/lsphp82/bin/php script/test_malga_pix.php
# anote o charge_id

# 2. Força status 'authorized' na Malga (simula pagamento)
curl -X POST "https://api.malga.io/v1/charges/6baa9e3f-3957-4262-a03e-7956cb91bef9/status" \ -H 'X-Client-Id: "f88629be-1490-468e-a1ce-9f3449f286aa"' \ -H "X-Api-Key: 9bdc5cba-aa4c-4945-9d27-1c30f333a9ca" \ -H "Content-Type: application/json" \ -d '{"status": "authorized"}'

curl.exe -X POST "https://api.malga.io/v1/charges/6baa9e3f-3957-4262-a03e-7956cb91bef9/status" `
-H "X-Client-Id: f88629be-1490-468e-a1ce-9f3449f286aa" `
-H "X-Api-Key: 9bdc5cba-aa4c-4945-9d27-1c30f333a9ca" `
-H "Content-Type: application/json" `
-d "{\"status\":\"authorized\"}"

curl.exe --location --request POST "https://sandbox-api.malga.io/v1/charges/6baa9e3f-3957-4262-a03e-7956cb91bef9" `
  --header "X-Client-Id: f88629be-1490-468e-a1ce-9f3449f286aa" `
  --header "X-Api-Key: 9bdc5cba-aa4c-4945-9d27-1c30f333a9ca" `
  --header "Content-Type: application/json" `
  --data-raw "{`"status`":`"authorized`"}"

  curl.exe --location --request POST "https://sandbox-api.malga.io/v1/charges/496f6a5e-8109-4df0-9f62-03bbc656c148" --header "X-Client-Id: f88629be-1490-468e-a1ce-9f3449f286aa" --header "X-Api-Key: 9bdc5cba-aa4c-4945-9d27-1c30f333a9ca" --header "Content-Type: application/json" --data-raw '{\"status\":\"authorized\"}'

# 3. Em ~5 segundos a Malga dispara o webhook. Confere:
mysql sportmoto_homo -e "SELECT id, tipo, processado, erro, recebido_em FROM pgto_webhook_log ORDER BY id DESC LIMIT 5"

# 4. E o pedido deve estar como aprovado:
mysql sportmoto_homo -e "SELECT codigo, status_pagamento, status_pedido, pago_em FROM pedidos ORDER BY id DESC LIMIT 5"
```

### 7. Rodar a suite de testes

```bash
/usr/local/lsws/lsphp82/bin/php tests/run_tests.php   # 24 testes
/usr/local/lsws/lsphp82/bin/php tests/run_e2e.php     # 6 testes
```

Tudo deve dar `OK: N    FAIL: 0`.

## Eventos tratados

Por padrão lidamos com tudo do objeto `transaction`. Outros objetos (seller, subscription) são marcados como processados sem efeito.

| Evento Malga | Status interno | Efeito no pedido |
|---|---|---|
| `transaction.pending` | `pendente` | aguardando_pagamento |
| `transaction.pre_authorized` | `pre_autorizado` | aguardando_pagamento |
| `transaction.authorized` | `aprovado` | **pagamento_aprovado** + confirma cupom |
| `transaction.failed` | `falhou` | aguardando_pagamento + libera estoque |
| `transaction.canceled` | `cancelado` | cancelado + libera estoque |
| `transaction.voided` | `estornado` | cancelado + libera estoque |
| `transaction.charged_back` | `chargeback` | cancelado + libera estoque |
| `transaction.refund_pending` | `estorno_pendente` | aguardando_pagamento |
| `transaction.revert_void` | `aprovado` | volta pra pagamento_aprovado |
| `transaction.dispute` | mantém atual | só loga |
| `transaction.dispute_closed` | mantém atual | só loga |

## Proteção contra out-of-order

A Malga garante ordem de envio, mas eventos podem chegar fora de ordem em casos extremos (retentativa de evento mais antigo). O processor compara `payload.createdAt` com `pgto_transacoes.atualizado_em`: se o evento é mais antigo, só aplica se for transição "natural" (ex: `pending → authorized` ainda OK; `aprovado → pending` é bloqueado).

## Próximas etapas

**Etapa 3 — SDK JS no front (PCI-compliant)**
Plugar o SDK da Malga em `payment-card-add.php` pro cartão nunca passar pelo nosso servidor. Hoje a tokenização vai via backend (`PaymentService::tokenizarCartao`), que funciona mas mantém o servidor no escopo PCI.

**Etapa 5 — Painel admin**
- Listagem de `pgto_transacoes` com filtros (método, status, gateway, período)
- Listagem de `pgto_webhook_log` com filtros (tipo, processado, erro) e botão "Reprocessar"
- Estorno manual com `AuthHelper::requirePermission('financeiro')`
- Métricas: taxa de aprovação por método/provedor (mesmo padrão do dashboard email v2)

**Etapa 6 — Hardening**
- Criptografar `api_key` e `webhook_public_key` em repouso (openssl AES-256-GCM)
- Rate limit no endpoint `/webhooks/malga` (10 req/s por IP)
- Alerta no LogService quando taxa de webhook em erro > 5%
- Health check endpoint pra monitorar se o worker tá rodando