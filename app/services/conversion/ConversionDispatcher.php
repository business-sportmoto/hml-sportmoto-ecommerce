<?php
declare(strict_types=1);

/**
 * app/services/conversion/ConversionDispatcher.php
 *
 * Orquestrador do envio. Roda no cron: lê eventos 'pending' do
 * ledger, checa consentimento, envia via cada adaptador, e trata
 * o resultado (sucesso / retry com backoff / dead_letter).
 *
 * DESACOPLADO: não conhece Meta nem Google — só a interface
 * ConversionAdapter. Adicionar destino = registrar mais um
 * adaptador no construtor.
 *
 * GARANTIAS:
 *  - Consentimento respeitado (evento sem marketing não vai pra
 *    destino que requerMarketing) → status 'skipped'
 *  - Retry com backoff exponencial (falha temporária)
 *  - Dead_letter em falha permanente (não perde o evento)
 *  - 'processing' evita dois crons pegarem o mesmo evento
 */
final class ConversionDispatcher
{
    private const LOTE          = 50;   // eventos por rodada
    private const MAX_TENTATIVAS = 5;   // além disso → dead_letter

    private PDO $db;
    /** @var ConversionAdapter[] */
    private array $adapters;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();

        // ── Registro dos destinos (só Meta por ora) ──
        // Adicionar Google/TikTok = mais uma linha aqui.
        $this->adapters = array_filter([
            new MetaCapiAdapter(),
        ], fn(ConversionAdapter $a) => $a->estaConfigurado());
    }

    /**
     * Processa um lote de eventos pendentes. Retorna um resumo
     * (pra log do cron).
     */
    public function processarLote(): array
    {
        // Sem nenhum adaptador configurado → não faz nada
        if (empty($this->adapters)) {
            return ['ok' => 0, 'skip' => 0, 'retry' => 0, 'dead' => 0,
                    'msg' => 'nenhum adaptador configurado'];
        }

        $eventos = $this->buscarPendentes();
        $r = ['ok' => 0, 'skip' => 0, 'retry' => 0, 'dead' => 0];

        foreach ($eventos as $ev) {
            // Marca 'processing' (evita outro cron pegar o mesmo)
            if (!$this->marcarProcessing((int)$ev['id'])) {
                continue; // outro processo já pegou
            }

            $ev['payload'] = json_decode($ev['payload'] ?? '[]', true) ?: [];

            $consentMarketing = (int)($ev['consent_marketing'] ?? 0) === 1;
            $consentAnalytics = (int)($ev['consent_analytics'] ?? 0) === 1;

            $algumEnviou   = false;
            $algumFalhou   = false;
            $algumPulou    = false;
            $ultimoErro    = null;

            foreach ($this->adapters as $adapter) {
                // ── GATE DE CONSENTIMENTO ──
                // Destino que exige marketing só recebe se houve
                // consentimento de marketing. É o elo com a Fase 0.
                if ($adapter->requerMarketing() && !$consentMarketing) {
                    $algumPulou = true;
                    continue;
                }

                $res = $adapter->enviar($ev);

                if ($res->sucesso) {
                    $algumEnviou = true;
                } elseif ($res->reenviar) {
                    $algumFalhou = true;
                    $ultimoErro  = $res->erro;
                } else {
                    // Falha permanente → dead_letter pra este destino
                    $this->paraDeadLetter($ev, $adapter->nome(), $res);
                    $algumFalhou = true;
                    $ultimoErro  = $res->erro;
                }
            }

            // ── Decide o status final do evento ──
            if ($algumEnviou && !$algumFalhou) {
                $this->marcarStatus((int)$ev['id'], 'sent');
                $r['ok']++;
            } elseif (!$algumEnviou && $algumPulou && !$algumFalhou) {
                // Todos os destinos foram pulados por consentimento
                $this->marcarStatus((int)$ev['id'], 'skipped');
                $r['skip']++;
            } elseif ($algumFalhou) {
                // Teve falha temporária → agenda retry OU dead_letter
                $tentativas = (int)$ev['tentativas'] + 1;
                if ($tentativas >= self::MAX_TENTATIVAS) {
                    $this->marcarStatus((int)$ev['id'], 'failed', $ultimoErro);
                    $r['dead']++;
                } else {
                    $this->agendarRetry((int)$ev['id'], $tentativas, $ultimoErro);
                    $r['retry']++;
                }
            } else {
                // Caso raro: nada enviou, nada pulou, nada falhou
                $this->marcarStatus((int)$ev['id'], 'sent'); // nada a fazer
                $r['ok']++;
            }
        }

        return $r;
    }

    // ══════════════════════════════════════════════════
    // ACESSO AO LEDGER
    // ══════════════════════════════════════════════════

    /** Eventos pending cuja hora de retry chegou (ou nunca tentados). */
    private function buscarPendentes(): array
    {
        $st = $this->db->prepare(
            "SELECT * FROM tracking_events
             WHERE status IN ('pending')
               AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
             ORDER BY criado_em ASC
             LIMIT " . self::LOTE
        );
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Marca 'processing' com guarda de corrida: só muda se ainda
     * está 'pending'. Se outro cron já mudou, o UPDATE afeta 0
     * linhas e retornamos false (não processa duplicado).
     */
    private function marcarProcessing(int $id): bool
    {
        $st = $this->db->prepare(
            "UPDATE tracking_events SET status = 'processing'
             WHERE id = ? AND status = 'pending'"
        );
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    private function marcarStatus(int $id, string $status, ?string $erro = null): void
    {
        $this->db->prepare(
            "UPDATE tracking_events
             SET status = ?, processado_em = NOW(), ultimo_erro = ?
             WHERE id = ?"
        )->execute([$status, $erro ? mb_substr($erro, 0, 500) : null, $id]);
    }

    /** Backoff exponencial: 2^tentativas minutos (2,4,8,16...). */
    private function agendarRetry(int $id, int $tentativas, ?string $erro): void
    {
        $minutos = (int)pow(2, $tentativas); // 2,4,8,16,32
        $this->db->prepare(
            "UPDATE tracking_events
             SET status = 'pending',
                 tentativas = ?,
                 proxima_tentativa = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                 ultimo_erro = ?
             WHERE id = ?"
        )->execute([$tentativas, $minutos, $erro ? mb_substr($erro, 0, 500) : null, $id]);
    }

    private function paraDeadLetter(array $ev, string $destino, ConversionResult $res): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO tracking_dead_letter
                 (event_id, event_name, destino, payload, erro, tentativas, http_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $ev['event_id'],
                $ev['event_name'],
                $destino,
                json_encode($ev['payload'] ?? [], JSON_UNESCAPED_UNICODE),
                $res->erro ? mb_substr($res->erro, 0, 1000) : null,
                (int)$ev['tentativas'] + 1,
                $res->httpStatus,
            ]);
        } catch (\Throwable $e) {
            error_log('[Dispatcher] dead_letter falhou: ' . $e->getMessage());
        }
    }
}