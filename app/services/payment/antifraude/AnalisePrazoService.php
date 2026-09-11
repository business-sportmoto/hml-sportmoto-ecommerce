<?php
declare(strict_types=1);

/**
 * app/services/payment/antifraude/AnalisePrazoService.php
 *
 * O prazo da análise de risco: quanto tempo um pedido está retido e o alerta
 * de quando ele passa do que foi prometido ao cliente.
 *
 * ── DE ONDE VEM O PRAZO ──────────────────────────────────────────────────
 *
 * A tela de pedido realizado diz ao cliente que a análise leva "até 48
 * horas" (decisão de 11/09/2026). Até então nada no sistema cobrava esse
 * prazo: o pedido podia ficar na fila uma semana e ninguém era avisado.
 * PRAZO_HORAS é esse número — se a tela mudar, muda aqui junto.
 *
 * ── DESDE QUANDO ─────────────────────────────────────────────────────────
 *
 * Retido desde a ÚLTIMA entrada em `em_analise` no histórico, não desde a
 * criação do pedido: um pedido liberado e devolvido à análise começa um
 * prazo novo. Sem histórico (pedido antigo), vale a criação.
 *
 * ── O ALERTA ─────────────────────────────────────────────────────────────
 *
 * Um aviso VIVO por pedido no sino do admin, chaveado em
 * `analise-prazo:{id}`. Nasce ao passar de 48 h e volta ao topo a cada 24 h
 * a mais sem decisão (72 h, 96 h…) — o suficiente para não ser esquecido,
 * pouco para não virar ruído. Quando o pedido sai da análise, o
 * AdminPedidoService::mudarStatus() chama encerrarAlerta() e o texto passa a
 * dizer que está resolvido, sem voltar ao topo.
 *
 * Quem recebe: super e gerente — os cargos que decidem a fila
 * (AdminAnaliseController).
 */
final class AnalisePrazoService
{
    /** O prazo prometido ao cliente na tela de pedido realizado. */
    public const PRAZO_HORAS = 48;

    /** A partir daqui a fila fica âmbar: ainda dá tempo, mas por pouco. */
    public const AVISO_HORAS = 36;

    private const STATUS_FILA = 'em_analise';

    /** Níveis que decidem a fila (AdminAnaliseController). */
    private const QUEM_DECIDE = ['super', 'gerente'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    public static function chave(int $pedidoId): string
    {
        return 'analise-prazo:' . $pedidoId;
    }

    /**
     * Desde quando cada pedido está retido.
     *
     * @param  int[] $pedidoIds
     * @return array<int, string>  id => 'Y-m-d H:i:s'
     */
    public function retidosDesde(array $pedidoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pedidoIds))));
        if (!$ids) return [];

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->db->prepare(
            "SELECT p.id, COALESCE(MAX(h.criado_em), p.criado_em) AS retido_em
               FROM pedidos p
          LEFT JOIN pedido_historico h
                 ON h.pedido_id = p.id AND h.status_novo = ?
              WHERE p.id IN ({$marcas})
           GROUP BY p.id, p.criado_em"
        );
        $st->execute(array_merge([self::STATUS_FILA], $ids));

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = (string) $r['retido_em'];
        }
        return $out;
    }

    /**
     * Pedidos em análise há PRAZO_HORAS ou mais, do mais antigo ao mais novo.
     * As horas saem do banco (NOW() do MySQL), para a conta não depender do
     * fuso do PHP bater com o do banco.
     *
     * @return array<int, array{id:int, codigo:string, total:float, retido_em:string, horas:int}>
     */
    public function atrasados(): array
    {
        $st = $this->db->prepare(
            "SELECT p.id, p.codigo, p.total,
                    COALESCE(MAX(h.criado_em), p.criado_em) AS retido_em,
                    TIMESTAMPDIFF(HOUR, COALESCE(MAX(h.criado_em), p.criado_em), NOW()) AS horas
               FROM pedidos p
          LEFT JOIN pedido_historico h
                 ON h.pedido_id = p.id AND h.status_novo = :st
              WHERE p.status_pedido = :st2
           GROUP BY p.id, p.codigo, p.total, p.criado_em
             HAVING horas >= :prazo
           ORDER BY retido_em ASC"
        );
        $st->bindValue(':st', self::STATUS_FILA);
        $st->bindValue(':st2', self::STATUS_FILA);
        $st->bindValue(':prazo', self::PRAZO_HORAS, PDO::PARAM_INT);
        $st->execute();

        return array_map(static fn(array $r) => [
            'id'        => (int) $r['id'],
            'codigo'    => (string) $r['codigo'],
            'total'     => (float) $r['total'],
            'retido_em' => (string) $r['retido_em'],
            'horas'     => (int) $r['horas'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Como a fila mostra a idade de um pedido retido.
     *
     * @return array{tom:string, rotulo:string}  tom: ok | perto | estourado
     */
    public static function situacao(int $horas): array
    {
        $horas = max(0, $horas);
        $idade = $horas < 24 ? $horas . 'h' : intdiv($horas, 24) . 'd ' . ($horas % 24) . 'h';

        if ($horas >= self::PRAZO_HORAS) {
            return ['tom' => 'estourado', 'rotulo' => $idade . ' · atrasado'];
        }
        if ($horas >= self::AVISO_HORAS) {
            return ['tom' => 'perto', 'rotulo' => $idade . ' · vence em ' . (self::PRAZO_HORAS - $horas) . 'h'];
        }
        return ['tom' => 'ok', 'rotulo' => $idade];
    }

    /**
     * Avisa os admins dos pedidos que passaram do prazo.
     *
     * Idempotente: rodar de novo na mesma janela de 24 h não avisa outra vez.
     * O "marco" (48 h = 2, 72 h = 3…) fica no contexto da própria
     * notificação — sem tabela de controle.
     *
     * @return array{atrasados:int, novos:int, reabertos:int, mantidos:int,
     *               sem_destinatario:int, pedidos:array}
     */
    public function alertar(bool $simular = false): array
    {
        $r = ['atrasados' => 0, 'novos' => 0, 'reabertos' => 0, 'mantidos' => 0,
              'sem_destinatario' => 0, 'pedidos' => []];

        $atrasados = $this->atrasados();
        $r['atrasados'] = count($atrasados);
        if (!$atrasados) return $r;

        $destinatarios = NotificacaoService::destinatariosAdmin(self::QUEM_DECIDE);

        foreach ($atrasados as $p) {
            $marco     = intdiv($p['horas'], 24);
            $existente = $this->alertaExistente($p['id']);

            // Já avisado neste marco e ainda sem decisão: fica quieto.
            if ($existente && empty($existente['resolvido']) && $existente['marco'] >= $marco) {
                $r['mantidos']++;
                $r['pedidos'][] = $p + ['acao' => 'mantido'];
                continue;
            }

            $acao = $existente ? 'reaberto' : 'novo';
            $r['pedidos'][] = $p + ['acao' => $acao];
            if ($simular) {
                $r[$acao === 'novo' ? 'novos' : 'reabertos']++;
                continue;
            }

            if (!$destinatarios) {
                $r['sem_destinatario']++;
                continue;
            }

            $valor = 'R$ ' . number_format($p['total'], 2, ',', '.');
            $id = NotificacaoService::sincronizar(self::chave($p['id']), [
                'categoria' => 'pedido',
                'tipo'      => 'admin_analise_prazo',
                'titulo'    => "Análise atrasada · #{$p['codigo']} — há {$p['horas']}h",
                'mensagem'  => "Retido desde " . date('d/m H:i', strtotime($p['retido_em'])) . " · {$valor}. "
                             . 'O cliente foi avisado de que a análise leva até '
                             . self::PRAZO_HORAS . ' horas — libere ou recuse.',
                'url'       => '/admin/pagamentos/analise/' . $p['id'],
                'contexto'  => ['pedido_id' => $p['id'], 'marco' => $marco, 'horas' => $p['horas']],
            ], $destinatarios, true);

            if ($id === null) {
                $r['sem_destinatario']++;
                continue;
            }
            $r[$acao === 'novo' ? 'novos' : 'reabertos']++;
        }

        return $r;
    }

    /**
     * O pedido saiu da análise: o alerta, se existir, passa a dizer que está
     * resolvido. Não volta ao topo — ninguém precisa agir.
     *
     * Chamado pelo AdminPedidoService::mudarStatus(), por onde passam as três
     * saídas da fila: liberar, recusar (tela de análise) e o parecer da
     * ClearSale. Nunca derruba a mudança de status.
     */
    public function encerrarAlerta(int $pedidoId, string $codigo, string $novoStatus): void
    {
        try {
            $desfecho = [
                'pagamento_aprovado' => 'liberado',
                'cancelado'          => 'recusado',
            ][$novoStatus] ?? 'movido para ' . $novoStatus;

            NotificacaoService::atualizarPorChave(self::chave($pedidoId), [
                'tipo'     => 'admin_analise_prazo_resolvido',
                'titulo'   => "Análise concluída · #{$codigo} — {$desfecho}",
                'mensagem' => 'O pedido saiu da análise. Nada a fazer.',
                'contexto' => ['pedido_id' => $pedidoId, 'resolvido' => true],
            ], false);
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'pagamento', [
                'acao' => 'encerrar_alerta_prazo_analise', 'pedido_id' => $pedidoId,
            ]);
        }
    }

    /** @return array{marco:int, resolvido:bool}|null */
    private function alertaExistente(int $pedidoId): ?array
    {
        $st = $this->db->prepare(
            "SELECT contexto_json FROM notificacoes WHERE chave = ? ORDER BY id DESC LIMIT 1"
        );
        $st->execute([self::chave($pedidoId)]);
        $json = $st->fetchColumn();
        if ($json === false) return null;

        $ctx = json_decode((string) $json, true) ?: [];
        return [
            'marco'     => (int) ($ctx['marco'] ?? 0),
            'resolvido' => !empty($ctx['resolvido']),
        ];
    }
}
