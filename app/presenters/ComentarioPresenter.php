<?php
// app/presenters/ComentarioPresenter.php
//
// Comentários de clip.
//
// A tabela guarda `email` e `ip` de quem comentou. Nenhum dos dois sai daqui:
// a lista é pública, e o que a pessoa aceitou tornar público foi o nome e o
// texto. É o mesmo cuidado que Pergunta::listarPorProduto() toma ao dar
// `unset($r['autor_email'])`.

final class ComentarioPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $rows, PresenterContext $ctx): array
    {
        return array_values(array_map(
            static fn(array $c) => self::um($c, $ctx),
            $rows
        ));
    }

    public static function um(array $c, PresenterContext $ctx): array
    {
        $nome = trim((string)($c['nome'] ?? ''));
        $nome = $nome !== '' ? $nome : 'Visitante';

        return [
            'id'    => (int)($c['id'] ?? 0),
            'nome'  => $nome,
            // As iniciais para o avatar de letra. Calcular aqui evita que cada
            // tela invente a própria regra de recorte.
            'iniciais' => self::iniciais($nome),
            'texto' => trim((string)($c['texto'] ?? '')),
            'em'    => self::data($c['criado_em'] ?? null),
            // Comentário de visitante nasce pendente; o autor precisa saber que
            // o dele ainda não está visível para os outros.
            'aprovado' => !isset($c['status']) || $c['status'] === 'aprovado' || !empty($c['aprovado']),
        ];
    }

    /** "Robert Junior" → "RJ"; "Ana" → "A". */
    private static function iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome)) ?: [];
        $partes = array_values(array_filter($partes));

        if (!$partes) {
            return '?';
        }

        $primeira = mb_substr($partes[0], 0, 1);
        $ultima   = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '';

        return mb_strtoupper($primeira . $ultima);
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
