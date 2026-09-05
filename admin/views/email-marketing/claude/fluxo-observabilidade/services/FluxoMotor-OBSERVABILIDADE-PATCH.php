<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  OBSERVABILIDADE — patches (FluxoMotor + fluxo-worker)
 * ═══════════════════════════════════════════════════════════════════════════
 *  4 edições. As âncoras consideram as Fases 3A e 3B já aplicadas.
 *  O log é try/catch total dentro do FluxoLogService — nenhuma destas edições
 *  pode derrubar uma jornada.
 * ═══════════════════════════════════════════════════════════════════════════
 */


/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 1 — FluxoMotor::iniciarExecucao(): marco de início.
   (2 trechos no mesmo método)

   1a. ACHE o laço que encontra o trigger:

            $trigger = null;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $no) {
                if (FluxoNoRegistry::ehTrigger($no['tipo_no'])) { $trigger = $no['chave']; break; }
            }

   TROQUE POR (captura também o TIPO do trigger, para a timeline saber como
   a jornada começou):

            $trigger = null; $triggerTipo = 'trigger';
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $no) {
                if (FluxoNoRegistry::ehTrigger($no['tipo_no'])) {
                    $trigger = $no['chave']; $triggerTipo = $no['tipo_no']; break;
                }
            }

   1b. ACHE (a linha do return, logo após o `]);` do INSERT — o `]);` fica
       como está):

            return (int)$this->db->lastInsertId() ?: null;

   TROQUE POR:
────────────────────────────────────────────────────────────────────────────── */

            $novoId = (int)$this->db->lastInsertId() ?: null;

            if ($novoId && class_exists('FluxoLogService')) {
                FluxoLogService::inicio($this->db, $novoId, $fluxoId, $versao, $clienteId, $triggerTipo);
            }
            return $novoId;



/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 2 — FluxoMotor::processarUma(): cronometrar e registrar cada passo.
   Envolve o bloco do frequency capping (Fase 3B).

   ACHE (bloco inteiro do cap, exatamente como ficou na 3B):

            $canalEnvio = FluxoGuard::canalDoNo($no['tipo_no']);
            $cid        = (int)($exec['cliente_id'] ?? 0);
            if ($canalEnvio !== null && $cid > 0 && FluxoGuard::capAtingido($cid, $canalEnvio, $this->db)) {
                if (class_exists('LogService')) {
                    try { LogService::info('fluxo: envio pulado por cap', ['cliente_id'=>$cid,'canal'=>$canalEnvio,'fluxo_id'=>$exec['fluxo_id']]); } catch (Throwable $x) {}
                }
                $porta = 'saida';
            } else {
                $porta = $handler->executar($exec, $config, $this->db);
                if ($canalEnvio !== null && $cid > 0 && $porta === 'saida') {
                    FluxoGuard::registrarEnvio($cid, $canalEnvio, (int)$exec['fluxo_id'], $this->db);
                }
            }

   TROQUE POR:
────────────────────────────────────────────────────────────────────────────── */

            $canalEnvio = FluxoGuard::canalDoNo($no['tipo_no']);
            $cid        = (int)($exec['cliente_id'] ?? 0);
            $t0         = microtime(true);        // ← observabilidade
            $logDetalhe = null;                   // ← "cap" quando envio pulado

            if ($canalEnvio !== null && $cid > 0 && FluxoGuard::capAtingido($cid, $canalEnvio, $this->db)) {
                if (class_exists('LogService')) {
                    try { LogService::info('fluxo: envio pulado por cap', ['cliente_id'=>$cid,'canal'=>$canalEnvio,'fluxo_id'=>$exec['fluxo_id']]); } catch (Throwable $x) {}
                }
                $porta = 'saida';
                $logDetalhe = 'cap';
            } else {
                $porta = $handler->executar($exec, $config, $this->db);
                if ($canalEnvio !== null && $cid > 0 && $porta === 'saida') {
                    FluxoGuard::registrarEnvio($cid, $canalEnvio, (int)$exec['fluxo_id'], $this->db);
                }
            }

            // ── Observabilidade: registra o passo com a porta real ──
            if (class_exists('FluxoLogService')) {
                if ($porta === FluxoNo::ERRO && !empty($exec['erro_detalhe'])) {
                    $logDetalhe = mb_substr((string)$exec['erro_detalhe'], 0, 200);
                }
                FluxoLogService::passo(
                    $this->db, $exec, $chave, $no['tipo_no'],
                    $porta, $logDetalhe, (microtime(true) - $t0) * 1000
                );
            }


/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 3 — FluxoMotor::finalizar(): marco de fim com o status.

   ACHE (a primeira linha do corpo do método finalizar):

    private function finalizar(array $exec, string $status): void
    {

   ADICIONE logo após a abertura do corpo:
────────────────────────────────────────────────────────────────────────────── */

        if (class_exists('FluxoLogService')) {
            FluxoLogService::fim($this->db, $exec, $status);
        }


/* ─────────────────────────────────────────────────────────────────────────────
   EDIÇÃO 4 — cli/fluxo-worker.php: purge diário por retenção.

   ACHE (o fim do worker, antes do "encerrado"):

       $log("encerrado");

   ADICIONE, IMEDIATAMENTE ANTES:
────────────────────────────────────────────────────────────────────────────── */

    // ── Purge diário do log de observabilidade (retenção configurável) ──
    if (class_exists('FluxoLogService')) {
        $apagados = FluxoLogService::purgarSeDevido(Database::getInstance()->getConnection());
        if ($apagados > 0) $log("log de passos: purge de {$apagados} linhas antigas");
    }
