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

    // ────────────────────────────────────────────────────
    // MAPEAMENTO DE COLUNAS DO CSV DE PRODUTOS
    //
    // A fonte da verdade é o NOME da coluna (P_COLS), resolvido a
    // partir do header do arquivo em resolverColunas(). Os índices
    // em P são só o fallback para header ausente/ilegível.
    //
    // Por que não confiar na posição: o export da Tray tem 50 colunas
    // e três dos índices aqui estavam deslocados em 2 casas — 'exibir'
    // lia "Imagens adicionais" (sempre vazia, então todo produto entrava
    // INATIVO), 'imgs_adicionais' lia o NCM e 'categoria' lia justamente
    // a URL do produto. Errar em silêncio é o padrão de falha de mapa
    // posicional; por nome, uma coluna que sai de lugar é detectada.
    // ────────────────────────────────────────────────────

    /** campo interno => nome da coluna no CSV (comparado normalizado) */
    const P_COLS = [
        'codigo'         => 'Código produto',
        'nome'           => 'Nome produto',
        'descricao'      => 'Descrição grande',
        'img1'           => 'Imagem principal',
        'img2'           => 'Imagem 2',
        'img3'           => 'Imagem 3',
        'img4'           => 'Imagem 4',
        'preco'          => 'Preço venda',
        'peso'           => 'Peso',
        'estoque'        => 'Estoque atual',
        'estoque_min'    => 'Estoque mínimo',
        'disponivel'     => 'Disponível',
        'promo_inicio'   => 'Inicio promoção',
        'promo_fim'      => 'Fim promoção',
        'preco_promo'    => 'Preço promoção',
        'destaque'       => 'Selo destaque',
        'lancamento'     => 'Selo lançamento',
        'vendidos'       => 'Quantidade vendida',
        'marca'          => 'Marca',
        'modelo'         => 'Modelo',
        'referencia'     => 'Referência',
        'ean'            => 'EAN',
        'comprimento'    => 'Comprimento',
        'largura'        => 'Largura',
        'altura'         => 'Altura',
        'meta_title'     => 'SEO Título',
        'meta_desc'      => 'SEO descrição simplificada',
        'meta_kw'        => 'SEO palavra chave',
        'url'            => 'Endereço do Produto (URL Tray)',
        'imgs_adicionais'=> 'Imagens adicionais',
        'categoria'      => 'Nome categoria',
        'exibir'         => 'Exibir na loja',
    ];

    // Índices das colunas no CSV de produtos (FALLBACK — ver P_COLS)
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
        'url'           => 45,
        'imgs_adicionais'=> 46,
        'categoria'     => 47,
        'exibir'        => 48,
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

    /** campo => índice, resolvido do header em resolverColunas() */
    private array $colP = self::P;

    /** campos que caíram no índice posicional por não achar o nome */
    private array $colunasPorFallback = [];

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

        if ($job['tipo'] === 'slugs') {
            return $this->previewSlugs($job);
        }

        $this->resolverColunas($job['arquivo_tmp']);

        $rows    = $this->lerLinhas($job['arquivo_tmp'], 1, 5);
        $preview = [];

        if ($job['tipo'] === 'produtos') {
            foreach ($rows as $r) {
                $preview[] = [
                    'tray_id' => $r[$this->colP['codigo']] ?? '',
                    'nome'    => $this->utf8($r[$this->colP['nome']] ?? ''),
                    'marca'   => $this->utf8($r[$this->colP['marca']] ?? ''),
                    'preco'   => $this->preco($r[$this->colP['preco']] ?? '0'),
                    'estoque' => (int)($r[$this->colP['estoque']] ?? 0),
                    'categoria'=> $this->utf8($r[$this->colP['categoria']] ?? ''),
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

        // Resolve as colunas pelo header ANTES de ler qualquer linha.
        // Cada chunk é uma requisição nova (instância nova do service),
        // então isto roda por chunk — custo de 1 fgetcsv, irrelevante.
        $this->resolverColunas($job['arquivo_tmp']);

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
    // JOB 'SLUGS' — reescreve só produtos.slug pelo CSV
    //
    // Casa a coluna "Referência" do CSV com produtos.sku_legado e
    // sobrescreve o slug com o endereço da coluna "Endereço do
    // Produto (URL Tray)". Não toca em mais nenhum campo.
    //
    // Existe separado do import completo porque é uma operação de
    // SEO, não de catálogo: precisa rodar sozinha, ser conferida
    // antes de gravar, e não pode arrastar preço/estoque junto.
    // ════════════════════════════════════════════════════

    /** Classificações possíveis de uma linha do job 'slugs'. */
    private const SLUG_OK            = 'alterado';
    private const SLUG_IGUAL         = 'ja_igual';
    private const SLUG_SEM_REF       = 'sem_referencia';
    private const SLUG_SEM_URL       = 'sem_url';
    private const SLUG_NAO_ENCONTRADO= 'nao_encontrado';
    private const SLUG_AMBIGUO       = 'ambiguo';
    private const SLUG_CONFLITO      = 'conflito';

    /**
     * Processa um chunk do job 'slugs'.
     *
     * @param bool $aplicar false = verificação (dry-run, não grava nada).
     * @return array{
     *   ok:bool, concluido:bool, processadas:int, total:int,
     *   resumo:array<string,int>, linhas:array<int,array<string,mixed>>
     * }
     */
    public function processarChunkSlugs(int $jobId, bool $aplicar): array {
        $job = $this->getJob($jobId);
        if (!$job)                    return ['ok' => false, 'msg' => 'Job não encontrado.'];
        if ($job['tipo'] !== 'slugs') return ['ok' => false, 'msg' => 'Job não é do tipo "slugs".'];

        $this->resolverColunas($job['arquivo_tmp']);

        // Sem estas duas colunas o job não tem o que fazer — para antes
        // de percorrer 6k linhas para não gravar nada.
        if (in_array('referencia', $this->colunasPorFallback, true)
            || in_array('url', $this->colunasPorFallback, true)) {
            return ['ok' => false, 'msg' =>
                'O CSV não tem as colunas "Referência" e/ou "Endereço do Produto (URL Tray)". '
                . 'Exporte novamente pelo painel da Tray.'];
        }

        $this->db->prepare("UPDATE import_jobs SET status = 'processando' WHERE id = ?")
                 ->execute([$jobId]);

        $offset = (int)$job['processadas'];
        $rows   = $this->lerLinhas($job['arquivo_tmp'], $offset + 1, self::CHUNK_SIZE);

        if (empty($rows)) {
            $this->finalizarJob($jobId);
            return ['ok' => true, 'concluido' => true, 'processadas' => $offset,
                    'total' => (int)$job['total_linhas'], 'resumo' => [], 'linhas' => []];
        }

        $resumo = array_fill_keys([
            self::SLUG_OK, self::SLUG_IGUAL, self::SLUG_SEM_REF, self::SLUG_SEM_URL,
            self::SLUG_NAO_ENCONTRADO, self::SLUG_AMBIGUO, self::SLUG_CONFLITO,
        ], 0);
        $linhas = [];

        foreach ($rows as $idx => $r) {
            $linhaNum = $offset + $idx + 2;   // +2: header + base 1
            $res      = $this->avaliarLinhaSlug($r, $aplicar);

            $resumo[$res['status']]++;

            // Linhas "já iguais" são a maioria e não interessam ao relatório.
            if ($res['status'] !== self::SLUG_IGUAL) {
                $linhas[] = ['linha' => $linhaNum] + $res;
            }
        }

        $novasProcessadas = $offset + count($rows);
        $concluido        = $novasProcessadas >= (int)$job['total_linhas'];

        // atualizados = slugs trocados (ou a trocar, no dry-run)
        // ignorados   = tudo que não muda: já igual + os problemas
        $this->db->prepare(
            "UPDATE import_jobs SET
                processadas  = processadas + ?,
                atualizados  = atualizados + ?,
                ignorados    = ignorados   + ?,
                status       = IF(? >= total_linhas, 'concluido', 'processando'),
                concluido_em = IF(? >= total_linhas, NOW(), NULL)
             WHERE id = ?"
        )->execute([
            count($rows),
            $resumo[self::SLUG_OK],
            count($rows) - $resumo[self::SLUG_OK],
            $novasProcessadas, $novasProcessadas,
            $jobId,
        ]);

        // O arquivo NÃO é apagado ao concluir (diferente do import
        // normal): a verificação e a aplicação são duas passadas sobre
        // o MESMO arquivo. Quem apaga é finalizarJobSlugs().
        return [
            'ok'          => true,
            'concluido'   => $concluido,
            'processadas' => $novasProcessadas,
            'total'       => (int)$job['total_linhas'],
            'resumo'      => $resumo,
            'linhas'      => $linhas,
        ];
    }

    /**
     * Avalia (e opcionalmente aplica) o slug de UMA linha do CSV.
     *
     * Ordem das guardas — cada uma é um motivo diferente de não gravar,
     * e o relatório precisa distinguir todos para o admin saber o que
     * corrigir na Tray:
     *   1. sem Referência        → linha inútil para casar
     *   2. sem URL utilizável    → nada com que sobrescrever
     *   3. nenhum produto casou  → produto não importado ainda
     *   4. mais de um casou      → sku_legado duplicado, não dá p/ escolher
     *   5. slug já é o do CSV    → no-op
     *   6. slug é de OUTRO produto → violaria uk_slug; NÃO sufixa,
     *      porque sufixar gravaria uma URL que o Google não conhece —
     *      pior que não gravar. Reporta e deixa a decisão com o admin.
     */
    private function avaliarLinhaSlug(array $r, bool $aplicar): array {
        $referencia = trim($this->utf8($r[$this->colP['referencia']] ?? ''));
        $urlBruta   = trim($this->utf8($r[$this->colP['url']] ?? ''));
        $nome       = trim($this->utf8($r[$this->colP['nome']] ?? ''));

        $base = ['referencia' => $referencia, 'nome' => mb_substr($nome, 0, 90),
                 'de' => null, 'para' => null, 'produto_id' => null];

        if ($referencia === '') {
            return $base + ['status' => self::SLUG_SEM_REF,
                            'detalhe' => 'Linha sem "Referência" — impossível casar com o site.'];
        }

        $slugNovo = $this->slugDaUrlTray($urlBruta);
        if ($slugNovo === null) {
            return $base + ['status' => self::SLUG_SEM_URL,
                            'detalhe' => 'Coluna "Endereço do Produto" vazia ou inválida.'];
        }
        $base['para'] = $slugNovo;

        $stmt = $this->db->prepare(
            "SELECT id, slug FROM produtos
             WHERE sku_legado = ? AND deleted_at IS NULL
             ORDER BY id ASC LIMIT 5"
        );
        $stmt->execute([$referencia]);
        $casados = $stmt->fetchAll();

        if (!$casados) {
            return $base + ['status' => self::SLUG_NAO_ENCONTRADO,
                            'detalhe' => "Nenhum produto com sku_legado = \"{$referencia}\"."];
        }

        if (count($casados) > 1) {
            $ids = implode(', ', array_column($casados, 'id'));
            return $base + ['status' => self::SLUG_AMBIGUO,
                            'detalhe' => "Referência \"{$referencia}\" está em mais de um produto (IDs {$ids}). Corrija o duplicado antes."];
        }

        $produtoId  = (int)$casados[0]['id'];
        $slugAtual  = (string)$casados[0]['slug'];
        $base['produto_id'] = $produtoId;
        $base['de']         = $slugAtual;

        if ($slugAtual === $slugNovo) {
            return $base + ['status' => self::SLUG_IGUAL, 'detalhe' => null];
        }

        $conf = $this->db->prepare(
            "SELECT id FROM produtos WHERE slug = ? AND id <> ? LIMIT 1"
        );
        $conf->execute([$slugNovo, $produtoId]);
        if ($donoId = $conf->fetchColumn()) {
            return $base + ['status' => self::SLUG_CONFLITO,
                            'detalhe' => "A URL \"{$slugNovo}\" já pertence ao produto ID {$donoId}. Nada foi alterado."];
        }

        if ($aplicar) {
            $this->db->prepare("UPDATE produtos SET slug = ? WHERE id = ?")
                     ->execute([$slugNovo, $produtoId]);

            LogService::info('Slug sobrescrito pelo CSV da Tray', [
                'produto_id' => $produtoId,
                'referencia' => $referencia,
                'de'         => $slugAtual,
                'para'       => $slugNovo,
            ], 'import');
        }

        return $base + ['status' => self::SLUG_OK, 'detalhe' => null];
    }

    /**
     * Zera os contadores para uma nova passada sobre o mesmo arquivo.
     * A verificação percorre o CSV inteiro; sem isto, a aplicação
     * começaria de processadas = total e não faria nada.
     */
    public function resetarJobSlugs(int $jobId): array {
        $job = $this->getJob($jobId);
        if (!$job)                    return ['ok' => false, 'msg' => 'Job não encontrado.'];
        if ($job['tipo'] !== 'slugs') return ['ok' => false, 'msg' => 'Job não é do tipo "slugs".'];

        if (!is_file($job['arquivo_tmp'])) {
            return ['ok' => false, 'msg' => 'O arquivo do job expirou. Envie o CSV novamente.'];
        }

        $this->db->prepare(
            "UPDATE import_jobs SET
                processadas = 0, criados = 0, atualizados = 0, ignorados = 0,
                erros_json = NULL, status = 'aguardando', concluido_em = NULL
             WHERE id = ?"
        )->execute([$jobId]);

        return ['ok' => true, 'total' => (int)$job['total_linhas']];
    }

    /** Apaga o CSV temporário do job de slugs (fim das duas passadas). */
    public function finalizarJobSlugs(int $jobId): void {
        $job = $this->getJob($jobId);
        if ($job && !empty($job['arquivo_tmp'])) {
            @unlink($job['arquivo_tmp']);
        }
    }

    /** Preview do job 'slugs': o de/para das 5 primeiras linhas. */
    private function previewSlugs(array $job): array {
        $this->resolverColunas($job['arquivo_tmp']);

        $faltando = array_values(array_intersect(
            ['referencia', 'url'], $this->colunasPorFallback
        ));
        if ($faltando) {
            return ['ok' => false, 'msg' =>
                'CSV sem as colunas necessárias: ' . implode(', ', $faltando)
                . '. Exporte novamente pelo painel da Tray.'];
        }

        $preview = [];
        foreach ($this->lerLinhas($job['arquivo_tmp'], 1, 5) as $r) {
            $res = $this->avaliarLinhaSlug($r, false);   // nunca grava
            $preview[] = [
                'referencia' => $res['referencia'],
                'nome'       => $res['nome'],
                'de'         => $res['de'],
                'para'       => $res['para'],
                'status'     => $res['status'],
                'detalhe'    => $res['detalhe'] ?? null,
            ];
        }

        return ['ok' => true, 'preview' => $preview, 'total' => (int)$job['total_linhas']];
    }

    // ════════════════════════════════════════════════════
    // IMPORTAÇÃO DE PRODUTO
    // ════════════════════════════════════════════════════

    private function processarProduto(array $r): string {
        $trayId   = trim($r[$this->colP['codigo']] ?? '');
        $nome     = $this->utf8(trim($r[$this->colP['nome']] ?? ''));
        if (empty($trayId) || empty($nome)) return 'ignorado';

        $disponivel = strtolower($this->utf8($r[$this->colP['disponivel']] ?? '')) === 'sim';
        $exibir     = strtolower($this->utf8($r[$this->colP['exibir']]    ?? '')) === 'sim';
        $ativo      = ($disponivel && $exibir) ? 1 : 0;

        $preco      = $this->preco($r[$this->colP['preco']]      ?? '0');
        $precoPromo = $this->precoNullavel($r[$this->colP['preco_promo']] ?? '0');
        $promoIn    = $this->data($r[$this->colP['promo_inicio']] ?? '');
        $promoFim   = $this->data($r[$this->colP['promo_fim']]   ?? '');

        $peso        = $this->pesoKg($r[$this->colP['peso']] ?? '0');
        $comprimento = $this->decimal($r[$this->colP['comprimento']] ?? '0');
        $largura     = $this->decimal($r[$this->colP['largura']]     ?? '0');
        $altura      = $this->decimal($r[$this->colP['altura']]      ?? '0');

        $marcaId    = $this->findOrCreateMarca($this->utf8($r[$this->colP['marca']] ?? ''));
        $catId      = $this->findOrCreateCategoria($this->utf8($r[$this->colP['categoria']] ?? ''));

        // ── Produto já existe? Precisa ser resolvido ANTES do slug ──
        // O resolverSlug() ignora colisão consigo mesmo e usa o slug
        // atual como fallback — os dois dependem do id existente.
        $stmt = $this->db->prepare(
            "SELECT id, slug FROM produtos WHERE tray_id = ? LIMIT 1"
        );
        $stmt->execute([$trayId]);
        $existente = $stmt->fetch();

        $produtoIdExistente = $existente ? (int)$existente['id'] : null;
        $slugAtual          = $existente ? (string)$existente['slug'] : null;

        $campos = [
            'tray_id'          => $trayId,
            'nome'             => $nome,
            // A URL vem da coluna "Endereço do Produto (URL Tray)" do CSV —
            // é a mesma que o Google indexou em www.sportmoto.com.br. É o
            // arquivo que manda, tanto na criação quanto na atualização.
            'slug'             => $this->resolverSlug(
                                      $nome,
                                      $r[$this->colP['url']] ?? null,
                                      $produtoIdExistente,
                                      $slugAtual
                                  ),
            'marca_id'         => $marcaId,
            'categoria_id'     => $catId ?: null,
            'sku_legado'       => $this->utf8($r[$this->colP['referencia']] ?? '') ?: null,
            'preco'            => $preco > 0 ? $preco : 0.01,
            'preco_promo'      => $precoPromo,
            'promo_inicio'     => $promoIn,
            'promo_fim'        => $promoFim,
            'estoque_total'    => (int)($r[$this->colP['estoque']]     ?? 0),
            'estoque_minimo'   => (int)($r[$this->colP['estoque_min']] ?? 0),
            'peso_kg'          => $peso,
            'comprimento_cm'   => $comprimento,
            'largura_cm'       => $largura,
            'altura_cm'        => $altura,
            'descricao'        => $this->utf8($r[$this->colP['descricao']] ?? '') ?: null,
            'meta_title'       => $this->utf8($r[$this->colP['meta_title']] ?? '') ?: null,
            'meta_description' => $this->utf8($r[$this->colP['meta_desc']]  ?? '') ?: null,
            'meta_keywords'    => $this->utf8($r[$this->colP['meta_kw']]    ?? '') ?: null,
            'ativo'            => $ativo,
            'destaque'         => strtolower($this->utf8($r[$this->colP['destaque']]   ?? '')) === 'sim' ? 1 : 0,
            'lancamento'       => strtolower($this->utf8($r[$this->colP['lancamento']] ?? '')) === 'sim' ? 1 : 0,
            'vendidos'         => (int)($r[$this->colP['vendidos']] ?? 0),
            'tem_variacao'     => 0,
        ];

        if ($existente) {
            // UPDATE — o slug do CSV MANDA (é a URL indexada). O
            // resolverSlug() já devolveu o slug atual quando a coluna
            // veio vazia, então aqui nunca há regeneração pelo nome.
            $sets   = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($campos)));
            $params = array_values($campos);
            $params[] = $produtoIdExistente;
            $this->db->prepare("UPDATE produtos SET {$sets} WHERE id = ?")->execute($params);
            $produtoId = $produtoIdExistente;
            $acao = 'atualizado';

            if ($slugAtual !== null && $slugAtual !== $campos['slug']) {
                LogService::info('Slug do produto atualizado pelo CSV da Tray', [
                    'produto_id' => $produtoId,
                    'tray_id'    => $trayId,
                    'de'         => $slugAtual,
                    'para'       => $campos['slug'],
                ], 'import');
            }
        } else {
            // INSERT
            $cols   = implode(', ', array_keys($campos));
            $vals   = implode(', ', array_fill(0, count($campos), '?'));
            $this->db->prepare(
                "INSERT INTO produtos ({$cols}) VALUES ({$vals})"
            )->execute(array_values($campos));
            $produtoId = (int)$this->db->lastInsertId();
            $acao = 'criado';
        }

        // Vincula categoria (pivot). Roda nos DOIS caminhos: antes só
        // acontecia no INSERT, então produto já existente nunca ganhava
        // a categoria mesmo com a coluna preenchida no CSV.
        if ($catId) {
            $this->db->prepare(
                "INSERT IGNORE INTO produto_categorias (produto_id, categoria_id, principal)
                 VALUES (?, ?, 1)"
            )->execute([$produtoId, $catId]);
        }

        // Agenda imagens
        $this->agendarImagensProduto($produtoId, $r);

        // ── Registra estoque no ledger (EstoqueService) ───
        // Sem isso: produto_skus.estoque tem valor mas estoque_saldo/estoque_log ficam zerados.
        // corrigir() é idempotente: se o saldo não mudou, não cria log duplicado.
        $estoqueInicial = (int)($r[$this->colP['estoque']] ?? 0);
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

        // EAN da linha (normalizado — vazio vira NULL)
        $ean = $this->utf8($r[self::V['ean']] ?? '') ?: null;

        // ── Guarda de EAN duplicado (uk_ean é GLOBAL) ──
        // A detecção de SKU existente é por (produto_id + sku), mas o
        // uk_ean é único em TODA a tabela. Um EAN repetido em outro
        // produto (erro de cadastro comum na Tray) estouraria 1062 no
        // INSERT. Decisão: importar SEM o EAN e logar o conflito —
        // não perde o produto, e registra pra investigar a origem.
        if ($ean !== null) {
            $stmtEan = $this->db->prepare(
                "SELECT ps.id, ps.produto_id, ps.sku
                 FROM produto_skus ps
                 WHERE ps.ean = ?
                   AND NOT (ps.produto_id = ? AND ps.sku = ?)
                 LIMIT 1"
            );
            $stmtEan->execute([$ean, $produtoId, $sku]);
            $conflito = $stmtEan->fetch();

            if ($conflito) {
                LogService::warning(
                    'EAN duplicado na importação Tray — SKU importado sem EAN',
                    [
                        'ean'                => $ean,
                        'produto_id_novo'    => $produtoId,
                        'sku_novo'           => $sku,
                        'sku_id_conflitante' => (int)$conflito['id'],
                        'produto_id_conflitante' => (int)$conflito['produto_id'],
                        'sku_conflitante'    => $conflito['sku'],
                    ],
                    'import'
                );
                $ean = null; // grava sem EAN; o produto entra, o conflito fica logado
            }
        }

        // ── SKU ausente: gera determinístico + loga ──
        // sku é NOT NULL e é a chave de deduplicação. Referência
        // vazia é erro de dado na Tray (deveria sempre existir).
        // Geramos a partir de um ID ESTÁVEL da linha (codigo_var),
        // não aleatório — senão cada reimportação criaria uma
        // variação nova (o dedup é por sku). Assim, mesma variação
        // → mesmo SKU → atualiza em vez de duplicar.
        if ($sku === '') {
            $codigoVar  = $this->utf8(trim($r[self::V['codigo_var']]  ?? ''));
            $codigoProd = $this->utf8(trim($r[self::V['codigo_prod']] ?? ''));

            if ($codigoVar !== '') {
                $sku = 'TRAY-V' . $codigoVar;
            } elseif ($codigoProd !== '') {
                // Sem código de variação, ancora no produto + atributos
                $sku = 'TRAY-P' . $codigoProd . '-'
                     . substr(md5($var1Valor . '|' . $var2Valor), 0, 8);
            } else {
                // Sem nenhum ID estável — último recurso: hash da linha
                // inteira. Determinístico p/ linha idêntica, mas frágil
                // se a linha mudar. É o pior caso; loga como tal.
                $sku = 'TRAY-X' . substr(md5(json_encode($r)), 0, 12);
            }

            LogService::warning(
                'Variação Tray sem referência — SKU gerado automaticamente',
                [
                    'produto_id'   => $produtoId,
                    'sku_gerado'   => $sku,
                    'codigo_var'   => $codigoVar ?: null,
                    'codigo_prod'  => $codigoProd ?: null,
                    'var1'         => $var1Valor ?? null,
                    'var2'         => $var2Valor ?? null,
                ],
                'import'
            );
        }

        $skuDados = [
            'produto_id'  => $produtoId,
            'sku'         => $sku,
            'ean'         => $ean,
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
            $this->colP['img1'], $this->colP['img2'],
            $this->colP['img3'], $this->colP['img4'],
        ];
        foreach ($colunas as $col) {
            $url = trim($r[$col] ?? '');
            if ($url) {
                $this->agendarImagem($produtoId, $url, $ordem, $ordem === 0);
                $ordem++;
            }
        }

        // Imagens adicionais
        $adicionais = trim($r[$this->colP['imgs_adicionais']] ?? '');
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

    // ════════════════════════════════════════════════════
    // RESOLUÇÃO DE COLUNAS PELO HEADER
    // ════════════════════════════════════════════════════

    /**
     * Monta $this->colP (campo => índice) lendo o header do CSV.
     *
     * Cada campo de P_COLS é procurado pelo NOME normalizado. O que não
     * for encontrado cai no índice de self::P e é registrado em
     * $this->colunasPorFallback — se essa lista vier grande, o arquivo
     * não é um export de produtos da Tray e o import vai gravar lixo.
     */
    private function resolverColunas(string $arquivo): void {
        $this->colP                = self::P;   // fallback completo
        $this->colunasPorFallback  = [];

        $header = $this->lerHeader($arquivo);
        if (!$header) {
            $this->colunasPorFallback = array_keys(self::P_COLS);
            LogService::warning(
                'CSV Tray sem header legível — usando índices posicionais',
                ['arquivo' => basename($arquivo)],
                'import'
            );
            return;
        }

        // nome normalizado => índice
        $indicePorNome = [];
        foreach ($header as $i => $nomeCol) {
            $chave = $this->normalizarNomeColuna($nomeCol);
            // Primeira ocorrência vence — nomes repetidos no export da Tray
            // (acontece) não podem sobrescrever a coluna certa.
            if ($chave !== '' && !isset($indicePorNome[$chave])) {
                $indicePorNome[$chave] = $i;
            }
        }

        foreach (self::P_COLS as $campo => $nomeEsperado) {
            $chave = $this->normalizarNomeColuna($nomeEsperado, false);
            if (isset($indicePorNome[$chave])) {
                $this->colP[$campo] = $indicePorNome[$chave];
            } else {
                $this->colunasPorFallback[] = $campo;
            }
        }

        if ($this->colunasPorFallback) {
            LogService::warning(
                'Colunas do CSV Tray não encontradas pelo nome — usando posição',
                [
                    'campos'  => $this->colunasPorFallback,
                    'arquivo' => basename($arquivo),
                    'header'  => array_map(fn($c) => $this->utf8((string)$c), $header),
                ],
                'import'
            );
        }
    }

    /** Primeira linha do CSV, crua (ainda em ISO-8859-1). */
    private function lerHeader(string $arquivo): array {
        $handle = fopen($arquivo, 'r');
        if (!$handle) return [];
        $header = fgetcsv($handle, 0, self::CSV_DELIMITER, '"');
        fclose($handle);
        return is_array($header) ? $header : [];
    }

    /**
     * Normaliza um nome de coluna para comparação tolerante:
     * remove BOM, converte encoding, tira acentos, baixa a caixa e
     * reduz qualquer pontuação a espaço único.
     *
     * "Endereço do Produto (URL Tray)" → "endereco do produto url tray"
     *
     * @param bool $doCsv true = veio do arquivo (converte ISO-8859-1);
     *                    false = veio de P_COLS (já é UTF-8 no código-fonte).
     */
    private function normalizarNomeColuna(string $nome, bool $doCsv = true): string {
        if ($doCsv) {
            $nome = $this->utf8($nome);
        }
        $nome = str_replace("\xEF\xBB\xBF", '', $nome);          // BOM
        $nome = transliterator_transliterate('Any-Latin; Latin-ASCII', $nome) ?: $nome;
        $nome = mb_strtolower($nome, 'UTF-8');
        $nome = preg_replace('/[^a-z0-9]+/', ' ', $nome) ?? $nome;
        return trim($nome);
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
        //
        // strrpos devolve FALSE quando não há '/' — o caso da planilha que
        // traz só o slug. `(int) false + 1` daria 1 e o substr comeria a
        // primeira letra ("bauletos-..." virava "auletos-..."), gerando uma
        // URL silenciosamente errada. Sem barra, o path inteiro É o slug.
        $path = rtrim($path, '/');
        $pos  = strrpos($path, '/');
        $slug = $pos === false ? $path : substr($path, $pos + 1);

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
     * @param string      $nome       Nome do produto (último recurso).
     * @param string|null $urlTray    Coluna "Endereço do Produto (URL Tray)".
     * @param int|null    $produtoId  ID se for UPDATE (ignora colisão consigo mesmo).
     * @param string|null $slugAtual  Slug já gravado, se for UPDATE.
     */
    private function resolverSlug(
        string  $nome,
        ?string $urlTray,
        ?int    $produtoId = null,
        ?string $slugAtual = null
    ): string {
        // A coluna vem em ISO-8859-1 como todo o CSV. A URL é ASCII, mas
        // converter mantém a regra uniforme e evita byte solto virar '-'.
        if ($urlTray !== null && $urlTray !== '') {
            $urlTray = $this->utf8($urlTray);
        }

        $slug = $this->slugDaUrlTray($urlTray);

        // 3. Sem slug utilizável no CSV
        if ($slug === null) {
            // Produto que já existe MANTÉM a URL atual. Regenerar pelo nome
            // aqui era o bug que trocava a URL a cada reimportação.
            if ($slugAtual !== null && $slugAtual !== '') {
                LogService::warning('Produto sem URL no CSV — slug atual preservado', [
                    'produto_id' => $produtoId,
                    'slug'       => $slugAtual,
                ], 'import');
                return $slugAtual;
            }

            LogService::warning('Produto sem URL da Tray — slug gerado pelo nome', [
                'nome' => mb_substr($nome, 0, 80),
            ], 'import');
            return SlugHelper::unique($nome, 'produtos');
        }

        // Slug do CSV é idêntico ao que já está gravado: nada a fazer.
        if ($slugAtual !== null && $slug === $slugAtual) {
            return $slugAtual;
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