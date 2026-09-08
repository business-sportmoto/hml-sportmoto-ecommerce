<?php
declare(strict_types=1);

/**
 * CompatibilidadeService — responde "serve na minha moto?" para UM produto.
 *
 * O catálogo já sabia filtrar por moto (MotoCompatibilidade + VeiculoService::
 * getProdutosCompativeisLote), mas só em booleano: serve ou não aparece. A
 * página de produto precisa de mais resolução, porque o dado tem três níveis
 * de precisão — montadora, modelo, faixa de anos — e a diferença entre
 * "não temos registro" e "não serve" é a diferença entre uma afirmação que a
 * loja pode fazer e uma que ela não pode.
 *
 * Estados retornados por avaliar():
 *
 *   SEM_LINHAS  produto não tem nenhuma compatibilidade cadastrada.
 *               O módulo inteiro não renderiza — decisão de produto: não se
 *               anuncia a lacuna. É o estado mais comum enquanto a base
 *               estiver incompleta.
 *   CONVITE     produto tem linhas, o visitante ainda não informou a moto.
 *   SERVE       a moto dele consta.
 *   ANO_FORA    o modelo consta, o ano dele está fora da faixa cadastrada.
 *               O estado mais útil da lista: é um quase-acerto que só a loja
 *               consegue enxergar.
 *   NAO_CONSTA  o produto tem linhas e a moto dele não está entre elas.
 *
 * NUNCA retorna "não serve". A tabela registra o que foi cadastrado como
 * compatível; a ausência de uma linha é ausência de registro, não prova de
 * incompatibilidade. A cópia da view depende disso.
 */
class CompatibilidadeService
{
    public const SEM_LINHAS = 'sem_linhas';
    public const CONVITE    = 'convite';
    public const SERVE      = 'serve';
    public const ANO_FORA   = 'ano_fora';
    public const NAO_CONSTA = 'nao_consta';

    private PDO $db;
    private MotoCompatibilidade $model;

    public function __construct()
    {
        $this->db    = Database::getInstance()->getConnection();
        $this->model = new MotoCompatibilidade();
    }

    /**
     * Avalia um produto contra a moto ativa.
     *
     * @param array|null $moto  formato de VeiculoService::getAtivo()
     * @return array{estado:string, linhas:array, casadas:array, faixas:array,
     *               observacoes:array, moto:?array, total_motos:int}
     */
    public function avaliar(int $produtoId, ?array $moto): array
    {
        $linhas = $this->model->getDoProduct($produtoId);

        $base = [
            'estado'      => self::SEM_LINHAS,
            'linhas'      => $linhas,
            'casadas'     => [],
            'faixas'      => [],
            'observacoes' => [],
            'moto'        => $moto,
            'total_motos' => $this->contarMotos($linhas),
        ];

        if (!$linhas)  return $base;
        if (!$moto || empty($moto['montadora_id'])) {
            return ['estado' => self::CONVITE] + $base;
        }

        $montadoraId = (int)$moto['montadora_id'];
        $modeloId    = !empty($moto['modelo_id']) ? (int)$moto['modelo_id'] : null;
        $ano         = !empty($moto['ano'])       ? (int)$moto['ano']       : null;

        // 1. Montadora — sem isso não há conversa.
        $daMontadora = array_values(array_filter(
            $linhas,
            fn(array $l) => (int)$l['montadora_id'] === $montadoraId
        ));
        if (!$daMontadora) return ['estado' => self::NAO_CONSTA] + $base;

        // 2. Modelo. Linha com modelo_id NULL vale para a montadora inteira.
        $doModelo = $modeloId
            ? array_values(array_filter(
                $daMontadora,
                fn(array $l) => $l['modelo_id'] === null || (int)$l['modelo_id'] === $modeloId
              ))
            : $daMontadora;
        if (!$doModelo) return ['estado' => self::NAO_CONSTA] + $base;

        // 3. Ano. Sem ano informado a loja não afirma nada sobre ano: casa no
        //    modelo e mostra as faixas cadastradas para ele decidir.
        if ($ano === null) {
            return [
                'estado'      => self::SERVE,
                'casadas'     => $doModelo,
                'faixas'      => $this->faixas($doModelo),
                'observacoes' => $this->observacoes($doModelo),
            ] + $base;
        }

        $noAno = array_values(array_filter(
            $doModelo,
            fn(array $l) => $this->anoNaFaixa($ano, $l)
        ));

        if ($noAno) {
            return [
                'estado'      => self::SERVE,
                'casadas'     => $noAno,
                'faixas'      => $this->faixas($noAno),
                'observacoes' => $this->observacoes($noAno),
            ] + $base;
        }

        // Modelo bate, ano não: quase-acerto. As faixas do modelo são a
        // resposta — é o que deixa o cliente julgar sozinho.
        return [
            'estado'      => self::ANO_FORA,
            'casadas'     => $doModelo,
            'faixas'      => $this->faixas($doModelo),
            'observacoes' => $this->observacoes($doModelo),
        ] + $base;
    }

    /**
     * Motos do produto agrupadas por montadora, para a lista de prova.
     * Uma peça pode ter 1 linha ou centenas — quem renderiza decide quantos
     * grupos mostra antes do "ver todas".
     *
     * @return array<string, array{montadora:string, slug:string, itens:array}>
     */
    public function agrupadoPorMontadora(array $linhas): array
    {
        $grupos = [];
        foreach ($linhas as $l) {
            $chave = (string)$l['montadora_id'];
            if (!isset($grupos[$chave])) {
                $grupos[$chave] = [
                    'montadora' => $l['montadora_nome'] ?? '',
                    'slug'      => $l['montadora_slug'] ?? '',
                    'itens'     => [],
                ];
            }
            $grupos[$chave]['itens'][] = [
                'modelo'     => $l['modelo_nome'] ?? null,
                'slug'       => $l['modelo_slug'] ?? null,
                'anos'       => $this->rotuloFaixa($l),
                'observacao' => $l['observacao'] ?: null,
            ];
        }

        uasort($grupos, fn($a, $b) => strcmp($a['montadora'], $b['montadora']));
        return $grupos;
    }

    /** Rótulo humano da faixa de anos de uma linha. */
    public function rotuloFaixa(array $l): ?string
    {
        $ini = $l['ano_inicio'] ? (int)$l['ano_inicio'] : null;
        $fim = $l['ano_fim']    ? (int)$l['ano_fim']    : null;

        if ($ini && $fim) return $ini === $fim ? (string)$ini : "{$ini}–{$fim}";
        if ($ini)         return "{$ini} em diante";
        if ($fim)         return "até {$fim}";
        return null;   // sem faixa = todos os anos cadastrados
    }

    /** Linha sem faixa cobre qualquer ano — a mesma regra do catálogo. */
    private function anoNaFaixa(int $ano, array $l): bool
    {
        $ini = $l['ano_inicio'] ? (int)$l['ano_inicio'] : null;
        $fim = $l['ano_fim']    ? (int)$l['ano_fim']    : null;

        if ($ini === null && $fim === null) return true;
        if ($ini === null) return $ano <= $fim;
        if ($fim === null) return $ano >= $ini;
        return $ano >= $ini && $ano <= $fim;
    }

    /** @return string[] faixas distintas, já em rótulo. */
    private function faixas(array $linhas): array
    {
        $out = [];
        foreach ($linhas as $l) {
            $r = $this->rotuloFaixa($l);
            if ($r !== null) $out[$r] = true;
        }
        return array_keys($out);
    }

    /** @return string[] observações do cadastro, sem repetir. */
    private function observacoes(array $linhas): array
    {
        $out = [];
        foreach ($linhas as $l) {
            $obs = trim((string)($l['observacao'] ?? ''));
            if ($obs !== '') $out[$obs] = true;
        }
        return array_keys($out);
    }

    /** Quantas motos distintas o produto cobre (modelo, ou a montadora toda). */
    private function contarMotos(array $linhas): int
    {
        $chaves = [];
        foreach ($linhas as $l) {
            $chaves[$l['montadora_id'] . ':' . ($l['modelo_id'] ?? '*')] = true;
        }
        return count($chaves);
    }
}
