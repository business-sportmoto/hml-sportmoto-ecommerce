<?php
// app/presenters/EnderecoPresenter.php
// Endereços de entrega.
//
// Duas formas do mesmo endereço, porque as telas pedem coisas diferentes:
//
//   `resumo`   — uma linha, para a barra de entrega do cabeçalho. É o que o
//                usuário reconhece de relance: rua e número, nada mais.
//   `completo` — o endereço postal inteiro, para a folha de seleção e o
//                checkout, onde escolher errado custa uma entrega.
//
// O CEP sai formatado (00000-000) porque a coluna é CHAR(9) e já guarda com
// hífen; formatar no app duplicaria a regra em dois lugares.

final class EnderecoPresenter
{
    /** @return array<int,array> */
    public static function colecao(array $rows): array
    {
        return array_values(array_map(
            static fn(array $e) => self::um($e),
            $rows
        ));
    }

    public static function um(array $e): array
    {
        $numero      = trim((string)($e['numero'] ?? ''));
        $logradouro  = trim((string)($e['logradouro'] ?? ''));
        $complemento = trim((string)($e['complemento'] ?? ''));

        // "Rua Tal 962" — sem vírgula, como o usuário lê na placa. O endereço
        // postal formal fica em `completo`.
        $resumo = trim($logradouro . ($numero !== '' ? ' ' . $numero : ''));

        $partes = array_filter([
            $resumo,
            $complemento !== '' ? $complemento : null,
            trim((string)($e['bairro'] ?? '')),
            trim((string)($e['cidade'] ?? '')) . ' - ' . strtoupper((string)($e['estado'] ?? '')),
            self::cep($e['cep'] ?? null),
        ]);

        return [
            'id'          => (int)$e['id'],
            'apelido'     => $e['apelido'] ?: null,
            'destinatario'=> $e['nome_destinatario'] ?? null,
            'resumo'      => $resumo !== '' ? $resumo : null,
            'completo'    => implode(', ', $partes),
            'cep'         => self::cep($e['cep'] ?? null),
            'logradouro'  => $logradouro,
            'numero'      => $numero,
            'complemento' => $complemento !== '' ? $complemento : null,
            'bairro'      => $e['bairro'] ?? null,
            'cidade'      => $e['cidade'] ?? null,
            'estado'      => strtoupper((string)($e['estado'] ?? '')),
            'principal'   => !empty($e['principal']),
            'observacao'  => $e['observacao_entrega'] ?: null,
        ];
    }

    private static function cep(?string $cep): ?string
    {
        $digitos = preg_replace('/\D/', '', (string)$cep);
        if (strlen((string)$digitos) !== 8) {
            return $cep ?: null;
        }
        return substr($digitos, 0, 5) . '-' . substr($digitos, 5);
    }
}
