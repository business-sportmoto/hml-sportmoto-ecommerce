<?php
// app/controllers/AppMotosController.php
//
// Navegação por moto: montadora → modelo → ano → peças compatíveis.
//
// Espelha MotoController (/motos e /montadora/{slug}/{modelo}-{ano}). É o
// recurso que separa esta loja de um e-commerce genérico, e até agora só
// existia na web — o app tinha a garagem (as motos DO CLIENTE) e a cascata de
// cadastro, mas não o caminho de descoberta "o que serve nesta moto".
//
// Uma diferença de forma em relação à web: lá a URL junta modelo e ano num
// segmento só (`cg-160-2017`, desmembrado por regex). Aqui são segmentos
// separados. O truque da web existe para a URL ficar bonita no Google; o app
// não tem esse problema, e desmembrar por regex é uma fonte de erro a menos.

class AppMotosController extends AppApiController
{
    /**
     * GET /api/app/v1/motos
     *
     * As montadoras que têm peças. Só elas: montadora cadastrada sem nenhum
     * produto compatível é um beco sem saída — a web já filtra assim
     * (HAVING total_produtos > 0) e o app não deve divergir.
     */
    public function montadoras(): void
    {
        $this->bootOpcional();
        $this->liberarSessao();

        try {
            $rows = $this->db()->query(
                "SELECT mm.id, mm.nome, mm.slug, mm.logo, mm.thumb,
                        COUNT(DISTINCT pc.produto_id) AS total_produtos,
                        COUNT(DISTINCT mo.id)         AS total_modelos
                   FROM moto_montadoras mm
              LEFT JOIN moto_modelos mo ON mo.montadora_id = mm.id AND mo.ativo = 1
              LEFT JOIN produto_compatibilidade pc ON pc.montadora_id = mm.id
                  WHERE mm.ativo = 1
               GROUP BY mm.id
                 HAVING total_produtos > 0
               ORDER BY mm.ordem ASC, mm.nome ASC"
            )->fetchAll();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'motos_montadoras']);
            $this->falha(500, 'falha_montadoras', 'Não foi possível carregar as marcas de moto.');
        }

        $this->ok(['montadoras' => MotoCatalogoPresenter::montadoras($rows, $this->contexto())]);
    }

    /**
     * GET /api/app/v1/motos/{montadora}
     * GET /api/app/v1/motos/{montadora}/{modelo}
     * GET /api/app/v1/motos/{montadora}/{modelo}/{ano}
     *
     * Uma rota só para os três níveis: o que muda entre eles é quão fundo a
     * pessoa desceu, e o payload acompanha — mais fundo, menos opções de
     * refinamento e mais precisão nos produtos.
     */
    public function catalogo(string $montadoraSlug = '', string $modeloSlug = '', string $anoTexto = ''): void
    {
        $this->bootOpcional();

        // O ano chega como `\d+` porque o roteador não aceita quantificador
        // com chaves no padrão. A faixa é conferida aqui: fora dela é lixo de
        // URL, e mostrar "peças para a Honda CG 160 do ano 99999" seria pior
        // do que dizer que não existe.
        $ano = null;
        if ($anoTexto !== '') {
            $ano = (int)$anoTexto;
            if ($ano < 1900 || $ano > (int)date('Y') + 2) {
                $this->falha(422, 'ano_invalido', 'Ano inválido.');
            }
        }

        $compat   = new MotoCompatibilidade();
        $resolvido = $compat->resolveUrl($montadoraSlug, $modeloSlug ?: null, $ano);

        if (empty($resolvido['montadora'])) {
            $this->falha(404, 'nao_encontrado', 'Marca de moto não encontrada.');
        }

        $montadora = $resolvido['montadora'];
        $modelo    = $resolvido['modelo'] ?? null;

        // Slug de modelo que não existe naquela montadora: seguir adiante
        // mostraria as peças da montadora inteira sob um título que promete um
        // modelo específico. Melhor dizer que não achou.
        if ($modeloSlug !== '' && $modelo === null) {
            $this->falha(404, 'nao_encontrado', 'Modelo não encontrado nesta marca.');
        }

        $pagina = $this->pagina(24, 48);
        $ctx    = $this->contexto();

        $montadoraId = (int)$montadora['id'];
        $modeloId    = $modelo ? (int)$modelo['id'] : null;

        $filtros = [
            'categoria_id' => (int)$this->query('categoria_id', 0),
            'marca_id'     => (int)$this->query('marca_id', 0),
            'q'            => trim((string)$this->query('q', '')),
            'ordem'        => $this->ordem((string)$this->query('ordem', 'relevancia')),
        ];

        try {
            $produtos = $compat->getProdutosCompativeis(
                $montadoraId, $modeloId, $ano, $pagina['limit'], $pagina['offset'], $filtros
            );
            $total = $compat->countCompativeis($montadoraId, $modeloId, $ano, $filtros);

            // Os refinamentos disponíveis dependem de onde a pessoa está. Sem
            // modelo escolhido, oferece modelos; com modelo e sem ano, oferece
            // anos. No nível mais fundo não há o que oferecer.
            $modelos = $modeloId === null ? $this->modelosDe($montadoraId) : [];
            $anos    = ($modeloId !== null && $ano === null) ? $this->anosDe($modeloId) : [];
        } catch (\Throwable $e) {
            AppLog::exception($e, [
                'acao' => 'motos_catalogo', 'montadora' => $montadoraId, 'modelo' => $modeloId,
            ]);
            $this->falha(500, 'falha_catalogo', 'Não foi possível carregar as peças desta moto.');
        }

        $this->liberarSessao();

        $this->okPaginado(
            'produtos',
            ProductCardPresenter::colecao($produtos, $ctx),
            $total,
            $pagina,
            [
                'contexto' => MotoCatalogoPresenter::contexto($montadora, $modelo, $ano, $ctx),
                'modelos'  => MotoCatalogoPresenter::modelos($modelos, $ctx),
                'anos'     => MotoCatalogoPresenter::anos($anos),
            ]
        );
    }

    /* ================================================================= */

    /** Modelos da montadora que têm ao menos uma peça mapeada. */
    private function modelosDe(int $montadoraId): array
    {
        $st = $this->db()->prepare(
            "SELECT mo.id, mo.nome, mo.slug, mo.thumb,
                    COUNT(DISTINCT pc.produto_id) AS total_produtos
               FROM moto_modelos mo
          LEFT JOIN produto_compatibilidade pc ON pc.modelo_id = mo.id
              WHERE mo.montadora_id = ? AND mo.ativo = 1
           GROUP BY mo.id
             HAVING total_produtos > 0
           ORDER BY mo.nome ASC"
        );
        $st->execute([$montadoraId]);
        return $st->fetchAll();
    }

    /**
     * Anos do modelo.
     *
     * A junção repete a regra de faixa da compatibilidade: uma peça cadastrada
     * como "2015 até 2020" conta para cada ano do intervalo, e uma sem faixa
     * (ano_inicio e ano_fim nulos) conta para todos.
     */
    private function anosDe(int $modeloId): array
    {
        $st = $this->db()->prepare(
            "SELECT ma.ano,
                    COUNT(DISTINCT pc.produto_id) AS total_produtos
               FROM moto_anos ma
          LEFT JOIN produto_compatibilidade pc
                 ON pc.modelo_id = ma.modelo_id
                AND (pc.ano_inicio IS NULL OR pc.ano_inicio <= ma.ano)
                AND (pc.ano_fim    IS NULL OR pc.ano_fim    >= ma.ano)
              WHERE ma.modelo_id = ?
           GROUP BY ma.ano
           ORDER BY ma.ano DESC"
        );
        $st->execute([$modeloId]);
        return $st->fetchAll();
    }

    /**
     * Ordenação vinda do cliente.
     *
     * Lista fechada, e não repasse direto: o valor entra na SQL por
     * interpolação lá no MotoCompatibilidade::buildOrder().
     */
    private function ordem(string $valor): string
    {
        $aceitas = ['relevancia', 'menor_preco', 'maior_preco', 'mais_vendidos', 'novidades'];

        return in_array($valor, $aceitas, true) ? $valor : 'relevancia';
    }
}
