<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/TrayImportService.php
// ════════════════════════════════════════════════════════

class TrayImportService {

    // private PDO $db;

    const CHUNK_SIZE    = 50;
    const CSV_DELIMITER = ';';
    const CSV_ENCODING  = 'ISO-8859-1';

    // Índices das colunas no CSV de produtos
    const P = [
        'codigo'        => 0,
        'nome'          => 4,
        'descricao'     => 5,
        'img1'          => 6,
        'img2'          => 7,
        'img3'          => 8,
        'img4'          => 9,
        'preco'         => 10,
        'peso'          => 11,
        'estoque'       => 13,
        'estoque_min'   => 14,
        'disponivel'    => 15,
        'promo_inicio'  => 16,
        'promo_fim'     => 17,
        'preco_promo'   => 18,
        'destaque'      => 19,
        'lancamento'    => 20,
        'vendidos'      => 22,
        'marca'         => 23,
        'modelo'        => 24,
        'referencia'    => 25,
        'ean'           => 26,
        'comprimento'   => 35,
        'largura'       => 36,
        'altura'        => 37,
        'meta_title'    => 40,
        'meta_desc'     => 41,
        'meta_kw'       => 42,
        'imgs_adicionais'=> 44,
        'categoria'     => 45,
        'exibir'        => 46,
    ];

    // Índices das colunas no CSV de variações
    const V = [
        'codigo_var'    => 0,
        'codigo_prod'   => 1,
        'ean'           => 3,
        'var1_nome'     => 4,
        'var1_valor'    => 5,
        'var2_nome'     => 6,
        'var2_valor'    => 7,
        'peso'          => 8,
        'preco'         => 9,
        'estoque'       => 10,
        'vendidos'      => 11,
        'promo_inicio'  => 13,
        'promo_fim'     => 14,
        'preco_promo'   => 15,
        'referencia'    => 16,
        'imagem'        => 17,
        'comprimento'   => 18,
        'largura'       => 19,
        'altura'        => 20,
        'imgs_adicionais'=> 21,
    ];

    private PDO            $db;
    private EstoqueService $estoque;

    public function __construct() {
        $this->db      = Database::getInstance()->getConnection();
        $this->estoque = new EstoqueService();
    }

    // ════════════════════════════════════════════════════
    // UPLOAD E CRIAÇÃO DE JOB
    // ════════════════════════════════════════════════════

    public function criarJob(array $file, string $tipo, int $adminId): array {
        // Valida extensão
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return ['ok' => false, 'msg' => 'Apenas arquivos .csv são aceitos.'];
        }

        // Move para diretório temporário seguro
        $tmpDir  = sys_get_temp_dir() . '/tray_imports';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $tmpName = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file($file['tmp_name'], $tmpName)) {
            return ['ok' => false, 'msg' => 'Erro ao salvar o arquivo.'];
        }

        // Conta linhas para mostrar progresso
        $totalLinhas = $this->contarLinhas($tmpName) - 1; // -1 pelo header

        if ($totalLinhas <= 0) {
            unlink($tmpName);
            return ['ok' => false, 'msg' => 'Arquivo vazio ou inválido.'];
        }

        $this->db->prepare(
            "INSERT INTO import_jobs
             (tipo, status, arquivo_tmp, total_linhas, admin_id)
             VALUES (?, 'aguardando', ?, ?, ?)"
        )->execute([$tipo, $tmpName, $totalLinhas, $adminId]);

        $jobId = (int)$this->db->lastInsertId();

        return [
            'ok'          => true,
            'job_id'      => $jobId,
            'total_linhas'=> $totalLinhas,
        ];
    }

    // ════════════════════════════════════════════════════
    // PREVIEW (primeiras 5 linhas parseadas)
    // ════════════════════════════════════════════════════

    public function preview(int $jobId): array {
        $job = $this->getJob($jobId);
        if (!$job) return ['ok' => false, 'msg' => 'Job não encontrado.'];

        $rows    = $this->lerLinhas($job['arquivo_tmp'], 1, 5);
        $preview = [];

        if ($job['tipo'] === 'produtos') {
            foreach ($rows as $r) {
                $preview[] = [
                    'tray_id' => $r[self::P['codigo']] ?? '',
                    'nome'    => $this->utf8($r[self::P['nome']] ?? ''),
                    'marca'   => $this->utf8($r[self::P['marca']] ?? ''),
                    'preco'   => $this->preco($r[self::P['preco']] ?? '0'),
                    'estoque' => (int)($r[self::P['estoque']] ?? 0),
                    'categoria'=> $this->utf8($r[self::P['categoria']] ?? ''),
                ];
            }
        } else {
            foreach ($rows as $r) {
                $preview[] = [
                    'codigo_prod'  => $r[self::V['codigo_prod']] ?? '',
                    'variacao'     => $this->utf8($r[self::V['var1_nome']] ?? ''),
                    'valor'        => $this->utf8($r[self::V['var1_valor']] ?? ''),
                    'sku'          => $r[self::V['referencia']] ?? '',
                    'preco'        => $this->preco($r[self::V['preco']] ?? '0'),
                    'estoque'      => (int)($r[self::V['estoque']] ?? 0),
                ];
            }
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
            return ['ok' => true, 'concluido' => true, 'msg' => 'Import já concluído.'];
        }

        // Marca como processando
        $this->db->prepare(
            "UPDATE import_jobs SET status = 'processando' WHERE id = ?"
        )->execute([$jobId]);

        $offset = (int)$job['processadas'];
        $inicio = $offset + 1; // +1 pelo header
        $rows   = $this->lerLinhas($job['arquivo_tmp'], $inicio, self::CHUNK_SIZE);

        if (empty($rows)) {
            $this->finalizarJob($jobId);
            return ['ok' => true, 'concluido' => true];
        }

        $criados    = 0;
        $atualizados= 0;
        $ignorados  = 0;
        $erros      = json_decode($job['erros_json'] ?? '[]', true) ?: [];

        foreach ($rows as $idx => $row) {
            try {
                $linhaNum = $offset + $idx + 2; // linha real no CSV

                if ($job['tipo'] === 'produtos') {
                    $result = $this->processarProduto($row);
                } else {
                    $result = $this->processarVariacao($row);
                }

                match ($result) {
                    'criado'    => $criados++,
                    'atualizado'=> $atualizados++,
                    'ignorado'  => $ignorados++,
                    default     => null,
                };

            } catch (\Throwable $e) {
                $erros[] = [
                    'linha' => $linhaNum ?? 0,
                    'msg'   => $e->getMessage() . ' - file:'. $e->getFile() . ' - line:'. $e->getLine(),
                ];
                error_log("[TrayImport] Linha {$linhaNum}: " . $e->getMessage());
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

        if ($concluido) {
            // Limpa arquivo temporário
            @unlink($job['arquivo_tmp']);
        }

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
    // IMPORTAÇÃO DE PRODUTO
    // ════════════════════════════════════════════════════

    private function processarProduto(array $r): string {
        $trayId   = trim($r[self::P['codigo']] ?? '');
        $nome     = $this->utf8(trim($r[self::P['nome']] ?? ''));
        if (empty($trayId) || empty($nome)) return 'ignorado';

        $disponivel = strtolower($this->utf8($r[self::P['disponivel']] ?? '')) === 'sim';
        $exibir     = strtolower($this->utf8($r[self::P['exibir']]    ?? '')) === 'sim';
        $ativo      = ($disponivel && $exibir) ? 1 : 0;

        $preco      = $this->preco($r[self::P['preco']]      ?? '0');
        $precoPromo = $this->precoNullavel($r[self::P['preco_promo']] ?? '0');
        $promoIn    = $this->data($r[self::P['promo_inicio']] ?? '');
        $promoFim   = $this->data($r[self::P['promo_fim']]   ?? '');

        $peso        = $this->pesoKg($r[self::P['peso']] ?? '0');
        $comprimento = $this->decimal($r[self::P['comprimento']] ?? '0');
        $largura     = $this->decimal($r[self::P['largura']]     ?? '0');
        $altura      = $this->decimal($r[self::P['altura']]      ?? '0');

        $marcaId    = $this->findOrCreateMarca($this->utf8($r[self::P['marca']] ?? ''));
        // $catId      = $this->findOrCreateCategoria($this->utf8($r[self::P['categoria']] ?? ''));

        $campos = [
            'tray_id'          => $trayId,
            'nome'             => $nome,
            'slug'             => SlugHelper::unique($nome, 'produtos'),
            'marca_id'         => $marcaId,
            'categoria_id'     => $catId,
            'sku_legado'       => $this->utf8($r[self::P['referencia']] ?? '') ?: null,
            'preco'            => $preco > 0 ? $preco : 0.01,
            'preco_promo'      => $precoPromo,
            'promo_inicio'     => $promoIn,
            'promo_fim'        => $promoFim,
            'estoque_total'    => (int)($r[self::P['estoque']]     ?? 0),
            'estoque_minimo'   => (int)($r[self::P['estoque_min']] ?? 0),
            'peso_kg'          => $peso,
            'comprimento_cm'   => $comprimento,
            'largura_cm'       => $largura,
            'altura_cm'        => $altura,
            'descricao'        => $this->utf8($r[self::P['descricao']] ?? '') ?: null,
            'meta_title'       => $this->utf8($r[self::P['meta_title']] ?? '') ?: null,
            'meta_description' => $this->utf8($r[self::P['meta_desc']]  ?? '') ?: null,
            'meta_keywords'    => $this->utf8($r[self::P['meta_kw']]    ?? '') ?: null,
            'ativo'            => $ativo,
            'destaque'         => strtolower($this->utf8($r[self::P['destaque']]   ?? '')) === 'sim' ? 1 : 0,
            'lancamento'       => strtolower($this->utf8($r[self::P['lancamento']] ?? '')) === 'sim' ? 1 : 0,
            'vendidos'         => (int)($r[self::P['vendidos']] ?? 0),
            'tem_variacao'     => 0,
        ];

        // Verifica se já existe pelo tray_id
        $stmt = $this->db->prepare(
            "SELECT id, slug FROM produtos WHERE tray_id = ? LIMIT 1"
        );
        $stmt->execute([$trayId]);
        $existente = $stmt->fetch();

        if ($existente) {
            // UPDATE — mantém o slug existente
            unset($campos['slug']);
            $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($campos)));
            $params = array_values($campos);
            $params[] = $existente['id'];
            $this->db->prepare("UPDATE produtos SET {$sets} WHERE id = ?")->execute($params);
            $produtoId = (int)$existente['id'];
            $acao = 'atualizado';
        } else {
            // INSERT
            $cols   = implode(', ', array_keys($campos));
            $vals   = implode(', ', array_fill(0, count($campos), '?'));
            $this->db->prepare(
                "INSERT INTO produtos ({$cols}) VALUES ({$vals})"
            )->execute(array_values($campos));
            $produtoId = (int)$this->db->lastInsertId();

            // Vincula categoria
            if ($catId) {
                $this->db->prepare(
                    "INSERT IGNORE INTO produto_categorias (produto_id, categoria_id, principal)
                     VALUES (?, ?, 1)"
                )->execute([$produtoId, $catId]);
            }

            $acao = 'criado';
        }

        // Agenda imagens
        $this->agendarImagensProduto($produtoId, $r);

        // ── Registra estoque no ledger (EstoqueService) ───
        // Sem isso: produto_skus.estoque tem valor mas estoque_saldo/estoque_log ficam zerados.
        // corrigir() é idempotente: se o saldo não mudou, não cria log duplicado.
        $estoqueInicial = (int)($r[self::P['estoque']] ?? 0);
        if ($estoqueInicial > 0) {
            try {
                $this->estoque->corrigir(
                    $produtoId,
                    $estoqueInicial,
                    'Importação Tray',
                    ['sku_id' => null, 'referencia_tipo' => 'tray_import']
                );
            } catch (\Throwable $e) {
                // Não bloqueia o import — loga e segue
                error_log("[TrayImport] corrigir produto {$produtoId}: " . $e->getMessage());
            }
        }

        return $acao;
    }

    // ════════════════════════════════════════════════════
    // IMPORTAÇÃO DE VARIAÇÃO
    // ════════════════════════════════════════════════════

    private function processarVariacao(array $r): string {
        $trayProdId = trim($r[self::V['codigo_prod']] ?? '');
        $var1Nome   = $this->utf8(trim($r[self::V['var1_nome']]  ?? ''));
        $var1Valor  = $this->utf8(trim($r[self::V['var1_valor']] ?? ''));
        $sku        = $this->utf8(trim($r[self::V['referencia']] ?? ''));

        if (empty($trayProdId) || empty($var1Nome) || empty($var1Valor)) return 'ignorado';

        // Busca produto pelo tray_id
        $stmt = $this->db->prepare("SELECT id FROM produtos WHERE tray_id = ? LIMIT 1");
        $stmt->execute([$trayProdId]);
        $produtoId = $stmt->fetchColumn();
        if (!$produtoId) return 'ignorado'; // produto não importado ainda

        $produtoId = (int)$produtoId;
        $preco     = $this->preco($r[self::V['preco']] ?? '0');
        $precoPromo= $this->precoNullavel($r[self::V['preco_promo']] ?? '0');

        // ── Atributo tipo 1 ───────────────────────────────
        $tipo1Id = $this->findOrCreateAtributoTipo($var1Nome);
        $this->vincularVariacaoTipo($produtoId, $tipo1Id);

        // ── Atributo tipo 2 (se existir) ──────────────────
        $var2Nome  = $this->utf8(trim($r[self::V['var2_nome']]  ?? ''));
        $var2Valor = $this->utf8(trim($r[self::V['var2_valor']] ?? ''));
        $tipo2Id   = null;
        if (!empty($var2Nome) && !empty($var2Valor)) {
            $tipo2Id = $this->findOrCreateAtributoTipo($var2Nome);
            $this->vincularVariacaoTipo($produtoId, $tipo2Id);
        }

        // ── SKU ───────────────────────────────────────────
        // Verifica se SKU já existe por referência + produto
        $stmt = $this->db->prepare(
            "SELECT id FROM produto_skus WHERE produto_id = ? AND sku = ? LIMIT 1"
        );
        $stmt->execute([$produtoId, $sku]);
        $skuId = $stmt->fetchColumn();

        $skuDados = [
            'produto_id'  => $produtoId,
            'sku'         => $sku ?: null,
            'ean'         => $this->utf8($r[self::V['ean']] ?? '') ?: null,
            'preco'       => $preco > 0 ? $preco : 0.01,
            'preco_promo' => $precoPromo,
            'estoque'     => (int)($r[self::V['estoque']] ?? 0),
            'ativo'       => 1,
        ];

        if ($skuId) {
            unset($skuDados['produto_id']);
            $sets = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($skuDados)));
            $params = array_values($skuDados);
            $params[] = $skuId;
            $this->db->prepare("UPDATE produto_skus SET {$sets} WHERE id = ?")->execute($params);
            $acao = 'atualizado';
        } else {
            $cols = implode(', ', array_keys($skuDados));
            $vals = implode(', ', array_fill(0, count($skuDados), '?'));
            $this->db->prepare(
                "INSERT INTO produto_skus ({$cols}) VALUES ({$vals})"
            )->execute(array_values($skuDados));
            $skuId = (int)$this->db->lastInsertId();
            $acao  = 'criado';
        }

        // ── Atributos do SKU ──────────────────────────────
        $this->db->prepare("DELETE FROM sku_atributos WHERE sku_id = ?")->execute([$skuId]);
        $stmtAttr = $this->db->prepare(
            "INSERT INTO sku_atributos (sku_id, atributo_tipo_id, valor) VALUES (?,?,?)"
        );
        $stmtAttr->execute([$skuId, $tipo1Id, $var1Valor]);
        if ($tipo2Id && $var2Valor) {
            $stmtAttr->execute([$skuId, $tipo2Id, $var2Valor]);
        }

        // ── Atualiza produto: tem_variacao + estoque_total ─
        $this->db->prepare(
            "UPDATE produtos SET
                tem_variacao  = 1,
                estoque_total = COALESCE((
                    SELECT SUM(estoque) FROM produto_skus WHERE produto_id = ? AND ativo = 1
                ), 0)
             WHERE id = ?"
        )->execute([$produtoId, $produtoId]);

        // Imagens de variações ignoradas intencionalmente.
        // Apenas imagens do produto pai são importadas (via processarProduto).

        // ── Registra estoque do SKU no ledger (EstoqueService) ──
        $estoqueSku = (int)($r[self::V['estoque']] ?? 0);
        if ($estoqueSku > 0) {
            try {
                $this->estoque->corrigir(
                    $produtoId,
                    $estoqueSku,
                    'Importação Tray',
                    ['sku_id' => $skuId, 'referencia_tipo' => 'tray_import']
                );
            } catch (\Throwable $e) {
                error_log("[TrayImport] corrigir sku {$skuId}: " . $e->getMessage());
            }
        }

        return $acao;
    }

    // ════════════════════════════════════════════════════
    // DOWNLOAD DE IMAGENS (chamado pelo cron/admin)
    // ════════════════════════════════════════════════════

    public function processarFilaImagens(int $limite = 30): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM import_image_queue
             WHERE status = 'pendente' AND tentativas < 3
             ORDER BY id ASC LIMIT ?"
        );
        $stmt->execute([$limite]);
        $fila = $stmt->fetchAll();

        $ok = $erro = 0;
        foreach ($fila as $item) {
            // Marca como baixando
            $this->db->prepare(
                "UPDATE import_image_queue SET status='baixando', tentativas=tentativas+1 WHERE id=?"
            )->execute([$item['id']]);

            $arquivo = $this->baixarImagem($item['url']);

            if ($arquivo) {
                // Insere em produto_imagens
                $this->db->prepare(
                    "INSERT INTO produto_imagens (produto_id, arquivo, principal, ordem, sku_id)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE arquivo = VALUES(arquivo)"
                )->execute([
                    $item['produto_id'],
                    $arquivo,
                    $item['principal'],
                    $item['ordem'],
                    $item['sku_id'],
                ]);

                $this->db->prepare(
                    "UPDATE import_image_queue
                     SET status='concluido', processado_em=NOW() WHERE id=?"
                )->execute([$item['id']]);
                $ok++;
            } else {
                $this->db->prepare(
                    "UPDATE import_image_queue
                     SET status=IF(tentativas>=3,'erro','pendente'), erro='Download falhou' WHERE id=?"
                )->execute([$item['id']]);
                $erro++;
            }
        }

        return ['ok' => $ok, 'erro' => $erro, 'fila' => count($fila)];
    }

    private function baixarImagem(string $url): ?string {
        $ext     = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $extAllow= ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $extAllow)) $ext = 'jpg';

        $arquivo = bin2hex(random_bytes(10)) . '.' . $ext;
        $destDir = UPLOAD_PATH . '/products';
        $dest    = $destDir . '/' . $arquivo;

        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        $ctx = stream_context_create(['http' => [
            'timeout'         => 20,
            'user_agent'      => 'Mozilla/5.0 (compatible; ProductImporter/1.0)',
            'follow_location' => true,
        ]]);

        $dados = @file_get_contents($url, false, $ctx);
        if ($dados === false || strlen($dados) < 100) return null;

        file_put_contents($dest, $dados);

        // Redimensiona com GD se disponível
        if (extension_loaded('gd')) {
            (new UploadHelper())->resizeExistente($dest, 1200, 1200, $ext);
        }

        return $arquivo;
    }

    // ════════════════════════════════════════════════════
    // STATUS DO JOB
    // ════════════════════════════════════════════════════

    public function getJob(int $jobId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM import_jobs WHERE id = ? LIMIT 1");
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }

     public function getStatusFila(): array {
        $stmt = $this->db->query(
            "SELECT
                COUNT(CASE WHEN status = 'pendente'  THEN 1 END) AS pendentes,
                COUNT(CASE WHEN status = 'concluido' THEN 1 END) AS concluidos,
                COUNT(CASE WHEN status = 'erro'      THEN 1 END) AS erros,
                COUNT(*) AS total
            FROM import_image_queue"
        );

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'pendentes'  => (int) ($row['pendentes'] ?? 0),
            'concluidos' => (int) ($row['concluidos'] ?? 0),
            'erros'      => (int) ($row['erros'] ?? 0),
            'total'      => (int) ($row['total'] ?? 0),
        ];
    }

    // ════════════════════════════════════════════════════
    // HELPERS PRIVADOS
    // ════════════════════════════════════════════════════

    private function agendarImagensProduto(int $produtoId, array $r): void {
        // Limpa fila anterior do produto (evita duplicatas em re-import)
        $this->db->prepare(
            "DELETE FROM import_image_queue WHERE produto_id = ? AND sku_id IS NULL"
        )->execute([$produtoId]);

        $ordem = 0;
        $colunas = [
            self::P['img1'], self::P['img2'],
            self::P['img3'], self::P['img4'],
        ];
        foreach ($colunas as $col) {
            $url = trim($r[$col] ?? '');
            if ($url) {
                $this->agendarImagem($produtoId, $url, $ordem, $ordem === 0);
                $ordem++;
            }
        }

        // Imagens adicionais
        $adicionais = trim($r[self::P['imgs_adicionais']] ?? '');
        if ($adicionais) {
            foreach (explode(',', $adicionais) as $url) {
                $url = trim($url);
                if ($url) {
                    $this->agendarImagem($produtoId, $url, $ordem++, false);
                }
            }
        }
    }

    private function agendarImagem(
        int    $produtoId,
        string $url,
        int    $ordem,
        bool   $principal,
        ?int   $skuId = null
    ): void {
        $this->db->prepare(
            "INSERT INTO import_image_queue
             (produto_id, sku_id, url, ordem, principal)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE url = VALUES(url)"
        )->execute([$produtoId, $skuId, $url, $ordem, $principal ? 1 : 0]);
    }

    private function findOrCreateMarca(string $nome): ?int {
        if (empty($nome)) return null;
        $stmt = $this->db->prepare("SELECT id FROM marcas WHERE nome = ? LIMIT 1");
        $stmt->execute([$nome]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        $slug = SlugHelper::unique($nome, 'marcas');
        $this->db->prepare("INSERT INTO marcas (nome, slug, ativo) VALUES (?,?,1)")->execute([$nome, $slug]);
        return (int)$this->db->lastInsertId();
    }

    private function findOrCreateCategoria(string $nome): ?int {
        if (empty($nome)) return null;
        $stmt = $this->db->prepare("SELECT id FROM categorias WHERE nome = ? LIMIT 1");
        $stmt->execute([$nome]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        $slug = SlugHelper::unique($nome, 'categorias');
        $this->db->prepare(
            "INSERT INTO categorias (nome, slug, ativo) VALUES (?,?,1)"
        )->execute([$nome, $slug]);
        return (int)$this->db->lastInsertId();
    }

    private function findOrCreateAtributoTipo(string $nome): int {
        $slug = SlugHelper::make($nome); // slug sem uniqueness check
        $stmt = $this->db->prepare("SELECT id FROM atributo_tipos WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;

        $this->db->prepare(
            "INSERT INTO atributo_tipos
             (nome, slug, tipo_display, papel, ordenacao)
             VALUES (?, ?, 'text', 'variacao', 0)"
        )->execute([$nome, $slug]);
        return (int)$this->db->lastInsertId();
    }

    private function vincularVariacaoTipo(int $produtoId, int $tipoId): void {
        $this->db->prepare(
            "INSERT IGNORE INTO produto_variacao_tipos
             (produto_id, atributo_tipo_id, ordenacao) VALUES (?,?,0)"
        )->execute([$produtoId, $tipoId]);
    }

    private function lerLinhas(string $arquivo, int $startLine, int $limit): array {
        $rows   = [];
        $handle = fopen($arquivo, 'r');
        if (!$handle) return [];

        $lineNum = 0;
        while (!feof($handle) && count($rows) < $limit) {
            $row = fgetcsv($handle, 0, self::CSV_DELIMITER, '"');
            if ($row === false) continue;
            $lineNum++;
            if ($lineNum <= $startLine) continue; // pula header + linhas anteriores ao offset
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

    private function finalizarJob(int $jobId): void {
        $this->db->prepare(
            "UPDATE import_jobs
             SET status = 'concluido', concluido_em = NOW() WHERE id = ?"
        )->execute([$jobId]);
    }

    private function utf8(string $str): string {
        return mb_convert_encoding($str, 'UTF-8', self::CSV_ENCODING);
    }

    private function preco(string $str): float {
        return (float)str_replace(['.', ','], ['', '.'], trim($str));
    }

    private function precoNullavel(string $str): ?float {
        $v = $this->preco($str);
        return $v > 0 ? $v : null;
    }

    private function pesoKg(string $str): ?float {
        $gramas = (float)str_replace(',', '.', trim($str));
        return $gramas > 0 ? round($gramas / 1000, 3) : null;
    }

    private function decimal(string $str): ?float {
        $v = (float)str_replace(',', '.', trim($str));
        return $v > 0 ? $v : null;
    }

    private function data(string $str): ?string {
        $str = trim($str);
        if (empty($str)) return null;
        // Tray: DD/MM/YYYY HH:MM ou DD/MM/YYYY
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $str, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }
}