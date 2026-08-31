<?php
/**
 * app/services/ChatDashboardService.php
 *
 * Números do dashboard. Todas as agregações são calculadas na hora sobre
 * índices existentes — o volume de um único número de WhatsApp não justifica
 * pré-agregação. A tabela chat_metricas_diarias existe para quando justificar.
 */
class ChatDashboardService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /** Cartões do topo, com variação sobre o período anterior. */
    public function kpis(int $dias = 30): array
    {
        $dias = max(1, min(365, $dias));

        $atual    = $this->janela($dias, 0);
        $anterior = $this->janela($dias, $dias);

        $kpis = [];
        foreach ($atual as $k => $v) {
            $ant = (int)($anterior[$k] ?? 0);
            $kpis[$k] = [
                'valor'    => (int)$v,
                'anterior' => $ant,
                'variacao' => $ant > 0 ? round((($v - $ant) / $ant) * 100, 1) : ($v > 0 ? 100.0 : 0.0),
            ];
        }

        // Estado instantâneo — não faz sentido comparar com período anterior
        $kpis['contatos_total']  = ['valor' => $this->umNumero("SELECT COUNT(*) FROM chat_contatos")];
        $kpis['janela_aberta']   = ['valor' => $this->umNumero(
            "SELECT COUNT(*) FROM chat_contatos WHERE janela_expira_em > NOW() AND optin = 1 AND bloqueado = 0"
        )];
        $kpis['conversas_abertas'] = ['valor' => $this->umNumero(
            "SELECT COUNT(*) FROM chat_conversas WHERE status <> 'resolvida'"
        )];
        $kpis['nao_lidas'] = ['valor' => $this->umNumero(
            "SELECT COUNT(*) FROM chat_conversas WHERE nao_lidas > 0"
        )];
        $kpis['sessoes_ativas'] = ['valor' => $this->umNumero(
            "SELECT COUNT(*) FROM chat_sessoes WHERE status IN ('ativo','dormindo','aguardando_resposta')"
        )];
        $kpis['fluxos_publicados'] = ['valor' => $this->umNumero(
            "SELECT COUNT(*) FROM chat_fluxos WHERE status = 'publicado'"
        )];

        return $kpis;
    }

    /** Contadores de uma janela de N dias, deslocada $offset dias para trás. */
    private function janela(int $dias, int $offset): array
    {
        $ini = date('Y-m-d 00:00:00', strtotime('-' . ($dias + $offset - 1) . ' days'));
        $fim = $offset > 0
            ? date('Y-m-d 23:59:59', strtotime('-' . $offset . ' days'))
            : date('Y-m-d 23:59:59');

        $p = [':i' => $ini, ':f' => $fim];

        return [
            'contatos_novos' => $this->umNumero(
                "SELECT COUNT(*) FROM chat_contatos WHERE criado_em BETWEEN :i AND :f", $p),
            'msgs_recebidas' => $this->umNumero(
                "SELECT COUNT(*) FROM chat_mensagens WHERE direcao = 'entrada' AND criado_em BETWEEN :i AND :f", $p),
            'msgs_enviadas' => $this->umNumero(
                "SELECT COUNT(*) FROM chat_mensagens WHERE direcao = 'saida' AND criado_em BETWEEN :i AND :f", $p),
            'conversas_novas' => $this->umNumero(
                "SELECT COUNT(*) FROM chat_conversas WHERE criado_em BETWEEN :i AND :f", $p),
            'resolvidas' => $this->umNumero(
                "SELECT COUNT(*) FROM chat_conversas WHERE resolvida_em BETWEEN :i AND :f", $p),
            'sessoes' => $this->umNumero(
                "SELECT COUNT(*) FROM chat_sessoes WHERE criado_em BETWEEN :i AND :f", $p),
            'falhas' => $this->umNumero(
                "SELECT COUNT(*) FROM chat_mensagens WHERE status = 'falhou' AND criado_em BETWEEN :i AND :f", $p),
        ];
    }

    /** Série diária para o gráfico principal. */
    public function serie(int $dias = 30): array
    {
        return (new ChatMensagemService($this->db))->serieDiaria($dias);
    }

    /**
     * Quebra por canal. A caixa é unificada, mas WhatsApp e Instagram têm
     * dinâmicas diferentes — misturar tudo num número só esconde qual dos
     * dois está puxando o resultado.
     */
    public function porCanal(int $dias = 30): array
    {
        $dias  = max(1, min(365, $dias));
        $desde = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));

        $base = [
            'whatsapp'  => ['rotulo' => 'WhatsApp',  'cor' => '#25d366'],
            'instagram' => ['rotulo' => 'Instagram', 'cor' => '#e1306c'],
        ];

        foreach ($base as $canal => $_) {
            $p = [':c' => $canal, ':d' => $desde];

            $base[$canal]['contatos'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_contatos WHERE canal = :c", [':c' => $canal]);
            $base[$canal]['novos'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_contatos WHERE canal = :c AND criado_em >= :d", $p);
            $base[$canal]['conversas_abertas'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_conversas WHERE canal = :c AND status <> 'resolvida'", [':c' => $canal]);
            $base[$canal]['nao_lidas'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_conversas WHERE canal = :c AND nao_lidas > 0", [':c' => $canal]);
            $base[$canal]['janela_aberta'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_contatos
                 WHERE canal = :c AND janela_expira_em > NOW() AND optin = 1 AND bloqueado = 0",
                [':c' => $canal]);

            // Mensagens: a direção vive na conversa, não no contato
            $base[$canal]['recebidas'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_mensagens m
                 JOIN chat_conversas cv ON cv.id = m.conversa_id
                 WHERE cv.canal = :c AND m.direcao = 'entrada' AND m.criado_em >= :d", $p);
            $base[$canal]['enviadas'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_mensagens m
                 JOIN chat_conversas cv ON cv.id = m.conversa_id
                 WHERE cv.canal = :c AND m.direcao = 'saida' AND m.criado_em >= :d", $p);
        }

        return $base;
    }

    /**
     * Bloco do Instagram: conta conectada, comentários e as regras que mais
     * disparam. Devolve `conectado=false` quando não há conta — a view usa
     * isso para chamar a ação em vez de mostrar zeros.
     */
    public function instagram(int $dias = 30): array
    {
        $out = [
            'conectado'    => false,
            'conta'        => null,
            'comentarios'  => 0,
            'dms'          => 0,
            'respostas'    => 0,
            'falhas'       => 0,
            'regras_ativas' => 0,
            'midias'       => 0,
            'top_regras'   => [],
            'sem_regra'    => 0,
        ];

        try {
            $st = $this->db->query(
                "SELECT id, username, nome, foto_url, seguidores, webhook_assinado, ativo
                 FROM chat_ig_contas WHERE ativo = 1 ORDER BY id LIMIT 1"
            );
            $conta = $st->fetch(PDO::FETCH_ASSOC);
            if (!$conta) return $out;

            $out['conectado'] = true;
            $out['conta']     = $conta;

            $dias  = max(1, min(365, $dias));
            $desde = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));
            $p     = [':d' => $desde];

            $out['comentarios'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_ig_comentarios WHERE criado_em >= :d", $p);
            $out['dms'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_ig_comentarios WHERE dm_enviado = 1 AND criado_em >= :d", $p);
            $out['respostas'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_ig_comentarios WHERE respondido_publico = 1 AND criado_em >= :d", $p);
            $out['falhas'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_ig_comentarios WHERE dm_erro IS NOT NULL AND criado_em >= :d", $p);
            // Comentário que não casou com regra nenhuma: oportunidade perdida
            $out['sem_regra'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_ig_comentarios WHERE regra_id IS NULL AND criado_em >= :d", $p);

            $out['regras_ativas'] = $this->umNumero("SELECT COUNT(*) FROM chat_ig_regras WHERE ativo = 1");
            $out['midias']        = $this->umNumero("SELECT COUNT(*) FROM chat_ig_midias");

            $stR = $this->db->prepare(
                "SELECT r.id, r.nome, r.palavras, r.ativo, r.total_disparos,
                        (SELECT COUNT(*) FROM chat_ig_comentarios k
                         WHERE k.regra_id = r.id AND k.dm_enviado = 1) AS dms
                 FROM chat_ig_regras r
                 ORDER BY r.total_disparos DESC, r.id DESC
                 LIMIT 5"
            );
            $stR->execute();
            $out['top_regras'] = $stR->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Módulo do Instagram ainda não migrado — o dashboard segue vivo
        }

        return $out;
    }

    /** Crescimento de contatos por dia. */
    public function serieContatos(int $dias = 30): array
    {
        $dias = max(1, min(180, $dias));
        $st = $this->db->query(
            "SELECT DATE(criado_em) AS dia, COUNT(*) AS n FROM chat_contatos
             WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL $dias DAY)
             GROUP BY DATE(criado_em)"
        );
        $porDia = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porDia[$r['dia']] = (int)$r['n'];

        $out = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out[] = ['dia' => $d, 'rotulo' => date('d/m', strtotime($d)), 'novos' => $porDia[$d] ?? 0];
        }
        return $out;
    }

    /** Distribuição de mensagens recebidas por hora — quando dá pico. */
    public function porHora(int $dias = 14): array
    {
        $dias = max(1, min(90, $dias));
        $st = $this->db->query(
            "SELECT HOUR(criado_em) AS h, COUNT(*) AS n FROM chat_mensagens
             WHERE direcao = 'entrada' AND criado_em >= DATE_SUB(NOW(), INTERVAL $dias DAY)
             GROUP BY HOUR(criado_em)"
        );
        $mapa = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $mapa[(int)$r['h']] = (int)$r['n'];

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = ['hora' => sprintf('%02dh', $h), 'total' => $mapa[$h] ?? 0];
        }
        return $out;
    }

    /** Fluxos com melhor desempenho. */
    public function topFluxos(int $limite = 8): array
    {
        $st = $this->db->prepare(
            "SELECT f.id, f.nome, f.status,
                    COUNT(s.id) AS iniciadas,
                    SUM(s.status = 'concluido') AS concluidas,
                    SUM(s.status = 'erro') AS erros,
                    SUM(s.status IN ('ativo','dormindo','aguardando_resposta')) AS em_curso
             FROM chat_fluxos f
             LEFT JOIN chat_sessoes s ON s.fluxo_id = f.id
             WHERE f.status <> 'arquivado'
             GROUP BY f.id, f.nome, f.status
             ORDER BY iniciadas DESC
             LIMIT " . max(1, min(50, $limite))
        );
        $st->execute();

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ini = (int)$r['iniciadas'];
            $r['taxa_conclusao'] = $ini > 0 ? round(((int)$r['concluidas'] / $ini) * 100, 1) : 0.0;
            $out[] = $r;
        }
        return $out;
    }

    /** Gatilhos mais acionados. */
    public function topGatilhos(int $limite = 8): array
    {
        $st = $this->db->prepare(
            "SELECT id, nome, tipo, padrao, total_disparos, ultimo_disparo_em, ativo
             FROM chat_gatilhos
             WHERE total_disparos > 0
             ORDER BY total_disparos DESC
             LIMIT " . max(1, min(50, $limite))
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Distribuição de contatos por tag. */
    public function porTag(int $limite = 10): array
    {
        $st = $this->db->prepare(
            "SELECT t.id, t.nome, t.cor, COUNT(ct.contato_id) AS total
             FROM chat_tags t
             LEFT JOIN chat_contato_tags ct ON ct.tag_id = t.id
             GROUP BY t.id, t.nome, t.cor
             ORDER BY total DESC
             LIMIT " . max(1, min(50, $limite))
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tempo médio de primeira resposta humana, em minutos.
     * Mede da mensagem do cliente até a primeira resposta de um agente
     * (autor_usuario_id preenchido) — resposta de bot não conta como atendimento.
     */
    public function tempoMedioResposta(int $dias = 30): ?float
    {
        $dias = max(1, min(180, $dias));
        try {
            $st = $this->db->query(
                "SELECT AVG(TIMESTAMPDIFF(SECOND, entrada.criado_em, saida.criado_em)) AS media
                 FROM chat_mensagens entrada
                 JOIN chat_mensagens saida
                   ON saida.conversa_id = entrada.conversa_id
                  AND saida.direcao = 'saida'
                  AND saida.autor_usuario_id IS NOT NULL
                  AND saida.id = (
                        SELECT MIN(s2.id) FROM chat_mensagens s2
                        WHERE s2.conversa_id = entrada.conversa_id
                          AND s2.direcao = 'saida'
                          AND s2.autor_usuario_id IS NOT NULL
                          AND s2.id > entrada.id
                      )
                 WHERE entrada.direcao = 'entrada'
                   AND entrada.criado_em >= DATE_SUB(NOW(), INTERVAL $dias DAY)"
            );
            $m = $st->fetchColumn();
            return $m !== null && $m !== false ? round(((float)$m) / 60, 1) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Últimas falhas de envio — o que está quebrando agora. */
    public function ultimasFalhas(int $limite = 10): array
    {
        $st = $this->db->prepare(
            "SELECT m.id, m.erro_codigo, m.erro_detalhe, m.criado_em, m.origem,
                    c.wa_id, c.nome, c.nome_perfil
             FROM chat_mensagens m
             JOIN chat_contatos c ON c.id = m.contato_id
             WHERE m.status = 'falhou'
             ORDER BY m.id DESC
             LIMIT " . max(1, min(50, $limite))
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Saúde da integração — o que a tela de config precisa mostrar. */
    public function saude(): array
    {
        $out = [
            'meta_ok'          => false,
            'meta_detalhe'     => '',
            'app_secret'       => ChatMetaClient::temAppSecret(),
            'verify_token'     => ChatMetaClient::verifyToken() !== '',
            'bot_ativo'        => ChatConfig::bool('bot_ativo', true),
            'templates'        => 0,
            'ultimo_webhook'   => null,
            'webhooks_24h'     => 0,
            'webhooks_recusados_24h' => 0,
        ];

        try {
            $out['meta_ok'] = (new ChatMetaClient())->estaConfigurado();
        } catch (Throwable $e) {
            $out['meta_detalhe'] = $e->getMessage();
        }

        try {
            $out['templates'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_templates WHERE status = 'APPROVED'"
            );
            $out['ultimo_webhook'] = $this->db->query(
                "SELECT MAX(criado_em) FROM chat_webhook_log"
            )->fetchColumn() ?: null;
            $out['webhooks_24h'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_webhook_log WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $out['webhooks_recusados_24h'] = $this->umNumero(
                "SELECT COUNT(*) FROM chat_webhook_log
                 WHERE assinatura_ok = 0 AND criado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
        } catch (Throwable $e) {}

        return $out;
    }

    private function umNumero(string $sql, array $params = []): int
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
