<?php
/**
 * app/services/ChatNotificacaoService.php
 *
 * Ponte entre o Atendimento e o sino de notificações in-app.
 *
 * Todo o módulo Chat avisa por aqui, e só por aqui. Concentrar num lugar é o
 * que permite três garantias que, espalhadas pelos chamadores, seriam esquecidas
 * uma a uma:
 *
 *   1. ANTI-REPETIÇÃO — cliente que manda cinco mensagens seguidas gera UMA
 *      notificação, não cinco. É a diferença entre um sino útil e um sino que
 *      todo mundo aprende a ignorar.
 *   2. NUNCA AVISA O AUTOR — quem pega a conversa para si não recebe "uma
 *      conversa foi atribuída a você".
 *   3. NUNCA DERRUBA O CHAMADOR — o webhook e o worker não podem quebrar
 *      porque o sino falhou. Todo método engole exceção e loga.
 *
 * Destinatário é sempre `usuarios.id` (o `destinatario_id` do tipo 'admin' no
 * módulo de notificações), nunca `admins.id` — ver §4.1 do CLAUDE.md.
 */
class ChatNotificacaoService
{
    public const CATEGORIA = 'atendimento';

    /** Tipos emitidos — o `tipo` também é a chave do anti-repetição. */
    public const T_CONVERSA_NOVA = 'chat_conversa_nova';
    public const T_POOL          = 'chat_pool';
    public const T_MENSAGEM      = 'chat_mensagem';
    public const T_ATRIBUIDA     = 'chat_atribuida';
    public const T_SEM_RESPOSTA  = 'chat_sem_resposta';
    public const T_FALHAS        = 'chat_falha_envio';
    public const T_CAMPANHA      = 'chat_campanha_fim';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // MENSAGEM RECEBIDA
    // =========================================================================

    /**
     * Chamado pelo webhook depois de persistir uma mensagem de ENTRADA.
     *
     * Três situações, três avisos diferentes:
     *   · contato falando pela primeira vez  → "Nova conversa" para os atendentes
     *   · conversa sem responsável           → "Sem responsável" para os atendentes
     *   · conversa com responsável           → só para o responsável
     *
     * Mensagem de saída não passa por aqui: ninguém precisa ser avisado do que
     * a própria loja acabou de mandar.
     *
     * @param bool $botRespondeu a automação já respondeu esta mensagem. Silencia
     *        o aviso do pool — ninguém precisa correr para uma conversa que o
     *        robô está conduzindo. NÃO silencia o aviso do responsável: se a
     *        conversa tem dono, ele quer saber de tudo que acontece nela.
     */
    public function entrada(int $conversaId, array $contato, string $texto, bool $botRespondeu = false): void
    {
        try {
            $cv = $this->conversa($conversaId);
            if (!$cv) return;

            $dono    = (int)($cv['atribuido_a'] ?? 0);
            $nome    = $this->nomeContato($contato);
            $preview = $this->preview($texto);
            $canal   = $this->rotuloCanal((string)($cv['canal'] ?? 'whatsapp'));
            $url     = $this->urlConversa($conversaId);
            $silencio = ChatConfig::int('notif_silencio_min', 15);

            // ── Conversa com dono: só o dono ──
            if ($dono > 0) {
                if (!ChatConfig::bool('notif_mensagem', true)) return;
                if ($this->jaAvisou(self::T_MENSAGEM, $conversaId, $silencio, $dono)) return;

                $this->enviar(self::T_MENSAGEM, [
                    'titulo'   => "{$nome} respondeu",
                    'mensagem' => $preview,
                    'url'      => $url,
                ], [$dono], ['conversa_id' => $conversaId, 'canal' => $cv['canal']]);
                return;
            }

            // ── Sem dono: vai para todos que atendem ──
            if ($botRespondeu) return;
            if (!ChatConfig::bool('notif_conversa_nova', true)) return;

            // total_entrada = 1 significa que esta é a primeira mensagem que a
            // pessoa manda na vida — merece um título diferente de "mais uma".
            $primeira = (int)($contato['total_entrada'] ?? 0) <= 1;
            $tipo     = $primeira ? self::T_CONVERSA_NOVA : self::T_POOL;

            if ($this->jaAvisou($tipo, $conversaId, $silencio)) return;

            $this->enviar($tipo, [
                'titulo'   => $primeira
                    ? "Nova conversa no {$canal} — {$nome}"
                    : "{$nome} está sem responsável",
                'mensagem' => $preview,
                'url'      => $url,
            ], $this->atendentes(), ['conversa_id' => $conversaId, 'canal' => $cv['canal']]);

        } catch (Throwable $e) {
            $this->logar('entrada', $e);
        }
    }

    // =========================================================================
    // ATRIBUIÇÃO
    // =========================================================================

    /**
     * Conversa passada para alguém.
     *
     * Pegar para si não gera aviso — a pessoa acabou de clicar no botão.
     * Retirar o responsável (para = 0) também não: não há a quem avisar, e o
     * pool inteiro descobre na próxima mensagem.
     */
    public function atribuida(int $conversaId, int $paraUsuarioId, ?int $porUsuarioId = null): void
    {
        try {
            if ($paraUsuarioId < 1 || $paraUsuarioId === (int)$porUsuarioId) return;
            if (!ChatConfig::bool('notif_atribuicao', true)) return;

            $cv = $this->conversa($conversaId);
            if (!$cv) return;

            $quem = $porUsuarioId ? $this->nomeUsuario((int)$porUsuarioId) : null;

            $this->enviar(self::T_ATRIBUIDA, [
                'titulo'   => 'Conversa atribuída a você — ' . $this->nomeContato($cv),
                'mensagem' => $quem
                    ? "{$quem} passou esta conversa para você."
                    : 'Você é o responsável por esta conversa.',
                'url'      => $this->urlConversa($conversaId),
            ], [$paraUsuarioId], ['conversa_id' => $conversaId, 'canal' => $cv['canal']]);

        } catch (Throwable $e) {
            $this->logar('atribuida', $e);
        }
    }

    // =========================================================================
    // WORKER — o que ninguém vê acontecer
    // =========================================================================

    /**
     * Cliente esperando resposta há tempo demais.
     *
     * `ultima_direcao = 'entrada'` já quer dizer "ninguém respondeu" — se o bot
     * tivesse respondido, a última direção seria 'saida'. Por isso não é preciso
     * checar sessão de fluxo aqui.
     *
     * Avisa o responsável; sem responsável, avisa os gestores — é decisão de
     * escala, não de operação.
     *
     * @return int quantas conversas geraram aviso
     */
    public function semResposta(): int
    {
        $min = ChatConfig::int('notif_sem_resposta_min', 30);
        if ($min <= 0) return 0;

        $enviadas = 0;
        try {
            // O teto de 24h evita ressuscitar conversa velha que ninguém vai
            // mais responder: aviso atrasado de dois dias não é aviso, é ruído.
            $st = $this->db->query(
                "SELECT cv.id, cv.canal, cv.atribuido_a, cv.ultima_mensagem_em,
                        cv.ultima_preview, ct.nome, ct.nome_perfil, ct.wa_id
                 FROM chat_conversas cv
                 JOIN chat_contatos ct ON ct.id = cv.contato_id
                 WHERE cv.status <> 'resolvida'
                   AND cv.ultima_direcao = 'entrada'
                   AND cv.ultima_mensagem_em <= DATE_SUB(NOW(), INTERVAL " . (int)$min . " MINUTE)
                   AND cv.ultima_mensagem_em >  DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY cv.ultima_mensagem_em ASC
                 LIMIT 50"
            );

            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cv) {
                $cvId = (int)$cv['id'];

                // Um aviso por espera, não um por ciclo do worker: só reavisa
                // se o cliente mandou algo novo depois do último aviso.
                if ($this->jaAvisouDesde(self::T_SEM_RESPOSTA, $cvId, (string)$cv['ultima_mensagem_em'])) {
                    continue;
                }

                $dono  = (int)($cv['atribuido_a'] ?? 0);
                $alvos = $dono > 0 ? [$dono] : $this->gestores();
                if (!$alvos) continue;

                $espera = $this->humanizar(time() - strtotime((string)$cv['ultima_mensagem_em']));

                $this->enviar(self::T_SEM_RESPOSTA, [
                    'titulo'   => $this->nomeContato($cv) . " espera resposta há {$espera}",
                    'mensagem' => $this->preview((string)$cv['ultima_preview']),
                    'url'      => $this->urlConversa($cvId),
                ], $alvos, ['conversa_id' => $cvId, 'canal' => $cv['canal'], 'espera_min' => $min]);

                $enviadas++;
            }
        } catch (Throwable $e) {
            $this->logar('semResposta', $e);
        }
        return $enviadas;
    }

    /**
     * Envios falhando em série — quase sempre é causa única (token expirado,
     * template pausado, número com restrição). Avisa os gestores uma vez por
     * hora com o erro mais frequente, que costuma ser o diagnóstico inteiro.
     */
    public function falhasDeEnvio(): bool
    {
        $limite = ChatConfig::int('notif_falhas_min', 5);
        if ($limite <= 0) return false;

        try {
            if ($this->jaAvisou(self::T_FALHAS, 0, 60)) return false;

            $st = $this->db->query(
                "SELECT COUNT(*) AS n,
                        SUBSTRING_INDEX(GROUP_CONCAT(erro_detalhe ORDER BY id DESC SEPARATOR '\\n'), '\\n', 1) AS ultimo
                 FROM chat_mensagens
                 WHERE direcao = 'saida' AND status = 'falhou'
                   AND criado_em > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $n = (int)($r['n'] ?? 0);
            if ($n < $limite) return false;

            $alvos = $this->gestores();
            if (!$alvos) return false;

            $this->enviar(self::T_FALHAS, [
                'titulo'   => "{$n} envios falharam na última hora",
                'mensagem' => $this->preview((string)($r['ultimo'] ?? ''), 180)
                              ?: 'Verifique as credenciais e o status do número.',
                'url'      => $this->base() . '/admin/chat',
            ], $alvos, ['conversa_id' => 0, 'falhas' => $n]);

            return true;

        } catch (Throwable $e) {
            $this->logar('falhasDeEnvio', $e);
            return false;
        }
    }

    /**
     * Aviso de sistema para os gestores, com a mesma trava anti-repetição dos
     * outros. Existe para módulos vizinhos (o teto de IA, por exemplo) não
     * precisarem alcançar os privados daqui só para mandar um recado.
     *
     * @param string $tipo chave do anti-repetição — use uma por assunto
     */
    public function avisoDeSistema(string $tipo, string $titulo, string $mensagem,
                                   ?string $url = null, int $silencioMin = 60): bool
    {
        try {
            if ($this->jaAvisou($tipo, 0, $silencioMin)) return false;

            $alvos = $this->gestores();
            if (!$alvos) return false;

            $this->enviar($tipo, [
                'titulo'   => $titulo,
                'mensagem' => $mensagem,
                'url'      => $url ?: ($this->base() . '/admin/chat'),
            ], $alvos, ['conversa_id' => 0]);

            return true;
        } catch (Throwable $e) {
            $this->logar('avisoDeSistema', $e);
            return false;
        }
    }

    /** Campanha terminou — avisa quem apertou o botão. */
    public function campanhaConcluida(int $campanhaId): void
    {
        try {
            if (!ChatConfig::bool('notif_campanha', true)) return;

            $st = $this->db->prepare(
                "SELECT id, nome, criado_por, total_enviados, total_falhas, total_pulados
                 FROM chat_campanhas WHERE id = :id LIMIT 1"
            );
            $st->execute([':id' => $campanhaId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);

            $dono = (int)($c['criado_por'] ?? 0);
            if (!$c || $dono < 1) return;

            $partes = [(int)$c['total_enviados'] . ' enviada(s)'];
            if ((int)$c['total_falhas'] > 0)  $partes[] = (int)$c['total_falhas'] . ' falha(s)';
            if ((int)$c['total_pulados'] > 0) $partes[] = (int)$c['total_pulados'] . ' pulada(s)';

            $this->enviar(self::T_CAMPANHA, [
                'titulo'   => 'Campanha concluída — ' . $c['nome'],
                'mensagem' => implode(' · ', $partes),
                'url'      => $this->base() . '/admin/chat/campanhas/' . (int)$c['id'],
            ], [$dono], ['campanha_id' => (int)$c['id']]);

        } catch (Throwable $e) {
            $this->logar('campanhaConcluida', $e);
        }
    }

    // =========================================================================
    // ANTI-REPETIÇÃO
    // =========================================================================

    /**
     * Já avisei isto nos últimos N minutos?
     *
     * A busca é por (tipo, janela de tempo) — coberta pelo índice
     * idx_tipo_criado — e só depois filtra o conversa_id dentro do JSON, sobre
     * as poucas linhas que sobraram.
     */
    private function jaAvisou(string $tipo, int $conversaId, int $minutos, ?int $usuarioId = null): bool
    {
        if ($minutos <= 0) return false;

        $sql = "SELECT 1 FROM notificacoes n";
        if ($usuarioId) $sql .= " JOIN notificacao_usuarios nu ON nu.notificacao_id = n.id";

        $sql .= " WHERE n.tipo = :t
                    AND n.criado_em > DATE_SUB(NOW(), INTERVAL " . (int)$minutos . " MINUTE)
                    AND CAST(JSON_EXTRACT(n.contexto_json, '$.conversa_id') AS UNSIGNED) = :c";
        $p = [':t' => $tipo, ':c' => $conversaId];

        if ($usuarioId) {
            $sql .= " AND nu.destinatario_tipo = 'admin' AND nu.destinatario_id = :u";
            $p[':u'] = $usuarioId;
        }

        $st = $this->db->prepare($sql . ' LIMIT 1');
        $st->execute($p);
        return (bool)$st->fetchColumn();
    }

    /** Variante ancorada num instante: "já avisei DEPOIS desta mensagem?" */
    private function jaAvisouDesde(string $tipo, int $conversaId, string $desde): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM notificacoes n
             WHERE n.tipo = :t AND n.criado_em > :d
               AND CAST(JSON_EXTRACT(n.contexto_json, '$.conversa_id') AS UNSIGNED) = :c
             LIMIT 1"
        );
        $st->execute([':t' => $tipo, ':d' => $desde, ':c' => $conversaId]);
        return (bool)$st->fetchColumn();
    }

    // =========================================================================
    // DESTINATÁRIOS
    // =========================================================================

    /** Quem atende — os mesmos cargos que entram no inbox (§4.6). */
    private function atendentes(): array
    {
        return $this->usuariosPorNivel(['super', 'gerente', 'vendedor']);
    }

    /** Quem responde por escala e por saúde do canal. */
    private function gestores(): array
    {
        return $this->usuariosPorNivel(['super', 'gerente']);
    }

    private function usuariosPorNivel(array $niveis): array
    {
        try {
            $in = implode(',', array_fill(0, count($niveis), '?'));
            $st = $this->db->prepare(
                "SELECT u.id FROM admins a
                 JOIN usuarios u ON u.id = a.usuario_id
                 WHERE u.ativo = 1 AND u.deleted_at IS NULL AND a.nivel IN ($in)"
            );
            $st->execute($niveis);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    // =========================================================================
    // AUXILIARES
    // =========================================================================

    private function enviar(string $tipo, array $dados, array $usuarioIds, array $contexto = []): void
    {
        $usuarioIds = array_values(array_unique(array_filter(array_map('intval', $usuarioIds))));
        if (!$usuarioIds) return;

        NotificacaoService::criar([
            'categoria' => self::CATEGORIA,
            'tipo'      => $tipo,
            'titulo'    => $dados['titulo'],
            'mensagem'  => $dados['mensagem'] ?? null,
            'url'       => $dados['url'] ?? null,
            'contexto'  => $contexto,
        ], array_map(fn($id) => ['tipo' => 'admin', 'id' => $id], $usuarioIds));
    }

    private function conversa(int $id): ?array
    {
        $st = $this->db->prepare(
            "SELECT cv.id, cv.canal, cv.status, cv.atribuido_a,
                    ct.nome, ct.nome_perfil, ct.wa_id
             FROM chat_conversas cv
             JOIN chat_contatos ct ON ct.id = cv.contato_id
             WHERE cv.id = :id LIMIT 1"
        );
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function nomeContato(array $c): string
    {
        $nome = trim((string)($c['nome'] ?? '')) ?: trim((string)($c['nome_perfil'] ?? ''));
        return $nome !== '' ? mb_substr($nome, 0, 60) : (string)($c['wa_id'] ?? 'Contato');
    }

    private function nomeUsuario(int $id): ?string
    {
        try {
            $st = $this->db->prepare("SELECT nome FROM usuarios WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $n = $st->fetchColumn();
            return $n === false ? null : trim((string)$n);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function preview(string $texto, int $max = 120): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
        return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
    }

    private function rotuloCanal(string $canal): string
    {
        return $canal === 'instagram' ? 'Instagram' : 'WhatsApp';
    }

    private function urlConversa(int $conversaId): string
    {
        return $this->base() . '/admin/chat/inbox?conversa=' . $conversaId;
    }

    private function base(): string
    {
        return defined('BASE_URL') ? BASE_URL : '';
    }

    private function humanizar(int $segundos): string
    {
        if ($segundos < 3600) return max(1, intdiv($segundos, 60)) . ' min';
        $h = intdiv($segundos, 3600);
        $m = intdiv($segundos % 3600, 60);
        return $m > 0 ? "{$h}h{$m}min" : "{$h}h";
    }

    private function logar(string $onde, Throwable $e): void
    {
        if (!class_exists('LogService')) return;
        try { LogService::exception($e, 'warning', 'chat', ['onde' => "notificacao:$onde"]); }
        catch (Throwable $x) {}
    }
}
