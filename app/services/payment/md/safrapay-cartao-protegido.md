# SafraPay — Cartão Protegido (cofre) e cartão temporário

> Levantado da documentação de homologação em 04/09/2026
> (`developers-hml.safrapay.com.br`). **Nada verificado por chamada** — o IP
> ainda não foi liberado. Tudo aqui é documentação.

## Os dois caminhos, e por que não são intercambiáveis

A Safra tem **duas** formas de cobrar sem o PAN na autorização. Elas resolvem
problemas diferentes, e confundir as duas leva a prometer o que não dá:

| | Cofre (`card.id`) | Cartão temporário (`temporaryCardToken`) |
|---|---|---|
| Dura | permanente | **15 minutos** |
| Serve para | recompra, recorrência | **uma** autorização, agora |
| Como nasce | `POST /v2/card` com **PAN** | browser (`SafraPay Transparent`) ou `POST /v2/temporary/card` |
| Auth | Bearer JWT (servidor) | JWT **ou** `BcryptOrMerchantIdAuthorize` (navegador) |
| PAN no nosso servidor | **sim** | **não**, quando gerado no navegador |

**`POST /v2/card` NÃO aceita token no lugar do PAN** — conferido no schema do
endpoint. Cofre da Safra exige o número no nosso backend.

## Cofre — `POST /v2/card`

Bearer JWT. Exige `customerId` de um cliente já criado em `POST /v2/customer`.

```json
{
  "customerId": "ffede3ee-37ab-47bb-9971-d3d14697d67a",
  "card": {
    "cardNumber": "4111111111111111", "cvv": "123", "brand": 1,
    "cardholderName": "Maria Silva", "cardholderDocument": "12345678909",
    "expirationMonth": 12, "expirationYear": 2028
  }
}
```

Resposta 200 → `card.id` (UUID), `card.cardNumber` mascarado
(`411111******1111`), bandeira, titular, validade. Nunca devolve PAN nem CVV.

Cobrança depois: `transactions[].card.id`. O CVV **volta a ser pedido** na
primeira utilização do cartão numa cobrança — não fica no cofre.

Outros endpoints do cofre: `GET /v2/card/byCustomer` (listar),
`GET /v2/Card/Bin`, atualizar, e **`DELETE /v2/card/{cardId}`** — desativa
(`isActive = false`) e, quando o merchant tem tokenização de bandeira, tenta
excluir o token na rede.

> A Safra **tem** remoção. A Cielo não publica nenhuma para o Cartão
> Protegido. Ver `cielo-api-30.md`.

## Cartão temporário — `POST /v2/temporary/card`

```
Authorization: Bearer {accessToken}
   ou  BcryptOrMerchantIdAuthorize (credenciamento do checkout transparente)
```

Corpo: `cardNumber`, `cardholderName`, `cardholderDocument`,
`expirationMonth`, `expirationYear`, `cvv` (todos obrigatórios), `brand` e
`billingAddress` opcionais.

Resposta: `cardToken` — usar como `temporaryCardToken` na cobrança.

Da própria doc, sobre esse caminho:

> *"indicado para integradores **sem escopo PCI** no backend. O PAN é
> capturado no checkout (ex.: biblioteca **SafraPay Transparent** no browser)
> ou enviado uma única vez a `POST /v2/temporary/card`"*

E, no parâmetro da cobrança:

> *"Temporary card token returned by `POST /v2/temporary/card` or the
> **SafraPay Transparent JavaScript library** (`createTemporaryCard`)"*

Na autorização com `temporaryCardToken`: **não** enviar `card.cardNumber`,
validade nem titular — só o token e, se necessário, `card.cvv`. Incompatível
com PAN completo na mesma transação.

## O que isso significa para o multi-cofre

Ver `docs/sportmoto-os/12-decisoes-tecnicas/pagamentos-cartao-multi-adquirente.md`.

**Cartão digitado agora** → a Safra entra igual às outras. A biblioteca
`SafraPay Transparent` gera o token no navegador, o PAN não toca o servidor,
e o token vive 15 minutos — tempo de sobra para o checkout em curso. Se o
pagamento cair na Safra, ela cobra com `temporaryCardToken`. Sem redigitar,
sem mudar escopo PCI.

**Cartão salvo de uma sessão anterior** → a Safra **não** entra. O token de
15 minutos já morreu, e o cofre dela exige o PAN no nosso servidor (SAQ D),
que é além do SAQ A-EP decidido. O cartão salvo continua caindo só entre
Mercado Pago e Cielo.

> **Atualização 04/09 (noite):** isso vale para o multi-cofre. Com um cofre
> externo com proxy (ver a *Contestação* na decisão), a Safra entra também
> para cartão salvo: o proxy chama `POST /v2/temporary/card` com o alias, e
> o PAN continua sem tocar o servidor.

## Não verificado

- Nenhuma chamada foi feita — IP bloqueado (`216.238.113.56`, ref. Akamai
  `18.45841402.1787762697.38c2426`).
- **A biblioteca `SafraPay Transparent`**: não achei URL do script, assinatura
  de `createTemporaryCard`, nem como se obtém a credencial
  `BcryptOrMerchantIdAuthorize`. A doc cita a biblioteca mas não a documenta
  na página do Cartão Protegido — pedir ao suporte, ou procurar em
  `/checkout`.
- Se `brand` é o enum `CardBrandCode` (ver `/primeiros-passos#cardbrand`).
