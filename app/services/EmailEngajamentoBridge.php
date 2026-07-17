<?php
/**
 * app/services/EmailEngajamentoBridge.php
 *
 * Ponte entre o email marketing e o event stream da automação.
 *
 * A tabela email_eventos registra aberturas/cliques de CAMPANHAS, mas é keyed
 * por contato de marketing (contato_id), não por cliente. Esta ponte lê os
 * eventos novos (por cursor), mapeia contato → cliente e espelha no stream
 * `eventos` como `email_aberto` / `email_clicado` (keyed por cliente_id).
 *
 * Com isso, os fluxos passam a reagir a engajamento de email:
 *   - trigger_evento em "email_aberto"        (dispara fluxo quando abre)
 *   - esperar_evento em "email_aberto"         (ramifica: abriu vs não abriu)
 *   - cond_evento_ocorreu "email_clicado"      (condição de engajamento)
 *
 * Rodado pelo fluxo-worker (fase A3), a cada minuto.
 *
 * ESCOPO: cobre aberturas de CAMPANHAS (email marketing). Emails enviados pelo
 * próprio fluxo (nó acao_email) vão direto ao provider e não passam por
 * email_eventos — rastrear a abertura DELES exige pixel de tracking no
 * acao_email (trabalho futuro).
 */
class EmailEngajamentoBridge
{
    /** Sentinela do token (eventos server-side não têm token de navegador). */
    private const TOKEN_SERVER = 'emailbridge00000000000000000000'; // 32 chars

    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Espelha os eventos de email novos no stream.
     * @return array{lidos:int, stream:int, sem_cliente:int}
     */
    public function sincronizar(int $lote = 1000): array
    {
        $stats = ['lidos' => 0, 'stream' => 0, 'sem_cliente' => 0];

        try {
            $cursor = (int)$this->getCfg('email_bridge_cursor_id', '0');

            // Pagina sobre TODOS os ids do lote (não só aberto/clicado): assim o
            // cursor avança mesmo sobre 'enviado'/'entregue', sem re-escanear a
            // cauda de envios em períodos de muitas entregas e poucas aberturas.
            $st = $this->db->prepare(
                "SELECT id, tipo, campanha_id, contato_id, destinatario_id, link_id, criado_em
                 FROM email_eventos
                 WHERE id > :c
                 ORDER BY id ASC
                 LIMIT " . max(100, min(5000, $lote))
            );
            $st->bindValue(':c', $cursor, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $ins = $this->db->prepare(
                "INSERT INTO eventos
                 (visitante_token, cliente_id, tipo, entidade_tipo, entidade_id, contexto_json, criado_em)
                 VALUES (:tok, :cid, :tipo, :etipo, :eid, :ctx, :cr)"
            );

            $ultimo = $cursor;
            foreach ($rows as $r) {
                $ultimo = (int)$r['id']; // cursor avança sobre todo o lote

                // Só aberturas e cliques viram evento de stream
                if (!in_array($r['tipo'], ['aberto', 'clicado'], true)) continue;
                $stats['lidos']++;

                $clienteId = $this->resolverCliente($r);
                if (!$clienteId) { $stats['sem_cliente']++; continue; }

                $tipoStream = ($r['tipo'] === 'clicado') ? 'email_clicado' : 'email_aberto';
                $campanhaId = $r['campanha_id'] ? (int)$r['campanha_id'] : null;

                $ctx = ['_origem' => 'email_bridge'];
                if ($campanhaId)         $ctx['campanha_id'] = $campanhaId;
                if (!empty($r['link_id'])) $ctx['link_id']    = (int)$r['link_id'];

                $ins->execute([
                    ':tok'   => self::TOKEN_SERVER,
                    ':cid'   => $clienteId,
                    ':tipo'  => $tipoStream,
                    ':etipo' => $campanhaId ? 'campanha' : null,
                    ':eid'   => $campanhaId,
                    ':ctx'   => json_encode($ctx, JSON_UNESCAPED_UNICODE),
                    ':cr'    => $r['criado_em'],
                ]);
                $stats['stream']++;
            }

            if ($ultimo > $cursor) {
                $this->setCfg('email_bridge_cursor_id', (string)$ultimo);
            }
        } catch (Throwable $e) {
            if (class_exists('LogService')) {
                try { LogService::error('EmailEngajamentoBridge: ' . $e->getMessage()); } catch (Throwable $x) {}
            }
        }

        return $stats;
    }

    /**
     * ═══ PONTO DE AJUSTE ═══
     * Mapeia um evento de email para o cliente.
     * Padrão: contato_id → email_contatos.email → usuarios.email → clientes.id
     * Se sua email_contatos tiver uma coluna cliente_id direta, troque por ela
     * (mais barato e mais preciso).
     */
    private function resolverCliente(array $r): ?int
    {
        if (empty($r['contato_id'])) return null;

        try {
            $st = $this->db->prepare(
                "SELECT c.id
                 FROM email_contatos ec
                 JOIN usuarios u  ON u.email = ec.email AND u.deleted_at IS NULL
                 JOIN clientes c  ON c.usuario_id = u.id
                 WHERE ec.id = :id
                 LIMIT 1"
            );
            $st->execute([':id' => (int)$r['contato_id']]);
            $cid = $st->fetchColumn();
            return $cid ? (int)$cid : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // -------------------------------------------------------------------------

    private function getCfg(string $chave, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT valor FROM fluxo_motor_config WHERE chave=:k");
            $st->execute([':k' => $chave]);
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null) ? (string)$v : $default;
        } catch (Throwable $e) { return $default; }
    }

    private function setCfg(string $chave, string $valor): void
    {
        $this->db->prepare(
            "INSERT INTO fluxo_motor_config (chave, valor) VALUES (:k,:v)
             ON DUPLICATE KEY UPDATE valor = :v2"
        )->execute([':k' => $chave, ':v' => $valor, ':v2' => $valor]);
    }
}
