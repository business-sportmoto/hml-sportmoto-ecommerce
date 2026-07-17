<?php
/**
 * app/services/FluxoGuard.php
 *
 * Frequency capping global: teto de mensagens por cliente numa janela
 * deslizante de 7 dias, somando TODOS os canais e TODOS os fluxos.
 *
 * Aplicado no motor (FluxoMotor::processarUma) ANTES de executar um nó de
 * envio: se o cliente estourou o teto, o envio é pulado e a jornada segue
 * pela porta 'saida' (não trava o fluxo, só protege o cliente do excesso).
 *
 * Mensagens transacionais (disparadas fora dos fluxos) NÃO contam — só
 * envios feitos por nós de automação passam por aqui.
 *
 * Config em fluxo_motor_config: cap_max_semana (0 = desligado).
 */
class FluxoGuard
{
    /** Nós de envio → canal. Nós fora deste mapa não sofrem cap. */
    private const CANAL_POR_NO = [
        'acao_email'       => 'email',
        'acao_whatsapp'    => 'whatsapp',
        'acao_notificacao' => 'notificacao',
    ];

    /** Canal do nó, ou null se o nó não é de envio. */
    public static function canalDoNo(string $tipoNo): ?string
    {
        return self::CANAL_POR_NO[$tipoNo] ?? null;
    }

    /** O cliente já atingiu o teto na janela de 7 dias? */
    public static function capAtingido(int $clienteId, string $canal, PDO $db): bool
    {
        if ($clienteId <= 0) return false;
        try {
            $cap = (int)self::getCfg($db, 'cap_max_semana', '0');
            if ($cap <= 0) return false; // desligado

            $st = $db->prepare(
                "SELECT COUNT(*) FROM fluxo_envios
                 WHERE cliente_id = :c AND enviado_em > DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $st->execute([':c' => $clienteId]);
            return (int)$st->fetchColumn() >= $cap;
        } catch (Throwable $e) {
            return false; // na dúvida, não bloqueia envio
        }
    }

    /** Registra um envio efetivado (para a contagem do cap). */
    public static function registrarEnvio(int $clienteId, string $canal, int $fluxoId, PDO $db): void
    {
        if ($clienteId <= 0) return;
        try {
            $db->prepare(
                "INSERT INTO fluxo_envios (cliente_id, canal, fluxo_id) VALUES (:c, :ca, :f)"
            )->execute([':c' => $clienteId, ':ca' => $canal, ':f' => $fluxoId]);
        } catch (Throwable $e) {
            // registro de cap não é crítico
        }
    }

    private static function getCfg(PDO $db, string $chave, string $default): string
    {
        try {
            $st = $db->prepare("SELECT valor FROM fluxo_motor_config WHERE chave=:k");
            $st->execute([':k' => $chave]);
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null) ? (string)$v : $default;
        } catch (Throwable $e) { return $default; }
    }
}
