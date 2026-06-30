<?php
/**
 * app/models/EmailTemplate.php
 */
// class EmailTemplate
// {
//     /** @var PDO */
//     private $db;

//     public function __construct()
//     {
//         $this->db = Database::getInstance()->getConnection();
//     }

//     public function all($apenasAtivos = false)
//     {
//         $sql = "SELECT id, nome, tipo, assunto, status, versao, atualizado_em
//                 FROM email_templates";
//         if ($apenasAtivos) $sql .= " WHERE status = 'ativo'";
//         $sql .= " ORDER BY atualizado_em DESC";
//         return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
//     }

//     public function find($id)
//     {
//         $st = $this->db->prepare("SELECT * FROM email_templates WHERE id = :id LIMIT 1");
//         $st->execute([':id' => (int)$id]);
//         return $st->fetch(PDO::FETCH_ASSOC) ?: null;
//     }

//     public function save(array $data)
//     {
//         $id = isset($data['id']) ? (int)$data['id'] : 0;
//         $vars = $data['variaveis_json'] ?? null;
//         if (is_array($vars)) $vars = json_encode($vars, JSON_UNESCAPED_UNICODE);

//         if ($id > 0) {
//             $st = $this->db->prepare("UPDATE email_templates SET
//                 nome=:nome, tipo=:tipo, assunto=:assunto, preheader=:preheader,
//                 html=:html, texto=:texto, variaveis_json=:vars, status=:status,
//                 versao = versao + 1
//                 WHERE id=:id");
//             $st->execute([
//                 ':nome' => $data['nome'],
//                 ':tipo' => $data['tipo'] ?? 'marketing',
//                 ':assunto' => $data['assunto'],
//                 ':preheader' => $data['preheader'] ?? null,
//                 ':html' => $data['html'],
//                 ':texto' => $data['texto'] ?? null,
//                 ':vars' => $vars,
//                 ':status' => $data['status'] ?? 'rascunho',
//                 ':id' => $id,
//             ]);
//             return $id;
//         }

//         $st = $this->db->prepare("INSERT INTO email_templates
//             (nome,tipo,assunto,preheader,html,texto,variaveis_json,status,versao)
//             VALUES
//             (:nome,:tipo,:assunto,:preheader,:html,:texto,:vars,:status,1)");
//         $st->execute([
//             ':nome' => $data['nome'],
//             ':tipo' => $data['tipo'] ?? 'marketing',
//             ':assunto' => $data['assunto'],
//             ':preheader' => $data['preheader'] ?? null,
//             ':html' => $data['html'],
//             ':texto' => $data['texto'] ?? null,
//             ':vars' => $vars,
//             ':status' => $data['status'] ?? 'rascunho',
//         ]);
//         return (int)$this->db->lastInsertId();
//     }

//     public function delete($id)
//     {
//         $st = $this->db->prepare("DELETE FROM email_templates WHERE id = :id");
//         return $st->execute([':id' => (int)$id]);
//     }
// }

/**
 * app/models/EmailTemplate.php  (v2)
 *
 * SUBSTITUI o EmailTemplate.php existente.
 * Adiciona suporte a:
 *   - campo formato (manual/visual/mjml)
 *   - source_json (estrutura GrapesJS)
 *   - source_css (CSS do builder)
 *   - render_status / render_log
 *   - snapshot automático a cada save() via EmailTemplateVersionService
 */
class EmailTemplate
{
    /** @var PDO */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function all(bool $somenteAtivos = false): array
    {
        $sql = "SELECT * FROM email_templates";
        if ($somenteAtivos) $sql .= " WHERE status = 'ativo'";
        $sql .= " ORDER BY atualizado_em DESC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $st = $this->db->prepare("SELECT * FROM email_templates WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Salva template — versão original + nova versão.
     * Snapshot é criado a cada salvar (se a tabela de versões existir).
     *
     * @param array $dados
     * @param int|null $userId — autor da alteração (pra histórico)
     */
    public function save(array $dados, ?int $userId = null): int
    {
        $id = (int)($dados['id'] ?? 0);

        if ($id > 0) {
            // Snapshot antes de sobrescrever
            $atual = $this->find($id);
            if ($atual && class_exists('EmailTemplateVersionService')) {
                try {
                    (new EmailTemplateVersionService())->snapshot(
                        $atual, 'Snapshot automático antes do salvar', $userId
                    );
                } catch (Throwable $e) {
                    // não interrompe o salvar
                    if (class_exists('LogService')) {
                        LogService::warning('email_tpl_snapshot: ' . $e->getMessage());
                    }
                }
            }

            $sql = "UPDATE email_templates SET
                        nome = :nome, tipo = :tipo, formato = :fmt,
                        assunto = :ass, preheader = :pre,
                        html = :html, source_json = :sj, source_css = :sc,
                        texto = :txt, status = :sts,
                        render_status = :rst, render_log = :rlog,
                        versao = versao + 1, atualizado_em = NOW()
                    WHERE id = :id";
            $st = $this->db->prepare($sql);
            $st->execute([
                ':nome' => $dados['nome'],
                ':tipo' => $dados['tipo'] ?? 'marketing',
                ':fmt'  => $dados['formato'] ?? 'manual',
                ':ass'  => $dados['assunto'],
                ':pre'  => $dados['preheader'] ?? null,
                ':html' => $dados['html'] ?? '',
                ':sj'   => $dados['source_json'] ?? null,
                ':sc'   => $dados['source_css'] ?? null,
                ':txt'  => $dados['texto'] ?? null,
                ':sts'  => $dados['status'] ?? 'rascunho',
                ':rst'  => $dados['render_status'] ?? 'ok',
                ':rlog' => $dados['render_log'] ?? null,
                ':id'   => $id,
            ]);
            return $id;
        }

        // Insert
        $sql = "INSERT INTO email_templates
                (nome, tipo, formato, assunto, preheader, html, source_json, source_css,
                 texto, status, render_status, render_log, versao, criado_em, atualizado_em)
                VALUES
                (:nome, :tipo, :fmt, :ass, :pre, :html, :sj, :sc,
                 :txt, :sts, :rst, :rlog, 1, NOW(), NOW())";
        $st = $this->db->prepare($sql);
        $st->execute([
            ':nome' => $dados['nome'],
            ':tipo' => $dados['tipo'] ?? 'marketing',
            ':fmt'  => $dados['formato'] ?? 'manual',
            ':ass'  => $dados['assunto'],
            ':pre'  => $dados['preheader'] ?? null,
            ':html' => $dados['html'] ?? '',
            ':sj'   => $dados['source_json'] ?? null,
            ':sc'   => $dados['source_css'] ?? null,
            ':txt'  => $dados['texto'] ?? null,
            ':sts'  => $dados['status'] ?? 'rascunho',
            ':rst'  => $dados['render_status'] ?? 'ok',
            ':rlog' => $dados['render_log'] ?? null,
        ]);
        $newId = (int)$this->db->lastInsertId();

        // Snapshot inicial v1
        if (class_exists('EmailTemplateVersionService')) {
            try {
                $tpl = $this->find($newId);
                (new EmailTemplateVersionService())->snapshot(
                    $tpl, 'Criação do template', $userId
                );
            } catch (Throwable $e) {
                // ignora
            }
        }

        return $newId;
    }

    public function delete(int $id): void
    {
        $this->db->prepare("DELETE FROM email_templates WHERE id = :id")
                 ->execute([':id' => $id]);
    }

    /**
     * Duplica um template (útil pra A/B test ou variações).
     */
    public function duplicar(int $id, ?int $userId = null): int
    {
        $tpl = $this->find($id);
        if (!$tpl) throw new RuntimeException('Template não encontrado');

        return $this->save([
            'nome'     => $tpl['nome'] . ' (cópia)',
            'tipo'     => $tpl['tipo'],
            'formato'  => $tpl['formato'],
            'assunto'  => $tpl['assunto'],
            'preheader' => $tpl['preheader'],
            'html'     => $tpl['html'],
            'source_json' => $tpl['source_json'],
            'source_css'  => $tpl['source_css'],
            'texto'    => $tpl['texto'],
            'status'   => 'rascunho',
        ], $userId);
    }
}


