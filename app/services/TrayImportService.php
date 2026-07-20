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

    private ImageUploadService $img;   // ← NOVO

    public function __construct() {
        $this->db      = Database::getInstance()->getConnection();
        $this->estoque = new EstoqueService();

        $this->img     = ImageUploadService::fromEnv();   // ← NOVO
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
                // ANTES: error_log("[TrayImport] Linha {$linhaNum}: ...")
                LogService::exception($e, 'warning', 'import', [
                    'job_id' => $jobId,
                    'linha'  => $linhaNum ?? 0,
                ]);
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
            // Preserva o slug já indexado (coluna "Endereço do Produto" do CSV da Tray).
            // Gerar slug novo quebraria todas as URLs no Google.
            'slug'             => $this->resolverSlug($nome, $linha['Endereço do Produto'] ?? null),
            'marca_id'         => $marcaId,
            'categoria_id'     => null,
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

    // public function processarFilaImagens(int $limite = 30): array {
    //     $stmt = $this->db->prepare(
    //         "SELECT * FROM import_image_queue
    //          WHERE status = 'pendente' AND tentativas < 3
    //          ORDER BY id ASC LIMIT ?"
    //     );
    //     $stmt->execute([$limite]);
    //     $fila = $stmt->fetchAll();

    //     $ok = $erro = 0;
    //     foreach ($fila as $item) {
    //         // Marca como baixando
    //         $this->db->prepare(
    //             "UPDATE import_image_queue SET status='baixando', tentativas=tentativas+1 WHERE id=?"
    //         )->execute([$item['id']]);

    //         $arquivo = $this->baixarImagem($item['url']);

    //         if ($arquivo) {
    //             // Insere em produto_imagens
    //             $this->db->prepare(
    //                 "INSERT INTO produto_imagens (produto_id, arquivo, principal, ordem, sku_id)
    //                  VALUES (?, ?, ?, ?, ?)
    //                  ON DUPLICATE KEY UPDATE arquivo = VALUES(arquivo)"
    //             )->execute([
    //                 $item['produto_id'],
    //                 $arquivo,
    //                 $item['principal'],
    //                 $item['ordem'],
    //                 $item['sku_id'],
    //             ]);

    //             $this->db->prepare(
    //                 "UPDATE import_image_queue
    //                  SET status='concluido', processado_em=NOW() WHERE id=?"
    //             )->execute([$item['id']]);
    //             $ok++;
    //         } else {
    //             $this->db->prepare(
    //                 "UPDATE import_image_queue
    //                  SET status=IF(tentativas>=3,'erro','pendente'), erro='Download falhou' WHERE id=?"
    //             )->execute([$item['id']]);
    //             $erro++;
    //         }
    //     }

    //     return ['ok' => $ok, 'erro' => $erro, 'fila' => count($fila)];
    // }

    /**
     * Processa a fila de imagens: baixa da Tray -> valida -> WebP -> R2.
     *
     * [AUDITORIA] Endurecido com:
     *  A. RECOVERY: itens presos em 'baixando' (request anterior morreu)
     *     voltam a 'pendente' após 10 min — antes ficavam presos PARA SEMPRE.
     *  B. CLAIM ATÔMICO: dois requests simultâneos não processam o mesmo item
     *     (mesmo padrão do carrinho abandonado: UPDATE condicional + rowCount).
     *  C. TRY/CATCH POR ITEM: uma PDOException (FK, coluna faltando) marca o
     *     ITEM como erro e segue — antes matava o batch inteiro e deixava
     *     itens em 'baixando'.
     *  D. ORÇAMENTO DE TEMPO: para antes do max_execution_time; o front
     *     rechama e continua de onde parou.
     */
    public function processarFilaImagens(int $limite = 25): array
    {
        // A. RECOVERY — resgata itens de execuções que morreram no meio.
        $this->db->exec(
            "UPDATE import_image_queue
                SET status = 'pendente'
              WHERE status = 'baixando'
                AND claim_em < (NOW() - INTERVAL 10 MINUTE)
                AND tentativas < 3"
        );
        // Presos com 3+ tentativas viram erro definitivo:
        $this->db->exec(
            "UPDATE import_image_queue
                SET status = 'erro', erro = 'Excesso de tentativas (worker morreu)'
              WHERE status = 'baixando'
                AND claim_em < (NOW() - INTERVAL 10 MINUTE)
                AND tentativas >= 3"
        );

        $stmt = $this->db->prepare(
            "SELECT * FROM import_image_queue
              WHERE status = 'pendente'
              ORDER BY id ASC
              LIMIT ?"
        );
        $stmt->bindValue(1, max(1, min(50, $limite)), PDO::PARAM_INT);
        $stmt->execute();
        $fila = $stmt->fetchAll();

        $ok = $erro = $pulados = 0;
        $inicio = microtime(true);

        foreach ($fila as $item) {
            // D. ORÇAMENTO DE TEMPO: 20s de trabalho por request. O que sobrar
            // fica 'pendente' e a próxima chamada do front continua.
            if ((microtime(true) - $inicio) > 20) {
                break;
            }

            // B. CLAIM ATÔMICO: só processa se ESTE request converteu
            // pendente->baixando. Se outro request chegou antes, pula.
            $claim = $this->db->prepare(
                "UPDATE import_image_queue
                    SET status = 'baixando', tentativas = tentativas + 1, claim_em = NOW()
                  WHERE id = ? AND status = 'pendente'"
            );
            $claim->execute([$item['id']]);
            if ($claim->rowCount() === 0) {
                $pulados++;
                continue; // outro worker reivindicou
            }

            // C. TRY/CATCH POR ITEM: falha isolada não derruba o batch.
            try {
                $urls = $this->baixarImagem($item['url']);

                if ($urls !== null) {
                    $this->db->prepare(
                        "INSERT INTO produto_imagens
                            (produto_id, arquivo, arquivo_thumb, principal, ordem, sku_id)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            arquivo = VALUES(arquivo),
                            arquivo_thumb = VALUES(arquivo_thumb)"
                    )->execute([
                        $item['produto_id'],
                        $urls['full'] ?? '',
                        $urls['thumb'] ?? '',
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
                            SET status=IF(tentativas>=3,'erro','pendente'),
                                erro='Download/validação falhou'
                          WHERE id=?"
                    )->execute([$item['id']]);
                    $erro++;
                }

            } catch (\Throwable $e) {
                // FK violada, coluna faltando, R2 fora... marca o ITEM e segue.
                LogService::exception($e, 'error', 'import', [
                    'queue_id'   => (int) $item['id'],
                    'produto_id' => (int) $item['produto_id'],
                ]);
                $this->db->prepare(
                    "UPDATE import_image_queue
                        SET status=IF(tentativas>=3,'erro','pendente'), erro=?
                      WHERE id=?"
                )->execute([mb_substr($e->getMessage(), 0, 250), $item['id']]);
                $erro++;
            }

            // Educação com a CDN da Tray: 30k downloads em rajada podem
            // disparar rate-limit deles e falhar tudo em massa.
            usleep(150000); // 150ms entre downloads
        }

        return ['ok' => $ok, 'erro' => $erro, 'pulados' => $pulados, 'fila' => count($fila)];
    }

    /**
     * Baixa uma imagem da Tray (URL) e sobe para o R2, retornando as URLs
     * públicas (full + thumb). Retorna null se o download/validação falhar.
     *
     * Mudou de disco local -> R2. A coluna produto_imagens.arquivo passa a
     * guardar a URL COMPLETA do R2. Validação e anti-SSRF ficam no service.
     *
     * @return array{full:string,thumb:string}|null
     */
    private function baixarImagem(string $url): ?array
    {
        try {
            
            $urls = $this->img->uploadFromUrl($url, 'produtos', [
                'full'  => 1200,
                'thumb' => 400,
            ]);
            return $urls;   // ['full'=>'https://media...', 'thumb'=>'https://media...'] ou null

        } catch (\RuntimeException $e) {
            // URL insegura (SSRF) ou imagem inválida -> falha controlada, loga e segue
            LogService::warning('Imagem da Tray rejeitada', [
                'url_host' => parse_url($url, PHP_URL_HOST),   // NÃO loga a URL inteira
                'motivo'   => $e->getMessage(),
            ], 'import');
            return null;

        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'import', [
                'url_host' => parse_url($url, PHP_URL_HOST),
            ]);
            return null;
        }
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

    /**
     * Extrai o slug da URL da Tray, preservando o caminho já indexado.
     *
     * Entrada: https://www.sportmoto.com.br/produtos/bauletos-laterais-...-braz-acessorios
     * Saída:   bauletos-laterais-...-braz-acessorios
     *
     * REGRAS:
     *  - Usa o ÚLTIMO segmento do path (a Tray sempre põe o slug no fim).
     *  - Remove query string, âncora e barra final.
     *  - Normaliza para minúsculas e recusa caracteres fora de [a-z0-9-]
     *    (defesa: o CSV é fonte externa; slug entra em URL e em rota).
     *  - Retorna null se a coluna vier vazia ou o slug for inválido —
     *    o chamador decide o fallback.
     */
    private function slugDaUrlTray(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        // Aceita URL completa OU já só o slug (planilhas variam)
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false || $path === '') {
            $path = $url; // veio sem esquema/host
        }

        // Último segmento: .../produtos/{slug}  ->  {slug}
        $path = rtrim($path, '/');
        $slug = substr($path, (int) strrpos($path, '/') + 1);

        // Limpeza defensiva
        $slug = strtolower(trim(urldecode($slug)));
        $slug = preg_replace('/\.(html?|php)$/', '', $slug) ?? $slug; // .html no fim, se houver
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? $slug;   // só a-z 0-9 -
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;            // colapsa --
        $slug = trim($slug, '-');

        // Sanidade: slug de 1-2 chars provavelmente é lixo da planilha
        if (strlen($slug) < 3 || strlen($slug) > 200) {
            return null;
        }

        return $slug;
    }

    /**
     * Resolve o slug definitivo de um produto na importação.
     *
     * PRECEDÊNCIA:
     *  1. Slug da URL da Tray (preserva SEO)  ← o que queremos em 99% dos casos
     *  2. Se esse slug já pertence a OUTRO produto, sufixa (-2, -3...)
     *  3. Se a coluna veio vazia/inválida, gera pelo nome (comportamento antigo)
     *
     * @param string      $nome       Nome do produto (fallback).
     * @param string|null $urlTray    Conteúdo da coluna "Endereço do Produto".
     * @param int|null    $produtoId  ID se for UPDATE (ignora colisão consigo mesmo).
     */
    private function resolverSlug(string $nome, ?string $urlTray, ?int $produtoId = null): string
    {
        $slug = $this->slugDaUrlTray($urlTray);

        // 3. Sem slug utilizável -> comportamento antigo
        if ($slug === null) {
            LogService::warning('Produto sem URL da Tray — slug gerado pelo nome', [
                'nome' => mb_substr($nome, 0, 80),
            ], 'import');
            return SlugHelper::unique($nome, 'produtos');
        }

        // 2. Colisão: o slug já é de OUTRO produto?
        $sql = "SELECT id FROM produtos WHERE slug = ?" . ($produtoId ? " AND id <> ?" : "") . " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($produtoId ? [$slug, $produtoId] : [$slug]);

        if (!$stmt->fetchColumn()) {
            return $slug; // 1. livre -> preserva exatamente
        }

        // Colisão real: sufixa até achar livre. NÃO sobrescreve o outro produto.
        $base = $slug;
        for ($i = 2; $i <= 50; $i++) {
            $tentativa = $base . '-' . $i;
            $stmt = $this->db->prepare(
                "SELECT id FROM produtos WHERE slug = ?" . ($produtoId ? " AND id <> ?" : "") . " LIMIT 1"
            );
            $stmt->execute($produtoId ? [$tentativa, $produtoId] : [$tentativa]);

            if (!$stmt->fetchColumn()) {
                LogService::warning('Slug da Tray duplicado — sufixado', [
                    'slug_original' => $base,
                    'slug_usado'    => $tentativa,
                ], 'import');
                return $tentativa;
            }
        }

        // Improvável: 50 colisões. Cai no gerador antigo.
        return SlugHelper::unique($nome, 'produtos');
    }
}