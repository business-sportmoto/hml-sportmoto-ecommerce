<?php
// app/presenters/HistoricoPresenter.php
// Itens do histórico de navegação.
//
// A tabela guarda quatro tipos no mesmo lugar (produto, categoria, marca,
// clip) e o JOIN de History::getClienteHistory() traz as colunas dos quatro,
// quase todas nulas em cada linha. Aqui isso vira um item com forma única —
// o app renderiza a lista sem um `switch` por tipo em cada célula.

final class HistoricoPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $linhas, PresenterContext $ctx): array
    {
        $saida = [];

        foreach ($linhas as $h) {
            $item = self::um($h, $ctx);
            if ($item !== null) {
                $saida[] = $item;
            }
        }

        return $saida;
    }

    /**
     * @return array|null null quando a referência foi apagada (produto
     *         excluído, por exemplo) — a linha do histórico sobrevive, mas não
     *         há o que mostrar, e um item sem nome nem destino é só ruído.
     */
    private static function um(array $h, PresenterContext $ctx): ?array
    {
        $tipo = (string)($h['tipo'] ?? '');

        [$titulo, $subtitulo, $imagem, $destino] = match ($tipo) {
            'produto' => [
                $h['produto_nome'] ?? null,
                null,
                $ctx->url($h['produto_img'] ?? null),
                !empty($h['produto_slug'])
                    ? ['tipo' => 'produto', 'params' => ['slug' => $h['produto_slug']]]
                    : null,
            ],
            'categoria' => [
                $h['categoria_nome'] ?? null,
                'Categoria',
                null,
                !empty($h['categoria_slug'])
                    ? ['tipo' => 'categoria', 'params' => ['slug' => $h['categoria_slug']]]
                    : null,
            ],
            'marca' => [
                $h['marca_nome'] ?? null,
                'Marca',
                $ctx->url($h['marca_logo'] ?? null),
                !empty($h['marca_slug'])
                    ? ['tipo' => 'marca', 'params' => ['slug' => $h['marca_slug']]]
                    : null,
            ],
            'clip' => [
                $h['clip_nome'] ?? 'Clip',
                'Clip',
                $ctx->url($h['clip_poster'] ?? null),
                !empty($h['clip_id'])
                    ? ['tipo' => 'clips', 'params' => ['id' => (int)$h['clip_id']]]
                    : null,
            ],
            'busca' => [
                $h['termo_busca'] ?? null,
                'Busca',
                null,
                !empty($h['termo_busca'])
                    ? ['tipo' => 'catalogo', 'params' => ['q' => $h['termo_busca']]]
                    : null,
            ],
            default => [null, null, null, null],
        };

        if ($titulo === null || $titulo === '') {
            return null;
        }

        return [
            'id'        => (int)$h['id'],
            'tipo'      => $tipo,
            'titulo'    => $titulo,
            'subtitulo' => $subtitulo,
            'imagem'    => $imagem,
            'destino'   => $destino,
            // Marca de tempo de permanência, quando registrada.
            'segundos'  => isset($h['tempo_permanencia']) ? (int)$h['tempo_permanencia'] : null,
            'visto_em'  => !empty($h['criado_em'])
                ? date(DATE_ATOM, strtotime((string)$h['criado_em']))
                : null,
        ];
    }
}
