<?php
declare(strict_types=1);

/**
 * IAAgenteConversa — persistência das conversas dos agentes de BI.
 *
 * Duas tabelas: `ia_agente_conversas` (o fio) e `ia_agente_mensagens`
 * (cada turno). Cada chamada ao modelo continua sendo uma linha em
 * `ia_geracoes` — aqui só se guarda o que foi dito e o que o modelo viu.
 */
class IAAgenteConversa
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /* ------------------------------------------------------------------ */
    /* Conversas                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * $d: uuid, agente, usuario_id(?int), modo, pagina(?), periodo(?),
     *     contexto(array|null), titulo(?)
     */
    public function criar(array $d): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ia_agente_conversas
                (uuid, agente, usuario_id, modo, pagina, periodo, contexto, titulo)
             VALUES (:uuid, :agente, :usuario_id, :modo, :pagina, :periodo, :contexto, :titulo)'
        );
        $stmt->execute([
            ':uuid'       => $d['uuid'],
            ':agente'     => $d['agente'],
            ':usuario_id' => $d['usuario_id'] ?? null,
            ':modo'       => $d['modo'] ?? 'tempo_real',
            ':pagina'     => $d['pagina'] ?? null,
            ':periodo'    => $d['periodo'] ?? null,
            ':contexto'   => isset($d['contexto']) ? json_encode($d['contexto'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':titulo'     => isset($d['titulo']) ? mb_substr((string) $d['titulo'], 0, 160) : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function buscarPorUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ia_agente_conversas WHERE uuid = :u LIMIT 1');
        $stmt->execute([':u' => $uuid]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        return $c ?: null;
    }

    public function buscar(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ia_agente_conversas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        return $c ?: null;
    }

    /** A conversa mais recente de um agente num modo — o "resumo de hoje". */
    public function ultimaPorAgente(string $agente, string $modo = 'agendado'): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ia_agente_conversas
              WHERE agente = :a AND modo = :m
              ORDER BY criado_em DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':a' => $agente, ':m' => $modo]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        return $c ?: null;
    }

    /** Já houve rodada agendada deste agente hoje? (dedup do cron) */
    public function existeAgendadoHoje(string $agente): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM ia_agente_conversas
              WHERE agente = :a AND modo = 'agendado' AND DATE(criado_em) = CURDATE() LIMIT 1"
        );
        $stmt->execute([':a' => $agente]);
        return (bool) $stmt->fetchColumn();
    }

    /** O mesmo gatilho (hash do alerta) já disparou hoje? (dedup do modo evento) */
    public function existeEventoHoje(string $gatilho): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM ia_agente_conversas
              WHERE modo = 'evento' AND DATE(criado_em) = CURDATE()
                AND JSON_UNQUOTE(JSON_EXTRACT(contexto, '$.gatilho')) = :g LIMIT 1"
        );
        $stmt->execute([':g' => $gatilho]);
        return (bool) $stmt->fetchColumn();
    }

    /** Conversas de uma pessoa, mais recentes primeiro. */
    public function listarDoUsuario(int $usuarioId, int $limite = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, uuid, agente, modo, pagina, periodo, titulo, criado_em, atualizado_em
               FROM ia_agente_conversas
              WHERE usuario_id = :u
              ORDER BY atualizado_em DESC LIMIT ' . (int) $limite
        );
        $stmt->execute([':u' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function tocar(int $id): void
    {
        $this->db->prepare('UPDATE ia_agente_conversas SET atualizado_em = NOW() WHERE id = :id')
                 ->execute([':id' => $id]);
    }

    /* ------------------------------------------------------------------ */
    /* Mensagens                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * $d: conversa_id, papel (user|assistant|tool), conteudo,
     *     ferramenta(?), parametros(array|null), geracao_id(?),
     *     tokens_in(?), tokens_out(?), tempo_ms(?)
     */
    public function adicionarMensagem(array $d): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ia_agente_mensagens
                (conversa_id, papel, ferramenta, parametros, conteudo, geracao_id, tokens_in, tokens_out, tempo_ms)
             VALUES (:conversa_id, :papel, :ferramenta, :parametros, :conteudo, :geracao_id, :tokens_in, :tokens_out, :tempo_ms)'
        );
        $stmt->execute([
            ':conversa_id' => (int) $d['conversa_id'],
            ':papel'       => $d['papel'],
            ':ferramenta'  => $d['ferramenta'] ?? null,
            ':parametros'  => isset($d['parametros']) ? json_encode($d['parametros'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':conteudo'    => (string) $d['conteudo'],
            ':geracao_id'  => $d['geracao_id'] ?? null,
            ':tokens_in'   => $d['tokens_in'] ?? null,
            ':tokens_out'  => $d['tokens_out'] ?? null,
            ':tempo_ms'    => $d['tempo_ms'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Todas as mensagens de uma conversa, na ordem. */
    public function mensagens(int $conversaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, papel, ferramenta, parametros, conteudo, geracao_id, tokens_in, tokens_out, tempo_ms, criado_em
               FROM ia_agente_mensagens
              WHERE conversa_id = :c
              ORDER BY id ASC'
        );
        $stmt->execute([':c' => $conversaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** A última resposta do agente numa conversa (para o resumo executivo). */
    public function ultimaResposta(int $conversaId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, conteudo, geracao_id, tokens_in, tokens_out, tempo_ms, criado_em
               FROM ia_agente_mensagens
              WHERE conversa_id = :c AND papel = 'assistant'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':c' => $conversaId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        return $m ?: null;
    }
}
