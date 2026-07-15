<?php
// admin/index.php — roteador isolado do painel administrativo

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict'); // mais restrito no admin
ini_set('session.use_strict_mode', 1);


require_once dirname(__DIR__) . '/config/defines.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT_PATH . '/core/',
        ROOT_PATH . '/app/models/',
        ROOT_PATH . '/app/helpers/',
        ROOT_PATH . '/app/services/',
        ROOT_PATH . '/app/services/email/',        
        ROOT_PATH . '/app/services/email/providers/',
        ROOT_PATH . '/app/services/ia/',
        ROOT_PATH . '/app/services/ia/providers/',
        ROOT_PATH . '/app/services/payment/',
        ROOT_PATH . '/admin/controllers/',
    ];
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});
// var_dump(ROOT_PATH);
$svc = new EmailMarketingService();

Session::start();

View::setBasePath(ROOT_PATH . '/admin/views');
View::setAssetPath(BASE_URL . '/admin/assets');

// Dados globais compartilhados em todas as views do admin
if (Session::isAdminLogado()) {
    View::share('admin_nome',  Session::get('admin_nome'));
    View::share('admin_nivel', Session::get('admin_nivel'));
}
View::share('csrf_token', SecurityHelper::generateCsrf());

require_once ROOT_PATH . '/admin/config/routes.php';
AdminRouter::dispatch();




/*
./admin/config/routes.email-marketing.php
./admin/controllers/EmailCampaignAdminController.php
./admin/controllers/EmailContactAdminController.php
./admin/controllers/EmailListAdminController.php
./admin/controllers/EmailMarketingController.php
./admin/controllers/EmailProviderAdminController.php
./admin/controllers/EmailSegmentAdminController.php
./admin/controllers/EmailSuppressionAdminController.php
./admin/controllers/EmailTemplateAdminController.php
./admin/views/email-marketing/campanhas/form.php
./admin/views/email-marketing/campanhas/index.php
./admin/views/email-marketing/campanhas/relatorio.php
./admin/views/email-marketing/contatos/index.php
./admin/views/email-marketing/index.php
./admin/views/email-marketing/listas/index.php
./admin/views/email-marketing/provedores/index.php
./admin/views/email-marketing/segmentos/index.php
./admin/views/email-marketing/supressoes/index.php
./admin/views/email-marketing/templates/form.php
./admin/views/email-marketing/templates/index.php
./app/controllers/EmailMarketingController.php
./app/controllers/EmailTrackingController.php
./app/controllers/EmailWebhookController.php
./app/models/EmailCampaign.php
./app/models/EmailCampaignRecipient.php
./app/models/EmailConsent.php
./app/models/EmailContact.php
./app/models/EmailEvent.php
./app/models/EmailImport.php
./app/models/EmailLink.php
./app/models/EmailList.php
./app/models/EmailProvider.php
./app/models/EmailSegment.php
./app/models/EmailSuppression.php
./app/models/EmailTemplate.php
./app/services/email/EmailCampaignService.php
./app/services/email/EmailConsentService.php
./app/services/email/EmailMarketingService.php
./app/services/email/EmailProviderService.php
./app/services/email/EmailQueueService.php
./app/services/email/EmailSegmentService.php
./app/services/email/EmailSuppressionService.php
./app/services/email/EmailTemplateService.php
./app/services/email/EmailTrackingService.php
./app/services/email/EmailWebhookService.php
./app/services/email/providers/AwsSesEmailProvider.php
./app/services/email/providers/BrevoEmailProvider.php
./app/services/email/providers/EmailProviderInterface.php
./app/services/email/providers/EmailSendResult.php
./app/services/email/providers/MailgunEmailProvider.php
./app/services/email/providers/SendGridEmailProvider.php
./app/services/email/providers/SmtpEmailProvider.php
./app/views/email-marketing/unsubscribe.php
./assets/css/email-marketing.css
./assets/js/email-marketing.js
./cli/email-worker.php
./config/email-marketing.php
./config/routes.email-marketing.php
./sql/email_marketing.sql
*/ 