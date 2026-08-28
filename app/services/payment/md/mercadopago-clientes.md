# Create customer

Create a customer with all its data and save the cards used to simplify the payment process.

**POST** `/v1/customers`

## Request parameters

- `email` (string, optional)
  Customer's email.

- `first_name` (string, optional)
  Customer's name.

- `last_name` (string, optional)
  Customer's last name.

- `phone` (object, optional)
  Customer phone's information.

  - `phone.area_code` (string, optional)
  Phone's area code.

  - `phone.number` (string, optional)
  Phone number.

- `identification` (object, optional)
  Customer identification's information.

  - `identification.type` (string, optional)
  Identification type.

  - `identification.number` (string, optional)
  Identification number.

- `default_address` (string, optional)
  Customer's default address.

- `address` (object, optional)
  Default address's information.

  - `address.id` (string, optional)
  Address ID.

  - `address.zip_code` (string, optional)
  Zip code.

  - `address.street_name` (string, optional)
  Street name.

  - `address.street_number` (number, optional)
  Street number.

  - `address.city` (object, optional)
  City.

  - `address.city.name` (string, optional)
  City name. For example, Autonomous City of Buenos Aires.

- `date_registered` (string, optional)
  Customer's registration date.

- `description` (string, optional)
  Customer's description.

- `default_card` (string, optional)
  Customer's default card.

## Response parameters

- `id` (string, optional)
  Customer ID.

- `email` (string, optional)
  Customer's email.

- `first_name` (string, optional)
  Customer's name.

- `last_name` (string, optional)
  Customer's last name.

- `phone` (object, optional)
  Customer phone's information.

  - `phone.area_code` (string, optional)
  Phone's area code.

  - `phone.number` (string, optional)
  Phone number.

- `identification` (object, optional)
  Customer identification's information.

  - `identification.type` (string, optional)
  Identification type.

  - `identification.number` (string, optional)
  Identification number.

- `address` (object, optional)
  Default address's information.

  - `address.id` (string, optional)
  Address ID.

  - `address.zip_code` (string, optional)
  Zip code.

  - `address.street_name` (string, optional)
  Street name.

  - `address.street_number` (number, optional)
  Street number.

- `date_registered` (string, optional)
  Customer's registration date.

- `description` (string, optional)
  Customer's description.

- `date_created` (string, optional)
  Customer's date created.

- `date_last_updated` (string, optional)
  Last modified date.

- `metadata` (object, optional)
  metadata

  - `metadata.source_sync` (string, optional)

- `default_card` (string, optional)
  Customer's default card.

- `default_address` (string, optional)
  Customer's default address.

- `cards` (array, optional)
  Customer's cards.

- `addresses` (array, optional)
  Customer's addresses.

- `live_mode` (boolean, optional)
  Whether the customers will be in sandbox or in production mode.

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
| 400 | 118 | the body must be a Json Object. |
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
| 400 | 214 | invalid zip code. |
| 401 | unauthorized | unauthorized. |
| 404 | not_found | not_found. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/customers' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "email": "test@testuser.com",
  "first_name": "João",
  "last_name": "Silva",
  "phone": {
  "area_code": "11",
  "number": "98765-4321"
  },
  "identification": {
  "type": "CPF",
  "number": "12345678900"
  },
  "default_address": "Home",
  "address": {
  "id": "123123",
  "zip_code": "06233-903",
  "street_name": "Rua Exemplo",
  "street_number": 123,
  "city": {
  "name": "string"
  }
  },
  "date_registered": "2021-10-20T11:37:30.000-04:00",
  "description": "Description del user",
  "default_card": "None"
  }'
```

## Response example

```json
{
  "id": "000000001-sT93QZFAsfxU9P5",
  "email": "test@testuser.com",
  "first_name": "João",
  "last_name": "Silva",
  "phone": {
  "area_code": "11",
  "number": "98765-4321"
  },
  "identification": {
  "type": "CPF",
  "number": 12345678
  },
  "address": {
  "id": "1162600094",
  "zip_code": "06233-903",
  "street_name": "Old Knebworth Ln",
  "street_number": 0
  },
  "date_registered": "string",
  "description": "This is my description",
  "date_created": "2018-02-20T15:36:23.541Z",
  "date_last_updated": "string",
  "metadata": {
  "source_sync": "source_ws"
  },
  "default_card": "string",
  "default_address": "1162600094",
  "cards": [
  {}
  ],
  "addresses": [
  {}
  ],
  "live_mode": true
}
```

# Search customers

Find all customer information using specific filters.

**GET** `/v1/customers/search`

## Request parameters

### Query

- `email` (string, required)
  Customer's email.

## Response parameters

- `paging` (object, optional)
  Information for pagination of search results.

  - `paging.limit` (number, optional)
  The maximum number of entries to return.

  - `paging.offset` (number, optional)
  The offset of the first item in the collection to return.

  - `paging.total` (number, optional)
  The total number of items in the collection.

- `results` (array, optional)
  The page of items. This will be an empty array if there are no results.

  - `results[].address` (object, optional)
  Default address's information.

  - `results[].address.id` (string, optional)
  Address ID.

  - `results[].address.street_name` (string, optional)
  Street name.

  - `results[].address.street_number` (number, optional)
  Street number.

  - `results[].address.zip_code` (string, optional)
  Zip code.

  - `results[].addresses` (array, optional)
  Customer's addresses.

  - `results[].cards` (array, optional)
  Customer's cards.

  - `results[].date_created` (string, optional)
  Customer's date created.

  - `results[].date_last_updated` (string, optional)
  Last modified date.

  - `results[].date_registered` (string, optional)
  Customer's registration date.

  - `results[].default_address` (string, optional)
  Customer's default address.

  - `results[].default_card` (string, optional)
  Customer's default card.

  - `results[].description` (string, optional)
  Customer's description.

  - `results[].email` (string, optional)
  Customer's email.

  - `results[].first_name` (string, optional)
  Customer's name.

  - `results[].id` (string, optional)
  Customer ID.

  - `results[].identification` (object, optional)
  Customer identification's information.

  - `results[].identification.number` (string, optional)
  Identification number.

  - `results[].identification.type` (string, optional)
  Identification type.

  - `results[].last_name` (string, optional)
  Customer's last name.

  - `results[].live_mode` (boolean, optional)
  Whether the customers will be in sandbox or in production mode.

  - `results[].metadata` (object, optional)
  metadata

  - `results[].phone` (object, optional)
  Customer phone's information.

  - `results[].phone.area_code` (string, optional)
  Phone's area code.

  - `results[].phone.number` (string, optional)
  Phone number.

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
| 400 | 118 | the body must be a Json Object. |
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
| 400 | 214 | invalid zip code. |
| 401 | unauthorized | unauthorized. |
| 404 | not_found | not_found. |

## Request example

### cURL

```bash
curl -X GET \
  'https://api.mercadopago.com/v1/customers/search?email=<EMAIL>' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "paging": {
  "limit": 10,
  "offset": 0,
  "total": 1
  },
  "results": [
  {
  "address": {
  "id": "1162600213",
  "street_name": "Caetano Poli, 12",
  "street_number": 0,
  "zip_code": "05187010"
  },
  "addresses": [
  {}
  ],
  "cards": [
  {}
  ],
  "date_created": "2017-05-05T04:00:00.000Z",
  "date_last_updated": "2017-05-05T13:23:25.021Z",
  "date_registered": "string",
  "default_address": "1162600213",
  "default_card": 1493990563105,
  "description": "string",
  "email": "test@testuser.com",
  "first_name": "Customer",
  "id": "123456789-jxOV430go9fx2e",
  "identification": {
  "number": "19119119100",
  "type": "CPF"
  },
  "last_name": "Tester",
  "live_mode": true,
  "metadata": {},
  "phone": {
  "area_code": "11",
  "number": "987654321"
  }
  }
  ]
}
```

# Get customer

Check all the information of a client created with the client ID of your choice.

**GET** `/v1/customers/{id}`

## Request parameters

### Path

- `id` (number, required)
  Customer ID.

## Response parameters

- `id` (string, optional)
  Customer ID.

- `email` (string, optional)
  Customer's email.

- `first_name` (string, optional)
  Customer's name.

- `last_name` (string, optional)
  Customer's last name.

- `phone` (object, optional)
  Customer phone's information.

  - `phone.area_code` (string, optional)
  Phone's area code.

  - `phone.number` (string, optional)
  Phone number.

- `identification` (object, optional)
  Customer identification's information.

  - `identification.type` (string, optional)
  Identification type.

  - `identification.number` (string, optional)
  Identification number.

- `address` (object, optional)
  Default address's information.

  - `address.id` (string, optional)
  Address ID.

  - `address.zip_code` (string, optional)
  Zip code.

  - `address.street_name` (string, optional)
  Street name.

  - `address.street_number` (number, optional)
  Street number.

- `date_registered` (string, optional)
  Customer's registration date.

- `description` (string, optional)
  Customer's description.

- `date_created` (string, optional)
  Customer's date created.

- `date_last_updated` (string, optional)
  Last modified date.

- `metadata` (object, optional)
  Customer's metadata.

  - `metadata.source_sync` (string, optional)

- `default_card` (string, optional)
  Customer's default card.

- `default_address` (string, optional)
  Customer's default address.

- `cards` (object, optional)
  Customer's cards.

  - `cards.id` (string, optional)
  Card ID.

  - `cards.customer_id` (string, optional)
  Customer ID.

  - `cards.expiration_month` (number, optional)
  Card's expiration month.

  - `cards.expiration_year` (number, optional)
  Card's expiration year.

  - `cards.first_six_digits` (string, optional)
  Card's first six digits.

  - `cards.last_four_digits` (string, optional)
  Card's last four digits.

  - `cards.payment_method` (object, optional)
  Payment method information.

  - `cards.payment_method.id` (string, optional)
  Payment method ID.

  - `cards.payment_method.name` (string, optional)
  Payment method name.

  - `cards.payment_method.payment_type_id` (string, optional)
  Payment method type.

  - `cards.payment_method.thumbnail` (string, optional)
  Payment method thumbnail.

  - `cards.payment_method.secure_thumbnail` (string, optional)
  Payment method secure thumbnail.

  - `cards.security_code` (object, optional)
  Security code information.

  - `cards.security_code.length` (number, optional)
  Security code's length.

  - `cards.security_code.card_location` (string, optional)
  Security code's card location.

  - `cards.issuer` (object, optional)
  Issuer information.

  - `cards.issuer.id` (number, optional)
  Issuer Id.

  - `cards.issuer.name` (string, optional)
  Issuer name.

  - `cards.cardholder` (object, optional)
  Card holder information.

  - `cards.cardholder.name` (number, optional)
  Card holder name.

  - `cards.cardholder.identification` (number, optional)
  Identification information.

  - `cards.cardholder.identification.number` (number, optional)
  Identification number.

  - `cards.cardholder.identification.subtype` (string, optional)
  Identification subtype.

  - `cards.cardholder.identification.type` (string, optional)
  Identification type.

  - `cards.date_created` (string, optional)
  Card's date created.

  - `cards.date_last_updated` (string, optional)
  Card's last modified date.

- `addresses` (object, optional)
  Customer's addresses.

  - `addresses.id` (string, optional)
  Address ID.

  - `addresses.phone` (string, optional)
  Phone number.

  - `addresses.name` (string, optional)
  Address name.

  - `addresses.floor` (string, optional)
  Floor.

  - `addresses.apartment` (string, optional)
  Apartment.

  - `addresses.street_name` (string, optional)
  Street name.

  - `addresses.street_number` (number, optional)
  Street number.

  - `addresses.zip_code` (string, optional)
  Postal code of an Address.

  - `addresses.city` (object, optional)
  City information.

  - `addresses.city.id` (string, optional)
  City ID.

  - `addresses.city.name` (string, optional)
  City name.

  - `addresses.state` (object, optional)
  State information.

  - `addresses.state.id` (string, optional)
  State ID.

  - `addresses.state.name` (string, optional)
  State name.

  - `addresses.country` (object, optional)
  Country information.

  - `addresses.country.id` (string, optional)
  Country ID.

  - `addresses.country.name` (string, optional)
  Country name.

  - `addresses.neighborhood` (object, optional)
  Neighborhood information.

  - `addresses.neighborhood.id` (string, optional)
  Neighborhood ID.

  - `addresses.neighborhood.name` (string, optional)
  Neighborhood name.

  - `addresses.municipality` (object, optional)
  Municipality information.

  - `addresses.municipality.id` (string, optional)
  Municipality ID.

  - `addresses.municipality.name` (string, optional)
  Municipality name.

  - `addresses.comments` (string, optional)
  Additional information.

  - `addresses.date_created` (string, optional)
  Address date created.

- `live_mode` (boolean, optional)
  Whether the customers will be in sandbox or in production mode.

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
| 400 | 118 | the body must be a Json Object. |
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
| 400 | 214 | invalid zip code. |
| 401 | unauthorized | unauthorized. |
| 404 | not_found | not_found. |

## Request example

### cURL

```bash
curl -X GET \
  'https://api.mercadopago.com/v1/customers/{id}' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "id": "470183340-cpunOI7UsIHlHr",
  "email": "test@testuser.com",
  "first_name": "Customer",
  "last_name": "Tester",
  "phone": {
  "area_code": "11",
  "number": "97654321"
  },
  "identification": {
  "type": "CPF",
  "number": "19119119100"
  },
  "address": {
  "id": "1162600213",
  "zip_code": "05187010",
  "street_name": "Caetano Poli, 12",
  "street_number": 0
  },
  "date_registered": "string",
  "description": "Customer Test",
  "date_created": "2021-03-16T15:45:17.000-04:00",
  "date_last_updated": "string",
  "metadata": {
  "source_sync": "source_ws"
  },
  "default_card": "string",
  "default_address": "1162600213",
  "cards": {
  "id": "string",
  "customer_id": "string",
  "expiration_month": 0,
  "expiration_year": 0,
  "first_six_digits": "string",
  "last_four_digits": "string",
  "payment_method": {
  "id": "string",
  "name": "string",
  "payment_type_id": "string",
  "thumbnail": "string",
  "secure_thumbnail": "string"
  },
  "security_code": {
  "length": 0,
  "card_location": "string"
  },
  "issuer": {
  "id": 0,
  "name": "string"
  },
  "cardholder": {
  "name": 0,
  "identification": {
  "number": 0,
  "subtype": "string",
  "type": "CPF"
  }
  },
  "date_created": "string",
  "date_last_updated": "string"
  },
  "addresses": {
  "id": "1162600213",
  "phone": "string",
  "name": "string",
  "floor": "string",
  "apartment": "string",
  "street_name": "Caetano Poli, 12",
  "street_number": 0,
  "zip_code": "05187010",
  "city": {
  "id": "BR-SP-44",
  "name": "São Paulo"
  },
  "state": {
  "id": "BR-SP",
  "name": "São Paulo"
  },
  "country": {
  "id": "BR",
  "name": "Brasil"
  },
  "neighborhood": {
  "id": "string",
  "name": "Jardim Ipanema (Zona Oeste)"
  },
  "municipality": {
  "id": "string",
  "name": "string"
  },
  "comments": "string",
  "date_created": "2021-03-16T15:45:17.000-04:00"
  },
  "live_mode": true
}
```

# Update customer

Renew the data of a customer. Indicate the customer ID and send the parameters with the information you want to update.

**PUT** `/v1/customers/{id}`

## Request parameters

### Path

- `id` (number, required)
  Customer ID.

- `email` (string, optional)
  Customer's email. It can only be updated if the customer has not yet associated an email.

- `first_name` (string, optional)
  Customer's name.

- `last_name` (string, optional)
  Customer's last name.

- `phone` (object, optional)
  Customer phone's information.

  - `phone.area_code` (string, optional)
  Phone's area code.

  - `phone.number` (string, optional)
  Phone number.

- `identification` (object, optional)
  Customer identification's information.

  - `identification.type` (string, optional)
  Identification type.

  - `identification.number` (string, optional)
  Identification number.

- `default_address` (string, optional)
  Customer's default address.

- `address` (object, optional)
  Default address's information.

  - `address.id` (string, optional)
  Address ID.

  - `address.zip_code` (string, optional)
  Zip code.

  - `address.street_name` (string, optional)
  Street name.

  - `address.street_number` (number, optional)
  Street number.

- `date_registered` (string, optional)
  Customer's registration date.

- `description` (string, optional)
  Customer's description.

- `default_card` (string, optional)
  Customer's default card.

## Response parameters

- `id` (string, optional)
  Customer ID.

- `email` (string, optional)
  Customer's email.

- `first_name` (string, optional)
  Customer's name.

- `last_name` (string, optional)
  Customer's last name.

- `phone` (object, optional)
  Customer phone's information.

  - `phone.area_code` (string, optional)
  Phone's area code.

  - `phone.number` (string, optional)
  Phone number.

- `identification` (object, optional)
  Customer identification's information.

  - `identification.type` (string, optional)
  Identification type.

  - `identification.number` (string, optional)
  Identification number.

- `address` (object, optional)
  Default address's information.

  - `address.id` (string, optional)
  Address ID.

  - `address.zip_code` (string, optional)
  Zip code.

  - `address.street_name` (string, optional)
  Street name.

  - `address.street_number` (number, optional)
  Street number.

- `date_registered` (string, optional)
  Customer's registration date.

- `description` (string, optional)
  Customer's description.

- `date_created` (string, optional)
  Customer's date created.

- `date_last_updated` (string, optional)
  Last modified date.

- `metadata` (object, optional)
  metadata

- `default_card` (string, optional)
  Customer's default card.

- `default_address` (string, optional)
  Customer's default address.

- `cards` (array, optional)
  Customer's cards.

- `addresses` (array, optional)
  Customer's addresses.

- `live_mode` (boolean, optional)
  Whether the customers will be in sandbox or in production mode.

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
| 400 | 118 | the body must be a Json Object. |
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
| 400 | 214 | invalid zip code. |
| 401 | unauthorized | unauthorized. |
| 404 | not_found | not_found. |

## Request example

### cURL

```bash
curl -X PUT \
  'https://api.mercadopago.com/v1/customers/{id}' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "email": "test@testuser.com",
  "first_name": "Jhon",
  "last_name": "Doe",
  "phone": {
  "area_code": "55",
  "number": "991234567"
  },
  "identification": {
  "type": "CPF",
  "number": "12345678900"
  },
  "default_address": "Home",
  "address": {
  "id": "123123",
  "zip_code": "01234567",
  "street_name": "Rua Exemplo",
  "street_number": 123
  },
  "date_registered": "2021-10-20T11:37:30.000-04:00",
  "description": "Description del user",
  "default_card": "None"
  }'
```

## Response example

```json
{
  "id": "000000001-sT93QZFAsfxU9P5",
  "email": "test@testuser.com",
  "first_name": "Bruce",
  "last_name": "Wayne",
  "phone": {
  "area_code": 23,
  "number": 12345678
  },
  "identification": {
  "type": "CPF",
  "number": 12345678
  },
  "address": {
  "id": "string",
  "zip_code": "SG1 2AX",
  "street_name": "Old Knebworth Ln",
  "street_number": 0
  },
  "date_registered": "string",
  "description": "This is my description",
  "date_created": "2018-02-20T15:36:23.541Z",
  "date_last_updated": "string",
  "metadata": {},
  "default_card": "string",
  "default_address": "string",
  "cards": [
  {}
  ],
  "addresses": [
  {}
  ],
  "live_mode": false
}
```