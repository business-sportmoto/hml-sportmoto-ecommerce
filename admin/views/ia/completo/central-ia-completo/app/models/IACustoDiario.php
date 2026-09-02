<?php
/**
 * IACustoDiario — leitura do rollup ia_custos_diarios (alimentado pelo
 * worker a partir da Fase 1; na Fase 0 os totais são zero).
 */
class IACustoDiario
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Gasto total (USD) do dia corrente. */
    public function gastoHoje(): float
    {
        try {
            $sql = 'SELECT COALESCE(SUM(total_usd), 0) FROM ia_custos_diarios WHERE `data` = CURDATE()';
            return (float) $this->db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            LogService::error('ia_custo_hoje_erro', ['erro' => $e->getMessage()]);
            return 0.0;
        }
    }

    /** Gasto total (USD) do mês corrente. */
    public function gastoMes(): float
    {
        try {
            $sql = "SELECT COALESCE(SUM(total_usd), 0)
                      FROM ia_custos_diarios
                     WHERE `data` >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
            return (float) $this->db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            LogService::error('ia_custo_mes_erro', ['erro' => $e->getMessage()]);
            return 0.0;
        }
    }
}
