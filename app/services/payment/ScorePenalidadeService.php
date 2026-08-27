<?php
declare(strict_types=1);

/**
 * app/services/payment/ScorePenalidadeService.php
 *
 * Aplica e remove penalidades de score por comportamento de risco.
 *
 * POR QUE COLUNA PRÓPRIA E NÃO DESCONTO NO score_total:
 *   ScoreService::recalcular() reescreve score_total a partir dos fatores
 *   reais (LTV, pedidos, devoluções). Descontar direto ali faria a punição
 *   evaporar no próximo recálculo — e ninguém perceberia, porque nada
 *   registraria que ela existiu. A penalidade em coluna separada sobrevive ao
 *   recálculo e tem validade explícita.
 *
 * O score EFETIVO que o decisor usa é score_total menos a penalidade vigente.
 */
class ScorePenalidadeService
{
    /** Entrou em antifraude: sinal fraco, penalidade leve. */
    public const PEN_ANTIFRAUDE = 25;
    /** Terceira tentativa com dados inválidos: teste de cartão. Pesada. */
    public const PEN_DADOS_INVALIDOS = 60;

    /** Quantas tentativas com dados inválidos disparam a penalidade. */
    public const LIMITE_DADOS_INVALIDOS = 3;

    /** Validade padrão. Punição sem prazo vira condenação perpétua. */
    private const DIAS_VALIDADE = 90;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /**
     * Aplica penalidade. Acumula com a vigente em vez de substituir — dois
     * comportamentos de risco no mesmo período devem somar, não se anular.
     */
    public function aplicar(int $clienteId, int $pontos, string $motivo, ?int $dias = null): void
    {
        if ($clienteId <= 0 || $pontos <= 0) return;

        $dias    = $dias ?? self::DIAS_VALIDADE;
        $vigente = $this->penalidadeVigente($clienteId);
        $total   = $vigente + $pontos;

        try {
            $this->db->prepare(
                "UPDATE clientes_score
                    SET penalidade_pontos    = :p,
                        penalidade_motivo    = :m,
                        penalidade_expira_em = DATE_ADD(NOW(), INTERVAL :d DAY),
                        penalidade_em        = NOW()
                  WHERE cliente_id = :c"
            )->execute([':p' => $total, ':m' => mb_substr($motivo, 0, 255), ':d' => $dias, ':c' => $clienteId]);

            LogService::audit('Penalidade de score aplicada', [
                'cliente_id'  => $clienteId,
                'pontos'      => $pontos,
                'acumulado'   => $total,
                'motivo'      => $motivo,
                'valida_dias' => $dias,
            ]);
        } catch (\Throwable $e) {
            // Penalidade é política de risco, não pode derrubar um pagamento.
            LogService::exception($e, 'error', 'pagamento', [
                'acao' => 'aplicar_penalidade', 'cliente_id' => $clienteId,
            ]);
        }
    }

    /** Penalidade leve por ter caído no antifraude. */
    public function porAntifraude(int $clienteId, string $regra): void
    {
        $this->aplicar($clienteId, self::PEN_ANTIFRAUDE,
            'Pedido enviado ao antifraude (' . $regra . ')');
    }

    /**
     * Penaliza quando o cliente acumula tentativas com dados de cartão
     * inválidos. Uma é engano de digitação; três seguidas é teste de cartão.
     *
     * Conta as tentativas das últimas 24h, não do pedido — quem testa cartão
     * abre pedidos novos a cada tentativa.
     *
     * @return bool true quando a penalidade foi aplicada agora
     */
    public function avaliarDadosInvalidos(int $clienteId): bool
    {
        if ($clienteId <= 0) return false;

        try {
            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM pgto_tentativas
                  WHERE cliente_id = ?
                    AND classe_erro IN ('cartao_invalido','cartao_vencido','senha_invalida')
                    AND criado_em >= (NOW() - INTERVAL 24 HOUR)"
            );
            $st->execute([$clienteId]);
            $n = (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'pagamento', ['acao' => 'contar_dados_invalidos']);
            return false;
        }

        if ($n < self::LIMITE_DADOS_INVALIDOS) return false;

        $this->aplicar($clienteId, self::PEN_DADOS_INVALIDOS,
            $n . ' tentativas com dados de cartão inválidos em 24h');

        LogService::critical('Possível teste de cartão', [
            'cliente_id' => $clienteId,
            'tentativas' => $n,
        ], 'pagamento');

        return true;
    }

    /**
     * Fraude confirmada pela ClearSale: zera o score e liga a trava.
     * A trava é permanente até um admin removê-la — daí não ter validade.
     */
    public function marcarFraudeConfirmada(int $clienteId, string $motivo = ''): void
    {
        if ($clienteId <= 0) return;

        try {
            $this->db->prepare(
                "UPDATE clientes_score
                    SET fraude_confirmada    = 1,
                        fraude_confirmada_em = NOW(),
                        penalidade_pontos    = 9999,
                        penalidade_motivo    = :m,
                        penalidade_expira_em = NULL,
                        penalidade_em        = NOW()
                  WHERE cliente_id = :c"
            )->execute([
                ':m' => mb_substr('Fraude confirmada. ' . $motivo, 0, 255),
                ':c' => $clienteId,
            ]);

            LogService::critical('Fraude confirmada — score zerado', [
                'cliente_id' => $clienteId,
                'motivo'     => $motivo,
            ], 'pagamento');
        } catch (\Throwable $e) {
            LogService::exception($e, 'error', 'pagamento', [
                'acao' => 'marcar_fraude', 'cliente_id' => $clienteId,
            ]);
        }
    }

    /** Remoção manual — decisão de admin, sempre auditada. */
    public function limpar(int $clienteId, string $motivo): void
    {
        $this->db->prepare(
            "UPDATE clientes_score
                SET penalidade_pontos = 0, penalidade_motivo = NULL,
                    penalidade_expira_em = NULL, fraude_confirmada = 0
              WHERE cliente_id = ?"
        )->execute([$clienteId]);

        LogService::audit('Penalidade de score removida', [
            'cliente_id' => $clienteId,
            'motivo'     => $motivo,
            'por'        => AuthHelper::usuarioId(),
        ]);
    }

    /** Penalidade que ainda vale hoje. Expirada conta como zero. */
    public function penalidadeVigente(int $clienteId): int
    {
        $st = $this->db->prepare(
            "SELECT penalidade_pontos, penalidade_expira_em
               FROM clientes_score WHERE cliente_id = ? LIMIT 1"
        );
        $st->execute([$clienteId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return 0;

        $p = (int) ($r['penalidade_pontos'] ?? 0);
        if ($p <= 0) return 0;

        $exp = $r['penalidade_expira_em'] ?? null;
        if ($exp !== null && strtotime((string) $exp) < time()) return 0;

        return $p;
    }
}
