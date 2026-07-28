<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/TrayClienteImportService.php
// ════════════════════════════════════════════════════════

class TrayClienteImportService {

    private PDO $db;

    const CHUNK_SIZE    = 50;
    const CSV_DELIMITER = ';';
    const CSV_ENCODING  = 'ISO-8859-1';

    // Índices das colunas no CSV de clientes Tray
    const C = [
        'codigo'       => 0,
        'data_cadastro'=> 1,
        'nome'         => 3,
        'cpf'          => 6,
        'logradouro'   => 7,
        'cidade'       => 8,
        'estado'       => 9,
        'cep'          => 11,
        'email'        => 12,
        'telefone'     => 13,
        'celular'      => 14,
        'newsletter'   => 19,
        'nascimento'   => 23,
        'numero'       => 24,
        'complemento'  => 25,
        'bairro'       => 26,
        'bloqueado'    => 32,
        'sexo'         => 33,
        'destinatario' => 34,
        'instagram'    => 37,
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // CRIAÇÃO DE JOB (reutiliza TrayImportService)
    // ════════════════════════════════════════════════════

    public function criarJob(array $file, int $adminId): array {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return ['ok' => false, 'msg' => 'Apenas arquivos .csv são aceitos.'];
        }

        $tmpDir = sys_get_temp_dir() . '/tray_imports';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $tmpName = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file($file['tmp_name'], $tmpName)) {
            return ['ok' => false, 'msg' => 'Erro ao salvar o arquivo.'];
        }

        $totalLinhas = $this->contarLinhas($tmpName) - 1;
        if ($totalLinhas <= 0) {
            unlink($tmpName);
            return ['ok' => false, 'msg' => 'Arquivo vazio ou inválido.'];
        }

        $this->db->prepare(
            "INSERT INTO import_jobs
             (tipo, status, arquivo_tmp, total_linhas, admin_id)
             VALUES ('clientes', 'aguardando', ?, ?, ?)"
        )->execute([$tmpName, $totalLinhas, $adminId]);

        return [
            'ok'           => true,
            'job_id'       => (int)$this->db->lastInsertId(),
            'total_linhas' => $totalLinhas,
        ];
    }

    // ════════════════════════════════════════════════════
    // PREVIEW
    // ════════════════════════════════════════════════════

    public function preview(int $jobId): array {
        $job = $this->getJob($jobId);
        if (!$job) return ['ok' => false, 'msg' => 'Job não encontrado.'];

        $rows    = $this->lerLinhas($job['arquivo_tmp'], 1, 5);
        $preview = [];

        foreach ($rows as $r) {
            $preview[] = [
                'tray_id'    => $r[self::C['codigo']]    ?? '',
                'nome'       => $this->utf8($r[self::C['nome']]  ?? ''),
                'email'      => $this->utf8($r[self::C['email']] ?? ''),
                'cpf'        => $this->limparCpf($r[self::C['cpf']] ?? ''),
                'cidade'     => $this->utf8($r[self::C['cidade']] ?? ''),
                'estado'     => $this->utf8($r[self::C['estado']] ?? ''),
                'bloqueado'  => ($r[self::C['bloqueado']] ?? '0') === '1' ? 'Sim' : 'Não',
            ];
        }

        return ['ok' => true, 'preview' => $preview, 'total' => $job['total_linhas']];
    }

    // ════════════════════════════════════════════════════
    // PROCESSAMENTO EM CHUNKS
    // ════════════════════════════════════════════════════

    public function processarChunk(int $jobId): array {
        $job = $this->getJob($jobId);
        if (!$job) return ['ok' => false, 'msg' => 'Job não encontrado.'];

        if ($job['status'] === 'concluido') {
            return ['ok' => true, 'concluido' => true];
        }

        $this->db->prepare(
            "UPDATE import_jobs SET status = 'processando' WHERE id = ?"
        )->execute([$jobId]);

        $offset = (int)$job['processadas'];
        $rows   = $this->lerLinhas($job['arquivo_tmp'], $offset + 1, self::CHUNK_SIZE);

        if (empty($rows)) {
            $this->finalizarJob($jobId);
            return ['ok' => true, 'concluido' => true];
        }

        $criados    = 0;
        $atualizados= 0;
        $ignorados  = 0;
        $erros      = json_decode($job['erros_json'] ?? '[]', true) ?: [];

        foreach ($rows as $idx => $row) {
            $linhaNum = $offset + $idx + 2;
            try {
                $result = $this->processarCliente($row);
                match ($result) {
                    'criado'    => $criados++,
                    'atualizado'=> $atualizados++,
                    'ignorado'  => $ignorados++,
                    default     => null,
                };
            } catch (\Throwable $e) {
                $erros[] = [
                    'linha' => $linhaNum,
                    'nome'  => $this->utf8($row[self::C['nome']] ?? ''),
                    'email' => $this->utf8($row[self::C['email']] ?? ''),
                    'msg'   => $e->getMessage(). ' (Linha: ' . $e->getLine() . ')'. ' (Arquivo: ' . $e->getFile() . ')',
                ];
                $ignorados++;
            }
        }

        $novasProcessadas = $offset + count($rows);
        $concluido        = $novasProcessadas >= (int)$job['total_linhas'];

        $this->db->prepare(
            "UPDATE import_jobs SET
                processadas  = processadas  + ?,
                criados      = criados      + ?,
                atualizados  = atualizados  + ?,
                ignorados    = ignorados    + ?,
                erros_json   = ?,
                status       = IF(? >= total_linhas, 'concluido', 'processando'),
                concluido_em = IF(? >= total_linhas, NOW(), NULL)
             WHERE id = ?"
        )->execute([
            count($rows),
            $criados, $atualizados, $ignorados,
            json_encode($erros),
            $novasProcessadas, $novasProcessadas,
            $jobId,
        ]);

        if ($concluido) @unlink($job['arquivo_tmp']);

        return [
            'ok'         => true,
            'concluido'  => $concluido,
            'processadas'=> $novasProcessadas,
            'total'      => (int)$job['total_linhas'],
            'criados'    => $criados,
            'atualizados'=> $atualizados,
            'ignorados'  => $ignorados,
        ];
    }

    // ════════════════════════════════════════════════════
    // LÓGICA DE IMPORTAÇÃO DO CLIENTE
    // ════════════════════════════════════════════════════

    private function processarCliente(array $r): string {
        $trayId = trim($r[self::C['codigo']] ?? '');
        $nome   = $this->utf8(trim($r[self::C['nome']]  ?? ''));
        $email  = strtolower(trim($this->utf8($r[self::C['email']] ?? '')));

        if (empty($email) || empty($nome)) return 'ignorado';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'ignorado';

        $cpf       = $this->limparCpf($r[self::C['cpf']] ?? '');
        $bloqueado = ($r[self::C['bloqueado']] ?? '0') === '1';

        // ── Checa CPF duplicado em outro cliente ──────────
        // ── CPF duplicado: reconcilia em vez de barrar ──────────
        if ($cpf) {
            $stmt = $this->db->prepare(
                "SELECT c.id, c.usuario_id, u.email
                 FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.cpf = ? LIMIT 1"
            );
            $stmt->execute([$cpf]);
            $cpfExistente = $stmt->fetch();

            if ($cpfExistente) {
                $emailCadastrado = strtolower(trim($cpfExistente['email']));

                if ($emailCadastrado === $email) {
                    // Mesma pessoa (CPF + e-mail batem) → atualiza campos
                    // vazios (atualizarCliente já faz COALESCE, não toca senha)
                    return $this->atualizarCliente(
                        ['usuario_id' => (int)$cpfExistente['usuario_id'],
                         'cliente_id' => (int)$cpfExistente['id']],
                        $r, $trayId, $cpf, $bloqueado
                    );
                }

                // CPF bate mas e-mail DIFERENTE → ambíguo, não arrisca.
                // Pode ser conta trocada, erro de digitação, ou fraude.
                LogService::warning(
                    'CPF já cadastrado com e-mail diferente — cliente Tray ignorado',
                    [
                        'cpf'              => $cpf,
                        'email_tray'       => $email,
                        'email_cadastrado' => $emailCadastrado,
                        'tray_id'          => $trayId,
                    ],
                    'import'
                );
                return 'ignorado';
            }
        }

        // ── Verifica se e-mail já existe (dedup) ──────────
        $stmt = $this->db->prepare(
            "SELECT u.id AS usuario_id, c.id AS cliente_id
             FROM usuarios u
             LEFT JOIN clientes c ON c.usuario_id = u.id
             WHERE u.email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $existente = $stmt->fetch();

        if ($existente) {
            return $this->atualizarCliente($existente, $r, $trayId, $cpf, $bloqueado);
        }

        return $this->criarCliente($r, $trayId, $cpf, $email, $nome, $bloqueado);
    }

    // ── INSERT novo usuário + cliente + endereço ──────────
    private function criarCliente(
        array  $r,
        string $trayId,
        string $cpf,
        string $email,
        string $nome,
        bool   $bloqueado
    ): string {
        $this->db->beginTransaction();
        try {
            // INSERT usuarios
            $this->db->prepare(
                "INSERT INTO usuarios
                 (nome, email, senha_hash, senha_definida, tipo,
                  email_verificado, ativo, criado_em)
                 VALUES (?, ?, NULL, 0, 'cliente', 1, ?, ?)"
            )->execute([
                $nome,
                $email,
                $bloqueado ? 0 : 1,
                $this->dataCadastro($r[self::C['data_cadastro']] ?? ''),
            ]);
            $usuarioId = (int)$this->db->lastInsertId();

            // INSERT clientes
            $this->db->prepare(
                "INSERT INTO clientes
                 (tray_id, usuario_id, cpf, telefone, celular,
                  nascimento, genero, newsletter, insta_cliente,
                  verificado, verificado_em, criado_em)
                 VALUES (?,?,?,?,?,?,?,?,?,1,NOW(),?)"
            )->execute([
                $trayId ?: null,
                $usuarioId,
                $cpf ?: null,
                $this->tel($r[self::C['telefone']] ?? ''),
                $this->tel($r[self::C['celular']]  ?? ''),
                $this->data($r[self::C['nascimento']] ?? ''),
                $this->genero($r[self::C['sexo']] ?? ''),
                $this->newsletter($r[self::C['newsletter']] ?? '1'),
                $this->utf8(trim($r[self::C['instagram']] ?? '')) ?: null,
                $this->dataCadastro($r[self::C['data_cadastro']] ?? ''),
            ]);
            $clienteId = (int)$this->db->lastInsertId();

            // INSERT endereço (se tiver CEP ou logradouro)
            $this->inserirEndereco($clienteId, $r, $nome);

            $this->db->commit();
            return 'criado';

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ── UPDATE usuário + cliente existentes ───────────────
    private function atualizarCliente(
        array  $existente,
        array  $r,
        string $trayId,
        string $cpf,
        bool   $bloqueado
    ): string {
        $usuarioId = (int)$existente['usuario_id'];
        $clienteId = (int)$existente['cliente_id'];

        // UPDATE usuarios — NUNCA toca em senha_hash
        $this->db->prepare(
            "UPDATE usuarios
             SET nome   = ?,
                 ativo  = ?
             WHERE id = ?"
        )->execute([
            $this->utf8(trim($r[self::C['nome']] ?? '')),
            $bloqueado ? 0 : 1,
            $usuarioId,
        ]);

        // UPDATE clientes (se o cliente_id existir)
        if ($clienteId) {
            $this->db->prepare(
                "UPDATE clientes SET
                    tray_id       = COALESCE(tray_id, ?),
                    cpf           = COALESCE(cpf, ?),
                    telefone      = COALESCE(NULLIF(?,''), telefone),
                    celular       = COALESCE(NULLIF(?,''), celular),
                    nascimento    = COALESCE(nascimento, ?),
                    genero        = COALESCE(genero, ?),
                    newsletter    = ?,
                    insta_cliente = COALESCE(NULLIF(?,''), insta_cliente)
                 WHERE id = ?"
            )->execute([
                $trayId ?: null,
                $cpf    ?: null,
                $this->tel($r[self::C['telefone']] ?? ''),
                $this->tel($r[self::C['celular']]  ?? ''),
                $this->data($r[self::C['nascimento']] ?? ''),
                $this->genero($r[self::C['sexo']] ?? ''),
                $this->newsletter($r[self::C['newsletter']] ?? '1'),
                $this->utf8(trim($r[self::C['instagram']] ?? '')),
                $clienteId,
            ]);

            // Insere endereço apenas se ainda não tiver nenhum
            $stmtEnd = $this->db->prepare(
                "SELECT COUNT(*) FROM enderecos WHERE cliente_id = ?"
            );
            $stmtEnd->execute([$clienteId]);
            $temEndereco = (int)$stmtEnd->fetchColumn() > 0;

            if (!$temEndereco) {
                $this->inserirEndereco(
                    $clienteId,
                    $r,
                    $this->utf8(trim($r[self::C['nome']] ?? ''))
                );
            }
        }

        return 'atualizado';
    }

    // ── INSERT endereço ───────────────────────────────────
    private function inserirEndereco(int $clienteId, array $r, string $nomeFallback): void {
        // limparNumeros já tira a máscara; mas a Tray às vezes manda
        // >8 dígitos (concatenação/lixo) → estoura a coluna (1406).
        // CEP brasileiro é sempre 8 dígitos: trunca o excedente.
        $cep = $this->limparNumeros($r[self::C['cep']] ?? '');
        if (strlen($cep) > 8) {
            LogService::warning(
                'CEP com mais de 8 dígitos na importação Tray — truncado',
                ['cep_original' => $cep, 'clienteId' => $clienteId, 'nomeFallback' => $nomeFallback],
                'import'
            );
            $cep = substr($cep, 0, 8);
        }

        $logradouro = $this->utf8(trim($r[self::C['logradouro']] ?? ''));

        // Só insere se tiver pelo menos CEP ou logradouro
        if (empty($cep) && empty($logradouro)) {
            return;
        }

        $destinatario = $this->utf8(trim($r[self::C['destinatario']] ?? '')) ?: $nomeFallback;

        $numero = $this->utf8(trim($r[self::C['numero']] ?? '')) ?: 'S/N';
        $complemento = $this->utf8(trim($r[self::C['complemento']] ?? '')) ?: null;

        $bairro = $this->utf8(trim($r[self::C['bairro']] ?? ''));
        $cidade = $this->utf8(trim($r[self::C['cidade']] ?? ''));
        $estado = strtoupper(trim($r[self::C['estado']] ?? ''));

        /*
        * Sua tabela exige NOT NULL para:
        * cep, logradouro, numero, bairro, cidade, estado.
        * Então não é seguro mandar NULL nesses campos.
        */
        $logradouro = $logradouro ?: 'Não informado';
        $bairro     = $bairro ?: 'Não informado';
        $cidade     = $cidade ?: 'Não informado';
        $estado     = $estado ?: 'NA';
        $cep        = $cep ?: '00000000';

        $sql = "
            INSERT INTO enderecos (
                cliente_id,
                nome_destinatario,
                logradouro,
                numero,
                complemento,
                bairro,
                cidade,
                estado,
                cep,
                principal
            ) VALUES (
                :cliente_id,
                :nome_destinatario,
                :logradouro,
                :numero,
                :complemento,
                :bairro,
                :cidade,
                :estado,
                :cep,
                :principal
            )
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            ':cliente_id'        => $clienteId,
            ':nome_destinatario' => $destinatario,
            ':logradouro'        => $logradouro,
            ':numero'            => $numero,
            ':complemento'       => $complemento,
            ':bairro'            => $bairro,
            ':cidade'            => $cidade,
            ':estado'            => $estado,
            ':cep'               => $cep,
            ':principal'         => 1,
        ]);
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    private function utf8(string $str): string {
        return mb_convert_encoding($str, 'UTF-8', self::CSV_ENCODING);
    }

    private function limparCpf(string $str): string {
        // Remove aspas simples que a Tray adiciona: '40208008802' → 40208008802
        return preg_replace('/[^0-9]/', '', $str);
    }

    private function limparNumeros(string $str): string {
        return preg_replace('/[^0-9]/', '', $str);
    }

    private function tel(string $str): ?string {
        $t = preg_replace('/[^0-9]/', '', $this->utf8($str));
        return strlen($t) >= 8 ? $t : null;
    }

    private function data(string $str): ?string {
        $str = trim($str);
        if (empty($str) || $str === '00/00/0000') return null;
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $str, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }

    private function dataCadastro(string $str): string {
        return $this->data($str) ?? date('Y-m-d H:i:s');
    }

    private function genero(string $str): ?string {
        return match (trim($str)) {
            '1'     => 'M',
            '2'     => 'F',
            default => null,
        };
    }

    private function newsletter(string $str): int {
        // 1 = inscrito, 2 = não inscrito
        return trim($str) === '2' ? 2 : 1;
    }

    // ── Job helpers ───────────────────────────────────────

    public function getJob(int $jobId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM import_jobs WHERE id = ? AND tipo = 'clientes' LIMIT 1"
        );
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }

    private function finalizarJob(int $jobId): void {
        $this->db->prepare(
            "UPDATE import_jobs
             SET status = 'concluido', concluido_em = NOW() WHERE id = ?"
        )->execute([$jobId]);
    }

    private function lerLinhas(string $arquivo, int $startLine, int $limit): array {
        $rows    = [];
        $handle  = fopen($arquivo, 'r');
        if (!$handle) return [];

        $lineNum = 0;
        while (!feof($handle) && count($rows) < $limit) {
            $row = fgetcsv($handle, 0, self::CSV_DELIMITER, '"');
            if ($row === false) continue;
            $lineNum++;
            if ($lineNum <= $startLine) continue;
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function contarLinhas(string $arquivo): int {
        $count  = 0;
        $handle = fopen($arquivo, 'r');
        while (!feof($handle)) {
            if (fgetcsv($handle, 0, self::CSV_DELIMITER, '"') !== false) $count++;
        }
        fclose($handle);
        return $count;
    }
}