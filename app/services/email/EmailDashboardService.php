<?php
/**
 * app/services/email/EmailDashboardService.php
 *
 * Agrega todos os KPIs do painel de Email Marketing.
 */
class EmailDashboardService
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Coleta resumida pra exibir no dashboard. */
    public function coletar(): array
    {
        return [
            'contatos'     => $this->kpiContatos(),
            'campanhas'    => $this->kpiCampanhas(),
            'importacoes'  => $this->kpiImportacoes(),
            'templates'    => $this->kpiTemplates(),
            'taxas'        => $this->taxasMedias(),
            'crescimento'  => $this->crescimentoBase(),
            'ultimas_campanhas'    => $this->ultimasCampanhas(5),
            'ultimas_importacoes'  => $this->ultimasImportacoes(5),
            'alertas_reputacao'    => $this->alertasReputacao(),
        ];
    }

    private function kpiContatos(): array
    {
        $r = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status='ativo' THEN 1 ELSE 0 END) AS ativos,
                SUM(CASE WHEN status='descadastrado' THEN 1 ELSE 0 END) AS descadastrados,
                SUM(CASE WHEN status='bounce' THEN 1 ELSE 0 END) AS bounces,
                SUM(CASE WHEN status='complaint' THEN 1 ELSE 0 END) AS complaints,
                SUM(CASE WHEN status='bloqueado' THEN 1 ELSE 0 END) AS bloqueados
             FROM email_contatos"
        )->fetch(PDO::FETCH_ASSOC);

        // Supressões
        $sup = (int)$this->db->query(
            "SELECT COUNT(*) FROM email_supressoes
             WHERE expira_em IS NULL OR expira_em > NOW()"
        )->fetchColumn();

        return array_map('intval', $r) + ['supressoes' => $sup];
    }

    private function kpiCampanhas(): array
    {
        $r = $this->db->query(
            "SELECT
                SUM(CASE WHEN status='rascunho'   THEN 1 ELSE 0 END) AS rascunho,
                SUM(CASE WHEN status='agendada'   THEN 1 ELSE 0 END) AS agendada,
                SUM(CASE WHEN status='em_envio'   THEN 1 ELSE 0 END) AS em_envio,
                SUM(CASE WHEN status='pausada'    THEN 1 ELSE 0 END) AS pausada,
                SUM(CASE WHEN status='concluida'  THEN 1 ELSE 0 END) AS concluida,
                SUM(CASE WHEN status='cancelada'  THEN 1 ELSE 0 END) AS cancelada,
                SUM(CASE WHEN ab_ativo = 1 AND ab_fase IN ('amostra','aguardando_vencedor') THEN 1 ELSE 0 END) AS ab_em_andamento
             FROM email_campanhas"
        )->fetch(PDO::FETCH_ASSOC);

        return array_map('intval', $r);
    }

    private function kpiImportacoes(): array
    {
        $r = $this->db->query(
            "SELECT
                SUM(CASE WHEN status='fila'         THEN 1 ELSE 0 END) AS fila,
                SUM(CASE WHEN status='processando' THEN 1 ELSE 0 END) AS processando,
                SUM(CASE WHEN status='concluido'   THEN 1 ELSE 0 END) AS concluido,
                SUM(CASE WHEN status='erro'        THEN 1 ELSE 0 END) AS erro,
                COALESCE(SUM(inseridos), 0)    AS total_inseridos_geral,
                COALESCE(SUM(atualizados), 0)  AS total_atualizados_geral
             FROM email_importacoes
             WHERE criado_em > DATE_SUB(NOW(), INTERVAL 90 DAY)"
        )->fetch(PDO::FETCH_ASSOC);

        return array_map('intval', $r);
    }

    private function kpiTemplates(): array
    {
        $r = $this->db->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status='ativo' THEN 1 ELSE 0 END) AS ativos,
                SUM(CASE WHEN formato='visual' THEN 1 ELSE 0 END) AS visuais,
                SUM(CASE WHEN render_status='warning' THEN 1 ELSE 0 END) AS com_aviso,
                SUM(CASE WHEN render_status='erro' THEN 1 ELSE 0 END) AS com_erro
             FROM email_templates"
        )->fetch(PDO::FETCH_ASSOC);
        return array_map('intval', $r);
    }

    /** Taxas médias dos últimos 30 dias. */
    private function taxasMedias(): array
    {
        $r = $this->db->query(
            "SELECT
                COALESCE(SUM(total_enviados), 0)    AS enviados,
                COALESCE(SUM(total_entregues), 0)   AS entregues,
                COALESCE(SUM(total_aberturas), 0)   AS aberturas,
                COALESCE(SUM(total_cliques), 0)     AS cliques,
                COALESCE(SUM(total_bounces), 0)     AS bounces,
                COALESCE(SUM(total_complaints), 0)  AS complaints,
                COALESCE(SUM(total_descadastros), 0) AS descadastros
             FROM email_campanhas
             WHERE status IN ('em_envio','concluida')
               AND COALESCE(concluida_em, atualizado_em) > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetch(PDO::FETCH_ASSOC);

        $entregues = max(1, (int)$r['entregues']);
        $enviados  = max(1, (int)$r['enviados']);

        return [
            'enviados'   => (int)$r['enviados'],
            'entregues'  => (int)$r['entregues'],
            'aberturas'  => (int)$r['aberturas'],
            'cliques'    => (int)$r['cliques'],
            'bounces'    => (int)$r['bounces'],
            'complaints' => (int)$r['complaints'],
            'descadastros' => (int)$r['descadastros'],
            'taxa_entrega'    => round(($r['entregues']  / $enviados) * 100, 2),
            'taxa_abertura'   => round(($r['aberturas']  / $entregues) * 100, 2),
            'taxa_clique'     => round(($r['cliques']    / $entregues) * 100, 2),
            'taxa_bounce'     => round(($r['bounces']    / $enviados) * 100, 2),
            'taxa_complaint'  => round(($r['complaints'] / $enviados) * 100, 4),
            'taxa_descadastro' => round(($r['descadastros'] / $entregues) * 100, 2),
        ];
    }

    /** Comparação dos últimos 30 dias vs 30 anteriores. */
    private function crescimentoBase(): array
    {
        $atual = (int)$this->db->query(
            "SELECT COUNT(*) FROM email_contatos
             WHERE status='ativo'
               AND criado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $anterior = (int)$this->db->query(
            "SELECT COUNT(*) FROM email_contatos
             WHERE status='ativo'
               AND criado_em > DATE_SUB(NOW(), INTERVAL 60 DAY)
               AND criado_em <= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchColumn();

        $delta = $atual - $anterior;
        $deltaPct = $anterior > 0 ? round(($delta / $anterior) * 100, 1) : null;

        return [
            'novos_30d' => $atual,
            'novos_30d_anterior' => $anterior,
            'delta' => $delta,
            'delta_pct' => $deltaPct,
        ];
    }

    private function ultimasCampanhas(int $n): array
    {
        $st = $this->db->prepare(
            "SELECT id, nome, status, ab_ativo, ab_fase, ab_vencedor,
                    total_destinatarios, total_enviados, total_aberturas, total_cliques,
                    criado_em, COALESCE(concluida_em, atualizado_em) AS data_ref
             FROM email_campanhas
             ORDER BY COALESCE(concluida_em, atualizado_em) DESC
             LIMIT $n"
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ultimasImportacoes(int $n): array
    {
        $st = $this->db->prepare(
            "SELECT id, arquivo, status, total_linhas, inseridos, atualizados, invalidos,
                    progresso_pct, criado_em, concluido_em
             FROM email_importacoes
             ORDER BY id DESC
             LIMIT $n"
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alertas de reputação:
     *   - bounce rate > 2% (alerta)
     *   - bounce rate > 5% (crítico)
     *   - complaint rate > 0.1% (alerta)
     *   - complaint rate > 0.3% (crítico)
     */
    private function alertasReputacao(): array
    {
        $alertas = [];
        $t = $this->taxasMedias();

        if ($t['taxa_bounce'] > 5) {
            $alertas[] = [
                'nivel' => 'critico',
                'titulo' => 'Bounce rate crítico',
                'mensagem' => "Bounce rate dos últimos 30 dias está em {$t['taxa_bounce']}% — acima do limite crítico de 5%. Provedores podem suspender envios.",
            ];
        } elseif ($t['taxa_bounce'] > 2) {
            $alertas[] = [
                'nivel' => 'alerta',
                'titulo' => 'Bounce rate elevado',
                'mensagem' => "Bounce rate em {$t['taxa_bounce']}% — acima do recomendado de 2%. Revise sua lista e remova emails inválidos.",
            ];
        }

        if ($t['taxa_complaint'] > 0.3) {
            $alertas[] = [
                'nivel' => 'critico',
                'titulo' => 'Taxa de spam crítica',
                'mensagem' => "Taxa de complaints em {$t['taxa_complaint']}% — acima de 0,3% pode levar a bloqueio.",
            ];
        } elseif ($t['taxa_complaint'] > 0.1) {
            $alertas[] = [
                'nivel' => 'alerta',
                'titulo' => 'Taxa de spam elevada',
                'mensagem' => "Taxa de complaints em {$t['taxa_complaint']}% — acima de 0,1%. Revise conteúdo e segmentação.",
            ];
        }

        // Importações travadas?
        $travadas = (int)$this->db->query(
            "SELECT COUNT(*) FROM email_importacoes
             WHERE status='processando'
               AND locked_at IS NOT NULL
               AND locked_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )->fetchColumn();
        if ($travadas > 0) {
            $alertas[] = [
                'nivel' => 'alerta',
                'titulo' => 'Importações travadas',
                'mensagem' => "$travadas importação(ões) parecem travadas há mais de 1 hora. Verifique se o csv-import-worker está rodando.",
            ];
        }

        // Templates com erro de render
        $tplErro = (int)$this->db->query(
            "SELECT COUNT(*) FROM email_templates WHERE render_status='erro'"
        )->fetchColumn();
        if ($tplErro > 0) {
            $alertas[] = [
                'nivel' => 'alerta',
                'titulo' => 'Templates com erro',
                'mensagem' => "$tplErro template(s) com erro de renderização. Revise antes de usar em campanhas.",
            ];
        }

        return $alertas;
    }
}
