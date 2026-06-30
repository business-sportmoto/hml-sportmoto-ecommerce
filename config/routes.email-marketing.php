<?php
/**
 * PATCH — config/routes.php
 *
 * Adicionar este bloco ANTES da rota curinga final '/{slug}'.
 * Se sua rota curinga estiver no final do arquivo, basta colar este bloco
 * imediatamente antes dela.
 */

// ------- Descadastro (público, sem login, via token seguro) -------
Router::get( '/email/descadastrar/{token}',                  'EmailMarketingController@unsubscribe');
Router::post('/email/descadastrar/{token}',                  'EmailMarketingController@unsubscribeConfirm');

// ------- Tracking pixel e cliques -------
Router::get( '/email/open/{token}.png',                      'EmailTrackingController@open');
Router::get( '/email/click/{destinatario}/{link}/{token}',   'EmailTrackingController@click');

// ------- Webhooks dos provedores -------
Router::post('/webhook/email/aws-ses',                       'EmailWebhookController@awsSes');
Router::post('/webhook/email/mailgun',                       'EmailWebhookController@mailgun');
Router::post('/webhook/email/sendgrid',                      'EmailWebhookController@sendgrid');
Router::post('/webhook/email/brevo',                         'EmailWebhookController@brevo');
