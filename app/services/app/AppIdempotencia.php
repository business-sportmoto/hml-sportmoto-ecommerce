<?php
declare(strict_types=1);

/**
 * app/services/app/AppIdempotencia.php
 *
 * "Já fiz isso?" — para operações que não podem acontecer duas vezes.
 *
 * O caso que importa é finalizar o pedido. A rede do celular cai no meio do
 * POST, o app não recebe resposta, o usuário toca de novo. Sem controle, sai um
 * segundo pedido, uma segunda cobrança e uma segunda baixa de estoque.
 *
 * A trava é o próprio INSERT: (dispositivo_id, chave) é UNIQUE, então duas
 * requisições concorrentes nunca conseguem ambas reservar. A que perder recebe
 * 409 e o app reconsulta, em vez de criar um pedido paralelo.
 */
final class AppIdempotencia
{
    /**
     * Considera abandonada uma reserva sem conclusão após este tempo.
     *
     * 5 minutos: o pior caso real é o gateway demorando alguns segundos. Um
     * valor muito maior deixaria o cliente travado depois de um erro fatal do
     * PHP; muito menor deixaria a retentativa passar enquanto a primeira ainda
     * está cobrando o cartão.
     */
    private const SEGUNDOS_TRAVA = 300;

    /**
     * Tenta reservar a chave.
     *
     * @return array{estado:string, resposta:?array, status_http:?int}
     *   estado 'reservado'    → siga; nada foi feito antes
     *   estado 'concluido'    → devolva `resposta` (é a original)
     *   estado 'em_andamento' → outra requisição está processando agora
     *   estado 'conflito'     → mesma chave, corpo diferente
     */
    public static function reservar(
        PDO $pdo,
        int $dispositivoId,
        string $chave,
        string $endpoint,
        array $corpo
    ): array {
        $hash = hash('sha256', json_encode($corpo, JSON_UNESCAPED_UNICODE) ?: '');

        try {
            $pdo->prepare(
                "INSERT INTO app_idempotencia
                    (dispositivo_id, chave, endpoint, requisicao_hash)
                 VALUES (:d, :c, :e, :h)"
            )->execute([':d' => $dispositivoId, ':c' => $chave, ':e' => $endpoint, ':h' => $hash]);

            return ['estado' => 'reservado', 'resposta' => null, 'status_http' => null];

        } catch (\PDOException $e) {
            // 23000 = violação de UNIQUE. Qualquer outro erro é problema de
            // banco e não pode ser confundido com "duplicata".
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        $st = $pdo->prepare(
            "SELECT estado, status_http, resposta, requisicao_hash, criado_em
               FROM app_idempotencia
              WHERE dispositivo_id = :d AND chave = :c LIMIT 1"
        );
        $st->execute([':d' => $dispositivoId, ':c' => $chave]);
        $linha = $st->fetch(PDO::FETCH_ASSOC);

        if (!$linha) {
            // A linha sumiu entre o INSERT falhar e o SELECT (limpeza rodando).
            // Tratar como reservado é seguro: no pior caso a operação repete.
            return ['estado' => 'reservado', 'resposta' => null, 'status_http' => null];
        }

        if (!hash_equals((string)$linha['requisicao_hash'], $hash)) {
            return ['estado' => 'conflito', 'resposta' => null, 'status_http' => null];
        }

        if ($linha['estado'] === 'concluido') {
            return [
                'estado'      => 'concluido',
                'resposta'    => json_decode((string)$linha['resposta'], true) ?: null,
                'status_http' => (int)$linha['status_http'],
            ];
        }

        // 'em_andamento' antigo = a requisição original morreu sem concluir
        // (fatal do PHP, processo derrubado). Liberamos para nova tentativa.
        if ($linha['estado'] === 'em_andamento'
            && strtotime((string)$linha['criado_em']) < time() - self::SEGUNDOS_TRAVA) {
            $pdo->prepare(
                "UPDATE app_idempotencia
                    SET estado = 'em_andamento', criado_em = NOW()
                  WHERE dispositivo_id = :d AND chave = :c"
            )->execute([':d' => $dispositivoId, ':c' => $chave]);

            return ['estado' => 'reservado', 'resposta' => null, 'status_http' => null];
        }

        if ($linha['estado'] === 'falhou') {
            // Falha não é resultado: deixa tentar de novo com a mesma chave.
            $pdo->prepare(
                "UPDATE app_idempotencia
                    SET estado = 'em_andamento', criado_em = NOW(), resposta = NULL, status_http = NULL
                  WHERE dispositivo_id = :d AND chave = :c"
            )->execute([':d' => $dispositivoId, ':c' => $chave]);

            return ['estado' => 'reservado', 'resposta' => null, 'status_http' => null];
        }

        return ['estado' => 'em_andamento', 'resposta' => null, 'status_http' => null];
    }

    public static function concluir(
        PDO $pdo,
        int $dispositivoId,
        string $chave,
        array $resposta,
        int $statusHttp,
        ?int $pedidoId = null
    ): void {
        try {
            $pdo->prepare(
                "UPDATE app_idempotencia
                    SET estado = 'concluido', status_http = :s, resposta = :r,
                        pedido_id = :p, concluido_em = NOW()
                  WHERE dispositivo_id = :d AND chave = :c"
            )->execute([
                ':s' => $statusHttp,
                ':r' => json_encode($resposta, JSON_UNESCAPED_UNICODE),
                ':p' => $pedidoId,
                ':d' => $dispositivoId,
                ':c' => $chave,
            ]);
        } catch (\Throwable $e) {
            // Não pode derrubar a resposta: o pedido já existe e o cliente
            // precisa saber disso. Perder o registro custa, no máximo, uma
            // duplicata numa retentativa improvável.
            AppLog::exception($e, ['acao' => 'idempotencia_concluir', 'chave' => $chave]);
        }
    }

    /**
     * Marca como falha — libera a chave para nova tentativa.
     * Usado quando a operação não chegou a produzir efeito (validação recusou).
     */
    public static function falhar(PDO $pdo, int $dispositivoId, string $chave): void
    {
        try {
            $pdo->prepare(
                "UPDATE app_idempotencia SET estado = 'falhou', concluido_em = NOW()
                  WHERE dispositivo_id = :d AND chave = :c"
            )->execute([':d' => $dispositivoId, ':c' => $chave]);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'idempotencia_falhar', 'chave' => $chave]);
        }
    }
}
