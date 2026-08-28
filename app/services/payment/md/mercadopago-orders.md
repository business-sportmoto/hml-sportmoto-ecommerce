# Create order

This endpoint allows to create orders in `automatic` (processing the transaction in a single stage) or `manual` (processing the transaction in stages that can be configured and executed incrementally) mode for payment transactions with Checkout Transparente. In case of success, the request will return a response with status 201.

**POST** `/v1/orders`

## Request parameters

### Header

- `X-Idempotency-Key` (string, required)
  This feature allows you to safely retry requests without the risk of accidentally performing the same action more than once. This is useful for avoiding errors, such as creating two identical payments. To ensure that each request is unique, it's important to use an exclusive value in the header of your request. We suggest using a UUID V4 or random strings. The header accepts values between 1 and 128 characters.

- `type` (string, optional)
  Order type, associated with the Mercado Pago solution for which it is created. For online card payments, the only possible value is `online`.
Possible enum values:

  - `online`
  Value associated with the creation of Orders for online payments.

- `external_reference` (string, optional)
  It is an external reference of the order. It can be, for example, a hashcode from the Central Bank, functioning as an identifier of the transaction origin. This field must have a maximum of 64 characters and can only be numbers, letters, hyphens (-) and underscores (_). Special characters ([ ], (), '', @) are not allowed. Required only for Pix payments.

- `transactions` (object, optional)
  Contains information about the transactions associated with the order. It can contain only one transaction. Required when processing payment in `automatic` mode, optional in `manual` mode. If not sent in `manual` mode, it must be `null` (not an empty array).

  - `transactions.payments` (array, optional)
  Contains information about the payment order. Required when processing payment in `automatic` mode. Must not be present (or must be empty) when creating an order in `manual` mode, as transactions are added later via request to the endpoint [POST /v1/orders/{order_id}/transactions](/developers/en/reference/online-payments/checkout-api/add-transaction-order/post).

  - `transactions.payments.amount` (string, optional)
  Transaction amount. If only one payment method is used, it must be equivalent to the amount entered in the `total_amount` field. If two are used, it is the sum between the two `amount` that must be equivalent to the `total_amount` value. The field can contain two decimal places or none.

  - `transactions.payments.payment_method` (object, optional)
  Information about the payment method. Access the endpoint [/v1/payment_methods](/developers/en/reference/online-payments/checkout-api/payment-methods/get) to check all available payment methods and get a list with the details of each one and their properties. The requirement of this parameter varies according to the need to send its attributes in the request. Depending on the payment method you are integrating, check below which of these attributes are required.

  - `transactions.payments.payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `transactions.payments.payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `transactions.payments.payment_method.token` (string, optional)
  Token that identifies the card and contains its data securely. Only required for "card" payments. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `transactions.payments.payment_method.installments` (integer, optional)
  Number of installments selected. The maximum accepted value is 36.

  - `transactions.payments.payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts up to 50 characters.

  - `transactions.payments.expiration_time` (string, optional)
  Transaction expiration date. Only applicable to Pix and boleto bancário payments. The valid format of the attribute is ISO 8601 duration format, for example: "P3Y6M4DT12H30M5S" represents a duration of 3 years, 6 months, 4 days, 12 hours, 30 minutes, and 5 seconds.

  - `transactions.payments.date_of_expiration` (string, optional)
  Date and time of the expiration of the payment. If an `expiration_time` is not sent, this field adopts a default value that depends on the payment method.

- `payer` (object, optional)
  Payer information. The requirement of this parameter varies according to the need to send its attributes in the request. Depending on the payment method you are integrating, check below which of these attributes are required.

  - `payer.email` (string, optional)
  Payer email. Required only for Pix and boleto bancário payments.

  - `payer.entity_type` (string, optional)
  Type of payer's entity. Optional for card, Pix, and boleto bancário payments.
Possible enum values:

  - `individual`
  Payer is individual.

  - `association`
  Payer is an association.

  - `payer.first_name` (string, optional)
  Payer first name.

  - `payer.last_name` (string, optional)
  Payer last name.

  - `payer.identification` (object, optional)
  Payer's personal identification. This parameter and its required attributes are needed only for boleto bancário payments.

  - `payer.identification.type` (string, optional)
  Payer's identification document type. Access the endpoint [/v1/identification_types](/developers/en/reference/online-payments/checkout-api/identification-types/get) to check all available identification types by country and get a list with the details of each one and their properties.

  - `payer.identification.number` (string, optional)
  Payer's identification document number.

  - `payer.phone` (object, optional)
  Payer's phone information. This parameter and its attributes are optional for card, Pix, and boleto bancário payments.

  - `payer.phone.area_code` (string, optional)
  Phone area code.

  - `payer.phone.number` (string, optional)
  Phone number.

  - `payer.address` (object, optional)
  Payer's address information. This parameter and its required attributes are needed only for boleto bancário payments.

  - `payer.address.zip_code` (string, optional)
  Payer's address zip code.

  - `payer.address.street_name` (string, optional)
  Payer's address street name.

  - `payer.address.street_number` (string, optional)
  Payer address street number.

  - `payer.address.neighborhood` (string, optional)
  Payer's address neighborhood.

  - `payer.address.state` (string, optional)
  Payer's address state. Must contain exactly 2 characters.

  - `payer.address.city` (string, optional)
  Payer's address city.

  - `payer.address.complement` (string, optional)
  Payer's address complement.

- `shipment` (object, optional)
  Shipping information. This parameter and its required attributes are needed only for Pix and boleto bancário payments.

  - `shipment.address` (object, optional)
  Shipping address information.

  - `shipment.address.zip_code` (string, optional)
  Shipping address zip code.

  - `shipment.address.street_name` (string, optional)
  Shipping address street name.

  - `shipment.address.street_number` (string, optional)
  Shipping address street number.

  - `shipment.address.neighborhood` (string, optional)
  Shipping address neighborhood.

  - `shipment.address.city` (string, optional)
  Shipping address city.

  - `shipment.address.state` (string, optional)
  Shipping address state. Must contain exactly 2 characters.

  - `shipment.address.complement` (string, optional)
  Shipping address complement.

- `total_amount` (string, optional)
  Total amount to be paid. The field can contain two decimal places or none.

- `capture_mode` (string, optional)
  Order capture mode.
Possible enum values:

  - `manual`
  Order's capture will be made manually. It allows the reservation of the transaction value in the payer's card, so the capture can be made afterwards, by using the ([/v1/orders/{order_id}/capture](/developers/en/reference/online-payments/checkout-api/capture-order/post)) endpoint.

  - `automatic`
  Order's capture will be made automatically. Authorize and capture values at the same time.

  - `automatic_async`
  The order can be processed asynchronously. The order may remain in `status=processing` awaiting asynchronous update and the final status will be updated later through webhooks or queries.

- `processing_mode` (string, optional)
  Order processing mode
Possible enum values:

  - `manual`
  Order's processing will be made manually. It is the processing mode used for the `manual` option, as it sets the processing to be made afterwards, by using the ([/v1/orders/{order_id}/process](/developers/en/reference/online-payments/checkout-api/process-order/post)) endpoint.

  - `automatic`
  Order's processing will be made instantly. It is the capture mode used for the `automatic` option.

- `description` (string, optional)
  Description of the purchased product or service, the reason for the payment order, or the description of a product in the marketplace.

- `integration_data` (object, optional)
  Additional information that can be used to integrate with other systems, such as the identifier of the order in the integrator's system.

  - `integration_data.integrator_id` (string, optional)
  Identifier of the integrator in Mercado Pago. It is the unique identifier of the integrator in Mercado Pago's systems.

  - `integration_data.platform_id` (string, optional)
  Identifier of the platform in Mercado Pago. It is the unique identifier of the platform in Mercado Pago's systems.

  - `integration_data.sponsor` (object, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

  - `integration_data.sponsor.id` (string, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems. Before making the request, remember to replace "<YOUR_SPONSOR_ID>" with your real "sponsor.id".

- `items` (array, optional)
  Information about the list of items to be paid.

  - `items[].title` (string, optional)
  Item name. The character limit is 150.

  - `items[].unit_price` (string, optional)
  Unit price of the purchased item. This field must have a maximum of 18 characters.

  - `items[].quantity` (Integer, optional)
  Purchased items quantity.

  - `items[].description` (string, optional)
  Purchased item description. The character limit is 100.

  - `items[].external_code` (string, optional)
  Item External code.

  - `items[].picture_url` (string, optional)
  Image URL corresponding to the item.

  - `items[].category_id` (string, optional)
  Item category ID.

  - `items[].type` (string, optional)
  Item type.

  - `items[].warranty` (boolean, optional)
  If the item has a warranty.

  - `items[].event_date` (string, optional)
  Event date.

- `config` (object, optional)
  This object allows you to configure specific settings for the order, such as security settings for online transactions with cards using the 3DS (3D Secure) authentication protocol.

  - `config.online` (object, optional)
  This object contains settings that apply only to online transactions with cards. Use this configuration to indicate when 3DS (3D Secure) authentication should be triggered in case of fraud risk and to define financial liability in case of disputes.

  - `config.online.transaction_security` (object, optional)
  Transaction security configuration for 3DS (3D Secure). After creating the order, the response will indicate if the challenge is required. If not required, the "status" field will have the value "processed", allowing you to continue normally with the application flow. If the challenge is required, the order will be returned with "status=action_required", "status_detail=pending_challenge" and the challenge URL will be available in the "url" field. The challenge must be displayed in an iframe using the URL returned, allowing the buyer to complete authentication without leaving the checkout flow. The buyer has 40 minutes to complete the challenge from when the URL is created. If not completed within this period, the bank will reject the transaction and Mercado Pago will consider the payment expired.

  - `config.online.transaction_security.validation` (string, optional)
  Defines when the 3DS (3D Secure) flow should be executed.
Possible enum values:

  - `on_fraud_risk`
  3DS (3D Secure) is required according to transaction risk. Recommended to balance security and transaction approval.

  - `never`
  The 3DS (3D Secure) flow and challenge are never executed. This is the default value if the field is not sent.

  - `config.online.transaction_security.liability_shift` (string, optional)
  Defines the financial responsibility in case of dispute. Should not be sent when "validation" is "never".
Possible enum values:

  - `required`
  The financial responsibility in case of dispute is of the card brand. This is the only value accepted for 3DS (3D Secure).

  - `config.online.callback_url` (string, optional)

## Response parameters

- `id` (string, optional)
  Identifier of the order created in the request, automatically generated by Mercado Pago.

- `type` (string, optional)
  Order type, associated with the Mercado Pago solution for which it is created. For online card payments, the only possible value is `online`.
Possible enum values:

  - `online`
  Value associated with the creation of Orders for online payments.

- `processing_mode` (string, optional)
  Order processing mode
Possible enum values:

  - `manual`
  Order's processing will be made manually. It is the processing mode used for the `manual` option, as it sets the processing to be made afterwards, by using the ([/v1/orders/{order_id}/process](/developers/en/reference/online-payments/checkout-api/process-order/post)) endpoint.

  - `automatic`
  Order's processing will be made instantly. It is the capture mode used for the `automatic` option.

- `external_reference` (string, optional)
  It is an external reference of the order. It can be, for example, a hashcode from the Central Bank, functioning as an identifier of the transaction origin. This field must have a maximum of 64 characters and can only be numbers, letters, hyphens (-) and underscores (_). Special characters ([ ], (), '', @) are not allowed. Required only for Pix payments.

- `total_amount` (string, optional)
  Total amount to be paid.

- `total_paid_amount` (string, optional)
  Total amount to be paid, represents the sum of all the transaction's "paid_amount" values.

- `integration_data` (object, optional)
  Additional information that can be used to integrate with other systems, such as the identifier of the order in the integrator's system.

  - `integration_data.application_id` (string, optional)
  Identifier of the Mercado Pago application that created the order.

  - `integration_data.integrator_id` (string, optional)
  Identifier of the integrator in Mercado Pago. It is the unique identifier of the integrator in Mercado Pago's systems.

  - `integration_data.platform_id` (string, optional)
  Identifier of the platform in Mercado Pago. It is the unique identifier of the platform in Mercado Pago's systems.

  - `integration_data.sponsor` (object, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

  - `integration_data.sponsor.id` (string, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

- `created_date` (string, optional)
  Order's creation date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `last_updated_date` (string, optional)
  Order's last update date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `country_code` (string, optional)
  Identifier of the site (country) to which the Mercado Pago application that created the order belongs.

- `status` (string, optional)
  Current status of the order.
Possible enum values:

  - `created`
  The order has been created successfully.

  - `processed`
  All transactions have been succesfully processed.

  - `action_required`
  Integrator action is required to complete processing. For example, the capture of an authorized payment.

  - `processing`
  The order is being processed and does not require any action from the integrator. For example, the payment may be pending manual review. This status may also be returned in the case of asynchronous payment creation.

  - `canceled`
  The order has been canceled and will not be processed further.

- `status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `created`
  The order has been created successfully.

  - `accredited`
  Payment accredited.

  - `in_process`
  The payment is being processed.

  - `in_review`
  The payment is being reviewed.

  - `waiting_payment`
  The order is waiting for payment.

  - `waiting_capture`
  In cases of "status=action_required", integrator action is required to complete processing. This "status_detail" indicates that the capture of an authorized payment is needed.

  - `waiting_retry`
  In cases of "status=action_required", the order is in the automatic retry window after a failed charge. A new payment attempt is scheduled before the configured maximum number of retries is reached.

  - `waiting_transfer`
  The order is waiting for the transfer of funds.

- `capture_mode` (string, optional)
  Order capture mode.
Possible enum values:

  - `manual`
  Order's capture will be made manually. It allows the reservation of the transaction value in the payer's card, so the capture can be made afterwards, by using the ([/v1/orders/{order_id}/capture](/developers/en/reference/online-payments/checkout-api/capture-order/post)) endpoint.

  - `automatic`
  Order's capture will be made automatically. Authorize and capture values at the same time.

  - `automatic_async`
  The order can be processed asynchronously. The order may remain in `status=processing` awaiting asynchronous update and the final status will be updated later through webhooks or queries.

- `shipment` (object, optional)
  Shipping information. This parameter and its required attributes are needed only for Pix and boleto bancário payments.

  - `shipment.address` (object, optional)
  Shipping address information.

  - `shipment.address.zip_code` (string, optional)
  Shipping address zip code.

  - `shipment.address.street_name` (string, optional)
  Shipping address street name.

  - `shipment.address.street_number` (string, optional)
  Shipping address street number.

  - `shipment.address.neighborhood` (string, optional)
  Shipping address neighborhood.

  - `shipment.address.city` (string, optional)
  Shipping address city.

  - `shipment.address.state` (string, optional)
  Shipping address state. Must contain exactly 2 characters.

  - `shipment.address.complement` (string, optional)
  Shipping address complement.

- `transactions` (object, optional)
  Contains information about the transactions associated with the order.

  - `transactions.payments` (array, optional)
  Contains information about the payment associated with the order.

  - `transactions.payments[].id` (string, optional)
  Identifier of the payment transaction created in the request, automatically generated by Mercado Pago.

  - `transactions.payments[].amount` (string, optional)
  Payment amount.

  - `transactions.payments[].paid_amount` (string, optional)
  Transaction paid amount. Represents the real amount paid including discounts or tips.

  - `transactions.payments[].taxes_amount` (string, optional)
  Amount corresponding to taxes applied to the transaction. Not returned when not provided by the payment processor. The field can contain two decimal places or none.

  - `transactions.payments[].reference_id` (string, optional)
  Reference ID of the transaction.

  - `transactions.payments[].status` (string, optional)
  Payment status.
Possible enum values:

  - `created`
  The transaction has been created successfully.

  - `processed`
  All transactions have been succesfully processed.

  - `action_required`
  Integrator action is required to complete processing. For example, the capture of an authorized payment.

  - `processing`
  The transaction is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

  - `transactions.payments[].status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `waiting_capture`
  In cases of "status=action_required", integrator action is required to complete processing. This "status_detail" indicates that the capture of an authorized payment is needed.

  - `waiting_retry`
  In cases of "status=action_required", the order is in the automatic retry window after a failed charge. A new payment attempt is scheduled before the configured maximum number of retries is reached.

  - `created`
  The transaction has been created successfully.

  - `pending_review_manual`
  The payment is pending manual review.

  - `in_process`
  The payment is being processed.

  - `transactions.payments[].expiration_time` (string, optional)
  Transaction expiration date.

  - `transactions.payments[].payment_method` (object, optional)
  Information about the payment method. Access the endpoint [/v1/payment_methods](/developers/en/reference/online-payments/checkout-api/payment-methods/get) to check all available payment methods and get a list with the details of each one and their properties.

  - `transactions.payments[].payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.

  - `transactions.payments[].payment_method.type` (string, optional)
  Type of payment method selected to make the payment.

  - `transactions.payments[].payment_method.token` (string, optional)
  It is a mandatory field for "card" payments, as it is the token that identifies the card and contains its data securely. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `transactions.payments[].payment_method.installments` (integer, optional)
  Number of installments selected.

  - `transactions.payments[].payment_method.installment_amount` (string, optional)
  Amount per installment. The field can contain two decimal places or none.

  - `transactions.payments[].payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts maximum of 50 characters.

  - `transactions.payments[].payment_method.ticket_url` (string, optional)
  Ticket URL. It is returned for payment methods such as Pix and boleto.

  - `transactions.payments[].payment_method.barcode_content` (string, optional)
  Barcode content. It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.reference` (string, optional)
  Reference number. It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.verification_code` (string, optional)
  Verification code. It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.financial_institution` (string, optional)
  Financial institution. It is returned for payment methods such as It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.digitable_line` (string, optional)
  Digitable line. It is returned for the payment method "boleto".

  - `transactions.payments[].payment_method.qr_code` (string, optional)
  QR code. It is returned for payment method "Pix".

  - `transactions.payments[].payment_method.qr_code_base64` (string, optional)
  QR code in base64. It is returned for payment method "Pix".

  - `transactions.payments[].payment_method.e2e_id` (string, optional)
  Unique and mandatory code generated for each "Pix" transaction, serving as a tracking proof that identifies the operation from start to end (end-to-end).

  - `transactions.payments[].payment_method.transaction_security` (object, optional)
  Transaction security configuration for 3DS (3D Secure), an authentication protocol used in online transactions with card. After creating the order, the response will indicate if the challenge is required. If not required, the "status" field will have the value "processed", allowing you to continue normally with the application flow. If the challenge is required, the order will be returned with "status=action_required", "status_detail=pending_challenge" and the challenge URL will be available in the "url" field. The challenge must be displayed in an iframe using the URL returned, allowing the buyer to complete authentication without leaving the checkout flow. The buyer has 40 minutes to complete the challenge from when the URL is created. If not completed within this period, the bank will reject the transaction and Mercado Pago will consider the payment expired.

  - `transactions.payments[].payment_method.transaction_security.validation` (string, optional)
  Defines when the 3DS (3D Secure) flow should be executed.
Possible enum values:

  - `on_fraud_risk`
  3DS (3D Secure) is required according to transaction risk. Recommended to balance security and transaction approval.

  - `never`
  The 3DS (3D Secure) flow and challenge are never executed. This is the default value if the field is not sent.

  - `transactions.payments[].payment_method.transaction_security.liability_shift` (string, optional)
  Defines the financial responsibility in case of dispute. Should not be sent when "validation" is "never".
Possible enum values:

  - `required`
  The financial responsibility in case of dispute is of the card brand. This is the only value accepted for 3DS (3D Secure).

  - `transactions.payments[].payment_method.transaction_security.url` (string, optional)
  URL of the challenge displayed after creating an order returned with "status=action_required" and "status_detail=pending_challenge". The challenge must be displayed in an iframe using the returned URL, allowing the buyer to complete authentication without leaving the checkout flow. The buyer has 40 minutes to complete the challenge from when the URL is created. If not completed within this period, the bank will reject the transaction and Mercado Pago will consider the payment expired.

  - `transactions.payments[].payment_method.transaction_security.id` (string, optional)
  ID of the challenge of the 3DS (3D Secure) security protocol.

  - `transactions.payments[].payment_method.transaction_security.type` (string, optional)
  Type of challenge. In the case of 3DS (3D Secure), the only possible value is "three_ds".

  - `transactions.payments[].payment_method.transaction_security.status` (string, optional)
  Status of the challenge in the 3DS (3D Secure) security protocol.
Possible enum values:

  - `AUTHENTICATED`
  Authentication performed by the responsible bank and forwarded to card brand validation.

  - `NOT_AUTHENTICATED`
  The challenge was not performed correctly or the responsible bank did not authorize the transaction due to some possible risk.

  - `CHALLENGE`
  The bank requested a challenge from the buyer and it has not yet been completed.

  - `ATTEMPTED`
  Authentication performed by the card brand.

  - `REJECTED`
  The responsible bank rejected the authentication due to some possible risk and also denied the possibility of challenge.

  - `ERROR`
  Missing some information required for 3DS authentication. Example: the "device_id" field was not filled.

  - `transactions.payments[].date_of_expiration` (string, optional)
  Date and time of the expiration of the payment. It is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request. If an `expiration_time` is not sent, this field adopts a default value that depends on the payment method.

- `description` (string, optional)
  Description of the purchased product or service , the reason for the payment order, or the description of a product in the marketplace.

- `items` (array, optional)
  Information about the list of items to be paid.

  - `items[].title` (string, optional)
  Item name. The character limit is 150.

  - `items[].unit_price` (string, optional)
  Unit price of the purchased item. This field must have a maximum of 18 characters.

  - `items[].quantity` (Integer, optional)
  Purchased items quantity.

  - `items[].description` (string, optional)
  Purchased item description. The character limit is 100.

  - `items[].external_code` (string, optional)
  Item External code.

  - `items[].picture_url` (string, optional)
  Image URL corresponding to the item.

  - `items[].category_id` (string, optional)
  Item category ID.

  - `items[].type` (string, optional)
  Item type.

  - `items[].warranty` (boolean, optional)
  If the item has a warranty.

  - `items[].event_date` (string, optional)
  Event date.

- `client_token` (string, optional)
  Authentication token to execute operations on the client side.

- `config` (object, optional)
  Specific settings configured for the order.

  - `config.online` (object, optional)
  Settings that apply only to online transactions with cards.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | empty_required_header | The "X-Idempotency-Key" header is required and was not sent. Make the requisition again including it. |
| 400 | invalid_idempotency_key_length | The value sent in the "X-Idempotency-Key" header exceeded the allowed size. The header accepts values between 1 and 128 characters. |
| 400 | required_properties | There are some required properties missing. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | unsupported_properties | An unsupported property was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | minimum_properties | The minimum number of properties required to execute the request was not sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | property_type | The wrong property type was submitted. For example, an "integer" value for a "string" property. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | minimum_items | The minimum number of items for some property was not sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | maximum_items | A greater number of items were sent than allowed for some property. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | property_value | An incorrect value for some property was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | json_syntax_error | An incorrect JSON was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | invalid_properties | Incorrect information was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | invalid_total_amount | The value entered in "total_amount" is not equivalent to the sum of the "transactions.payments.amount" field of the total transactions. Please verify if the values ​​are correct. |
| 400 | invalid_email_for_sandbox | Email format is invalid for sandbox environment, must contains "@testuser.com". |
| 400 | order_invalid_sponsor_id | Order sponsor id is invalid. Make sure the ID is correct. |
| 400 | invalid_header_value | Caller id ("caller_id") not found. Make sure the ID is correct. |
| 400 | order_builder_without_transactions | The "transactions" node of the order created in "manual" mode cannot be an empty array. Send it with the value "null" to try again. |
| 400 | invalid_order_type | The order type provided is invalid or not supported. Expected one of: "online". |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 402 | 402 | Order was created but some transaction failed. Check the "errors" field for more information. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 409 | idempotency_key_already_used | The value sent as the idempotency header (`X-Idempotency-Key`) has already been used. Please try the request again sending a new value. |
| 423 | resource_locked | Idempotency Key Locked. Please retry after some time. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | idempotency_validation_failed | Validation fail. Please try submitting the request again. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "type": "online",
  "external_reference": "ext_ref_1234",
  "transactions": {
  "payments": {
  "amount": "24.50",
  "payment_method": {
  "id": "visa",
  "type": "credit_card",
  "token": "12345",
  "installments": 1,
  "statement_descriptor": "My Store"
  },
  "expiration_time": "P3Y6M4DT12H30M5S",
  "date_of_expiration": "2027-12-31T10:00:00.000-04:00"
  }
  },
  "payer": {
  "email": "test@testuser.com",
  "entity_type": "individual",
  "first_name": "João",
  "last_name": "Silva",
  "identification": {
  "type": "CPF",
  "number": "19119119100"
  },
  "phone": {
  "area_code": "11",
  "number": "98765-4321"
  },
  "address": {
  "zip_code": "06233-903",
  "street_name": "Rua Teste",
  "street_number": "3003",
  "neighborhood": "Bonfim",
  "state": "SP",
  "city": "Osasco",
  "complement": "Apto 303"
  }
  },
  "shipment": {
  "address": {
  "zip_code": "06233-903",
  "street_name": "Rua Teste",
  "street_number": "3003",
  "neighborhood": "Bonfim",
  "city": "Osasco",
  "state": "SP",
  "complement": "Apto 303"
  }
  },
  "total_amount": "50.00",
  "capture_mode": "automatic",
  "processing_mode": "automatic",
  "description": "Smartphone",
  "integration_data": {
  "integrator_id": "dev_123",
  "platform_id": "1234567890",
  "sponsor": {
  "id": "<YOUR_SPONSOR_ID>"
  }
  },
  "items": [
  {
  "title": "Smartphone",
  "unit_price": "24.50",
  "quantity": 1,
  "description": "Smartphone",
  "external_code": "1234",
  "picture_url": "https://http2.mlstatic.com/resources/frontend/statics/growth-sellers-landings/device-mlb-point-i_medium2x.png",
  "category_id": "MLB1055",
  "type": "MLB1055",
  "warranty": "true",
  "event_date": "2014-06-28T16:53:03.176-04:00"
  }
  ],
  "config": {
  "online": {
  "transaction_security": {
  "validation": "on_fraud_risk",
  "liability_shift": "required"
  },
  "callback_url": "string"
  }
  }
  }'
```

## Response example

```json
{
  "id": "ORD01J49MMW3SSBK5PSV3DFR32959",
  "type": "online",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "50.00",
  "total_paid_amount": "50.00",
  "integration_data": {
  "application_id": "1234",
  "integrator_id": "dev_123",
  "platform_id": "1234567890",
  "sponsor": {
  "id": "<YOUR_SPONSOR_ID>"
  }
  },
  "created_date": "2024-08-26T13:06:51.045317772Z",
  "last_updated_date": "2024-08-26T13:06:51.045317772Z",
  "country_code": "BR",
  "status": "processed",
  "status_detail": "accredited",
  "capture_mode": "automatic",
  "shipment": {
  "address": {
  "zip_code": "06233-903",
  "street_name": "Rua Teste",
  "street_number": "3003",
  "neighborhood": "Bonfim",
  "city": "Osasco",
  "state": "SP",
  "complement": "Apto 303"
  }
  },
  "transactions": {
  "payments": [
  {
  "id": "PAY01J67CQQH5904WDBVZEM4JMEP3",
  "amount": "50.00",
  "paid_amount": "47.28",
  "taxes_amount": "0.50",
  "reference_id": "01JEVQM899NWSQC4FYWWW7KTF9",
  "status": "processed",
  "status_detail": "accredited",
  "expiration_time": "P3Y6M4DT12H30M5S",
  "payment_method": {
  "id": null,
  "type": null,
  "token": null,
  "installments": null,
  "installment_amount": null,
  "statement_descriptor": null,
  "ticket_url": null,
  "barcode_content": null,
  "reference": null,
  "verification_code": null,
  "financial_institution": null,
  "digitable_line": null,
  "qr_code": null,
  "qr_code_base64": null,
  "e2e_id": null,
  "transaction_security": null
  },
  "date_of_expiration": "2027-12-31T10:00:00.000-04:00"
  }
  ]
  },
  "description": "Smartphone",
  "items": [
  {
  "title": "Smartphone",
  "unit_price": "24.50",
  "quantity": 1,
  "description": "Smartphone",
  "external_code": "1234",
  "picture_url": "https://http2.mlstatic.com/resources/frontend/statics/growth-sellers-landings/device-mlb-point-i_medium2x.png",
  "category_id": "MLB1055",
  "type": "MLB1055",
  "warranty": "true",
  "event_date": "2014-06-28T16:53:03.176-04:00"
  }
  ],
  "client_token": "QWERTY12345.ASDFG67890",
  "config": {
  "online": {}
  }
}
```

## Use cases

### Create payment with credit card

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "type": "online",
  "external_reference": "ext_ref_1234",
  "transactions": {
  "payments": {
  "amount": "50.00",
  "payment_method": {
  "id": "visa",
  "type": "credit_card",
  "token": "1c87b6b301010101ddcd92f9bbbb3be2",
  "installments": 1
  }
  }
  },
  "payer": {
  "email": "test@testuser.com"
  },
  "total_amount": "100.00",
  "processing_mode": "automatic",
  "integration_data": {
  "sponsor": {}
  },
  "config": {
  "online": {
  "transaction_security": {
  "validation": "never"
  }
  }
  }
  }'
```

```json
{
  "id": "ORDTST01KB0JDVXYPD6HPP2HSJDKH8FG",
  "type": "online",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "100.00",
  "total_paid_amount": "100.00",
  "integration_data": {
  "application_id": "874202490101010",
  "sponsor": {}
  },
  "created_date": "2025-11-26T17:12:25.791Z",
  "last_updated_date": "2025-11-26T17:12:27.093Z",
  "country_code": "BR",
  "status": "processed",
  "status_detail": "accredited",
  "capture_mode": "automatic_async",
  "transactions": {
  "payments": [
  {
  "id": "PAY01KB0JDVXYPD6HPP2HSMJS11QN",
  "amount": "100.00",
  "paid_amount": "100.00",
  "taxes_amount": "0.50",
  "reference_id": "00076jffff",
  "status": "processed",
  "status_detail": "accredited",
  "payment_method": {
  "id": null,
  "type": null,
  "token": null,
  "installments": null,
  "installment_amount": null,
  "transaction_security": null
  }
  }
  ]
  },
  "config": {
  "online": {}
  }
}
```

### Create payment with Pix

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "type": "online",
  "external_reference": "ext_ref_1234",
  "transactions": {
  "payments": {
  "amount": "100.00",
  "payment_method": {
  "id": "pix",
  "type": "bank_transfer"
  },
  "expiration_time": "P1D"
  }
  },
  "payer": {
  "email": "test@testuser.com"
  },
  "shipment": {
  "address": {
  "zip_code": "11034430",
  "street_name": "Av. Paulista",
  "street_number": "100",
  "neighborhood": "Bonfim",
  "city": "SAO PAULO",
  "state": "SP",
  "complement": "101"
  }
  },
  "total_amount": "100.00",
  "processing_mode": "automatic",
  "integration_data": {
  "sponsor": {}
  }
  }'
```

```json
{
  "id": "ORDTST01KB00C5QRFKWERZQW5D6THPYQ",
  "type": "online",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "100.00",
  "integration_data": {
  "application_id": "874203390251070",
  "sponsor": {}
  },
  "created_date": "2025-11-26T11:56:55.928Z",
  "last_updated_date": "2025-11-26T11:56:57.089Z",
  "country_code": "BR",
  "status": "action_required",
  "status_detail": "waiting_transfer",
  "capture_mode": "automatic_async",
  "shipment": {
  "address": {
  "zip_code": "11034430",
  "street_name": "Av. Paulista",
  "street_number": "100",
  "neighborhood": "Bonfim",
  "city": "SAO PAULO",
  "state": "SP",
  "complement": "101"
  }
  },
  "transactions": {
  "payments": [
  {
  "id": "PAY01KB00C5QRFKWERZQW5DX6XMMJ",
  "amount": "50.00",
  "taxes_amount": "0.50",
  "reference_id": "00076axsfl",
  "status": "action_required",
  "status_detail": "waiting_transfer",
  "expiration_time": "P1D",
  "payment_method": {
  "id": null,
  "type": null,
  "installment_amount": null,
  "ticket_url": null,
  "qr_code": null,
  "qr_code_base64": null,
  "e2e_id": null,
  "transaction_security": null
  },
  "date_of_expiration": "2025-11-27T11:56:55.983+00:00"
  }
  ]
  }
}
```

### Create payment with boleto

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "type": "online",
  "external_reference": "ext_ref_1234",
  "transactions": {
  "payments": {
  "amount": "100.00",
  "payment_method": {
  "id": "boleto",
  "type": "ticket"
  },
  "expiration_time": "P3D",
  "date_of_expiration": "2027-12-31T10:00:00.000-04:00"
  }
  },
  "payer": {
  "email": "test@testuser.com",
  "first_name": "João",
  "last_name": "Silva",
  "identification": {
  "type": "CPF",
  "number": "99999999999"
  },
  "phone": {
  "area_code": "11",
  "number": "43434343"
  },
  "address": {
  "zip_code": "11034430",
  "street_name": "Av. Paulista",
  "street_number": "100",
  "neighborhood": "Bonfim",
  "state": "SP",
  "city": "SAO PAULO",
  "complement": "101"
  }
  },
  "shipment": {
  "address": {
  "zip_code": "11034430",
  "street_name": "Av. Paulista",
  "street_number": "100",
  "neighborhood": "Bonfim",
  "city": "SAO PAULO",
  "state": "SP",
  "complement": "101"
  }
  },
  "total_amount": "100.00",
  "processing_mode": "automatic",
  "integration_data": {
  "sponsor": {}
  }
  }'
```

```json
{
  "id": "ORDTST01KB017NDRQ7ZHETXWFF7V3TZX",
  "type": "online",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "50.00",
  "integration_data": {
  "application_id": "287582901010111683",
  "sponsor": {}
  },
  "created_date": "2025-11-26T12:11:56.729Z",
  "last_updated_date": "2025-11-26T12:11:57.974Z",
  "country_code": "BR",
  "status": "action_required",
  "status_detail": "pending_waiting_payment",
  "capture_mode": "automatic_async",
  "shipment": {
  "address": {
  "zip_code": "11034430",
  "street_name": "Av. Paulista",
  "street_number": "100",
  "neighborhood": "Bonfim",
  "city": "SAO PAULO",
  "state": "SP",
  "complement": "101"
  }
  },
  "transactions": {
  "payments": [
  {
  "id": "PAY01KB017NDRQ7ZHETXWFH5KA86R",
  "amount": "50.00",
  "taxes_amount": "0.50",
  "reference_id": "00076ob0b0b0",
  "status": "action_required",
  "status_detail": "pending_waiting_payment",
  "expiration_time": "P3D",
  "payment_method": {
  "id": null,
  "type": null,
  "installment_amount": null,
  "ticket_url": null,
  "barcode_content": null,
  "digitable_line": null,
  "transaction_security": null
  },
  "date_of_expiration": "2025-11-27T11:56:55.983+00:00"
  }
  ]
  }
}
```

### Create payment with debit card

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "type": "online",
  "external_reference": "ext_ref_123",
  "transactions": {
  "payments": {
  "amount": "100.00",
  "payment_method": {
  "id": "debelo",
  "type": "debit_card",
  "token": "1c87b6b301010101ddcd92f9bbbb3be2"
  }
  }
  },
  "payer": {
  "email": "test@testuser.com"
  },
  "total_amount": "50.00",
  "processing_mode": "automatic",
  "integration_data": {
  "sponsor": {}
  },
  "config": {
  "online": {
  "transaction_security": {
  "validation": "never"
  }
  }
  }
  }'
```

```json
{
  "id": "ORDTST01KB0JDVXYPD6HPP2HSJDKH8FG",
  "type": "online",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_123",
  "total_amount": "100.00",
  "total_paid_amount": "100.00",
  "integration_data": {
  "application_id": "01010182923271683",
  "sponsor": {}
  },
  "created_date": "2025-11-26T17:12:25.791Z",
  "last_updated_date": "2025-11-26T17:12:27.093Z",
  "country_code": "BR",
  "status": "processed",
  "status_detail": "accredited",
  "capture_mode": "automatic_async",
  "transactions": {
  "payments": [
  {
  "id": "PAY01KB0JDVXYPD6HPP2HSMJS11QN",
  "amount": "100.00",
  "paid_amount": "100.00",
  "taxes_amount": "0.50",
  "reference_id": "00076ggggg",
  "status": "processed",
  "status_detail": "accredited",
  "payment_method": {
  "id": null,
  "type": null,
  "token": null,
  "installment_amount": null,
  "transaction_security": null
  }
  }
  ]
  },
  "config": {
  "online": {}
  }
}
```

# Capture order fully

This endpoint allows to totally capture a previously authorized order. Every associated payment will be captured in total. In case of success, the request will return a response with status 200.

**POST** `/v1/orders/{order_id}/capture`

## Request parameters

### Header

- `X-Idempotency-Key` (string, required)
  This function allows you to repeat requests safely, without the risk of carrying out the same action more than once by mistake. This is useful to avoid mistakes such as creating two identical payments. To ensure that each request is unique, it is important to use a unique value in your request header. We suggest using a V4 UUID or random strings. The header accepts values between 1 and 128 characters.

### Path

- `order_id` (string, required)
  Order ID whose values ​​will be captured. This value is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request.

## Response parameters

- `id` (string, optional)
  Identifier of the order being processed in the request.

- `status` (string, optional)
  Current status of the order.
Possible enum values:

  - `processed`
  All transactions have been succesfully processed.

  - `processing`
  The order is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

- `status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `in_process`
  When the `status=processing`, the payment is being processed.

- `transactions` (object, optional)
  Contains information about the transactions associated with the order.

  - `transactions.payments` (array, optional)
  Contains information about the payment associated with the order.

  - `transactions.payments[].id` (string, optional)
  Identifier of the payment transaction created in the request, automatically generated by Mercado Pago.

  - `transactions.payments[].amount` (string, optional)
  Transaction amount.

  - `transactions.payments[].status` (string, optional)
  Payment status.
Possible enum values:

  - `processed`
  All transactions have been succesfully processed.

  - `processing`
  The order is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

  - `transactions.payments[].status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `in_process`
  When the `status=processing`, the payment is being processed.

  - `transactions.payments[].reference_id` (string, optional)
  Reference ID of the transaction.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | empty_required_header | The "X-Idempotency-Key" header is required and was not sent. Make the requisition again including it. |
| 400 | invalid_idempotency_key_length | The value sent in the "X-Idempotency-Key" header exceeded the allowed size. The header accepts values between 1 and 128 characters. |
| 400 | invalid_path_param | The "order_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 402 | 402 | Order was created but some transaction failed. Check the "errors" field for more information. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 409 | idempotency_key_already_used | The value sent as the idempotency header ("X-Idempotency-Key") has already been used. Please try the request again sending a new value. |
| 409 | cannot_capture_order | Error. Order cannot be captured. The status of the order does not allow its capture. Only orders with "status=action_required" and "status_detail=waiting_capture" can be captured. |
| 409 | operation_not_supported | The operation is not supported for this order. Please check the order "status" and "status_detail" and try again. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | idempotency_validation_failed | Validation fail. Please try submitting the request again. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders/{order_id}/capture' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "id": "ORD01J49MMW3SSBK5PSV3DFR32959",
  "status": "processed",
  "status_detail": "accredited",
  "transactions": {
  "payments": [
  {
  "id": "PAY01J49MMW3SSBK5PSV3DFR32959",
  "amount": "24.50",
  "status": "processed",
  "status_detail": "accredited",
  "reference_id": "01JEVQM899NWSQC4FYWWW7KTF9"
  }
  ]
  }
}
```

# Update a transaction from the order

This endpoint allows updating the information of a payment transaction in the order.

**PUT** `/v1/orders/{order_id}/transactions/{transaction_id}`

## Request parameters

### Header

- `X-Idempotency-Key` (string, required)
  This feature allows you to safely retry requests without the risk of accidentally performing the same action more than once. This is useful for avoiding errors, such as creating two identical payments. To ensure that each request is unique, it's important to use an exclusive value in the header of your request. We suggest using a UUID V4 or random strings. The header accepts values between 1 and 128 characters.

### Path

- `order_id` (string, required)
  ID of the order that is being updated. This value is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request.

- `transaction_id` (string, required)
  Identifier of the payment transaction that will be updated in the order. This ID is automatically generated by Mercado Pago when the request is created or when the transaction is added later to the order.

- `payment_method` (object, optional)
  Information about the payment method. Access the endpoint [/v1/payment_methods](/developers/en/reference/online-payments/checkout-api/payment-methods/get) to check all available payment methods and get a list with the details of each one and their properties. The requirement of this parameter varies according to the need to send its attributes in the request. Depending on the payment method you are integrating, check below which of these attributes are required.

  - `payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `payment_method.token` (string, optional)
  Token that identifies the card and contains its data securely. Only required for "card" payments. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `payment_method.installments` (integer, optional)
  Number of installments selected. The maximum accepted value is 36.

  - `payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts up to 50 characters.

## Response parameters

- `payment_method` (object, optional)
  Information about the payment method used in the transaction.

  - `payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `payment_method.token` (string, optional)
  Token that identifies the card and contains its data securely. Only required for "card" payments. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `payment_method.installments` (integer, optional)
  Number of installments selected.

  - `payment_method.installment_amount` (string, optional)
  Amount per installment. The field can contain two decimal places or none.

  - `payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts up to 50 characters.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | empty_required_header | The "X-Idempotency-Key" header is required and was not sent. Make the requisition again including it. |
| 400 | invalid_idempotency_key_length | The value sent in the "X-Idempotency-Key" header exceeded the allowed size. The header accepts values between 1 and 128 characters. |
| 400 | invalid_path_param | The "order_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 400 | invalid_transaction_id | The "transaction_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 409 | idempotency_key_already_used | The value sent as the idempotency header (`X-Idempotency-Key`) has already been used. Please try the request again sending a new value. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X PUT \
  'https://api.mercadopago.com/v1/orders/{order_id}/transactions/{transaction_id}' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "payment_method": {
  "id": "visa",
  "type": "credit_card",
  "token": "12345",
  "installments": 1,
  "statement_descriptor": "My Store"
  }
  }'
```

## Response example

```json
{
  "payment_method": {
  "id": "visa",
  "type": "credit_card",
  "token": "12345",
  "installments": 1,
  "installment_amount": "8.30",
  "statement_descriptor": "My Store"
  }
}
```

# Add transactions to the order

This endpoint allows payment transactions to be added to the order. This operation can only be carried out in manual mode (processing the transaction in stages that can be configured and executed incrementally), with the `processing_mode` field filled with `manual` value. In case of success, the request will return a response with status 201.

**POST** `/v1/orders/{order_id}/transactions`

## Request parameters

### Header

- `X-Idempotency-Key` (string, required)
  This feature allows you to safely retry requests without the risk of accidentally performing the same action more than once. This is useful for avoiding errors, such as creating two identical payments. To ensure that each request is unique, it's important to use an exclusive value in the header of your request. We suggest using a UUID V4 or random strings. The header accepts values between 1 and 128 characters.

### Path

- `id` (string, required)
  Order ID, returned in the response to the request made for its creation.

- `payments` (array, optional)
  Contains information about the payment order.

  - `payments[].amount` (string, optional)
  Transaction amount. If only one payment method is used, it must be equivalent to the amount entered in the `total_amount` field. If two are used, it is the sum between the two `amount` that must be equivalent to the `total_amount` value. The field can contain two decimal places or none.

  - `payments[].payment_method` (object, optional)
  Information about the payment method. Access the endpoint [/v1/payment_methods](/developers/en/reference/online-payments/checkout-api/payment-methods/get) to check all available payment methods and get a list with the details of each one and their properties. The requirement of this parameter varies according to the need to send its attributes in the request. Depending on the payment method you are integrating, check below which of these attributes are required.

  - `payments[].payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `payments[].payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `payments[].payment_method.token` (string, optional)
  Token that identifies the card and contains its data securely. Only required for "card" payments. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `payments[].payment_method.installments` (integer, optional)
  Number of installments selected. The maximum accepted value is 36.

  - `payments[].payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts up to 50 characters.

## Response parameters

- `payments` (array, optional)
  Contains information about the payment associated with the order.

  - `payments[].id` (string, optional)
  Identifier of the payment transaction created in the request, automatically generated by Mercado Pago.

  - `payments[].amount` (string, optional)
  Transaction amount.

  - `payments[].date_of_expiration` (string, optional)
  Date and time of the expiration of the payment. It is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request. If an `expiration_time` is not sent, this field adopts a default value that depends on the payment method.

  - `payments[].status` (string, optional)
  Payment status.
Possible enum values:

  - `created`
  The order has been created successfully.

  - `payments[].status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `created`
  Payment has been created successfully.

  - `payments[].payment_method` (object, optional)
  Information about the payment method used in the transaction.

  - `payments[].payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `payments[].payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `payments[].payment_method.token` (string, optional)
  Token that identifies the card and contains its data securely. Only required for "card" payments. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `payments[].payment_method.installments` (integer, optional)
  Number of installments selected.

  - `payments[].payment_method.installment_amount` (string, optional)
  Amount per installment. The field can contain two decimal places or none.

  - `payments[].payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts up to 50 characters.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | empty_required_header | The "X-Idempotency-Key" header is required and was not sent. Make the requisition again including it. |
| 400 | invalid_idempotency_key_length | The value sent in the "X-Idempotency-Key" header exceeded the allowed size. The header accepts values between 1 and 128 characters. |
| 400 | invalid_path_param | The "order_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 400 | required_properties | There are some required properties missing. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | unsupported_properties | An unsupported property was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | minimum_properties | The minimum number of properties required to execute the request was not sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | property_type | The wrong property type was submitted. For example, an "integer" value for a "string" property. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | minimum_items | The minimum number of items for some property was not sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | maximum_items | A greater number of items were sent than allowed for some property. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | property_value | An incorrect value for some property was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | json_syntax_error | An incorrect JSON was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | invalid_properties | Incorrect information was sent. Check the message returned in the error details to find out what the problem was and try again. |
| 400 | exceeded_number_of_transactions | An error occurred in the request. The order accepts a maximum of one transaction. Remove the excess transactions. |
| 400 | invalid_order_mode_for_operation | This operation is not allowed in the mode defined for order processing. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 409 | idempotency_key_already_used | The value sent as the idempotency header (`X-Idempotency-Key`) has already been used. Please try the request again sending a new value. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | idempotency_validation_failed | Validation fail. Please try submitting the request again. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders/{order_id}/transactions' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "payments": [
  {
  "amount": "24.50",
  "payment_method": {
  "id": "visa",
  "type": "credit_card",
  "token": "12345",
  "installments": 1,
  "statement_descriptor": "My Store"
  }
  }
  ]
  }'
```

## Response example

```json
{
  "payments": [
  {
  "id": "PAY01J49MMW3SSBK5PSV3DFR32959",
  "amount": "24.50",
  "date_of_expiration": "2027-12-31T10:00:00.000-04:00",
  "status": "created",
  "status_detail": "created",
  "payment_method": {
  "id": "visa",
  "type": "credit_card",
  "token": "12345",
  "installments": 1,
  "installment_amount": "8.30",
  "statement_descriptor": "My Store"
  }
  }
  ]
}
```

# Process order by ID

This endpoint allows executing the processing of an order and its transactions using the reference ID obtained in the response to its creation. In case of success, the request will return a response with status 200.

**POST** `/v1/orders/{order_id}/process`

## Request parameters

### Header

- `X-Idempotency-Key` (string, required)
  This function allows you to repeat requests safely, without the risk of carrying out the same action more than once by mistake. This is useful to avoid mistakes such as creating two identical payments. To ensure that each request is unique, it is important to use a unique value in your request header. We suggest using a V4 UUID or random strings. The header accepts values between 1 and 128 characters.

### Path

- `order_id` (string, required)
  ID of the order that is being processed. This value is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request.

## Response parameters

- `id` (string, optional)
  Identifier of the order created in the request, automatically generated by Mercado Pago.

- `processing_mode` (string, optional)
  Order processing mode
Possible enum values:

  - `manual`
  Order's processing will be made manually. It is the processing mode used for the `manual` option, as it sets the processing to be made afterwards, by using the ([/v1/orders/{order_id}/process](/developers/en/reference/online-payments/checkout-api/process-order/post)) endpoint.

  - `automatic`
  Order's processing will be made instantly. It is the capture mode used for the `automatic` option.

- `external_reference` (string, optional)
  It is an external reference of the order. It can be, for example, a hashcode from the Central Bank, functioning as an identifier of the transaction origin. This field must have a maximum of 64 characters and can only be numbers, letters, hyphens (-) and underscores (_). Special characters ([ ], (), '', @) are not allowed. Required only for Pix payments.

- `total_amount` (string, optional)
  Total amount to be paid.

- `total_paid_amount` (string, optional)
  Total amount to be paid, represents the sum of all the transaction's "paid_amount" values.

- `integration_data` (object, optional)
  Additional information that can be used to integrate with other systems, such as the identifier of the order in the integrator's system.

  - `integration_data.application_id` (string, optional)
  Identifier of the Mercado Pago application that created the order.

  - `integration_data.integrator_id` (string, optional)
  Identifier of the integrator in Mercado Pago. It is the unique identifier of the integrator in Mercado Pago's systems.

  - `integration_data.platform_id` (string, optional)
  Identifier of the platform in Mercado Pago. It is the unique identifier of the platform in Mercado Pago's systems.

  - `integration_data.sponsor` (object, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

  - `integration_data.sponsor.id` (string, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

- `user_id` (string, optional)
  Identifier of the user to which the Mercado Pago application that created the order belongs. It is the person that will receive the payment.

- `created_date` (string, optional)
  Order's creation date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `last_updated_date` (string, optional)
  Order's last update date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `country_code` (string, optional)
  Identifier of the site (country) to which the Mercado Pago application that created the order belongs.

- `type` (string, optional)
  Order type, associated with the Mercado Pago solution for which it is created. For online card payments, the only possible value is `online`.
Possible enum values:

  - `online`
  Value associated with the creation of Orders for online payments.

- `status` (string, optional)
  Current status of the order.
Possible enum values:

  - `processed`
  All transactions have been succesfully processed.

  - `action_required`
  Integrator action is required to complete processing. For example, the capture of an authorized payment.

  - `processing`
  The order is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

- `status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `waiting_capture`
  In cases of "status=action_required", integrator action is required to complete processing. This "status_detail" indicates that the capture of an authorized payment is needed.

  - `waiting_retry`
  In cases of "status=action_required", the order is in the automatic retry window after a failed charge. A new payment attempt is scheduled before the configured maximum number of retries is reached.

  - `pending_review_manual`
  The payment is pending manual review.

  - `in_process`
  The payment is being processed.

- `capture_mode` (string, optional)
  Order capture mode.
Possible enum values:

  - `manual`
  Order's capture will be made manually. It allows the reservation of the transaction value in the payer's card, so the capture can be made afterwards, by using the ([/v1/orders/{order_id}/capture](/developers/en/reference/online-payments/checkout-api/capture-order/post)) endpoint.

  - `automatic`
  Order's capture will be made automatically. Authorize and capture values at the same time.

  - `automatic_async`
  The order can be processed asynchronously. The order may remain in `status=processing` awaiting asynchronous update and the final status will be updated later through webhooks or queries.

- `transactions` (object, optional)
  Contains information about the transactions associated with the order.

  - `transactions.payments` (array, optional)
  Contains information about the payment associated with the order.

  - `transactions.payments[].id` (string, optional)
  Identifier of the payment transaction created in the request, automatically generated by Mercado Pago.

  - `transactions.payments[].amount` (string, optional)
  Transaction amount.

  - `transactions.payments[].paid_amount` (string, optional)
  Transaction paid amount. Represents the real amount paid including discounts or tips.

  - `transactions.payments[].taxes_amount` (string, optional)
  Amount corresponding to taxes applied to the transaction. Not returned when not provided by the payment processor. The field can contain two decimal places or none.

  - `transactions.payments[].date_of_expiration` (string, optional)
  Date and time of the expiration of the payment. It is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request. If an `expiration_time` is not sent, this field adopts a default value that depends on the payment method.

  - `transactions.payments[].reference_id` (string, optional)
  Reference ID of the transaction.

  - `transactions.payments[].status` (string, optional)
  Payment status.
Possible enum values:

  - `processed`
  All transactions have been succesfully processed.

  - `action_required`
  Integrator action is required to complete processing. For example, the capture of an authorized payment.

  - `processing`
  The order is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

  - `transactions.payments[].status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `waiting_capture`
  In cases of "status=action_required", integrator action is required to complete processing. This "status_detail" indicates that the capture of an authorized payment is needed.

  - `waiting_retry`
  In cases of "status=action_required", the order is in the automatic retry window after a failed charge. A new payment attempt is scheduled before the configured maximum number of retries is reached.

  - `pending_review_manual`
  The payment is pending manual review.

  - `in_process`
  The payment is being processed.

  - `transactions.payments[].payment_method` (object, optional)
  Information about the payment method used in the transaction.

  - `transactions.payments[].payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `transactions.payments[].payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `transactions.payments[].payment_method.token` (string, optional)
  Token that identifies the card and contains its data securely. Only required for "card" payments. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `transactions.payments[].payment_method.installments` (integer, optional)
  Number of installments selected.

  - `transactions.payments[].payment_method.installment_amount` (string, optional)
  Amount per installment. The field can contain two decimal places or none.

  - `transactions.payments[].payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts up to 50 characters.

  - `transactions.payments[].expiration_time` (string, optional)
  Transaction expiration date.

- `description` (string, optional)
  Description of the purchased product or service , the reason for the payment order, or the description of a product in the marketplace.

- `items` (array, optional)
  Information about the list of items to be paid.

  - `items[].title` (string, optional)
  Item name. The character limit is 150.

  - `items[].unit_price` (string, optional)
  Unit price of the purchased item. This field must have a maximum of 18 characters.

  - `items[].quantity` (Integer, optional)
  Purchased items quantity.

  - `items[].description` (string, optional)
  Purchased item description. The character limit is 100.

  - `items[].external_code` (string, optional)
  Item External code.

  - `items[].picture_url` (string, optional)
  Image URL corresponding to the item.

  - `items[].category_id` (string, optional)
  Item category ID.

  - `items[].type` (string, optional)
  Item type.

  - `items[].warranty` (boolean, optional)
  If the item has a warranty.

  - `items[].event_date` (string, optional)
  Event date.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | empty_required_header | The "X-Idempotency-Key" header is required and was not sent. Make the requisition again including it. |
| 400 | invalid_idempotency_key_length | The value sent in the "X-Idempotency-Key" header exceeded the allowed size. The header accepts values between 1 and 128 characters. |
| 400 | invalid_path_param | The "order_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 400 | invalid_total_amount | The value entered in "total_amount" is not equivalent to the sum of the "transactions.payments.amount" field of the total transactions. Please verify if the values ​​are correct. |
| 400 | invalid_order_mode_for_operation | This operation is not allowed in the mode defined for order processing. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 402 | 402 | Order was created but some transaction failed. Check the "errors" field for more information. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 409 | idempotency_key_already_used | The value sent as the idempotency header (`X-Idempotency-Key`) has already been used. Please try the request again sending a new value. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | idempotency_validation_failed | Validation fail. Please try submitting the request again. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders/{order_id}/process' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "id": "ORD01J49MMW3SSBK5PSV3DFR32959",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "50.00",
  "total_paid_amount": "50.00",
  "integration_data": {
  "application_id": "1234",
  "integrator_id": "dev_123",
  "platform_id": "1234567890",
  "sponsor": {
  "id": "<YOUR_SPONSOR_ID>"
  }
  },
  "user_id": "12345",
  "created_date": "2024-08-26T13:06:51.045317772Z",
  "last_updated_date": "2024-08-26T13:06:51.045317772Z",
  "country_code": "BR",
  "type": "online",
  "status": "processed",
  "status_detail": "accredited",
  "capture_mode": "automatic",
  "transactions": {
  "payments": [
  {
  "id": "PAY01J67CQQH5904WDBVZEM4JMEP3",
  "amount": "24.50",
  "paid_amount": "47.28",
  "taxes_amount": "0.50",
  "date_of_expiration": "2027-12-31T10:00:00.000-04:00",
  "reference_id": "01JEVQM899NWSQC4FYWWW7KTF9",
  "status": "processed",
  "status_detail": "accredited",
  "payment_method": {
  "id": null,
  "type": null,
  "token": null,
  "installments": null,
  "installment_amount": null,
  "statement_descriptor": null
  },
  "expiration_time": "P3Y6M4DT12H30M5S"
  }
  ]
  },
  "description": "Smartphone",
  "items": [
  {
  "title": "Smartphone",
  "unit_price": "24.50",
  "quantity": 1,
  "description": "Smartphone",
  "external_code": "1234",
  "picture_url": "https://http2.mlstatic.com/resources/frontend/statics/growth-sellers-landings/device-mlb-point-i_medium2x.png",
  "category_id": "MLB1055",
  "type": "MLB1055",
  "warranty": "true",
  "event_date": "2014-06-28T16:53:03.176-04:00"
  }
  ]
}
```

# Delete a transaction from the order

This endpoint allows you to delete a payment transaction from the order. In case of success, the request will return a response with status 204.

**DELETE** `/v1/orders/{order_id}/transactions/{transaction_id}`

## Request parameters

### Path

- `order_id` (string, required)
  Order ID, returned in the response to the request made for its creation.

- `transaction_id` (string, required)
  Identifier of the payment transaction that will be deleted from the order. This ID is automatically generated by Mercado Pago when the request is created or when the transaction is added later to the order.

## Response parameters

This endpoint has no response body.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | invalid_path_param | The "order_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 400 | invalid_transaction_id | The "transaction_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X DELETE \
  'https://api.mercadopago.com/v1/orders/{order_id}/transactions/{transaction_id}' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
"string"
```

# Search order

This endpoint allows you to search for orders in a massive way, using various filters and pagination information. In case of success, the request will return a response with status 200.

**GET** `/v1/orders`

## Request parameters

### Query

- `begin_date` (string, required)
  Start date to filter orders by creation date (RFC 3339 format).

- `end_date` (string, required)
  End date for filtering orders by creation date (RFC 3339 format).

- `external_reference` (string, optional)
  It is an external reference of the order. It can be, for example, a hashcode from the Central Bank, functioning as an identifier of the transaction origin. This field must have a maximum of 64 characters and can only be numbers, letters, hyphens (-) and underscores (_). Special characters such as ([ ], (), '', @) are not allowed.

- `type` (string, optional)
  Order type, associated with the Mercado Pago solution for which it is created. For online card payments, the only possible value is "online".

- `status` (string, optional)
  Current status of the order.

- `status_detail` (string, optional)
  Details about payment status.

- `payment_method_id` (string, optional)
  Identifier of the payment method.

- `payment_method_type` (string, optional)
  Type of payment method.

- `page` (integer, optional)
  Page number for pagination. The minimum value is 1 page.

- `page_size` (integer, optional)
  Number of results per page. The maximum value is 100 results per page and, in case it is not filled, the default value is 20 results per page.

- `sort_by` (string, optional)
  Sorts the results by creation date or last update date (default: "created_date").

- `sort_order` (string, optional)
  Sorts the display of search results in descending order. The default value is "desc".

## Response parameters

- `data` (array, optional)
  List of orders matching the search criteria.

  - `data[].id` (string, optional)
  Identifier of the order, automatically generated by Mercado Pago.

  - `data[].type` (string, optional)
  Order type, associated with the Mercado Pago solution for which it is created. For online card payments, the only possible value is `online`.
Possible enum values:

  - `online`
  Value associated with the creation of Orders for online payments.

  - `data[].processing_mode` (string, optional)
  Order processing mode
Possible enum values:

  - `manual`
  Order's processing will be made manually. It is the processing mode used for the `manual` option, as it sets the processing to be made afterwards, by using the ([/v1/orders/{order_id}/process](/developers/en/reference/online-payments/checkout-api/process-order/post)) endpoint.

  - `automatic`
  Order's processing will be made instantly. It is the capture mode used for the `automatic` option.

  - `data[].external_reference` (string, optional)
  It is an external reference of the order. It can be, for example, a hashcode from the Central Bank, functioning as an identifier of the transaction origin. This field must have a maximum of 64 characters and can only be numbers, letters, hyphens (-) and underscores (_). Special characters ([ ], (), '', @) are not allowed. Required only for Pix payments.

  - `data[].total_amount` (string, optional)
  Total amount to be paid.

  - `data[].total_paid_amount` (string, optional)
  Total amount to be paid, represents the sum of all the transaction's "paid_amount" values.

  - `data[].user_id` (string, optional)
  Identifier of the user to which the Mercado Pago application that created the order belongs. It is the person that will receive the payment.

  - `data[].status` (string, optional)
  Current status of the order.
Possible enum values:

  - `created`
  The order has been created successfully.

  - `processed`
  All transactions have been succesfully processed.

  - `action_required`
  Integrator action is required to complete processing. For example, the capture of an authorized payment.

  - `failed`
  An error occurred in the processing of the order. It may be due to sending incorrect data, risk of fraud, or rejections by the issuing entity of the payment method.

  - `processing`
  The order is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

  - `refunded`
  The order has been refunded.

  - `canceled`
  The order has been canceled.

  - `data[].status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `refunded`
  The payment has been refunded.

  - `partially_refunded`
  The payment has been partially refunded.

  - `waiting_capture`
  In cases of "status=action_required", integrator action is required to complete processing. This "status_detail" indicates that the capture of an authorized payment is needed.

  - `waiting_retry`
  In cases of "status=action_required", the order is in the automatic retry window after a failed charge. A new payment attempt is scheduled before the configured maximum number of retries is reached.

  - `bad_filled_card_data`
  An error occurred in the processing due to sending incorrect card data.

  - `invalid_card_token`
  An error occurred in the processing due to sending an incorrect card token.

  - `high_risk`
  The transaction was rejected due to fraud prevention.

  - `rejected_by_issuer`
  The transaction failed because authorization was required.

  - `required_call_for_authorize`
  The transaction was rejected by the card issuer.

  - `max_attempts_exceeded`
  The transaction was rejected for exceeding the maximum number of attempts to complete it.

  - `card_disabled`
  The card chosen for the transaction is disabled.

  - `card_insufficient_amount`
  The card chosen for the transaction does not have enough founds.

  - `amount_limit_exceeded`
  The transaction amount exceeds the card limit.

  - `invalid_installments`
  There was an error during processing.

  - `processing_error`
  There was an error during processing.

  - `pending_review_manual`
  The payment is pending manual review.

  - `in_process`
  The payment is being processed.

  - `canceled`
  The order has been canceled.

  - `data[].capture_mode` (string, optional)
  Order capture mode.
Possible enum values:

  - `manual`
  Order's capture will be made manually. It allows the reservation of the transaction value in the payer's card, so the capture can be made afterwards, by using the ([/v1/orders/{order_id}/capture](/developers/en/reference/online-payments/checkout-api/capture-order/post)) endpoint.

  - `automatic`
  Order's capture will be made automatically. Authorize and capture values at the same time.

  - `automatic_async`
  The order can be processed asynchronously. The order may remain in `status=processing` awaiting asynchronous update and the final status will be updated later through webhooks or queries.

  - `data[].currency` (string, optional)
  Identifier of the currency used in the order.

  - `data[].created_date` (string, optional)
  Order's creation date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

  - `data[].last_updated_date` (string, optional)
  Order's last update date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

  - `data[].integration_data` (object, optional)
  Additional information that can be used to integrate with other systems, such as the identifier of the order in the integrator's system.

  - `data[].integration_data.application_id` (string, optional)
  Identifier of the Mercado Pago application that created the order.

  - `data[].integration_data.integrator_id` (string, optional)
  Identifier of the integrator in Mercado Pago. It is the unique identifier of the integrator in Mercado Pago's systems.

  - `data[].integration_data.platform_id` (string, optional)
  Identifier of the platform in Mercado Pago. It is the unique identifier of the platform in Mercado Pago's systems.

  - `data[].integration_data.sponsor` (object, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

  - `data[].integration_data.sponsor.id` (string, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

  - `data[].transactions` (object, optional)
  Contains information about the transactions associated with the order.

  - `data[].transactions.payments` (array, optional)
  Contains information about the payment associated with the order.

  - `data[].transactions.payments[].id` (string, optional)
  Identifier of the payment transaction, automatically generated.

  - `data[].transactions.payments[].amount` (string, optional)
  Transaction amount.

  - `data[].transactions.payments[].paid_amount` (string, optional)
  Transaction paid amount. Represents the real amount paid including discounts or tips.

  - `data[].transactions.payments[].reference_id` (string, optional)
  Reference ID of the transaction.

  - `data[].transactions.payments[].status` (string, optional)
  Payment status.

  - `data[].transactions.payments[].status_detail` (string, optional)
  Details about payment status.

  - `data[].transactions.payments[].payment_method` (object, optional)
  Information about the payment method.

  - `data[].transactions.payments[].payment_method.id` (string, optional)
  Identifier of the payment method.

  - `data[].transactions.payments[].payment_method.type` (string, optional)
  Type of payment method.

  - `data[].transactions.payments[].payment_method.installments` (integer, optional)
  Number of installments.

  - `data[].transactions.payments[].payment_method.transaction_security` (object, optional)
  Transaction security configuration for 3DS (3D Secure), an authentication protocol used in online transactions with card. After creating the order, the response will indicate if the challenge is required. If not required, the "status" field will have the value "processed", allowing you to continue normally with the application flow. If the challenge is required, the order will be returned with "status=action_required", "status_detail=pending_challenge" and the challenge URL will be available in the "url" field. The challenge must be displayed in an iframe using the URL returned, allowing the buyer to complete authentication without leaving the checkout flow. The buyer has 40 minutes to complete the challenge from when the URL is created. If not completed within this period, the bank will reject the transaction and Mercado Pago will consider the payment expired.

  - `data[].transactions.payments[].payment_method.transaction_security.validation` (string, optional)
  Defines when the 3DS (3D Secure) flow should be executed.
Possible enum values:

  - `on_fraud_risk`
  3DS (3D Secure) is required according to transaction risk. Recommended to balance security and transaction approval.

  - `never`
  The 3DS (3D Secure) flow and challenge are never executed. This is the default value if the field is not sent.

  - `data[].transactions.payments[].payment_method.transaction_security.liability_shift` (string, optional)
  Defines the financial responsibility in case of dispute. Should not be sent when "validation" is "never".
Possible enum values:

  - `required`
  The financial responsibility in case of dispute is of the card brand. This is the only value accepted for 3DS (3D Secure).

  - `data[].transactions.payments[].payment_method.transaction_security.url` (string, optional)
  URL of the challenge displayed after creating an order returned with "status=action_required" and "status_detail=pending_challenge". The challenge must be displayed in an iframe using the returned URL, allowing the buyer to complete authentication without leaving the checkout flow. The buyer has 40 minutes to complete the challenge from when the URL is created. If not completed within this period, the bank will reject the transaction and Mercado Pago will consider the payment expired.

  - `data[].transactions.payments[].payment_method.transaction_security.id` (string, optional)
  ID of the challenge of the 3DS (3D Secure) security protocol.

  - `data[].transactions.payments[].payment_method.transaction_security.type` (string, optional)
  Type of challenge. In the case of 3DS (3D Secure), the only possible value is "three_ds".

  - `data[].transactions.payments[].payment_method.transaction_security.status` (string, optional)
  Status of the challenge in the 3DS (3D Secure) security protocol.
Possible enum values:

  - `AUTHENTICATED`
  Authentication performed by the responsible bank and forwarded to card brand validation.

  - `NOT_AUTHENTICATED`
  The challenge was not performed correctly or the responsible bank did not authorize the transaction due to some possible risk.

  - `CHALLENGE`
  The bank requested a challenge from the buyer and it has not yet been completed.

  - `ATTEMPTED`
  Authentication performed by the card brand.

  - `REJECTED`
  The responsible bank rejected the authentication due to some possible risk and also denied the possibility of challenge.

  - `ERROR`
  Missing some information required for 3DS authentication. Example: the "device_id" field was not filled.

- `paging` (object, optional)
  Pagination information.

  - `paging.total` (string, optional)
  Total number of orders matching the search criteria.

  - `paging.total_pages` (string, optional)
  Total number of pages available.

  - `paging.offset` (string, optional)
  Number of items skipped.

  - `paging.limit` (string, optional)
  Maximum number of items returned per page.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | required_search_params | Required search parameters are missing. The "begin_date" and "end_date" parameters are mandatory for the search. |
| 400 | invalid_search_params | One or more search parameters are invalid. This can occur with invalid date formats (must use RFC3339 format, e.g.: "2023-01-01T00:00:00Z"), invalid pagination values ("page" and "page_size"), invalid sorting values ("sort_by", "sort_order") or invalid filter values ("status", "type", "payment_method_id", etc.). Check the error message details for specific information. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X GET \
  'https://api.mercadopago.com/v1/orders?begin_date=<BEGIN_DATE>&end_date=<END_DATE>&external_reference=<EXTERNAL_REFERENCE>&type=<TYPE>&status=<STATUS>&status_detail=<STATUS_DETAIL>&payment_method_id=<PAYMENT_METHOD_ID>&payment_method_type=<PAYMENT_METHOD_TYPE>&page=<PAGE>&page_size=<PAGE_SIZE>&sort_by=<SORT_BY>&sort_order=<SORT_ORDER>' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "data": [
  {
  "id": "ORD01K9SN1Q5959CGN8QGW00EH7V5",
  "type": "online",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "50.00",
  "total_paid_amount": "50.00",
  "user_id": "12345",
  "status": "processed",
  "status_detail": "accredited",
  "capture_mode": "automatic",
  "currency": "BRL",
  "created_date": "2024-08-26T13:06:51.045317772Z",
  "last_updated_date": "2024-08-26T13:06:51.045317772Z",
  "integration_data": {
  "application_id": "1234",
  "integrator_id": "dev_123",
  "platform_id": "1234567890",
  "sponsor": {
  "id": null
  }
  },
  "transactions": {
  "payments": [
  null
  ]
  }
  }
  ],
  "paging": {
  "total": "54",
  "total_pages": "3",
  "offset": "0",
  "limit": "20"
  }
}
```

# Get order by ID

This endpoint allows consulting all information on an order using the ID obtained in the response to its creation. In case of success, the request will return a response with status 200.

**GET** `/v1/orders/{id}`

## Request parameters

### Path

- `id` (string, required)
  Order ID, returned in the response to the request made for its creation.

## Response parameters

- `id` (string, optional)
  Identifier of the order created in the request, automatically generated by Mercado Pago.

- `processing_mode` (string, optional)
  Order processing mode
Possible enum values:

  - `manual`
  Order's processing will be made manually. It is the processing mode used for the `manual` option, as it sets the processing to be made afterwards, by using the ([/v1/orders/{order_id}/process](/developers/en/reference/online-payments/checkout-api/process-order/post)) endpoint.

  - `automatic`
  Order's processing will be made instantly. It is the capture mode used for the `automatic` option.

- `external_reference` (string, optional)
  It is an external reference of the order. It can be, for example, a hashcode from the Central Bank, functioning as an identifier of the transaction origin. This field must have a maximum of 64 characters and can only be numbers, letters, hyphens (-) and underscores (_). Special characters ([ ], (), '', @) are not allowed. Required only for Pix payments.

- `total_amount` (string, optional)
  Total amount to be paid.

- `integration_data` (object, optional)
  Additional information that can be used to integrate with other systems, such as the identifier of the order in the integrator's system.

  - `integration_data.application_id` (string, optional)
  Identifier of the Mercado Pago application that created the order.

  - `integration_data.integrator_id` (string, optional)
  Identifier of the integrator in Mercado Pago. It is the unique identifier of the integrator in Mercado Pago's systems.

  - `integration_data.platform_id` (string, optional)
  Identifier of the platform in Mercado Pago. It is the unique identifier of the platform in Mercado Pago's systems.

  - `integration_data.sponsor` (object, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

  - `integration_data.sponsor.id` (string, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

- `user_id` (string, optional)
  Identifier of the user to which the Mercado Pago application that created the order belongs. It is the person that will receive the payment.

- `created_date` (string, optional)
  Order's creation date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `last_updated_date` (string, optional)
  Order's last update date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `country_code` (string, optional)
  Identifier of the site (country) to which the Mercado Pago application that created the order belongs.

- `type` (string, optional)
  Order type, associated with the Mercado Pago solution for which it is created. For online card payments, the only possible value is `online`.
Possible enum values:

  - `online`
  Value associated with the creation of Orders for online payments.

- `status` (string, optional)
  Current status of the order.
Possible enum values:

  - `created`
  The order has been created successfully.

  - `processed`
  All transactions have been succesfully processed.

  - `action_required`
  Integrator action is required to complete processing. For example, the capture of an authorized payment.

  - `failed`
  An error occurred in the processing of the order. It may be due to sending incorrect data, risk of fraud, or rejections by the issuing entity of the payment method.

  - `processing`
  The order is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

  - `refunded`
  The order has been refunded.

  - `canceled`
  The order has been canceled.

- `status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `refunded`
  The payment has been refunded.

  - `partially_refunded`
  The payment has been partially refunded.

  - `waiting_capture`
  In cases of "status=action_required", integrator action is required to complete processing. This "status_detail" indicates that the capture of an authorized payment is needed.

  - `waiting_retry`
  In cases of "status=action_required", the order is in the automatic retry window after a failed charge. A new payment attempt is scheduled before the configured maximum number of retries is reached.

  - `bad_filled_card_data`
  An error occurred in the processing due to sending incorrect card data.

  - `invalid_card_token`
  An error occurred in the processing due to sending an incorrect card token.

  - `high_risk`
  The transaction was rejected due to fraud prevention.

  - `rejected_by_issuer`
  The transaction failed because authorization was required.

  - `required_call_for_authorize`
  The transaction was rejected by the card issuer.

  - `max_attempts_exceeded`
  The transaction was rejected for exceeding the maximum number of attempts to complete it.

  - `card_disabled`
  The card chosen for the transaction is disabled.

  - `card_insufficient_amount`
  The card chosen for the transaction does not have enough founds.

  - `amount_limit_exceeded`
  The transaction amount exceeds the card limit.

  - `invalid_installments`
  There was an error during processing.

  - `processing_error`
  There was an error during processing.

  - `pending_review_manual`
  The payment is pending manual review.

  - `in_process`
  The payment is being processed.

  - `canceled`
  The order has been canceled.

- `capture_mode` (string, optional)
  Order capture mode.
Possible enum values:

  - `manual`
  Order's capture will be made manually. It allows the reservation of the transaction value in the payer's card, so the capture can be made afterwards, by using the ([/v1/orders/{order_id}/capture](/developers/en/reference/online-payments/checkout-api/capture-order/post)) endpoint.

  - `automatic`
  Order's capture will be made automatically. Authorize and capture values at the same time.

  - `automatic_async`
  The order can be processed asynchronously. The order may remain in `status=processing` awaiting asynchronous update and the final status will be updated later through webhooks or queries.

- `total_paid_amount` (string, optional)
  Total amount to be paid, represents the sum of all the transaction's "paid_amount" values.

- `transactions` (object, optional)
  Contains information about the transactions associated with the order.

  - `transactions.payments` (array, optional)
  Contains information about the payment associated with the order.

  - `transactions.payments[].id` (string, optional)
  Identifier of the payment transaction created in the request, automatically generated by Mercado Pago.

  - `transactions.payments[].amount` (string, optional)
  Transaction amount.

  - `transactions.payments[].paid_amount` (string, optional)
  Transaction paid amount. Represents the real amount paid including discounts or tips.

  - `transactions.payments[].taxes_amount` (string, optional)
  Amount corresponding to taxes applied to the transaction. Not returned when not provided by the payment processor. The field can contain two decimal places or none.

  - `transactions.payments[].date_of_expiration` (string, optional)
  Date and time of the expiration of the payment. It is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request. If an `expiration_time` is not sent, this field adopts a default value that depends on the payment method.

  - `transactions.payments[].reference_id` (string, optional)
  Reference ID of the transaction.

  - `transactions.payments[].status` (string, optional)
  Payment status.
Possible enum values:

  - `created`
  The order has been created successfully.

  - `processed`
  The payment has been succesfully processed.

  - `action_required`
  Integrator action is required to complete processing. For example, the capture of an authorized payment.

  - `canceled`
  The payment has been canceled and cannot be processed.

  - `failed`
  An error occurred in the processing of the payment. It may be due to sending incorrect data, risk of fraud, or rejections by the issuing entity of the payment method.

  - `processing`
  The payment is being processed and does not require any action from the integrator. For example, the payment may be pending manual review.

  - `charged_back`
  A chargeback was filed and the payment is being contested.

  - `refunded`
  The order has been refunded.

  - `transactions.payments[].status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `accredited`
  Payment accredited.

  - `partially_refunded`
  The payment has been partially refunded.

  - `waiting_capture`
  In cases of "status=action_required", integrator action is required to complete processing. This "status_detail" indicates that the capture of an authorized payment is needed.

  - `waiting_retry`
  In cases of "status=action_required", the order is in the automatic retry window after a failed charge. A new payment attempt is scheduled before the configured maximum number of retries is reached.

  - `bad_filled_card_data`
  An error occurred in the processing due to sending incorrect card data.

  - `invalid_card_token`
  An error occurred in the processing due to sending an incorrect card token.

  - `high_risk`
  The transaction was rejected due to fraud prevention.

  - `rejected_by_issuer`
  The transaction failed because authorization was required.

  - `required_call_for_authorize`
  The transaction was rejected by the card issuer.

  - `max_attempts_exceeded`
  The transaction was rejected for exceeding the maximum number of attempts to complete it.

  - `card_disabled`
  The card chosen for the transaction is disabled.

  - `card_insufficient_amount`
  The card chosen for the transaction does not have enough founds.

  - `amount_limit_exceeded`
  The transaction amount exceeds the card limit.

  - `processing_error`
  There was an error during processing.

  - `invalid_installments`
  The installments amount selected when creating the transaction is invalid.

  - `pending_review_manual`
  The payment is pending manual review.

  - `in_process`
  When the "status=processing", the payment is being processed. When "status=charged_back", the chargeback is being process.

  - `canceled_transaction`
  The transaction has been canceled.

  - `settled`
  If "status=charged_back", an agreement was reached and the chargeback process has been completed.

  - `reimbursed`
  The transaction amount has been returned.

  - `refunded`
  The payment has been refunded.

  - `transactions.payments[].payment_method` (object, optional)
  Information about the payment method. Access the endpoint [/v1/payment_methods](/developers/en/reference/online-payments/checkout-api/payment-methods/get) to check all available payment methods and get a list with the details of each one and their properties.

  - `transactions.payments[].payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `debmaster`
  "Master debit" card.

  - `debvisa`
  "Visa debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `transactions.payments[].payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `transactions.payments[].payment_method.token` (string, optional)
  It is a mandatory field for card payments, as it is the token that identifies the card and contains its data securely. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the card payment configuration in the Checkout Transparente documentation.

  - `transactions.payments[].payment_method.installments` (integer, optional)
  Number of installments selected.

  - `transactions.payments[].payment_method.installment_amount` (string, optional)
  Amount per installment. The field can contain two decimal places or none.

  - `transactions.payments[].payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts maximum of 50 characters.

  - `transactions.payments[].payment_method.ticket_url` (string, optional)
  Ticket URL. It is returned for payment methods such as Pix and boleto.

  - `transactions.payments[].payment_method.barcode_content` (string, optional)
  Barcode content. It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.reference` (string, optional)
  Reference number. It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.verification_code` (string, optional)
  Verification code. It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.financial_institution` (string, optional)
  Financial institution. It is returned for payment methods such as It is returned for the &quot;boleto&quot; payment method.

  - `transactions.payments[].payment_method.digitable_line` (string, optional)
  Digitable line. It is returned for the payment method "boleto".

  - `transactions.payments[].payment_method.qr_code` (string, optional)
  QR code. It is returned for payment method "Pix".

  - `transactions.payments[].payment_method.qr_code_base64` (string, optional)
  QR code in base64. It is returned for payment method "Pix".

  - `transactions.payments[].payment_method.e2e_id` (string, optional)
  Unique and mandatory code generated for each "Pix" transaction, serving as a tracking proof that identifies the operation from start to end (end-to-end).

  - `transactions.chargebacks` (array, optional)
  Contains information about the chargebacks associated with the order.

  - `transactions.chargebacks[].id` (string, optional)
  Identifier of the chargeback transaction, automatically generated by Mercado Pago.

  - `transactions.chargebacks[].transaction_id` (string, optional)
  Identifier of the payment transaction associated with the chargeback, automatically generated by Mercado Pago.

  - `transactions.chargebacks[].case_id` (string, optional)
  Identifier of the chargeback case.

  - `transactions.chargebacks[].status` (string, optional)
  Status of the chargeback.
Possible enum values:

  - `in_process`
  The chargeback is in process.

  - `settled`
  The chargeback has been settled.

  - `reimbursed`
  The chargeback has been reimbursed.

  - `transactions.chargebacks[].references` (array, optional)

- `description` (string, optional)
  Description of the purchased product or service , the reason for the payment order, or the description of a product in the marketplace.

- `items` (array, optional)
  Information about the list of items to be paid.

  - `items[].title` (string, optional)
  Item name. The character limit is 150.

  - `items[].unit_price` (string, optional)
  Unit price of the purchased item. This field must have a maximum of 18 characters.

  - `items[].quantity` (Integer, optional)
  Purchased items quantity.

  - `items[].description` (string, optional)
  Purchased item description. The character limit is 100.

  - `items[].external_code` (string, optional)
  Item External code.

  - `items[].picture_url` (string, optional)
  Image URL corresponding to the item.

  - `items[].category_id` (string, optional)
  Item category ID.

  - `items[].type` (string, optional)
  Item type.

  - `items[].warranty` (boolean, optional)
  If the item has a warranty.

  - `items[].event_date` (string, optional)
  Event date.

- `expiration_time` (string, optional)
  Transaction expiration date.

- `client_token` (string, optional)
  Authentication token to execute operations on the client side.

- `config` (object, optional)
  Contains the payment method configuration that was applied to the order, including default payment method type, installments configuration, and who pays the installments cost. This information reflects the actual configuration that is being used for the order and can be used to display payment options to the buyer in the checkout interface.

  - `config.payment_method` (object, optional)
  Payment method configuration.

  - `config.payment_method.default_type` (string, optional)
  Default payment method type.

  - `config.payment_method.installments_cost` (string, optional)
  Responsible for the installments cost. Possible values: "seller" or "buyer".

  - `config.payment_method.installments` (object, optional)
  Installments configuration.

  - `config.payment_method.installments.interest_free` (object, optional)
  Interest-free installments configuration.

  - `config.payment_method.installments.interest_free.type` (string, optional)
  Type of interest-free installments. Possible values: "range" or "list".

  - `config.payment_method.installments.interest_free.values` (array, optional)
  Available interest-free installment values.

  - `config.payment_method.installments.available` (object, optional)
  Available installments configuration.

  - `config.payment_method.installments.available.type` (string, optional)
  Type of available installments.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | invalid_path_param | The order_id provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X GET \
  'https://api.mercadopago.com/v1/orders/{id}' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "id": "ORD01J49MMW3SSBK5PSV3DFR32959",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "50.00",
  "integration_data": {
  "application_id": "1234",
  "integrator_id": "dev_123",
  "platform_id": "1234567890",
  "sponsor": {
  "id": "<YOUR_SPONSOR_ID>"
  }
  },
  "user_id": "12345",
  "created_date": "2024-08-26T13:06:51.045317772Z",
  "last_updated_date": "2024-08-26T13:06:51.045317772Z",
  "country_code": "BR",
  "type": "online",
  "status": "processed",
  "status_detail": "accredited",
  "capture_mode": "automatic",
  "total_paid_amount": "50.00",
  "transactions": {
  "payments": [
  {
  "id": "PAY01J67CQQH5904WDBVZEM4JMEP3",
  "amount": "24.50",
  "paid_amount": "47.28",
  "taxes_amount": "0.50",
  "date_of_expiration": "2027-12-31T10:00:00.000-04:00",
  "reference_id": "01JEVQM899NWSQC4FYWWW7KTF9",
  "status": "processed",
  "status_detail": "accredited",
  "payment_method": {
  "id": null,
  "type": null,
  "token": null,
  "installments": null,
  "installment_amount": null,
  "statement_descriptor": null,
  "ticket_url": null,
  "barcode_content": null,
  "reference": null,
  "verification_code": null,
  "financial_institution": null,
  "digitable_line": null,
  "qr_code": null,
  "qr_code_base64": null,
  "e2e_id": null
  }
  }
  ],
  "chargebacks": [
  {
  "id": "CBK01J67CQQH5904WDBVZEM4JMEP3",
  "transaction_id": "PAY01J67CQQH5904WDBVZEM4JMEP3",
  "case_id": "1234567890",
  "status": "in_process",
  "references": [
  null
  ]
  }
  ]
  },
  "description": "Smartphone",
  "items": [
  {
  "title": "Smartphone",
  "unit_price": "24.50",
  "quantity": 1,
  "description": "Smartphone",
  "external_code": "1234",
  "picture_url": "https://http2.mlstatic.com/resources/frontend/statics/growth-sellers-landings/device-mlb-point-i_medium2x.png",
  "category_id": "MLB1055",
  "type": "MLB1055",
  "warranty": "true",
  "event_date": "2014-06-28T16:53:03.176-04:00"
  }
  ],
  "expiration_time": "P3Y6M4DT12H30M5S",
  "client_token": "QWERTY12345.ASDFG67890",
  "config": {
  "payment_method": {
  "default_type": "credit_card",
  "installments_cost": "seller",
  "installments": {
  "interest_free": {
  "type": null,
  "values": null
  },
  "available": {
  "type": null
  }
  }
  }
  }
}
```

# Cancel order by ID

This endpoint allows canceling an order and its transactions using the reference ID obtained in the response to its creation. Only an order with `status=action_required` or `status=created` can be canceled. In case of success, the request will return a response with status 200.

**POST** `/v1/orders/{order_id}/cancel`

## Request parameters

### Header

- `X-Idempotency-Key` (string, required)
  This feature allows you to safely retry requests without the risk of accidentally performing the same action more than once. This is useful for avoiding errors, such as creating two identical payments. To ensure that each request is unique, it's important to use an exclusive value in the header of your request. We suggest using a UUID V4 or random strings. The header accepts values between 1 and 128 characters.

### Path

- `order_id` (string, required)
  ID of the order that is being canceled. This value is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request.

## Response parameters

- `id` (string, optional)
  Identifier of the order created in the request, automatically generated by Mercado Pago.

- `processing_mode` (string, optional)
  Order processing mode
Possible enum values:

  - `manual`
  Order's processing will be made manually. It is the processing mode used for the `manual` option, as it sets the processing to be made afterwards, by using the ([/v1/orders/{order_id}/process](/developers/en/reference/online-payments/checkout-api/process-order/post)) endpoint.

  - `automatic`
  Order's processing will be made instantly. It is the capture mode used for the `automatic` option.

- `external_reference` (string, optional)
  It is an external reference of the order. It can be, for example, a hashcode from the Central Bank, functioning as an identifier of the transaction origin. This field must have a maximum of 64 characters and can only be numbers, letters, hyphens (-) and underscores (_). Special characters ([ ], (), '', @) are not allowed. Required only for Pix payments.

- `total_amount` (string, optional)
  Total amount to be paid.

- `integration_data` (object, optional)
  Additional information that can be used to integrate with other systems, such as the identifier of the order in the integrator's system.

  - `integration_data.application_id` (string, optional)
  Identifier of the Mercado Pago application that created the order.

  - `integration_data.integrator_id` (string, optional)
  Identifier of the integrator in Mercado Pago. It is the unique identifier of the integrator in Mercado Pago's systems.

  - `integration_data.platform_id` (string, optional)
  Identifier of the platform in Mercado Pago. It is the unique identifier of the platform in Mercado Pago's systems.

  - `integration_data.sponsor` (object, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

  - `integration_data.sponsor.id` (string, optional)
  Identifier of the sponsor in Mercado Pago. It is the unique identifier of the sponsor in Mercado Pago's systems.

- `user_id` (string, optional)
  Identifier of the user to which the Mercado Pago application that created the order belongs. It is the person that will receive the payment.

- `created_date` (string, optional)
  Order's creation date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `last_updated_date` (string, optional)
  Order's last update date, in "yyyy-MM-ddTHH:mm:ss.sssZ" format.

- `country_code` (string, optional)
  Identifier of the site (country) to which the Mercado Pago application that created the order belongs.

- `type` (string, optional)
  Order type, associated with the Mercado Pago solution for which it is created. For online card payments, the only possible value is `online`.
Possible enum values:

  - `online`
  Value associated with the creation of Orders for online payments.

- `status` (string, optional)
  Current status of the order.
Possible enum values:

  - `canceled`
  The order has been canceled successfully.

- `status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `canceled_transaction`
  The order has been canceled successfully.

- `capture_mode` (string, optional)
  Order capture mode.
Possible enum values:

  - `manual`
  Order's capture will be made manually. It allows the reservation of the transaction value in the payer's card, so the capture can be made afterwards, by using the ([/v1/orders/{order_id}/capture](/developers/en/reference/online-payments/checkout-api/capture-order/post)) endpoint.

  - `automatic`
  Order's capture will be made automatically. Authorize and capture values at the same time.

  - `automatic_async`
  The order can be processed asynchronously. The order may remain in `status=processing` awaiting asynchronous update and the final status will be updated later through webhooks or queries.

- `transactions` (object, optional)
  Contains information about the transactions associated with the order.

  - `transactions.payments` (array, optional)
  Contains information about the payment associated with the order.

  - `transactions.payments[].id` (string, optional)
  Identifier of the payment transaction created in the request, automatically generated by Mercado Pago.

  - `transactions.payments[].amount` (string, optional)
  Transaction amount.

  - `transactions.payments[].reference_id` (string, optional)
  Reference ID of the transaction.

  - `transactions.payments[].status` (string, optional)
  Payment status.
Possible enum values:

  - `canceled`
  The payment has been canceled and cannot be processed.

  - `transactions.payments[].status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `canceled_transaction`
  The transaction has been canceled.

  - `transactions.payments[].expiration_time` (string, optional)
  Transaction expiration date.

  - `transactions.payments[].payment_method` (object, optional)
  Information about the payment method used in the transaction.

  - `transactions.payments[].payment_method.id` (string, optional)
  Identifier of the payment method selected to make the payment. If it's a "card" payment, it will show the brand.
Possible enum values:

  - `visa`
  "Visa credit" card.

  - `master`
  "Master credit" card.

  - `debelo`
  "Elo debit" card.

  - `boleto`
  "Boleto bancário" payment.

  - `pix`
  Payment with "Pix", an instant digital payment method.

  - `transactions.payments[].payment_method.type` (string, optional)
  Type of payment method selected to make the payment.
Possible enum values:

  - `credit_card`
  Credit card.

  - `debit_card`
  Debit card.

  - `ticket`
  Cash payment.

  - `bank_transfer`
  Bank transfer.

  - `transactions.payments[].payment_method.token` (string, optional)
  Token that identifies the card and contains its data securely. Only required for "card" payments. It has a minimum length of 32 characters, and a maximum length of 33. If you don't know how to generate it, go to the "card" payment configuration in the Checkout Transparente documentation.

  - `transactions.payments[].payment_method.installments` (integer, optional)
  Number of installments selected.

  - `transactions.payments[].payment_method.installment_amount` (string, optional)
  Amount per installment. The field can contain two decimal places or none.

  - `transactions.payments[].payment_method.statement_descriptor` (string, optional)
  Description that the payment will appear with in the card statement. Accepts up to 50 characters.

- `description` (string, optional)
  Description of the purchased product or service , the reason for the payment order, or the description of a product in the marketplace.

- `items` (array, optional)
  Information about the list of items to be paid.

  - `items[].title` (string, optional)
  Item name. The character limit is 150.

  - `items[].unit_price` (string, optional)
  Unit price of the purchased item. This field must have a maximum of 18 characters.

  - `items[].quantity` (Integer, optional)
  Purchased items quantity.

  - `items[].description` (string, optional)
  Purchased item description. The character limit is 100.

  - `items[].external_code` (string, optional)
  Item External code.

  - `items[].picture_url` (string, optional)
  Image URL corresponding to the item.

  - `items[].category_id` (string, optional)
  Item category ID.

  - `items[].type` (string, optional)
  Item type.

  - `items[].warranty` (boolean, optional)
  If the item has a warranty.

  - `items[].event_date` (string, optional)
  Event date.

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | invalid_path_param | The "order_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 400 | empty_required_header | The "X-Idempotency-Key" header is required and was not sent. Make the requisition again including it. |
| 400 | invalid_idempotency_key_length | The value sent in the "X-Idempotency-Key" header exceeded the allowed size. The header accepts values between 1 and 128 characters. |
| 401 | 401 | The value sent as Access Token is incorrect. Please check and try again with the correct value. |
| 401 | invalid_credentials | There is no support for test credentials. Use test users with production credentials for the sandbox environment and your production credentials for the production environment. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 409 | cannot_cancel_order | The status of the order does not allow its cancelation. Only orders with "status=action_required" or "status=created" can be canceled. |
| 409 | order_already_canceled | The order has already been canceled. |
| 409 | idempotency_key_already_used | The value sent as the idempotency header ("X-Idempotency-Key") has already been used. Please try the request again sending a new value. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders/{order_id}/cancel' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>'
```

## Response example

```json
{
  "id": "ORD01J49MMW3SSBK5PSV3DFR32959",
  "processing_mode": "automatic",
  "external_reference": "ext_ref_1234",
  "total_amount": "50.00",
  "integration_data": {
  "application_id": "1234",
  "integrator_id": "dev_123",
  "platform_id": "1234567890",
  "sponsor": {
  "id": "<YOUR_SPONSOR_ID>"
  }
  },
  "user_id": "12345",
  "created_date": "2024-08-26T13:06:51.045317772Z",
  "last_updated_date": "2024-08-26T13:06:51.045317772Z",
  "country_code": "BR",
  "type": "online",
  "status": "canceled",
  "status_detail": "canceled_transaction",
  "capture_mode": "automatic",
  "transactions": {
  "payments": [
  {
  "id": "PAY01J67CQQH5904WDBVZEM4JMEP3",
  "amount": "24.50",
  "reference_id": "01JEVQM899NWSQC4FYWWW7KTF9",
  "status": "canceled",
  "status_detail": "canceled_transaction",
  "expiration_time": "P3Y6M4DT12H30M5S",
  "payment_method": {
  "id": null,
  "type": null,
  "token": null,
  "installments": null,
  "installment_amount": null,
  "statement_descriptor": null
  }
  }
  ]
  },
  "description": "Smartphone",
  "items": [
  {
  "title": "Smartphone",
  "unit_price": "24.50",
  "quantity": 1,
  "description": "Smartphone",
  "external_code": "1234",
  "picture_url": "https://http2.mlstatic.com/resources/frontend/statics/growth-sellers-landings/device-mlb-point-i_medium2x.png",
  "category_id": "MLB1055",
  "type": "MLB1055",
  "warranty": "true",
  "event_date": "2014-06-28T16:53:03.176-04:00"
  }
  ]
}
```

# Refund order

This endpoint performs a full or partial refund of the transactions associated with an order. To perform a full refund, you must not send the amount to be refunded in the request body. To perform a partial refund you must indicate the amount to be refunded, along with the transaction ID you wish to return. In case of success, the request will return a response with status 201.

**POST** `/v1/orders/{order_id}/refund`

## Request parameters

### Header

- `X-Idempotency-Key` (string, required)
  This function allows you to repeat requests safely, without the risk of carrying out the same action more than once by mistake. This is useful to avoid mistakes such as creating two identical payments. To ensure that each request is unique, it is important to use a unique value in your request header. We suggest using a V4 UUID or random strings. The header accepts values between 1 and 128 characters.

### Path

- `order_id` (string, required)
  ID of the order that is being refunded. This value is returned in the response to the ([/v1/orders](/developers/en/reference/online-payments/checkout-api/create-order/post)) request.

- `transactions` (array, optional)
  Contains information about the transactions associated with the order. It can contain only one transaction.

  - `transactions[].id` (string, optional)
  Identifier of the payment transaction created in the request, automatically generated by Mercado Pago.

  - `transactions[].amount` (string, optional)
  Transaction amount. If only one payment method is used, it must be equivalent to the amount entered in the `total_amount` field. If two are used, it is the sum between the two `amount` that must be equivalent to the `total_amount` value. The field can contain two decimal places or none.

## Response parameters

- `id` (string, optional)
  Identifier of the order being processed in the request.

- `status` (string, optional)
  Current status of the order.
Possible enum values:

  - `processed`
  All transactions have been succesfully processed.

  - `refunded`
  The order has been refunded.

- `status_detail` (string, optional)
  Details about payment status.
Possible enum values:

  - `refunded`
  The order has been refunded.

  - `partially_refunded`
  The order has been partially refunded.

- `transactions` (object, optional)
  Contains information about the transactions associated with the order.

  - `transactions.refunds` (array, optional)
  Contains information about the refund associated with the order.

  - `transactions.refunds[].id` (string, optional)
  Identifier of the refunded order transaction created in the request, automatically generated by Mercado Pago.

  - `transactions.refunds[].transaction_id` (string, optional)
  Identifier of the transaction associated with the refund.

  - `transactions.refunds[].reference_id` (string, optional)
  Reference ID of the transaction.

  - `transactions.refunds[].amount` (string, optional)
  Transaction amount.

  - `transactions.refunds[].status` (string, optional)
  Payment status.
Possible enum values:

  - `processed`
  The payment has been succesfully processed.

  - `transactions.refunds[].e2e_id` (string, optional)
  Unique and mandatory code generated for each "Pix" transaction, serving as a tracking proof that identifies the operation from start to end (end-to-end).

## Errors

| Status | Error | Description |
| ------- | ------- | ----------- |
| 400 | empty_required_header | The "X-Idempotency-Key" header is required and was not sent. Make the requisition again including it. |
| 400 | invalid_idempotency_key_length | The value sent in the "X-Idempotency-Key" header exceeded the allowed size. The header accepts values between 1 and 128 characters. |
| 400 | invalid_path_param | The "order_id" provided in the request path is not correct. Please confirm it and provide a valid ID to try again. |
| 400 | refund_amount_exceeds | The requested refund amount is greater than the available amount. |
| 403 | forbidden | The application does not have permission to access this resource. Please check that the Access Token used has the necessary permissions and scopes for this operation. |
| 403 | PA_UNAUTHORIZED_RESULT_FROM_POLICIES | The account is blocked and its API keys have been revoked. At least one policy evaluated by the Policy Agent returned an UNAUTHORIZED result. |
| 404 | order_not_found | Order not found. Please check if you provided the correct order ID. |
| 404 | transaction_not_found | Transaction not found. Please check if you provided the correct Transaction ID. |
| 409 | idempotency_key_already_used | The value sent as the idempotency header ("X-Idempotency-Key") has already been used. Please try the request again sending a new value. |
| 409 | order_already_refunded | Order already refunded. |
| 409 | cannot_refund_order | Cannot refund order. Please check if the order is already refunded. |
| 409 | order_refund_already_in_process | There is already a full refund request in process for the order in question. |
| 429 | too_many_requests | "Client ID" blocked by the gateway because the request limit for the ID in question was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 429 | usage_quota_exceeded | Quota enforced by the API backend because the per-client request limit was reached. Read the "Retry-After" header from the response and wait the indicated number of seconds before retrying. For greater resilience, implement exponential backoff with jitter, that is, increase the wait time with each new attempt and add a random variation to avoid simultaneous retransmission of multiple requests. |
| 500 | idempotency_validation_failed | Validation fail. Please try submitting the request again. |
| 500 | internal_error | Generic error. Please try submitting the request again. |

## Request example

### cURL

```bash
curl -X POST \
  'https://api.mercadopago.com/v1/orders/{order_id}/refund' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ACCESS_TOKEN>' \
  -d '{
  "transactions": [
  {
  "id": "PAY01J67CQQH5904WDBVZEM4JMEP3",
  "amount": "24.50"
  }
  ]
  }'
```

## Response example

```json
{
  "id": "ORD01J49MMW3SSBK5PSV3DFR32959",
  "status": "processed",
  "status_detail": "refunded",
  "transactions": {
  "refunds": [
  {
  "id": "REF01J49MMW3SSBK5PSV3DFR32959",
  "transaction_id": "PAY01JEVQM06WDW16MAQ8B5SC0MSC",
  "reference_id": "01JEVQM899NWSQC4FYWWW7KTF9",
  "amount": "24.50",
  "status": "processed",
  "e2e_id": "PIXE18236120202509281610s04cf5a1234"
  }
  ]
  }
}
```