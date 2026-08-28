# Save card

Store the card reference used by the customer in the payment securely on our servers to avoid asking for all the data in future transactions.

**POST** `/v1/customers/{customer_id}/cards`

## Request parameters

### Path

- `customer_id` (string, required)
  Customer's Id

- `token` (string, optional)
  Card Token

## Response parameters

- `id` (string, optional)
  id

- `expiration_month` (number, optional)
  expiration_month

- `expiration_year` (number, optional)
  expiration_year

- `first_six_digits` (string, optional)
  first_six_digits

- `last_four_digits` (string, optional)
  last_four_digits

- `payment_method` (object, optional)
  payment_method

  - `payment_method.id` (string, optional)
  id

  - `payment_method.name` (string, optional)
  name

  - `payment_method.payment_type_id` (string, optional)
  payment_type_id

  - `payment_method.thumbnail` (string, optional)
  thumbnail

  - `payment_method.secure_thumbnail` (string, optional)
  secure_thumbnail

- `security_code` (object, optional)
  security_code

  - `security_code.length` (number, optional)
  length

  - `security_code.card_location` (string, optional)
  card_location

- `issuer` (object, optional)
  issuer

  - `issuer.id` (number, optional)
  id

  - `issuer.name` (string, optional)
  name

- `cardholder` (object, optional)
  cardholder

  - `cardholder.name` (string, optional)
  name

  - `cardholder.identification` (object, optional)
  identification

  - `cardholder.identification.number` (string, optional)
  number

  - `cardholder.identification.type` (string, optional)
  type

- `date_created` (string, optional)
  date_created

- `date_last_updated` (string, optional)
  date_last_updated

- `customer_id` (string, optional)
  customer_id

- `user_id` (string, optional)
  user_id

- `live_mode` (boolean, optional)
  live_mode

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | 100 | the credentials are required. |
| 400 | 101 | the customer already exist. |
| 400 | 102 | missing customer id. |
| 400 | 103 | parameter must be an object |
| 400 | 104 | parameter length is too large. |
| 400 | 105 | the customer id is invalid. |
| 400 | 106 | the email format is invalid. |
| 400 | 107 | the first_name is invalid. |
| 400 | 108 | the last_name is invalid. |
| 400 | 109 | the phone.area_code is invalid. |
| 400 | 110 | the phone.number is invalid. |
| 400 | 111 | the identification.type is invalid. |
| 400 | 112 | the identification.number is invalid. |
| 400 | 113 | the address.zip_code is invalid. |
| 400 | 114 | the address.street_name is invalid. |
| 400 | 115 | the date_registered format is invalid. |
| 400 | 116 | the description is invalid. |
| 400 | 117 | the metadata is invalid. |
| 400 | 118 | the body must be a Json object |
| 400 | 119 | the card is required. |
| 400 | 120 | card not found. |
| 400 | 121 | the card is invalid. |
| 400 | 122 | the card data is invalid. |
| 400 | 123 | the payment_method_id is required. |
| 400 | 124 | the issuer_id is required. |
| 400 | 125 | invalid parameters. |
| 400 | 126 | invalid parameter. You cannot update the email. |
| 400 | 127 | invalid parameter. Cannot resolve the payment method of card, check the payment_method_id and issuer_id. |
| 400 | 128 | the email format is invalid. Use 'test_payer_[0-9]{1,10}@testuser.com'. |
| 400 | 129 | the customer has reached the maximum allowed number of cards. |
| 400 | 140 | invalid card owner. |
| 400 | 150 | invalid users involved. |
| 400 | 200 | invalid range format (range=:date_parameter:after::date_from,before::date_to). |
| 400 | 201 | range attribute must belong to date entity. |
| 400 | 202 | invalid 'after' parameter. It should be date[iso_8601]. |
| 400 | 203 | invalid 'before' parameter. It should be date[iso_8601]. |
| 400 | 204 | invalid filters format. |
| 400 | 205 | invalid query format. |
| 400 | 206 | attributes to sort must belong to 'customer' entity. |
| 400 | 207 | order filter must be 'asc' or 'desc'. |
| 400 | 208 | invalid 'sort' parameter format. |
| 401 | unauthorized | unauthorized. |
| 404 | cards API unavailable for legal reasons | Error returned when the email used by the payer requests cancellation from Mercado Pago. |
| 451 | Unavailable for legal reasons | Error returned when the email used by the payer requests cancellation from Mercado Pago. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/customers/{customer_id}/cards' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "token": "9b2d63e00d66a8c721607214ceda233a"
  }'
```

## Response example

```json
{
  "id": 1562188766852,
  "expiration_month": 6,
  "expiration_year": 2023,
  "first_six_digits": 423564,
  "last_four_digits": 5682,
  "payment_method": {
  "id": "visa",
  "name": "visa",
  "payment_type_id": "credit_card",
  "thumbnail": "http://img.mlstatic.com/org-img/MP3/API/logos/visa.gif",
  "secure_thumbnail": "https://www.mercadopago.com/org-img/MP3/API/logos/visa.gif"
  },
  "security_code": {
  "length": 3,
  "card_location": "back"
  },
  "issuer": {
  "id": 25,
  "name": "visa"
  },
  "cardholder": {
  "name": "APRO",
  "identification": {
  "number": 19119119100,
  "type": "CPF"
  }
  },
  "date_created": "2019-07-03T21:15:35.000Z",
  "date_last_updated": "2019-07-03T21:19:18.000Z",
  "customer_id": "448870796-7ZjwhKGxILixxN",
  "user_id": 448870796,
  "live_mode": true
}
```

# Get customer cards

Consult a client's saved cards in order to be able to show them when making a payment.

**GET** `/v1/customers/{customer_id}/cards`

## Request parameters

### Path

- `customer_id` (string, required)
  Customer's Id

## Response parameters

This endpoint has no response body.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | 100 | the credentials are required. |
| 400 | 101 | the customer already exist. |
| 400 | 102 | missing customer id. |
| 400 | 103 | parameter must be an object |
| 400 | 104 | parameter length is too large. |
| 400 | 105 | the customer id is invalid. |
| 400 | 106 | the email format is invalid. |
| 400 | 107 | the first_name is invalid. |
| 400 | 108 | the last_name is invalid. |
| 400 | 109 | the phone.area_code is invalid. |
| 400 | 110 | the phone.number is invalid. |
| 400 | 111 | the identification.type is invalid. |
| 400 | 112 | the identification.number is invalid. |
| 400 | 113 | the address.zip_code is invalid. |
| 400 | 114 | the address.street_name is invalid. |
| 400 | 115 | the date_registered format is invalid. |
| 400 | 116 | the description is invalid. |
| 400 | 117 | the metadata is invalid. |
| 400 | 118 | the body must be a Json object |
| 400 | 119 | the card is required. |
| 400 | 120 | card not found. |
| 400 | 121 | the card is invalid. |
| 400 | 122 | the card data is invalid. |
| 400 | 123 | the payment_method_id is required. |
| 400 | 124 | the issuer_id is required. |
| 400 | 125 | invalid parameters. |
| 400 | 126 | invalid parameter. You cannot update the email. |
| 400 | 127 | invalid parameter. Cannot resolve the payment method of card, check the payment_method_id and issuer_id. |
| 400 | 128 | the email format is invalid. Use 'test_payer_[0-9]{1,10}@testuser.com'. |
| 400 | 129 | the customer has reached the maximum allowed number of cards. |
| 400 | 140 | invalid card owner. |
| 400 | 150 | invalid users involved. |
| 400 | 200 | invalid range format (range=:date_parameter:after::date_from,before::date_to). |
| 400 | 201 | range attribute must belong to date entity. |
| 400 | 202 | invalid 'after' parameter. It should be date[iso_8601]. |
| 400 | 203 | invalid 'before' parameter. It should be date[iso_8601]. |
| 400 | 204 | invalid filters format. |
| 400 | 205 | invalid query format. |
| 400 | 206 | attributes to sort must belong to 'customer' entity. |
| 400 | 207 | order filter must be 'asc' or 'desc'. |
| 400 | 208 | invalid 'sort' parameter format. |
| 401 | unauthorized | unauthorized. |
| 404 | not_found | not_found. |

## Request example

### cURL

```bash
curl -X GET \
  'https://api.mercadopago.com/v1/customers/{customer_id}/cards' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
[
  {
  "id": "277090284",
  "date_registered": "string",
  "date_created": "2018-07-18T13:28:32.000-04:00",
  "date_last_updated": "2018-08-07T12:21:32.243-04:00",
  "customer_id": "329653963-byLEdchVPhPc4H",
  "expiration_month": 11,
  "expiration_year": 2020,
  "first_six_digits": "423564",
  "last_four_digits": "5682",
  "payment_method": {
  "id": "visa",
  "name": "visa",
  "payment_type_id": "credit_card",
  "thumbnail": "http://img.mlstatic.com/org-img/MP3/API/logos/visa.gif",
  "secure_thumbnail": "https://www.mercadopago.com/org-img/MP3/API/logos/visa.gif"
  },
  "security_code": {
  "length": 3,
  "card_location": "back"
  },
  "issuer": {
  "id": 25,
  "name": "visa"
  },
  "cardholder": {
  "name": "APRO",
  "identification": {
  "number": "19119119100",
  "subtype": "string",
  "type": "CPF"
  }
  },
  "user_id": "12123adfasdf123u4u",
  "live_mode": true
  }
]
```

# Update card

Renew the details of a card associated with a customer. Indicate the IDs and send the parameters with the information you want to update.

**PUT** `/v1/customers/{customer_id}/cards/{id}`

## Request parameters

- `expiration_month` (number, optional)
  Card's expiration month.

- `expiration_year` (number, optional)
  Card's expiration year.

- `cardholder` (object, optional)
  Card holder data.

  - `cardholder.name` (string, optional)
  Card holder name.

  - `cardholder.identification` (object, optional)
  Card holder identification.

  - `cardholder.identification.type` (string, optional)
  Identification type.

  - `cardholder.identification.number` (string, optional)
  Identification number.

## Response parameters

- `id` (string, optional)

- `expiration_month` (number, optional)

- `expiration_year` (number, optional)

- `first_six_digits` (string, optional)

- `last_four_digits` (string, optional)

- `payment_method` (object, optional)

  - `payment_method.id` (string, optional)

  - `payment_method.name` (string, optional)

  - `payment_method.payment_type_id` (string, optional)

  - `payment_method.thumbnail` (string, optional)

  - `payment_method.secure_thumbnail` (string, optional)

- `security_code` (object, optional)

  - `security_code.length` (number, optional)

  - `security_code.card_location` (string, optional)

- `issuer` (object, optional)

  - `issuer.id` (number, optional)

  - `issuer.name` (string, optional)

- `cardholder` (object, optional)

  - `cardholder.name` (string, optional)

  - `cardholder.identification` (object, optional)

  - `cardholder.identification.number` (string, optional)

  - `cardholder.identification.type` (string, optional)

- `date_created` (string, optional)

- `date_last_updated` (string, optional)

- `customer_id` (string, optional)

- `user_id` (string, optional)

- `live_mode` (boolean, optional)

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | 100 | the credentials are required. |
| 400 | 101 | the customer already exist. |
| 400 | 102 | missing customer id. |
| 400 | 103 | parameter must be an object |
| 400 | 104 | parameter length is too large. |
| 400 | 105 | the customer id is invalid. |
| 400 | 106 | the email format is invalid. |
| 400 | 107 | the first_name is invalid. |
| 400 | 108 | the last_name is invalid. |
| 400 | 109 | the phone.area_code is invalid. |
| 400 | 110 | the phone.number is invalid. |
| 400 | 111 | the identification.type is invalid. |
| 400 | 112 | the identification.number is invalid. |
| 400 | 113 | the address.zip_code is invalid. |
| 400 | 114 | the address.street_name is invalid. |
| 400 | 115 | the date_registered format is invalid. |
| 400 | 116 | the description is invalid. |
| 400 | 117 | the metadata is invalid. |
| 400 | 118 | the body must be a Json object |
| 400 | 119 | the card is required. |
| 400 | 120 | card not found. |
| 400 | 121 | the card is invalid. |
| 400 | 122 | the card data is invalid. |
| 400 | 123 | the payment_method_id is required. |
| 400 | 124 | the issuer_id is required. |
| 400 | 125 | invalid parameters. |
| 400 | 126 | invalid parameter. You cannot update the email. |
| 400 | 127 | invalid parameter. Cannot resolve the payment method of card, check the payment_method_id and issuer_id. |
| 400 | 128 | the email format is invalid. Use 'test_payer_[0-9]{1,10}@testuser.com'. |
| 400 | 129 | the customer has reached the maximum allowed number of cards. |
| 400 | 140 | invalid card owner. |
| 400 | 150 | invalid users involved. |
| 400 | 200 | invalid range format (range=:date_parameter:after::date_from,before::date_to). |
| 400 | 201 | range attribute must belong to date entity. |
| 400 | 202 | invalid 'after' parameter. It should be date[iso_8601]. |
| 400 | 203 | invalid 'before' parameter. It should be date[iso_8601]. |
| 400 | 204 | invalid filters format. |
| 400 | 205 | invalid query format. |
| 400 | 206 | attributes to sort must belong to 'customer' entity. |
| 400 | 207 | order filter must be 'asc' or 'desc'. |
| 400 | 208 | invalid 'sort' parameter format. |
| 401 | unauthorized | unauthorized. |
| 404 | not_found | not_found. |

## Request example

### cURL

```bash
curl -X PUT \
  'https://api.mercadopago.com/v1/customers/{customer_id}/cards/{id}' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "expiration_month": 12,
  "expiration_year": 2025,
  "cardholder": {
  "name": "João Silva",
  "identification": {
  "type": "CPF",
  "number": "27009907"
  }
  }
  }'
```

## Response example

```json
{
  "id": "8987269652",
  "expiration_month": 7,
  "expiration_year": 2023,
  "first_six_digits": "503143",
  "last_four_digits": "6351",
  "payment_method": {
  "id": "visa",
  "name": "visa",
  "payment_type_id": "credit_card",
  "thumbnail": "http://img.mlstatic.com/org-img/MP3/API/logos/master.gif",
  "secure_thumbnail": "https://www.mercadopago.com/org-img/MP3/API/logos/master.gif"
  },
  "security_code": {
  "length": 3,
  "card_location": "back"
  },
  "issuer": {
  "id": 24,
  "name": "visa"
  },
  "cardholder": {
  "name": "APRO",
  "identification": {
  "number": "19119119100",
  "type": "CPF"
  }
  },
  "date_created": "2021-03-16T16:08:21.000-04:00",
  "date_last_updated": "2021-03-16T16:11:54.294-04:00",
  "customer_id": "470183340-cpunOI7UsIHlHr",
  "user_id": "470183340",
  "live_mode": true
}
```

# Delete card

Delete the data of a card associated with a customer whenever you need to.

**DELETE** `/v1/customers/{customer_id}/cards/{id}`

## Request parameters

### Path

- `customer_id` (string, required)
  Customer's Id

- `id` (string, required)
  Card's Id

## Response parameters

- `id` (string, optional)

- `expiration_month` (number, optional)

- `expiration_year` (number, optional)

- `first_six_digits` (string, optional)

- `last_four_digits` (string, optional)

- `payment_method` (object, optional)

  - `payment_method.id` (string, optional)

  - `payment_method.name` (string, optional)

  - `payment_method.payment_type_id` (string, optional)

  - `payment_method.thumbnail` (string, optional)

  - `payment_method.secure_thumbnail` (string, optional)

- `security_code` (object, optional)

  - `security_code.length` (number, optional)

  - `security_code.card_location` (string, optional)

- `issuer` (object, optional)

  - `issuer.id` (number, optional)

  - `issuer.name` (string, optional)

- `cardholder` (object, optional)

  - `cardholder.name` (string, optional)

  - `cardholder.identification` (object, optional)

  - `cardholder.identification.number` (string, optional)

  - `cardholder.identification.type` (string, optional)

- `date_created` (string, optional)

- `date_last_updated` (string, optional)

- `customer_id` (string, optional)

- `user_id` (string, optional)

- `live_mode` (boolean, optional)

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | 100 | the credentials are required. |
| 400 | 101 | the customer already exist. |
| 400 | 102 | missing customer id. |
| 400 | 103 | parameter must be an object |
| 400 | 104 | parameter length is too large. |
| 400 | 105 | the customer id is invalid. |
| 400 | 106 | the email format is invalid. |
| 400 | 107 | the first_name is invalid. |
| 400 | 108 | the last_name is invalid. |
| 400 | 109 | the phone.area_code is invalid. |
| 400 | 110 | the phone.number is invalid. |
| 400 | 111 | the identification.type is invalid. |
| 400 | 112 | the identification.number is invalid. |
| 400 | 113 | the address.zip_code is invalid. |
| 400 | 114 | the address.street_name is invalid. |
| 400 | 115 | the date_registered format is invalid. |
| 400 | 116 | the description is invalid. |
| 400 | 117 | the metadata is invalid. |
| 400 | 118 | the body must be a Json object |
| 400 | 119 | the card is required. |
| 400 | 120 | card not found. |
| 400 | 121 | the card is invalid. |
| 400 | 122 | the card data is invalid. |
| 400 | 123 | the payment_method_id is required. |
| 400 | 124 | the issuer_id is required. |
| 400 | 125 | invalid parameters. |
| 400 | 126 | invalid parameter. You cannot update the email. |
| 400 | 127 | invalid parameter. Cannot resolve the payment method of card, check the payment_method_id and issuer_id. |
| 400 | 128 | the email format is invalid. Use 'test_payer_[0-9]{1,10}@testuser.com'. |
| 400 | 129 | the customer has reached the maximum allowed number of cards. |
| 400 | 140 | invalid card owner. |
| 400 | 150 | invalid users involved. |
| 400 | 200 | invalid range format (range=:date_parameter:after::date_from,before::date_to). |
| 400 | 201 | range attribute must belong to date entity. |
| 400 | 202 | invalid 'after' parameter. It should be date[iso_8601]. |
| 400 | 203 | invalid 'before' parameter. It should be date[iso_8601]. |
| 400 | 204 | invalid filters format. |
| 400 | 205 | invalid query format. |
| 400 | 206 | attributes to sort must belong to 'customer' entity. |
| 400 | 207 | order filter must be 'asc' or 'desc'. |
| 400 | 208 | invalid 'sort' parameter format. |
| 401 | unauthorized | unauthorized. |
| 404 | not_found | not_found. |

## Request example

### cURL

```bash
curl -X DELETE \
  'https://api.mercadopago.com/v1/customers/{customer_id}/cards/{id}' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "id": "8987269652",
  "expiration_month": 7,
  "expiration_year": 2023,
  "first_six_digits": "503143",
  "last_four_digits": "6351",
  "payment_method": {
  "id": "visa",
  "name": "visa",
  "payment_type_id": "credit_card",
  "thumbnail": "http://img.mlstatic.com/org-img/MP3/API/logos/master.gif",
  "secure_thumbnail": "https://www.mercadopago.com/org-img/MP3/API/logos/master.gif"
  },
  "security_code": {
  "length": 3,
  "card_location": "back"
  },
  "issuer": {
  "id": 24,
  "name": "visa"
  },
  "cardholder": {
  "name": "APRO",
  "identification": {
  "number": "19119119100",
  "type": "CPF"
  }
  },
  "date_created": "2021-03-16T16:08:21.000-04:00",
  "date_last_updated": "2021-03-16T16:14:40.962-04:00",
  "customer_id": "470183340-cpunOI7UsIHlHr",
  "user_id": "470183340",
  "live_mode": true
}
```