<?php
declare(strict_types=1);

/**
 * app/services/ChatCanalPessoaService.php
 *
 * Uma PESSOA tem vários canais. O módulo de chat, não.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * O PROBLEMA QUE ESTE SERVICE RESOLVE
 *
 * `chat_contatos` tem UNIQUE (canal, wa_id): quem fala com a loja pelo
 * WhatsApp e pelo Instagram são DUAS linhas, não uma. O que as costura é o
 * `cliente_id`. E o e-mail não está em nenhuma das duas — mora em `usuarios`,
 * alcançado por `clientes.usuario_id`.
 *
 * Uma sessão de fluxo roda amarrada a UM contato. Sem esta camada, um fluxo
 * que começou no WhatsApp não tem como saber que a mesma pessoa tem Instagram,
 * nem como alcançá-la por lá — e a cascata "Instagram → e-mail → WhatsApp"
 * seria impossível de montar no canvas.
 *
 * Aqui, "pessoa" = `cliente_id`. Contato sem cadastro vinculado só enxerga o
 * próprio canal, e isso é correto: sem `cliente_id` não há como afirmar que
 * dois perfis são a mesma pessoa, e adivinhar isso mandaria a mensagem de um
 * cliente para outro.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * O QUE "ALCANÇÁVEL" SIGNIFICA EM CADA CANAL
 *
 * | Canal     | Existe                    | Janela                              |
 * |-----------|---------------------------|-------------------------------------|
 * | instagram | contato IG com optin      | 24h de mensagem OU 7 dias da tag    |
 * |           |                           | HUMAN_AGENT (`janela_humana_ate`)   |
 * | whatsapp  | contato WA com optin      | 24h para texto livre; fora dela só  |
 * |           |                           | template aprovado                   |
 * | email     | `usuarios.email` válido   | não tem janela                      |
 *
 * As janelas não são detalhe: no Instagram, fora delas a Meta simplesmente
 * recusa. No WhatsApp, fora dela o texto livre é recusado mas o template
 * passa — por isso `alcancavel()` distingue os dois casos por `exigirJanela`.
 */
class ChatCanalPessoaService
{
    public const CANAIS = ['instagram', 'whatsapp', 'email'];

    private PDO $db;

    /** Cache por request: a cascata consulta a mesma pessoa várias vezes. */
    private array $cache = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // IDENTIDADE
    // =========================================================================

    /**
     * Tudo que se sabe sobre como alcançar a pessoa por trás deste contato.
     *
     * @return array{
     *   cliente_id:int, nome:string,
     *   instagram:?array, whatsapp:?array, email:?array
     * }
     */
    public function canais(int $contatoId): array
    {
        if (isset($this->cache[$contatoId])) return $this->cache[$contatoId];

        $base = $this->contato($contatoId);
        $out  = [
            'cliente_id' => 0, 'nome' => '',
            'instagram'  => null, 'whatsapp' => null, 'email' => null,
        ];
        if (!$base) return $this->cache[$contatoId] = $out;

        $clienteId = (int)($base['cliente_id'] ?? 0);
        $out['cliente_id'] = $clienteId;
        $out['nome'] = (string)($base['nome'] ?: $base['nome_perfil'] ?: '');

        // O próprio contato da sessão sempre conta — mesmo sem cliente_id.
        $out[(string)$base['canal']] = $this->comoContato($base);

        // Sem cadastro não dá para afirmar que outro perfil é a mesma pessoa.
        if ($clienteId < 1) return $this->cache[$contatoId] = $out;

        // Irmãos: outras linhas do MESMO cliente, em canais diferentes.
        $st = $this->db->prepare(
            "SELECT * FROM chat_contatos
             WHERE cliente_id = :c AND id <> :id AND bloqueado = 0
             ORDER BY ultima_entrada_em DESC, id DESC"
        );
        $st->execute([':c' => $clienteId, ':id' => $contatoId]);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $irmao) {
            $canal = (string)$irmao['canal'];
            // O contato da sessão tem precedência: é onde a conversa está viva
            if (!isset($out[$canal]) || $out[$canal] === null) {
                $out[$canal] = $this->comoContato($irmao);
            }
        }

        $out['email'] = $this->email($clienteId, $out['nome']);

        return $this->cache[$contatoId] = $out;
    }

    /**
     * Este canal está utilizável AGORA?
     *
     * @param bool $exigirJanela  false = "existe o canal"; true = "dá para
     *                            mandar mensagem livre agora". No Instagram
     *                            os dois praticamente coincidem (fora da
     *                            janela a Meta recusa); no WhatsApp não,
     *                            porque o template passa fora dela.
     */
    public function alcancavel(int $contatoId, string $canal, bool $exigirJanela = true): bool
    {
        $c = $this->canais($contatoId)[$canal] ?? null;
        if (!$c) return false;

        if ($canal === 'email') return true;          // e-mail não tem janela
        if (!$exigirJanela)     return true;

        return (bool)($c['na_janela'] ?? false);
    }

    /** O destino de um canal, ou null. */
    public function destino(int $contatoId, string $canal): ?array
    {
        return $this->canais($contatoId)[$canal] ?? null;
    }

    // =========================================================================
    // MONTAGEM
    // =========================================================================

    private function contato(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM chat_contatos WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Contato de chat como destino de canal. */
    private function comoContato(array $c): ?array
    {
        if ((int)($c['bloqueado'] ?? 0) === 1) return null;
        if ((int)($c['optin'] ?? 1) !== 1)     return null;

        $agora   = time();
        $janela  = !empty($c['janela_expira_em'])
                && strtotime((string)$c['janela_expira_em']) > $agora;

        // Só o Instagram tem a tag de atendimento humano (7 dias). No WhatsApp
        // a coluna existe mas não vale como janela de mensagem livre.
        $humana  = (string)$c['canal'] === 'instagram'
                && !empty($c['janela_humana_ate'])
                && strtotime((string)$c['janela_humana_ate']) > $agora;

        return [
            'tipo'       => 'contato',
            'contato_id' => (int)$c['id'],
            'canal'      => (string)$c['canal'],
            'na_janela'  => $janela || $humana,
            'identidade' => (string)($c['canal'] === 'instagram'
                            ? ($c['ig_username'] ?: $c['wa_id'])
                            : $c['wa_id']),
        ];
    }

    /** E-mail da pessoa, por `clientes.usuario_id` → `usuarios.email`. */
    private function email(int $clienteId, string $nomeFallback): ?array
    {
        try {
            $st = $this->db->prepare(
                "SELECT u.email, u.nome
                 FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = :c LIMIT 1"
            );
            $st->execute([':c' => $clienteId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }

        $email = trim((string)($r['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        return [
            'tipo'       => 'email',
            'canal'      => 'email',
            'na_janela'  => true,
            'identidade' => $email,
            'nome'       => (string)($r['nome'] ?? '') ?: $nomeFallback,
        ];
    }

    // =========================================================================
    // JÁ COMPROU?
    // =========================================================================

    /**
     * A pessoa comprou este produto, com pagamento aprovado?
     *
     * É o freio de mão da cascata: insistir com quem já comprou não é só
     * inútil, é o tipo de mensagem que faz a pessoa bloquear a loja.
     *
     * @param int $desdeHoras 0 = qualquer época; >0 limita a janela recente,
     *                        que é o que interessa quando a pergunta é
     *                        "comprou DEPOIS de abandonar?"
     */
    public function comprouProduto(int $clienteId, int $produtoId, int $desdeHoras = 0): bool
    {
        if ($clienteId < 1 || $produtoId < 1) return false;

        $sql = "SELECT 1
                FROM pedidos p
                JOIN pedido_itens pi ON pi.pedido_id = p.id
                WHERE p.cliente_id = :c
                  AND pi.produto_id = :p
                  AND p.status_pagamento = 'aprovado'";
        $params = [':c' => $clienteId, ':p' => $produtoId];

        if ($desdeHoras > 0) {
            $sql .= " AND p.criado_em >= DATE_SUB(NOW(), INTERVAL :h HOUR)";
            $params[':h'] = $desdeHoras;
        }
        $sql .= ' LIMIT 1';

        try {
            $st = $this->db->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_INT);
            $st->execute();
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            // Na dúvida, NÃO afirma que comprou: o fluxo segue e manda a
            // mensagem. Errar para o lado de calar por causa de um erro de
            // query esconderia a falha e mataria a recuperação em silêncio.
            return false;
        }
    }
}
