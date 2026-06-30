<?php
/**
 * app/services/email/CsvImportService.php
 *
 * Encapsula análise, validação e processamento de CSVs de contatos.
 * Trabalha com streaming (fgetcsv) para suportar arquivos grandes.
 */
class CsvImportService
{
    /** @var PDO */
    private $db;
    /** @var EmailImport */
    private $imports;
    /** @var EmailContact */
    private $contacts;
    /** @var EmailSuppression */
    private $supressoes;

    /** Diretório onde os CSVs são armazenados (fora da pasta pública). */
    private $uploadDir;
    /** Diretório onde relatórios de erros são gravados. */
    private $errorReportDir;

    /** Limites */
    const MAX_FILE_SIZE_BYTES = 50 * 1024 * 1024; // 50MB
    const ALLOWED_EXTENSIONS  = ['csv', 'txt'];
    const ALLOWED_MIMES       = [
        'text/csv', 'text/plain', 'application/csv',
        'application/vnd.ms-excel', 'application/octet-stream',
    ];
    const PREVIEW_ROWS = 5;
    const BATCH_SIZE = 500;

    /** Campos que podem ser mapeados a partir do CSV. */
    public static function camposDisponiveis(): array
    {
        return [
            'email'           => ['label' => 'Email',           'obrigatorio' => true],
            'nome'            => ['label' => 'Nome',            'obrigatorio' => false],
            'primeiro_nome'   => ['label' => 'Primeiro nome',   'obrigatorio' => false],
            'telefone'        => ['label' => 'Telefone',        'obrigatorio' => false],
            'celular'         => ['label' => 'Celular',         'obrigatorio' => false],
            'documento'       => ['label' => 'Documento',       'obrigatorio' => false],
            'data_nascimento' => ['label' => 'Data de nascimento', 'obrigatorio' => false],
            'genero'          => ['label' => 'Gênero',          'obrigatorio' => false],
            'tags'            => ['label' => 'Tags',            'obrigatorio' => false],
            'origem'          => ['label' => 'Origem (sobrescreve)', 'obrigatorio' => false],
        ];
    }

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->imports    = new EmailImport();
        $this->contacts   = new EmailContact();
        $this->supressoes = new EmailSuppression();

        $base = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
        $this->uploadDir      = $base . '/storage/email-imports';
        $this->errorReportDir = $base . '/storage/email-imports/errors';
        if (!is_dir($this->uploadDir))      @mkdir($this->uploadDir, 0775, true);
        if (!is_dir($this->errorReportDir)) @mkdir($this->errorReportDir, 0775, true);
    }

    // -------------------------------------------------------------------------
    // UPLOAD + ANÁLISE
    // -------------------------------------------------------------------------

    /**
     * Recebe upload, valida, move pra storage e retorna preview.
     *
     * @param array $file array do $_FILES
     * @return array{importacao_id:int, preview:array, header:array, delimiter:string, encoding:string, total_estimado:int}
     */
    public function receberUpload(array $file, ?int $criadoPor = null): array
    {
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Arquivo não enviado ou com erro de upload');
        }
        if ($file['size'] > self::MAX_FILE_SIZE_BYTES) {
            throw new RuntimeException('Arquivo excede o limite de 50MB');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Extensão inválida (use .csv ou .txt)');
        }

        // Validação de MIME via finfo (defesa em profundidade)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($mime && !in_array($mime, self::ALLOWED_MIMES, true)) {
                // Tolera, mas registra
                if (class_exists('LogService')) {
                    LogService::warning('csv_import: mime inesperado', ['mime' => $mime, 'arquivo' => $file['name']]);
                }
            }
        }

        // Move para storage (nome único, fora da pasta pública)
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $finalName = date('Ymd-His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_' . $safeName . '.csv';
        $destPath  = $this->uploadDir . '/' . $finalName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Falha ao salvar o arquivo no servidor');
        }
        chmod($destPath, 0640);

        // Analisa header, delimitador, encoding e estima total
        $analise = $this->analisar($destPath);

        $importacaoId = $this->imports->criar([
            'arquivo'      => $file['name'],
            'arquivo_path' => $destPath,
            'delimiter'    => $analise['delimiter'],
            'encoding'     => $analise['encoding'],
            'tem_header'   => $analise['tem_header'] ? 1 : 0,
            'total_linhas' => $analise['total_estimado'],
            'status'       => 'validando',
            'criado_por'   => $criadoPor,
        ]);

        return array_merge([
            'importacao_id' => $importacaoId,
        ], $analise);
    }

    /**
     * Analisa header, delimitador, encoding e estima quantidade de linhas.
     */
    public function analisar(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo não encontrado: ' . basename($path));
        }
        $fh = fopen($path, 'r');
        if (!$fh) throw new RuntimeException('Não foi possível abrir o arquivo');

        // Encoding: tenta detectar BOM e força UTF-8
        $bom = fread($fh, 3);
        $encoding = 'UTF-8';
        if ($bom === "\xEF\xBB\xBF") {
            $encoding = 'UTF-8-BOM';
        } else {
            rewind($fh);
            // Sniff primeiras 4KB pra mb_detect
            $sample = fread($fh, 4096);
            $detected = function_exists('mb_detect_encoding')
                ? mb_detect_encoding($sample, ['UTF-8','ISO-8859-1','Windows-1252'], true)
                : 'UTF-8';
            $encoding = $detected ?: 'UTF-8';
            rewind($fh);
        }

        // Detecta delimitador a partir da primeira linha
        $primeira = fgets($fh);
        $delim = ',';
        if (substr_count($primeira, ';') > substr_count($primeira, ',')) {
            $delim = ';';
        } elseif (substr_count($primeira, "\t") > substr_count($primeira, ',')) {
            $delim = "\t";
        }
        rewind($fh);
        if ($encoding === 'UTF-8-BOM') fread($fh, 3); // pula BOM

        $primeiraLinha = fgetcsv($fh, 0, $delim);
        if ($encoding !== 'UTF-8' && $encoding !== 'UTF-8-BOM' && $primeiraLinha) {
            $primeiraLinha = array_map(function ($v) use ($encoding) {
                return mb_convert_encoding((string)$v, 'UTF-8', $encoding);
            }, $primeiraLinha);
        }

        // Heurística pra detectar header: se a primeira coluna não é email válido, é header
        $temHeader = !filter_var(trim($primeiraLinha[0] ?? ''), FILTER_VALIDATE_EMAIL);

        // Preview (primeiras N linhas após o header)
        $preview = [];
        $startPos = ftell($fh); // estamos depois do header (ou na 2a linha)
        if (!$temHeader) rewind($fh);
        if ($encoding === 'UTF-8-BOM' && !$temHeader) fread($fh, 3);

        for ($i = 0; $i < self::PREVIEW_ROWS; $i++) {
            $row = fgetcsv($fh, 0, $delim);
            if ($row === false) break;
            if ($encoding !== 'UTF-8' && $encoding !== 'UTF-8-BOM') {
                $row = array_map(function ($v) use ($encoding) {
                    return mb_convert_encoding((string)$v, 'UTF-8', $encoding);
                }, $row);
            }
            $preview[] = $row;
        }

        // Estima total: conta linhas (limitado a 500k pra não travar em arquivo gigante)
        rewind($fh);
        if ($encoding === 'UTF-8-BOM') fread($fh, 3);
        $totalEstimado = 0;
        while (!feof($fh)) {
            if (fgets($fh) !== false) $totalEstimado++;
            if ($totalEstimado > 500000) break; // safety
        }
        if ($temHeader) $totalEstimado = max(0, $totalEstimado - 1);

        fclose($fh);

        return [
            'header'         => $temHeader ? $primeiraLinha : [],
            'preview'        => $preview,
            'delimiter'      => $delim,
            'encoding'       => $encoding,
            'tem_header'     => $temHeader,
            'total_estimado' => $totalEstimado,
        ];
    }

    /**
     * Confirma mapeamento e opções, marca job como 'fila' para o worker.
     *
     * @param array $mapeamento ['email'=>0,'nome'=>1,...]  (índice das colunas)
     * @param array $opcoes ['origem'=>'admin','base_legal'=>'consentimento','lista_id'=>123,
     *                       'criar_lista'=>0,'nome_nova_lista'=>'',
     *                       'atualizar_existentes'=>1,'ignorar_suprimidos'=>1,
     *                       'registrar_consentimento'=>1]
     */
    public function confirmarConfiguracao(int $importacaoId, array $mapeamento, array $opcoes): void
    {
        $imp = $this->imports->find($importacaoId);
        if (!$imp) throw new RuntimeException('Importação não encontrada');
        if ($imp['status'] !== 'validando') {
            throw new RuntimeException('Importação não está em estado de validação');
        }

        // mapeamento mínimo: email é obrigatório
        if (!isset($mapeamento['email']) || $mapeamento['email'] === '' || $mapeamento['email'] === null) {
            throw new RuntimeException('Coluna do email é obrigatória no mapeamento');
        }

        // Cria lista nova se solicitado
        if (!empty($opcoes['criar_lista']) && !empty($opcoes['nome_nova_lista'])) {
            $listaModel = new EmailList();
            $opcoes['lista_id'] = $listaModel->save([
                'nome'      => trim((string)$opcoes['nome_nova_lista']),
                'descricao' => 'Criada via importação CSV em ' . date('d/m/Y H:i'),
                'ativo'     => 1,
            ]);
        }

        $this->imports->atualizar($importacaoId, [
            'mapeamento_json' => $mapeamento,
            'opcoes_json'     => $opcoes,
            'lista_id'        => $opcoes['lista_id'] ?? null,
            'status'          => 'fila',
        ]);
    }

    // -------------------------------------------------------------------------
    // PROCESSAMENTO (chamado pelo worker)
    // -------------------------------------------------------------------------

    /**
     * Processa um job de importação (já reservado pelo worker).
     * Faz tudo em lotes com transaction por lote.
     */
    public function processar(array $job, ?callable $progressoCallback = null): array
    {
        $importacaoId = (int)$job['id'];
        $path         = $job['arquivo_path'];
        $delim        = $job['delimiter'] ?: ',';
        $encoding     = $job['encoding']  ?: 'UTF-8';
        $temHeader    = (bool)$job['tem_header'];
        $mapeamento   = json_decode((string)$job['mapeamento_json'], true) ?: [];
        $opcoes       = json_decode((string)$job['opcoes_json'], true) ?: [];

        if (!is_file($path)) {
            throw new RuntimeException("Arquivo do CSV não encontrado: $path");
        }

        $stats = [
            'linhas_processadas' => 0,
            'inseridos'    => 0,
            'atualizados'  => 0,
            'duplicados'   => 0,
            'invalidos'    => 0,
            'suprimidos'   => 0,
        ];

        $fh = fopen($path, 'r');
        if (!$fh) throw new RuntimeException('Não foi possível abrir o arquivo para leitura');

        // Pula BOM
        if ($encoding === 'UTF-8-BOM') fread($fh, 3);
        if ($temHeader) fgetcsv($fh, 0, $delim);

        $total = max(1, (int)$job['total_linhas']);
        $vistos = [];
        $linha = $temHeader ? 1 : 0;
        $contatosLote = [];

        // Pre-carrega supressões em memória (até 100k)
        $supressoes = $this->carregarSupressoes();

        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            $linha++;
            $stats['linhas_processadas']++;

            // Converte encoding se preciso
            if ($encoding !== 'UTF-8' && $encoding !== 'UTF-8-BOM') {
                $row = array_map(function ($v) use ($encoding) {
                    return mb_convert_encoding((string)$v, 'UTF-8', $encoding);
                }, $row);
            }

            // Aplica mapeamento
            $dados = [];
            foreach ($mapeamento as $campo => $idx) {
                if ($idx === '' || $idx === null) continue;
                $dados[$campo] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            }

            $email = strtolower(trim($dados['email'] ?? ''));
            $email = preg_replace('/\s+/u', '', $email);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['invalidos']++;
                $this->imports->registrarErro($importacaoId, $linha, $email, 'email_invalido', null, $dados);
                continue;
            }

            if (isset($vistos[$email])) {
                $stats['duplicados']++;
                $this->imports->registrarErro($importacaoId, $linha, $email, 'duplicado_no_arquivo', null, $dados);
                continue;
            }
            $vistos[$email] = true;

            if (isset($supressoes[$email])) {
                $stats['suprimidos']++;
                if (!empty($opcoes['ignorar_suprimidos'])) {
                    // Apenas pula
                } else {
                    $this->imports->registrarErro($importacaoId, $linha, $email, 'suprimido', 'Email está na lista de supressão', $dados);
                }
                continue;
            }

            // Acumula em lote
            $contatosLote[] = [
                'linha' => $linha,
                'dados' => array_merge($dados, ['email' => $email]),
            ];

            if (count($contatosLote) >= self::BATCH_SIZE) {
                $this->processarLote($contatosLote, $importacaoId, $opcoes, $stats);
                $contatosLote = [];

                // Atualiza progresso
                $pct = min(99, (int)round(($stats['linhas_processadas'] / $total) * 100));
                $this->imports->atualizar($importacaoId, [
                    'linhas_processadas' => $stats['linhas_processadas'],
                    'inseridos'    => $stats['inseridos'],
                    'atualizados'  => $stats['atualizados'],
                    'duplicados'   => $stats['duplicados'],
                    'invalidos'    => $stats['invalidos'],
                    'suprimidos'   => $stats['suprimidos'],
                    'progresso_pct'=> $pct,
                ]);
                if ($progressoCallback) call_user_func($progressoCallback, $stats, $pct);

                // Verifica cancelamento
                $jobAtual = $this->imports->find($importacaoId);
                if ($jobAtual && $jobAtual['status'] === 'cancelada') {
                    fclose($fh);
                    return $stats;
                }
            }
        }
        // Resto do lote
        if ($contatosLote) {
            $this->processarLote($contatosLote, $importacaoId, $opcoes, $stats);
        }
        fclose($fh);

        // Atualiza totais finais
        $this->imports->atualizar($importacaoId, array_merge($stats, [
            'progresso_pct' => 100,
            'status' => 'concluido',
            'concluido_em' => date('Y-m-d H:i:s'),
            'total_linhas' => max($total, $stats['linhas_processadas']),
        ]));

        return $stats;
    }

    private function processarLote(array $lote, int $importacaoId, array $opcoes, array &$stats): void
    {
        $listaId = $opcoes['lista_id'] ?? null;
        $atualizar = !empty($opcoes['atualizar_existentes']);
        $regConsentimento = !empty($opcoes['registrar_consentimento']);

        $this->db->beginTransaction();
        try {
            foreach ($lote as $item) {
                $dados = $item['dados'];
                $email = $dados['email'];

                $existente = $this->contacts->findByEmail($email);
                $statusAtual = $existente['status'] ?? null;

                // Proteção: não reativar contatos problemáticos
                if ($existente && in_array($statusAtual, ['descadastrado','bounce','complaint','bloqueado'], true)) {
                    $stats['suprimidos']++;
                    $this->imports->registrarErro($importacaoId, $item['linha'], $email,
                        'contato_protegido', "Status atual: $statusAtual", $dados);
                    continue;
                }

                if ($existente && !$atualizar) {
                    $stats['duplicados']++;
                    $contatoId = (int)$existente['id'];
                } else {
                    $payload = [
                        'email' => $email,
                        'nome'  => $dados['nome'] ?? null,
                        'primeiro_nome' => $dados['primeiro_nome'] ?? null,
                        'telefone' => $dados['telefone'] ?? null,
                        'celular' => $dados['celular'] ?? null,
                        'documento' => $dados['documento'] ?? null,
                        'data_nascimento' => $this->parseData($dados['data_nascimento'] ?? null),
                        'genero' => $dados['genero'] ?? null,
                        'tags' => $dados['tags'] ?? null,
                        'origem' => $opcoes['origem'] ?? ($dados['origem'] ?? 'importacao'),
                        'base_legal' => $opcoes['base_legal'] ?? 'consentimento',
                        'status' => 'ativo',
                    ];
                    $contatoId = $this->contacts->upsert($payload);

                    if ($existente) {
                        $stats['atualizados']++;
                    } else {
                        $stats['inseridos']++;
                    }
                }

                // Adiciona à lista se houver
                if ($listaId && $contatoId) {
                    $stIns = $this->db->prepare(
                        "INSERT INTO email_lista_contatos (lista_id, contato_id, status, adicionado_em)
                         VALUES (:l, :c, 'ativo', NOW())
                         ON DUPLICATE KEY UPDATE status='ativo', removido_em=NULL"
                    );
                    $stIns->execute([':l' => $listaId, ':c' => $contatoId]);
                }

                // Registra consentimento se solicitado
                if ($regConsentimento && $contatoId && class_exists('EmailConsent')) {
                    try {
                        $consent = new EmailConsent();
                        $consent->registrar([
                            'contato_id' => $contatoId,
                            'acao' => 'opt_in',
                            'origem' => 'importacao',
                            'base_legal' => $opcoes['base_legal'] ?? 'consentimento',
                            'referencia' => 'importacao_csv#' . $importacaoId,
                        ]);
                    } catch (Throwable $e) {
                        // não interrompe
                    }
                }
            }

            // Reconta a lista após o lote
            if ($listaId) {
                $this->db->prepare(
                    "UPDATE email_listas l SET total_contatos = (
                        SELECT COUNT(*) FROM email_lista_contatos
                        WHERE lista_id = l.id AND status='ativo'
                     ) WHERE l.id = :l"
                )->execute([':l' => $listaId]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            // registra erro no primeiro item do lote pra rastrear
            $primeiraLinha = $lote[0]['linha'] ?? 0;
            $this->imports->registrarErro($importacaoId, $primeiraLinha, null,
                'erro_lote', $e->getMessage(), null);
            throw $e;
        }
    }

    private function carregarSupressoes(): array
    {
        $st = $this->db->query(
            "SELECT email FROM email_supressoes
             WHERE expira_em IS NULL OR expira_em > NOW()
             LIMIT 100000"
        );
        $sup = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $sup[strtolower($email)] = true;
        }
        return $sup;
    }

    private function parseData(?string $valor): ?string
    {
        if (!$valor) return null;
        $valor = trim($valor);
        // Aceita: YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor)) return $valor;
        if (preg_match('#^(\d{2})[/-](\d{2})[/-](\d{4})$#', $valor, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // RELATÓRIO DE ERROS — geração de CSV (com proteção contra CSV injection)
    // -------------------------------------------------------------------------

    public function gerarRelatorioErros(int $importacaoId): string
    {
        $job = $this->imports->find($importacaoId);
        if (!$job) throw new RuntimeException('Importação não encontrada');

        $path = $this->errorReportDir . '/erros_' . $importacaoId . '_' . date('Ymd-His') . '.csv';
        $fh = fopen($path, 'w');
        if (!$fh) throw new RuntimeException('Não foi possível criar o arquivo de erros');

        // BOM UTF-8 pra abrir bem no Excel
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['linha', 'email', 'motivo', 'detalhe']);

        $pagina = 1;
        while (true) {
            $r = $this->imports->erros($importacaoId, $pagina, 500);
            if (!$r['itens']) break;
            foreach ($r['itens'] as $e) {
                fputcsv($fh, [
                    $e['linha'],
                    self::sanitizeCsvCell($e['email']),
                    self::sanitizeCsvCell($e['motivo']),
                    self::sanitizeCsvCell($e['detalhe']),
                ]);
            }
            if (count($r['itens']) < 500) break;
            $pagina++;
        }
        fclose($fh);

        $this->imports->atualizar($importacaoId, ['erros_arquivo_path' => $path]);
        return $path;
    }

    /** Mitiga CSV injection (=, +, -, @ no início). */
    public static function sanitizeCsvCell(?string $v): string
    {
        if ($v === null || $v === '') return '';
        $first = $v[0] ?? '';
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $v;
        }
        return $v;
    }
}
