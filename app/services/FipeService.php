<?php
// app/services/FipeService.php
declare(strict_types=1);

class FipeService {

    private PDO    $db;
    private string $baseUrl  = 'https://fipe.parallelum.com.br/api/v2';
    private string $token;   // X-Subscription-Token do fipe.online
    private int    $timeout  = 20;

    public function __construct() {
        $this->db    = Database::getInstance()->getConnection();
        $this->token = FIPE_TOKEN ?? ''; // define em defines.php
    }

    // ────────────────────────────────────────────────────
    // API PÚBLICA
    // ────────────────────────────────────────────────────

    public function sincronizarTudo(callable $progress = null): array {
        $logId = $this->iniciarLog();

        try {
            $stats = ['montadoras' => 0, 'modelos' => 0, 'anos' => 0];

            // GET /motorcycles/brands → [{code, name}, ...]
            $marcas = $this->get('/motorcycles/brands');
            if ($progress) $progress("Montadoras: " . count($marcas));

            foreach ($marcas as $marca) {
                $montadoraId = $this->upsertMontadora($marca);
                $stats['montadoras']++;

                usleep(300_000); // 300ms entre montadoras

                // GET /motorcycles/brands/{brandId}/models
                $modelos = $this->get("/motorcycles/brands/{$marca['code']}/models");
                if ($progress) $progress("  {$marca['name']}: " . count($modelos) . " modelos");

                foreach ($modelos as $modelo) {
                    $modeloId = $this->upsertModelo($montadoraId, $modelo);
                    $stats['modelos']++;

                    usleep(150_000); // 150ms entre modelos

                    // GET /motorcycles/brands/{brandId}/models/{modelId}/years
                    $anos = $this->get(
                        "/motorcycles/brands/{$marca['code']}/models/{$modelo['code']}/years"
                    );

                    foreach ($anos as $ano) {
                        $this->upsertAno($modeloId, $ano);
                        $stats['anos']++;
                    }
                }
            }

            $this->finalizarLog($logId, $stats, 'ok');
            return ['ok' => true, 'stats' => $stats];

        } catch (\Exception $e) {
            $this->finalizarLog($logId, [], 'erro', $e->getMessage());
            throw $e;
        }
    }

    public function sincronizarMontadora(string $brandCode): array {
        $modelos = $this->get("/motorcycles/brands/{$brandCode}/models");
        $marca   = ['code' => $brandCode, 'name' => $brandCode]; // fallback

        // Tenta pegar o nome da montadora
        $marcas = $this->get('/motorcycles/brands');
        foreach ($marcas as $m) {
            if ($m['code'] === $brandCode) { $marca = $m; break; }
        }

        $montadoraId = $this->upsertMontadora($marca);
        $stats       = ['modelos' => 0, 'anos' => 0];

        foreach ($modelos as $modelo) {
            $modeloId = $this->upsertModelo($montadoraId, $modelo);
            $stats['modelos']++;

            $anos = $this->get(
                "/motorcycles/brands/{$brandCode}/models/{$modelo['code']}/years"
            );
            foreach ($anos as $ano) {
                $this->upsertAno($modeloId, $ano);
                $stats['anos']++;
            }
            usleep(150_000);
        }

        return ['ok' => true, 'stats' => $stats];
    }

    // ────────────────────────────────────────────────────
    // HTTP — GET com token
    // ────────────────────────────────────────────────────

    private function get(string $endpoint): array {
        $url     = $this->baseUrl . $endpoint;
        $headers = ['Accept: application/json'];

        // Token é opcional na API pública, mas aumenta rate limit
        if (!empty($this->token)) {
            $headers[] = 'X-Subscription-Token: ' . $this->token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ecommerce-fipe/1.0)',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("cURL: {$curlErr}");
        }

        if ($httpCode === 429) {
            // Rate limit — aguarda e tenta de novo
            sleep(5);
            return $this->get($endpoint);
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("FIPE API HTTP {$httpCode}: {$url}");
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException(
                "JSON inválido da API FIPE. Resposta: " . substr($response, 0, 200)
            );
        }

        return $data;
    }

    // ────────────────────────────────────────────────────
    // UPSERT — campos corretos: code + name
    // ────────────────────────────────────────────────────

    private function upsertMontadora(array $dados): int {
        $nome = trim($dados['name'] ?? '');
        $code = trim($dados['code'] ?? '');
        if (!$nome || !$code) return 0;

        $slug = $this->slugify($nome); // ← corrigido

        $stmt = $this->db->prepare(
            "SELECT id FROM moto_montadoras WHERE fipe_codigo = ? LIMIT 1"
        );
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $this->db->prepare(
                "UPDATE moto_montadoras SET nome=?, slug=? WHERE id=?"
            )->execute([$nome, $slug, $id]);
            return (int)$id;
        }

        $slugFinal = $this->slugUnico('moto_montadoras', $slug);

        $this->db->prepare(
            "INSERT INTO moto_montadoras (nome, slug, fipe_codigo) VALUES (?,?,?)"
        )->execute([$nome, $slugFinal, $code]);

        return (int)$this->db->lastInsertId();
    }

    private function slugify(string $texto): string {
        $slug = mb_strtolower(trim($texto));
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        return trim($slug, '-');
    }

    private function upsertModelo(int $montadoraId, array $dados): int {
        $nome = trim($dados['name'] ?? '');
        $code = trim($dados['code'] ?? '');
        if (!$nome || !$code) return 0;

        $slug = $this->slugify($nome); // ← corrigido

        $stmt = $this->db->prepare(
            "SELECT id FROM moto_modelos
            WHERE montadora_id=? AND fipe_codigo=? LIMIT 1"
        );
        $stmt->execute([$montadoraId, $code]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $this->db->prepare(
                "UPDATE moto_modelos SET nome=?, slug=? WHERE id=?"
            )->execute([$nome, $slug, $id]);
            return (int)$id;
        }

        $slugFinal = $this->slugUnico('moto_modelos', $slug, $montadoraId);

        $this->db->prepare(
            "INSERT INTO moto_modelos (montadora_id, nome, slug, fipe_codigo)
            VALUES (?,?,?,?)"
        )->execute([$montadoraId, $nome, $slugFinal, $code]);

        return (int)$this->db->lastInsertId();
    }

    private function upsertAno(int $modeloId, array $dados): void {
        // API retorna: { "code": "2023-1", "name": "2023 Gasolina" }
        $anoRaw = trim($dados['name'] ?? '');
        $code   = trim($dados['code'] ?? '');

        // Extrai ano numérico de "2023 Gasolina" → 2023
        // Ou do code "2023-1" → 2023
        preg_match('/^(\d{4})/', $anoRaw ?: $code, $m);
        $ano = isset($m[1]) ? (int)$m[1] : 0;
        if (!$ano || $ano < 1960 || $ano > 2030) return;

        $stmt = $this->db->prepare(
            "SELECT id FROM moto_anos WHERE modelo_id=? AND ano=? LIMIT 1"
        );
        $stmt->execute([$modeloId, $ano]);

        if ($stmt->fetchColumn()) {
            $this->db->prepare(
                "UPDATE moto_anos SET fipe_codigo=? WHERE modelo_id=? AND ano=?"
            )->execute([$code, $modeloId, $ano]);
        } else {
            $this->db->prepare(
                "INSERT INTO moto_anos (modelo_id, ano, fipe_codigo) VALUES (?,?,?)"
            )->execute([$modeloId, $ano, $code]);
        }
    }

    // ────────────────────────────────────────────────────
    // HELPERS
    // ────────────────────────────────────────────────────

    /**
     * Garante slug único na tabela.
     * $scopeId = montadora_id para moto_modelos.
     */
    private function slugUnico(string $tabela, string $slug, ?int $scopeId = null): string {
        $final   = $slug;
        $counter = 1;

        while (true) {
            if ($scopeId !== null) {
                $stmt = $this->db->prepare(
                    "SELECT id FROM {$tabela} WHERE montadora_id=? AND slug=? LIMIT 1"
                );
                $stmt->execute([$scopeId, $final]);
            } else {
                $stmt = $this->db->prepare(
                    "SELECT id FROM {$tabela} WHERE slug=? LIMIT 1"
                );
                $stmt->execute([$final]);
            }

            if (!$stmt->fetchColumn()) break;
            $final = $slug . '-' . (++$counter);
        }

        return $final;
    }

    // ────────────────────────────────────────────────────
    // LOG
    // ────────────────────────────────────────────────────

    private function iniciarLog(): int {
        $this->db->prepare(
            "INSERT INTO fipe_sync_log (status) VALUES ('rodando')"
        )->execute();
        return (int)$this->db->lastInsertId();
    }

    private function finalizarLog(int $id, array $stats, string $status, string $erro = ''): void {
        $this->db->prepare(
            "UPDATE fipe_sync_log
             SET finalizado_em=NOW(), montadoras=?, modelos=?, anos=?, status=?, erro_msg=?
             WHERE id=?"
        )->execute([
            $stats['montadoras'] ?? 0,
            $stats['modelos']    ?? 0,
            $stats['anos']       ?? 0,
            $status,
            $erro ?: null,
            $id,
        ]);
    }

    // Adicionar ao FipeService — substituem o sincronizarTudo() para uso step-by-step

    /**
     * Busca todas as marcas de moto da FIPE e salva no banco.
     * Retorna array com dados para o JS iterar.
     */
    public function buscarESalvarMarcas(): array {
        $marcas = $this->get('/motorcycles/brands');
        $result = [];

        foreach ($marcas as $marca) {
            $nome = trim($marca['name'] ?? '');
            $code = trim($marca['code'] ?? '');
            if (!$nome || !$code) continue;

            $id = $this->upsertMontadora($marca);
            if ($id) {
                $result[] = [
                    'id_local'  => $id,
                    'fipe_code' => $code,
                    'nome'      => $nome,
                ];
            }
        }

        return $result;
    }

    /**
     * Busca modelos de uma marca e salva no banco.
     * Retorna array para o JS iterar os anos.
     */
    public function buscarESalvarModelos(int $montadoraId, string $fipeCode): array {
        $modelos = $this->get("/motorcycles/brands/{$fipeCode}/models");
        $result  = [];

        foreach ($modelos as $modelo) {
            $nome = trim($modelo['name'] ?? '');
            $code = trim($modelo['code'] ?? '');
            if (!$nome || !$code) continue;

            $id = $this->upsertModelo($montadoraId, $modelo);
            if ($id) {
                $result[] = [
                    'id_local'         => $id,
                    'fipe_code_modelo' => $code,
                    'nome'             => $nome,
                ];
            }
        }

        return $result;
    }

    /**
     * Busca anos de um modelo e salva no banco.
     * Retorna total de anos salvos.
     */
    public function buscarESalvarAnos(
        int    $modeloId,
        string $fipeCodeMarca,
        string $fipeCodeModelo
    ): int {
        $anos  = $this->get(
            "/motorcycles/brands/{$fipeCodeMarca}/models/{$fipeCodeModelo}/years"
        );
        $total = 0;

        foreach ($anos as $ano) {
            $this->upsertAno($modeloId, $ano);
            $total++;
        }

        return $total;
    }
}