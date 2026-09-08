<?php
/**
 * app/services/TrackingAlertaService.php
 *
 * Decide QUANDO um problema de tracking merece acordar alguém, e avisa.
 *
 * Separado do TrackingHealthService de propósito: aquele mede e não
 * escreve nada; este escreve (log + notificação). Misturar os dois
 * tornaria impossível consultar a saúde sem risco de efeito colateral —
 * o widget do dashboard chama o medidor a cada carregamento.
 *
 * Canais: LogService (canal 'tracking') + notificação in-app broadcast
 * para admins. Não inventa infraestrutura: usa o NotificacaoService que
 * já existe, materializado pelo cli/notificacao-worker.php.
 */
final class TrackingAlertaService
{
    /**
     * Horas de silêncio por tipo de alerta depois de disparado.
     *
     * Sem isto, um vigia rodando de 5 em 5 minutos com a fila parada
     * geraria ~288 notificações por dia e o time aprenderia a ignorar
     * o sino — que é a única forma de um alerta falhar completamente.
     */
    private const THROTTLE_HORAS = 6;

    /** Prefixo do `tipo` na tabela notificacoes; é a chave do throttle. */
    private const TIPO_PREFIXO = 'tracking_alerta_';

    /**
     * A partir de quantos eventos NOVOS em dead letter (24h) vale avisar.
     * Mede a janela de 24h, e não o acumulado: enquanto não existir a
     * ferramenta de reprocessar, o acumulado nunca desce e o alerta
     * ficaria permanentemente aceso.
     */
    private const DEAD_24H_MIN = 5;

    /** Idem para eventos que esgotaram as tentativas. */
    private const FALHADOS_MIN = 5;

    /**
     * Avalia a saúde da fila e dispara o que for acionável.
     *
     * @return array{disparados:array<int,string>, silenciados:array<int,string>}
     */
    public static function verificarFila(): array
    {
        $r = TrackingHealthService::resumo();
        $out = ['disparados' => [], 'silenciados' => []];

        if ($r['erro']) {
            self::disparar(
                'medicao',
                'Tracking: não foi possível medir a fila',
                'A consulta de saúde do pipeline falhou. Ver logs do canal tracking.',
                'critical',
                $r,
                $out
            );
            return $out;
        }

        // Presos: o dispatcher marcou 'processing' e não voltou. Nada
        // devolve essas linhas à fila, então 1 já é perda definitiva.
        if ($r['presos'] > 0) {
            self::disparar(
                'presos',
                "Tracking: {$r['presos']} evento(s) travado(s)",
                'Eventos presos em processing não voltam para a fila sozinhos — '
                . 'a conversão não será reportada.',
                'critical',
                $r,
                $out
            );
        }

        // Atrasados já significa "vencido há mais de 15 min" (regra do
        // TrackingHealthService), logo indica cron parado ou travado.
        if ($r['atrasados'] > 0) {
            $idade = $r['atraso_min'] !== null ? " há ~{$r['atraso_min']} min" : '';
            self::disparar(
                'fila_parada',
                "Tracking: fila parada{$idade}",
                "{$r['atrasados']} evento(s) aguardando envio. "
                . 'Verificar o cron cli/conversion-dispatch.php.',
                'critical',
                $r,
                $out
            );
        }

        if ($r['dead_24h'] >= self::DEAD_24H_MIN) {
            self::disparar(
                'dead_letter',
                "Tracking: {$r['dead_24h']} evento(s) em dead letter (24h)",
                'Falhas permanentes de envio. Ver o erro e o http_status em '
                . 'tracking_dead_letter.',
                'error',
                $r,
                $out
            );
        }

        if ($r['falhados'] >= self::FALHADOS_MIN) {
            self::disparar(
                'falhados',
                "Tracking: {$r['falhados']} evento(s) esgotaram as tentativas",
                'Eventos que não serão mais reenviados automaticamente.',
                'error',
                $r,
                $out
            );
        }

        return $out;
    }

    /**
     * Avalia o resultado de uma reconciliação diária e avisa se houver
     * pedido aprovado sem Purchase reportado.
     *
     * @param array $rec retorno de TrackingHealthService::reconciliar()
     * @return array{disparados:array<int,string>, silenciados:array<int,string>}
     */
    public static function verificarReconciliacao(array $rec): array
    {
        $out = ['disparados' => [], 'silenciados' => []];

        if (!empty($rec['erro']) || (int) $rec['divergencia'] <= 0) {
            return $out;
        }

        $ex = !empty($rec['exemplos'])
            ? ' Ex.: ' . implode(', ', array_slice($rec['exemplos'], 0, 5)) . '.'
            : '';

        self::disparar(
            'reconciliacao_' . $rec['dia'],
            "Tracking: {$rec['divergencia']} venda(s) sem Purchase em {$rec['dia']}",
            "De {$rec['aprovados']} pedido(s) aprovado(s), {$rec['ausentes']} não geraram "
            . "evento e {$rec['falhados']} falharam no envio.{$ex}",
            'critical',
            $rec,
            $out
        );

        return $out;
    }

    /**
     * Grava o log e cria a notificação, respeitando o throttle.
     * Nunca lança: alerta que derruba o processo é pior que o problema.
     */
    private static function disparar(
        string $chave,
        string $titulo,
        string $mensagem,
        string $nivel,
        array $contexto,
        array &$out
    ): void {
        $tipo = self::TIPO_PREFIXO . $chave;

        try {
            // O log SEMPRE sai — é o registro técnico, e o LogService já
            // deduplica por fingerprint. O throttle vale só para a
            // notificação, que é o que consome atenção humana.
            $ctx = array_merge([
                'origem' => 'TrackingAlertaService',
                'alerta' => $chave,
            ], self::contextoEnxuto($contexto));

            // Despacho explícito em vez de LogService::$nivel(): a forma
            // variável é válida, mas vira fatal se algum dia chegar aqui
            // um nível que não existe como método.
            $texto = $titulo . ' — ' . $mensagem;
            if ($nivel === 'critical') {
                LogService::critical($texto, $ctx, 'tracking');
            } else {
                LogService::error($texto, $ctx, 'tracking');
            }

            if (self::silenciado($tipo)) {
                $out['silenciados'][] = $chave;
                return;
            }

            NotificacaoService::criarBroadcast([
                'categoria' => 'sistema',
                'tipo'      => $tipo,
                'titulo'    => $titulo,
                'mensagem'  => $mensagem,
                'url'       => '/admin/logs?canal=tracking&status=abertos&periodo=24h',
            ], 'todos_admins');

            $out['disparados'][] = $chave;

        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'tracking', [
                'origem' => 'TrackingAlertaService::disparar',
                'alerta' => $chave,
            ]);
        }
    }

    /** Já houve notificação deste tipo dentro da janela de silêncio? */
    private static function silenciado(string $tipo): bool
    {
        try {
            $st = Database::getInstance()->getConnection()->prepare(
                "SELECT 1 FROM notificacoes
                  WHERE tipo = ?
                    AND criado_em >= NOW() - INTERVAL " . (int) self::THROTTLE_HORAS . " HOUR
                  LIMIT 1"
            );
            $st->execute([$tipo]);
            return (bool) $st->fetchColumn();

        } catch (\Throwable $e) {
            // Sem conseguir consultar o histórico, prefere-se notificar:
            // repetir um aviso é recuperável, engolir um não é.
            LogService::exception($e, 'warning', 'tracking', [
                'origem' => 'TrackingAlertaService::silenciado',
            ]);
            return false;
        }
    }

    /** Só os números — o contexto vai pro log, não precisa carregar tudo. */
    private static function contextoEnxuto(array $c): array
    {
        $chaves = ['presos','atrasados','falhados','dead_abertos','dead_24h',
                   'pendentes','enviados_24h','atraso_min',
                   'dia','aprovados','ausentes','divergencia'];
        return array_intersect_key($c, array_flip($chaves));
    }
}
