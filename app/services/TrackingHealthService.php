<?php
/**
 * app/services/TrackingHealthService.php
 *
 * Resumo de saúde do pipeline de conversões (tracking_events +
 * tracking_dead_letter), para o widget do dashboard admin.
 *
 * SOMENTE LEITURA. Não escreve, não corrige, não reprocessa —
 * existe para tornar visível uma falha que hoje é silenciosa.
 *
 * Mora em app/services/ (e não em app/services/conversion/) porque
 * o autoloader do painel NÃO varre a subpasta conversion/ — os
 * irmãos ConversionService/ConsentService/ClickCaptureService já
 * ficam aqui pelo mesmo motivo.
 *
 * Espelha o contrato de LogService::resumo(): estático, cacheado
 * com TTL curto e à prova de falha (nunca derruba o dashboard).
 */
final class TrackingHealthService
{
    /** TTL do cache, em segundos. O cron roda 1x/min — 60s não atrasa nada. */
    private const TTL = 60;

    /**
     * Minutos a partir dos quais um evento parado vira sintoma.
     * O dispatcher processa um lote em segundos; 15 min cobre uma
     * eventual rodada longa sem gerar alarme falso.
     */
    private const MINUTOS_ATRASO = 15;

    private const CHAVE_CACHE = 'tracking_widget_resumo';

    /**
     * @return array{
     *   ok:bool, enviados_24h:int, pulados_24h:int, falhados:int,
     *   pendentes:int, atrasados:int, presos:int,
     *   dead_abertos:int, dead_24h:int,
     *   ultimo_processado:?string, atraso_min:?int, erro:bool
     * }
     */
    public static function resumo(): array
    {
        if (class_exists('CacheHelper')) {
            $cache = CacheHelper::get(self::CHAVE_CACHE);
            if ($cache !== null) {
                return $cache;
            }
        }

        $dados = self::consultar();

        if (class_exists('CacheHelper')) {
            CacheHelper::set(self::CHAVE_CACHE, $dados, self::TTL);
        }
        return $dados;
    }

    /** Invalida o cache (útil depois de reprocessar dead_letter). */
    public static function limparCache(): void
    {
        if (class_exists('CacheHelper')) {
            CacheHelper::delete(self::CHAVE_CACHE);
        }
    }

    private static function consultar(): array
    {
        $vazio = [
            'ok'                => true,
            'enviados_24h'      => 0,
            'pulados_24h'       => 0,
            'falhados'          => 0,
            'pendentes'         => 0,
            'atrasados'         => 0,
            'presos'            => 0,
            'dead_abertos'      => 0,
            'dead_24h'          => 0,
            'ultimo_processado' => null,
            'atraso_min'        => null,
            'erro'              => false,
        ];

        try {
            $db = Database::getInstance()->getConnection();

            // Intervalos vêm de constantes da própria classe (int), nunca
            // de entrada externa — por isso podem ser interpolados.
            $atraso = (int) self::MINUTOS_ATRASO;

            // Uma passada só sobre a tabela. As condições de retry repetem
            // exatamente o WHERE de ConversionDispatcher::buscarPendentes():
            // um evento cujo backoff ainda não venceu está esperando, não
            // atrasado — contá-lo como atraso geraria alarme falso.
            $ev = $db->query(
                "SELECT
                    COALESCE(SUM(status = 'sent'
                             AND processado_em >= NOW() - INTERVAL 24 HOUR), 0) AS enviados_24h,
                    COALESCE(SUM(status = 'skipped'
                             AND processado_em >= NOW() - INTERVAL 24 HOUR), 0) AS pulados_24h,
                    COALESCE(SUM(status = 'failed'),  0)  AS falhados,
                    COALESCE(SUM(status = 'pending'), 0)  AS pendentes,
                    COALESCE(SUM(status = 'pending'
                             AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                             AND criado_em < NOW() - INTERVAL {$atraso} MINUTE), 0) AS atrasados,
                    COALESCE(SUM(status = 'processing'
                             AND criado_em < NOW() - INTERVAL {$atraso} MINUTE), 0) AS presos,
                    MAX(processado_em) AS ultimo_processado,
                    TIMESTAMPDIFF(
                        MINUTE,
                        MIN(CASE WHEN status = 'pending'
                                  AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                                 THEN criado_em END),
                        NOW()
                    ) AS atraso_min
                 FROM tracking_events"
            )->fetch(PDO::FETCH_ASSOC);

            // dead_letter: 'abertos' = ainda não reprocessado. Enquanto a
            // ferramenta de reprocessar não existe (P3#19 do contexto de
            // tracking), reprocessado_em é sempre NULL e abertos == total.
            $dl = $db->query(
                "SELECT
                    COALESCE(SUM(reprocessado_em IS NULL), 0)                  AS abertos,
                    COALESCE(SUM(criado_em >= NOW() - INTERVAL 24 HOUR), 0)    AS total_24h
                 FROM tracking_dead_letter"
            )->fetch(PDO::FETCH_ASSOC);

            $r = [
                'enviados_24h'      => (int) ($ev['enviados_24h'] ?? 0),
                'pulados_24h'       => (int) ($ev['pulados_24h']  ?? 0),
                'falhados'          => (int) ($ev['falhados']     ?? 0),
                'pendentes'         => (int) ($ev['pendentes']    ?? 0),
                'atrasados'         => (int) ($ev['atrasados']    ?? 0),
                'presos'            => (int) ($ev['presos']       ?? 0),
                'dead_abertos'      => (int) ($dl['abertos']      ?? 0),
                'dead_24h'          => (int) ($dl['total_24h']    ?? 0),
                'ultimo_processado' => $ev['ultimo_processado'] ?? null,
                'atraso_min'        => isset($ev['atraso_min']) && $ev['atraso_min'] !== null
                    ? (int) $ev['atraso_min']
                    : null,
                'erro'              => false,
            ];

            $r['ok'] = self::saudavel($r);

            return $r;

        } catch (\Throwable $e) {
            // O widget não pode derrubar o dashboard. Devolve o shape
            // vazio marcado com erro=true pra view dizer "não consegui
            // medir" em vez de mostrar zeros que parecem saúde.
            LogService::exception($e, 'warning', 'tracking', [
                'origem' => 'TrackingHealthService::consultar',
            ]);
            $vazio['erro'] = true;
            $vazio['ok']   = false;
            return $vazio;
        }
    }

    /**
     * Só conta como problema o que exige ALGUÉM AGIR.
     *
     * Fora da lista de propósito:
     *  - 'pulados' é consentimento negado — conformidade LGPD, não falha;
     *  - 'pendentes' dentro da janela é a fila trabalhando normalmente.
     */
    private static function saudavel(array $r): bool
    {
        return $r['atrasados'] === 0
            && $r['presos'] === 0
            && $r['falhados'] === 0
            && $r['dead_abertos'] === 0;
    }

    // ══════════════════════════════════════════════════
    // RECONCILIAÇÃO DIÁRIA
    // ══════════════════════════════════════════════════

    /**
     * Confere, para um dia, se todo pedido com pagamento aprovado tem
     * o seu Purchase no ledger — e em que estado.
     *
     * É a checagem que os contadores da fila não fazem: a fila só sabe
     * dos eventos que NASCERAM. Se o gatilho não disparou, não há linha
     * nenhuma para ficar pendente, e o card acima mostraria tudo verde
     * enquanto a conversão nunca foi reportada.
     *
     * O casamento é por `tracking_events.event_id = pedidos.codigo`, o
     * mesmo valor que o Pixel usa como eventID.
     *
     * ATENÇÃO a dias antigos: eventos gravados ANTES da correção do
     * event_id (que passou a mandar `codigo` em vez do id numérico)
     * não casam por aqui e aparecem como 'ausentes'. Para monitoramento
     * diário — o uso previsto — isso não afeta nada.
     *
     * @param string|null $dia 'AAAA-MM-DD'; ontem quando omitido.
     * @return array{
     *   dia:string, aprovados:int, enviados:int, pulados:int,
     *   na_fila:int, falhados:int, ausentes:int, divergencia:int,
     *   exemplos:array<int,string>, erro:bool
     * }
     */
    public static function reconciliar(?string $dia = null): array
    {
        $dia = $dia !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) === 1
            ? $dia
            : date('Y-m-d', strtotime('-1 day'));

        $r = [
            'dia' => $dia, 'aprovados' => 0, 'enviados' => 0, 'pulados' => 0,
            'na_fila' => 0, 'falhados' => 0, 'ausentes' => 0,
            'divergencia' => 0, 'exemplos' => [], 'erro' => false,
        ];

        try {
            $db = Database::getInstance()->getConnection();

            // Intervalo semiaberto sobre pago_em (indexado) em vez de
            // DATE(pago_em) = ?, que anularia o índice.
            $ini = $dia . ' 00:00:00';
            $fim = date('Y-m-d', strtotime($dia . ' +1 day')) . ' 00:00:00';

            $sql =
                "SELECT
                    COUNT(*)                                              AS aprovados,
                    COALESCE(SUM(te.id IS NULL), 0)                       AS ausentes,
                    COALESCE(SUM(te.status = 'sent'), 0)                  AS enviados,
                    COALESCE(SUM(te.status = 'skipped'), 0)               AS pulados,
                    COALESCE(SUM(te.status IN ('pending','processing')),0) AS na_fila,
                    COALESCE(SUM(te.status IN ('failed','partial')), 0)    AS falhados
                   FROM pedidos p
                   LEFT JOIN tracking_events te
                          ON te.event_id   = p.codigo
                         AND te.event_name = 'Purchase'
                  WHERE p.status_pagamento = 'aprovado'
                    AND p.pago_em >= ? AND p.pago_em < ?";

            $st = $db->prepare($sql);
            $st->execute([$ini, $fim]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            foreach (['aprovados','ausentes','enviados','pulados','na_fila','falhados'] as $k) {
                $r[$k] = (int) ($row[$k] ?? 0);
            }

            // Divergência = o que deveria ter sido reportado e não foi.
            // 'pulados' fica DE FORA: consentimento de marketing negado
            // é conformidade LGPD, não evento perdido.
            $r['divergencia'] = $r['ausentes'] + $r['falhados'];

            if ($r['divergencia'] > 0) {
                $ex = $db->prepare(
                    "SELECT p.codigo
                       FROM pedidos p
                       LEFT JOIN tracking_events te
                              ON te.event_id   = p.codigo
                             AND te.event_name = 'Purchase'
                      WHERE p.status_pagamento = 'aprovado'
                        AND p.pago_em >= ? AND p.pago_em < ?
                        AND (te.id IS NULL OR te.status IN ('failed','partial'))
                      ORDER BY p.pago_em ASC
                      LIMIT 10"
                );
                $ex->execute([$ini, $fim]);
                $r['exemplos'] = $ex->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }

            return $r;

        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'tracking', [
                'origem' => 'TrackingHealthService::reconciliar',
                'dia'    => $dia,
            ]);
            $r['erro'] = true;
            return $r;
        }
    }
}
