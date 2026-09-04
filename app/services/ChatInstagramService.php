<?php
/**
 * app/services/ChatInstagramService.php
 *
 * Camada de domínio do Instagram: contas conectadas, cache de mídias e a
 * automação de comentários — o recurso que faz "comente PROMO e receba no
 * direct" funcionar.
 *
 * REGRA CENTRAL DA PRIVATE REPLY:
 * A Meta permite abrir DM a partir de um comentário UMA ÚNICA VEZ por
 * comentário, dentro de 7 dias. A segunda tentativa devolve erro 10903. Por
 * isso todo comentário é gravado em chat_ig_comentarios ANTES de qualquer
 * envio, e `dm_enviado` é a trava — sem esse registro, um webhook reenviado
 * pela Meta viraria erro em vez de no-op.
 */
class ChatInstagramService
{
    private PDO $db;
    private ChatContatoService  $contatos;
    private ChatConversaService $conversas;
    private ChatMensagemService $mensagens;

    public function __construct(?PDO $db = null)
    {
        $this->db        = $db ?? Database::getInstance()->getConnection();
        $this->contatos  = new ChatContatoService($this->db);
        $this->conversas = new ChatConversaService($this->db);
        $this->mensagens = new ChatMensagemService($this->db);
    }

    // =========================================================================
    // CONTAS
    // =========================================================================

    public function contas(bool $soAtivas = false): array
    {
        $where = $soAtivas ? 'WHERE ativo = 1' : '';
        return $this->db->query(
            "SELECT * FROM chat_ig_contas $where ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function conta(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_ig_contas WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function contaPorIgUserId(string $igUserId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_ig_contas WHERE ig_user_id = :i LIMIT 1");
        $st->execute([':i' => $igUserId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A primeira conta ativa — usada quando não há ambiguidade. */
    public function contaPadrao(): ?array
    {
        $st = $this->db->query("SELECT * FROM chat_ig_contas WHERE ativo = 1 ORDER BY id LIMIT 1");
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Descobre as contas pelo token de usuário e grava/atualiza.
     * @return array{ok:bool, contas?:int, erro?:string, detalhe?:array}
     */
    public function conectar(?string $userToken = null): array
    {
        $token = $userToken ?: (string)(getenv('META_ACCESS_TOKEN')
                                     ?: ($_ENV['META_ACCESS_TOKEN'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'erro' => 'META_ACCESS_TOKEN não configurado no .env.'];
        }

        try {
            $achadas = ChatInstagramClient::descobrirContas($token);
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }

        if (!$achadas) {
            return [
                'ok'   => false,
                'erro' => 'Nenhuma página do Facebook com conta Instagram Business vinculada '
                        . 'foi encontrada. Verifique se o token tem os escopos pages_show_list '
                        . 'e instagram_basic, e se a conta do Instagram está no modo Profissional '
                        . 'e vinculada a uma página.',
            ];
        }

        $ins = $this->db->prepare(
            "INSERT INTO chat_ig_contas
                (ig_user_id, username, nome, foto_url, page_id, page_nome, page_token,
                 seguidores, ativo, sincronizado_em)
             VALUES (:ig, :u, :n, :f, :pid, :pn, :pt, :s, 1, NOW())
             ON DUPLICATE KEY UPDATE
                username = VALUES(username), nome = VALUES(nome), foto_url = VALUES(foto_url),
                page_id = VALUES(page_id), page_nome = VALUES(page_nome),
                page_token = VALUES(page_token), seguidores = VALUES(seguidores),
                sincronizado_em = NOW(), ultimo_erro = NULL"
        );

        foreach ($achadas as $c) {
            $ins->execute([
                ':ig'  => $c['ig_user_id'], ':u' => $c['username'], ':n' => $c['nome'],
                ':f'   => $c['foto_url'],   ':pid' => $c['page_id'], ':pn' => $c['page_nome'],
                ':pt'  => $c['page_token'], ':s' => $c['seguidores'],
            ]);
        }

        if (class_exists('LogService')) {
            try { LogService::audit('chat_ig_conectado', ['contas' => count($achadas)]); } catch (Throwable $e) {}
        }

        return ['ok' => true, 'contas' => count($achadas), 'detalhe' => $achadas];
    }

    /** Assina o app nos eventos da página. Sem isso o webhook não chega. */
    public function assinarWebhook(int $contaId): array
    {
        $conta = $this->conta($contaId);
        if (!$conta) return ['ok' => false, 'erro' => 'Conta não encontrada.'];
        if (empty($conta['page_id'])) return ['ok' => false, 'erro' => 'Conta sem página vinculada.'];

        try {
            $cli = ChatInstagramClient::daConta($conta);
            $cli->assinarWebhook((string)$conta['page_id']);

            $this->db->prepare(
                "UPDATE chat_ig_contas SET webhook_assinado = 1, ultimo_erro = NULL WHERE id = :id"
            )->execute([':id' => $contaId]);

            return ['ok' => true];
        } catch (Throwable $e) {
            $msg = $e->getMessage();

            // Faltar pages_messaging aqui NÃO impede o canal de funcionar: os
            // comentários chegam pela assinatura do objeto `instagram` no
            // painel do app, que é outro mecanismo. Só o DM de entrada
            // depende desta assinatura de página.
            if (str_contains($msg, 'pages_messaging')) {
                $this->registrarErro($contaId, 'sem pages_messaging para assinar a página');
                return [
                    'ok'      => false,
                    'parcial' => true,
                    'erro'    => 'Falta a permissão pages_messaging no token para assinar a página. '
                               . 'Comentários continuam chegando normalmente (eles vêm pela assinatura '
                               . 'do objeto "instagram" no painel do app). Só o direct de ENTRADA '
                               . 'depende desta assinatura.',
                ];
            }

            $this->registrarErro($contaId, $msg);
            return ['ok' => false, 'erro' => $msg];
        }
    }

    public function alternarAtivo(int $contaId): void
    {
        $this->db->prepare("UPDATE chat_ig_contas SET ativo = 1 - ativo WHERE id = :id")
                 ->execute([':id' => $contaId]);
    }

    public function desconectar(int $contaId): void
    {
        // Apaga a conta mas preserva contatos e conversas: são histórico de
        // atendimento, não podem sumir porque alguém desconectou a integração.
        $this->db->prepare("DELETE FROM chat_ig_contas WHERE id = :id")->execute([':id' => $contaId]);
    }

    private function registrarErro(int $contaId, string $erro): void
    {
        try {
            $this->db->prepare("UPDATE chat_ig_contas SET ultimo_erro = :e WHERE id = :id")
                     ->execute([':e' => mb_substr($erro, 0, 400), ':id' => $contaId]);
        } catch (Throwable $e) {}
    }

    // =========================================================================
    // MÍDIAS
    // =========================================================================

    /** @return array{ok:bool, total?:int, erro?:string} */
    public function sincronizarMidias(int $contaId): array
    {
        $conta = $this->conta($contaId);
        if (!$conta) return ['ok' => false, 'erro' => 'Conta não encontrada.'];

        try {
            $cli    = ChatInstagramClient::daConta($conta);
            $limite = max(1, min(100, ChatConfig::int('ig_sync_midias_limite', 50)));
            $lista  = $cli->midias($limite);
        } catch (Throwable $e) {
            $this->registrarErro($contaId, $e->getMessage());
            return ['ok' => false, 'erro' => $e->getMessage()];
        }

        $ins = $this->db->prepare(
            "INSERT INTO chat_ig_midias
                (conta_id, media_id, tipo, legenda, permalink, thumb_url,
                 publicado_em, total_comentarios, total_curtidas, sincronizado_em)
             VALUES (:c, :m, :t, :l, :p, :th, :pub, :tc, :tk, NOW())
             ON DUPLICATE KEY UPDATE
                legenda = VALUES(legenda), permalink = VALUES(permalink),
                thumb_url = VALUES(thumb_url), total_comentarios = VALUES(total_comentarios),
                total_curtidas = VALUES(total_curtidas), sincronizado_em = NOW()"
        );

        foreach ($lista as $m) {
            // Reels chegam com media_product_type=REELS e media_type=VIDEO;
            // o painel quer distinguir os dois
            $tipo = ($m['media_product_type'] ?? '') === 'REELS'
                ? 'REELS' : (string)($m['media_type'] ?? '');

            $ins->execute([
                ':c'   => $contaId,
                ':m'   => (string)$m['id'],
                ':t'   => $tipo,
                ':l'   => isset($m['caption']) ? mb_substr((string)$m['caption'], 0, 4000) : null,
                ':p'   => $m['permalink'] ?? null,
                ':th'  => $m['thumbnail_url'] ?? ($m['media_url'] ?? null),
                ':pub' => !empty($m['timestamp']) ? date('Y-m-d H:i:s', strtotime((string)$m['timestamp'])) : null,
                ':tc'  => (int)($m['comments_count'] ?? 0),
                ':tk'  => (int)($m['like_count'] ?? 0),
            ]);
        }

        return ['ok' => true, 'total' => count($lista)];
    }

    public function midias(?int $contaId = null, int $limite = 60): array
    {
        $sql = "SELECT m.*, c.username FROM chat_ig_midias m
                JOIN chat_ig_contas c ON c.id = m.conta_id";
        $p = [];
        if ($contaId) { $sql .= " WHERE m.conta_id = :c"; $p[':c'] = $contaId; }
        $sql .= " ORDER BY m.publicado_em DESC LIMIT " . max(1, min(200, $limite));

        $st = $this->db->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // REGRAS DE COMENTÁRIO
    // =========================================================================

    public function regras(bool $soAtivas = false): array
    {
        // A lixeira fica de fora sempre: automação excluída não pode voltar a
        // responder cliente por causa de uma consulta esquecida.
        $where = $soAtivas
            ? 'WHERE r.ativo = 1 AND r.excluido_em IS NULL'
            : 'WHERE r.excluido_em IS NULL';

        return $this->db->query(
            "SELECT r.*, f.nome AS fluxo_nome, t.nome AS tag_nome, t.cor AS tag_cor,
                    c.username AS conta_username
             FROM chat_ig_regras r
             LEFT JOIN chat_fluxos f ON f.id = r.fluxo_id
             LEFT JOIN chat_tags t   ON t.id = r.tag_id
             LEFT JOIN chat_ig_contas c ON c.id = r.conta_id
             $where
             ORDER BY r.ativo DESC, r.prioridade ASC, r.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function regra(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_ig_regras WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) $r['midias'] = json_decode($r['midias_json'] ?? '[]', true) ?: [];
        return $r ?: null;
    }

    /** @return array{ok:bool, id?:int, erro?:string} */
    public function salvarRegra(array $d, ?int $id = null): array
    {
        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '') return ['ok' => false, 'erro' => 'Informe o nome da regra.'];

        $escopo = in_array($d['escopo'] ?? '', ['todas', 'midia', 'novas'], true) ? $d['escopo'] : 'todas';
        $modo   = in_array($d['modo_match'] ?? '', ['exato', 'contem', 'comeca', 'regex'], true) ? $d['modo_match'] : 'contem';

        $midias = array_values(array_filter(array_map('strval', (array)($d['midias'] ?? []))));
        if ($escopo === 'midia' && !$midias) {
            return ['ok' => false, 'erro' => 'Selecione pelo menos uma publicação.'];
        }

        $enviarDm  = !empty($d['enviar_dm']);
        $respPub   = !empty($d['responder_publico']);
        $fluxoId   = (int)($d['fluxo_id'] ?? 0) ?: null;

        if (!$enviarDm && !$respPub && !$fluxoId && empty($d['tag_id'])) {
            return ['ok' => false, 'erro' => 'A regra precisa fazer pelo menos uma coisa: responder, mandar DM, iniciar fluxo ou aplicar tag.'];
        }
        if ($enviarDm && trim((string)($d['mensagem_dm'] ?? '')) === '' && !$fluxoId) {
            return ['ok' => false, 'erro' => 'Escreva a mensagem do direct ou escolha um fluxo.'];
        }
        if ($respPub && trim((string)($d['resposta_publica'] ?? '')) === '') {
            return ['ok' => false, 'erro' => 'Escreva a resposta pública.'];
        }

        $palavras = trim((string)($d['palavras'] ?? ''));
        if ($modo === 'regex' && $palavras !== ''
            && @preg_match('#' . str_replace('#', '\#', $palavras) . '#iu', '') === false) {
            return ['ok' => false, 'erro' => 'A expressão regular informada é inválida.'];
        }

        $campos = [
            ':conta'  => (int)($d['conta_id'] ?? 0) ?: null,
            ':nome'   => mb_substr($nome, 0, 140),
            ':escopo' => $escopo,
            ':mj'     => $midias ? json_encode($midias, JSON_UNESCAPED_UNICODE) : null,
            ':pal'    => mb_substr($palavras, 0, 400) ?: null,
            ':modo'   => $modo,
            ':ip'     => (int)!empty($d['ignorar_proprios']),
            ':ir'     => (int)!empty($d['ignorar_respostas']),
            ':rp'     => (int)$respPub,
            ':txtp'   => trim((string)($d['resposta_publica'] ?? '')) ?: null,
            ':dm'     => (int)$enviarDm,
            ':txtdm'  => trim((string)($d['mensagem_dm'] ?? '')) ?: null,
            ':fid'    => $fluxoId,
            ':tid'    => (int)($d['tag_id'] ?? 0) ?: null,
            ':prio'   => max(0, min(999, (int)($d['prioridade'] ?? 50))),
            ':ativo'  => (int)!empty($d['ativo']),
            ':uma'    => (int)!empty($d['uma_vez_por_pessoa']),
        ];

        try {
            if ($id) {
                $campos[':id'] = $id;
                $this->db->prepare(
                    "UPDATE chat_ig_regras SET
                        conta_id = :conta, nome = :nome, escopo = :escopo, midias_json = :mj,
                        palavras = :pal, modo_match = :modo, ignorar_proprios = :ip,
                        ignorar_respostas = :ir, responder_publico = :rp, resposta_publica = :txtp,
                        enviar_dm = :dm, mensagem_dm = :txtdm, fluxo_id = :fid, tag_id = :tid,
                        prioridade = :prio, ativo = :ativo, uma_vez_por_pessoa = :uma
                     WHERE id = :id"
                )->execute($campos);
                return ['ok' => true, 'id' => $id];
            }

            $this->db->prepare(
                "INSERT INTO chat_ig_regras
                    (conta_id, nome, escopo, midias_json, palavras, modo_match,
                     ignorar_proprios, ignorar_respostas, responder_publico, resposta_publica,
                     enviar_dm, mensagem_dm, fluxo_id, tag_id, prioridade, ativo, uma_vez_por_pessoa)
                 VALUES (:conta, :nome, :escopo, :mj, :pal, :modo, :ip, :ir, :rp, :txtp,
                         :dm, :txtdm, :fid, :tid, :prio, :ativo, :uma)"
            )->execute($campos);
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao salvar: ' . $e->getMessage()];
        }
    }

    public function alternarRegra(int $id): void
    {
        $this->db->prepare("UPDATE chat_ig_regras SET ativo = 1 - ativo WHERE id = :id")
                 ->execute([':id' => $id]);
    }

    public function excluirRegra(int $id): void
    {
        $this->db->prepare("DELETE FROM chat_ig_regras WHERE id = :id")->execute([':id' => $id]);
    }

    // =========================================================================
    // PROCESSAMENTO DE COMENTÁRIO
    // =========================================================================

    /**
     * Trata um comentário recebido pelo webhook.
     *
     * @param array  $c        value do webhook (id, text, from, media, parent_id)
     * @param string $igUserId conta que recebeu
     * @return array{ok:bool, motivo:string, regra_id?:int}
     */
    public function processarComentario(array $c, string $igUserId, string $evento = 'comments'): array
    {
        $commentId = (string)($c['id'] ?? '');
        if ($commentId === '') return ['ok' => false, 'motivo' => 'comentário sem id'];

        $conta   = $this->contaPorIgUserId($igUserId);
        $contaId = $conta ? (int)$conta['id'] : null;

        $texto    = (string)($c['text'] ?? '');
        $fromId   = (string)($c['from']['id'] ?? '');
        $fromUser = (string)($c['from']['username'] ?? $c['username'] ?? '');
        $mediaId  = (string)($c['media']['id'] ?? '');
        $parentId = (string)($c['parent_id'] ?? '');

        // ── Grava primeiro. O UNIQUE em comment_id é o dedup real: a Meta
        //    reenvia o webhook e sem isso o mesmo comentário viraria dois DMs.
        $ins = $this->db->prepare(
            "INSERT IGNORE INTO chat_ig_comentarios
                (conta_id, comment_id, parent_id, media_id, from_ig_id, from_username, texto)
             VALUES (:c, :cid, :pid, :mid, :fid, :fu, :t)"
        );
        $ins->execute([
            ':c'   => $contaId, ':cid' => $commentId, ':pid' => $parentId ?: null,
            ':mid' => $mediaId ?: null, ':fid' => $fromId ?: null,
            ':fu'  => $fromUser ?: null, ':t' => $texto,
        ]);
        if ($ins->rowCount() === 0) {
            return ['ok' => false, 'motivo' => 'comentário já processado'];
        }
        $registroId = (int)$this->db->lastInsertId();

        if (!ChatConfig::bool('ig_comentarios_ativo', true)) {
            $this->marcarProcessado($registroId);
            return ['ok' => false, 'motivo' => 'automação de comentários desligada'];
        }

        // Comentário da própria conta responder a si mesma é loop garantido
        if ($fromId !== '' && $fromId === $igUserId) {
            $this->marcarProcessado($registroId);
            return ['ok' => false, 'motivo' => 'comentário da própria conta'];
        }

        // ── Acha a regra ──
        $tipoMidia = (string)($c['media']['media_product_type'] ?? '');
        $porQue = null;
        $regra  = $this->acharRegra(
            $texto, $mediaId, $contaId, $parentId !== '', $fromId, $evento, $tipoMidia, $porQue
        );
        if (!$regra) {
            $this->marcarProcessado($registroId);
            // Duas regras já bastam para apontar a correção; a coluna do log
            // tem 400 caracteres e a lista inteira não caberia.
            $motivo = $porQue
                ? 'nenhuma regra casou — ' . implode(' · ', array_slice($porQue, 0, 2))
                : 'nenhuma automação ativa para comentário';
            return ['ok' => false, 'motivo' => mb_substr($motivo, 0, 380)];
        }

        $this->db->prepare("UPDATE chat_ig_comentarios SET regra_id = :r WHERE id = :id")
                 ->execute([':r' => (int)$regra['id'], ':id' => $registroId]);

        $r = $this->executarRegra($regra, $registroId, $commentId, $fromId, $fromUser, $texto, $conta);
        $this->marcarProcessado($registroId);
        $this->registrarDisparo((int)$regra['id']);

        return ['ok' => $r['ok'], 'motivo' => $r['motivo'], 'regra_id' => (int)$regra['id']];
    }

    /**
     * Primeira regra ativa que casa, por prioridade.
     *
     * @param string $evento    comments | live_comments | story_reply | mentions
     * @param string $tipoMidia FEED | REELS | VIDEO — distingue post de reel
     */
    /**
     * @param array|null $porQue Preenchido com o motivo de cada regra recusada.
     *        Sem isto o log diz só "nenhuma regra casou", e quem investiga não
     *        sabe se marcou o Reel errado, se a palavra não bateu ou se aquela
     *        pessoa já tinha sido atendida.
     */
    private function acharRegra(
        string $texto, string $mediaId, ?int $contaId, bool $ehResposta, string $fromId,
        string $evento = 'comments', string $tipoMidia = '', ?array &$porQue = null
    ): ?array {
        $porQue = [];
        $anota = function (array $r, string $motivo) use (&$porQue): void {
            $porQue[] = '#' . (int)$r['id'] . ' ' . mb_substr((string)$r['nome'], 0, 40) . ': ' . $motivo;
        };

        $gat = new ChatGatilhoService($this->db);

        // Sem tipo informado, descobre pelo cache de mídias — é o que separa
        // "venda pelos reels" de "responda comentários do feed"
        if ($tipoMidia === '' && $mediaId !== '') {
            $tipoMidia = (string)$this->tipoDaMidia($mediaId);
        }

        foreach ($this->regras(true) as $regra) {
            // Conta específica
            if ($regra['conta_id'] !== null && $contaId !== null
                && (int)$regra['conta_id'] !== $contaId) {
                $anota($regra, 'é de outra conta do Instagram');
                continue;
            }

            // Tipo de gatilho: uma automação de live não responde post comum
            $gatilho = (string)($regra['gatilho_tipo'] ?? 'comentario');
            if (!ChatIgReceitaService::gatilhoAceita($gatilho, $evento, $tipoMidia)) {
                $anota($regra, "gatilho é “{$gatilho}”, e isto é {$evento}"
                             . ($tipoMidia !== '' ? " em {$tipoMidia}" : ''));
                continue;
            }

            if ($ehResposta && (int)$regra['ignorar_respostas'] === 1) {
                $anota($regra, 'é resposta dentro de uma thread, e a automação ignora essas');
                continue;
            }

            // Escopo por mídia
            if ($regra['escopo'] === 'midia') {
                $midias = json_decode($regra['midias_json'] ?? '[]', true) ?: [];
                if (!in_array($mediaId, array_map('strval', $midias), true)) {
                    $anota($regra, $midias
                        ? 'a publicação ' . ($mediaId ?: '(sem id)') . ' não está entre as '
                          . count($midias) . ' marcadas no escopo'
                        : 'escopo é "uma publicação específica" e nenhuma foi marcada');
                    continue;
                }
            }

            // Palavras: vazio = qualquer comentário serve
            $palavras = trim((string)($regra['palavras'] ?? ''));
            if ($palavras !== '' && !$gat->casa($texto, $palavras, (string)$regra['modo_match'])) {
                $anota($regra, "o texto não casa com “{$palavras}” ({$regra['modo_match']})");
                continue;
            }

            // "Uma vez por pessoa"
            if ((int)$regra['uma_vez_por_pessoa'] === 1 && $fromId !== '') {
                $st = $this->db->prepare(
                    "SELECT 1 FROM chat_ig_comentarios
                     WHERE regra_id = :r AND from_ig_id = :f AND dm_enviado = 1 LIMIT 1"
                );
                $st->execute([':r' => (int)$regra['id'], ':f' => $fromId]);
                if ($st->fetchColumn()) {
                    $anota($regra, 'esta pessoa já foi atendida e a automação é "só uma vez por pessoa"');
                    continue;
                }
            }

            return $regra;
        }
        return null;
    }

    /** Tipo da mídia pelo cache local (REELS, IMAGE, VIDEO, CAROUSEL_ALBUM). */
    private function tipoDaMidia(string $mediaId): string
    {
        try {
            $st = $this->db->prepare("SELECT tipo FROM chat_ig_midias WHERE media_id = :m LIMIT 1");
            $st->execute([':m' => $mediaId]);
            return (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Executa as ações da regra: resposta pública, DM, fluxo, tag. */
    private function executarRegra(
        array $regra, int $registroId, string $commentId,
        string $fromId, string $fromUser, string $textoComentario, ?array $conta
    ): array {
        if (!$conta) return ['ok' => false, 'motivo' => 'conta do Instagram não conectada'];

        $cli   = ChatInstagramClient::daConta($conta);
        $feito = [];

        // ── 1. Resposta pública ──
        if ((int)$regra['responder_publico'] === 1
            && ChatConfig::bool('ig_responder_publico', true)) {
            try {
                // Variações separadas por | evitam o perfil ficar com 40
                // respostas idênticas embaixo do mesmo post
                $opcoes = array_values(array_filter(array_map('trim',
                    explode('|', (string)$regra['resposta_publica']))));
                $txt = $opcoes ? $opcoes[random_int(0, count($opcoes) - 1)] : '';

                if ($txt !== '') {
                    $txt = ChatContatoService::interpolar($txt, [
                        'usuario'   => $fromUser,
                        'site_nome' => defined('SITE_NAME') ? SITE_NAME : '',
                    ]);
                    $cli->responderComentario($commentId, $txt);
                    $this->db->prepare(
                        "UPDATE chat_ig_comentarios SET respondido_publico = 1 WHERE id = :id"
                    )->execute([':id' => $registroId]);
                    $feito[] = 'resposta pública';
                }
            } catch (Throwable $e) {
                $this->logar('warning', 'ig: falha na resposta pública', [
                    'comment_id' => $commentId, 'erro' => $e->getMessage(),
                ]);
            }
        }

        // ── 2. O contato ──
        //
        // Ele amarra tag, fluxo e conversa. Nascia só dentro do ramo do DM
        // abaixo, e a receita "por fluxo" desliga o DM de propósito — então a
        // única receita feita para entregar a conversa ao fluxo era justamente
        // a que não criava contato, e o fluxo nunca começava. Sem erro, sem
        // sessão, sem log: o comentário casava e morria ali.
        //
        // `garantirContato` é upsert por wa_id, então criar aqui não conflita
        // com o ramo do DM, que pode reatribuir com o IGSID que a API devolve
        // (é o id certo para mensagem, e nem sempre igual ao do comentário).
        $contatoId = null;
        $precisaContato = (int)$regra['enviar_dm'] === 1
                       || !empty($regra['tag_id'])
                       || !empty($regra['fluxo_id']);

        if ($precisaContato && $fromId !== '') {
            try {
                $contatoId = $this->garantirContato($fromId, $fromUser, $conta) ?: null;
            } catch (Throwable $e) {
                $this->logar('error', 'ig: falha ao criar contato do comentário', [
                    'from_ig_id' => $fromId, 'erro' => $e->getMessage(),
                ]);
            }
        }

        // ── 3. DM privado (private reply) ──
        if ((int)$regra['enviar_dm'] === 1 && ChatConfig::bool('ig_dm_por_comentario', true)) {
            $exigeSeguidor = (int)($regra['exigir_seguidor'] ?? 0) === 1;

            // Na receita de crescimento, a primeira mensagem é o convite para
            // seguir — o link só sai depois que a pessoa confirma. Não dá para
            // checar `is_user_follow_business` aqui: a Meta só devolve esse
            // campo depois que a pessoa manda mensagem, e comentar não conta.
            $mensagem = $exigeSeguidor
                ? trim((string)($regra['mensagem_nao_seguidor'] ?? ''))
                : trim((string)($regra['mensagem_dm'] ?? ''));

            try {
                if ($mensagem !== '') {
                    $mensagem = ChatContatoService::interpolar($mensagem, [
                        'usuario'       => $fromUser,
                        'primeiro_nome' => $fromUser ?: 'tudo bem',
                        'nome'          => $fromUser,
                        'site_nome'     => defined('SITE_NAME') ? SITE_NAME : '',
                        'saudacao'      => $this->saudacao(),
                        'comentario'    => $textoComentario,
                    ]);

                    if ($exigeSeguidor) {
                        // Botão de confirmação: o payload volta pelo webhook e
                        // é lá que a verificação de "segue mesmo?" acontece
                        $resp = $cli->responderNoDirectComOpcoes($commentId, $mensagem, [
                            ['titulo' => 'Já segui!', 'payload' => 'ig_segui_' . (int)$regra['id']],
                        ]);
                    } else {
                        $mensagem = $this->anexarLink($mensagem, $regra, null, $commentId);
                        $resp = $cli->responderNoDirect($commentId, $mensagem);
                    }

                    $igsid = (string)($resp['igsid'] ?? '') ?: $fromId;

                    // Só agora existe um contato: a private reply abriu a thread
                    if ($igsid !== '') {
                        $contatoId = $this->garantirContato($igsid, $fromUser, $conta);
                        $this->registrarMensagemSaida(
                            $contatoId, $mensagem, (string)($resp['wamid'] ?? ''),
                            $commentId, (int)$regra['id']
                        );

                        // Guarda a entrega pendente: quando a pessoa tocar em
                        // "Já segui!", é daqui que sai qual link mandar
                        if ($exigeSeguidor) {
                            $this->contatos->setCampo($contatoId, '_ig_pendente', (int)$regra['id']);
                        }
                    }

                    $this->db->prepare(
                        "UPDATE chat_ig_comentarios SET dm_enviado = 1, contato_id = :ct WHERE id = :id"
                    )->execute([':ct' => $contatoId, ':id' => $registroId]);

                    (new ChatIgAutomacaoService($this->db))->registrarEnvio((int)$regra['id']);
                    $feito[] = $exigeSeguidor ? 'convite para seguir enviado' : 'DM enviado';
                }
            } catch (ChatIgException $e) {
                // 10903 = já usamos a private reply neste comentário. Não é
                // falha nova: é a Meta confirmando a regra de uma vez só.
                $motivo = $e->metaCode === ChatInstagramClient::ERRO_REPLY_USADA
                    ? 'private reply já usada neste comentário'
                    : $e->getMessage();

                $this->db->prepare(
                    "UPDATE chat_ig_comentarios SET dm_erro = :e WHERE id = :id"
                )->execute([':e' => mb_substr($motivo, 0, 400), ':id' => $registroId]);

                $this->logar('warning', 'ig: private reply falhou', [
                    'comment_id' => $commentId, 'meta_code' => $e->metaCode, 'erro' => $motivo,
                ]);
            } catch (Throwable $e) {
                $this->db->prepare(
                    "UPDATE chat_ig_comentarios SET dm_erro = :e WHERE id = :id"
                )->execute([':e' => mb_substr($e->getMessage(), 0, 400), ':id' => $registroId]);
            }
        }

        // ── 4. Tag ──
        if (!empty($regra['tag_id']) && $contatoId) {
            $this->contatos->aplicarTag($contatoId, (int)$regra['tag_id']);
            $feito[] = 'tag aplicada';
        }

        // ── 5. Fluxo ──
        if (!empty($regra['fluxo_id']) && $contatoId) {
            try {
                $sessao = (new ChatFluxoMotor($this->db))->iniciar(
                    (int)$regra['fluxo_id'], $contatoId,
                    [
                        '_origem'     => 'comentario_ig',
                        '_regra_id'   => (int)$regra['id'],
                        // O nó acao_ig_responder_comentario lê daqui
                        '_comment_id' => $commentId,
                        'comentario'  => $textoComentario,
                        'usuario_ig'  => $fromUser,
                    ]
                );
                if ($sessao) $feito[] = "fluxo {$regra['fluxo_id']} iniciado";
            } catch (Throwable $e) {
                $this->logar('error', 'ig: falha ao iniciar fluxo', ['erro' => $e->getMessage()]);
            }
        }

        return $feito
            ? ['ok' => true,  'motivo' => implode(', ', $feito)]
            : ['ok' => false, 'motivo' => 'nenhuma ação concluída'];
    }

    /**
     * Anexa o link da automação à mensagem, encurtado para medir o CTR.
     *
     * O Instagram não tem botão em private reply, então o link vai no corpo
     * mesmo. Encurtar não é estética: sem o redirect próprio não há como saber
     * quantos dos que receberam realmente clicaram.
     */
    private function anexarLink(string $mensagem, array $regra, ?int $contatoId, ?string $commentId): string
    {
        $destino = trim((string)($regra['link_destino'] ?? ''));
        if ($destino === '') return $mensagem;

        $curto = (new ChatIgAutomacaoService($this->db))
            ->gerarLink((int)$regra['id'], $contatoId, $destino, $commentId);

        if (!$curto) return $mensagem;

        $rotulo = trim((string)($regra['link_texto'] ?? '')) ?: 'Acessar';
        return rtrim($mensagem) . "\n\n" . $rotulo . ': ' . $curto;
    }

    /**
     * Resposta a story: aplica as automações de gatilho `story_reply`.
     *
     * Diferente do comentário, aqui a thread de DM JÁ está aberta — não existe
     * private reply nem resposta pública. É envio direto.
     *
     * @return bool true se alguma automação respondeu
     */
    public function processarRespostaStory(int $contatoId, string $texto, ?array $conta): bool
    {
        if (!ChatConfig::bool('ig_comentarios_ativo', true)) return false;

        $regra = $this->acharRegra($texto, '', $conta ? (int)$conta['id'] : null, false, '', 'story_reply');
        if (!$regra) return false;

        $mensagem = trim((string)($regra['mensagem_dm'] ?? ''));
        if ($mensagem === '' && empty($regra['fluxo_id'])) return false;

        $contato = $this->contatos->obter($contatoId);
        $vars    = $contato ? $this->contatos->variaveis($contato) : [];

        if ($mensagem !== '') {
            $mensagem = ChatContatoService::interpolar($mensagem, array_merge($vars, [
                'comentario' => $texto,
                'usuario'    => (string)($contato['ig_username'] ?? ''),
            ]));
            $mensagem = $this->anexarLink($mensagem, $regra, $contatoId, null);

            $r = (new ChatEnvioService($this->db))->texto($contatoId, $mensagem, [
                'origem'    => 'comentario_ig',
                'origem_id' => (int)$regra['id'],
                'vars'      => [],   // já interpolado acima
            ]);
            if (!$r['ok']) return false;

            (new ChatIgAutomacaoService($this->db))->registrarEnvio((int)$regra['id']);
        }

        if (!empty($regra['tag_id'])) {
            $this->contatos->aplicarTag($contatoId, (int)$regra['tag_id']);
        }
        if (!empty($regra['fluxo_id'])) {
            try {
                (new ChatFluxoMotor($this->db))->iniciar((int)$regra['fluxo_id'], $contatoId, [
                    '_origem' => 'story_ig', '_regra_id' => (int)$regra['id'], 'resposta_story' => $texto,
                ]);
            } catch (Throwable $e) {}
        }

        $this->registrarDisparo((int)$regra['id']);
        return true;
    }

    /**
     * A pessoa tocou em "Já segui!". Confere de verdade e entrega o link.
     *
     * Agora dá para checar: ela mandou mensagem, então a Meta libera o campo
     * `is_user_follow_business` no perfil.
     *
     * @return bool true se o payload era desta mecânica e foi tratado
     */
    public function tratarConfirmacaoSeguidor(string $payload, int $contatoId, string $igsid, ?array $conta): bool
    {
        if (!preg_match('/^ig_segui_(\d+)$/', $payload, $m)) return false;
        $regraId = (int)$m[1];

        $regra = $this->regra($regraId);
        if (!$regra || !$conta) return true;   // payload era nosso, mas não há o que entregar

        // Perfil atualizado: é isto que diz se seguiu mesmo
        $this->enriquecerPerfil($contatoId, $igsid, $conta);

        $st = $this->db->prepare("SELECT ig_seguidor FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $contatoId]);
        $segue = $st->fetchColumn();

        $cli = ChatInstagramClient::daConta($conta);
        $env = new ChatEnvioService($this->db);

        // NULL = a Meta não devolveu o campo. Punir quem talvez tenha seguido
        // custa mais caro do que entregar para quem não seguiu.
        if ($segue !== null && (int)$segue === 0) {
            $msg = trim((string)($regra['mensagem_nao_seguidor'] ?? ''))
                ?: 'Ainda não consegui te ver na lista de seguidores 👀 Me segue e toca no botão de novo!';
            try {
                $cli->enviarRespostasRapidas($igsid, $msg, [
                    ['titulo' => 'Já segui!', 'payload' => 'ig_segui_' . $regraId],
                ]);
            } catch (Throwable $e) {}
            return true;
        }

        $mensagem = trim((string)($regra['mensagem_dm'] ?? '')) ?: 'Aqui está o que você pediu:';
        $mensagem = ChatContatoService::interpolar($mensagem, [
            'usuario'       => (string)($this->contatos->obter($contatoId)['ig_username'] ?? ''),
            'primeiro_nome' => (string)($this->contatos->obter($contatoId)['nome_exibicao'] ?? ''),
            'saudacao'      => $this->saudacao(),
            'site_nome'     => defined('SITE_NAME') ? SITE_NAME : '',
        ]);
        $mensagem = $this->anexarLink($mensagem, $regra, $contatoId, null);

        $env->texto($contatoId, $mensagem, [
            'origem'    => 'comentario_ig',
            'origem_id' => $regraId,
        ]);

        (new ChatIgAutomacaoService($this->db))->registrarEnvio($regraId);
        $this->contatos->setCampo($contatoId, '_ig_pendente', null);

        if (!empty($regra['tag_id'])) {
            $this->contatos->aplicarTag($contatoId, (int)$regra['tag_id']);
        }
        if (!empty($regra['fluxo_id'])) {
            try {
                (new ChatFluxoMotor($this->db))->iniciar((int)$regra['fluxo_id'], $contatoId, [
                    '_origem' => 'comentario_ig', '_regra_id' => $regraId,
                ]);
            } catch (Throwable $e) {}
        }
        return true;
    }

    // =========================================================================
    // CONTATOS DO INSTAGRAM
    // =========================================================================

    /**
     * Garante o contato do IG. Diferente do WhatsApp: o identificador é o
     * IGSID, e o @handle vai numa coluna própria.
     */
    public function garantirContato(string $igsid, string $username = '', ?array $conta = null): int
    {
        $st = $this->db->prepare(
            "INSERT INTO chat_contatos (canal, wa_id, nome_perfil, ig_username, ig_conta_id, origem)
             VALUES ('instagram', :w, :n, :u, :c, 'instagram')
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                ig_username = COALESCE(NULLIF(VALUES(ig_username), ''), ig_username),
                nome_perfil = COALESCE(NULLIF(VALUES(nome_perfil), ''), nome_perfil)"
        );
        $st->execute([
            ':w' => $igsid,
            ':n' => $username ? mb_substr($username, 0, 120) : null,
            ':u' => $username ? mb_substr($username, 0, 120) : null,
            ':c' => $conta ? (int)$conta['id'] : null,
        ]);

        $contatoId = (int)$this->db->lastInsertId();
        if ($contatoId > 0) $this->conversas->garantir($contatoId, 'instagram');
        return $contatoId;
    }

    /**
     * Registra entrada de DM: renova a janela de 24h E a janela humana de 7
     * dias (a tag HUMAN_AGENT), que são regras diferentes no Instagram.
     */
    public function registrarEntradaDm(string $igsid, string $username, ?array $conta): array
    {
        $horas    = max(1, ChatConfig::int('janela_horas', 24));
        $diasHum  = max(1, ChatConfig::int('ig_janela_humana_dias', 7));

        $this->garantirContato($igsid, $username, $conta);

        $this->db->prepare(
            "UPDATE chat_contatos
             SET janela_expira_em  = DATE_ADD(NOW(), INTERVAL :h HOUR),
                 janela_humana_ate = DATE_ADD(NOW(), INTERVAL :d DAY),
                 ultima_entrada_em = NOW(),
                 total_entrada     = total_entrada + 1
             WHERE canal = 'instagram' AND wa_id = :w"
        )->execute([':h' => $horas, ':d' => $diasHum, ':w' => $igsid]);

        $c = $this->contatos->obterPorWaId($igsid, 'instagram');
        return $c ?: [];
    }

    /** Enriquece o contato com dados do perfil (nome, foto, se segue). */
    public function enriquecerPerfil(int $contatoId, string $igsid, array $conta): void
    {
        try {
            $p = ChatInstagramClient::daConta($conta)->perfilDoUsuario($igsid);
            if (!$p) return;

            $this->db->prepare(
                "UPDATE chat_contatos
                 SET nome_perfil = COALESCE(NULLIF(:n, ''), nome_perfil),
                     ig_username = COALESCE(NULLIF(:u, ''), ig_username),
                     avatar_url  = COALESCE(NULLIF(:f, ''), avatar_url),
                     ig_seguidor = :s
                 WHERE id = :id"
            )->execute([
                ':n'  => (string)($p['name'] ?? ''),
                ':u'  => (string)($p['username'] ?? ''),
                ':f'  => (string)($p['profile_pic'] ?? ''),
                ':s'  => isset($p['is_user_follow_business']) ? (int)$p['is_user_follow_business'] : null,
                ':id' => $contatoId,
            ]);
        } catch (Throwable $e) {
            // enriquecimento é opcional; a conversa funciona sem ele
        }
    }

    private function registrarMensagemSaida(
        int $contatoId, string $texto, string $mid, string $commentId, int $regraId
    ): void {
        $cv = $this->conversas->obterPorContato($contatoId, 'instagram')
           ?: $this->conversas->garantir($contatoId, 'instagram');
        if (empty($cv['id'])) return;

        $id = $this->mensagens->gravar([
            'conversa_id' => (int)$cv['id'],
            'contato_id'  => $contatoId,
            'direcao'     => 'saida',
            'tipo'        => 'text',
            'texto'       => $texto,
            'wamid'       => $mid ?: null,
            'status'      => 'enviado',
            'origem'      => 'comentario_ig',
            'origem_id'   => $regraId,
        ]);

        if ($id && $commentId !== '') {
            $this->db->prepare("UPDATE chat_mensagens SET comment_id = :c WHERE id = :id")
                     ->execute([':c' => $commentId, ':id' => $id]);
        }
    }

    // =========================================================================
    // RELATÓRIO
    // =========================================================================

    public function comentariosRecentes(array $f = [], int $limite = 50): array
    {
        $w = ['1=1']; $p = [];

        if (!empty($f['regra_id'])) { $w[] = 'k.regra_id = :r';  $p[':r'] = (int)$f['regra_id']; }
        if (!empty($f['media_id'])) { $w[] = 'k.media_id = :m';  $p[':m'] = (string)$f['media_id']; }
        if (!empty($f['so_dm']))    { $w[] = 'k.dm_enviado = 1'; }
        if (!empty($f['so_erro']))  { $w[] = 'k.dm_erro IS NOT NULL'; }

        $st = $this->db->prepare(
            "SELECT k.*, r.nome AS regra_nome, m.permalink, m.thumb_url
             FROM chat_ig_comentarios k
             LEFT JOIN chat_ig_regras r ON r.id = k.regra_id
             LEFT JOIN chat_ig_midias m ON m.media_id = k.media_id
             WHERE " . implode(' AND ', $w) . "
             ORDER BY k.id DESC LIMIT " . max(1, min(200, $limite))
        );
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function kpis(): array
    {
        $out = [
            'contas' => 0, 'contatos' => 0, 'comentarios_hoje' => 0,
            'dms_hoje' => 0, 'regras_ativas' => 0, 'falhas_hoje' => 0,
        ];
        try {
            $q = fn(string $sql) => (int)$this->db->query($sql)->fetchColumn();
            $out['contas']            = $q("SELECT COUNT(*) FROM chat_ig_contas WHERE ativo = 1");
            $out['contatos']          = $q("SELECT COUNT(*) FROM chat_contatos WHERE canal = 'instagram'");
            $out['comentarios_hoje']  = $q("SELECT COUNT(*) FROM chat_ig_comentarios WHERE DATE(criado_em) = CURDATE()");
            $out['dms_hoje']          = $q("SELECT COUNT(*) FROM chat_ig_comentarios WHERE dm_enviado = 1 AND DATE(criado_em) = CURDATE()");
            $out['regras_ativas']     = $q("SELECT COUNT(*) FROM chat_ig_regras WHERE ativo = 1");
            $out['falhas_hoje']       = $q("SELECT COUNT(*) FROM chat_ig_comentarios WHERE dm_erro IS NOT NULL AND DATE(criado_em) = CURDATE()");
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * Top seguidores por nível de interação.
     *
     * IMPORTANTE — o que este número É e o que NÃO É:
     * conta apenas o que passa pelo nosso sistema — comentários recebidos e
     * mensagens no direct. Curtida, salvamento, compartilhamento e visualização
     * de story NÃO entram: a API do Instagram não expõe esses dados por
     * seguidor. Então o ranking daqui não bate com o do Business Suite da Meta,
     * e não deveria mesmo — são medidas diferentes.
     *
     * Peso: um direct vale mais que um comentário porque exige mais intenção
     * (a pessoa saiu do feed e abriu conversa).
     *
     * @return array lista ordenada por pontos, com detalhamento
     */
    public function topSeguidores(int $dias = 7, int $limite = 10): array
    {
        $dias  = max(1, min(90, $dias));
        $desde = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));

        $PESO_COMENTARIO = 1;
        $PESO_MENSAGEM   = 2;

        $pessoas = [];

        try {
            // ── Comentários ──
            // Agrupa por IGSID; quem comentou sem virar contato entra do mesmo jeito
            $st = $this->db->prepare(
                "SELECT k.from_ig_id AS ig_id,
                        MAX(k.from_username) AS username,
                        COUNT(*) AS comentarios,
                        MAX(k.criado_em) AS ultima,
                        MAX(k.contato_id) AS contato_id
                 FROM chat_ig_comentarios k
                 WHERE k.criado_em >= :d AND k.from_ig_id IS NOT NULL AND k.from_ig_id <> ''
                 GROUP BY k.from_ig_id"
            );
            $st->execute([':d' => $desde]);

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pessoas[$r['ig_id']] = [
                    'ig_id'       => (string)$r['ig_id'],
                    'username'    => (string)($r['username'] ?? ''),
                    'contato_id'  => $r['contato_id'] ? (int)$r['contato_id'] : null,
                    'comentarios' => (int)$r['comentarios'],
                    'mensagens'   => 0,
                    'ultima'      => (string)$r['ultima'],
                ];
            }

            // ── Mensagens recebidas no direct ──
            $st = $this->db->prepare(
                "SELECT c.wa_id AS ig_id, c.id AS contato_id,
                        MAX(c.ig_username) AS username,
                        COUNT(*) AS mensagens,
                        MAX(m.criado_em) AS ultima
                 FROM chat_mensagens m
                 JOIN chat_conversas cv ON cv.id = m.conversa_id
                 JOIN chat_contatos  c  ON c.id  = m.contato_id
                 WHERE cv.canal = 'instagram' AND m.direcao = 'entrada' AND m.criado_em >= :d
                 GROUP BY c.wa_id, c.id"
            );
            $st->execute([':d' => $desde]);

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $id = (string)$r['ig_id'];
                if (isset($pessoas[$id])) {
                    $pessoas[$id]['mensagens']  = (int)$r['mensagens'];
                    $pessoas[$id]['contato_id'] = (int)$r['contato_id'];
                    if ($r['ultima'] > $pessoas[$id]['ultima']) $pessoas[$id]['ultima'] = (string)$r['ultima'];
                    if ($pessoas[$id]['username'] === '') $pessoas[$id]['username'] = (string)($r['username'] ?? '');
                } else {
                    $pessoas[$id] = [
                        'ig_id'       => $id,
                        'username'    => (string)($r['username'] ?? ''),
                        'contato_id'  => (int)$r['contato_id'],
                        'comentarios' => 0,
                        'mensagens'   => (int)$r['mensagens'],
                        'ultima'      => (string)$r['ultima'],
                    ];
                }
            }

            if (!$pessoas) return [];

            // ── Enriquece com nome e foto de quem virou contato ──
            $ids = array_keys($pessoas);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $stC = $this->db->prepare(
                "SELECT id, wa_id, nome, nome_perfil, ig_username, avatar_url, ig_seguidor, cliente_id
                 FROM chat_contatos WHERE canal = 'instagram' AND wa_id IN ($ph)"
            );
            $stC->execute($ids);

            foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $id = (string)$c['wa_id'];
                if (!isset($pessoas[$id])) continue;
                $pessoas[$id]['contato_id'] = (int)$c['id'];
                $pessoas[$id]['nome']       = $c['nome'] ?: ($c['nome_perfil'] ?: '');
                $pessoas[$id]['avatar']     = $c['avatar_url'] ?: null;
                $pessoas[$id]['seguidor']   = $c['ig_seguidor'] !== null ? (int)$c['ig_seguidor'] : null;
                $pessoas[$id]['cliente_id'] = $c['cliente_id'] ? (int)$c['cliente_id'] : null;
                if ($pessoas[$id]['username'] === '') $pessoas[$id]['username'] = (string)($c['ig_username'] ?? '');
            }
        } catch (Throwable $e) {
            $this->logar('error', 'ig: falha no top de seguidores', ['erro' => $e->getMessage()]);
            return [];
        }

        // ── Pontuação e ordenação ──
        foreach ($pessoas as $id => $p) {
            $pessoas[$id]['pontos'] = $p['comentarios'] * $PESO_COMENTARIO
                                    + $p['mensagens']   * $PESO_MENSAGEM;
            $pessoas[$id]['nome']   = $p['nome']   ?? '';
            $pessoas[$id]['avatar'] = $p['avatar'] ?? null;
            $pessoas[$id]['seguidor']   = $p['seguidor']   ?? null;
            $pessoas[$id]['cliente_id'] = $p['cliente_id'] ?? null;
        }

        usort($pessoas, function ($a, $b) {
            // Empate desempata por interação mais recente — quem falou ontem
            // interessa mais que quem falou há três semanas
            if ($b['pontos'] !== $a['pontos']) return $b['pontos'] <=> $a['pontos'];
            return strcmp((string)$b['ultima'], (string)$a['ultima']);
        });

        return array_slice(array_values($pessoas), 0, max(1, min(100, $limite)));
    }

    /** Simulador: qual regra pegaria este comentário? */
    public function simular(string $texto, string $mediaId = ''): array
    {
        $regra = $this->acharRegra($texto, $mediaId, null, false, '');
        if (!$regra) return ['casou' => false, 'motivo' => 'Nenhuma regra ativa casa com esse comentário.'];

        $acoes = [];
        if ((int)$regra['responder_publico'] === 1) $acoes[] = 'responde no comentário';
        if ((int)$regra['enviar_dm'] === 1)         $acoes[] = 'manda DM';
        if (!empty($regra['fluxo_id']))             $acoes[] = 'inicia fluxo';
        if (!empty($regra['tag_id']))               $acoes[] = 'aplica tag';

        return [
            'casou'  => true,
            'regra'  => ['id' => (int)$regra['id'], 'nome' => $regra['nome']],
            'acoes'  => $acoes,
        ];
    }

    // =========================================================================
    // AUXILIARES
    // =========================================================================

    private function marcarProcessado(int $id): void
    {
        try {
            $this->db->prepare("UPDATE chat_ig_comentarios SET processado = 1 WHERE id = :id")
                     ->execute([':id' => $id]);
        } catch (Throwable $e) {}
    }

    private function registrarDisparo(int $regraId): void
    {
        try {
            $this->db->prepare(
                "UPDATE chat_ig_regras
                 SET total_disparos = total_disparos + 1, ultimo_disparo_em = NOW()
                 WHERE id = :id"
            )->execute([':id' => $regraId]);
        } catch (Throwable $e) {}
    }

    private function saudacao(): string
    {
        $h = (int)date('G');
        if ($h < 12) return 'Bom dia';
        if ($h < 18) return 'Boa tarde';
        return 'Boa noite';
    }

    private function logar(string $nivel, string $msg, array $ctx = []): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::$nivel($msg, $ctx, 'chat'); } catch (Throwable $e) {}
    }
}
