<?php
/**
 * IAModelo — acesso a ia_modelos (catálogo de modelos por capacidade).
 */
class IAModelo
{
    private PDO $db;

    /** Campos permitidos em criar()/atualizar() — allowlist. */
    private const CAMPOS_EDITAVEIS = [
        'provedor_id', 'capacidade', 'codigo_modelo', 'nome',
        'prioridade', 'ativo', 'custo_config', 'params_padrao', 'timeout_s',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Capacidades suportadas (chave => rótulo). Fonte única para validação e UI. */
    public static function capacidades(): array
    {
        return [
            'texto'         => 'Texto',
            'imagem'        => 'Imagem',
            'imagem_edicao' => 'Edição de imagem',
            'remocao_fundo' => 'Remoção de fundo',
            'upscale'       => 'Upscale',
            'video'         => 'Vídeo',
            'narracao'      => 'Narração',
            'transcricao'   => 'Transcrição',
        ];
    }

    /** Lista o catálogo com dados do provedor, ordenado para leitura de roteamento. */
    public function listar(): array
    {
        try {
            $sql = "SELECT m.id, m.provedor_id, m.capacidade, m.codigo_modelo, m.nome,
                           m.prioridade, m.ativo, m.custo_config, m.params_padrao, m.timeout_s,
                           m.tempo_medio_ms, m.total_execucoes, m.total_falhas,
                           p.nome AS provedor_nome, p.codigo AS provedor_codigo, p.ativo AS provedor_ativo
                      FROM ia_modelos m
                INNER JOIN ia_provedores p ON p.id = m.provedor_id
                  ORDER BY m.capacidade ASC, m.prioridade ASC, m.nome ASC";

            $linhas = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return $linhas ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_modelo_listar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    public function buscar(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT m.*, p.nome AS provedor_nome, p.codigo AS provedor_codigo
                   FROM ia_modelos m
             INNER JOIN ia_provedores p ON p.id = m.provedor_id
                  WHERE m.id = :id
                  LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_modelo_buscar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Cria um modelo. Retorna o id novo ou 0 em falha. */
    public function criar(array $dados): int
    {
        $campos = [];
        $marcas = [];
        $params = [];

        foreach (self::CAMPOS_EDITAVEIS as $campo) {
            if (array_key_exists($campo, $dados)) {
                $campos[] = "`{$campo}`";
                $marcas[] = ":{$campo}";
                $params[":{$campo}"] = $dados[$campo];
            }
        }

        if (empty($campos)) {
            return 0;
        }

        try {
            $sql  = 'INSERT INTO ia_modelos (' . implode(', ', $campos) . ') VALUES (' . implode(', ', $marcas) . ')';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            LogService::error('ia_modelo_criar_erro', ['erro' => $e->getMessage()]);
            return 0;
        }
    }

    /** Atualiza campos permitidos (allowlist). */
    public function atualizar(int $id, array $dados): bool
    {
        $set    = [];
        $params = [':id' => $id];

        foreach (self::CAMPOS_EDITAVEIS as $campo) {
            if (array_key_exists($campo, $dados)) {
                $set[] = "`{$campo}` = :{$campo}";
                $params[":{$campo}"] = $dados[$campo];
            }
        }

        if (empty($set)) {
            return true;
        }

        try {
            $sql  = 'UPDATE ia_modelos SET ' . implode(', ', $set) . ' WHERE id = :id LIMIT 1';
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (Throwable $e) {
            LogService::error('ia_modelo_atualizar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Exclui um modelo apenas se nunca foi executado (histórico preservado
     * pelos campos snapshot em ia_geracoes; ainda assim, com uso, apenas desative).
     */
    public function excluir(int $id): array
    {
        $modelo = $this->buscar($id);
        if ($modelo === null) {
            return ['ok' => false, 'msg' => 'Modelo não encontrado.'];
        }

        if ((int) $modelo['total_execucoes'] > 0) {
            return ['ok' => false, 'msg' => 'Este modelo já foi utilizado — desative em vez de excluir.'];
        }

        try {
            $stmt = $this->db->prepare('DELETE FROM ia_modelos WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            return ['ok' => true, 'msg' => 'Modelo excluído.'];
        } catch (Throwable $e) {
            LogService::error('ia_modelo_excluir_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro ao excluir o modelo.'];
        }
    }

    /** Resumo humanizado do custo_config para exibição em tabela. */
    public static function resumoCusto(?string $json): string
    {
        if ($json === null || trim($json) === '') {
            return '—';
        }

        $cfg = json_decode($json, true);
        if (!is_array($cfg) || empty($cfg['tipo'])) {
            return '—';
        }

        switch ($cfg['tipo']) {
            case 'por_token':
                $in  = self::fmtUsd((float) ($cfg['usd_in_1m'] ?? 0));
                $out = self::fmtUsd((float) ($cfg['usd_out_1m'] ?? 0));
                return "US$ {$in} / {$out} · 1M tokens";

            case 'por_imagem':
                return 'US$ ' . self::fmtUsd((float) ($cfg['usd_imagem'] ?? 0)) . ' / imagem';

            case 'por_execucao':
                return 'US$ ' . self::fmtUsd((float) ($cfg['usd_execucao'] ?? 0)) . ' / execução';

            default:
                return '—';
        }
    }

    /** Formata USD com 2 a 4 casas, sem zeros à direita desnecessários. */
    private static function fmtUsd(float $valor): string
    {
        $texto = number_format($valor, 4, ',', '.');
        $texto = rtrim($texto, '0');
        $texto = rtrim($texto, ',');

        if (strpos($texto, ',') === false) {
            return $texto . ',00';
        }

        $decimais = strlen(substr($texto, strpos($texto, ',') + 1));
        return ($decimais === 1) ? $texto . '0' : $texto;
    }
}
