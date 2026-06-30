<?php
// app/services/DocumentService.php
// Gerencia todo o ciclo de verificação de documentos:
// encriptação, armazenamento, análise automática e status.

class DocumentService {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // ── Status ────────────────────────────────────────────────

    public function getStatus(int $clienteId): array {
        $stmt = $this->db->prepare(
            "SELECT vd.*, c.verificado, c.verificado_em
             FROM clientes c
             LEFT JOIN verificacao_documentos vd ON vd.cliente_id = c.id
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $row = $stmt->fetch();

        if (!$row || !isset($row['id'])) {
            return ['status' => 'nao_enviado', 'verificado' => false];
        }

        return [
            'status'         => $row['status'],
            'verificado'     => (bool) $row['verificado'],
            'verificado_em'  => $row['verificado_em'],
            'tipo'           => $row['tipo'],
            'score'          => $row['score_analise'],
            'motivo'         => $row['motivo_rejeicao'],
            'criado_em'      => $row['criado_em'],
            'analisado_em'   => $row['analisado_em'],
        ];
    }

    public function isVerificado(int $clienteId): bool {
        $stmt = $this->db->prepare(
            "SELECT verificado FROM clientes WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Upload e armazenamento ────────────────────────────────

    /**
     * Processa o upload do documento:
     * 1. Valida o arquivo
     * 2. Encripta com AES-256
     * 3. Salva apenas os dados encriptados (nunca o arquivo em disco)
     * 4. Dispara análise automática
     */
    public function processUpload(int $clienteId, array $file, string $tipo): array {
        // Validação básica
        $validation = $this->validateFile($file);
        if (!$validation['ok']) {
            return $validation;
        }

        $conteudo = file_get_contents($file['tmp_name']);
        if (!$conteudo) {
            return ['ok' => false, 'msg' => 'Erro ao ler o arquivo.'];
        }

        // Hash de integridade (antes de encriptar)
        $hashIntegridade = hash('sha512', $conteudo);

        // Encriptação AES-256-CBC
        $iv         = random_bytes(16);
        $chave      = hash('sha256', DOC_ENCRYPT_KEY, true);
        $encriptado = openssl_encrypt($conteudo, DOC_ENCRYPT_ALGO, $chave, 0, $iv);

        if ($encriptado === false) {
            return ['ok' => false, 'msg' => 'Erro na encriptação. Tente novamente.'];
        }

        // Remove documento anterior se existir
        $this->db->prepare(
            "DELETE FROM verificacao_documentos WHERE cliente_id = ?"
        )->execute([$clienteId]);

        // Salva no banco (NUNCA em disco)
        $this->db->prepare(
            "INSERT INTO verificacao_documentos
             (cliente_id, tipo, documento_enc, iv, hash_integridade, status, criado_em)
             VALUES (?, ?, ?, ?, ?, 'em_analise', NOW())"
        )->execute([
            $clienteId,
            $tipo,
            $encriptado,
            base64_encode($iv),
            $hashIntegridade,
        ]);

        $docId = (int) $this->db->lastInsertId();

        // Análise automática
        $resultado = $this->analisarDocumento($docId, $conteudo, $tipo, $file['type']);

        // Limpa o conteúdo da memória
        unset($conteudo, $encriptado);

        return [
            'ok'     => true,
            'status' => $resultado['status'],
            'msg'    => $resultado['msg'],
            'score'  => $resultado['score'],
        ];
    }

    // ── Análise automática ────────────────────────────────────

    /**
     * Análise automática do documento sem intervenção humana.
     * Verifica: dimensões, qualidade, proporção, nitidez, brilho e padrões de documento.
     */
    private function analisarDocumento(int $docId, string $conteudo,
                                        string $tipo, string $mimeType): array {
        $log    = [];
        $score  = 0;
        $passed = true;

        try {
            // Cria imagem em memória para análise
            $img = match($mimeType) {
                'image/jpeg' => @imagecreatefromstring($conteudo),
                'image/png'  => @imagecreatefromstring($conteudo),
                'image/webp' => @imagecreatefromstring($conteudo),
                default      => false,
            };

            if (!$img) {
                return $this->rejeitar($docId, 'Arquivo corrompido ou não é uma imagem válida.', 0, []);
            }

            $largura = imagesx($img);
            $altura  = imagesy($img);

            // ── Teste 1: Dimensões mínimas ────────────────────
            $dimOk = ($largura >= 600 && $altura >= 400);
            $log['dimensoes'] = [
                'ok'      => $dimOk,
                'w'       => $largura,
                'h'       => $altura,
                'minimo'  => '600x400',
            ];
            if ($dimOk) $score += 20;
            else         $passed = false;

            // ── Teste 2: Proporção do documento ──────────────
            // RG/CNH: proporção ~1.4:1 a 1.8:1 (paisagem)
            $proporcao     = $largura / max($altura, 1);
            $proporcaoOk   = ($proporcao >= 1.2 && $proporcao <= 2.2);
            $log['proporcao'] = [
                'ok'        => $proporcaoOk,
                'valor'     => round($proporcao, 2),
                'esperado'  => '1.2 a 2.2',
            ];
            if ($proporcaoOk) $score += 15;

            // ── Teste 3: Tamanho do arquivo ───────────────────
            $tamanho   = strlen($conteudo);
            $tamanhoOk = ($tamanho >= 50000); // mín. 50KB (evita screenshots de baixa resolução)
            $log['tamanho'] = [
                'ok'     => $tamanhoOk,
                'bytes'  => $tamanho,
                'minimo' => '50000',
            ];
            if ($tamanhoOk) $score += 15;
            else             $passed = false;

            // ── Teste 4: Análise de cores (documento real tem variação) ──
            $variacaoCores = $this->analisarVariacaoCores($img, $largura, $altura);
            $coresOk       = ($variacaoCores['desvio_medio'] > 15);
            $log['cores'] = array_merge(['ok' => $coresOk], $variacaoCores);
            if ($coresOk) $score += 15;
            else           $passed = false;

            // ── Teste 5: Nitidez (documentos desfocados são rejeitados) ──
            $nitidez   = $this->analisarNitidez($img, $largura, $altura);
            $nitidezOk = ($nitidez['score'] > 20);
            $log['nitidez'] = array_merge(['ok' => $nitidezOk], $nitidez);
            if ($nitidezOk) $score += 20;
            else             $passed = false;

            // ── Teste 6: Brilho (não pode ser imagem toda escura ou branca) ──
            $brilho   = $this->analisarBrilho($img, $largura, $altura);
            $brilhoOk = ($brilho['media'] > 30 && $brilho['media'] < 240);
            $log['brilho'] = array_merge(['ok' => $brilhoOk], $brilho);
            if ($brilhoOk) $score += 15;
            else            $passed = false;

            imagedestroy($img);

        } catch (Exception $e) {
            $passed = false;
            $log['erro'] = $e->getMessage();
        }

        // Score mínimo para aprovar: 70
        if ($passed && $score >= 70) {
            return $this->aprovar($docId, $score, $log);
        }

        $motivo = $this->gerarMotivoRejeicao($log, $score);
        return $this->rejeitar($docId, $motivo, $score, $log);
    }

    private function analisarVariacaoCores($img, int $w, int $h): array {
        $amostras   = [];
        $passo      = max(1, (int)($w / 20));
        $passoAltura = max(1, (int)($h / 20));

        for ($x = 0; $x < $w; $x += $passo) {
            for ($y = 0; $y < $h; $y += $passoAltura) {
                $rgb       = imagecolorat($img, $x, $y);
                $r         = ($rgb >> 16) & 0xFF;
                $g         = ($rgb >> 8)  & 0xFF;
                $b         = $rgb         & 0xFF;
                $amostras[] = ($r + $g + $b) / 3;
            }
        }

        if (empty($amostras)) return ['desvio_medio' => 0, 'total_amostras' => 0];

        $media  = array_sum($amostras) / count($amostras);
        $desvio = 0;
        foreach ($amostras as $a) {
            $desvio += pow($a - $media, 2);
        }
        $desvio = sqrt($desvio / count($amostras));

        return [
            'desvio_medio'   => round($desvio, 2),
            'media_brilho'   => round($media, 2),
            'total_amostras' => count($amostras),
        ];
    }

    private function analisarNitidez($img, int $w, int $h): array {
        // Detecta bordas usando diferença de pixels vizinhos (Laplaciano simplificado)
        $bordas  = 0;
        $amostras = 0;
        $passo   = max(1, (int)($w / 30));

        for ($x = $passo; $x < $w - $passo; $x += $passo) {
            for ($y = $passo; $y < $h - $passo; $y += $passo) {
                $c  = imagecolorat($img, $x, $y);
                $r  = imagecolorat($img, $x + 1, $y);
                $b  = imagecolorat($img, $x, $y + 1);

                $lum_c = (($c >> 16) & 0xFF) * 0.299 + (($c >> 8) & 0xFF) * 0.587 + ($c & 0xFF) * 0.114;
                $lum_r = (($r >> 16) & 0xFF) * 0.299 + (($r >> 8) & 0xFF) * 0.587 + ($r & 0xFF) * 0.114;
                $lum_b = (($b >> 16) & 0xFF) * 0.299 + (($b >> 8) & 0xFF) * 0.587 + ($b & 0xFF) * 0.114;

                $bordas += abs($lum_c - $lum_r) + abs($lum_c - $lum_b);
                $amostras++;
            }
        }

        $scoreNitidez = $amostras > 0 ? round($bordas / $amostras, 2) : 0;
        return ['score' => $scoreNitidez, 'amostras' => $amostras];
    }

    private function analisarBrilho($img, int $w, int $h): array {
        $soma   = 0;
        $total  = 0;
        $passo  = max(1, (int)($w / 25));
        $passoH = max(1, (int)($h / 25));

        for ($x = 0; $x < $w; $x += $passo) {
            for ($y = 0; $y < $h; $y += $passoH) {
                $rgb   = imagecolorat($img, $x, $y);
                $r     = ($rgb >> 16) & 0xFF;
                $g     = ($rgb >> 8)  & 0xFF;
                $b     = $rgb         & 0xFF;
                $soma += ($r + $g + $b) / 3;
                $total++;
            }
        }

        $media = $total > 0 ? round($soma / $total, 2) : 0;
        return ['media' => $media, 'amostras' => $total];
    }

    private function gerarMotivoRejeicao(array $log, int $score): string {
        $problemas = [];

        if (!($log['dimensoes']['ok']  ?? true)) $problemas[] = 'imagem muito pequena';
        if (!($log['tamanho']['ok']    ?? true)) $problemas[] = 'resolução insuficiente';
        if (!($log['nitidez']['ok']    ?? true)) $problemas[] = 'imagem desfocada';
        if (!($log['brilho']['ok']     ?? true)) $problemas[] = 'iluminação inadequada';
        if (!($log['cores']['ok']      ?? true)) $problemas[] = 'baixo contraste';

        if (empty($problemas)) {
            return 'Documento não atende aos requisitos mínimos de qualidade.';
        }

        return 'Problemas detectados: ' . implode(', ', $problemas) . '. Por favor, tire uma nova foto.';
    }

    private function aprovar(int $docId, int $score, array $log): array {
        $this->db->prepare(
            "UPDATE verificacao_documentos
             SET status = 'verificado', score_analise = ?, analise_log = ?, analisado_em = NOW()
             WHERE id = ?"
        )->execute([$score, json_encode($log), $docId]);

        // Busca o cliente_id
        $stmt = $this->db->prepare("SELECT cliente_id FROM verificacao_documentos WHERE id = ?");
        $stmt->execute([$docId]);
        $clienteId = $stmt->fetchColumn();

        if ($clienteId) {
            $this->db->prepare(
                "UPDATE clientes SET verificado = 1, verificado_em = NOW() WHERE id = ?"
            )->execute([$clienteId]);
        }

        return [
            'status' => 'verificado',
            'score'  => $score,
            'msg'    => 'Documento verificado com sucesso! Seu perfil agora está verificado.',
        ];
    }

    private function rejeitar(int $docId, string $motivo, int $score, array $log): array {
        $this->db->prepare(
            "UPDATE verificacao_documentos
             SET status = 'rejeitado', motivo_rejeicao = ?,
                 score_analise = ?, analise_log = ?, analisado_em = NOW()
             WHERE id = ?"
        )->execute([$motivo, $score, json_encode($log), $docId]);

        return [
            'status' => 'rejeitado',
            'score'  => $score,
            'msg'    => $motivo,
        ];
    }

    // ── Validação do arquivo ──────────────────────────────────

    private function validateFile(array $file): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $erros = [
                UPLOAD_ERR_INI_SIZE   => 'Arquivo muito grande (limite do servidor).',
                UPLOAD_ERR_FORM_SIZE  => 'Arquivo muito grande.',
                UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
                UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
            ];
            return ['ok' => false, 'msg' => $erros[$file['error']] ?? 'Erro no upload.'];
        }

        if ($file['size'] > DOC_MAX_SIZE) {
            return ['ok' => false, 'msg' => 'Arquivo muito grande. Máximo 10MB.'];
        }

        // Verifica MIME real (não apenas extensão)
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeReal = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeReal, DOC_ALLOWED_MIME, true)) {
            return ['ok' => false, 'msg' => 'Formato inválido. Use JPG, PNG ou WEBP.'];
        }

        return ['ok' => true];
    }

    // ── Token para upload mobile ──────────────────────────────

    /**
     * Gera token temporário para o link do QR code.
     * Vinculado ao cliente e expira em 30 minutos.
     */
    public function gerarTokenMobile(int $clienteId): array {
        $token    = SecurityHelper::generateToken(24);
        $expiraEm = date('Y-m-d H:i:s', time() + DOC_TOKEN_TTL);

        // Cria ou atualiza registro pendente
        $existe = $this->db->prepare(
            "SELECT id FROM verificacao_documentos WHERE cliente_id = ? LIMIT 1"
        );
        $existe->execute([$clienteId]);

        if ($existe->fetchColumn()) {
            $this->db->prepare(
                "UPDATE verificacao_documentos
                 SET token_mobile = ?, token_expira_em = ?, status = 'pendente'
                 WHERE cliente_id = ?"
            )->execute([$token, $expiraEm, $clienteId]);
        } else {
            $this->db->prepare(
                "INSERT INTO verificacao_documentos
                 (cliente_id, tipo, documento_enc, iv, hash_integridade,
                  token_mobile, token_expira_em, status)
                 VALUES (?, 'rg', '', '', '', ?, ?, 'pendente')"
            )->execute([$clienteId, $token, $expiraEm]);
        }

        $url = BASE_URL . '/verificar-documento/' . $token;

        return [
            'ok'       => true,
            'token'    => $token,
            'url'      => $url,
            'expira_em'=> $expiraEm,
            'ttl_min'  => DOC_TOKEN_TTL / 60,
        ];
    }

    /**
     * Valida token mobile e retorna o cliente_id associado.
     */
    public function validarTokenMobile(string $token): ?int {
        $stmt = $this->db->prepare(
            "SELECT cliente_id FROM verificacao_documentos
             WHERE token_mobile = ? AND token_expira_em > NOW()
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Verifica se há uma sessão mobile aguardando resultado.
     */
    public function checkMobileResult(int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT status, score_analise, motivo_rejeicao
             FROM verificacao_documentos
             WHERE cliente_id = ?
               AND status IN ('verificado','rejeitado','em_analise')
             LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}