<?php
declare(strict_types=1);

/**
 * app/services/TrayPedidoImportService.php
 *
 * Importa pedidos da Tray a partir de dois arquivos CSV:
 *  - pedidos.csv          (1 linha por pedido)
 *  - produtos_vendidos.csv (1 linha por item, várias por pedido)
 */
class TrayPedidoImportService
{
    private PDO $db;

    const CHUNK_SIZE    = 30;
    const CSV_DELIMITER = ';';
    const CSV_ENCODING  = 'ISO-8859-1';

    // Índices de colunas — pedidos.csv
    const P = [
        'tray_id'       =>  0, // Pedido
        'data'          =>  1, // Data
        'hora'          =>  2, // Hora
        'cliente_nome'  =>  3, // Nome do Cliente
        'cpf'           =>  5, // CPF
        'email'         =>  7, // Email
        'telefone'      =>  8, // Telefone
        'celular'       =>  9, // Celular
        'subtotal'      => 10, // Subtotal produtos
        'frete_tipo'    => 13, // Frete tipo
        'frete_valor'   => 14, // Frete valor
        'pagamento_tipo'=> 15, // Pagamento tipo
        'pagamento_data'=> 16, // Pagamento data
        'envio_data'    => 17, // Envio data
        'envio_codigo'  => 18, // Envio codigo
        'obs_cliente'   => 19, // Obs. cliente
        'obs_loja'      => 20, // Obs. loja
        'status'        => 21, // Status pedido
        'cupom'         => 24, // Cupom desconto
        'desconto'      => 25, // Desconto
        'endereco'      => 27, // Endereço
        'numero'        => 28, // Número
        'complemento'   => 29, // Complemento
        'bairro'        => 30, // Bairro
        'cidade'        => 31, // Cidade
        'estado'        => 32, // Estado
        'cep'           => 33, // Cep
        'total'         => 34, // Total
        'cod_cliente'   => 39, // Código cliente (tray_id do cliente)
        'destinatario'  => 40, // Destinatário
    ];

    // Índices de colunas — produtos_vendidos.csv
    const I = [
        'cod_produto'  => 0, // Código produto (tray_id)
        'ean'          => 1, // EAN
        'cod_pedido'   => 3, // Código pedido
        'nome'         => 4, // Nome produto (com HTML e variação)
        'preco'        => 5, // Preço venda
        'quantidade'   => 6, // Quantidade
        'referencia'   => 7, // Referência = SKU
        'preco_custo'  => 8, // Preço custo
    ];

    // Mapeamento de status Tray → nosso sistema
    const STATUS_MAP = [
        'A ENVIAR'                      => 'pagamento_aprovado',
        'A ENVIAR VINDI'                => 'pagamento_aprovado',
        'AGUARDANDO ENVIO'              => 'em_separacao',
        'AGUARDANDO PAGAMENTO'          => 'aguardando_pagamento',
        'AGUARDANDO RETIRADA'           => 'aguardando_pagamento',
        'CANCELADO'                     => 'cancelado',
        'COMPRA EM ANALISE'             => 'em_separacao',
        'FINALIZADO'                    => 'entregue',
        'PENDENTE PAGAMENTO'            => 'aguardando_pagamento',
        'PENDENTE'                      => 'aguardando_pagamento',
        'EM MONITORAMENTO'              => 'em_separacao',
        'ENVIADO'                       => 'enviado',
        'AGUARDANDO VINDI'              => 'aguardando_pagamento',
        'EM SEPARAÇÃO'                  => 'em_separacao',
        'EM SEPARACAO'                  => 'em_separacao',
        'DEVOLUÇÃO'                     => 'troca_devolucao',
        'DEVOLUCAO'                     => 'troca_devolucao',
        'PEDIDO FATURADO'               => 'em_separacao',
        'A FATURAR'                     => 'em_separacao',
        'DEVOLUÇÃO PARCIAL'             => 'troca_devolucao',
        'DEVOLUCAO PARCIAL'             => 'troca_devolucao',
        'TROCA PARCIAL'                 => 'troca_devolucao',
        'AGUARDANDO DISPONIBILIDADE'    => 'aguardando_pagamento',
        'CHARGEBACK'                    => 'entregue',
        'DEVOLVIDO'                     => 'troca_devolucao',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ════════════════════════════════════════════════════
    // CRIAÇÃO DO JOB
    // ════════════════════════════════════════════════════

    public function criarJob(array $pedidosFile, array $produtosFile, int $adminId): array
    {
        foreach ([$pedidosFile, $produtosFile] as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return ['ok' => false, 'msg' => 'Erro no upload: ' . ($f['name'] ?? '')];
            }
        }

        $tmpDir = sys_get_temp_dir() . '/tray_imports';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $tmp1 = $tmpDir . '/' . bin2hex(random_bytes(8)) . '_pedidos.csv';
        $tmp2 = $tmpDir . '/' . bin2hex(random_bytes(8)) . '_produtos.csv';

        if (!move_uploaded_file($pedidosFile['tmp_name'], $tmp1) ||
            !move_uploaded_file($produtosFile['tmp_name'], $tmp2)) {
            return ['ok' => false, 'msg' => 'Erro ao salvar arquivos temporários.'];
        }

        $totalLinhas = $this->contarLinhas($tmp1) - 1;
        if ($totalLinhas <= 0) {
            return ['ok' => false, 'msg' => 'Arquivo de pedidos vazio ou inválido.'];
        }

        $this->db->prepare(
            "INSERT INTO import_jobs
             (tipo, status, arquivo_tmp, arquivo_tmp_2, total_linhas, admin_id)
             VALUES ('pedidos', 'aguardando', ?, ?, ?, ?)"
        )->execute([$tmp1, $tmp2, $totalLinhas, $adminId]);

        return [
            'ok'           => true,
            'job_id'       => (int)$this->db->lastInsertId(),
            'total_linhas' => $totalLinhas,
        ];
    }

    // ════════════════════════════════════════════════════
    // PREVIEW
    // ════════════════════════════════════════════════════

    public function preview(int $jobId): array
    {
        $job = $this->getJob($jobId);
        if (!$job) return ['ok' => false, 'msg' => 'Job não encontrado.'];

        $itensIdx = $this->buildItensIndex($job['arquivo_tmp_2']);
        $rows     = $this->lerLinhas($job['arquivo_tmp'], 1, 5);
        $preview  = [];

        foreach ($rows as $r) {
            $trayId   = trim($r[self::P['tray_id']]);
            $preview[] = [
                'tray_id'   => $trayId,
                'data'      => $this->data($r[self::P['data']] ?? ''),
                'cliente'   => $this->utf8($r[self::P['cliente_nome']] ?? ''),
                'status'    => $this->mapearStatus($r[self::P['status']] ?? ''),
                'total'     => $this->preco($r[self::P['total']] ?? '0'),
                'n_itens'   => count($itensIdx[$trayId] ?? []),
            ];
        }

        return ['ok' => true, 'preview' => $preview, 'total' => $job['total_linhas']];
    }

    // ════════════════════════════════════════════════════
    // PROCESSAMENTO EM CHUNKS
    // ════════════════════════════════════════════════════

    public function processarChunk(int $jobId): array
    {
        $job = $this->getJob($jobId);
        if (!$job) return ['ok' => false, 'msg' => 'Job não encontrado.'];
        if ($job['status'] === 'concluido') return ['ok' => true, 'concluido' => true];

        $this->db->prepare(
            "UPDATE import_jobs SET status = 'processando' WHERE id = ?"
        )->execute([$jobId]);

        // Carrega índice de itens inteiro na memória (arquivo pequeno)
        $itensIdx = $this->buildItensIndex($job['arquivo_tmp_2']);

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
                $result = $this->processarPedido($row, $itensIdx);
                match ($result) {
                    'criado'    => $criados++,
                    'ignorado'  => $ignorados++,
                    default     => null,
                };
            } catch (\Throwable $e) {
                $erros[] = [
                    'linha'   => $linhaNum,
                    'pedido'  => trim($row[self::P['tray_id']] ?? ''),
                    'msg'     => $e->getMessage(),
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
               ignorados    = ignorados    + ?,
               erros_json   = ?,
               status       = IF(? >= total_linhas, 'concluido', 'processando'),
               concluido_em = IF(? >= total_linhas, NOW(), NULL)
             WHERE id = ?"
        )->execute([
            count($rows), $criados, $ignorados,
            json_encode($erros, JSON_UNESCAPED_UNICODE),
            $novasProcessadas, $novasProcessadas,
            $jobId,
        ]);

        if ($concluido) {
            @unlink($job['arquivo_tmp']);
            @unlink($job['arquivo_tmp_2']);
        }

        return [
            'ok'          => true,
            'concluido'   => $concluido,
            'processadas' => $novasProcessadas,
            'total'       => (int)$job['total_linhas'],
            'criados'     => $criados,
            'ignorados'   => $ignorados,
        ];
    }

    // ════════════════════════════════════════════════════
    // LÓGICA DE IMPORTAÇÃO DO PEDIDO
    // ════════════════════════════════════════════════════

    private function processarPedido(array $r, array $itensIdx): string
    {
        $trayId = trim($r[self::P['tray_id']]);
        if (empty($trayId)) return 'ignorado';

        // Idempotência — pula se já importado
        $existe = $this->db->prepare("SELECT id FROM pedidos WHERE tray_id = ? LIMIT 1");
        $existe->execute([$trayId]);
        if ($existe->fetchColumn()) return 'ignorado';

        // Dados do pedido
        $statusPedido   = $this->mapearStatus($r[self::P['status']]);
        $statusPagamento= $this->inferirStatusPagamento($statusPedido, $r[self::P['pagamento_data']] ?? '');
        $pagamento      = $this->parsePagamento($r[self::P['pagamento_tipo']] ?? '', $r[self::P['obs_loja']] ?? '');
        $clienteId      = $this->findCliente(
            trim($r[self::P['cod_cliente']] ?? ''),
            preg_replace('/\D/', '', $r[self::P['cpf']] ?? ''),
            strtolower(trim($this->utf8($r[self::P['email']] ?? '')))
        );

        $criadoEm   = $this->datahora($r[self::P['data']] ?? '', $r[self::P['hora']] ?? '');
        $pagoEm     = $r[self::P['pagamento_data']] ? $this->data($r[self::P['pagamento_data']]) : null;
        $enviadoEm  = $r[self::P['envio_data']] ? $this->data($r[self::P['envio_data']]) : null;

        $enderecoJson = json_encode([
            'destinatario' => $this->utf8($r[self::P['destinatario']] ?? ''),
            'logradouro'   => $this->utf8($r[self::P['endereco']]    ?? ''),
            'numero'       => $this->utf8($r[self::P['numero']]      ?? ''),
            'complemento'  => $this->utf8($r[self::P['complemento']] ?? ''),
            'bairro'       => $this->utf8($r[self::P['bairro']]      ?? ''),
            'cidade'       => $this->utf8($r[self::P['cidade']]      ?? ''),
            'estado'       => strtoupper(trim($r[self::P['estado']]  ?? '')),
            'cep'          => preg_replace('/\D/', '', $r[self::P['cep']] ?? ''),
        ], JSON_UNESCAPED_UNICODE);

        $this->db->beginTransaction();
        try {
            // INSERT pedido
            $this->db->prepare(
                "INSERT INTO pedidos (
                    tray_id, codigo, cliente_id,
                    status_pedido, status_pagamento,
                    subtotal, desconto, frete, frete_servico, total,
                    forma_pagamento, parcelas, cartao_bandeira,
                    codigo_rastreio, enviado_em, pago_em,
                    cupom_id,
                    observacao_cliente, observacao_interna,
                    endereco_entrega,
                    canal,
                    criado_em, atualizado_em
                ) VALUES (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
                )"
            )->execute([
                $trayId,
                $trayId,                          // codigo = tray_id
                $clienteId,
                $statusPedido,
                $statusPagamento,
                $this->preco($r[self::P['subtotal']]   ?? '0'),
                $this->preco($r[self::P['desconto']]   ?? '0'),
                $this->preco($r[self::P['frete_valor']]?? '0'),
                $this->utf8($r[self::P['frete_tipo']]  ?? ''),
                $this->preco($r[self::P['total']]      ?? '0'),
                $pagamento['metodo'],
                $pagamento['parcelas'],
                $pagamento['bandeira'],
                trim($r[self::P['envio_codigo']] ?? '') ?: null,
                $enviadoEm,
                $pagoEm,
                null,
                $this->utf8($r[self::P['obs_cliente']] ?? '') ?: null,
                $this->utf8($r[self::P['obs_loja']]    ?? '') ?: null,
                $enderecoJson,
                'tray',   // canal — venda importada da Tray, não do site
                $criadoEm,
                $criadoEm,
            ]);

            $pedidoId = (int)$this->db->lastInsertId();

            // INSERT itens
            foreach ($itensIdx[$trayId] ?? [] as $item) {
                $this->inserirItem($pedidoId, $item);
            }

            // INSERT histórico (entrada única — status final)
            $this->db->prepare(
                "INSERT INTO pedido_historico (pedido_id, status_novo, observacao, criado_em)
                 VALUES (?, ?, 'Importado da Tray', ?)"
            )->execute([$pedidoId, $statusPedido, $criadoEm]);

            // Evento no stream: compra feita no marketplace também tira o
            // cliente das jornadas de recuperação. registrarPara() porque isto
            // roda em CLI, onde o registrar() devolve null por guarda.
            if ($clienteId > 0 && class_exists('TrackingService')
                && method_exists('TrackingService', 'registrarPara')) {
                try {
                    TrackingService::registrarPara(
                        (int)$clienteId, 'pedido_criado', 'pedido', $pedidoId,
                        ['origem' => 'tray'], 'tray'
                    );
                } catch (\Throwable $e) {
                    error_log('[TrayPedidoImport] evento pedido_criado: ' . $e->getMessage());
                }
            }

            $this->db->commit();
            return 'criado';

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    // ════════════════════════════════════════════════════
    // INSERÇÃO DE ITEM
    // ════════════════════════════════════════════════════

    private function inserirItem(int $pedidoId, array $item): void
    {
        $referencia  = trim($item[self::I['referencia']] ?? '');
        $codProdTray = trim($item[self::I['cod_produto']] ?? '');
        $nomeHtml    = $item[self::I['nome']] ?? '';
        $preco       = $this->preco($item[self::I['preco']] ?? '0');
        $quantidade  = max(1, (int)$item[self::I['quantidade']]);

        // Extrai variação do HTML antes de limpar
        $opcoes = $this->extrairOpcoes($nomeHtml);
        $nome   = $this->limparNomeProduto($nomeHtml);

        // Busca SKU pela referência
        $produtoId = null;
        $skuId     = null;

        if ($referencia) {
            $stmt = $this->db->prepare(
                "SELECT ps.id AS sku, ps.produto_id
                 FROM produto_skus ps
                 WHERE ps.sku = ?
                 LIMIT 1"
            );
            $stmt->execute([$referencia]);
            $found = $stmt->fetch();
            if ($found) {
                $skuId     = (int)$found['sku'];
                $produtoId = (int)$found['produto_id'];
            }
        }

        // Fallback: busca produto pelo tray_id
        if (!$produtoId && $codProdTray) {
            $stmt = $this->db->prepare(
                "SELECT id FROM produtos WHERE tray_id = ? LIMIT 1"
            );
            $stmt->execute([$codProdTray]);
            $pid = $stmt->fetchColumn();
            if ($pid) $produtoId = (int)$pid;
        }

        $this->db->prepare(
            "INSERT INTO pedido_itens
             (pedido_id, produto_id, sku, nome_produto,
              quantidade, preco_unitario, opcoes_selecionadas)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([
            $pedidoId,
            $produtoId,
            $skuId,
            $nome,
            $quantidade,
            $preco,
            $opcoes ? json_encode($opcoes, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    private function findCliente(string $trayCodigo, string $cpf, string $email): ?int
    {
        // 1. Por tray_id do cliente
        if ($trayCodigo) {
            $stmt = $this->db->prepare("SELECT id FROM clientes WHERE tray_id = ? LIMIT 1");
            $stmt->execute([$trayCodigo]);
            if ($id = $stmt->fetchColumn()) return (int)$id;
        }
        // 2. Por CPF
        if (strlen($cpf) === 11) {
            $stmt = $this->db->prepare("SELECT id FROM clientes WHERE cpf = ? LIMIT 1");
            $stmt->execute([$cpf]);
            if ($id = $stmt->fetchColumn()) return (int)$id;
        }
        // 3. Por e-mail
        if ($email) {
            $stmt = $this->db->prepare(
                "SELECT c.id FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE u.email = ? LIMIT 1"
            );
            $stmt->execute([$email]);
            if ($id = $stmt->fetchColumn()) return (int)$id;
        }
        return null; // importa com cliente_id NULL
    }

    private function mapearStatus(string $trayStatus): string
    {
        $key = strtoupper(trim($trayStatus));
        // Remove acentos para matching mais robusto
        $key = strtr($key, ['Ã' => 'A', 'Ç' => 'C', 'Ã' => 'A', 'Ã' => 'A']);
        $key = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        return self::STATUS_MAP[$key] ?? 'aguardando_pagamento';
    }

    private function inferirStatusPagamento(string $statusPedido, string $dataPagemento): string
    {
        if (in_array($statusPedido, ['cancelado'])) return 'recusado';
        if (in_array($statusPedido, ['troca_devolucao'])) return 'reembolsado';
        if ($dataPagemento && $dataPagemento !== '00/00/0000') return 'aprovado';
        return 'aguardando';
    }

    private function parsePagamento(string $tipo, string $obs): array
    {
        $metodo   = 'outro';
        $bandeira = null;
        $parcelas = 1;

        $tipoUpper = strtoupper($tipo);
        if (str_contains($tipoUpper, 'PIX'))    $metodo = 'pix';
        elseif (str_contains($tipoUpper, 'CART') || str_contains($tipoUpper, 'CARD'))
            $metodo = 'cartao';
        elseif (str_contains($tipoUpper, 'BOLETO')) $metodo = 'boleto';

        if ($metodo === 'cartao') {
            if      (str_contains($tipoUpper, 'VISA'))   $bandeira = 'visa';
            elseif  (str_contains($tipoUpper, 'MASTER')) $bandeira = 'mastercard';
            elseif  (str_contains($tipoUpper, 'ELO'))    $bandeira = 'elo';
            elseif  (str_contains($tipoUpper, 'AMEX'))   $bandeira = 'amex';
            elseif  (str_contains($tipoUpper, 'HIPERCARD')) $bandeira = 'hipercard';
        }

        // Extrai parcelas das observações: "12 vezes de R$ 131,99"
        if (preg_match('/(\d+)\s*vezes?\s*de\s*R\$\s*[\d,.]+/i', $obs, $m)) {
            $parcelas = (int)$m[1];
        }

        return compact('metodo', 'bandeira', 'parcelas');
    }

    private function extrairOpcoes(string $html): array
    {
        $opcoes = [];
        // <strong>Tamanho</strong> 56/S ou <strong>Cor</strong> Azul
        if (preg_match_all('/<strong[^>]*>([^<]+)<\/strong>\s*([^<\n]+)/iu', $html, $m)) {
            for ($i = 0; $i < count($m[1]); $i++) {
                $chave = trim($this->utf8($m[1][$i]));
                $valor = trim($this->utf8($m[2][$i]));
                if ($chave && $valor) $opcoes[$chave] = $valor;
            }
        }
        return $opcoes;
    }

    private function limparNomeProduto(string $html): string
    {
        $html = $this->utf8($html);
        // Remove HTML
        $texto = strip_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8'));
        // Remove "( Ref. XXXX)" e variações
        $texto = preg_replace('/\s*\(Ref\.?\s*[A-Z0-9]+\)/i', '', $texto);
        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    // ── Index itens por código de pedido ─────────────────

    private function buildItensIndex(string $arquivo): array
    {
        $index  = [];
        $handle = fopen($arquivo, 'r');
        if (!$handle) return [];

        fgetcsv($handle, 0, self::CSV_DELIMITER); // pula header
        while (!feof($handle)) {
            $row = fgetcsv($handle, 0, self::CSV_DELIMITER, '"');
            if (!$row || !isset($row[self::I['cod_pedido']])) continue;
            $codPedido = trim($row[self::I['cod_pedido']]);
            if ($codPedido) $index[$codPedido][] = $row;
        }
        fclose($handle);
        return $index;
    }

    // ── Conversões ────────────────────────────────────────

    private function utf8(string $s): string
    {
        return mb_convert_encoding($s, 'UTF-8', self::CSV_ENCODING);
    }

    private function preco(string $s): float
    {
        $s = trim($s);
        // Formato BR: "1.799,80" → 1799.80
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
        return (float)$s;
    }

    private function data(string $s): ?string
    {
        $s = trim($s);
        if (!$s || $s === '00/00/0000') return null;
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $s, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }

    private function datahora(string $data, string $hora): string
    {
        $d = $this->data($data) ?? date('Y-m-d');
        $h = trim($hora) ?: '00:00:00';
        return $d . ' ' . $h;
    }

    // ── Job helpers ───────────────────────────────────────

    public function getJob(int $jobId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM import_jobs WHERE id = ? AND tipo = 'pedidos' LIMIT 1"
        );
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }

    private function finalizarJob(int $jobId): void
    {
        $this->db->prepare(
            "UPDATE import_jobs SET status = 'concluido', concluido_em = NOW() WHERE id = ?"
        )->execute([$jobId]);
    }

    private function lerLinhas(string $arquivo, int $startLine, int $limit): array
    {
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

    private function contarLinhas(string $arquivo): int
    {
        $count  = 0;
        $handle = fopen($arquivo, 'r');
        while (!feof($handle)) {
            if (fgetcsv($handle, 0, self::CSV_DELIMITER, '"') !== false) $count++;
        }
        fclose($handle);
        return $count;
    }
}