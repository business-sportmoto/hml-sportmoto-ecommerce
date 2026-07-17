<?php
/**
 * app/services/FluxoSunsetService.php
 *
 * Sunset policy: protege a reputação de envio (Mailgun) suprimindo do
 * marketing os contatos que RECEBEM e NUNCA ABREM.
 *
 * Regra: quem recebeu ao menos N emails na janela (padrão 90 dias) e abriu
 * ZERO nesse período é suprimido do marketing (via NotifPrefsService), e
 * marcado com a tag 'sunset' para não ser reavaliado toda semana.
 *
 * Transacionais continuam normalmente — sunset só desliga MARKETING.
 * Rodado por cron semanal (cli/fluxo-sunset.php).
 *
 * Mapeamento contato → cliente: mesmo ponto de ajuste da EmailEngajamentoBridge.
 */
class FluxoSunsetService
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * @return array{avaliados:int, suprimidos:int, ja_marcados:int, sem_cliente:int}
     */
    public function processar(int $lote = 1000): array
    {
        $stats = ['avaliados' => 0, 'suprimidos' => 0, 'ja_marcados' => 0, 'sem_cliente' => 0];

        try {
            $janela = max(1, (int)$this->getCfg('sunset_janela_dias', '90'));
            $minEnv = max(1, (int)$this->getCfg('sunset_min_enviados', '3'));

            // Contatos que receberam >= N e-mails na janela e abriram ZERO
            $st = $this->db->prepare(
                "SELECT contato_id,
                        SUM(CASE WHEN tipo IN ('enviado','entregue') THEN 1 ELSE 0 END) AS enviados,
                        SUM(CASE WHEN tipo = 'aberto' THEN 1 ELSE 0 END)                AS aberturas
                 FROM email_eventos
                 WHERE contato_id IS NOT NULL
                   AND criado_em > DATE_SUB(NOW(), INTERVAL {$janela} DAY)
                 GROUP BY contato_id
                 HAVING enviados >= :min AND aberturas = 0
                 LIMIT " . max(100, min(10000, $lote))
            );
            $st->bindValue(':min', $minEnv, PDO::PARAM_INT);
            $st->execute();
            $alvos = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($alvos as $a) {
                $stats['avaliados']++;

                $clienteId = $this->resolverCliente((int)$a['contato_id']);
                if (!$clienteId) { $stats['sem_cliente']++; continue; }

                // Já marcado como sunset? não reprocessa
                if ($this->temTag($clienteId, 'sunset')) { $stats['ja_marcados']++; continue; }

                // Suprime marketing (transacionais seguem)
                if (class_exists('NotifPrefsService')) {
                    try { NotifPrefsService::descadastrarMarketing($clienteId); } catch (Throwable $e) {}
                }
                $this->addTag($clienteId, 'sunset');
                $stats['suprimidos']++;

                if (class_exists('LogService')) {
                    try { LogService::audit('sunset_suprimido', ['cliente_id' => $clienteId, 'enviados' => (int)$a['enviados']]); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error('FluxoSunsetService: ' . $e->getMessage()); } catch (Throwable $x) {}
            }
        }

        return $stats;
    }

    /** ═══ PONTO DE AJUSTE (igual ao da EmailEngajamentoBridge) ═══ */
    private function resolverCliente(int $contatoId): ?int
    {
        try {
            $st = $this->db->prepare(
                "SELECT c.id
                 FROM email_contatos ec
                 JOIN usuarios u ON u.email = ec.email AND u.deleted_at IS NULL
                 JOIN clientes c ON c.usuario_id = u.id
                 WHERE ec.id = :id LIMIT 1"
            );
            $st->execute([':id' => $contatoId]);
            $cid = $st->fetchColumn();
            return $cid ? (int)$cid : null;
        } catch (Throwable $e) { return null; }
    }

    private function temTag(int $clienteId, string $tag): bool
    {
        try {
            $st = $this->db->prepare("SELECT 1 FROM cliente_tags WHERE cliente_id=:c AND tag=:t LIMIT 1");
            $st->execute([':c' => $clienteId, ':t' => $tag]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    }

    private function addTag(int $clienteId, string $tag): void
    {
        try {
            $this->db->prepare("INSERT IGNORE INTO cliente_tags (cliente_id, tag) VALUES (:c,:t)")
                     ->execute([':c' => $clienteId, ':t' => $tag]);
        } catch (Throwable $e) {}
    }

    private function getCfg(string $chave, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT valor FROM fluxo_motor_config WHERE chave=:k");
            $st->execute([':k' => $chave]);
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null) ? (string)$v : $default;
        } catch (Throwable $e) { return $default; }
    }
}




