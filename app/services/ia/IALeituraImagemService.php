<?php
/**
 * IALeituraImagemService — lê UMA imagem e devolve prompts reutilizáveis
 * (de imagem e de vídeo) para a biblioteca da Central de IA.
 *
 * Fluxo: valida os bytes → guarda a imagem de origem (ia_arquivos) →
 * geração síncrona do tipo prompt_de_imagem, que nasce 'processando' →
 * orquestrador com a imagem anexada (só adapters com visão entram) → JSON
 * normalizado de volta para a tela.
 *
 * Nada vai para a biblioteca aqui: a pessoa revisa e escolhe salvar como
 * prompt de imagem, de vídeo, ou os dois. A imagem nunca vai para o banco
 * em base64 — ela só existe durante a chamada ao provedor.
 */
class IALeituraImagemService
{
    public const TIPO = 'prompt_de_imagem';

    public const MAX_BYTES = 5242880; // 5 MB

    private const MIMES    = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const LADO_MIN = 64;
    private const LADO_MAX = 8192;

    /**
     * Tokens de entrada de UMA imagem, só para a ESTIMATIVA. O Gemini conta
     * 258 tokens por bloco de 768 px; uma foto de 2000 px vira ~9 blocos.
     * Folga de propósito: é o número que barra antes de gastar.
     */
    private const TOKENS_IMAGEM_ESTIMADOS = 2400;

    private IAOrchestrator $orq;
    private IACustoService $custo;

    public function __construct(?IAOrchestrator $orq = null)
    {
        $this->orq   = $orq ?? new IAOrchestrator();
        $this->custo = new IACustoService();
    }

    /* ------------------------------------------------------------------ */
    /* Entradas                                                            */
    /* ------------------------------------------------------------------ */

    /** Upload do formulário ($_FILES['imagem']). @throws RuntimeException com mensagem exibível */
    public function lerUpload(array $arquivo, int $usuarioId): array
    {
        $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(match ($erro) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'A imagem passa do limite de upload do servidor.',
                UPLOAD_ERR_NO_FILE                        => 'Escolha uma imagem.',
                UPLOAD_ERR_PARTIAL                        => 'O envio foi interrompido — tente de novo.',
                default                                   => 'Falha no envio da imagem (código ' . $erro . ').',
            });
        }

        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Arquivo de upload inválido.');
        }
        if ((int) ($arquivo['size'] ?? 0) > self::MAX_BYTES) {
            throw new \RuntimeException('A imagem passa de 5 MB.');
        }

        return $this->lerBinario((string) file_get_contents($tmp), $usuarioId, null, 'upload');
    }

    /**
     * Foto principal de um produto. A URL vem do BANCO, nunca do navegador —
     * baixar uma URL mandada pela tela seria abrir a porta para o servidor
     * buscar endereços internos a pedido de quem quisesse.
     */
    public function lerFotoDoProduto(int $produtoId, int $usuarioId): array
    {
        $img = (new IARecorteService())->imagemDoProduto($produtoId);
        $url = (string) ($img['url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Este produto não tem foto com URL pública.');
        }

        return $this->lerBinario($this->baixar($url), $usuarioId, $produtoId, 'produto');
    }

    /* ------------------------------------------------------------------ */
    /* Regras                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Os bytes são uma imagem utilizável? O tipo sai do CONTEÚDO (finfo),
     * não da extensão nem do Content-Type — os dois o cliente escolhe.
     * @throws RuntimeException
     */
    public function validarImagem(string $binario): array
    {
        $bytes = strlen($binario);
        if ($bytes === 0) {
            throw new \RuntimeException('A imagem veio vazia.');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new \RuntimeException('A imagem passa de 5 MB.');
        }

        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->buffer($binario) ?: '');
        if (!isset(self::MIMES[$mime])) {
            throw new \RuntimeException('Formato não aceito (' . ($mime !== '' ? $mime : 'desconhecido') . '). Use JPG, PNG ou WebP.');
        }

        $dim = @getimagesizefromstring($binario);
        if ($dim === false) {
            throw new \RuntimeException('O arquivo não é uma imagem legível.');
        }
        $largura = (int) $dim[0];
        $altura  = (int) $dim[1];
        if ($largura < self::LADO_MIN || $altura < self::LADO_MIN) {
            throw new \RuntimeException("Imagem pequena demais ({$largura}×{$altura} px) — mínimo de 64 px por lado.");
        }
        if ($largura > self::LADO_MAX || $altura > self::LADO_MAX) {
            throw new \RuntimeException("Imagem grande demais ({$largura}×{$altura} px) — máximo de 8192 px por lado.");
        }

        return ['mime' => $mime, 'extensao' => self::MIMES[$mime], 'largura' => $largura, 'altura' => $altura, 'bytes' => $bytes];
    }

    /**
     * Só as chaves do contrato, texto sem caractere de controle e com teto de
     * tamanho. Público porque a biblioteca relê o resultado GRAVADO da
     * geração para pré-preencher o formulário — e ele sai pelo mesmo filtro.
     */
    public static function normalizar(array $d): array
    {
        $txt = function ($v, int $max): string {
            $s = is_scalar($v) ? (string) $v : '';
            $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
            $s = trim((string) preg_replace('/[ \t]+/', ' ', $s));
            return mb_substr($s, 0, $max);
        };

        $el        = is_array($d['elementos'] ?? null) ? $d['elementos'] : [];
        $elementos = [];
        foreach (['sujeito', 'material_cor', 'enquadramento', 'luz', 'fundo', 'camera'] as $k) {
            $elementos[$k] = $txt($el[$k] ?? '', 160);
        }

        return [
            'titulo'        => $txt($d['titulo'] ?? '', 100),
            'resumo'        => $txt($d['resumo'] ?? '', 300),
            'prompt_imagem' => $txt($d['prompt_imagem'] ?? '', 1500),
            'prompt_video'  => $txt($d['prompt_video'] ?? '', 1200),
            'negativo'      => $txt($d['negativo'] ?? '', 400),
            'elementos'     => $elementos,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Execução                                                            */
    /* ------------------------------------------------------------------ */

    private function lerBinario(string $binario, int $usuarioId, ?int $produtoId, string $origem): array
    {
        if ($usuarioId <= 0) {
            throw new \RuntimeException('Sessão expirada — faça login novamente.');
        }

        $img = $this->validarImagem($binario);

        $tipoRow = (new IATipoConteudo())->buscarPorCodigo(self::TIPO);
        if ($tipoRow === null || (int) $tipoRow['ativo'] !== 1) {
            throw new \RuntimeException('Tipo prompt_de_imagem ausente ou inativo — rode php cli/ia-migrar.php --aplicar.');
        }

        $prompt = 'Analise a imagem anexada e devolva somente o JSON pedido nas instruções.';

        // Estimativa com o custo do modelo FIXADO no tipo (é ele que roda
        // primeiro), mais a imagem — que o texto do prompt não mostra.
        $pinoId   = (int) ($tipoRow['modelo_id'] ?? 0) > 0 ? (int) $tipoRow['modelo_id'] : null;
        $cfg      = $this->custo->custoConfigDoModelo($pinoId) ?? $this->custo->custoConfigPrimarioTexto();
        $custoEst = $this->custo->estimarTexto(
            $cfg,
            mb_strlen($prompt) + mb_strlen((string) $tipoRow['instrucoes_sistema']) + self::TOKENS_IMAGEM_ESTIMADOS * 4,
            (int) $tipoRow['max_tokens']
        );
        $chk = $this->custo->podeGerar($usuarioId, $custoEst, 1);
        if (!$chk['ok']) {
            throw new \RuntimeException($chk['msg']);
        }

        $hash   = hash('sha256', $binario);
        $uuid   = $this->uuidV4();
        $modelo = new IAGeracao();

        // Nasce 'processando': execução síncrona (o Ajax espera), então o
        // worker NUNCA pode reivindicar esta linha e ler de novo.
        $id = $modelo->criar([
            'uuid'                     => $uuid,
            'usuario_id'               => $usuarioId,
            'produto_id'               => $produtoId,
            'campanha_id'              => null,
            'geracao_origem_id'        => null,
            'tipo_conteudo_id'         => (int) $tipoRow['id'],
            'capacidade'               => 'texto',
            'formato'                  => null,
            'angulo'                   => null,
            'prompt_template_id'       => null,
            'prompt_template_snapshot' => null,
            'prompt_final'             => $prompt,
            'contexto'                 => json_encode([
                'origem'     => $origem,
                'produto_id' => $produtoId,
                'imagem'     => $img + ['sha256' => $hash],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'chave_dedup'              => hash('sha256', uniqid(self::TIPO . '|', true)),
            'custo_estimado_usd'       => $custoEst,
            'status'                   => 'processando',
        ]);
        // criar() devolve int: id, 0 em erro, -1062 na dedup — nunca null.
        if ($id <= 0) {
            throw new \RuntimeException('Não foi possível registrar a leitura da imagem.');
        }

        // A imagem de origem fica guardada: é a procedência do prompt que sair daqui.
        $arquivoId = $this->guardarOrigem($id, $uuid, $binario, $img, $hash);

        $geracao = [
            'id'                 => $id,
            'uuid'               => $uuid,
            'usuario_id'         => $usuarioId,
            'capacidade'         => 'texto',
            'prompt_final'       => $prompt,
            'custo_estimado_usd' => $custoEst,
        ];
        $tipoArr = [
            'instrucoes_sistema' => $tipoRow['instrucoes_sistema'],
            'max_tokens'         => (int) $tipoRow['max_tokens'],
            'modelo_id'          => $pinoId,
            'nome'               => $tipoRow['nome'],
            'saida'              => 'json',
            'imagens'            => [['mime' => $img['mime'], 'base64' => base64_encode($binario)]],
        ];

        $servico = new IAGeracaoService();

        // Orquestrador que LANÇA deixaria a linha presa em 'processando' — e o
        // watchdog a devolveria à fila para o worker ler sem a imagem.
        try {
            $r = $this->orq->executarTexto($geracao, $tipoArr);
        } catch (\Throwable $e) {
            $servico->falhar($geracao, IAResultado::falha('excecao', mb_substr($e->getMessage(), 0, 500), false));
            throw $e;
        }

        if (!$r->ok) {
            $servico->falhar($geracao, $r);
            throw new \RuntimeException('Leitura: ' . ($r->erro ?: 'nenhum modelo com visão respondeu.'));
        }

        $bruto = $this->decodificarJson((string) $r->texto);
        if ($bruto === null) {
            $servico->falhar($geracao, IAResultado::falha('json_invalido', 'Provedor não devolveu o JSON esperado.', false));
            throw new \RuntimeException('O modelo não devolveu o JSON esperado — tente de novo.');
        }

        // O provedor respondeu e cobrou: conclui mesmo se a imagem for
        // inutilizável. Isso é resposta, não falha de infraestrutura.
        $servico->concluir($geracao, $r);

        $d = self::normalizar($bruto);
        if ($d['prompt_imagem'] === '' && $d['prompt_video'] === '') {
            throw new \RuntimeException('A IA não conseguiu usar esta imagem' . ($d['resumo'] !== '' ? ': ' . $d['resumo'] : '.'));
        }

        return $d + [
            'geracao_id' => $id,
            'arquivo_id' => $arquivoId,
            '_ia'        => [
                'modelo'    => $r->modeloCodigo,
                'provedor'  => $r->provedorCodigo,
                'custo_usd' => $r->custoRealUsd,
                'tempo_ms'  => $r->tempoMs,
            ],
        ];
    }

    /** Grava a imagem de origem em IA_STORAGE_PATH/entradas/AAAA/MM. Devolve o id em ia_arquivos ou null. */
    private function guardarOrigem(int $geracaoId, string $uuid, string $binario, array $img, string $hash): ?int
    {
        try {
            $base = defined('IA_STORAGE_PATH')
                ? rtrim(IA_STORAGE_PATH, '/')
                : rtrim(dirname(__DIR__, 3), '/') . '/storage/ia';

            $dir = $base . '/entradas/' . date('Y/m');
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                LogService::warning('ia_storage_entradas_indisponivel', ['dir' => $dir]);
                return null;
            }

            $caminho = $dir . '/' . $uuid . '.' . $img['extensao'];
            if (file_put_contents($caminho, $binario, LOCK_EX) === false) {
                LogService::warning('ia_gravar_entrada_falhou', ['caminho' => $caminho]);
                return null;
            }

            $arqId = (new IAGeracao())->registrarArquivo($geracaoId, 'imagem', $caminho, $img['mime'], $img['bytes'], $hash);
            return $arqId > 0 ? $arqId : null;
        } catch (\Throwable $e) {
            LogService::warning('ia_guardar_origem_erro', ['geracao_id' => $geracaoId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** GET da foto do produto: só http(s), com teto de tempo e de tamanho. */
    private function baixar(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $corpo  = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro   = curl_error($ch);
        curl_close($ch);

        if ($corpo === false || $status !== 200 || $corpo === '') {
            throw new \RuntimeException('Não foi possível baixar a foto do produto (' . ($erro !== '' ? $erro : 'HTTP ' . $status) . ').');
        }
        if (strlen((string) $corpo) > self::MAX_BYTES) {
            throw new \RuntimeException('A foto do produto passa de 5 MB.');
        }
        return (string) $corpo;
    }

    /** JSON do modelo, tolerando cerca de ```json e texto em volta. */
    private function decodificarJson(string $texto): ?array
    {
        $t = trim($texto);
        $t = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
        $d = json_decode($t, true);
        if (is_array($d)) {
            return $d;
        }
        $ini = strpos($t, '{');
        $fim = strrpos($t, '}');
        if ($ini === false || $fim === false || $fim <= $ini) {
            return null;
        }
        $d = json_decode(substr($t, $ini, $fim - $ini + 1), true);
        return is_array($d) ? $d : null;
    }

    private function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
