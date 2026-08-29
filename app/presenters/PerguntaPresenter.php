<?php
// app/presenters/PerguntaPresenter.php
// Perguntas e respostas de produto.
//
// A resposta pode vir da IA (Gemini) ou de um atendente. O app mostra a origem
// porque as duas têm peso diferente para quem lê: "respondido pela loja" não é
// a mesma coisa que "gerado automaticamente".
//
// Como em AvaliacaoPresenter, o voto "útil" chega em lote — a web resolve isso
// com uma query por pergunta dentro do laço.

final class PerguntaPresenter
{
    /**
     * @param array<int,array> $rows  Linhas de Pergunta::listarPorProduto()
     * @param array<int,bool>  $votos [pergunta_id => votou]
     * @return array<int,array>
     */
    public static function colecao(array $rows, PresenterContext $ctx, array $votos = []): array
    {
        return array_values(array_map(
            static fn(array $p) => self::uma($p, $ctx, $votos),
            $rows
        ));
    }

    public static function uma(array $p, PresenterContext $ctx, array $votos = []): array
    {
        $id     = (int)($p['id'] ?? 0);
        $status = (string)($p['status'] ?? 'aguardando_ia');
        $fonte  = $p['resposta_fonte'] ?? null;

        return [
            'id'        => $id,
            'pergunta'  => trim((string)($p['pergunta'] ?? '')),
            'resposta'  => self::texto($p['resposta'] ?? null),
            // 'ia' | 'admin' | null. O app rotula "Resposta automática" vs
            // "Resposta da loja"; sem isso não dá para distinguir.
            'fonte'     => in_array($fonte, ['ia', 'admin'], true) ? $fonte : null,
            'status'    => $status,
            'respondida'=> $status === 'respondida' && !empty($p['resposta']),
            'autor'     => [
                // Só o primeiro nome: a listagem é pública e o sobrenome não
                // acrescenta nada para quem lê.
                'nome' => self::primeiroNome((string)($p['autor_nome'] ?? '')),
            ],
            // `minha` vem do model (comparação por e-mail, já feita lá) e
            // permite ao app destacar "sua pergunta" na lista.
            'minha'     => !empty($p['minha']),
            'criado_em' => self::data($p['criado_em'] ?? null),
            'respondida_em' => self::data($p['respondida_em'] ?? null),
            'util'      => [
                'total' => (int)($p['util_count'] ?? 0),
                'votei' => (bool)($votos[$id] ?? false),
            ],
        ];
    }

    /* ================================================================= */

    private static function primeiroNome(string $nome): string
    {
        $nome  = trim($nome);
        if ($nome === '') return 'Cliente';

        $parte = explode(' ', $nome)[0];
        return $parte !== '' ? $parte : 'Cliente';
    }

    private static function texto(?string $v): ?string
    {
        $v = $v === null ? '' : trim($v);
        return $v === '' ? null : $v;
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
