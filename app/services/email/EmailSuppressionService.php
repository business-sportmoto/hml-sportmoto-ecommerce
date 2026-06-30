<?php
/**
 * app/services/email/EmailSuppressionService.php
 */
class EmailSuppressionService
{
    /** @var EmailSuppression */
    private $model;

    public function __construct()
    {
        $this->model = new EmailSuppression();
    }

    public function isSuppressed($email)
    {
        return $this->model->isSuppressed($email);
    }

    public function suprimir($email, $motivo, $origem = 'sistema', $obs = null)
    {
        return $this->model->adicionar($email, $motivo, $origem, $obs);
    }

    public function remover($email)
    {
        return $this->model->remover($email);
    }
}
