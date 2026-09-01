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

            // O telefone existe para o formulário de edição poder devolvê-lo
            // intacto. Sem ele o campo abriria vazio, e salvar uma correção de
            // número da casa APAGARIA o telefone que a transportadora usa para
            // ligar quando não acha o endereço.
            'telefone'    => self::telefone($e['telefone_contato'] ?? null),
        ];
    }

    /** "(51) 98973-9674" — a coluna guarda só dígitos. */
    private static function telefone(?string $telefone): ?string
    {
        $d = preg_replace('/\D/', '', (string)$telefone) ?? '';

        if (strlen($d) === 11) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
        }
        if (strlen($d) === 10) {
            return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
        }

        return $d !== '' ? $d : null;
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
