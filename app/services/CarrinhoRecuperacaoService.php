<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/CarrinhoRecuperacaoService.php
//
// Ciclo de vida da recuperação (arquitetura estado-based,
// mesmo padrão do CashbackService):
//
//   Cron → detectarAbandonados()   marca carrinhos inativos
//        → reconciliarRecuperados() detecta compra pós-abandono
//        → reciclarSugestoes()      calcula próxima ação
//
//   Admin → mudarStatus / atribuir / anotar / enviar msgs
//   Público → resolverToken() restaura carrinho via link
// ════════════════════════════════════════════════════════

class CarrinhoRecuperacaoService {

    /** Horas sem atividade para considerar abandono */
    private const JANELA_ABANDONO_H   = 3;
    /** Valor mínimo do carrinho para entrar na central */
    private const VALOR_MINIMO        = 30.0;
    /** Validade do link público de retorno */
    private const TOKEN_VALIDADE_DIAS = 7;
    /** Máximo de tentativas antes de sugerir 'perdido' */
    private const MAX_TENTATIVAS      = 3;

    private const STATUS_VALIDOS = [
        'novo','abandonado','em_recuperacao','msg_enviada',
        'aguardando_resposta','respondeu','negociacao',
        'recuperado','perdido','ignorado','sem_contato',
    ];

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // DETECÇÃO (cron) — idempotente
    // ══════════════════════════════════════════════════

    /**
     * Marca como abandonado todo carrinho ativo com itens, valor
     * mínimo e sem atividade na janela. INSERT...SELECT com
     * NOT EXISTS = idempotente por construção; snapshot de valor
     * e itens congelado no momento da detecção.
     */
    public function detectarAbandonados(): int {
        $stmt = $this->db->prepare(
            "INSERT INTO carrinho_recuperacao
                (carrinho_id, cliente_id, status, valor_snapshot,
                 itens_snapshot, abandonado_em)
             SELECT ca.id, ca.cliente_id, 'abandonado',
                    agg.valor, agg.qtd, NOW()
             FROM carrinhos ca
             JOIN (SELECT ci.carrinho_id,
                          SUM(ci.quantidade * ci.preco_unitario) AS valor,
                          SUM(ci.quantidade)                     AS qtd
                   FROM carrinho_itens ci
                   GROUP BY ci.carrinho_id) agg ON agg.carrinho_id = ca.id
             WHERE ca.status = 'ativo'
               AND ca.atualizado_em < DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND agg.valor >= ?
               AND NOT EXISTS (SELECT 1 FROM carrinho_recuperacao cr
                               WHERE cr.carrinho_id = ca.id)"
        );
        $stmt->execute([self::JANELA_ABANDONO_H, self::VALOR_MINIMO]);
        $novos = $stmt->rowCount();

        if ($novos > 0) {
            $this->pontuarNovos();
            $this->registrarEventosDeteccao();
        }
        return $novos;
    }

    /**
     * Score 0–100 e prioridade para registros recém-detectados.
     * Critérios: valor, identificação, canais de contato,
     * recorrência do cliente e recência do abandono.
     */
    private function pontuarNovos(): void {
        $this->db->exec(
            "UPDATE carrinho_recuperacao cr
             LEFT JOIN clientes c ON c.id = cr.cliente_id
             SET cr.score = LEAST(100,
                   (CASE WHEN cr.valor_snapshot >= 500 THEN 30
                         WHEN cr.valor_snapshot >= 200 THEN 20
                         WHEN cr.valor_snapshot >= 100 THEN 10 ELSE 0 END)
                 + (CASE WHEN cr.cliente_id IS NOT NULL THEN 15 ELSE 0 END)
                 + (CASE WHEN c.telefone IS NOT NULL AND c.telefone != '' THEN 15 ELSE 0 END)
                 + (CASE WHEN c.email    IS NOT NULL AND c.email    != '' THEN 10 ELSE 0 END)
                 + (CASE WHEN EXISTS (SELECT 1 FROM pedidos p
                                      WHERE p.cliente_id = cr.cliente_id
                                        AND p.status_pagamento = 'aprovado')
                         THEN 20 ELSE 0 END)
                 + 10 /* recência: acabou de ser detectado */
             ),
             cr.prioridade = CASE
                 WHEN cr.score >= 75 THEN 'imediata'
                 WHEN cr.score >= 55 THEN 'alta'
                 WHEN cr.score >= 35 THEN 'media'
                 ELSE 'baixa' END,
             cr.status = CASE
                 WHEN cr.cliente_id IS NULL
                   OR (c.telefone IS NULL OR c.telefone = '')
                  AND (c.email    IS NULL OR c.email    = '')
                 THEN 'sem_contato' ELSE cr.status END
             WHERE cr.score = 0 AND cr.status IN ('abandonado','sem_contato')"
        );

        // Recalcula prioridade após possível mudança de status acima
        $this->db->exec(
            "UPDATE carrinho_recuperacao
             SET prioridade = CASE
                 WHEN score >= 75 THEN 'imediata'
                 WHEN score >= 55 THEN 'alta'
                 WHEN score >= 35 THEN 'media' ELSE 'baixa' END
             WHERE status IN ('abandonado','sem_contato')"
        );
    }

    private function registrarEventosDeteccao(): void {
        $this->db->exec(
            "INSERT INTO carrinho_recuperacao_eventos
                (recuperacao_id, tipo, descricao)
             SELECT cr.id, 'abandono_detectado',
                    CONCAT('Abandono detectado — ', cr.itens_snapshot,
                           ' item(ns), R$ ', FORMAT(cr.valor_snapshot, 2, 'pt_BR'))
             FROM carrinho_recuperacao cr
             WHERE NOT EXISTS (SELECT 1 FROM carrinho_recuperacao_eventos e
                               WHERE e.recuperacao_id = cr.id
                                 AND e.tipo = 'abandono_detectado')"
        );
    }

    // ══════════════════════════════════════════════════
    // RECONCILIAÇÃO (cron) — recuperação orgânica ou via link
    // ══════════════════════════════════════════════════

    /**
     * Cliente identificado que fez pedido aprovado APÓS o abandono
     * = carrinho recuperado, independente do canal. Estado-based:
     * captura recuperação orgânica, via link, via WhatsApp — tudo.
     */
    public function reconciliarRecuperados(): int {
        $stmt = $this->db->prepare(
            "UPDATE carrinho_recuperacao cr
             JOIN pedidos p ON p.cliente_id = cr.cliente_id
                           AND p.criado_em  > cr.abandonado_em
                           AND p.status_pagamento = 'aprovado'
             SET cr.status               = 'recuperado',
                 cr.pedido_recuperado_id = p.id,
                 cr.valor_recuperado     = p.total,
                 cr.ultima_acao_em       = NOW()
             WHERE cr.status NOT IN ('recuperado','perdido','ignorado')
               AND cr.cliente_id IS NOT NULL"
        );
        $stmt->execute();
        $n = $stmt->rowCount();

        if ($n > 0) {
            $this->db->exec(
                "INSERT INTO carrinho_recuperacao_eventos
                    (recuperacao_id, tipo, descricao, meta)
                 SELECT cr.id, 'recuperado',
                        CONCAT('Compra confirmada — pedido #', cr.pedido_recuperado_id),
                        JSON_OBJECT('pedido_id', cr.pedido_recuperado_id,
                                    'valor', cr.valor_recuperado)
                 FROM carrinho_recuperacao cr
                 WHERE cr.status = 'recuperado'
                   AND NOT EXISTS (SELECT 1 FROM carrinho_recuperacao_eventos e
                                   WHERE e.recuperacao_id = cr.id
                                     AND e.tipo = 'recuperado')"
            );
        }
        return $n;
    }

    /** Sugere próxima ação: carrinhos parados com tentativas esgotadas. */
    public function contarSugestaoPerdidos(): int {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM carrinho_recuperacao
             WHERE status IN ('msg_enviada','aguardando_resposta')
               AND tentativas_contato >= " . self::MAX_TENTATIVAS . "
               AND ultima_acao_em < DATE_SUB(NOW(), INTERVAL 5 DAY)"
        )->fetchColumn();
    }

    // ══════════════════════════════════════════════════
    // AÇÕES DO OPERADOR
    // ══════════════════════════════════════════════════

    public function mudarStatus(int $id, string $novoStatus, int $adminId,
                                string $motivo = ''): array {
        if (!in_array($novoStatus, self::STATUS_VALIDOS, true)) {
            return ['ok' => false, 'msg' => 'Status inválido.'];
        }

        $rec = $this->exigir($id);
        if (!$rec) return ['ok' => false, 'msg' => 'Registro não encontrado.'];

        // Perdido exige justificativa — accountability comercial
        if ($novoStatus === 'perdido' && trim($motivo) === '') {
            return ['ok' => false, 'msg' => 'Informe o motivo da perda.'];
        }

        $this->db->prepare(
            "UPDATE carrinho_recuperacao
             SET status = ?, motivo_perda = ?, ultima_acao_em = NOW()
             WHERE id = ?"
        )->execute([
            $novoStatus,
            $novoStatus === 'perdido' ? mb_substr(trim($motivo), 0, 120) : null,
            $id,
        ]);

        $this->evento($id, $novoStatus === 'perdido' ? 'perdido' : 'status_alterado',
            "Status: {$rec['status']} → {$novoStatus}"
            . ($motivo !== '' ? " ({$motivo})" : ''),
            ['de' => $rec['status'], 'para' => $novoStatus], $adminId);

        return ['ok' => true];
    }

    public function atribuirResponsavel(int $id, int $responsavelId, int $adminId): array {
        if (!$this->exigir($id)) return ['ok' => false, 'msg' => 'Registro não encontrado.'];

        $stmt = $this->db->prepare("SELECT nome FROM usuarios WHERE id = ? AND ativo = 1");
        $stmt->execute([$responsavelId]);
        $nome = $stmt->fetchColumn();
        if (!$nome) return ['ok' => false, 'msg' => 'Usuário inválido.'];

        $this->db->prepare(
            "UPDATE carrinho_recuperacao
             SET responsavel_id = ?, ultima_acao_em = NOW() WHERE id = ?"
        )->execute([$responsavelId, $id]);

        $this->evento($id, 'responsavel_alterado', "Responsável: {$nome}",
            ['responsavel_id' => $responsavelId], $adminId);
        return ['ok' => true];
    }

    public function anotar(int $id, string $texto, int $adminId): array {
        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) > 1000) {
            return ['ok' => false, 'msg' => 'Anotação inválida (1–1000 caracteres).'];
        }
        if (!$this->exigir($id)) return ['ok' => false, 'msg' => 'Registro não encontrado.'];

        $this->evento($id, 'anotacao', $texto, [], $adminId);
        $this->db->prepare(
            "UPDATE carrinho_recuperacao SET ultima_acao_em = NOW() WHERE id = ?"
        )->execute([$id]);
        return ['ok' => true];
    }

    public function agendarContato(int $id, string $dataHora, int $adminId): array {
        $ts = strtotime($dataHora);
        if ($ts === false || $ts < time()) {
            return ['ok' => false, 'msg' => 'Data/hora inválida ou no passado.'];
        }
        if (!$this->exigir($id)) return ['ok' => false, 'msg' => 'Registro não encontrado.'];

        $quando = date('Y-m-d H:i:s', $ts);
        $this->db->prepare(
            "UPDATE carrinho_recuperacao
             SET proximo_contato_em = ?, ultima_acao_em = NOW() WHERE id = ?"
        )->execute([$quando, $id]);

        $this->evento($id, 'agendamento',
            'Próximo contato: ' . date('d/m/Y H:i', $ts), [], $adminId);
        return ['ok' => true];
    }

    // ══════════════════════════════════════════════════
    // MENSAGENS — WhatsApp (wa.me) e E-mail (Mailgun)
    // ══════════════════════════════════════════════════

    /**
     * Prepara mensagem de WhatsApp: renderiza template e devolve
     * o link wa.me. O envio é MANUAL (click-to-chat) — automação
     * fora da API oficial Meta = banimento do número. O registro
     * do envio acontece aqui, no clique do operador.
     */
    public function prepararWhatsapp(int $id, int $templateId, int $adminId): array {
        $rec = $this->exigir($id);
        if (!$rec) return ['ok' => false, 'msg' => 'Registro não encontrado.'];

        $telefone = preg_replace('/\D/', '', (string)($rec['cliente_telefone'] ?? ''));
        if (strlen($telefone) < 10) {
            return ['ok' => false, 'msg' => 'Cliente sem telefone válido.'];
        }

        $tpl = $this->getTemplate($templateId, 'whatsapp');
        if (!$tpl) return ['ok' => false, 'msg' => 'Template inválido.'];

        $msg   = $this->renderizar($tpl['conteudo'], $rec);
        $waUrl = 'https://wa.me/55' . $telefone . '?text=' . rawurlencode($msg);

        $this->registrarEnvio($id, 'whatsapp_enviado', $tpl, $adminId);

        return ['ok' => true, 'url' => $waUrl, 'mensagem' => $msg];
    }

    /** Envia e-mail de recuperação via MailHelper (Mailgun). */
    public function enviarEmail(int $id, int $templateId, int $adminId): array {
        $rec = $this->exigir($id);
        if (!$rec) return ['ok' => false, 'msg' => 'Registro não encontrado.'];

        $email = trim((string)($rec['cliente_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => 'Cliente sem e-mail válido.'];
        }

        // Anti-spam operacional: bloqueia reenvio do mesmo canal em < 24h
        if ($this->envioRecente($id, 'email_enviado', 24)) {
            return ['ok' => false, 'msg' => 'E-mail já enviado nas últimas 24h. Evite excesso de contato.'];
        }

        $tpl = $this->getTemplate($templateId, 'email');
        if (!$tpl) return ['ok' => false, 'msg' => 'Template inválido.'];

        $assunto = $this->renderizar((string)$tpl['assunto'], $rec);
        $corpo   = $this->wrapperEmail($this->renderizar($tpl['conteudo'], $rec, true));

        try {
            MailHelper::send($email, $assunto, $corpo);
        } catch (\Throwable $e) {
            error_log('[CarrinhoRecuperacao] email #' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Falha no envio. Tente novamente.'];
        }

        $this->registrarEnvio($id, 'email_enviado', $tpl, $adminId);
        return ['ok' => true];
    }

    private function registrarEnvio(int $id, string $tipo, array $tpl, int $adminId): void {
        $this->evento($id, $tipo,
            ($tipo === 'whatsapp_enviado' ? 'WhatsApp' : 'E-mail')
            . ' — template "' . $tpl['nome'] . '"',
            ['template_id' => (int)$tpl['id']], $adminId);

        $this->db->prepare(
            "UPDATE carrinho_recuperacao
             SET status = IF(status IN ('abandonado','novo','em_recuperacao'),
                             'msg_enviada', status),
                 tentativas_contato = tentativas_contato + 1,
                 ultima_acao_em = NOW()
             WHERE id = ?"
        )->execute([$id]);
    }

    private function envioRecente(int $id, string $tipo, int $horas): bool {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM carrinho_recuperacao_eventos
             WHERE recuperacao_id = ? AND tipo = ?
               AND criado_em > DATE_SUB(NOW(), INTERVAL {$horas} HOUR)
             LIMIT 1"
        );
        $stmt->execute([$id, $tipo]);
        return (bool)$stmt->fetchColumn();
    }

    // ══════════════════════════════════════════════════
    // TOKEN PÚBLICO DE RETORNO
    // ══════════════════════════════════════════════════

    /** Gera (ou renova) o token do link público. 256 bits de entropia. */
    public function gerarToken(int $id): ?string {
        $rec = $this->exigir($id);
        if (!$rec) return null;

        if (!empty($rec['token_recuperacao'])
            && strtotime((string)$rec['token_expira_em']) > time() + 86400) {
            return $rec['token_recuperacao']; // ainda válido por 24h+, reusa
        }

        $token = bin2hex(random_bytes(32));
        $this->db->prepare(
            "UPDATE carrinho_recuperacao
             SET token_recuperacao = ?,
                 token_expira_em   = DATE_ADD(NOW(), INTERVAL ? DAY)
             WHERE id = ?"
        )->execute([$token, self::TOKEN_VALIDADE_DIAS, $id]);

        return $token;
    }

    /**
     * Resolve o token do link público: valida, registra o retorno
     * e devolve o carrinho_id para o controller restaurar os itens.
     * NÃO expõe nenhum dado do cliente — o token só dá acesso aos
     * ITENS, nunca à identidade.
     */
    public function resolverToken(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

        $stmt = $this->db->prepare(
            "SELECT cr.id, cr.carrinho_id
             FROM carrinho_recuperacao cr
             WHERE cr.token_recuperacao = ?
               AND cr.token_expira_em > NOW()
               AND cr.status NOT IN ('recuperado','perdido')
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $rec = $stmt->fetch();
        if (!$rec) return null;

        $this->evento((int)$rec['id'], 'cliente_retornou',
            'Cliente acessou o link de recuperação');
        $this->db->prepare(
            "UPDATE carrinho_recuperacao
             SET status = IF(status IN ('msg_enviada','aguardando_resposta'),
                             'respondeu', status),
                 ultima_acao_em = NOW()
             WHERE id = ?"
        )->execute([(int)$rec['id']]);

        return $rec;
    }

    // ══════════════════════════════════════════════════
    // TEMPLATES — renderização de variáveis
    // ══════════════════════════════════════════════════

    private function getTemplate(int $id, string $canal): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM recuperacao_templates
             WHERE id = ? AND canal = ? AND ativo = 1 LIMIT 1"
        );
        $stmt->execute([$id, $canal]);
        return $stmt->fetch() ?: null;
    }

    public function listarTemplates(string $canal): array {
        $stmt = $this->db->prepare(
            "SELECT id, nome, assunto, conteudo, uso_recomendado
             FROM recuperacao_templates
             WHERE canal = ? AND ativo = 1 ORDER BY id"
        );
        $stmt->execute([$canal]);
        return $stmt->fetchAll();
    }

    /**
     * Substitui variáveis do template. Para e-mail ($html=true),
     * valores do cliente são escapados — conteúdo do template é
     * confiável (admin), dados do cliente NÃO são.
     */
    private function renderizar(string $conteudo, array $rec, bool $html = false): string {
        $itens = (new CarrinhoAbandonado())->getItens((int)$rec['carrinho_id']);
        $token = $this->gerarToken((int)$rec['id']);
        $link  = BASE_URL . '/carrinho/recuperar/' . $token;

        $nome     = trim((string)($rec['cliente_nome'] ?? '')) ?: 'cliente';
        $primeiro = explode(' ', $nome)[0];
        $nomesProdutos = implode(', ',
            array_slice(array_column($itens, 'produto_nome'), 0, 3));

        $esc = fn(string $v): string => $html
            ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;

        $vars = [
            '{nome}'          => $esc($nome),
            '{primeiro_nome}' => $esc($primeiro),
            '{loja}'          => 'Sportmoto',
            '{valor}'         => PriceHelper::format((float)$rec['valor_snapshot']),
            '{produtos}'      => $esc($nomesProdutos),
            '{link}'          => $link,
            '{vendedor}'      => $esc((string)($rec['responsavel_nome'] ?? 'Equipe Sportmoto')),
            '{telefone_loja}' => defined('LOJA_TELEFONE') ? LOJA_TELEFONE : '',
            '{produtos_html}' => $html ? $this->produtosHtml($itens) : $nomesProdutos,
        ];

        return strtr($conteudo, $vars);
    }

    private function produtosHtml(array $itens): string {
        $rows = '';
        foreach ($itens as $i) {
            $rows .= '<tr>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e2e8f0;">'
                . htmlspecialchars((string)$i['produto_nome'], ENT_QUOTES, 'UTF-8')
                . ' <span style="color:#64748b;">×' . (int)$i['quantidade'] . '</span></td>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;">'
                . PriceHelper::format((float)$i['subtotal']) . '</td></tr>';
        }
        return '<table style="width:100%;border-collapse:collapse;margin:16px 0;">'
             . $rows . '</table>';
    }

    private function wrapperEmail(string $conteudo): string {
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f1f5f9;'
            . 'font-family:Arial,Helvetica,sans-serif;color:#1e293b;">'
            . '<div style="max-width:560px;margin:24px auto;background:#fff;'
            . 'border-radius:12px;padding:32px;">'
            . $conteudo
            . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:28px 0 16px;">'
            . '<p style="font-size:12px;color:#94a3b8;">Sportmoto — peças e acessórios '
            . 'para sua moto. Se não deseja mais receber estes e-mails, responda '
            . 'solicitando remoção.</p></div></body></html>';
    }

    // ══════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════

    private function exigir(int $id): ?array {
        return (new CarrinhoAbandonado())->findById($id);
    }

    private function evento(int $recId, string $tipo, string $descricao,
                            array $meta = [], ?int $adminId = null): void {
        $this->db->prepare(
            "INSERT INTO carrinho_recuperacao_eventos
                (recuperacao_id, tipo, descricao, meta, admin_id)
             VALUES (?,?,?,?,?)"
        )->execute([
            $recId, $tipo, mb_substr($descricao, 0, 255),
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $adminId,
        ]);
    }
}