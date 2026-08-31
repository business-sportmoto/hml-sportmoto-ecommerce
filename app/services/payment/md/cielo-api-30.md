# Cielo — API E-commerce 3.0

> Levantado da documentação oficial em 29/08/2026 e **validado contra o
> sandbox real em 31/08/2026**. O que foi confirmado por chamada está marcado
> com ✅; o resto é documentação e não deve ser assumido.

## Produto certo

As credenciais do `.env` (`CIELO_MERCHANT_ID`, `CIELO_MERCHANT_KEY`,
`CIELO_MERCHANT_CATEGORY_CODE`) são da **API E-commerce 3.0** — a que recebe a
transação por API e devolve o instrumento.

**Não confundir com o Cielo Link / Checkout Cielo**
(`docs.cielo.com.br/link/...`, base `cieloecommerce.cielo.com.br/api/public`,
`POST /v1/products`): aquele é checkout hospedado, o cliente sai da loja e a
Cielo é quem decide. Não cabe no motor de fluxos — sem classificação por
tentativa, sem antifraude no meio, sem queda para outra adquirente.

## Bases

| Ambiente | Transação | Consulta |
|---|---|---|
| Produção | `https://api.cieloecommerce.cielo.com.br` | `https://apiquery.cieloecommerce.cielo.com.br` |
| Sandbox | `https://apisandbox.cieloecommerce.cielo.com.br` | `https://apiquerysandbox.cieloecommerce.cielo.com.br` |

O sandbox usa credenciais PRÓPRIAS, obtidas no portal da Cielo — não as de
produção.

## Autenticação

Headers, em toda chamada:

```
MerchantId:   <uuid>
MerchantKey:  <40 chars>
Content-Type: application/json
RequestId:    <uuid>      (opcional, ajuda no suporte)
```

Sem OAuth, sem token de sessão.

## Endpoints

| Método | Caminho | Para quê |
|---|---|---|
| POST | `/1/sales` | cria a venda (crédito, Pix, boleto) |
| PUT | `/1/sales/{PaymentId}/capture?amount={centavos}` | captura |
| PUT | `/1/sales/{PaymentId}/void?amount={centavos}` | cancela / estorna |
| GET | `/1/sales/{PaymentId}` | consulta (na base de CONSULTA) |
| GET | `/1/sales?merchantOrderId={id}` | consulta pelo nosso código |
| POST | `/1/card` | tokeniza cartão (servidor-a-servidor) |

## Cartão de crédito

`POST /1/sales` — [doc](https://docs.cielo.com.br/ecommerce-cielo/reference/criar-pagamento-credito.md)

```json
{
  "MerchantOrderId": "2017051001",
  "Customer": {
    "Name": "Aline de Souza", "Identity": "12345678909",
    "IdentityType": "CPF", "Email": "aline@email.com"
  },
  "Payment": {
    "Type": "CreditCard", "Amount": 10000, "Currency": "BRL", "Country": "BRA",
    "Installments": 1, "Interest": "ByMerchant",
    "Capture": true, "Authenticate": false, "Recurrent": false,
    "SoftDescriptor": "LojaTeste",
    "CreditCard": {
      "CardNumber": "4091688625337641", "Holder": "Aline de Souza",
      "ExpirationDate": "12/2035", "SecurityCode": "333",
      "Brand": "Visa", "SaveCard": false
    }
  }
}
```

`Amount` em **centavos**. `Capture: false` = pré-autorização (é o que o modo
`pre_captura` do antifraude precisa).

Resposta 201, campos que importam:

```json
"Payment": {
  "PaymentId": "6f8d1753-...", "Tid": "1124060407175",
  "ProofOfSale": "182738", "AuthorizationCode": "663864",
  "Status": 2, "ReturnCode": "6", "ReturnMessage": "Operation Successful"
}
```

### ⚠️ O PAN passa pelo nosso servidor

`CardNumber` e `SecurityCode` vão no corpo da requisição — **não existe
tokenização no navegador** nesta API. O `/1/card` também é servidor-a-servidor.

Isso é diferente do Mercado Pago, onde os Secure Fields mantêm o PAN fora do
nosso DOM e do nosso servidor. Rotear cartão para a Cielo tira a loja do
escopo PCI mais simples (SAQ A) e a leva para SAQ A-EP/D. **Decisão de
negócio, não técnica** — por isso o adapter nasce com o cartão disponível mas
o fluxo é quem decide se manda tráfego para lá.

Pix e boleto **não** têm essa implicação: não trafegam dado de cartão.

## Pix

`POST /1/sales` — [doc](https://docs.cielo.com.br/ecommerce-cielo/reference/qrcode-pix.md)

```json
{
  "MerchantOrderId": "Loja123456",
  "Customer": { "Name": "Nome do Pagador", "Identity": "12345678909", "IdentityType": "CPF" },
  "Payment": { "Type": "Pix", "Amount": 100 }
}
```

Resposta:

```json
"Payment": {
  "Paymentid": "1997be4d-...",
  "QrCodeString": "00020101021226880014br.gov.bcb.pix2566...",
  "QrcodeBase64Image": "rfhviy64ak+zse18cwcmtg==...",
  "Status": 12, "ReturnCode": "0", "ReturnMessage": "Pix gerado com sucesso"
}
```

**Duas armadilhas de nome, ambas na resposta:**
- `Paymentid` (i minúsculo) no Pix, `PaymentId` no cartão e no boleto.
- `QrCodeString` (C maiúsculo) mas `QrcodeBase64Image` (c minúsculo).

Ler as duas grafias é obrigatório — errar uma devolve campo vazio sem erro.

A imagem vem em base64 **cru**, sem o prefixo `data:` (mesmo caso do Mercado
Pago).

A documentação **não descreve como definir expiração** do QR. Não inventar
campo: até confirmar, o Pix da Cielo usa o padrão deles.

## Boleto

`POST /1/sales` — [doc](https://docs.cielo.com.br/ecommerce-cielo/reference/boleto-api.md)

Exige `Customer.Address` completo (Street, Number, ZipCode, District, City,
State, Country) e, no `Payment`: `Provider`, `BoletoNumber`, `Assignor`,
`Demonstrative`, `ExpirationDate` (`YYYY-MM-DD`), `Identification` (CNPJ do
cedente), `Instructions`.

Resposta: `DigitableLine`, `BarCodeNumber`, `Url`, `Status: 1`.

`Provider` depende do banco contratado (ex.: `Bradesco2`, `Itau2`). **Não há
padrão** — precisa vir da configuração da adquirente.

## Status

| Status | Significado |
|---|---|
| 0 | Não finalizado |
| 1 | Autorizado (boleto emitido) |
| 2 | Pago / capturado |
| 3 | Negado |
| 10 | Cancelado |
| 11 | Devolvido / estornado |
| 12 | Pix gerado |
| 13 | Abortado |
| 20 | Recorrência agendada |

## ReturnCode

[doc](https://docs.cielo.com.br/ecommerce-cielo/reference/api-codes.md)

- `00` / `4` — autorizada
- `6` — capturada
- `0` — sucesso (Pix, boleto)
- `9` — cancelamento/estorno aprovado
- `100`–`323` — erro de validação nosso (campo obrigatório, formato, afiliação)

Para **recusa de emissor**, a própria Cielo remete à **tabela ABECS** — a
mesma que `PagamentoErroClassifier::ABECS` já implementa para a Safra. Por
isso o adapter da Cielo reaproveita aquele mapa em vez de montar o seu: dois
mapas do mesmo padrão divergem, e divergir aqui é retentar uma negativa de
emissor e tomar multa de bandeira.

## Sandbox — como testar ✅

Credenciais **próprias**, do portal da Cielo. No `.env` elas vivem como
`SANDBOX_CIELO_MERCHANT_ID` / `SANDBOX_CIELO_MERCHANT_KEY`; o
`PagamentoCredencialService` aceita essa forma e também `CIELO_TEST_*`.

**O ambiente é decidido pela coluna `pgto_gateways.sandbox`**, não pelo
`.env`. Antes de qualquer teste, conferir — apontar credencial de sandbox
para o host de produção falha na autenticação (inofensivo), mas o inverso
cobra cartão de verdade.

### O dígito final do cartão comanda o desfecho ✅

Os 15 primeiros dígitos são livres. Verificado com `402400715376319X`:

| Final | Status | ReturnCode | Mensagem da Cielo | Porta do motor |
|---|---|---|---|---|
| 0, 1, 4 | 2 | 6 | Operation Successful | `aprovado` |
| 2 | 3 | 05 | Not Authorized | `negado_generico` |
| 3 | 3 | 57 | Card Expired | `negado_generico` |
| 5 | 3 | 78 | Blocked Card | `negado_dados` |
| 6 | 0 | 99 | Timeout | `incerto` (consulta) |
| 7 | 3 | 77 | Card Canceled | `negado_dados` |
| 8 | 3 | 70 | Problems with Creditcard | `negado_generico` |
| 9 | — | 6 ou 9 | aleatório | — |

Nenhuma recusa libera queda para outra adquirente. O timeout (99) vem com
`Status 0`, e por isso vira `incerto` com consulta — não é decisão do
emissor, então recusar seria errado e retentar às cegas seria pior.

Os códigos 70, 77 e 78 foram acrescentados à tabela ABECS compartilhada a
partir dessas respostas reais.

### Fluxo completo verificado ✅

| Operação | Resultado |
|---|---|
| Autorizar com captura | 201, Status 2, `aprovado` |
| Pré-autorizar (`Capture:false`) | 201, Status 1, ReturnCode 4, `aprovado` |
| Capturar | 200, ReturnCode 6 |
| Cancelar | 200 |
| Consultar | 200 (na base `apiquerysandbox`) |
| Pix | 201, Status 12, copia-e-cola + imagem |
| Boleto | 201, Status 1 → `pendente`, linha digitável + URL |

### Regras específicas do sandbox ✅

- **Boleto:** `Payment.Provider` tem de ser **`Simulado`**. Em produção isso
  não existe — o adapter bloqueia com mensagem clara em vez de deixar a
  Cielo recusar sem explicar.
- **Pix:** o status é sempre `12` (pendente) e **não há como marcar como
  pago**. A baixa só pode ser exercitada em produção.

## Notificação (Post de Notificação) ✅ contrato, ⏳ não exercitada

`POST` com `Content-Type: application/json`:

```json
{ "PaymentId": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx", "ChangeType": 1 }
```

`ChangeType`: 1 mudança de status · 2 recorrência criada · 3 antifraude ·
4 status de recorrência · 5 cancelamento negado · 6 boleto pago a menor ·
7 chargeback · 8 alerta de fraude · 25 cancelamento parcial.

Responder **200**. Reenvio a cada 30 minutos, mais três tentativas.

**NÃO HÁ ASSINATURA.** A Cielo só oferece até 3 headers fixos configuráveis
no painel — qualquer um que os descubra repete. Por isso o
`CieloRetornoService` trata o aviso como "algo mudou" e vai **consultar a
API** para saber o quê. Confiar no corpo deixaria um POST forjado aprovar
pedido.

## O que NÃO foi verificado

- Expiração do Pix (campo não documentado na página consultada).
- Lista completa de `Provider` de boleto (depende do contrato).
- Comportamento do 3DS: existe documentação separada
  ([3ds-sobre](https://docs.cielo.com.br/ecommerce-cielo/docs/3ds-sobre.md)),
  mas o fluxo da Cielo autentica **no navegador antes** da autorização e
  manda o resultado no `Payment.ExternalAuthentication` — modelo diferente do
  Mercado Pago. Não implementado ainda.
- **Baixa de Pix e boleto**: o sandbox não deixa marcar como pago, então o
  `CieloRetornoService` foi exercitado com um pagamento de CARTÃO
  (aplicou, reenvio ficou `inalterado`, id desconhecido não quebrou). O
  caminho é o mesmo, mas Pix e boleto de verdade só em produção.
- **3DS**: a Cielo autentica no navegador ANTES da autorização e recebe o
  resultado em `Payment.ExternalAuthentication` — modelo diferente do
  Mercado Pago. Não implementado.
- **Providers de boleto em produção**: dependem do banco contratado.
