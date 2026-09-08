<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/ia/IAIntencaoService.php
// ════════════════════════════════════════════════════════

/**
 * Intenção de compra por cliente — Fase 3.
 *
 * A DIVISÃO DE TRABALHO, que é o ponto todo:
 *   SQL  decide "o que ele mais olhou" — contagem ponderada por recência e
 *        tempo de página. Isso não é interpretação, é `GROUP BY`.
 *   IA   entra SÓ quando o sinal é ambíguo: categorias empatadas, ou termo de
 *        busca que não casa com o que foi visto.
 *
 * Rodar um LLM por cliente para dizer "ele viu capacete três vezes" seria caro
 * (uma chamada por pessoa da base) e menos confiável que a consulta. Com
 * 10 mil clientes, a diferença é entre centavos e dezenas de dólares por noite.
 *
 * LGPD
 *   Perfilar navegação para montar oferta é tratamento de dado pessoal. Só
 *   entra cliente com contato ATIVO em email_contatos e fora de
 *   email_supressoes — o consentimento que já existe. Quem descadastrou sai do
 *   perfilamento junto, que é o comportamento que a pessoa espera ao pedir
 *   para não receber mais.
 */
class IAIntencaoService
{
    private const TIPO = 'intencao_cliente';

    /** Janela de navegação considerada. Além disso o interesse envelheceu. */
    private const DIAS = 90;

    /** Abaixo disso não há sinal: não vale nem contar, muito menos perguntar. */
    private const MIN_EVENTOS = 3;

    /**
     * Se a categoria líder tem esta fração do peso total, o sinal é claro e a
     * SQL decide sozinha — sem chamada de IA. É o que segura o custo.
     */
    private const DOMINANCIA = 0.60;

    private IAOrchestrator $orq;
    private IACustoService $custo;
    private PDO            $db;

    public function __construct(?IAOrchestrator $orq = null)
    {
        $this->orq   = $orq ?? new IAOrchestrator();
        $this->custo = new IACustoService();
        $this->db    = Database::getInstance()->getConnection();
    }

    /* ══════════════════════════════════════════════════════
       1. Quem pode ser perfilado
       ══════════════════════════════════════════════════════ */

    /**
     * Clientes elegíveis: têm navegação na janela E consentimento vigente.
     *
     * O consentimento não é detalhe burocrático — é o que separa
     * personalização de vigilância. Quem pediu para sair sai do perfilamento
     * também, não só da lista de envio.
     *
     * @return int[] cliente_id
     */
    public function elegiveis(int $limite = 500): array
    {
        $sql = "SELECT DISTINCT h.cliente_id
                  FROM historico_navegacao h
            INNER JOIN email_contatos ec ON ec.cliente_id = h.cliente_id
                 WHERE h.cliente_id IS NOT NULL
                   AND h.criado_em >= DATE_SUB(NOW(), INTERVAL :dias DAY)
                   AND ec.status = 'ativo'
                   AND NOT EXISTS (
                        SELECT 1 FROM email_supressoes s WHERE s.email = ec.email
                   )
              GROUP BY h.cliente_id
                HAVING COUNT(*) >= :minimo
              ORDER BY MAX(h.criado_em) DESC
                 LIMIT {$limite}";

        try {
            $st = $this->db->prepare($sql);
            $st->execute([':dias' => self::DIAS, ':minimo' => self::MIN_EVENTOS]);
            return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            LogService::error('ia_intencao_elegiveis_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /* ══════════════════════════════════════════════════════
       2. Os sinais — SQL, não IA
       ══════════════════════════════════════════════════════ */

    /**
     * O que o cliente olhou, ponderado.
     *
     * Peso de cada visita = tempo de página (limitado a 3 min, para uma aba
     * esquecida aberta não valer mais que dez visitas reais) × fator de
     * recência (o que ele viu ontem diz mais que o de dois meses atrás).
     *
     * `historico_navegacao` guarda `tipo` + `referencia_id`, então categoria
     * de produto sai por JOIN — ver o produto conta para a categoria dele,
     * que é o que "interesse por categoria" significa na prática.
     *
     * @return array{eventos:int, categorias:array, marcas:array, produtos:array, buscas:array}
     */
    public function sinaisDe(int $clienteId): array
    {
        $vazio = ['eventos' => 0, 'categorias' => [], 'marcas' => [], 'produtos' => [], 'buscas' => []];
        if ($clienteId <= 0) { return $vazio; }

        // 1 + tempo/60 limitado a 3 min · recência: 7d ×3, 30d ×2, resto ×1
        $peso = '(1 + LEAST(COALESCE(h.tempo_pagina, 0), 180) / 60)
                 * CASE
                     WHEN h.criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)  THEN 3
                     WHEN h.criado_em >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 2
                     ELSE 1
                   END';

        $janela = 'h.cliente_id = :c AND h.criado_em >= DATE_SUB(NOW(), INTERVAL :dias DAY)';
        $args   = [':c' => $clienteId, ':dias' => self::DIAS];

        try {
            $eventos = (int) $this->um(
                "SELECT COUNT(*) FROM historico_navegacao h WHERE {$janela}", $args
            );
            if ($eventos < self::MIN_EVENTOS) { return $vazio; }

            // Categorias: página de categoria + categoria do produto visto
            $categorias = $this->linhas(
                "SELECT c.id, c.nome, ROUND(SUM({$peso}), 1) AS peso, COUNT(*) AS visitas
                   FROM historico_navegacao h
             INNER JOIN categorias c
                     ON (h.tipo = 'categoria' AND c.id = h.referencia_id)
                     OR (h.tipo = 'produto' AND c.id = (
                            SELECT p.categoria_id FROM produtos p WHERE p.id = h.referencia_id
                        ))
                  WHERE {$janela}
               GROUP BY c.id, c.nome
               ORDER BY peso DESC LIMIT 5", $args
            );

            $marcas = $this->linhas(
                "SELECT m.id, m.nome, ROUND(SUM({$peso}), 1) AS peso, COUNT(*) AS visitas
                   FROM historico_navegacao h
             INNER JOIN marcas m
                     ON (h.tipo = 'marca' AND m.id = h.referencia_id)
                     OR (h.tipo = 'produto' AND m.id = (
                            SELECT p.marca_id FROM produtos p WHERE p.id = h.referencia_id
                        ))
                  WHERE {$janela}
               GROUP BY m.id, m.nome
               ORDER BY peso DESC LIMIT 5", $args
            );

            $produtos = $this->linhas(
                "SELECT p.id, p.nome, ROUND(SUM({$peso}), 1) AS peso, COUNT(*) AS visitas
                   FROM historico_navegacao h
             INNER JOIN produtos p ON p.id = h.referencia_id
                  WHERE {$janela} AND h.tipo = 'produto'
               GROUP BY p.id, p.nome
               ORDER BY peso DESC LIMIT 8", $args
            );

            $buscas = $this->linhas(
                "SELECT h.termo_busca AS termo, COUNT(*) AS visitas
                   FROM historico_navegacao h
                  WHERE {$janela} AND h.tipo = 'busca'
                    AND h.termo_busca IS NOT NULL AND h.termo_busca <> ''
               GROUP BY h.termo_busca
               ORDER BY visitas DESC, MAX(h.criado_em) DESC LIMIT 6", $args
            );

            return compact('eventos', 'categorias', 'marcas', 'produtos', 'buscas');

        } catch (\Throwable $e) {
            LogService::error('ia_intencao_sinais_erro', ['cliente_id' => $clienteId, 'erro' => $e->getMessage()]);
            return $vazio;
        }
    }

    /**
     * O sinal é claro o bastante para a SQL decidir sozinha?
     *
     * Claro = uma categoria concentra a maior parte do peso E não há termo de
     * busca puxando para outro lado. Nesses casos chamar a IA é gastar para
     * confirmar o óbvio.
     */
    public function sinalClaro(array $sinais): bool
    {
        $cats = $sinais['categorias'] ?? [];
        if (count($cats) === 0) { return false; }
        if (count($cats) === 1) { return ($sinais['buscas'] ?? []) === []; }

        $total = array_sum(array_column($cats, 'peso'));
        if ($total <= 0) { return false; }

        $lider = (float) $cats[0]['peso'] / $total;

        // Busca é justamente onde a leitura importa: "capacete infantil" com
        // histórico de capacete adulto muda o sentido do perfil inteiro.
        return $lider >= self::DOMINANCIA && ($sinais['buscas'] ?? []) === [];
    }

    /** Rótulo direto da contagem, sem IA. */
    public function rotuloPorSql(array $sinais): array
    {
        $cat = $sinais['categorias'][0] ?? null;
        if ($cat === null) {
            return ['segmento' => 'sem-sinal', 'resumo' => 'Navegação insuficiente para inferir interesse.', 'confianca' => 'baixa'];
        }

        $marca = $sinais['marcas'][0]['nome'] ?? '';
        $slug  = $this->slug((string) $cat['nome']);

        return [
            'segmento'  => mb_substr($slug, 0, 40),
            'resumo'    => 'Concentrou a navegação em ' . $cat['nome']
                         . ($marca !== '' ? ', com preferência por ' . $marca : '') . '.',
            'confianca' => count($sinais['categorias']) === 1 ? 'alta' : 'media',
        ];
    }

    /* ══════════════════════════════════════════════════════
       3. A IA — só no caso ambíguo
       ══════════════════════════════════════════════════════ */

    /**
     * Pede à IA o rótulo, quando o sinal não fala por si.
     *
     * @return array{segmento:string, resumo:string, confianca:string, geracao_id:int}
     * @throws RuntimeException
     */
    public function inferirComIa(int $clienteId, array $sinais, ?int $usuarioId = null): array
    {
        $tipoRow = (new IATipoConteudo())->buscarPorCodigo(self::TIPO);
        if ($tipoRow === null || (int) $tipoRow['ativo'] !== 1) {
            throw new \RuntimeException('Tipo intencao_cliente ausente — rode sql/ia/2026-09-08_ia_intencao_cliente.sql.');
        }

        $prompt   = $this->montarPrompt($sinais);
        $custoEst = $this->custo->estimarTexto(
            $this->custo->custoConfigPrimarioTexto(),
            mb_strlen($prompt) + mb_strlen((string) $tipoRow['instrucoes_sistema']),
            (int) $tipoRow['max_tokens']
        );

        // Sem usuário: o lote roda no cron, sem ninguém logado. Só os tetos
        // globais valem — o mesmo desenho da Q&A de produto.
        $chk = $this->custo->podeGerar((int) $usuarioId, $custoEst, 1);
        if (!$chk['ok']) {
            throw new \RuntimeException($chk['msg']);
        }

        $uuid = $this->uuidV4();
        $id   = (new IAGeracao())->criar([
            'uuid'                     => $uuid,
            'usuario_id'               => $usuarioId,   // null = lote do sistema
            'produto_id'               => null,
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
                'cliente_id' => $clienteId,
                'eventos'    => $sinais['eventos'] ?? 0,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'chave_dedup'              => hash('sha256', uniqid('intencao|' . $clienteId . '|', true)),
            'custo_estimado_usd'       => $custoEst,
            'status'                   => 'processando',
        ]);

        if ($id <= 0) {
            throw new \RuntimeException('Não foi possível registrar a geração da intenção.');
        }

        $geracao = [
            'id' => $id, 'uuid' => $uuid, 'usuario_id' => $usuarioId,
            'capacidade' => 'texto', 'prompt_final' => $prompt, 'custo_estimado_usd' => $custoEst,
        ];
        $tipoArr = [
            'instrucoes_sistema' => $tipoRow['instrucoes_sistema'],
            'max_tokens'         => (int) $tipoRow['max_tokens'],
            'modelo_id'          => $tipoRow['modelo_id'],
            'nome'               => $tipoRow['nome'],
            'saida'              => $tipoRow['saida'] ?? 'json',
        ];

        $servico = new IAGeracaoService();

        // Orquestrador que lança deixaria a linha presa em 'processando' e o
        // watchdog a devolveria à fila — num lote noturno isso vira o worker
        // reprocessando clientes que ninguém está esperando.
        try {
            $r = $this->orq->executarTexto($geracao, $tipoArr);
        } catch (\Throwable $e) {
            $servico->falhar($geracao, IAResultado::falha('excecao', mb_substr($e->getMessage(), 0, 500), false));
            throw $e;
        }

        if (!$r->ok) {
            $servico->falhar($geracao, $r);
            throw new \RuntimeException('Intenção: ' . ($r->erro ?: 'geração falhou.'));
        }

        $dados = $this->decodificarJsonTolerante((string) $r->texto);
        if ($dados === null || empty($dados['segmento'])) {
            $servico->falhar($geracao, IAResultado::falha('json_invalido', 'Sem segmento no retorno.', false));
            throw new \RuntimeException('O provedor não devolveu um segmento.');
        }

        $servico->concluir($geracao, $r);

        $conf = strtolower(trim((string) ($dados['confianca'] ?? 'media')));
        return [
            'segmento'   => mb_substr($this->slug((string) $dados['segmento']), 0, 40),
            'resumo'     => mb_substr(trim((string) ($dados['resumo'] ?? '')), 0, 500),
            // Whitelist: valor fora do ENUM abortaria o INSERT inteiro.
            'confianca'  => in_array($conf, ['alta', 'media', 'baixa'], true) ? $conf : 'media',
            'geracao_id' => $id,
        ];
    }

    /* ══════════════════════════════════════════════════════
       4. O ciclo completo
       ══════════════════════════════════════════════════════ */

    /**
     * Calcula e grava o perfil de um cliente.
     *
     * NUNCA lança: o lote noturno não pode parar por causa de um cliente.
     *
     * @return array{ok:bool, cliente_id:int, segmento?:string, origem?:string, msg?:string}
     */
    public function recalcular(int $clienteId, ?int $usuarioId = null): array
    {
        $sinais = $this->sinaisDe($clienteId);

        if (($sinais['eventos'] ?? 0) < self::MIN_EVENTOS) {
            return ['ok' => false, 'cliente_id' => $clienteId, 'msg' => 'sinal insuficiente'];
        }

        $geracaoId = null;
        $origem    = 'sql';

        if ($this->sinalClaro($sinais)) {
            $r = $this->rotuloPorSql($sinais);
        } else {
            try {
                $r         = $this->inferirComIa($clienteId, $sinais, $usuarioId);
                $geracaoId = $r['geracao_id'];
                $origem    = 'ia';
            } catch (\Throwable $e) {
                // IA indisponível ou teto atingido não pode zerar o perfil:
                // a contagem sozinha ainda é melhor que nada.
                LogService::warning('ia_intencao_fallback_sql', [
                    'cliente_id' => $clienteId, 'erro' => $e->getMessage(),
                ]);
                $r = $this->rotuloPorSql($sinais);
                $r['confianca'] = 'baixa';
            }
        }

        $gravou = $this->gravar($clienteId, $r, $sinais, $origem, $geracaoId);

        return $gravou
            ? ['ok' => true, 'cliente_id' => $clienteId, 'segmento' => $r['segmento'], 'origem' => $origem]
            : ['ok' => false, 'cliente_id' => $clienteId, 'msg' => 'falha ao gravar'];
    }

    private function gravar(int $clienteId, array $r, array $sinais, string $origem, ?int $geracaoId): bool
    {
        try {
            $st = $this->db->prepare(
                'INSERT INTO ia_cliente_intencao
                    (cliente_id, segmento, resumo, confianca, origem, sinais_json, eventos, geracao_id, calculado_em)
                 VALUES (:c, :s, :r, :cf, :o, :sj, :e, :g, NOW()) AS novo
                 ON DUPLICATE KEY UPDATE
                    segmento = novo.segmento, resumo = novo.resumo, confianca = novo.confianca,
                    origem = novo.origem, sinais_json = novo.sinais_json, eventos = novo.eventos,
                    geracao_id = novo.geracao_id, calculado_em = NOW()'
            );
            $st->execute([
                ':c'  => $clienteId,
                ':s'  => $r['segmento'],
                ':r'  => $r['resumo'] ?: null,
                ':cf' => $r['confianca'],
                ':o'  => $origem,
                ':sj' => json_encode($sinais, JSON_UNESCAPED_UNICODE),
                ':e'  => (int) ($sinais['eventos'] ?? 0),
                ':g'  => $geracaoId,
            ]);
            return true;
        } catch (\Throwable $e) {
            LogService::error('ia_intencao_gravar_erro', ['cliente_id' => $clienteId, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Roda o lote. Devolve o resumo para o CLI imprimir.
     *
     * @return array{elegiveis:int, calculados:int, por_sql:int, por_ia:int, pulados:int}
     */
    public function lote(int $limite = 500, ?int $usuarioId = null): array
    {
        $ids = $this->elegiveis($limite);
        $r   = ['elegiveis' => count($ids), 'calculados' => 0, 'por_sql' => 0, 'por_ia' => 0, 'pulados' => 0];

        foreach ($ids as $id) {
            $res = $this->recalcular($id, $usuarioId);
            if (empty($res['ok'])) { $r['pulados']++; continue; }
            $r['calculados']++;
            $r[($res['origem'] ?? 'sql') === 'ia' ? 'por_ia' : 'por_sql']++;
        }

        return $r;
    }

    /* ══════════════════════════════════════════════════════
       5. Leitura
       ══════════════════════════════════════════════════════ */

    public function perfil(int $clienteId): ?array
    {
        try {
            $st = $this->db->prepare(
                'SELECT i.*, u.nome AS cliente_nome, u.email AS cliente_email
                   FROM ia_cliente_intencao i
              LEFT JOIN clientes c  ON c.id = i.cliente_id
              LEFT JOIN usuarios u  ON u.id = c.usuario_id
                  WHERE i.cliente_id = :c LIMIT 1'
            );
            $st->execute([':c' => $clienteId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) { return null; }
            $r['sinais'] = json_decode((string) $r['sinais_json'], true) ?: [];
            return $r;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Perfis calculados, do mais recente para o mais antigo. */
    public function listar(int $limite = 100): array
    {
        try {
            $st = $this->db->prepare(
                'SELECT i.cliente_id, i.segmento, i.resumo, i.confianca, i.origem,
                        i.eventos, i.calculado_em, u.nome AS cliente_nome
                   FROM ia_cliente_intencao i
              LEFT JOIN clientes c ON c.id = i.cliente_id
              LEFT JOIN usuarios u ON u.id = c.usuario_id
               ORDER BY i.calculado_em DESC LIMIT ' . max(1, min(500, $limite))
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Segmentos existentes, com quantos clientes cada um — para a campanha. */
    public function segmentos(): array
    {
        try {
            return $this->db->query(
                'SELECT segmento, COUNT(*) AS clientes,
                        SUM(confianca = \'alta\') AS alta
                   FROM ia_cliente_intencao
               GROUP BY segmento ORDER BY clientes DESC, segmento'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ══════════════════════════════════════════════════════
       Apoio
       ══════════════════════════════════════════════════════ */

    private function montarPrompt(array $s): string
    {
        $p = "SINAIS DE NAVEGACAO (ja contados e ponderados)\n\n";

        $p .= "Categorias mais vistas:\n";
        foreach ($s['categorias'] as $c) { $p .= "- {$c['nome']} (peso {$c['peso']}, {$c['visitas']} visitas)\n"; }
        if ($s['categorias'] === []) { $p .= "- nenhuma\n"; }

        $p .= "\nMarcas mais vistas:\n";
        foreach ($s['marcas'] as $m) { $p .= "- {$m['nome']} (peso {$m['peso']})\n"; }
        if ($s['marcas'] === []) { $p .= "- nenhuma\n"; }

        $p .= "\nProdutos abertos:\n";
        foreach (array_slice($s['produtos'], 0, 6) as $pr) { $p .= "- {$pr['nome']}\n"; }
        if ($s['produtos'] === []) { $p .= "- nenhum\n"; }

        if ($s['buscas'] !== []) {
            $p .= "\nTermos buscados:\n";
            foreach ($s['buscas'] as $b) { $p .= "- \"{$b['termo']}\" ({$b['visitas']}x)\n"; }
        }

        $p .= "\nTotal de eventos na janela: {$s['eventos']}\n\nResponda somente com o JSON.";
        return $p;
    }

    /** "Capacetes Fechados" → "capacetes-fechados" */
    private function slug(string $t): string
    {
        $t = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
        $t = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $t) ?? '');
        return trim($t, '-') ?: 'sem-sinal';
    }

    private function um(string $sql, array $args)
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchColumn();
    }

    private function linhas(string $sql, array $args): array
    {
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function decodificarJsonTolerante(string $texto): ?array
    {
        $texto = trim($texto);
        if (strpos($texto, '```') === 0) {
            $texto = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $texto);
        }
        $dec = json_decode((string) $texto, true);
        return is_array($dec) ? $dec : null;
    }

    private function uuidV4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
