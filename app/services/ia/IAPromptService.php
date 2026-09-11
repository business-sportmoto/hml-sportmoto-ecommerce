<?php
/**
 * IAPromptService — regras da biblioteca de prompts da Central de IA.
 *
 * O que um prompt salvo precisa respeitar para não quebrar uma geração:
 *  - servir a UMA capacidade (texto, imagem ou vídeo) e, se ligado a um tipo
 *    de conteúdo, ao tipo da MESMA capacidade — um prompt de vídeo num tipo
 *    de texto seria mandado a um modelo de texto;
 *  - usar só os {{marcadores}} que o IAPromptBuilder resolve. Um marcador
 *    desconhecido não é trocado e chega ao modelo como texto literal;
 *  - padrão só com tipo: é ao escolher o tipo no Gerar que o padrão entra
 *    no campo. Um por tipo — o banco garante (índice único).
 *
 * Procedência de "lido de imagem" é derivada NO SERVIDOR a partir da
 * geração — o navegador manda o id da leitura, nunca o id do arquivo.
 */
class IAPromptService
{
    /** Os únicos {{marcadores}} que o IAPromptBuilder::substituirPlaceholders resolve. */
    public const PLACEHOLDERS = ['produto_nome', 'marca', 'categoria', 'preco', 'preco_promo', 'estoque'];

    public const CAPACIDADES = ['texto' => 'Texto', 'imagem' => 'Imagem', 'video' => 'Vídeo'];
    public const ORIGENS     = ['manual' => 'Feito à mão', 'imagem' => 'Lido de imagem', 'sistema' => 'Sistema'];

    private const CORPO_MIN = 10;
    private const CORPO_MAX = 8000;

    private IAPromptTemplate $modelo;

    public function __construct(?IAPromptTemplate $modelo = null)
    {
        $this->modelo = $modelo ?? new IAPromptTemplate();
    }

    public function paraGerar(): array
    {
        return $this->modelo->paraGerar();
    }

    /** Tipos que podem ter prompt salvo: os do Gerar com capacidade de texto, imagem ou vídeo. */
    public function tiposElegiveis(): array
    {
        return array_values(array_filter(
            (new IATipoConteudo())->listarAtivos(),
            fn($t) => isset(self::CAPACIDADES[(string) $t['capacidade']])
        ));
    }

    /** Marcadores {{x}} do corpo que o builder não sabe resolver. */
    public function placeholdersInvalidos(string $corpo): array
    {
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $corpo, $m);
        return array_values(array_unique(array_filter(
            $m[1] ?? [],
            fn($n) => !in_array($n, self::PLACEHOLDERS, true)
        )));
    }

    /* ------------------------------------------------------------------ */
    /* Escrita                                                             */
    /* ------------------------------------------------------------------ */

    /** Cria ou atualiza a partir do POST do formulário. */
    public function salvar(array $post, int $usuarioId): array
    {
        if ($usuarioId <= 0) {
            return ['ok' => false, 'msg' => 'Sessão expirada — faça login novamente.'];
        }

        $id    = (int) ($post['id'] ?? 0);
        $atual = $id > 0 ? $this->modelo->buscar($id) : null;
        if ($id > 0 && $atual === null) {
            return ['ok' => false, 'msg' => 'Prompt não encontrado.'];
        }

        // Natureza é fato de nascimento: não muda na edição.
        $natureza = $atual !== null
            ? (string) $atual['natureza']
            : (((string) ($post['natureza'] ?? 'prompt')) === 'angulo' ? 'angulo' : 'prompt');

        $nome       = trim((string) ($post['nome'] ?? ''));
        $descricao  = trim((string) ($post['descricao'] ?? ''));
        $corpo      = trim(str_replace("\r\n", "\n", (string) ($post['corpo'] ?? '')));
        $tipoId     = (int) ($post['tipo_conteudo_id'] ?? 0);
        $ativo      = !empty($post['ativo']) ? 1 : 0;
        $padrao     = !empty($post['padrao']);
        $capacidade = $natureza === 'angulo' ? 'texto' : (string) ($post['capacidade'] ?? '');
        $angulo     = $natureza === 'angulo'
            ? strtolower(trim((string) ($post['angulo'] ?? ($atual['angulo'] ?? ''))))
            : null;

        $nLen = mb_strlen($nome);
        if ($nLen < 3 || $nLen > 100) {
            return ['ok' => false, 'msg' => 'Dê ao prompt um nome de 3 a 100 caracteres.'];
        }
        if (mb_strlen($descricao) > 255) {
            return ['ok' => false, 'msg' => 'A descrição passa de 255 caracteres.'];
        }
        if (!isset(self::CAPACIDADES[$capacidade])) {
            return ['ok' => false, 'msg' => 'Escolha para que o prompt serve: texto, imagem ou vídeo.'];
        }
        $cLen = mb_strlen($corpo);
        if ($cLen < self::CORPO_MIN || $cLen > self::CORPO_MAX) {
            return ['ok' => false, 'msg' => sprintf('O prompt precisa ter entre %d e %s caracteres (tem %s).',
                self::CORPO_MIN, number_format(self::CORPO_MAX, 0, ',', '.'), number_format($cLen, 0, ',', '.'))];
        }

        $invalidos = $this->placeholdersInvalidos($corpo);
        if ($invalidos !== []) {
            return ['ok' => false, 'msg' => 'Marcador desconhecido: {{' . implode('}}, {{', $invalidos) . '}}. '
                . 'Os aceitos são {{' . implode('}}, {{', self::PLACEHOLDERS) . '}} — os outros chegariam ao modelo como texto.'];
        }

        $tipo = null;
        if ($tipoId > 0) {
            $tipo = (new IATipoConteudo())->buscar($tipoId);
            if ($tipo === null || (int) $tipo['ativo'] !== 1 || ($tipo['grupo'] ?? '') === 'sistema') {
                return ['ok' => false, 'msg' => 'Tipo de conteúdo inválido ou inativo.'];
            }
            if ((string) $tipo['capacidade'] !== $capacidade) {
                return ['ok' => false, 'msg' => sprintf(
                    'O tipo "%s" gera %s, e este prompt é de %s — um não serve ao outro.',
                    $tipo['nome'],
                    mb_strtolower(self::CAPACIDADES[(string) $tipo['capacidade']] ?? (string) $tipo['capacidade']),
                    mb_strtolower(self::CAPACIDADES[$capacidade])
                )];
            }
        }

        if ($padrao && ($natureza !== 'prompt' || $tipo === null)) {
            return ['ok' => false, 'msg' => 'Para ser padrão, o prompt precisa estar ligado a um tipo de conteúdo — é ao escolher o tipo que o padrão entra no campo.'];
        }
        if ($padrao && !$ativo) {
            return ['ok' => false, 'msg' => 'Um prompt desativado não pode ser o padrão.'];
        }

        if ($natureza === 'angulo') {
            if (!preg_match('/^[a-z0-9_]{2,40}$/', (string) $angulo)) {
                return ['ok' => false, 'msg' => 'O código do ângulo usa só letras minúsculas, números e _ (2 a 40).'];
            }
            if ($this->modelo->anguloDuplicado((string) $angulo, $tipoId > 0 ? $tipoId : null, $id > 0 ? $id : null)) {
                return ['ok' => false, 'msg' => 'Já existe um ângulo com esse código para esse tipo.'];
            }
        }

        // Procedência: só na criação, e derivada da geração no servidor.
        $origem    = (string) ($atual['origem'] ?? 'manual');
        $geracaoId = null;
        $arquivoId = null;
        if ($atual === null && (int) ($post['geracao_id'] ?? 0) > 0) {
            $proc = $this->procedenciaDeLeitura((int) $post['geracao_id']);
            if ($proc === null) {
                return ['ok' => false, 'msg' => 'A leitura de imagem indicada não existe ou não concluiu.'];
            }
            $origem    = 'imagem';
            $geracaoId = $proc['geracao_id'];
            $arquivoId = $proc['arquivo_id'];
        }

        $novoId = $this->modelo->salvar($id > 0 ? $id : null, [
            'tipo_conteudo_id'  => $tipoId > 0 ? $tipoId : null,
            'natureza'          => $natureza,
            'capacidade'        => $capacidade,
            'angulo'            => $angulo,
            'nome'              => $nome,
            'descricao'         => $descricao,
            'corpo'             => $corpo,
            'ativo'             => $ativo,
            'padrao'            => $padrao,
            'origem'            => $origem,
            'geracao_id'        => $geracaoId,
            'imagem_arquivo_id' => $arquivoId,
            'criado_por'        => $usuarioId,
        ]);

        if ($novoId <= 0) {
            return ['ok' => false, 'msg' => 'Não foi possível salvar o prompt. O erro foi registrado.'];
        }

        LogService::audit('ia_prompt_salvo', [
            'id'         => $novoId,
            'novo'       => $id === 0,
            'natureza'   => $natureza,
            'capacidade' => $capacidade,
            'padrao'     => $padrao,
            'origem'     => $origem,
            'usuario_id' => $usuarioId,
        ]);

        return ['ok' => true, 'id' => $novoId, 'msg' => $id > 0 ? 'Prompt atualizado.' : 'Prompt salvo na biblioteca.'];
    }

    public function alternar(int $id): array
    {
        if ($this->modelo->buscar($id) === null) {
            return ['ok' => false, 'msg' => 'Prompt não encontrado.'];
        }
        $novo = $this->modelo->alternar($id);
        if ($novo === null) {
            return ['ok' => false, 'msg' => 'Não foi possível alterar o prompt.'];
        }
        return ['ok' => true, 'ativo' => $novo, 'msg' => $novo === 1 ? 'Prompt ativado.' : 'Prompt desativado.'];
    }

    public function definirPadrao(int $id): array
    {
        $p = $this->modelo->buscar($id);
        if ($p === null) {
            return ['ok' => false, 'msg' => 'Prompt não encontrado.'];
        }
        if ($p['natureza'] !== 'prompt' || empty($p['tipo_conteudo_id'])) {
            return ['ok' => false, 'msg' => 'Só um prompt ligado a um tipo de conteúdo pode ser o padrão desse tipo.'];
        }
        if (!$this->modelo->definirPadrao($id, (int) $p['tipo_conteudo_id'])) {
            return ['ok' => false, 'msg' => 'Não foi possível definir o padrão.'];
        }
        LogService::audit('ia_prompt_padrao', ['id' => $id, 'tipo_conteudo_id' => (int) $p['tipo_conteudo_id']]);
        return ['ok' => true, 'msg' => 'Agora é o padrão de "' . ($p['tipo_nome'] ?? 'tipo') . '".'];
    }

    public function duplicar(int $id, int $usuarioId): array
    {
        $p = $this->modelo->buscar($id);
        if ($p === null) {
            return ['ok' => false, 'msg' => 'Prompt não encontrado.'];
        }
        if ($p['natureza'] !== 'prompt') {
            return ['ok' => false, 'msg' => 'Ângulo não se duplica — crie um novo com outro código.'];
        }
        return $this->salvar([
            'natureza'         => 'prompt',
            'nome'             => mb_substr((string) $p['nome'], 0, 92) . ' (cópia)',
            'descricao'        => (string) ($p['descricao'] ?? ''),
            'corpo'            => (string) $p['corpo'],
            'capacidade'       => (string) $p['capacidade'],
            'tipo_conteudo_id' => (int) ($p['tipo_conteudo_id'] ?? 0),
            'ativo'            => 1,
            'padrao'           => 0,
        ], $usuarioId);
    }

    /**
     * Exclusão: sistema nunca; prompt já usado em geração também não — o
     * histórico aponta para ele. Nos dois casos, desativar resolve.
     */
    public function excluir(int $id): array
    {
        $p = $this->modelo->buscar($id);
        if ($p === null) {
            return ['ok' => false, 'msg' => 'Prompt não encontrado.'];
        }
        if ($p['origem'] === 'sistema') {
            return ['ok' => false, 'msg' => 'Os ângulos de sistema não podem ser excluídos — desative se não quiser usar.'];
        }
        $usos = $this->modelo->emUso($id);
        if ($usos > 0) {
            return ['ok' => false, 'msg' => "Este prompt já gerou {$usos} conteúdo(s) — o histórico aponta para ele. Desative em vez de excluir."];
        }
        if (!$this->modelo->excluir($id)) {
            return ['ok' => false, 'msg' => 'Não foi possível excluir o prompt.'];
        }
        LogService::audit('ia_prompt_excluido', ['id' => $id, 'nome' => (string) $p['nome']]);
        return ['ok' => true, 'msg' => 'Prompt excluído.'];
    }

    /* ------------------------------------------------------------------ */
    /* Leitura de imagem → formulário                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Pré-preenche o formulário a partir de uma leitura concluída. O corpo
     * sai do resultado GRAVADO da geração, normalizado — não do navegador.
     * $variante: 'imagem' | 'video'.
     */
    public function prefillDeLeitura(int $geracaoId, string $variante): ?array
    {
        $proc = $this->procedenciaDeLeitura($geracaoId);
        if ($proc === null) {
            return null;
        }
        $g     = (new IAGeracao())->buscarPorId($geracaoId);
        $bruto = json_decode((string) ($g['resultado_texto'] ?? ''), true);
        if (!is_array($bruto)) {
            return null;
        }
        $d   = IALeituraImagemService::normalizar($bruto);
        $cap = $variante === 'video' ? 'video' : 'imagem';

        $corpo = $cap === 'video' ? $d['prompt_video'] : $d['prompt_imagem'];
        if ($corpo === '') {
            return null;
        }
        if ($d['negativo'] !== '') {
            $corpo .= "\nAvoid: " . $d['negativo'];
        }

        return [
            'id'                => 0,
            'natureza'          => 'prompt',
            'capacidade'        => $cap,
            'tipo_conteudo_id'  => null,
            'angulo'            => null,
            'nome'              => mb_substr($d['titulo'] !== '' ? $d['titulo'] : 'Prompt lido de imagem', 0, 100),
            'descricao'         => mb_substr($d['resumo'], 0, 255),
            'corpo'             => $corpo,
            'ativo'             => 1,
            'padrao'            => 0,
            'origem'            => 'imagem',
            'geracao_id'        => $geracaoId,
            'imagem_arquivo_id' => $proc['arquivo_id'],
        ];
    }

    /** A geração é uma leitura de imagem concluída? Devolve ids ou null. */
    private function procedenciaDeLeitura(int $geracaoId): ?array
    {
        if ($geracaoId <= 0) {
            return null;
        }
        $tipo = (new IATipoConteudo())->buscarPorCodigo(IALeituraImagemService::TIPO);
        $g    = (new IAGeracao())->buscarPorId($geracaoId);
        if ($tipo === null || $g === null
            || (int) $g['tipo_conteudo_id'] !== (int) $tipo['id']
            || $g['status'] !== 'concluida') {
            return null;
        }
        return [
            'geracao_id' => $geracaoId,
            'arquivo_id' => (new IAGeracao())->arquivoPrincipalDe($geracaoId),
        ];
    }
}
