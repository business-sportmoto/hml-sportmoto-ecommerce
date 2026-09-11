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
                           p.nome AS provedor_nome, p.codigo AS provedor_codigo, p.ativo AS provedor_ativo,
                           (p.api_key_enc IS NOT NULL) AS provedor_tem_chave
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

    /* ------------------------------------------------------------------ */
    /* params_padrao — config por modelo                                   */
    /* ------------------------------------------------------------------ */

    /**
     * O que cada capacidade aceita quando o modelo não declara nada.
     * São os valores que estavam cravados no código antes desta camada.
     */
    private const PROPORCOES_PADRAO = ['1:1', '3:2', '2:3'];

    /**
     * `params_padrao` tem duas naturezas misturadas no mesmo JSON: parâmetros
     * que vão CRUS para o payload do provedor (temperature, output_format…) e
     * metadados que só interessam a este projeto. Os metadados vivem num bloco
     * reservado `ia` para não vazarem para a API — antes o `ref_param` ficava
     * solto no topo e o adapter tinha de lembrar de dar unset nele.
     *
     *   {"ia": {"proporcoes": ["1:1","9:16"], "aceita_referencia": true,
     *           "ref_param": "input_images"},
     *    "output_format": "webp"}
     *
     * Chaves do bloco `ia`:
     *  - proporcoes        (string[]) proporções que o modelo aceita
     *  - aceita_referencia (bool)     aceita imagem de entrada como referência
     *  - ref_param         (string)   nome do input onde a referência vai
     */
    public static function meta(?string $json): array
    {
        $bruto = self::decodificar($json);
        $ia    = is_array($bruto['ia'] ?? null) ? $bruto['ia'] : [];

        $proporcoes = [];
        foreach ((array) ($ia['proporcoes'] ?? []) as $p) {
            $p = trim((string) $p);
            // Formato L:A, só dígitos — não deixa entrar lixo no payload.
            if ($p !== '' && preg_match('/^\d{1,2}:\d{1,2}$/', $p)) {
                $proporcoes[] = $p;
            }
        }

        return [
            'proporcoes'        => $proporcoes !== [] ? array_values(array_unique($proporcoes)) : self::PROPORCOES_PADRAO,
            'declara_proporcoes' => $proporcoes !== [],
            'aceita_referencia' => !empty($ia['aceita_referencia']),
            'ref_param'         => is_string($ia['ref_param'] ?? null) && $ia['ref_param'] !== ''
                                       ? $ia['ref_param'] : 'input_images',
        ];
    }

    /** Só o que deve seguir para o payload do provedor (sem o bloco `ia`). */
    public static function paramsApi(?string $json): array
    {
        $bruto = self::decodificar($json);
        unset($bruto['ia']);
        return $bruto;
    }

    /**
     * Modelo primário (menor prioridade) ativo da capacidade, com provedor
     * ativo e chave configurada — o mesmo critério do orquestrador. É ele que
     * a tela usa para saber quais proporções oferecer.
     */
    public function primarioDaCapacidade(string $capacidade): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.id, m.codigo_modelo, m.nome, m.capacidade, m.params_padrao, m.custo_config
                   FROM ia_modelos m
             INNER JOIN ia_provedores p
                     ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                  WHERE m.capacidade = :cap AND m.ativo = 1
               ORDER BY m.prioridade ASC, m.id ASC
                  LIMIT 1"
            );
            $stmt->execute([':cap' => $capacidade]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_modelo_primario_erro', ['capacidade' => $capacidade, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Modelos que a tela de gerar oferece para escolha: ativos, de provedor
     * ativo e com chave — o MESMO critério do orquestrador, para a tela nunca
     * oferecer uma IA que a fila pularia. Na ordem da fila.
     */
    public function paraEscolha(array $capacidades): array
    {
        $capacidades = array_values(array_intersect($capacidades, array_keys(self::capacidades())));
        if ($capacidades === []) {
            return [];
        }
        try {
            $marc = implode(',', array_fill(0, count($capacidades), '?'));
            $stmt = $this->db->prepare(
                "SELECT m.id, m.capacidade, m.codigo_modelo, m.nome, m.prioridade, m.custo_config,
                        m.params_padrao, m.tempo_medio_ms, m.total_execucoes, m.total_falhas,
                        p.codigo AS provedor_codigo, p.nome AS provedor_nome
                   FROM ia_modelos m
             INNER JOIN ia_provedores p
                     ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                  WHERE m.ativo = 1 AND m.capacidade IN ({$marc})
               ORDER BY m.capacidade ASC, m.prioridade ASC, m.id ASC"
            );
            $stmt->execute($capacidades);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_modelo_escolha_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * O modelo pode rodar esta capacidade agora? Devolve a linha ou null. O id
     * vem do navegador: ativo, provedor ativo com chave, mesma capacidade.
     */
    public function usavel(int $id, string $capacidade): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT m.* FROM ia_modelos m
             INNER JOIN ia_provedores p
                     ON p.id = m.provedor_id AND p.ativo = 1 AND p.api_key_enc IS NOT NULL
                  WHERE m.id = :id AND m.capacidade = :cap AND m.ativo = 1
                  LIMIT 1"
            );
            $stmt->execute([':id' => $id, ':cap' => $capacidade]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_modelo_usavel_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Imagem conceitual cadastrada para o modelo (params_padrao.ia.imagem).
     * Só https: o endereço vai para um <img> no painel. Sem ela, a tela
     * desenha a arte a partir do fabricante e da capacidade.
     */
    public static function imagemConceito(?string $json): ?string
    {
        $ia  = self::decodificar($json)['ia'] ?? null;
        $url = is_array($ia) ? ($ia['imagem'] ?? null) : null;
        return (is_string($url) && preg_match('#^https://[^\s"\'<>]+$#i', $url)) ? $url : null;
    }

    /** Proporções a oferecer para uma capacidade (cai no padrão sem modelo ativo). */
    public function proporcoesDaCapacidade(string $capacidade): array
    {
        $primario = $this->primarioDaCapacidade($capacidade);
        return self::meta($primario['params_padrao'] ?? null)['proporcoes'];
    }

    /**
     * Metadados de VÍDEO do bloco `ia` — o que o modelo aceita de fato,
     * tirado do esquema de entrada de cada um na API do Replicate:
     *
     *   {"ia": {"proporcoes": ["9:16","16:9"], "duracoes": [4,6,8],
     *           "resolucoes": ["720p"], "img_param": "image",
     *           "resolucao_param": "resolution", "aceita_referencia": true,
     *           "audio": true}}
     *
     * resolucao_param null = o modelo não tem campo de resolução (Kling:
     * vem do `mode`). Sem declaração, cai num conjunto mínimo seguro.
     */
    public static function metaVideo(?string $json): array
    {
        $ia = self::decodificar($json)['ia'] ?? [];
        $ia = is_array($ia) ? $ia : [];

        $proporcoes = array_values(array_filter(array_map('strval', (array) ($ia['proporcoes'] ?? [])),
            fn($p) => (bool) preg_match('/^\d{1,2}:\d{1,2}$/', $p)));

        $duracoes = array_values(array_unique(array_filter(array_map('intval', (array) ($ia['duracoes'] ?? [])),
            fn($d) => $d >= 1 && $d <= 60)));
        sort($duracoes);

        $resolucoes = array_values(array_filter(array_map('strval', (array) ($ia['resolucoes'] ?? [])),
            fn($r) => (bool) preg_match('/^(\d{3,4}p|4k)$/', $r)));

        $resParam = array_key_exists('resolucao_param', $ia) ? $ia['resolucao_param'] : 'resolution';

        return [
            'proporcoes'        => $proporcoes !== [] ? $proporcoes : ['16:9'],
            'duracoes'          => $duracoes !== [] ? $duracoes : [5],
            'resolucoes'        => $resolucoes !== [] ? $resolucoes : ['720p'],
            'img_param'         => (is_string($ia['img_param'] ?? null) && $ia['img_param'] !== '') ? $ia['img_param'] : 'image',
            'resolucao_param'   => (is_string($resParam) && $resParam !== '') ? $resParam : null,
            'aceita_referencia' => !empty($ia['aceita_referencia']),
            'audio'             => !empty($ia['audio']),
        ];
    }

    /**
     * Ajusta o pedido ao que o MODELO aceita. Fonte única da regra — usada
     * no enfileiramento (estimativa) e no orquestrador (payload), para os
     * dois nunca discordarem. Pedido fora da lista não vira HTTP 422:
     *  - duração: a maior aceita que não passa do pedido; se nenhuma cabe,
     *    a menor aceita. Nunca cobra tempo a mais quando existe opção menor;
     *  - resolução e proporção: a pedida se aceita, senão a primeira.
     */
    public static function ajustarVideo(array $meta, int $duracao, string $resolucao, string $proporcao): array
    {
        $cabem = array_filter($meta['duracoes'], fn($d) => $d <= $duracao);

        return [
            'duracao'   => (int) ($cabem !== [] ? max($cabem) : min($meta['duracoes'])),
            'resolucao' => in_array($resolucao, $meta['resolucoes'], true) ? $resolucao : (string) reset($meta['resolucoes']),
            'proporcao' => in_array($proporcao, $meta['proporcoes'], true) ? $proporcao : (string) reset($meta['proporcoes']),
        ];
    }

    /**
     * O que a tela de gerar oferece para vídeo: opções e preço por segundo
     * do modelo PRIMÁRIO — o custo aparece antes do clique. null quando
     * não há modelo de vídeo ativo com provedor configurado.
     */
    public function opcoesVideo(): ?array
    {
        $p = $this->primarioDaCapacidade('video');
        if ($p === null) {
            return null;
        }
        $cfg = self::decodificar($p['custo_config'] ?? null);

        return [
            'modelo'                => (string) $p['codigo_modelo'],
            'nome'                  => (string) $p['nome'],
            'meta'                  => self::metaVideo($p['params_padrao'] ?? null),
            'usd_segundo'           => is_array($cfg['usd_segundo'] ?? null) ? $cfg['usd_segundo'] : [],
            'usd_segundo_sem_audio' => is_array($cfg['usd_segundo_sem_audio'] ?? null) ? $cfg['usd_segundo_sem_audio'] : [],
        ];
    }

    private static function decodificar(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $dec = json_decode($json, true);
        return is_array($dec) ? $dec : [];
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

            case 'por_segundo':
                $precos = is_array($cfg['usd_segundo'] ?? null) ? $cfg['usd_segundo'] : [];
                if ($precos === []) {
                    return 'sem preço cadastrado';
                }
                $partes = [];
                foreach ($precos as $res => $usd) {
                    $partes[] = 'US$ ' . self::fmtUsd((float) $usd) . ' (' . $res . ')';
                }
                return implode(' · ', $partes) . ' / s';

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
