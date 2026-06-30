<?php
/**
 * app/services/email/EmailMarketingService.php
 *
 * Orquestrador macro: KPIs do dashboard, totais agregados.
 */
class EmailMarketingService
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function dashboardKpis()
    {
        $out = [
            'contatos_ativos' => 0,
            'contatos_descadastrados' => 0,
            'contatos_total' => 0,
            'campanhas_ativas' => 0,
            'campanhas_total' => 0,
            'enviados_ult_30d' => 0,
            'entregues_ult_30d' => 0,
            'aberturas_ult_30d' => 0,
            'cliques_ult_30d' => 0,
            'taxa_abertura_30d' => 0.0,
            'taxa_clique_30d' => 0.0,
            'taxa_bounce_30d' => 0.0,
            'supressoes_total' => 0,
        ];

        $r = $this->db->query("SELECT
                COUNT(*) AS total,
                SUM(status='ativo') AS ativos,
                SUM(status='descadastrado') AS descads
            FROM email_contatos")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $out['contatos_total'] = (int)$r['total'];
            $out['contatos_ativos'] = (int)$r['ativos'];
            $out['contatos_descadastrados'] = (int)$r['descads'];
        }

        $r = $this->db->query("SELECT
                COUNT(*) AS total,
                SUM(status IN ('agendada','enviando','enfileirando','pausada')) AS ativas
            FROM email_campanhas")->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $out['campanhas_total']  = (int)$r['total'];
            $out['campanhas_ativas'] = (int)$r['ativas'];
        }

        $r = $this->db->query("SELECT
                COALESCE(SUM(total_enviados),0)    AS env,
                COALESCE(SUM(total_entregues),0)   AS entr,
                COALESCE(SUM(total_aberturas),0)   AS ab,
                COALESCE(SUM(total_cliques),0)     AS cl,
                COALESCE(SUM(total_bounces),0)     AS bo
            FROM email_campanhas
            WHERE concluida_em IS NULL OR concluida_em >= (NOW() - INTERVAL 30 DAY)")
            ->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $env = (int)$r['env'];
            $entr = (int)$r['entr'];
            $out['enviados_ult_30d']  = $env;
            $out['entregues_ult_30d'] = $entr;
            $out['aberturas_ult_30d'] = (int)$r['ab'];
            $out['cliques_ult_30d']   = (int)$r['cl'];

            $base = $entr > 0 ? $entr : $env;
            if ($base > 0) {
                $out['taxa_abertura_30d'] = round(((int)$r['ab'] / $base) * 100, 2);
                $out['taxa_clique_30d']   = round(((int)$r['cl'] / $base) * 100, 2);
            }
            if ($env > 0) {
                $out['taxa_bounce_30d'] = round(((int)$r['bo'] / $env) * 100, 2);
            }
        }

        $out['supressoes_total'] = (int)$this->db->query(
            "SELECT COUNT(*) FROM email_supressoes
             WHERE expira_em IS NULL OR expira_em > NOW()"
        )->fetchColumn();

        return $out;
    }

    public function ultimasCampanhas($limit = 5)
    {
        $limit = max(1, min(50, (int)$limit));
        $sql = "SELECT id, nome, status, total_destinatarios, total_enviados,
                       total_aberturas, total_cliques, total_bounces, criado_em
                FROM email_campanhas
                ORDER BY id DESC LIMIT $limit";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}