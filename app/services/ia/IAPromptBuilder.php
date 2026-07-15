<?php
/**
 * IAPromptBuilder — monta o contexto do produto e o prompt final.
 *
 * Consome as tabelas reais da SportMoto:
 *  produtos, marcas, categorias (via produto_categorias.principal),
 *  caracteristicas + produto_caracteristicas, produto_compatibilidade
 *  + moto_montadoras + moto_modelos, produto_review_summary, avaliacoes,
 *  produto_imagens.
 *
 * O contexto vira snapshot JSON em ia_geracoes.contexto — o que o modelo viu
 * fica auditável mesmo que o produto mude depois.
 */
class IAPromptBuilder
{
    private PDO $db;

    private const MAX_CARACTERISTICAS = 14;
    private const MAX_COMPATIBILIDADES = 12;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /* ------------------------------------------------------------------ */
    /* Contexto                                                            */
    /* ------------------------------------------------------------------ */

    /** Snapshot completo do produto para o prompt. Null se produto inexistente/inativo. */
    public function montarContexto(int $produtoId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT p.id, p.nome, p.slug, p.descricao_curta, p.preco, p.preco_promo,
                        p.promo_inicio, p.promo_fim, p.estoque_total, p.lancamento,
                        p.destaque, p.vendidos, p.tem_variacao,
                        m.nome AS marca,
                        COALESCE(cat_pc.nome, cat_dir.nome) AS categoria
                   FROM produtos p
              LEFT JOIN marcas m ON m.id = p.marca_id
              LEFT JOIN produto_categorias pc ON pc.produto_id = p.id AND pc.principal = 1
              LEFT JOIN categorias cat_pc ON cat_pc.id = pc.categoria_id
              LEFT JOIN categorias cat_dir ON cat_dir.id = p.categoria_id
                  WHERE p.id = :id AND p.deleted_at IS NULL
                  LIMIT 1"
            );
            $stmt->execute([':id' => $produtoId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            LogService::error('ia_ctx_produto_erro', ['produto_id' => $produtoId, 'erro' => $e->getMessage()]);
            return null;
        }

        if (!$p) {
            return null;
        }

        $promoVigente = $this->promoVigente($p);

        return [
            'produto_id'      => (int) $p['id'],
            'nome'            => (string) $p['nome'],
            'marca'           => $p['marca'] !== null ? (string) $p['marca'] : null,
            'categoria'       => $p['categoria'] !== null ? (string) $p['categoria'] : null,
            'preco'           => (float) $p['preco'],
            'preco_promo'     => $promoVigente ? (float) $p['preco_promo'] : null,
            'promo_fim'       => $promoVigente ? (string) $p['promo_fim'] : null,
            'estoque_total'   => (int) $p['estoque_total'],
            'lancamento'      => ((int) $p['lancamento'] === 1),
            'destaque'        => ((int) $p['destaque'] === 1),
            'vendidos'        => $p['vendidos'] !== null ? (int) $p['vendidos'] : null,
            'descricao_curta' => $this->limparTexto($p['descricao_curta'], 400),
            'caracteristicas' => $this->caracteristicas($produtoId),
            'compatibilidade' => $this->compatibilidades($produtoId),
            'avaliacoes'      => $this->avaliacoes($produtoId),
            'imagem_principal'=> $this->imagemPrincipal($produtoId),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Prompt final                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Monta o prompt (mensagem do usuário) a partir de contexto + ângulo + briefing.
     * As instruções de FORMATO ficam no system prompt do tipo (ia_tipos_conteudo).
     */
    public function montarPrompt(array $contexto, array $tipo, ?array $template, array $briefing): string
    {
        $blocos = [];

        $blocos[] = 'TAREFA: gerar "' . $tipo['nome'] . '" para o produto abaixo, em português do Brasil.';

        if ($template !== null && trim((string) $template['corpo']) !== '') {
            $blocos[] = "ABORDAGEM CRIATIVA (" . $template['nome'] . "):\n" . trim((string) $template['corpo']);
        }

        $blocos[] = "DADOS DO PRODUTO:\n" . $this->contextoComoTexto($contexto);

        $linhasBriefing = [];
        foreach (['objetivo' => 'Objetivo', 'publico' => 'Público-alvo', 'tom' => 'Tom de comunicação', 'condicao' => 'Condição especial'] as $chave => $rotulo) {
            $valor = trim((string) ($briefing[$chave] ?? ''));
            if ($valor !== '') {
                $linhasBriefing[] = "- {$rotulo}: {$valor}";
            }
        }
        if (!empty($linhasBriefing)) {
            $blocos[] = "BRIEFING:\n" . implode("\n", $linhasBriefing);
        }

        $blocos[] = 'REGRAS: use somente os dados acima; não invente preço, prazo, estoque ou especificações. Responda apenas com o conteúdo final, sem comentários.';

        $prompt = implode("\n\n", $blocos);

        return $this->substituirPlaceholders($prompt, $contexto);
    }

    /** Suporte a {{placeholders}} em templates editados pelo usuário. */
    public function substituirPlaceholders(string $texto, array $contexto): string
    {
        $mapa = [
            '{{produto_nome}}' => (string) ($contexto['nome'] ?? ''),
            '{{marca}}'        => (string) ($contexto['marca'] ?? ''),
            '{{categoria}}'    => (string) ($contexto['categoria'] ?? ''),
            '{{preco}}'        => $this->brl($contexto['preco'] ?? null),
            '{{preco_promo}}'  => $this->brl($contexto['preco_promo'] ?? null),
            '{{estoque}}'      => (string) ($contexto['estoque_total'] ?? ''),
        ];
        return strtr($texto, $mapa);
    }

    /** Bloco de texto legível do contexto (o que o modelo recebe). */
    public function contextoComoTexto(array $c): string
    {
        $l = [];
        $l[] = '- Produto: ' . $c['nome'];
        if (!empty($c['marca']))     { $l[] = '- Marca: ' . $c['marca']; }
        if (!empty($c['categoria'])) { $l[] = '- Categoria: ' . $c['categoria']; }

        if ($c['preco_promo'] !== null) {
            $linha = '- Preço: de ' . $this->brl($c['preco']) . ' por ' . $this->brl($c['preco_promo']);
            if (!empty($c['promo_fim'])) {
                $linha .= ' (promoção válida até ' . $this->dataBr($c['promo_fim']) . ')';
            }
            $l[] = $linha;
        } else {
            $l[] = '- Preço: ' . $this->brl($c['preco']);
        }

        $l[] = '- Estoque disponível: ' . (int) $c['estoque_total'] . ' unidades';
        if (!empty($c['lancamento'])) { $l[] = '- Situação: LANÇAMENTO'; }
        if (!empty($c['vendidos']) && (int) $c['vendidos'] >= 10) {
            $l[] = '- Prova social: mais de ' . (int) $c['vendidos'] . ' unidades vendidas';
        }
        if (!empty($c['descricao_curta'])) { $l[] = '- Resumo: ' . $c['descricao_curta']; }

        if (!empty($c['caracteristicas'])) {
            $itens = array_map(
                fn($x) => $x['nome'] . ': ' . $x['valor'] . ($x['unidade'] ? ' ' . $x['unidade'] : ''),
                $c['caracteristicas']
            );
            $l[] = '- Características: ' . implode('; ', $itens);
        }

        if (!empty($c['compatibilidade'])) {
            $l[] = '- Compatível com: ' . implode('; ', $c['compatibilidade']);
        }

        $av = $c['avaliacoes'] ?? null;
        if (is_array($av) && !empty($av['total'])) {
            $linha = '- Avaliações: nota média ' . number_format((float) $av['media'], 1, ',', '') .
                     ' de 5 (' . (int) $av['total'] . ' avaliações aprovadas)';
            $l[] = $linha;
            if (!empty($av['resumo'])) {
                $l[] = '- O que os clientes dizem: ' . $av['resumo'];
            }
        }

        return implode("\n", $l);
    }

    /* ------------------------------------------------------------------ */
    /* Consultas auxiliares                                                */
    /* ------------------------------------------------------------------ */

    private function caracteristicas(int $produtoId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT c.nome, c.unidade, pc.valor
                   FROM produto_caracteristicas pc
             INNER JOIN caracteristicas c ON c.id = pc.caracteristica_id AND c.ativo = 1
                  WHERE pc.produto_id = :id AND pc.valor IS NOT NULL AND pc.valor <> ''
               ORDER BY c.ordem ASC, c.nome ASC
                  LIMIT " . self::MAX_CARACTERISTICAS
            );
            $stmt->execute([':id' => $produtoId]);
            $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(fn($x) => [
                'nome'    => (string) $x['nome'],
                'valor'   => $this->limparTexto($x['valor'], 120),
                'unidade' => $x['unidade'] !== null ? (string) $x['unidade'] : null,
            ], $linhas);
        } catch (Throwable $e) {
            LogService::error('ia_ctx_caract_erro', ['produto_id' => $produtoId, 'erro' => $e->getMessage()]);
            return [];
        }
    }

    /** "Honda CG 160 (2016–2023)" — ouro para copy de peça de moto. */
    private function compatibilidades(int $produtoId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT mm.nome AS montadora, mo.nome AS modelo,
                        pcp.ano_inicio, pcp.ano_fim
                   FROM produto_compatibilidade pcp
             INNER JOIN moto_montadoras mm ON mm.id = pcp.montadora_id
              LEFT JOIN moto_modelos mo ON mo.id = pcp.modelo_id
                  WHERE pcp.produto_id = :id
               ORDER BY mm.nome ASC, mo.nome ASC
                  LIMIT " . self::MAX_COMPATIBILIDADES
            );
            $stmt->execute([':id' => $produtoId]);
            $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $saida = [];
            foreach ($linhas as $x) {
                $txt = (string) $x['montadora'];
                if (!empty($x['modelo'])) {
                    $txt .= ' ' . $x['modelo'];
                }
                if (!empty($x['ano_inicio'])) {
                    $txt .= ' (' . (int) $x['ano_inicio'] . (!empty($x['ano_fim']) ? '–' . (int) $x['ano_fim'] : ' em diante') . ')';
                }
                $saida[] = $txt;
            }
            return $saida;
        } catch (Throwable $e) {
            LogService::error('ia_ctx_compat_erro', ['produto_id' => $produtoId, 'erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Média/total de avaliações aprovadas + resumo IA já existente (review_summary). */
    private function avaliacoes(int $produtoId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS total, AVG(nota) AS media
                   FROM avaliacoes
                  WHERE produto_id = :id AND aprovado = 1'
            );
            $stmt->execute([':id' => $produtoId]);
            $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'media' => null];

            $resumo = null;
            $stmt2 = $this->db->prepare('SELECT resumo FROM produto_review_summary WHERE produto_id = :id LIMIT 1');
            $stmt2->execute([':id' => $produtoId]);
            $linha = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($linha && trim((string) $linha['resumo']) !== '') {
                $resumo = $this->limparTexto($linha['resumo'], 400);
            }

            if ((int) $agg['total'] === 0 && $resumo === null) {
                return null;
            }

            return [
                'total'  => (int) $agg['total'],
                'media'  => $agg['media'] !== null ? round((float) $agg['media'], 1) : null,
                'resumo' => $resumo,
            ];
        } catch (Throwable $e) {
            LogService::error('ia_ctx_avaliacoes_erro', ['produto_id' => $produtoId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    private function imagemPrincipal(int $produtoId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT arquivo FROM produto_imagens
                  WHERE produto_id = :id
               ORDER BY principal DESC, ordem ASC, id ASC
                  LIMIT 1'
            );
            $stmt->execute([':id' => $produtoId]);
            $arq = $stmt->fetchColumn();
            return $arq !== false ? (string) $arq : null;
        } catch (Throwable $e) {
            LogService::error('ia_ctx_imagem_erro', ['produto_id' => $produtoId, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    /** promo_inicio/promo_fim são DATE — comparação por dia corrente. */
    private function promoVigente(array $p): bool
    {
        if ($p['preco_promo'] === null || (float) $p['preco_promo'] <= 0) {
            return false;
        }
        $hoje = date('Y-m-d');
        if (!empty($p['promo_inicio']) && $p['promo_inicio'] > $hoje) {
            return false;
        }
        if (!empty($p['promo_fim']) && $p['promo_fim'] < $hoje) {
            return false;
        }
        return true;
    }

    private function limparTexto($texto, int $max): ?string
    {
        if ($texto === null) {
            return null;
        }
        $limpo = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $texto)));
        if ($limpo === '') {
            return null;
        }
        return mb_strlen($limpo) > $max ? mb_substr($limpo, 0, $max - 1) . '…' : $limpo;
    }

    private function brl($valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    private function dataBr(string $data): string
    {
        $ts = strtotime($data);
        return $ts ? date('d/m/Y', $ts) : $data;
    }
}
