<?php
/**
 * app/services/ChatIgAutomacaoService.php
 *
 * Administração das automações de Instagram: pastas, propriedade, receitas,
 * métricas e links rastreados.
 *
 * VISIBILIDADE POR LINHA (mesmo padrão da Central de Recuperação, §4.7 do
 * CLAUDE.md): cada automação tem dono. Quem não é gestor vê só as suas e as
 * sem dono; gestor vê tudo. As três camadas do padrão estão aqui:
 *   1. o SQL filtra a listagem
 *   2. podeAcessar() é chamado em TODA ação que recebe {id}
 *   3. quem não pode acessar recebe 404, nunca 403 — confirmar que o recurso
 *      existe já vaza informação, e com id sequencial dá para enumerar o
 *      trabalho dos colegas
 *
 * O runtime (casar comentário, enviar DM) continua no ChatInstagramService.
 * Aqui é só o lado do painel.
 */
class ChatIgAutomacaoService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // VISIBILIDADE
    // =========================================================================

    /** Gestor enxerga e edita tudo. */
    public function ehGestor(): bool
    {
        return class_exists('AuthHelper') && AuthHelper::hasLevel('super', 'gerente');
    }

    private function usuarioId(): int
    {
        return class_exists('AuthHelper') ? AuthHelper::usuarioId() : 0;
    }

    /**
     * Fragmento de WHERE que aplica a visibilidade.
     * @return array{0:string,1:array} [sql, params]
     */
    private function escopo(string $alias = 'r'): array
    {
        if ($this->ehGestor()) return ['1=1', []];

        $uid = $this->usuarioId();
        // Automação sem dono é do time — visível para todos que operam
        return ["($alias.usuario_id = :escopo_uid OR $alias.usuario_id IS NULL)", [':escopo_uid' => $uid]];
    }

    /** O usuário atual pode mexer nesta automação? */
    public function podeAcessar(array $automacao): bool
    {
        if ($this->ehGestor()) return true;
        $dono = (int)($automacao['usuario_id'] ?? 0);
        return $dono === 0 || $dono === $this->usuarioId();
    }

    // =========================================================================
    // PASTAS
    // =========================================================================

    public function pastas(): array
    {
        [$where, $p] = $this->ehGestor()
            ? ['1=1', []]
            : ['(f.usuario_id = :uid OR f.usuario_id IS NULL)', [':uid' => $this->usuarioId()]];

        $st = $this->db->prepare(
            "SELECT f.*, u.nome AS dono_nome,
                    (SELECT COUNT(*) FROM chat_ig_regras r
                     WHERE r.pasta_id = f.id AND r.excluido_em IS NULL) AS total
             FROM chat_ig_pastas f
             LEFT JOIN usuarios u ON u.id = f.usuario_id
             WHERE $where
             ORDER BY f.ordem, f.nome"
        );
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pasta(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_ig_pastas WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array{ok:bool, id?:int, erro?:string} */
    public function salvarPasta(string $nome, string $cor = '#64748b', ?int $id = null): array
    {
        $nome = trim($nome);
        if ($nome === '') return ['ok' => false, 'erro' => 'Informe o nome da pasta.'];
        if (!preg_match('/^#[0-9a-f]{6}$/i', $cor)) $cor = '#64748b';

        try {
            if ($id) {
                $atual = $this->pasta($id);
                if (!$atual) return ['ok' => false, 'erro' => 'Pasta não encontrada.'];
                if (!$this->ehGestor() && (int)($atual['usuario_id'] ?? 0) !== $this->usuarioId()) {
                    return ['ok' => false, 'erro' => 'Pasta não encontrada.'];   // 404 disfarçado
                }
                $this->db->prepare("UPDATE chat_ig_pastas SET nome = :n, cor = :c WHERE id = :id")
                         ->execute([':n' => mb_substr($nome, 0, 80), ':c' => $cor, ':id' => $id]);
                return ['ok' => true, 'id' => $id];
            }

            $this->db->prepare(
                "INSERT INTO chat_ig_pastas (nome, cor, usuario_id, ordem)
                 VALUES (:n, :c, :u, (SELECT COALESCE(MAX(o.ordem), 0) + 1 FROM (SELECT ordem FROM chat_ig_pastas) o))"
            )->execute([
                ':n' => mb_substr($nome, 0, 80),
                ':c' => $cor,
                ':u' => $this->usuarioId() ?: null,
            ]);
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao salvar: ' . $e->getMessage()];
        }
    }

    /** Apagar pasta NÃO apaga automação — elas voltam para "sem pasta". */
    public function excluirPasta(int $id): array
    {
        $pasta = $this->pasta($id);
        if (!$pasta) return ['ok' => false, 'erro' => 'Pasta não encontrada.'];
        if (!$this->ehGestor() && (int)($pasta['usuario_id'] ?? 0) !== $this->usuarioId()) {
            return ['ok' => false, 'erro' => 'Pasta não encontrada.'];
        }

        $this->db->prepare("UPDATE chat_ig_regras SET pasta_id = NULL WHERE pasta_id = :id")
                 ->execute([':id' => $id]);
        $this->db->prepare("DELETE FROM chat_ig_pastas WHERE id = :id")->execute([':id' => $id]);
        return ['ok' => true];
    }

    public function moverParaPasta(int $automacaoId, ?int $pastaId): array
    {
        $a = $this->obter($automacaoId);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        if ($pastaId) {
            $p = $this->pasta($pastaId);
            if (!$p) return ['ok' => false, 'erro' => 'Pasta não encontrada.'];
        }

        $this->db->prepare("UPDATE chat_ig_regras SET pasta_id = :p WHERE id = :id")
                 ->execute([':p' => $pastaId ?: null, ':id' => $automacaoId]);
        return ['ok' => true];
    }

    // =========================================================================
    // LISTAGEM
    // =========================================================================

    /**
     * @param array $f busca, gatilho, status, pasta_id, lixeira
     * @return array{itens:array, total:int}
     */
    public function listar(array $f = [], int $pagina = 1, int $porPagina = 25): array
    {
        [$escopo, $p] = $this->escopo();
        $w = [$escopo];

        $w[] = !empty($f['lixeira']) ? 'r.excluido_em IS NOT NULL' : 'r.excluido_em IS NULL';

        if (!empty($f['busca'])) {
            $w[] = '(r.nome LIKE :busca OR r.palavras LIKE :busca2)';
            $p[':busca'] = '%' . trim((string)$f['busca']) . '%';
            $p[':busca2'] = $p[':busca'];
        }
        if (!empty($f['gatilho'])) {
            $w[] = 'r.gatilho_tipo = :gat';
            $p[':gat'] = (string)$f['gatilho'];
        }
        if (!empty($f['status']) && in_array($f['status'], ['rascunho', 'ativa', 'parada'], true)) {
            $w[] = 'r.status = :st';
            $p[':st'] = $f['status'];
        }
        if (isset($f['pasta_id']) && $f['pasta_id'] !== '' && $f['pasta_id'] !== null) {
            if ((int)$f['pasta_id'] === 0) {
                $w[] = 'r.pasta_id IS NULL';
            } else {
                $w[] = 'r.pasta_id = :pasta';
                $p[':pasta'] = (int)$f['pasta_id'];
            }
        }

        $where     = 'WHERE ' . implode(' AND ', $w);
        $porPagina = max(1, min(100, $porPagina));
        $offset    = (max(1, $pagina) - 1) * $porPagina;

        $stT = $this->db->prepare("SELECT COUNT(*) FROM chat_ig_regras r $where");
        $stT->execute($p);
        $total = (int)$stT->fetchColumn();

        $st = $this->db->prepare(
            "SELECT r.*, u.nome AS dono_nome, pa.nome AS pasta_nome, pa.cor AS pasta_cor,
                    f.nome AS fluxo_nome, t.nome AS tag_nome, t.cor AS tag_cor,
                    c.username AS conta_username
             FROM chat_ig_regras r
             LEFT JOIN usuarios u        ON u.id = r.usuario_id
             LEFT JOIN chat_ig_pastas pa ON pa.id = r.pasta_id
             LEFT JOIN chat_fluxos f     ON f.id = r.fluxo_id
             LEFT JOIN chat_tags t       ON t.id = r.tag_id
             LEFT JOIN chat_ig_contas c  ON c.id = r.conta_id
             $where
             ORDER BY r.atualizado_em DESC, r.id DESC
             LIMIT $porPagina OFFSET $offset"
        );
        $st->execute($p);

        $itens = array_map([$this, 'hidratar'], $st->fetchAll(PDO::FETCH_ASSOC));
        return ['itens' => $itens, 'total' => $total];
    }

    /** Acrescenta o que a listagem exibe e o service calcula. */
    private function hidratar(array $a): array
    {
        $envios  = (int)$a['total_envios'];
        $cliques = (int)$a['total_cliques'];

        $a['ctr']            = $envios > 0 ? round(($cliques / $envios) * 100, 1) : null;
        $a['midias']         = json_decode($a['midias_json'] ?? '[]', true) ?: [];
        $a['receita_meta']   = ChatIgReceitaService::obter((string)$a['receita']);
        $a['gatilho_rotulo'] = ChatIgReceitaService::rotuloGatilho((string)$a['gatilho_tipo']);
        $a['gatilho_icone']  = ChatIgReceitaService::iconeGatilho((string)$a['gatilho_tipo']);
        return $a;
    }

    /** Contadores das abas de status. */
    public function contadores(): array
    {
        [$escopo, $p] = $this->escopo();
        $out = ['todas' => 0, 'ativa' => 0, 'rascunho' => 0, 'parada' => 0, 'lixeira' => 0];

        try {
            $st = $this->db->prepare(
                "SELECT r.status, COUNT(*) n FROM chat_ig_regras r
                 WHERE $escopo AND r.excluido_em IS NULL GROUP BY r.status"
            );
            $st->execute($p);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$row['status']] = (int)$row['n'];
                $out['todas'] += (int)$row['n'];
            }

            $stL = $this->db->prepare(
                "SELECT COUNT(*) FROM chat_ig_regras r WHERE $escopo AND r.excluido_em IS NOT NULL"
            );
            $stL->execute($p);
            $out['lixeira'] = (int)$stL->fetchColumn();
        } catch (Throwable $e) {}

        return $out;
    }

    /** Uma automação, já com a visibilidade aplicada. NULL = não existe ou não é sua. */
    public function obter(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT r.*, u.nome AS dono_nome, pa.nome AS pasta_nome, pa.cor AS pasta_cor,
                    f.nome AS fluxo_nome, t.nome AS tag_nome, t.cor AS tag_cor,
                    c.username AS conta_username
             FROM chat_ig_regras r
             LEFT JOIN usuarios u        ON u.id = r.usuario_id
             LEFT JOIN chat_ig_pastas pa ON pa.id = r.pasta_id
             LEFT JOIN chat_fluxos f     ON f.id = r.fluxo_id
             LEFT JOIN chat_tags t       ON t.id = r.tag_id
             LEFT JOIN chat_ig_contas c  ON c.id = r.conta_id
             WHERE r.id = :id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return null;

        // Sem permissão devolve NULL, e o controller responde 404 — quem não
        // pode ver nem descobre que o registro existe
        if (!$this->podeAcessar($a)) return null;

        return $this->hidratar($a);
    }

    // =========================================================================
    // CRIAÇÃO E EDIÇÃO
    // =========================================================================

    /** Cria a partir de uma receita, já com os textos-modelo preenchidos. */
    public function criarDaReceita(string $receita, ?string $nome = null, ?int $pastaId = null): array
    {
        if (!ChatIgReceitaService::existe($receita)) $receita = 'zero';

        $meta   = ChatIgReceitaService::obter($receita);
        $padrao = ChatIgReceitaService::padraoDe($receita);
        $nome   = trim((string)$nome) ?: $meta['nome'];

        try {
            $this->db->prepare(
                "INSERT INTO chat_ig_regras
                    (nome, receita, gatilho_tipo, pasta_id, usuario_id, escopo, palavras,
                     modo_match, responder_publico, resposta_publica, enviar_dm, mensagem_dm,
                     exigir_seguidor, mensagem_nao_seguidor, link_texto, prioridade,
                     ativo, status, ignorar_proprios, uma_vez_por_pessoa)
                 VALUES
                    (:nome, :rec, :gat, :pasta, :user, :escopo, :pal,
                     :modo, :rp, :rptxt, :dm, :dmtxt,
                     :seg, :segtxt, :ltxt, :prio,
                     0, 'rascunho', :ip, :uma)"
            )->execute([
                ':nome'  => mb_substr($nome, 0, 140),
                ':rec'   => $receita,
                ':gat'   => $padrao['gatilho_tipo'],
                ':pasta' => $pastaId ?: null,
                ':user'  => $this->usuarioId() ?: null,
                ':escopo'=> $padrao['escopo'] ?? 'todas',
                ':pal'   => $padrao['palavras'] ?? null,
                ':modo'  => $padrao['modo_match'] ?? 'contem',
                ':rp'    => (int)($padrao['responder_publico'] ?? 0),
                ':rptxt' => $padrao['resposta_publica'] ?? null,
                ':dm'    => (int)($padrao['enviar_dm'] ?? 1),
                ':dmtxt' => $padrao['mensagem_dm'] ?? null,
                ':seg'   => (int)($padrao['exigir_seguidor'] ?? 0),
                ':segtxt'=> $padrao['mensagem_nao_seguidor'] ?? null,
                ':ltxt'  => $padrao['link_texto'] ?? null,
                ':prio'  => (int)($padrao['prioridade'] ?? 50),
                ':ip'    => (int)($padrao['ignorar_proprios'] ?? 1),
                ':uma'   => (int)($padrao['uma_vez_por_pessoa'] ?? 0),
            ]);
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao criar: ' . $e->getMessage()];
        }
    }

    /**
     * Salva a edição. Valida contra os campos que a RECEITA declara — pedir
     * resposta pública numa automação de story, que não tem comentário para
     * responder, seria erro do formulário, não do usuário.
     *
     * @return array{ok:bool, erro?:string}
     */
    public function salvar(int $id, array $d): array
    {
        $a = $this->obter($id);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        $receita = (string)$a['receita'];
        $usa = fn(string $campo) => ChatIgReceitaService::usaCampo($receita, $campo);

        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '') return ['ok' => false, 'erro' => 'Informe o nome da automação.'];

        $escopo = in_array($d['escopo'] ?? '', ['todas', 'midia', 'novas'], true) ? $d['escopo'] : 'todas';
        $modo   = in_array($d['modo_match'] ?? '', ['exato', 'contem', 'comeca', 'regex'], true) ? $d['modo_match'] : 'contem';

        $midias = array_values(array_filter(array_map('strval', (array)($d['midias'] ?? []))));
        if ($escopo === 'midia' && !$midias) {
            return ['ok' => false, 'erro' => 'Selecione pelo menos uma publicação.'];
        }

        $palavras = trim((string)($d['palavras'] ?? ''));
        if ($modo === 'regex' && $palavras !== ''
            && @preg_match('#' . str_replace('#', '\#', $palavras) . '#iu', '') === false) {
            return ['ok' => false, 'erro' => 'A expressão regular informada é inválida.'];
        }

        $enviarDm = $usa('mensagem_dm') && !empty($d['enviar_dm']);
        $respPub  = $usa('resposta_publica') && !empty($d['responder_publico']);
        $fluxoId  = $usa('fluxo') ? ((int)($d['fluxo_id'] ?? 0) ?: null) : null;
        $tagId    = $usa('tag')   ? ((int)($d['tag_id'] ?? 0) ?: null) : null;

        if (!$enviarDm && !$respPub && !$fluxoId && !$tagId) {
            return ['ok' => false, 'erro' => 'A automação precisa fazer pelo menos uma coisa: responder, mandar direct, iniciar fluxo ou aplicar tag.'];
        }
        if ($enviarDm && trim((string)($d['mensagem_dm'] ?? '')) === '' && !$fluxoId) {
            return ['ok' => false, 'erro' => 'Escreva a mensagem do direct ou escolha um fluxo.'];
        }
        if ($respPub && trim((string)($d['resposta_publica'] ?? '')) === '') {
            return ['ok' => false, 'erro' => 'Escreva a resposta pública.'];
        }

        $exigirSeg = $usa('exigir_seguidor') && !empty($d['exigir_seguidor']);
        if ($exigirSeg && trim((string)($d['mensagem_nao_seguidor'] ?? '')) === '') {
            return ['ok' => false, 'erro' => 'Escreva a mensagem para quem ainda não segue o perfil.'];
        }

        $link = trim((string)($d['link_destino'] ?? ''));
        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            return ['ok' => false, 'erro' => 'O link precisa começar com http:// ou https://.'];
        }

        try {
            $this->db->prepare(
                "UPDATE chat_ig_regras SET
                    nome = :nome, pasta_id = :pasta, conta_id = :conta,
                    escopo = :escopo, midias_json = :mj, palavras = :pal, modo_match = :modo,
                    ignorar_proprios = :ip, ignorar_respostas = :ir,
                    responder_publico = :rp, resposta_publica = :rptxt,
                    enviar_dm = :dm, mensagem_dm = :dmtxt,
                    exigir_seguidor = :seg, mensagem_nao_seguidor = :segtxt,
                    link_destino = :link, link_texto = :ltxt,
                    pedir_email = :pe, mensagem_email = :petxt,
                    fluxo_id = :fid, tag_id = :tid,
                    prioridade = :prio, uma_vez_por_pessoa = :uma,
                    atualizado_por = :por
                 WHERE id = :id"
            )->execute([
                ':nome'  => mb_substr($nome, 0, 140),
                ':pasta' => (int)($d['pasta_id'] ?? 0) ?: null,
                ':conta' => (int)($d['conta_id'] ?? 0) ?: null,
                ':escopo'=> $escopo,
                ':mj'    => $midias ? json_encode($midias, JSON_UNESCAPED_UNICODE) : null,
                ':pal'   => mb_substr($palavras, 0, 400) ?: null,
                ':modo'  => $modo,
                ':ip'    => (int)!empty($d['ignorar_proprios']),
                ':ir'    => (int)!empty($d['ignorar_respostas']),
                ':rp'    => (int)$respPub,
                ':rptxt' => trim((string)($d['resposta_publica'] ?? '')) ?: null,
                ':dm'    => (int)$enviarDm,
                ':dmtxt' => trim((string)($d['mensagem_dm'] ?? '')) ?: null,
                ':seg'   => (int)$exigirSeg,
                ':segtxt'=> trim((string)($d['mensagem_nao_seguidor'] ?? '')) ?: null,
                ':link'  => $link ?: null,
                ':ltxt'  => mb_substr(trim((string)($d['link_texto'] ?? '')), 0, 60) ?: null,
                ':pe'    => (int)($usa('pedir_email') && !empty($d['pedir_email'])),
                ':petxt' => trim((string)($d['mensagem_email'] ?? '')) ?: null,
                ':fid'   => $fluxoId,
                ':tid'   => $tagId,
                ':prio'  => max(0, min(999, (int)($d['prioridade'] ?? 50))),
                ':uma'   => (int)!empty($d['uma_vez_por_pessoa']),
                ':por'   => $this->usuarioId() ?: null,
                ':id'    => $id,
            ]);
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao salvar: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // CICLO DE VIDA
    // =========================================================================

    /** @return array{ok:bool, status?:string, erro?:string} */
    public function mudarStatus(int $id, string $status): array
    {
        if (!in_array($status, ['rascunho', 'ativa', 'parada'], true)) {
            return ['ok' => false, 'erro' => 'Status inválido.'];
        }
        $a = $this->obter($id);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        // Ativar exige que a automação faça alguma coisa — senão fica "ligada"
        // consumindo comentário e não respondendo nada
        if ($status === 'ativa') {
            $faz = (int)$a['responder_publico'] || (int)$a['enviar_dm'] || $a['fluxo_id'] || $a['tag_id'];
            if (!$faz) return ['ok' => false, 'erro' => 'Configure pelo menos uma ação antes de ativar.'];

            if ((int)$a['enviar_dm'] === 1 && trim((string)$a['mensagem_dm']) === '' && !$a['fluxo_id']) {
                return ['ok' => false, 'erro' => 'Escreva a mensagem do direct antes de ativar.'];
            }
        }

        // `ativo` (0/1) segue espelhado: consultas antigas continuam válidas
        $this->db->prepare(
            "UPDATE chat_ig_regras SET status = :s, ativo = :a, atualizado_por = :por WHERE id = :id"
        )->execute([
            ':s'   => $status,
            ':a'   => $status === 'ativa' ? 1 : 0,
            ':por' => $this->usuarioId() ?: null,
            ':id'  => $id,
        ]);

        if (class_exists('LogService')) {
            try { LogService::audit('chat_ig_automacao_status', ['id' => $id, 'status' => $status]); }
            catch (Throwable $e) {}
        }
        return ['ok' => true, 'status' => $status];
    }

    public function duplicar(int $id): array
    {
        $a = $this->obter($id);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        try {
            $this->db->prepare(
                "INSERT INTO chat_ig_regras
                    (conta_id, pasta_id, usuario_id, nome, receita, gatilho_tipo, escopo,
                     midias_json, palavras, modo_match, ignorar_proprios, ignorar_respostas,
                     responder_publico, resposta_publica, enviar_dm, mensagem_dm,
                     exigir_seguidor, mensagem_nao_seguidor, link_destino, link_texto,
                     pedir_email, mensagem_email, fluxo_id, tag_id, prioridade,
                     uma_vez_por_pessoa, ativo, status)
                 SELECT conta_id, pasta_id, :user, CONCAT(nome, ' copy'), receita, gatilho_tipo, escopo,
                        midias_json, palavras, modo_match, ignorar_proprios, ignorar_respostas,
                        responder_publico, resposta_publica, enviar_dm, mensagem_dm,
                        exigir_seguidor, mensagem_nao_seguidor, link_destino, link_texto,
                        pedir_email, mensagem_email, fluxo_id, tag_id, prioridade,
                        uma_vez_por_pessoa, 0, 'rascunho'
                 FROM chat_ig_regras WHERE id = :id"
            )->execute([':user' => $this->usuarioId() ?: null, ':id' => $id]);

            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao duplicar: ' . $e->getMessage()];
        }
    }

    /** Lixeira: preserva o histórico de comentários que apontam para a regra. */
    public function excluir(int $id): array
    {
        $a = $this->obter($id);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        $this->db->prepare(
            "UPDATE chat_ig_regras SET excluido_em = NOW(), ativo = 0, status = 'parada' WHERE id = :id"
        )->execute([':id' => $id]);
        return ['ok' => true];
    }

    public function restaurar(int $id): array
    {
        $a = $this->obter($id);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        $this->db->prepare("UPDATE chat_ig_regras SET excluido_em = NULL WHERE id = :id")
                 ->execute([':id' => $id]);
        return ['ok' => true];
    }

    /** Apaga de vez. Só gestor — leva o vínculo do histórico junto. */
    public function excluirDefinitivo(int $id): array
    {
        if (!$this->ehGestor()) return ['ok' => false, 'erro' => 'Sem permissão.'];
        $a = $this->obter($id);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        $this->db->prepare("DELETE FROM chat_ig_regras WHERE id = :id")->execute([':id' => $id]);
        return ['ok' => true];
    }

    /** Transferir dono: só gestor (mexe em trabalho alheio). */
    public function transferir(int $id, ?int $novoDono): array
    {
        if (!$this->ehGestor()) return ['ok' => false, 'erro' => 'Sem permissão para transferir.'];
        $a = $this->obter($id);
        if (!$a) return ['ok' => false, 'erro' => 'Automação não encontrada.'];

        $this->db->prepare("UPDATE chat_ig_regras SET usuario_id = :u WHERE id = :id")
                 ->execute([':u' => $novoDono ?: null, ':id' => $id]);
        return ['ok' => true];
    }

    // =========================================================================
    // LINKS RASTREADOS (base do CTR)
    // =========================================================================

    /**
     * Gera o link curto de uma automação para um contato.
     * Reaproveita o link se já existe: o mesmo contato clicando duas vezes é
     * um clique único, não dois envios.
     */
    public function gerarLink(int $regraId, ?int $contatoId, string $urlDestino, ?string $commentId = null): ?string
    {
        $urlDestino = trim($urlDestino);
        if ($urlDestino === '' || !preg_match('#^https?://#i', $urlDestino)) return null;

        try {
            if ($contatoId) {
                $st = $this->db->prepare(
                    "SELECT token FROM chat_ig_links
                     WHERE regra_id = :r AND contato_id = :c AND url_destino = :u LIMIT 1"
                );
                $st->execute([':r' => $regraId, ':c' => $contatoId, ':u' => $urlDestino]);
                if ($t = $st->fetchColumn()) return $this->urlCurta((string)$t);
            }

            // 9 bytes → 12 chars em base64url: curto o bastante para caber na
            // mensagem e largo o bastante contra tentativa de adivinhação
            $token = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');

            $this->db->prepare(
                "INSERT INTO chat_ig_links (regra_id, contato_id, comment_id, token, url_destino)
                 VALUES (:r, :c, :cm, :t, :u)"
            )->execute([
                ':r' => $regraId, ':c' => $contatoId, ':cm' => $commentId,
                ':t' => $token,   ':u' => mb_substr($urlDestino, 0, 1000),
            ]);
            return $this->urlCurta($token);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function urlCurta(string $token): string
    {
        return (defined('BASE_URL') ? BASE_URL : '') . '/ir/' . $token;
    }

    /**
     * Registra o clique e devolve o destino.
     * Só o PRIMEIRO clique de cada link conta para o CTR — reclique da mesma
     * pessoa inflaria a métrica sem significar alcance novo.
     */
    public function registrarClique(string $token): ?string
    {
        try {
            $st = $this->db->prepare(
                "SELECT id, regra_id, url_destino, cliques FROM chat_ig_links WHERE token = :t LIMIT 1"
            );
            $st->execute([':t' => $token]);
            $link = $st->fetch(PDO::FETCH_ASSOC);
            if (!$link) return null;

            $primeiro = (int)$link['cliques'] === 0;

            $this->db->prepare(
                "UPDATE chat_ig_links
                 SET cliques = cliques + 1,
                     primeiro_clique_em = COALESCE(primeiro_clique_em, NOW()),
                     ultimo_clique_em = NOW()
                 WHERE id = :id"
            )->execute([':id' => (int)$link['id']]);

            if ($primeiro && $link['regra_id']) {
                $this->db->prepare(
                    "UPDATE chat_ig_regras SET total_cliques = total_cliques + 1 WHERE id = :r"
                )->execute([':r' => (int)$link['regra_id']]);
            }

            return (string)$link['url_destino'];
        } catch (Throwable $e) {
            return null;
        }
    }

    public function registrarEnvio(int $regraId): void
    {
        try {
            $this->db->prepare(
                "UPDATE chat_ig_regras SET total_envios = total_envios + 1 WHERE id = :id"
            )->execute([':id' => $regraId]);
        } catch (Throwable $e) {}
    }

    public function registrarEmail(int $regraId, ?int $contatoId, string $email): bool
    {
        $email = trim(mb_strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

        try {
            $st = $this->db->prepare(
                "INSERT IGNORE INTO chat_ig_emails (regra_id, contato_id, email)
                 VALUES (:r, :c, :e)"
            );
            $st->execute([':r' => $regraId, ':c' => $contatoId, ':e' => mb_substr($email, 0, 180)]);

            if ($st->rowCount() > 0) {
                $this->db->prepare(
                    "UPDATE chat_ig_regras SET total_emails = total_emails + 1 WHERE id = :id"
                )->execute([':id' => $regraId]);
                return true;
            }
        } catch (Throwable $e) {}
        return false;
    }

    // =========================================================================
    // INSIGHTS
    // =========================================================================

    /** Métricas de uma automação para a tela de insights. */
    public function insights(int $id, int $dias = 30): array
    {
        $a = $this->obter($id);
        if (!$a) return [];

        $dias  = max(1, min(365, $dias));
        $desde = date('Y-m-d 00:00:00', strtotime('-' . ($dias - 1) . ' days'));

        $envios  = (int)$a['total_envios'];
        $cliques = (int)$a['total_cliques'];

        $out = [
            'envios'   => $envios,
            'cliques'  => $cliques,
            'ctr'      => $envios > 0 ? round(($cliques / $envios) * 100, 1) : 0.0,
            'emails'   => (int)$a['total_emails'],
            'disparos' => (int)$a['total_disparos'],
            'serie'    => [],
            'comentarios' => ['total' => 0, 'com_dm' => 0, 'publicos' => 0, 'falhas' => 0],
            'ultimos'  => [],
        ];

        try {
            $st = $this->db->prepare(
                "SELECT COUNT(*) total,
                        SUM(dm_enviado = 1) com_dm,
                        SUM(respondido_publico = 1) publicos,
                        SUM(dm_erro IS NOT NULL) falhas
                 FROM chat_ig_comentarios WHERE regra_id = :r AND criado_em >= :d"
            );
            $st->execute([':r' => $id, ':d' => $desde]);
            if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                $out['comentarios'] = [
                    'total'    => (int)$c['total'],
                    'com_dm'   => (int)$c['com_dm'],
                    'publicos' => (int)$c['publicos'],
                    'falhas'   => (int)$c['falhas'],
                ];
            }

            // Série diária: comentários recebidos x directs enviados
            $stS = $this->db->prepare(
                "SELECT DATE(criado_em) dia, COUNT(*) comentarios, SUM(dm_enviado = 1) dms
                 FROM chat_ig_comentarios
                 WHERE regra_id = :r AND criado_em >= :d
                 GROUP BY DATE(criado_em)"
            );
            $stS->execute([':r' => $id, ':d' => $desde]);
            $porDia = [];
            foreach ($stS->fetchAll(PDO::FETCH_ASSOC) as $row) $porDia[$row['dia']] = $row;

            for ($i = $dias - 1; $i >= 0; $i--) {
                $dia = date('Y-m-d', strtotime("-$i days"));
                $out['serie'][] = [
                    'dia'         => $dia,
                    'rotulo'      => date('d/m', strtotime($dia)),
                    'comentarios' => (int)($porDia[$dia]['comentarios'] ?? 0),
                    'dms'         => (int)($porDia[$dia]['dms'] ?? 0),
                ];
            }

            $stU = $this->db->prepare(
                "SELECT k.comment_id, k.from_username, k.texto, k.dm_enviado,
                        k.respondido_publico, k.dm_erro, k.criado_em, m.permalink
                 FROM chat_ig_comentarios k
                 LEFT JOIN chat_ig_midias m ON m.media_id = k.media_id
                 WHERE k.regra_id = :r ORDER BY k.id DESC LIMIT 20"
            );
            $stU->execute([':r' => $id]);
            $out['ultimos'] = $stU->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        return $out;
    }

    /** Agentes que podem receber uma automação transferida. */
    public function donosPossiveis(): array
    {
        try {
            return $this->db->query(
                "SELECT u.id, u.nome FROM admins a
                 JOIN usuarios u ON u.id = a.usuario_id
                 WHERE u.ativo = 1 AND u.deleted_at IS NULL
                 ORDER BY u.nome"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
