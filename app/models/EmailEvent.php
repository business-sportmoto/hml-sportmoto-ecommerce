<?php
/**
 * app/models/EmailEvent.php
 */
class EmailEvent
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Registra evento com dedupe_key para evitar duplicidade vinda de webhooks.
     * Retorna o ID inserido, ou null se duplicado.
     */
    public function registrar(array $d)
    {
        $key = $d['dedupe_key'] ?? hash('sha256', json_encode([
            $d['provider_message_id'] ?? '',
            $d['tipo'] ?? '',
            $d['subtipo'] ?? '',
            $d['destinatario_id'] ?? '',
            microtime(true),
        ]));

        $payload = $d['payload_json'] ?? null;
        if (is_array($payload)) $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $sql = "INSERT IGNORE INTO email_eventos
            (campanha_id, destinatario_id, contato_id, provider_message_id,
             tipo, subtipo, ip, user_agent, link_id, dedupe_key, payload_json)
            VALUES
            (:camp, :dest, :ct, :pm, :tipo, :sub, :ip, :ua, :link, :dk, :pj)";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':camp' => isset($d['campanha_id'])     ? (int)$d['campanha_id']     : null,
            ':dest' => isset($d['destinatario_id']) ? (int)$d['destinatario_id'] : null,
            ':ct'   => isset($d['contato_id'])      ? (int)$d['contato_id']      : null,
            ':pm'   => $d['provider_message_id'] ?? null,
            ':tipo' => $d['tipo'],
            ':sub'  => $d['subtipo'] ?? null,
            ':ip'   => $d['ip'] ?? null,
            ':ua'   => isset($d['user_agent']) ? substr($d['user_agent'], 0, 250) : null,
            ':link' => isset($d['link_id']) ? (int)$d['link_id'] : null,
            ':dk'   => $key,
            ':pj'   => $payload,
        ]);
        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }
}
