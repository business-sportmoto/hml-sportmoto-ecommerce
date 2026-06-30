<?php
/**
 * app/services/email/EmailTemplateVersionService.php
 *
 * Gestão de histórico de versões de templates.
 * Snapshot automático a cada salvar (chamado pelo controller).
 */
class EmailTemplateVersionService
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Cria snapshot da versão atual do template.
     * Deve ser chamado ANTES de gravar uma nova versão.
     */
    public function snapshot(array $template, ?string $motivo = null, ?int $userId = null): int
    {
        $sql = "INSERT INTO email_template_versoes
                (template_id, versao, formato, nome, assunto, preheader,
                 html, source_json, source_css, texto, motivo, criado_por, criado_em)
                VALUES
                (:tid, :ver, :fmt, :nome, :ass, :pre,
                 :html, :sjson, :scss, :txt, :motivo, :usr, NOW())";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':tid'    => (int)$template['id'],
            ':ver'    => (int)$template['versao'],
            ':fmt'    => $template['formato'] ?? 'manual',
            ':nome'   => $template['nome'],
            ':ass'    => $template['assunto'],
            ':pre'    => $template['preheader'] ?? null,
            ':html'   => $template['html'] ?? '',
            ':sjson'  => $template['source_json'] ?? null,
            ':scss'   => $template['source_css'] ?? null,
            ':txt'    => $template['texto'] ?? null,
            ':motivo' => $motivo,
            ':usr'    => $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function listar(int $templateId, int $limit = 50): array
    {
        $st = $this->db->prepare(
            "SELECT v.id, v.versao, v.formato, v.nome, v.assunto,
                    v.motivo, v.criado_em, v.criado_por,
                    u.nome AS autor_nome
             FROM email_template_versoes v
             LEFT JOIN usuarios u ON u.id = v.criado_por
             WHERE v.template_id = :t
             ORDER BY v.versao DESC
             LIMIT $limit"
        );
        $st->execute([':t' => $templateId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $versaoId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM email_template_versoes WHERE id = :id LIMIT 1");
        $st->execute([':id' => $versaoId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Restaura uma versão anterior — cria um novo snapshot a partir dela
     * e marca o template com os dados restaurados.
     */
    public function restaurar(int $versaoId, ?int $userId = null): int
    {
        $versao = $this->find($versaoId);
        if (!$versao) throw new RuntimeException('Versão não encontrada');

        $templateId = (int)$versao['template_id'];

        // 1) Snapshot da versão atual antes de sobrescrever
        $tpl = (new EmailTemplate())->find($templateId);
        if (!$tpl) throw new RuntimeException('Template não encontrado');
        $this->snapshot($tpl, 'Snapshot pré-restauração (v' . $versao['versao'] . ')', $userId);

        // 2) Atualiza o template com os dados da versão antiga (versao incrementa)
        $st = $this->db->prepare(
            "UPDATE email_templates SET
                nome = :nome, assunto = :ass, preheader = :pre,
                formato = :fmt, html = :html, source_json = :sj, source_css = :sc,
                texto = :txt,
                versao = versao + 1,
                atualizado_em = NOW()
             WHERE id = :id"
        );
        $st->execute([
            ':nome' => $versao['nome'],
            ':ass'  => $versao['assunto'],
            ':pre'  => $versao['preheader'],
            ':fmt'  => $versao['formato'],
            ':html' => $versao['html'],
            ':sj'   => $versao['source_json'],
            ':sc'   => $versao['source_css'],
            ':txt'  => $versao['texto'],
            ':id'   => $templateId,
        ]);

        // 3) Cria snapshot do estado pós-restauração
        $tplNovo = (new EmailTemplate())->find($templateId);
        return $this->snapshot($tplNovo, 'Restaurado da v' . $versao['versao'], $userId);
    }
}
