<?php
/**
 * Gestão administrativa de transportadoras (cadastro, credenciais,
 * serviços, status, prioridade, teste de conexão e logs de comunicação).
 *
 * Princípios aplicados (padrão do projeto):
 *  - allowlist de colunas em todo UPDATE/INSERT;
 *  - transação para operações compostas (transportadora + serviços);
 *  - segredos com preservação (campo secreto em branco mantém o salvo);
 *  - segredos nunca voltam ao navegador (obter() os redige);
 *  - LogService::audit em toda mutação relevante;
 *  - validação como método ESTÁTICO PURO (validar), testável sem banco.
 */
class TransportadoraAdminService
{
    private PDO $pdo;

    /** Colunas graváveis em log_transportadoras. */
    private const CAMPOS = [
        'nome', 'slug', 'adapter', 'logo_url', 'status', 'ambiente', 'cep_origem',
        'contrato', 'config', 'prazo_preparo_dias', 'margem_tipo', 'margem_percentual',
        'margem_valor', 'seguro_padrao', 'usa_valor_declarado', 'suporta_coleta',
        'suporta_postagem', 'prioridade',
    ];

    private const STATUS   = ['ativo', 'pausado', 'inativo'];
    private const AMBIENTES = ['producao', 'homologacao', 'sandbox'];
    private const MARGENS  = ['nenhum', 'desconto', 'acrescimo'];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       LEITURA
       ================================================================= */

    /** Lista transportadoras com contagem de serviços (para a tela). */
    public function listar(array $filtros = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filtros['status'])) { $where[] = 't.status = :st'; $params[':st'] = $filtros['status']; }
        if (!empty($filtros['busca'])) { $where[] = '(t.nome LIKE :q OR t.slug LIKE :q)'; $params[':q'] = '%' . $filtros['busca'] . '%'; }
        $sql = "SELECT t.*, (SELECT COUNT(*) FROM log_transportadora_servicos s WHERE s.transportadora_id = t.id) AS servicos_qtd
                FROM log_transportadoras t";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY t.prioridade ASC, t.nome ASC';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar transportadoras', ['erro' => $e->getMessage()]);
            return [];
        }

        // Redige segredos e injeta metadados do adapter.
        $cat = TransportadoraManager::catalogo();
        foreach ($linhas as &$l) {
            $l['config'] = $this->redigirConfig($l['config'] ?? null, (string)$l['adapter']);
            $l['adapter_label'] = $cat[$l['adapter']]['label'] ?? $l['adapter'];
        }
        return $linhas;
    }

    /** Uma transportadora + serviços + config redigida (para o formulário). */
    public function obter(int $id): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM log_transportadoras WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) return null;

            $ss = $this->pdo->prepare("SELECT * FROM log_transportadora_servicos WHERE transportadora_id = :id ORDER BY prioridade ASC, nome ASC");
            $ss->execute([':id' => $id]);
            $t['servicos'] = $ss->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao obter transportadora', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }

        // Config: informa quais segredos Já estão preenchidos, sem revelar valor.
        $t['config_preenchida'] = $this->segredosPreenchidos($t['config'] ?? null, (string)$t['adapter']);
        $t['config'] = $this->redigirConfig($t['config'] ?? null, (string)$t['adapter']);
        return $t;
    }

    /** Logs de comunicação de uma transportadora (paginado). */
    public function logs(int $id, int $pagina = 1, int $porPagina = 30): array
    {
        $pagina = max(1, $pagina);
        $off = ($pagina - 1) * $porPagina;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, tipo, referencia_id, status_http, sucesso, duracao_ms, criado_em, requisicao, resposta
                 FROM log_comunicacoes WHERE transportadora_id = :id
                 ORDER BY id DESC LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
            $stmt->bindValue(':off', $off, PDO::PARAM_INT);
            $stmt->execute();
            $itens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $tot = $this->pdo->prepare("SELECT COUNT(*) FROM log_comunicacoes WHERE transportadora_id = :id");
            $tot->execute([':id' => $id]);
            $total = (int)$tot->fetchColumn();
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar logs de comunicação', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['itens' => [], 'total' => 0, 'pagina' => $pagina, 'por_pagina' => $porPagina];
        }
        return ['itens' => $itens, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina];
    }

    /* =================================================================
       ESCRITA
       ================================================================= */

    /**
     * Cria ou atualiza uma transportadora (+ serviços) em transação.
     * @return array{ok:bool, id?:int, erros?:array<string,string>, erro?:string}
     */
    public function salvar(array $dados, ?int $usuarioId = null): array
    {
        $id = isset($dados['id']) ? (int)$dados['id'] : 0;
        $isUpdate = $id > 0;

        $erros = self::validar($dados, $isUpdate);
        if ($erros) {
            return ['ok' => false, 'erros' => $erros];
        }

        $adapter = (string)$dados['adapter'];
        $existente = $isUpdate ? $this->obterCru($id) : null;
        if ($isUpdate && !$existente) {
            return ['ok' => false, 'erro' => 'Transportadora não encontrada.'];
        }

        // Monta config preservando segredos em branco.
        $configFinal = $this->mesclarConfig(
            $existente['config'] ?? null,
            $dados['config'] ?? [],
            $adapter
        );

        $campos = [
            'nome'               => trim((string)$dados['nome']),
            'slug'               => $this->slug($dados['slug'] ?? $dados['nome']),
            'adapter'            => $adapter,
            'logo_url'           => $this->nuloSeVazio($dados['logo_url'] ?? null),
            'status'             => in_array($dados['status'] ?? '', self::STATUS, true) ? $dados['status'] : 'inativo',
            'ambiente'           => in_array($dados['ambiente'] ?? '', self::AMBIENTES, true) ? $dados['ambiente'] : 'sandbox',
            'cep_origem'         => $this->nuloSeVazio($dados['cep_origem'] ?? null),
            'contrato'           => $this->nuloSeVazio($dados['contrato'] ?? null),
            'config'             => json_encode($configFinal, JSON_UNESCAPED_UNICODE),
            'prazo_preparo_dias' => max(0, (int)($dados['prazo_preparo_dias'] ?? 0)),
            'margem_tipo'        => in_array($dados['margem_tipo'] ?? '', self::MARGENS, true) ? $dados['margem_tipo'] : 'nenhum',
            'margem_percentual'  => round((float)($dados['margem_percentual'] ?? 0), 2),
            'margem_valor'       => round((float)($dados['margem_valor'] ?? 0), 2),
            'seguro_padrao'      => !empty($dados['seguro_padrao']) ? 1 : 0,
            'usa_valor_declarado'=> !empty($dados['usa_valor_declarado']) ? 1 : 0,
            'suporta_coleta'     => !empty($dados['suporta_coleta']) ? 1 : 0,
            'suporta_postagem'   => isset($dados['suporta_postagem']) ? (!empty($dados['suporta_postagem']) ? 1 : 0) : 1,
            'prioridade'         => (int)($dados['prioridade'] ?? 100),
        ];

        try {
            $this->pdo->beginTransaction();

            if ($isUpdate) {
                $sets = [];
                foreach ($campos as $k => $_) {
                    if (in_array($k, self::CAMPOS, true)) $sets[] = "`{$k}` = :{$k}";
                }
                $sql = "UPDATE log_transportadoras SET " . implode(', ', $sets) . " WHERE id = :id";
                $stmt = $this->pdo->prepare($sql);
                $campos[':id'] = $id;
                $stmt->execute($this->comColon($campos));
            } else {
                $cols = array_filter(array_keys($campos), fn($k) => in_array($k, self::CAMPOS, true));
                $sql = "INSERT INTO log_transportadoras (`" . implode('`,`', $cols) . "`) VALUES (:" . implode(',:', $cols) . ")";
                $stmt = $this->pdo->prepare($sql);
                $ins = [];
                foreach ($cols as $c) $ins[":{$c}"] = $campos[$c];
                $stmt->execute($ins);
                $id = (int)$this->pdo->lastInsertId();
            }

            // Sincroniza serviços (replace do conjunto, em transação).
            if (array_key_exists('servicos', $dados) && is_array($dados['servicos'])) {
                $this->sincronizarServicos($id, $dados['servicos']);
            }

            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            // slug duplicado?
            if ((int)$e->errorInfo[1] === 1062) {
                return ['ok' => false, 'erros' => ['slug' => 'Já existe uma transportadora com esse identificador (slug).']];
            }
            LogService::error('Falha ao salvar transportadora', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar. Verifique os dados e tente novamente.'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            LogService::error('Falha ao salvar transportadora', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro inesperado ao salvar.'];
        }

        LogService::audit($isUpdate ? 'Transportadora atualizada' : 'Transportadora criada', [
            'transportadora_id' => $id, 'adapter' => $adapter, 'usuario_id' => $usuarioId,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** Altera o status (ativo|pausado|inativo). */
    public function alternarStatus(int $id, string $status, ?int $usuarioId = null): array
    {
        if (!in_array($status, self::STATUS, true)) {
            return ['ok' => false, 'erro' => 'Status inválido.'];
        }
        try {
            $stmt = $this->pdo->prepare("UPDATE log_transportadoras SET status = :s WHERE id = :id");
            $stmt->execute([':s' => $status, ':id' => $id]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao alterar status de transportadora', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Não foi possível alterar o status.'];
        }
        LogService::audit('Status de transportadora alterado', ['transportadora_id' => $id, 'status' => $status, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'status' => $status];
    }

    /** Reordena prioridades a partir de uma lista de IDs (0 = maior prioridade). */
    public function reordenar(array $ordemIds, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("UPDATE log_transportadoras SET prioridade = :p WHERE id = :id");
            $p = 10;
            foreach ($ordemIds as $id) {
                $stmt->execute([':p' => $p, ':id' => (int)$id]);
                $p += 10;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            LogService::error('Falha ao reordenar transportadoras', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Não foi possível reordenar.'];
        }
        LogService::audit('Transportadoras reordenadas', ['ordem' => $ordemIds, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /** Testa a conexão resolvendo o adapter e chamando testarConexao(). */
    public function testarConexao(int $id, ?int $usuarioId = null): array
    {
        $linha = $this->obterCru($id);
        if (!$linha) {
            return ['ok' => false, 'mensagem' => 'Transportadora não encontrada.'];
        }
        try {
            $adapter = TransportadoraManager::resolver($linha);
            $res = $adapter->testarConexao(); // o adapter já registra em log_comunicacoes
        } catch (\Throwable $e) {
            LogService::error('Erro no teste de conexão', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'mensagem' => 'Erro ao testar: ' . $e->getMessage()];
        }
        // Atualiza carimbo de última sincronização em caso de sucesso.
        if (!empty($res['ok'])) {
            try {
                $this->pdo->prepare("UPDATE log_transportadoras SET ultima_sync = NOW() WHERE id = :id")->execute([':id' => $id]);
            } catch (\Throwable $e) { /* não crítico */ }
        }
        LogService::audit('Teste de conexão de transportadora', ['transportadora_id' => $id, 'ok' => !empty($res['ok']), 'usuario_id' => $usuarioId]);
        return $res;
    }

    /* =================================================================
       VALIDAÇÃO (pura — testável sem banco)
       ================================================================= */

    /** @return array<string,string> mapa campo => mensagem de erro (vazio = ok) */
    public static function validar(array $d, bool $isUpdate = false): array
    {
        $e = [];
        $cat = TransportadoraManager::catalogo();

        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '')          $e['nome'] = 'Informe o nome da transportadora.';
        elseif (mb_strlen($nome) > 120) $e['nome'] = 'Nome muito longo (máx. 120).';

        $adapter = (string)($d['adapter'] ?? '');
        if ($adapter === '' || !in_array($adapter, TransportadoraManager::disponiveis(), true)) {
            $e['adapter'] = 'Selecione um adapter válido.';
        }

        $ambiente = (string)($d['ambiente'] ?? '');
        if (!in_array($ambiente, self::AMBIENTES, true)) {
            $e['ambiente'] = 'Ambiente inválido.';
        } elseif (isset($cat[$adapter]) && !in_array($ambiente, $cat[$adapter]['ambientes'], true)) {
            $e['ambiente'] = 'Este adapter não suporta o ambiente selecionado.';
        }

        if (isset($d['margem_tipo']) && !in_array($d['margem_tipo'], self::MARGENS, true)) {
            $e['margem_tipo'] = 'Tipo de margem inválido.';
        }
        if (isset($d['status']) && !in_array($d['status'], self::STATUS, true)) {
            $e['status'] = 'Status inválido.';
        }

        // Campos secretos obrigatórios: exigidos apenas na CRIAÇÃO.
        if (!$isUpdate && isset($cat[$adapter])) {
            $config = (array)($d['config'] ?? []);
            foreach ($cat[$adapter]['campos'] as $campo) {
                if (!empty($campo['obrigatorio'])) {
                    $val = trim((string)($config[$campo['nome']] ?? ''));
                    if ($val === '') {
                        $e['config_' . $campo['nome']] = $campo['label'] . ' é obrigatório.';
                    }
                }
            }
        }

        return $e;
    }

    /* =================================================================
       Helpers privados
       ================================================================= */

    private function obterCru(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM log_transportadoras WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function sincronizarServicos(int $transportadoraId, array $servicos): void
    {
        $this->pdo->prepare("DELETE FROM log_transportadora_servicos WHERE transportadora_id = :id")
                  ->execute([':id' => $transportadoraId]);

        if (empty($servicos)) return;

        $sql = "INSERT INTO log_transportadora_servicos
                (transportadora_id, codigo, nome, modalidade, habilitado, prazo_adicional, prioridade)
                VALUES (:tid, :codigo, :nome, :modalidade, :hab, :prazo, :prio)";
        $stmt = $this->pdo->prepare($sql);
        foreach ($servicos as $s) {
            $codigo = trim((string)($s['codigo'] ?? ''));
            if ($codigo === '') continue;
            $stmt->execute([
                ':tid'        => $transportadoraId,
                ':codigo'     => $codigo,
                ':nome'       => trim((string)($s['nome'] ?? $codigo)),
                ':modalidade' => $this->nuloSeVazio($s['modalidade'] ?? null),
                ':hab'        => !empty($s['habilitado']) ? 1 : 0,
                ':prazo'      => (int)($s['prazo_adicional'] ?? 0),
                ':prio'       => (int)($s['prioridade'] ?? 100),
            ]);
        }
    }

    /** Mescla config nova sobre a existente, preservando segredos em branco. */
    private function mesclarConfig($existenteJson, array $nova, string $adapter): array
    {
        $existente = is_array($existenteJson) ? $existenteJson : (json_decode((string)$existenteJson, true) ?: []);
        $secretos = TransportadoraManager::camposSecretos($adapter);

        foreach ($nova as $k => $v) {
            $isSecret = in_array($k, $secretos, true);
            if ($isSecret && trim((string)$v) === '') {
                continue; // mantém o segredo já salvo
            }
            $existente[$k] = $v;
        }
        return $existente;
    }

    /** Substitui valores de segredos por máscara antes de exibir. */
    private function redigirConfig($configJson, string $adapter): array
    {
        $cfg = is_array($configJson) ? $configJson : (json_decode((string)$configJson, true) ?: []);
        foreach (TransportadoraManager::camposSecretos($adapter) as $campo) {
            if (!empty($cfg[$campo])) $cfg[$campo] = '';
        }
        return $cfg;
    }

    /** Quais segredos já estão preenchidos (para a UI mostrar "â€¢â€¢â€¢ preenchido"). */
    private function segredosPreenchidos($configJson, string $adapter): array
    {
        $cfg = is_array($configJson) ? $configJson : (json_decode((string)$configJson, true) ?: []);
        $out = [];
        foreach (TransportadoraManager::camposSecretos($adapter) as $campo) {
            $out[$campo] = !empty($cfg[$campo]);
        }
        return $out;
    }

    private function slug($base): string
    {
        $s = mb_strtolower(trim((string)$base));
        $s = strtr($s, ['á'=>'a','Ã '=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-') ?: ('transp-' . substr(md5((string)$base . microtime()), 0, 6));
    }

    private function nuloSeVazio($v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;
        return ($v === '' || $v === null) ? null : (string)$v;
    }

    /** Reindexa um array associativo com prefixo ":" nas chaves. */
    private function comColon(array $campos): array
    {
        $out = [];
        foreach ($campos as $k => $v) {
            $key = str_starts_with((string)$k, ':') ? $k : (':' . $k);
            $out[$key] = $v;
        }
        return $out;
    }
}
