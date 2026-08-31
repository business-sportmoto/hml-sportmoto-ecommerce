<?php
/**
 * app/services/ChatContatoService.php
 *
 * O contato é o "subscriber" do módulo. Este service é dono de:
 *   · upsert por wa_id (o webhook chama a cada mensagem recebida)
 *   · vínculo automático com `clientes` pelo telefone
 *   · janela de 24h (a regra que decide texto livre vs. template)
 *   · tags e campos personalizados
 *   · opt-in / opt-out
 *
 * REGRA DA JANELA: a Meta abre 24h de atendimento a partir da ÚLTIMA mensagem
 * recebida do cliente. Dentro dela vale texto livre; fora, só template HSM.
 * Guardamos `janela_expira_em` em vez de recalcular por consulta às mensagens
 * porque o envio precisa dessa decisão em O(1), no caminho quente.
 */
class ChatContatoService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // LEITURA
    // =========================================================================

    public function obter(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        return $c ? $this->hidratar($c) : null;
    }

    public function obterPorWaId(string $waId, string $canal = 'whatsapp'): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM chat_contatos WHERE canal = :ca AND wa_id = :w LIMIT 1"
        );
        // Normalizar só faz sentido para telefone. Um IGSID passado pelo
        // normalizador brasileiro poderia ganhar um "55" na frente e nunca
        // mais casar com o registro gravado.
        $st->execute([
            ':ca' => $canal,
            ':w'  => $canal === 'whatsapp' ? ChatMetaClient::normalizarNumero($waId) : trim($waId),
        ]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        return $c ? $this->hidratar($c) : null;
    }

    /** Acrescenta campos derivados que a UI e os nós de fluxo usam. */
    private function hidratar(array $c): array
    {
        $ehInstagram = ($c['canal'] ?? 'whatsapp') === 'instagram';

        $c['campos']    = json_decode($c['campos_json'] ?? '{}', true) ?: [];
        $c['na_janela'] = $this->naJanela($c);

        // No Instagram o identificador é um IGSID, não um telefone: formatá-lo
        // como número produziria algo como "+17841400008460056".
        $fallback = $ehInstagram
            ? ('@' . ($c['ig_username'] ?? $c['wa_id']))
            : $this->formatarTelefone($c['wa_id']);

        $c['nome_exibicao'] = $c['nome'] ?: ($c['nome_perfil'] ?: $fallback);
        $c['identificador'] = $ehInstagram
            ? ('@' . ($c['ig_username'] ?? '—'))
            : ($c['telefone_exibicao'] ?: $c['wa_id']);
        $c['canal_rotulo']  = $ehInstagram ? 'Instagram' : 'WhatsApp';
        $c['tags']          = $this->tagsDo((int)$c['id']);
        return $c;
    }

    /** A janela de 24h está aberta para este contato? */
    public function naJanela(array $contato): bool
    {
        $exp = $contato['janela_expira_em'] ?? null;
        return $exp !== null && strtotime((string)$exp) > time();
    }

    public function podeReceber(array $contato): array
    {
        if ((int)($contato['bloqueado'] ?? 0) === 1) {
            return ['ok' => false, 'motivo' => 'contato bloqueado'];
        }
        if ((int)($contato['optin'] ?? 1) !== 1) {
            return ['ok' => false, 'motivo' => 'contato fez opt-out'];
        }
        return ['ok' => true, 'motivo' => ''];
    }

    // =========================================================================
    // UPSERT
    // =========================================================================

    /**
     * Cria ou atualiza o contato a partir de uma mensagem RECEBIDA.
     * Renova a janela de 24h — é o único ponto do sistema que pode fazer isso.
     *
     * @param array $opts nome_perfil, origem, origem_ref
     * @return array contato hidratado
     */
    public function registrarEntrada(string $waId, array $opts = [], string $canal = 'whatsapp'): array
    {
        $waId = ChatMetaClient::normalizarNumero($waId);
        if ($waId === '') throw new InvalidArgumentException('ChatContato: wa_id vazio');

        $horas  = max(1, ChatConfig::int('janela_horas', 24));
        $janela = date('Y-m-d H:i:s', time() + $horas * 3600);
        $agora  = date('Y-m-d H:i:s');

        // INSERT ... ON DUPLICATE KEY resolve a corrida entre webhooks
        // concorrentes do mesmo contato sem SELECT-então-INSERT.
        $st = $this->db->prepare(
            "INSERT INTO chat_contatos
                (canal, wa_id, telefone_exibicao, nome_perfil, origem, origem_ref,
                 janela_expira_em, ultima_entrada_em, total_entrada)
             VALUES (:ca, :w, :tel, :np, :o, :oref, :jan, :ag, 1)
             ON DUPLICATE KEY UPDATE
                nome_perfil       = COALESCE(NULLIF(VALUES(nome_perfil), ''), nome_perfil),
                janela_expira_em  = VALUES(janela_expira_em),
                ultima_entrada_em = VALUES(ultima_entrada_em),
                total_entrada     = total_entrada + 1"
        );
        $st->execute([
            ':ca'   => $canal,
            ':w'    => $waId,
            ':tel'  => $this->formatarTelefone($waId),
            ':np'   => mb_substr(trim((string)($opts['nome_perfil'] ?? '')), 0, 120),
            ':o'    => $opts['origem']     ?? 'whatsapp',
            ':oref' => isset($opts['origem_ref']) ? mb_substr((string)$opts['origem_ref'], 0, 120) : null,
            ':jan'  => $janela,
            ':ag'   => $agora,
        ]);

        $contato = $this->obterPorWaId($waId, $canal);
        if (!$contato) throw new RuntimeException('ChatContato: falha ao registrar entrada');

        // Vínculo com a base de clientes — só tenta uma vez, quando ainda não há
        if (empty($contato['cliente_id'])) {
            $clienteId = $this->descobrirCliente($waId);
            if ($clienteId) {
                $this->vincularCliente((int)$contato['id'], $clienteId);
                $contato = $this->obter((int)$contato['id']) ?? $contato;
            }
        }

        return $contato;
    }

    /**
     * Cria (ou recupera) contato sem renovar a janela — usado por importação,
     * campanha e criação manual pelo admin. NÃO abre janela de 24h porque
     * nenhuma mensagem foi recebida.
     */
    public function garantir(string $waId, array $dados = [], string $canal = 'whatsapp'): array
    {
        $waId = ChatMetaClient::normalizarNumero($waId);
        if ($waId === '') throw new InvalidArgumentException('ChatContato: wa_id vazio');

        $existente = $this->obterPorWaId($waId, $canal);
        if ($existente) return $existente;

        $st = $this->db->prepare(
            "INSERT INTO chat_contatos
                (canal, wa_id, telefone_exibicao, nome, email, cliente_id, origem, origem_ref, campos_json)
             VALUES (:ca, :w, :tel, :n, :e, :cli, :o, :oref, :cj)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $st->execute([
            ':ca'   => $canal,
            ':w'    => $waId,
            ':tel'  => $this->formatarTelefone($waId),
            ':n'    => isset($dados['nome']) ? mb_substr((string)$dados['nome'], 0, 120) : null,
            ':e'    => isset($dados['email']) ? mb_substr((string)$dados['email'], 0, 180) : null,
            ':cli'  => !empty($dados['cliente_id']) ? (int)$dados['cliente_id'] : $this->descobrirCliente($waId),
            ':o'    => $dados['origem'] ?? 'manual',
            ':oref' => isset($dados['origem_ref']) ? mb_substr((string)$dados['origem_ref'], 0, 120) : null,
            ':cj'   => json_encode($dados['campos'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);

        return $this->obterPorWaId($waId, $canal) ?? [];
    }

    /** Marca saída (mensagem enviada pela loja). Não mexe na janela. */
    public function registrarSaida(int $contatoId): void
    {
        $this->db->prepare(
            "UPDATE chat_contatos
             SET ultima_saida_em = NOW(), total_saida = total_saida + 1
             WHERE id = :id"
        )->execute([':id' => $contatoId]);
    }

    // =========================================================================
    // EDIÇÃO
    // =========================================================================

    /** Atualiza campos editáveis pelo admin. Whitelist explícita. */
    public function atualizar(int $id, array $dados): bool
    {
        $permitidos = ['nome', 'email', 'idioma', 'optin', 'bloqueado', 'origem', 'origem_ref'];
        $sets = []; $params = [':id' => $id];

        foreach ($permitidos as $c) {
            if (!array_key_exists($c, $dados)) continue;
            $sets[] = "$c = :$c";
            $params[":$c"] = in_array($c, ['optin', 'bloqueado'], true)
                ? (int)!empty($dados[$c])
                : ($dados[$c] === '' ? null : $dados[$c]);
        }
        if (!$sets) return false;

        // Opt-out precisa carimbar a data — senão o relatório de LGPD mente
        if (array_key_exists('optin', $dados) && (int)!empty($dados['optin']) === 0) {
            $sets[] = 'optout_em = NOW()';
        }

        $this->db->prepare("UPDATE chat_contatos SET " . implode(', ', $sets) . " WHERE id = :id")
                 ->execute($params);
        return true;
    }

    public function optOut(int $id, string $motivo = 'pedido pelo contato'): void
    {
        $this->db->prepare(
            "UPDATE chat_contatos
             SET optin = 0, optout_em = NOW(), optout_motivo = :m
             WHERE id = :id"
        )->execute([':m' => mb_substr($motivo, 0, 120), ':id' => $id]);

        // Encerra sessões de fluxo em andamento — continuar seria justamente o
        // que a pessoa pediu para parar.
        $this->db->prepare(
            "UPDATE chat_sessoes SET status = 'saiu', erro_detalhe = 'opt-out'
             WHERE contato_id = :id AND status IN ('ativo','dormindo','aguardando_resposta')"
        )->execute([':id' => $id]);

        $this->aplicarTagPorSlug($id, 'opt-out');

        if (class_exists('LogService')) {
            try { LogService::audit('chat_optout', ['contato_id' => $id, 'motivo' => $motivo]); } catch (Throwable $e) {}
        }
    }

    public function optIn(int $id): void
    {
        $this->db->prepare(
            "UPDATE chat_contatos SET optin = 1, optout_em = NULL, optout_motivo = NULL WHERE id = :id"
        )->execute([':id' => $id]);
        $this->removerTagPorSlug($id, 'opt-out');
    }

    // =========================================================================
    // CAMPOS PERSONALIZADOS
    // =========================================================================

    public function setCampo(int $id, string $chave, $valor): void
    {
        $chave = preg_replace('/[^a-z0-9_]/i', '_', trim($chave));
        if ($chave === '') return;

        $st = $this->db->prepare("SELECT campos_json FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $campos = json_decode((string)$st->fetchColumn() ?: '{}', true) ?: [];

        if ($valor === null || $valor === '') unset($campos[$chave]);
        else $campos[$chave] = is_scalar($valor) ? $valor : json_encode($valor, JSON_UNESCAPED_UNICODE);

        $this->db->prepare("UPDATE chat_contatos SET campos_json = :c WHERE id = :id")
                 ->execute([':c' => json_encode($campos, JSON_UNESCAPED_UNICODE), ':id' => $id]);
    }

    public function getCampo(int $id, string $chave, $default = null)
    {
        $st = $this->db->prepare("SELECT campos_json FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $campos = json_decode((string)$st->fetchColumn() ?: '{}', true) ?: [];
        return $campos[$chave] ?? $default;
    }

    /** Todas as chaves de campo já usadas — alimenta os selects do editor. */
    public function chavesDeCampoConhecidas(int $limite = 200): array
    {
        try {
            $st = $this->db->query(
                "SELECT campos_json FROM chat_contatos
                 WHERE campos_json IS NOT NULL AND campos_json <> '{}' AND campos_json <> ''
                 ORDER BY atualizado_em DESC LIMIT " . (int)$limite
            );
            $chaves = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) {
                foreach (array_keys(json_decode((string)$j, true) ?: []) as $k) $chaves[$k] = true;
            }
            ksort($chaves);
            return array_keys($chaves);
        } catch (Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // TAGS
    // =========================================================================

    public function tagsDo(int $contatoId): array
    {
        $st = $this->db->prepare(
            "SELECT t.id, t.nome, t.slug, t.cor
             FROM chat_contato_tags ct
             JOIN chat_tags t ON t.id = ct.tag_id
             WHERE ct.contato_id = :c
             ORDER BY t.nome"
        );
        $st->execute([':c' => $contatoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function aplicarTag(int $contatoId, int $tagId, ?int $usuarioId = null): bool
    {
        try {
            $st = $this->db->prepare(
                "INSERT IGNORE INTO chat_contato_tags (contato_id, tag_id, usuario_id)
                 VALUES (:c, :t, :u)"
            );
            $st->execute([':c' => $contatoId, ':t' => $tagId, ':u' => $usuarioId]);
            if ($st->rowCount() > 0) $this->recontarTag($tagId);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function removerTag(int $contatoId, int $tagId): bool
    {
        $st = $this->db->prepare(
            "DELETE FROM chat_contato_tags WHERE contato_id = :c AND tag_id = :t"
        );
        $st->execute([':c' => $contatoId, ':t' => $tagId]);
        if ($st->rowCount() > 0) $this->recontarTag($tagId);
        return true;
    }

    /** Aplica por slug, criando a tag se ainda não existir (usado pelos fluxos). */
    public function aplicarTagPorSlug(int $contatoId, string $slug, ?string $nome = null): bool
    {
        $tagId = $this->tagIdPorSlug($slug, $nome);
        return $tagId ? $this->aplicarTag($contatoId, $tagId) : false;
    }

    public function removerTagPorSlug(int $contatoId, string $slug): bool
    {
        $st = $this->db->prepare("SELECT id FROM chat_tags WHERE slug = :s LIMIT 1");
        $st->execute([':s' => $this->slug($slug)]);
        $id = (int)$st->fetchColumn();
        return $id ? $this->removerTag($contatoId, $id) : false;
    }

    public function temTagSlug(int $contatoId, string $slug): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM chat_contato_tags ct
             JOIN chat_tags t ON t.id = ct.tag_id
             WHERE ct.contato_id = :c AND t.slug = :s LIMIT 1"
        );
        $st->execute([':c' => $contatoId, ':s' => $this->slug($slug)]);
        return (bool)$st->fetchColumn();
    }

    public function tagIdPorSlug(string $slug, ?string $nome = null): ?int
    {
        $s = $this->slug($slug);
        if ($s === '') return null;

        $st = $this->db->prepare("SELECT id FROM chat_tags WHERE slug = :s LIMIT 1");
        $st->execute([':s' => $s]);
        $id = (int)$st->fetchColumn();
        if ($id) return $id;

        try {
            $ins = $this->db->prepare("INSERT INTO chat_tags (nome, slug) VALUES (:n, :s)");
            $ins->execute([':n' => mb_substr($nome ?: $slug, 0, 60), ':s' => $s]);
            return (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            // corrida: outro processo criou entre o SELECT e o INSERT
            $st->execute([':s' => $s]);
            return (int)$st->fetchColumn() ?: null;
        }
    }

    public function listarTags(): array
    {
        return $this->db->query(
            "SELECT t.*, (SELECT COUNT(*) FROM chat_contato_tags ct WHERE ct.tag_id = t.id) AS total
             FROM chat_tags t ORDER BY t.nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function criarTag(string $nome, string $cor = '#2563eb', ?string $descricao = null): ?int
    {
        $nome = trim($nome);
        if ($nome === '') return null;
        $slug = $this->slug($nome);
        try {
            $st = $this->db->prepare(
                "INSERT INTO chat_tags (nome, slug, cor, descricao) VALUES (:n, :s, :c, :d)"
            );
            $st->execute([
                ':n' => mb_substr($nome, 0, 60), ':s' => $slug,
                ':c' => preg_match('/^#[0-9a-f]{6}$/i', $cor) ? $cor : '#2563eb',
                ':d' => $descricao ? mb_substr($descricao, 0, 200) : null,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (Throwable $e) {
            return $this->tagIdPorSlug($slug);
        }
    }

    public function excluirTag(int $tagId): bool
    {
        $this->db->prepare("DELETE FROM chat_tags WHERE id = :id")->execute([':id' => $tagId]);
        return true;
    }

    private function recontarTag(int $tagId): void
    {
        try {
            $this->db->prepare(
                "UPDATE chat_tags SET total_contatos =
                    (SELECT COUNT(*) FROM chat_contato_tags WHERE tag_id = :t)
                 WHERE id = :t2"
            )->execute([':t' => $tagId, ':t2' => $tagId]);
        } catch (Throwable $e) {}
    }

    // =========================================================================
    // LISTAGEM E SEGMENTAÇÃO
    // =========================================================================

    /**
     * Lista paginada com filtros. Mesma assinatura de filtros usada pelo
     * segmento das campanhas — é a MESMA query, para o público estimado
     * bater exatamente com quem recebe.
     *
     * @param array $f busca, tags[], optin, janela, com_cliente, origem, desde, ate
     * @return array{itens:array, total:int}
     */
    public function listar(array $f = [], int $pagina = 1, int $porPagina = 30): array
    {
        [$where, $params] = $this->montarFiltro($f);
        $pagina    = max(1, $pagina);
        $porPagina = max(1, min(200, $porPagina));
        $offset    = ($pagina - 1) * $porPagina;

        $stT = $this->db->prepare("SELECT COUNT(DISTINCT c.id) FROM chat_contatos c $where");
        $stT->execute($params);
        $total = (int)$stT->fetchColumn();

        $sql = "SELECT DISTINCT c.*, cv.id AS conversa_id, cv.status AS conversa_status,
                       cv.nao_lidas, cv.ultima_mensagem_em
                FROM chat_contatos c
                LEFT JOIN chat_conversas cv ON cv.contato_id = c.id
                $where
                ORDER BY COALESCE(c.ultima_entrada_em, c.criado_em) DESC
                LIMIT $porPagina OFFSET $offset";
        $st = $this->db->prepare($sql);
        $st->execute($params);

        $itens = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $itens[] = $this->hidratar($c);

        return ['itens' => $itens, 'total' => $total];
    }

    /** IDs de um segmento — a fila de uma campanha sai daqui. */
    public function idsDoSegmento(array $f = [], int $limite = 100000): array
    {
        [$where, $params] = $this->montarFiltro($f);
        $st = $this->db->prepare(
            "SELECT DISTINCT c.id FROM chat_contatos c $where ORDER BY c.id LIMIT " . (int)$limite
        );
        $st->execute($params);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function contarSegmento(array $f = []): int
    {
        [$where, $params] = $this->montarFiltro($f);
        $st = $this->db->prepare("SELECT COUNT(DISTINCT c.id) FROM chat_contatos c $where");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    /**
     * Traduz o array de filtros em WHERE + params.
     * Tudo por placeholder — nenhum valor entra concatenado no SQL.
     */
    private function montarFiltro(array $f): array
    {
        $w = ['1=1'];
        $p = [];

        if (!empty($f['busca'])) {
            $termo = '%' . trim((string)$f['busca']) . '%';
            $w[] = "(c.nome LIKE :busca OR c.nome_perfil LIKE :busca2 OR c.wa_id LIKE :busca3 OR c.email LIKE :busca4)";
            $p[':busca'] = $termo; $p[':busca2'] = $termo; $p[':busca3'] = $termo; $p[':busca4'] = $termo;
        }

        if (isset($f['optin']) && $f['optin'] !== '' && $f['optin'] !== null) {
            $w[] = "c.optin = :optin";
            $p[':optin'] = (int)!empty($f['optin']);
        }

        if (!empty($f['nao_bloqueado'])) {
            $w[] = "c.bloqueado = 0";
        }

        // janela: 'aberta' | 'fechada'
        if (!empty($f['janela'])) {
            $w[] = $f['janela'] === 'aberta'
                ? "(c.janela_expira_em IS NOT NULL AND c.janela_expira_em > NOW())"
                : "(c.janela_expira_em IS NULL OR c.janela_expira_em <= NOW())";
        }

        if (isset($f['com_cliente']) && $f['com_cliente'] !== '' && $f['com_cliente'] !== null) {
            $w[] = !empty($f['com_cliente']) ? "c.cliente_id IS NOT NULL" : "c.cliente_id IS NULL";
        }

        if (!empty($f['origem'])) {
            $w[] = "c.origem = :origem";
            $p[':origem'] = (string)$f['origem'];
        }

        if (!empty($f['desde'])) { $w[] = "c.criado_em >= :desde"; $p[':desde'] = (string)$f['desde']; }
        if (!empty($f['ate']))   { $w[] = "c.criado_em <= :ate";   $p[':ate']   = (string)$f['ate']; }

        // Tags: IN (qualquer) ou HAVING COUNT (todas)
        $tags = array_values(array_filter(array_map('intval', (array)($f['tags'] ?? []))));
        if ($tags) {
            $ph = [];
            foreach ($tags as $i => $t) { $ph[] = ":tag$i"; $p[":tag$i"] = $t; }
            $lista = implode(',', $ph);

            if (!empty($f['tags_modo']) && $f['tags_modo'] === 'todas') {
                $w[] = "(SELECT COUNT(DISTINCT ct.tag_id) FROM chat_contato_tags ct
                         WHERE ct.contato_id = c.id AND ct.tag_id IN ($lista)) = " . count($tags);
            } else {
                $w[] = "EXISTS (SELECT 1 FROM chat_contato_tags ct
                                WHERE ct.contato_id = c.id AND ct.tag_id IN ($lista))";
            }
        }

        // Tags excluídas — "não mande para quem já tem a tag X"
        $excl = array_values(array_filter(array_map('intval', (array)($f['tags_excluir'] ?? []))));
        if ($excl) {
            $ph = [];
            foreach ($excl as $i => $t) { $ph[] = ":xtag$i"; $p[":xtag$i"] = $t; }
            $w[] = "NOT EXISTS (SELECT 1 FROM chat_contato_tags ct
                                WHERE ct.contato_id = c.id AND ct.tag_id IN (" . implode(',', $ph) . "))";
        }

        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    // =========================================================================
    // VÍNCULO COM A BASE DE CLIENTES
    // =========================================================================

    /**
     * Acha o cliente pelo telefone. Compara só os dígitos e pelos ÚLTIMOS 8 —
     * a base tem telefone gravado em formatos variados (com/sem DDI, com/sem
     * o 9º dígito), e o sufixo é o que sobrevive a todas as variações.
     */
    public function descobrirCliente(string $waId): ?int
    {
        $n = preg_replace('/\D/', '', $waId) ?? '';
        if (strlen($n) < 10) return null;
        $sufixo = substr($n, -8);

        try {
            $st = $this->db->prepare(
                "SELECT id FROM clientes
                 WHERE RIGHT(REGEXP_REPLACE(COALESCE(celular, ''),  '[^0-9]', ''), 8) = :s1
                    OR RIGHT(REGEXP_REPLACE(COALESCE(telefone, ''), '[^0-9]', ''), 8) = :s2
                 ORDER BY id ASC LIMIT 1"
            );
            $st->execute([':s1' => $sufixo, ':s2' => $sufixo]);
            $id = (int)$st->fetchColumn();
            return $id ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function vincularCliente(int $contatoId, int $clienteId): bool
    {
        try {
            $this->db->prepare("UPDATE chat_contatos SET cliente_id = :cli WHERE id = :id")
                     ->execute([':cli' => $clienteId, ':id' => $contatoId]);

            // Puxa nome/email do cadastro quando o contato ainda não tem
            $st = $this->db->prepare(
                "SELECT u.nome, u.email FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = :id LIMIT 1"
            );
            $st->execute([':id' => $clienteId]);
            if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                $this->db->prepare(
                    "UPDATE chat_contatos
                     SET nome  = COALESCE(NULLIF(nome, ''), :n),
                         email = COALESCE(NULLIF(email, ''), :e)
                     WHERE id = :id"
                )->execute([':n' => $u['nome'], ':e' => $u['email'], ':id' => $contatoId]);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function desvincularCliente(int $contatoId): void
    {
        $this->db->prepare("UPDATE chat_contatos SET cliente_id = NULL WHERE id = :id")
                 ->execute([':id' => $contatoId]);
    }

    // =========================================================================
    // NOTAS
    // =========================================================================

    public function notas(int $contatoId, int $limite = 50): array
    {
        $st = $this->db->prepare(
            "SELECT n.*, u.nome AS autor
             FROM chat_notas n
             LEFT JOIN usuarios u ON u.id = n.usuario_id
             WHERE n.contato_id = :c
             ORDER BY n.id DESC LIMIT " . (int)$limite
        );
        $st->execute([':c' => $contatoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function adicionarNota(int $contatoId, string $nota, ?int $usuarioId = null): ?int
    {
        $nota = trim($nota);
        if ($nota === '') return null;
        $st = $this->db->prepare(
            "INSERT INTO chat_notas (contato_id, usuario_id, nota) VALUES (:c, :u, :n)"
        );
        $st->execute([':c' => $contatoId, ':u' => $usuarioId, ':n' => mb_substr($nota, 0, 5000)]);
        return (int)$this->db->lastInsertId();
    }

    public function excluirNota(int $notaId): void
    {
        $this->db->prepare("DELETE FROM chat_notas WHERE id = :id")->execute([':id' => $notaId]);
    }

    // =========================================================================
    // VARIÁVEIS DE TEMPLATE
    // =========================================================================

    /**
     * Monta o dicionário {{var}} do contato. É o que os nós de mensagem e as
     * campanhas interpolam. Campos personalizados entram por último e vencem,
     * permitindo sobrescrever qualquer coisa por fluxo.
     */
    public function variaveis(array $contato): array
    {
        $nome = $contato['nome'] ?: ($contato['nome_perfil'] ?: '');
        $vars = [
            'site_nome'     => defined('SITE_NAME') ? SITE_NAME : 'SportMoto',
            'url_site'      => defined('BASE_URL') ? BASE_URL : '',
            'data_atual'    => date('d/m/Y'),
            'hora_atual'    => date('H:i'),
            'ano'           => date('Y'),
            'nome'          => $nome ?: 'tudo bem',
            'primeiro_nome' => $nome ? (explode(' ', trim($nome))[0]) : 'tudo bem',
            'telefone'      => $contato['wa_id'] ?? '',
            'email'         => $contato['email'] ?? '',
            'saudacao'      => $this->saudacao(),
        ];

        // Dados da loja quando o contato é cliente identificado
        if (!empty($contato['cliente_id'])) {
            try {
                $st = $this->db->prepare(
                    "SELECT COUNT(*) AS pedidos, COALESCE(SUM(total), 0) AS gasto,
                            MAX(criado_em) AS ultimo_pedido
                     FROM pedidos WHERE cliente_id = :c"
                );
                $st->execute([':c' => (int)$contato['cliente_id']]);
                if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $vars['total_pedidos'] = (int)$r['pedidos'];
                    $vars['total_gasto']   = 'R$ ' . number_format((float)$r['gasto'], 2, ',', '.');
                    $vars['ultimo_pedido'] = $r['ultimo_pedido'] ? date('d/m/Y', strtotime($r['ultimo_pedido'])) : '';
                }
            } catch (Throwable $e) {}
        }

        foreach (($contato['campos'] ?? []) as $k => $v) {
            if (is_scalar($v)) $vars[$k] = $v;
        }
        return $vars;
    }

    /** Interpola {{var}} numa string. Idêntico ao motor v2, de propósito. */
    public static function interpolar(string $texto, array $vars): string
    {
        if ($texto === '' || !str_contains($texto, '{{')) return $texto;
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            fn($m) => (string)($vars[$m[1]] ?? ''),
            $texto
        ) ?? $texto;
    }

    // =========================================================================
    // UTILITÁRIOS
    // =========================================================================

    private function saudacao(): string
    {
        $h = (int)date('G');
        if ($h < 12) return 'Bom dia';
        if ($h < 18) return 'Boa tarde';
        return 'Boa noite';
    }

    /** 5551989739674 → +55 (51) 98973-9674 */
    public function formatarTelefone(string $waId): string
    {
        $n = preg_replace('/\D/', '', $waId) ?? '';
        if (strlen($n) === 13 && str_starts_with($n, '55')) {
            return sprintf('+55 (%s) %s-%s', substr($n, 2, 2), substr($n, 4, 5), substr($n, 9));
        }
        if (strlen($n) === 12 && str_starts_with($n, '55')) {
            return sprintf('+55 (%s) %s-%s', substr($n, 2, 2), substr($n, 4, 4), substr($n, 8));
        }
        return '+' . $n;
    }

    private function slug(string $texto): string
    {
        $t = strtolower(self::semAcento(trim($texto)));
        $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
        return trim($t, '-');
    }

    /**
     * Remove acentos por tabela explícita.
     *
     * NÃO usa iconv('ASCII//TRANSLIT'): o resultado depende da implementação de
     * iconv do sistema. No Windows 'á' vira "'a" (apóstrofo + a) em vez de 'a',
     * e aí "Olá" deixa de casar com "ola" na busca por palavra-chave. Tabela
     * fixa dá o mesmo resultado em qualquer servidor.
     */
    public static function semAcento(string $t): string
    {
        static $de = null, $para = null;
        if ($de === null) {
            $mapa = [
                'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
                'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
                'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
                'ç'=>'c','ñ'=>'n','ý'=>'y',
                'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','Å'=>'A',
                'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
                'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
                'Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
                'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
                'Ç'=>'C','Ñ'=>'N','Ý'=>'Y',
            ];
            $de   = array_keys($mapa);
            $para = array_values($mapa);
        }
        return str_replace($de, $para, $t);
    }
}
