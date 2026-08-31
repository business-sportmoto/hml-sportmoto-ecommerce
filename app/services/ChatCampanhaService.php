<?php
/**
 * app/services/ChatCampanhaService.php
 *
 * Broadcast para um segmento de contatos.
 *
 * FILA MATERIALIZADA: ao iniciar, o segmento vira linhas em
 * chat_campanha_destinatarios. Isso dá três coisas que uma query "ao vivo" não
 * daria — retomada exata após pausa/queda, relatório por destinatário, e
 * público congelado (quem entrar na base depois não recebe uma campanha que
 * já estava rodando).
 *
 * RITMO: `ritmo_por_minuto` limita a vazão. Despejar 5 mil mensagens de uma vez
 * estoura o rate limit da Meta e derruba a qualidade do número — o worker
 * consome a fila em lotes pequenos, várias passadas.
 *
 * TIPO texto vs template:
 *   · template → funciona sempre (é o único jeito fora da janela de 24h)
 *   · texto    → só alcança quem interagiu nas últimas 24h; os demais são
 *                marcados como 'pulado', não como falha
 */
class ChatCampanhaService
{
    private PDO $db;
    private ChatContatoService  $contatos;
    private ChatEnvioService    $envio;
    private ChatTemplateService $templates;

    public function __construct(?PDO $db = null)
    {
        $this->db        = $db ?? Database::getInstance()->getConnection();
        $this->contatos  = new ChatContatoService($this->db);
        $this->envio     = new ChatEnvioService($this->db);
        $this->templates = new ChatTemplateService($this->db);
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    public function listar(int $limite = 100): array
    {
        return $this->db->query(
            "SELECT c.*, f.nome AS fluxo_nome, u.nome AS autor
             FROM chat_campanhas c
             LEFT JOIN chat_fluxos f ON f.id = c.fluxo_id
             LEFT JOIN usuarios u ON u.id = c.criado_por
             ORDER BY c.id DESC LIMIT " . max(1, min(500, $limite))
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obter(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT c.*, f.nome AS fluxo_nome FROM chat_campanhas c
             LEFT JOIN chat_fluxos f ON f.id = c.fluxo_id
             WHERE c.id = :id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;

        $c['segmento']      = json_decode($c['segmento_json'] ?? '{}', true) ?: [];
        $c['template_vars'] = json_decode($c['template_vars_json'] ?? '{}', true) ?: [];
        return $c;
    }

    /** @return array{ok:bool, id?:int, erro?:string} */
    public function salvar(array $d, ?int $id = null, ?int $usuarioId = null): array
    {
        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '') return ['ok' => false, 'erro' => 'Informe o nome da campanha.'];

        $tipo = in_array($d['tipo'] ?? '', ['template', 'texto'], true) ? $d['tipo'] : 'template';

        if ($tipo === 'template') {
            $tplNome = trim((string)($d['template_nome'] ?? ''));
            if ($tplNome === '') return ['ok' => false, 'erro' => 'Selecione um template aprovado.'];

            $tpl = $this->templates->obter($tplNome, (string)($d['template_idioma'] ?? 'pt_BR'));
            if (!$tpl) return ['ok' => false, 'erro' => 'Template não encontrado. Sincronize os templates.'];
            if ($tpl['status'] !== 'APPROVED') {
                return ['ok' => false, 'erro' => "O template \"$tplNome\" não está aprovado (status: {$tpl['status']})."];
            }

            // Contagem de parâmetros errada = erro 132000 em CADA envio
            $body = array_values(array_filter((array)($d['vars_body'] ?? []), fn($v) => trim((string)$v) !== ''));
            if (count($body) < (int)$tpl['vars_body']) {
                return ['ok' => false, 'erro' => "Este template precisa de {$tpl['vars_body']} variável(is) no corpo; você preencheu " . count($body) . '.'];
            }
        } else {
            if (trim((string)($d['mensagem'] ?? '')) === '') {
                return ['ok' => false, 'erro' => 'Escreva a mensagem.'];
            }
        }

        $agendado = trim((string)($d['agendado_para'] ?? ''));
        $agendado = $agendado !== '' ? date('Y-m-d H:i:s', strtotime($agendado)) : null;

        $campos = [
            ':nome'  => mb_substr($nome, 0, 140),
            ':tipo'  => $tipo,
            ':tn'    => $tipo === 'template' ? mb_substr((string)$d['template_nome'], 0, 120) : null,
            ':ti'    => $tipo === 'template' ? mb_substr((string)($d['template_idioma'] ?? 'pt_BR'), 0, 12) : null,
            ':tv'    => json_encode([
                'body'   => array_values((array)($d['vars_body'] ?? [])),
                'header' => (string)($d['var_header'] ?? ''),
                'botao'  => (string)($d['var_botao'] ?? ''),
            ], JSON_UNESCAPED_UNICODE),
            ':msg'   => $tipo === 'texto' ? (string)$d['mensagem'] : null,
            ':fid'   => (int)($d['fluxo_id'] ?? 0) ?: null,
            ':seg'   => json_encode($this->normalizarSegmento((array)($d['segmento'] ?? [])), JSON_UNESCAPED_UNICODE),
            ':ag'    => $agendado,
            ':ritmo' => max(1, min(600, (int)($d['ritmo_por_minuto'] ?? 60))),
        ];

        try {
            if ($id) {
                $atual = $this->obter($id);
                // Editar campanha rodando bagunçaria a fila já materializada
                if ($atual && !in_array($atual['status'], ['rascunho', 'agendada', 'pausada'], true)) {
                    return ['ok' => false, 'erro' => 'Campanha já enviada não pode ser editada.'];
                }
                $campos[':id'] = $id;
                $this->db->prepare(
                    "UPDATE chat_campanhas SET
                        nome = :nome, tipo = :tipo, template_nome = :tn, template_idioma = :ti,
                        template_vars_json = :tv, mensagem = :msg, fluxo_id = :fid,
                        segmento_json = :seg, agendado_para = :ag, ritmo_por_minuto = :ritmo
                     WHERE id = :id"
                )->execute($campos);
                return ['ok' => true, 'id' => $id];
            }

            $campos[':cp'] = $usuarioId;
            $this->db->prepare(
                "INSERT INTO chat_campanhas
                    (nome, tipo, template_nome, template_idioma, template_vars_json,
                     mensagem, fluxo_id, segmento_json, agendado_para, ritmo_por_minuto, criado_por)
                 VALUES (:nome, :tipo, :tn, :ti, :tv, :msg, :fid, :seg, :ag, :ritmo, :cp)"
            )->execute($campos);
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => 'Falha ao salvar: ' . $e->getMessage()];
        }
    }

    /** Whitelist dos filtros aceitos — nada além disso vai para o JSON. */
    private function normalizarSegmento(array $s): array
    {
        return array_filter([
            'tags'          => array_values(array_filter(array_map('intval', (array)($s['tags'] ?? [])))),
            'tags_modo'     => in_array($s['tags_modo'] ?? '', ['qualquer', 'todas'], true) ? $s['tags_modo'] : 'qualquer',
            'tags_excluir'  => array_values(array_filter(array_map('intval', (array)($s['tags_excluir'] ?? [])))),
            'janela'        => in_array($s['janela'] ?? '', ['aberta', 'fechada'], true) ? $s['janela'] : null,
            'com_cliente'   => isset($s['com_cliente']) && $s['com_cliente'] !== '' ? (int)(bool)$s['com_cliente'] : null,
            'origem'        => trim((string)($s['origem'] ?? '')) ?: null,
            'desde'         => trim((string)($s['desde'] ?? '')) ?: null,
            'ate'           => trim((string)($s['ate'] ?? '')) ?: null,
        ], fn($v) => $v !== null && $v !== [] && $v !== '');
    }

    public function excluir(int $id): bool
    {
        $c = $this->obter($id);
        if (!$c) return false;
        if ($c['status'] === 'enviando') return false;   // apagar no meio deixa órfãos
        $this->db->prepare("DELETE FROM chat_campanhas WHERE id = :id")->execute([':id' => $id]);
        return true;
    }

    // =========================================================================
    // PÚBLICO
    // =========================================================================

    /**
     * O segmento SEMPRE exclui quem fez opt-out ou está bloqueado — é regra do
     * sistema, não escolha de quem monta a campanha.
     */
    private function filtroDoSegmento(array $campanha): array
    {
        $f = (array)($campanha['segmento'] ?? []);
        $f['optin']         = 1;
        $f['nao_bloqueado'] = 1;
        // Campanha de texto livre só alcança quem tem janela aberta
        if (($campanha['tipo'] ?? '') === 'texto') $f['janela'] = 'aberta';
        return $f;
    }

    public function estimarPublico(array $campanha): int
    {
        return $this->contatos->contarSegmento($this->filtroDoSegmento($campanha));
    }

    /** Estimativa a partir de filtros crus (preview ao vivo no formulário). */
    public function estimarPorFiltros(array $segmento, string $tipo = 'template'): int
    {
        return $this->estimarPublico([
            'segmento' => $this->normalizarSegmento($segmento),
            'tipo'     => $tipo,
        ]);
    }

    // =========================================================================
    // EXECUÇÃO
    // =========================================================================

    /** Materializa a fila e coloca a campanha em execução. */
    public function iniciar(int $id): array
    {
        $c = $this->obter($id);
        if (!$c) return ['ok' => false, 'erro' => 'Campanha não encontrada.'];
        if (!in_array($c['status'], ['rascunho', 'agendada', 'pausada'], true)) {
            return ['ok' => false, 'erro' => "Campanha no status '{$c['status']}' não pode ser iniciada."];
        }
        if (!$this->envio->disponivel()) {
            return ['ok' => false, 'erro' => 'WhatsApp não configurado: ' . $this->envio->erroConfig()];
        }

        // Retomar campanha pausada não remonta a fila — o público seria outro
        if ($c['status'] !== 'pausada') {
            $ids = $this->contatos->idsDoSegmento($this->filtroDoSegmento($c));
            if (!$ids) return ['ok' => false, 'erro' => 'Nenhum contato corresponde ao segmento.'];

            $this->db->beginTransaction();
            try {
                $this->db->prepare("DELETE FROM chat_campanha_destinatarios WHERE campanha_id = :c")
                         ->execute([':c' => $id]);

                $ins = $this->db->prepare(
                    "INSERT IGNORE INTO chat_campanha_destinatarios (campanha_id, contato_id) VALUES (:c, :ct)"
                );
                foreach ($ids as $contatoId) $ins->execute([':c' => $id, ':ct' => $contatoId]);

                $this->db->prepare(
                    "UPDATE chat_campanhas
                     SET total_destinatarios = :t, total_enviados = 0, total_entregues = 0,
                         total_lidos = 0, total_falhas = 0, total_pulados = 0
                     WHERE id = :id"
                )->execute([':t' => count($ids), ':id' => $id]);

                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                return ['ok' => false, 'erro' => 'Falha ao montar a fila: ' . $e->getMessage()];
            }
        }

        $this->db->prepare(
            "UPDATE chat_campanhas
             SET status = 'enviando', iniciado_em = COALESCE(iniciado_em, NOW()), erro_detalhe = NULL
             WHERE id = :id"
        )->execute([':id' => $id]);

        if (class_exists('LogService')) {
            try { LogService::audit('chat_campanha_iniciada', ['campanha_id' => $id]); } catch (Throwable $e) {}
        }

        $atual = $this->obter($id);
        return ['ok' => true, 'total' => (int)($atual['total_destinatarios'] ?? 0)];
    }

    public function pausar(int $id): bool
    {
        $this->db->prepare(
            "UPDATE chat_campanhas SET status = 'pausada' WHERE id = :id AND status = 'enviando'"
        )->execute([':id' => $id]);
        return true;
    }

    public function cancelar(int $id): bool
    {
        $this->db->prepare(
            "UPDATE chat_campanhas SET status = 'cancelada', concluido_em = NOW()
             WHERE id = :id AND status IN ('enviando','pausada','agendada','rascunho')"
        )->execute([':id' => $id]);
        return true;
    }

    /**
     * Consome um lote da fila. Chamado pelo worker, várias vezes por minuto.
     *
     * @return array{enviados:int, falhas:int, pulados:int, fim:bool}
     */
    public function processarLote(int $campanhaId, int $limite = 20): array
    {
        $out = ['enviados' => 0, 'falhas' => 0, 'pulados' => 0, 'fim' => false];

        $c = $this->obter($campanhaId);
        if (!$c || $c['status'] !== 'enviando') { $out['fim'] = true; return $out; }

        $st = $this->db->prepare(
            "SELECT d.id, d.contato_id FROM chat_campanha_destinatarios d
             WHERE d.campanha_id = :c AND d.status = 'pendente'
             ORDER BY d.id ASC LIMIT " . max(1, min(200, $limite))
        );
        $st->execute([':c' => $campanhaId]);
        $lote = $st->fetchAll(PDO::FETCH_ASSOC);

        if (!$lote) {
            $this->db->prepare(
                "UPDATE chat_campanhas SET status = 'concluida', concluido_em = NOW() WHERE id = :id"
            )->execute([':id' => $campanhaId]);
            (new ChatMensagemService($this->db))->recalcularCampanha($campanhaId);
            $out['fim'] = true;
            return $out;
        }

        $upd = $this->db->prepare(
            "UPDATE chat_campanha_destinatarios
             SET status = :s, wamid = :w, erro_detalhe = :e, enviado_em = NOW()
             WHERE id = :id"
        );

        foreach ($lote as $linha) {
            $r = $this->enviarPara((int)$linha['contato_id'], $c);

            if ($r['ok']) {
                $status = 'enviado';  $out['enviados']++;
            } elseif (in_array($r['motivo'] ?? '', [
                ChatEnvioService::MOTIVO_FORA_JANELA,
                ChatEnvioService::MOTIVO_OPTOUT,
                ChatEnvioService::MOTIVO_BLOQUEADO,
            ], true)) {
                // Não é erro nosso: o contato simplesmente não é alcançável
                $status = 'pulado';   $out['pulados']++;
            } else {
                $status = 'falhou';   $out['falhas']++;
            }

            $upd->execute([
                ':s'  => $status,
                ':w'  => $r['wamid'] ?? null,
                ':e'  => $r['erro'] ? mb_substr((string)$r['erro'], 0, 400) : null,
                ':id' => (int)$linha['id'],
            ]);

            // Fluxo pós-envio (ex.: pesquisa de satisfação após o disparo)
            if ($r['ok'] && !empty($c['fluxo_id'])) {
                try {
                    (new ChatFluxoMotor($this->db))->iniciar(
                        (int)$c['fluxo_id'], (int)$linha['contato_id'],
                        ['_campanha_id' => $campanhaId]
                    );
                } catch (Throwable $e) {}
            }
        }

        (new ChatMensagemService($this->db))->recalcularCampanha($campanhaId);
        return $out;
    }

    private function enviarPara(int $contatoId, array $c): array
    {
        $contato = $this->contatos->obter($contatoId);
        if (!$contato) return ['ok' => false, 'erro' => 'contato removido', 'motivo' => 'config'];

        $vars = $this->contatos->variaveis($contato);
        $opts = [
            'origem'    => 'campanha',
            'origem_id' => (int)$c['id'],
            'vars'      => $vars,
            'proativo'  => true,     // campanha respeita quiet hours
        ];

        if (($c['tipo'] ?? 'template') === 'texto') {
            return $this->envio->texto($contatoId, (string)$c['mensagem'], $opts);
        }

        $componentes = $this->templates->montarComponentes((array)$c['template_vars'], $vars);
        return $this->envio->template(
            $contatoId,
            (string)$c['template_nome'],
            (string)($c['template_idioma'] ?? 'pt_BR'),
            $componentes,
            $opts
        );
    }

    /** Campanhas prontas para rodar agora (worker). */
    public function pendentes(): array
    {
        return $this->db->query(
            "SELECT id, ritmo_por_minuto FROM chat_campanhas
             WHERE status = 'enviando'
                OR (status = 'agendada' AND agendado_para IS NOT NULL AND agendado_para <= NOW())
             ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // RELATÓRIO
    // =========================================================================

    public function destinatarios(int $campanhaId, string $status = '', int $pagina = 1, int $porPagina = 50): array
    {
        $w = ['d.campanha_id = :c'];
        $p = [':c' => $campanhaId];

        if ($status !== '' && in_array($status, ['pendente', 'enviado', 'entregue', 'lido', 'falhou', 'pulado'], true)) {
            $w[] = 'd.status = :s';
            $p[':s'] = $status;
        }

        $where     = 'WHERE ' . implode(' AND ', $w);
        $porPagina = max(1, min(200, $porPagina));
        $offset    = (max(1, $pagina) - 1) * $porPagina;

        $stT = $this->db->prepare("SELECT COUNT(*) FROM chat_campanha_destinatarios d $where");
        $stT->execute($p);
        $total = (int)$stT->fetchColumn();

        $st = $this->db->prepare(
            "SELECT d.*, ct.wa_id, ct.nome, ct.nome_perfil, ct.telefone_exibicao
             FROM chat_campanha_destinatarios d
             JOIN chat_contatos ct ON ct.id = d.contato_id
             $where
             ORDER BY d.id ASC LIMIT $porPagina OFFSET $offset"
        );
        $st->execute($p);

        return ['itens' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function resumo(int $campanhaId): array
    {
        $st = $this->db->prepare(
            "SELECT status, COUNT(*) AS n FROM chat_campanha_destinatarios
             WHERE campanha_id = :c GROUP BY status"
        );
        $st->execute([':c' => $campanhaId]);

        $out = ['pendente' => 0, 'enviado' => 0, 'entregue' => 0, 'lido' => 0, 'falhou' => 0, 'pulado' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['status']] = (int)$r['n'];
        return $out;
    }
}
