<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/PedidoCodigoService.php
//
// O número do pedido. De 2 em 2, continuando de onde está.
//
// ── UM GERADOR SÓ ───────────────────────────────────────
//
// Havia três formatos saindo de três lugares:
//
//   checkout (site e app)   MAX(codigo numérico) + 2
//   pedido manual (admin)   md5 hex de 8 caracteres
//   Order::createFromCart   PED-AAAAMMDD-XXXXX   (sem uso)
//
// Agora os três passam por aqui. A exceção é o import da Tray, que grava o
// número do pedido LÁ (`codigo = tray_id`) de propósito — é o número que o
// cliente conhece daquela venda.
//
// ── POR QUE DENTRO DA TRANSAÇÃO ─────────────────────────
//
// `reservar()` precisa ser chamado DEPOIS do beginTransaction() e ANTES do
// INSERT do pedido. Dois efeitos, e os dois são o motivo de existir:
//
//   - o UPDATE trava a linha até o COMMIT: checkouts simultâneos entram em
//     fila em vez de ler o mesmo número e um deles bater no `uk_codigo`;
//   - se o checkout falhar, o ROLLBACK desfaz o UPDATE: o número volta, e o
//     próximo pedido pega exatamente ele. Sem buraco na contagem de 2 em 2.
//
// Fora de transação também funciona (UPDATE com LAST_INSERT_ID é atômico
// sozinho), só que um pedido que falhe depois deixa o número para trás.
//
// ── A ARMADILHA DO LAST_INSERT_ID ───────────────────────
//
// Esta classe usa LAST_INSERT_ID(expr) para ler o valor recém-gravado. O
// INSERT do pedido, logo em seguida, sobrescreve esse valor com o id do
// pedido — que é o que o chamador quer ao chamar $db->lastInsertId(). Por
// isso a ordem é rígida: reservar → INSERT → lastInsertId. Nunca reservar
// entre o INSERT e a leitura do id.
// ════════════════════════════════════════════════════════

final class PedidoCodigoService
{
    /** Distância entre um pedido e o seguinte. */
    public const PASSO = 2;

    /** Primeiro código de um banco sem nenhum pedido. */
    public const PRIMEIRO = 15121;

    /** Teto de tentativas ao pular um código que já existe. */
    private const MAX_SALTOS = 50;

    /**
     * Reserva o próximo código de pedido.
     *
     * @param PDO $db  A MESMA conexão da transação que fará o INSERT.
     *                 Database::getInstance() devolve sempre a mesma, mas
     *                 recebê-la explícita deixa claro de qual transação o
     *                 número faz parte.
     */
    public static function reservar(PDO $db): string
    {
        try {
            for ($i = 0; $i < self::MAX_SALTOS; $i++) {
                $db->exec(
                    "UPDATE pedido_sequencia
                        SET ultimo = LAST_INSERT_ID(ultimo + passo)
                      WHERE id = 1"
                );
                $numero = (int) $db->query("SELECT LAST_INSERT_ID()")->fetchColumn();

                if ($numero <= 0) {
                    // UPDATE não achou a linha: tabela criada sem o INSERT
                    // inicial. Cai no cálculo antigo em vez de emitir 0.
                    return self::pelaTabelaDePedidos($db, 'linha id=1 ausente');
                }

                // Código já existe? Só acontece com lixo antigo: um md5 do
                // gerador manual que calhou de ser só dígitos e caiu
                // exatamente no caminho da sequência. Pula para o próximo em
                // vez de deixar o INSERT bater no `uk_codigo`.
                if (!self::existe($db, (string) $numero)) {
                    return (string) $numero;
                }
            }

            throw new RuntimeException('PedidoCodigoService: ' . self::MAX_SALTOS
                . ' códigos seguidos já existiam — a sequência está dessincronizada.');

        } catch (PDOException $e) {
            // 42S02 = tabela não existe. É o intervalo entre subir o código e
            // rodar sql/pedido-sequencia.sql — o checkout não pode parar por
            // isso. Qualquer outro erro de banco sobe normalmente.
            if ($e->getCode() === '42S02') {
                return self::pelaTabelaDePedidos($db, 'tabela pedido_sequencia ausente');
            }
            throw $e;
        }
    }

    /**
     * O cálculo antigo, como rede de segurança.
     *
     * Só roda sem a tabela de sequência. Tem a corrida e o risco de sequestro
     * descritos em sql/pedido-sequencia.sql — por isso avisa no log toda vez.
     */
    private static function pelaTabelaDePedidos(PDO $db, string $motivo): string
    {
        if (class_exists('LogService')) {
            LogService::warning('pedido: numeração pelo MAX de pedidos — rode sql/pedido-sequencia.sql', [
                'motivo' => $motivo,
            ]);
        }

        $ultimo = (int) $db->query(
            "SELECT COALESCE(MAX(CAST(codigo AS UNSIGNED)), 0)
               FROM pedidos
              WHERE codigo REGEXP '^[0-9]+$'
                AND COALESCE(canal, '') <> 'tray'"
        )->fetchColumn();

        return (string) ($ultimo < self::PRIMEIRO ? self::PRIMEIRO : $ultimo + self::PASSO);
    }

    private static function existe(PDO $db, string $codigo): bool
    {
        $st = $db->prepare("SELECT 1 FROM pedidos WHERE codigo = ? LIMIT 1");
        $st->execute([$codigo]);
        return (bool) $st->fetchColumn();
    }
}
