<?php
/**
 * app/services/email/EmailAbTestService.php
 *
 * Orquestra o ciclo de vida de um teste A/B:
 *   1. validarConfiguracao  — checa se a campanha está pronta para A/B
 *   2. iniciarAmostra       — divide aleatoriamente os destinatários em A e B
 *   3. verificarDecisao     — chamado pelo worker; determina se já é hora de decidir
 *   4. calcularVencedor     — aplica a métrica configurada
 *   5. aplicarVencedor      — gera destinatários do rollout (resto da base) com a variação vencedora
 *   6. tratarEmpate         — usa configuração ab_em_empate
 *   7. escolherManualmente  — admin decide
 */
class EmailAbTestService
{
    /** @var PDO */
    private $db;
    /** @var EmailCampaignVariation */
    private $variacoes;
    /** @var EmailCampaign */
    private $campanhas;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->variacoes = new EmailCampaignVariation();
        $this->campanhas = new EmailCampaign();
    }

    // -------------------------------------------------------------------------
    // 1. Validação prévia
    // -------------------------------------------------------------------------

    /**
     * Verifica se a campanha está pronta para rodar A/B.
     *
     * @return array{ok:bool, erros:array}
     */
    public function validarConfiguracao(int $campanhaId): array
    {
        $erros = [];
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp) return ['ok' => false, 'erros' => ['Campanha não encontrada']];

        if (empty($camp['ab_ativo'])) {
            return ['ok' => false, 'erros' => ['Teste A/B não está ativo nesta campanha']];
        }

        $vs = $this->variacoes->findByCampanha($campanhaId);
        $porVar = [];
        foreach ($vs as $v) $porVar[$v['variacao']] = $v;

        foreach (['a','b'] as $letra) {
            if (!isset($porVar[$letra])) {
                $erros[] = "Variação $letra não configurada";
                continue;
            }
            $v = $porVar[$letra];
            if (empty($v['template_id'])) $erros[] = "Variação $letra: template não definido";
            if (empty($v['assunto']))     $erros[] = "Variação $letra: assunto não definido";
        }

        $pa = (int)($camp['ab_amostra_pct_a'] ?? 0);
        $pb = (int)($camp['ab_amostra_pct_b'] ?? 0);
        if ($pa < 5 || $pa > 50) $erros[] = "Amostra A deve estar entre 5% e 50%";
        if ($pb < 5 || $pb > 50) $erros[] = "Amostra B deve estar entre 5% e 50%";
        if (($pa + $pb) >= 100)  $erros[] = "Soma das amostras A+B deve ser menor que 100%";

        return ['ok' => empty($erros), 'erros' => $erros];
    }

    // -------------------------------------------------------------------------
    // 2. Iniciar amostra — divide destinatários
    // -------------------------------------------------------------------------

    /**
     * Marca destinatários A/B aleatoriamente.
     * Deve ser chamado APÓS popular email_campanha_destinatarios com a base completa.
     *
     * Lógica:
     *   - Conta total de destinatários pending
     *   - Calcula pct_a% e pct_b% deles
     *   - Marca aleatoriamente: variacao='a' OR 'b' OR NULL (rollout pendente)
     */
    public function iniciarAmostra(int $campanhaId): array
    {
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp) throw new RuntimeException('Campanha não encontrada');

        $pa = (int)($camp['ab_amostra_pct_a'] ?? 15);
        $pb = (int)($camp['ab_amostra_pct_b'] ?? 15);

        // Total de destinatários "pending" e sem variação atribuída
        $stTotal = $this->db->prepare(
            "SELECT COUNT(*) FROM email_campanha_destinatarios
             WHERE campanha_id = :c AND status = 'pendente' AND variacao IS NULL"
        );
        $stTotal->execute([':c' => $campanhaId]);
        $total = (int)$stTotal->fetchColumn();
        if ($total === 0) throw new RuntimeException('Sem destinatários pendentes para a amostra');

        $qtdA = max(1, (int)floor($total * ($pa / 100)));
        $qtdB = max(1, (int)floor($total * ($pb / 100)));
        if ($qtdA + $qtdB >= $total) {
            // Salvaguarda
            $qtdA = (int)floor($total * 0.15);
            $qtdB = (int)floor($total * 0.15);
        }

        // Marca A (aleatório) — usa ORDER BY RAND() em LIMIT
        $this->db->beginTransaction();
        try {
            $stA = $this->db->prepare(
                "UPDATE email_campanha_destinatarios
                 SET variacao = 'a', fase_ab = 'amostra'
                 WHERE id IN (
                    SELECT id FROM (
                        SELECT id FROM email_campanha_destinatarios
                        WHERE campanha_id = :c AND variacao IS NULL AND status='pendente'
                        ORDER BY RAND() LIMIT $qtdA
                    ) sub
                 )"
            );
            $stA->execute([':c' => $campanhaId]);
            $afetadosA = $stA->rowCount();

            $stB = $this->db->prepare(
                "UPDATE email_campanha_destinatarios
                 SET variacao = 'b', fase_ab = 'amostra'
                 WHERE id IN (
                    SELECT id FROM (
                        SELECT id FROM email_campanha_destinatarios
                        WHERE campanha_id = :c AND variacao IS NULL AND status='pendente'
                        ORDER BY RAND() LIMIT $qtdB
                    ) sub
                 )"
            );
            $stB->execute([':c' => $campanhaId]);
            $afetadosB = $stB->rowCount();

            // Atualiza contadores na variação
            $this->variacoes->setDestinatarios($campanhaId, 'a', $afetadosA);
            $this->variacoes->setDestinatarios($campanhaId, 'b', $afetadosB);

            // Marca a campanha
            $this->db->prepare(
                "UPDATE email_campanhas SET
                    ab_fase = 'amostra',
                    ab_amostra_iniciada_em = NOW()
                 WHERE id = :c"
            )->execute([':c' => $campanhaId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if (class_exists('LogService')) {
            LogService::audit('ab_iniciar_amostra', [
                'campanha_id' => $campanhaId,
                'total' => $total,
                'qtd_a' => $afetadosA,
                'qtd_b' => $afetadosB,
                'restante_rollout' => $total - $afetadosA - $afetadosB,
            ]);
        }

        return [
            'total' => $total,
            'amostra_a' => $afetadosA,
            'amostra_b' => $afetadosB,
            'rollout_pendente' => $total - $afetadosA - $afetadosB,
        ];
    }

    // -------------------------------------------------------------------------
    // 3. Verificar se já é hora de decidir
    // -------------------------------------------------------------------------

    /**
     * @return array{decidir:bool, motivo:string, info:array}
     */
    public function verificarDecisao(int $campanhaId): array
    {
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp || empty($camp['ab_ativo'])) {
            return ['decidir' => false, 'motivo' => 'campanha_nao_ab', 'info' => []];
        }
        if ($camp['ab_fase'] !== 'amostra') {
            return ['decidir' => false, 'motivo' => 'fora_da_amostra', 'info' => ['fase' => $camp['ab_fase']]];
        }
        if ($camp['ab_metrica'] === 'manual') {
            return ['decidir' => false, 'motivo' => 'metrica_manual', 'info' => []];
        }

        $tempoMin = (int)$camp['ab_tempo_analise_min'];
        $minEventos = (int)$camp['ab_min_eventos'];

        // Tempo decorrido
        $stT = $this->db->prepare(
            "SELECT TIMESTAMPDIFF(MINUTE, ab_amostra_iniciada_em, NOW())
             FROM email_campanhas WHERE id = :c"
        );
        $stT->execute([':c' => $campanhaId]);
        $minDecorridos = (int)$stT->fetchColumn();

        if ($minDecorridos < $tempoMin) {
            return [
                'decidir' => false,
                'motivo' => 'aguardando_tempo',
                'info'   => ['decorridos' => $minDecorridos, 'minimo' => $tempoMin],
            ];
        }

        // Eventos mínimos atingidos?
        $vs = $this->variacoes->findByCampanha($campanhaId);
        if (count($vs) < 2) {
            return ['decidir' => false, 'motivo' => 'variacoes_faltando', 'info' => []];
        }

        $coluna = $camp['ab_metrica'] === 'abertura' ? 'total_aberturas' : 'total_cliques';
        $eventosA = (int)($vs[0][$coluna] ?? 0);
        $eventosB = (int)($vs[1][$coluna] ?? 0);

        if ($eventosA < $minEventos || $eventosB < $minEventos) {
            return [
                'decidir' => false,
                'motivo' => 'aguardando_eventos',
                'info'   => ['a' => $eventosA, 'b' => $eventosB, 'minimo' => $minEventos],
            ];
        }

        return ['decidir' => true, 'motivo' => 'pronto', 'info' => []];
    }

    // -------------------------------------------------------------------------
    // 4. Calcular vencedor
    // -------------------------------------------------------------------------

    /**
     * @return array{vencedor:?string, empate:bool, taxas:array}
     */
    public function calcularVencedor(int $campanhaId): array
    {
        $camp = $this->campanhas->find($campanhaId);
        if (!$camp) throw new RuntimeException('Campanha não encontrada');

        $vs = $this->variacoes->findByCampanha($campanhaId);
        if (count($vs) < 2) throw new RuntimeException('Variações insuficientes');

        $taxas = [];
        foreach ($vs as $v) {
            $base = max(1, (int)$v['total_entregues']);
            $taxas[$v['variacao']] = [
                'enviados'   => (int)$v['total_enviados'],
                'entregues'  => (int)$v['total_entregues'],
                'aberturas'  => (int)$v['total_aberturas'],
                'cliques'    => (int)$v['total_cliques'],
                'bounces'    => (int)$v['total_bounces'],
                'complaints' => (int)$v['total_complaints'],
                'taxa_abertura'   => round(($v['total_aberturas'] / $base) * 100, 2),
                'taxa_clique'     => round(($v['total_cliques']  / $base) * 100, 2),
                'taxa_bounce'     => round(($v['total_bounces']  / max(1, (int)$v['total_enviados'])) * 100, 2),
                'taxa_complaint'  => round(($v['total_complaints'] / max(1, (int)$v['total_enviados'])) * 100, 2),
            ];
        }

        $metrica = $camp['ab_metrica'] === 'abertura' ? 'taxa_abertura' : 'taxa_clique';

        // Score: métrica - penalidade por bounces+complaints
        $scoreA = $taxas['a'][$metrica] - ($taxas['a']['taxa_complaint'] * 5);
        $scoreB = $taxas['b'][$metrica] - ($taxas['b']['taxa_complaint'] * 5);

        // Considera empate se diferença for menor que 0.5pp (ponto percentual)
        $empate = abs($scoreA - $scoreB) < 0.5;
        $vencedor = null;
        if (!$empate) $vencedor = $scoreA > $scoreB ? 'a' : 'b';

        $taxas['scores'] = ['a' => $scoreA, 'b' => $scoreB];
        $taxas['metrica_usada'] = $metrica;

        return ['vencedor' => $vencedor, 'empate' => $empate, 'taxas' => $taxas];
    }

    // -------------------------------------------------------------------------
    // 5. Aplicar vencedor — atribui variação para o rollout
    // -------------------------------------------------------------------------

    public function aplicarVencedor(int $campanhaId, string $vencedor, string $decididaPor = 'auto'): int
    {
        $vencedor = in_array($vencedor, ['a','b'], true) ? $vencedor : 'a';

        $this->db->beginTransaction();
        try {
            // Atribui vencedor a todos os destinatários ainda sem variação
            $st = $this->db->prepare(
                "UPDATE email_campanha_destinatarios
                 SET variacao = :v, fase_ab = 'rollout'
                 WHERE campanha_id = :c AND variacao IS NULL AND status='pendente'"
            );
            $st->execute([':v' => $vencedor, ':c' => $campanhaId]);
            $rollout = $st->rowCount();

            // Atualiza contador
            $this->variacoes->incrementar($campanhaId, $vencedor, 'destinatarios', $rollout);

            // Marca campanha
            $this->db->prepare(
                "UPDATE email_campanhas SET
                    ab_fase = 'rollout',
                    ab_vencedor = :v,
                    ab_decidida_em = NOW(),
                    ab_decidida_por = :d
                 WHERE id = :c"
            )->execute([':v' => $vencedor, ':d' => $decididaPor, ':c' => $campanhaId]);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        if (class_exists('LogService')) {
            LogService::audit('ab_aplicar_vencedor', [
                'campanha_id' => $campanhaId,
                'vencedor' => $vencedor,
                'rollout_qtd' => $rollout,
                'decidida_por' => $decididaPor,
            ]);
        }

        return $rollout;
    }

    // -------------------------------------------------------------------------
    // 6. Tratar empate
    // -------------------------------------------------------------------------

    public function tratarEmpate(int $campanhaId): array
    {
        $camp = $this->campanhas->find($campanhaId);
        $modo = $camp['ab_em_empate'] ?? 'aguardar_manual';

        if ($modo === 'a' || $modo === 'b') {
            $qtd = $this->aplicarVencedor($campanhaId, $modo, 'auto_empate_' . $modo);
            return ['acao' => 'aplicado', 'vencedor' => $modo, 'qtd' => $qtd];
        }
        if ($modo === 'random') {
            $venc = (mt_rand(0, 1) === 0) ? 'a' : 'b';
            $qtd = $this->aplicarVencedor($campanhaId, $venc, 'auto_empate_random');
            return ['acao' => 'aplicado', 'vencedor' => $venc, 'qtd' => $qtd];
        }

        // Marca como aguardando decisão manual
        $this->db->prepare(
            "UPDATE email_campanhas SET ab_fase = 'aguardando_vencedor'
             WHERE id = :c"
        )->execute([':c' => $campanhaId]);

        return ['acao' => 'aguardando_manual', 'vencedor' => null];
    }

    // -------------------------------------------------------------------------
    // 7. Escolha manual
    // -------------------------------------------------------------------------

    public function escolherManualmente(int $campanhaId, string $vencedor, ?int $userId = null): int
    {
        if (!in_array($vencedor, ['a','b'], true)) {
            throw new RuntimeException('Vencedor inválido');
        }
        return $this->aplicarVencedor($campanhaId, $vencedor, 'manual_user_' . ((int)$userId));
    }

    /**
     * Orquestrador principal — chamado pelo worker a cada lote.
     * Retorna o que aconteceu pra logging.
     */
    public function processarCiclo(int $campanhaId): array
    {
        $check = $this->verificarDecisao($campanhaId);
        if (!$check['decidir']) {
            return ['acao' => 'aguardando', 'motivo' => $check['motivo'], 'info' => $check['info']];
        }

        // Calcula vencedor
        $res = $this->calcularVencedor($campanhaId);

        if ($res['empate']) {
            $emp = $this->tratarEmpate($campanhaId);
            return ['acao' => 'empate', 'tratamento' => $emp, 'taxas' => $res['taxas']];
        }

        $camp = $this->campanhas->find($campanhaId);
        // Se envio automático está desativado, aguarda decisão manual
        if (empty($camp['ab_envio_automatico'])) {
            $this->db->prepare(
                "UPDATE email_campanhas SET ab_fase = 'aguardando_vencedor'
                 WHERE id = :c"
            )->execute([':c' => $campanhaId]);
            return ['acao' => 'aguardando_manual', 'vencedor_sugerido' => $res['vencedor'], 'taxas' => $res['taxas']];
        }

        $qtd = $this->aplicarVencedor($campanhaId, $res['vencedor'], 'auto');
        return ['acao' => 'aplicado', 'vencedor' => $res['vencedor'], 'qtd' => $qtd, 'taxas' => $res['taxas']];
    }
}
