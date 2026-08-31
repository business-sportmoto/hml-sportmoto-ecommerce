<?php
/**
 * app/services/ChatMensagemService.php
 *
 * Persistência das mensagens e leitura da thread.
 *
 * DEDUP POR wamid: a Meta reenvia o mesmo webhook quando não recebe 200 rápido
 * o suficiente. Sem dedup, uma lentidão de rede vira mensagem duplicada na
 * thread E disparo duplicado de fluxo. O UNIQUE em chat_mensagens.wamid é a
 * garantia real — o INSERT IGNORE devolve rowCount 0 e o chamador sabe que já
 * tinha processado aquilo.
 */
class ChatMensagemService
{
    private PDO $db;
    private ChatConversaService $conversas;

    public function __construct(?PDO $db = null)
    {
        $this->db       = $db ?? Database::getInstance()->getConnection();
        $this->conversas = new ChatConversaService($this->db);
    }

    // =========================================================================
    // GRAVAÇÃO
    // =========================================================================

    /**
     * Grava uma mensagem. Devolve o id, ou null se era duplicata (wamid repetido).
     *
     * @param array $m conversa_id, contato_id, direcao, tipo, texto, wamid,
     *                 midia_*, payload, status, origem, origem_id,
     *                 autor_usuario_id, resposta_a
     */
    public function gravar(array $m): ?int
    {
        $direcao = ($m['direcao'] ?? 'saida') === 'entrada' ? 'entrada' : 'saida';
        $wamid   = trim((string)($m['wamid'] ?? '')) ?: null;

        $sql = "INSERT " . ($wamid ? 'IGNORE ' : '') . "INTO chat_mensagens
                  (conversa_id, contato_id, direcao, tipo, texto,
                   midia_id, midia_url, midia_mime, midia_nome, midia_tamanho,
                   payload_json, wamid, resposta_a, status, erro_codigo, erro_detalhe,
                   origem, origem_id, autor_usuario_id, criado_em)
                VALUES
                  (:cv, :ct, :dir, :tipo, :texto,
                   :mid, :murl, :mmime, :mnome, :mtam,
                   :pl, :wamid, :resp, :status, :ecod, :edet,
                   :origem, :oid, :autor, :criado)";

        $st = $this->db->prepare($sql);
        $st->execute([
            ':cv'     => (int)$m['conversa_id'],
            ':ct'     => (int)$m['contato_id'],
            ':dir'    => $direcao,
            ':tipo'   => mb_substr((string)($m['tipo'] ?? 'text'), 0, 20),
            ':texto'  => isset($m['texto']) && $m['texto'] !== '' ? (string)$m['texto'] : null,
            ':mid'    => $m['midia_id']      ?? null,
            ':murl'   => $m['midia_url']     ?? null,
            ':mmime'  => $m['midia_mime']    ?? null,
            ':mnome'  => isset($m['midia_nome']) ? mb_substr((string)$m['midia_nome'], 0, 200) : null,
            ':mtam'   => isset($m['midia_tamanho']) ? (int)$m['midia_tamanho'] : null,
            ':pl'     => isset($m['payload']) ? json_encode($m['payload'], JSON_UNESCAPED_UNICODE) : null,
            ':wamid'  => $wamid,
            ':resp'   => $m['resposta_a'] ?? null,
            ':status' => $this->statusValido((string)($m['status'] ?? ($direcao === 'entrada' ? 'recebido' : 'enviado'))),
            ':ecod'   => isset($m['erro_codigo']) ? (int)$m['erro_codigo'] : null,
            ':edet'   => isset($m['erro_detalhe']) ? mb_substr((string)$m['erro_detalhe'], 0, 400) : null,
            ':origem' => mb_substr((string)($m['origem'] ?? 'inbox'), 0, 30),
            ':oid'    => !empty($m['origem_id']) ? (int)$m['origem_id'] : null,
            ':autor'  => !empty($m['autor_usuario_id']) ? (int)$m['autor_usuario_id'] : null,
            // permite reidratar histórico com o timestamp real da Meta
            ':criado' => $m['criado_em'] ?? date('Y-m-d H:i:s'),
        ]);

        // INSERT IGNORE que não inseriu = wamid repetido = webhook duplicado
        if ($st->rowCount() === 0) return null;

        $id = (int)$this->db->lastInsertId();

        $this->conversas->tocar(
            (int)$m['conversa_id'],
            $direcao,
            $this->preview($m),
            $direcao === 'entrada'
        );

        return $id;
    }

    /**
     * Atualiza o status a partir do webhook de statuses (sent/delivered/read/failed).
     * Nunca faz downgrade: um 'delivered' atrasado não pode apagar um 'read'.
     */
    public function atualizarStatusPorWamid(string $wamid, string $status, ?array $erro = null): bool
    {
        $mapa = [
            'sent'      => 'enviado',
            'delivered' => 'entregue',
            'read'      => 'lido',
            'failed'    => 'falhou',
            'deleted'   => 'falhou',
        ];
        $novo = $mapa[strtolower($status)] ?? null;
        if (!$novo) return false;

        // Ordem de progressão — só avança
        $ordem = ['pendente' => 0, 'enviado' => 1, 'entregue' => 2, 'lido' => 3];

        $st = $this->db->prepare("SELECT id, status FROM chat_mensagens WHERE wamid = :w LIMIT 1");
        $st->execute([':w' => $wamid]);
        $msg = $st->fetch(PDO::FETCH_ASSOC);
        if (!$msg) return false;

        if ($novo !== 'falhou') {
            $atualPeso = $ordem[$msg['status']] ?? -1;
            $novoPeso  = $ordem[$novo] ?? -1;
            if ($novoPeso <= $atualPeso) return false;
        }

        $sets   = ['status = :s'];
        $params = [':s' => $novo, ':id' => (int)$msg['id']];

        if ($novo === 'entregue') $sets[] = 'entregue_em = COALESCE(entregue_em, NOW())';
        if ($novo === 'lido')     $sets[] = 'lido_em = COALESCE(lido_em, NOW()), entregue_em = COALESCE(entregue_em, NOW())';
        if ($novo === 'falhou' && $erro) {
            $sets[] = 'erro_codigo = :ec';
            $sets[] = 'erro_detalhe = :ed';
            $params[':ec'] = isset($erro['code']) ? (int)$erro['code'] : null;
            $params[':ed'] = mb_substr((string)($erro['title'] ?? $erro['message'] ?? ''), 0, 400);
        }

        $this->db->prepare("UPDATE chat_mensagens SET " . implode(', ', $sets) . " WHERE id = :id")
                 ->execute($params);

        // Espelha no destinatário da campanha, quando a mensagem veio de uma
        $this->propagarParaCampanha($wamid, $novo, $erro);

        return true;
    }

    private function propagarParaCampanha(string $wamid, string $status, ?array $erro): void
    {
        try {
            $mapa = ['enviado' => 'enviado', 'entregue' => 'entregue', 'lido' => 'lido', 'falhou' => 'falhou'];
            if (!isset($mapa[$status])) return;

            $st = $this->db->prepare(
                "UPDATE chat_campanha_destinatarios
                 SET status = :s, erro_detalhe = COALESCE(:e, erro_detalhe)
                 WHERE wamid = :w"
            );
            $st->execute([
                ':s' => $mapa[$status],
                ':e' => $erro ? mb_substr((string)($erro['title'] ?? ''), 0, 400) : null,
                ':w' => $wamid,
            ]);
            if ($st->rowCount() === 0) return;

            // Recalcula os totais da campanha afetada
            $stC = $this->db->prepare(
                "SELECT campanha_id FROM chat_campanha_destinatarios WHERE wamid = :w LIMIT 1"
            );
            $stC->execute([':w' => $wamid]);
            $campId = (int)$stC->fetchColumn();
            if ($campId) $this->recalcularCampanha($campId);
        } catch (Throwable $e) {}
    }

    public function recalcularCampanha(int $campanhaId): void
    {
        try {
            $this->db->prepare(
                "UPDATE chat_campanhas c SET
                    total_enviados  = (SELECT COUNT(*) FROM chat_campanha_destinatarios d
                                       WHERE d.campanha_id = c.id AND d.status IN ('enviado','entregue','lido')),
                    total_entregues = (SELECT COUNT(*) FROM chat_campanha_destinatarios d
                                       WHERE d.campanha_id = c.id AND d.status IN ('entregue','lido')),
                    total_lidos     = (SELECT COUNT(*) FROM chat_campanha_destinatarios d
                                       WHERE d.campanha_id = c.id AND d.status = 'lido'),
                    total_falhas    = (SELECT COUNT(*) FROM chat_campanha_destinatarios d
                                       WHERE d.campanha_id = c.id AND d.status = 'falhou'),
                    total_pulados   = (SELECT COUNT(*) FROM chat_campanha_destinatarios d
                                       WHERE d.campanha_id = c.id AND d.status = 'pulado')
                 WHERE c.id = :id"
            )->execute([':id' => $campanhaId]);
        } catch (Throwable $e) {}
    }

    // =========================================================================
    // LEITURA
    // =========================================================================

    /**
     * Thread da conversa, ordem cronológica.
     * $antesDe permite paginar para trás (scroll infinito do inbox).
     */
    public function thread(int $conversaId, int $limite = 50, int $antesDe = 0): array
    {
        $limite = max(1, min(200, $limite));
        $sql = "SELECT m.*, u.nome AS autor_nome
                FROM chat_mensagens m
                LEFT JOIN usuarios u ON u.id = m.autor_usuario_id
                WHERE m.conversa_id = :cv";
        $params = [':cv' => $conversaId];

        if ($antesDe > 0) { $sql .= " AND m.id < :antes"; $params[':antes'] = $antesDe; }

        $sql .= " ORDER BY m.id DESC LIMIT $limite";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

        // Veio DESC para pegar as N mais recentes; a UI quer ASC
        $linhas = array_reverse($linhas);
        return array_map([$this, 'hidratar'], $linhas);
    }

    /** Mensagens novas desde um id — o polling do inbox usa isto. */
    public function novasDesde(int $conversaId, int $desdeId): array
    {
        $st = $this->db->prepare(
            "SELECT m.*, u.nome AS autor_nome
             FROM chat_mensagens m
             LEFT JOIN usuarios u ON u.id = m.autor_usuario_id
             WHERE m.conversa_id = :cv AND m.id > :d
             ORDER BY m.id ASC LIMIT 100"
        );
        $st->execute([':cv' => $conversaId, ':d' => $desdeId]);
        return array_map([$this, 'hidratar'], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Mensagens cujo status mudou — o polling atualiza os tiques sem recarregar. */
    public function statusAtualizados(int $conversaId, string $desde): array
    {
        $st = $this->db->prepare(
            "SELECT id, status, erro_detalhe FROM chat_mensagens
             WHERE conversa_id = :cv AND atualizado_em >= :d AND direcao = 'saida'
             ORDER BY id ASC LIMIT 200"
        );
        $st->execute([':cv' => $conversaId, ':d' => $desde]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * O JOIN com usuarios não é enfeite: é este método que devolve a mensagem
     * recém-enviada para o inbox desenhar a bolha. Sem ele, a mensagem nova
     * saía sem autor e só ganhava nome depois de recarregar a página, quando o
     * thread() — que tem o JOIN — assumia.
     */
    public function obter(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT m.*, u.nome AS autor_nome
             FROM chat_mensagens m
             LEFT JOIN usuarios u ON u.id = m.autor_usuario_id
             WHERE m.id = :id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        return $m ? $this->hidratar($m) : null;
    }

    /** Última mensagem recebida do contato — usada para responder com context. */
    public function ultimaEntrada(int $contatoId): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM chat_mensagens
             WHERE contato_id = :c AND direcao = 'entrada'
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([':c' => $contatoId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        return $m ? $this->hidratar($m) : null;
    }

    private function hidratar(array $m): array
    {
        $m['payload']   = json_decode($m['payload_json'] ?? 'null', true);
        $m['hora']      = date('H:i', strtotime((string)$m['criado_em']));
        $m['dia']       = date('Y-m-d', strtotime((string)$m['criado_em']));
        $m['e_midia']   = in_array($m['tipo'], ['image', 'video', 'audio', 'document', 'sticker'], true);
        unset($m['payload_json']);
        return $m;
    }

    // =========================================================================
    // AUXILIARES
    // =========================================================================

    /** Texto curto que representa a mensagem na lista do inbox. */
    private function preview(array $m): string
    {
        $texto = trim((string)($m['texto'] ?? ''));
        if ($texto !== '') return $texto;

        return match ((string)($m['tipo'] ?? 'text')) {
            'image'       => '📷 Imagem',
            'video'       => '🎥 Vídeo',
            'audio'       => '🎤 Áudio',
            'document'    => '📄 ' . ($m['midia_nome'] ?? 'Documento'),
            'sticker'     => '🌟 Figurinha',
            'location'    => '📍 Localização',
            'contacts'    => '👤 Contato',
            'template'    => '📋 Template',
            'interactive' => '🔘 Mensagem interativa',
            'reaction'    => '❤️ Reação',
            default       => 'Mensagem',
        };
    }

    private function statusValido(string $s): string
    {
        $v = ['pendente', 'enviado', 'entregue', 'lido', 'falhou', 'recebido'];
        return in_array($s, $v, true) ? $s : 'enviado';
    }

    // =========================================================================
    // MÉTRICAS
    // =========================================================================

    /** Série diária de entrada/saída para o gráfico do dashboard. */
    public function serieDiaria(int $dias = 30): array
    {
        $dias = max(1, min(180, $dias));
        $st = $this->db->prepare(
            "SELECT DATE(criado_em) AS dia,
                    SUM(direcao = 'entrada') AS entrada,
                    SUM(direcao = 'saida')   AS saida,
                    SUM(status = 'falhou')   AS falhas
             FROM chat_mensagens
             WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL $dias DAY)
             GROUP BY DATE(criado_em)
             ORDER BY dia"
        );
        $st->execute();
        $porDia = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porDia[$r['dia']] = $r;

        // Preenche buracos — um gráfico com dias faltando mente sobre a tendência
        $out = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $out[] = [
                'dia'     => $d,
                'rotulo'  => date('d/m', strtotime($d)),
                'entrada' => (int)($porDia[$d]['entrada'] ?? 0),
                'saida'   => (int)($porDia[$d]['saida']   ?? 0),
                'falhas'  => (int)($porDia[$d]['falhas']  ?? 0),
            ];
        }
        return $out;
    }
}
