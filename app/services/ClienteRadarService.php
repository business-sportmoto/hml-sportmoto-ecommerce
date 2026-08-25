<?php
/**
 * app/services/ClienteRadarService.php
 *
 * Radar de clientes: varre o cadastro procurando estados que "amadurecem" com
 * o tempo e emite eventos no stream. O motor existente faz o resto.
 *
 * Sondas:
 *   - aniversario       (uma vez por ano)
 *   - inatividade       (limiares 30/60/90 dias; reseta quando o cliente loga)
 *   - saldo_expirando   (crédito prestes a expirar; uma vez por transação)
 *
 * Emite via INSERT DIRETO na tabela `eventos` — o TrackingService retorna null
 * em CLI (PHP_SAPI==='cli'), então o worker não pode usá-lo. Os eventos saem
 * com cliente_id preenchido e um visitante_token sentinela, fácil de limpar.
 *
 * Todas as datas-limite são calculadas em PHP e passadas como parâmetros, para
 * a query ser portável (o único resquício MySQL é NOW() e MONTH/DAY, que o
 * harness de teste adapta para SQLite).
 */
class ClienteRadarService
{
    /** Token sentinela dos eventos do radar (CHAR(32)). */
    private const TOKEN = 'radar00000000000000000000000000';

    /** Limiares de inatividade em dias — FIXOS (viram nomes de evento). */
    private const LIMIARES_INATIVIDADE = [90, 60, 30]; // maior primeiro

    /** @var PDO */
    private $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: Database::getInstance()->getConnection();
    }

    /**
     * Roda todas as sondas. @return array estatísticas por sonda.
     */
    public function varrer(): array
    {
        $stats = [
            'aniversario'     => 0,
            'inatividade'     => 0,
            'saldo_expirando' => 0,
            'purgados'        => 0,
        ];
        try { $stats['aniversario']     = $this->sondaAniversario(); }     catch (Throwable $e) { $this->log('aniversario', $e); }
        try { $stats['inatividade']     = $this->sondaInatividade(); }     catch (Throwable $e) { $this->log('inatividade', $e); }
        try { $stats['saldo_expirando'] = $this->sondaSaldoExpirando(); }  catch (Throwable $e) { $this->log('saldo_expirando', $e); }
        try { $stats['purgados']        = $this->purgarEmissoes(); }       catch (Throwable $e) { $this->log('purge', $e); }
        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SONDA: ANIVERSÁRIO
    // ─────────────────────────────────────────────────────────────────────────

    private function sondaAniversario(): int
    {
        $mes = (int)date('n');
        $dia = (int)date('j');
        $ano = (int)date('Y');

        // Edge 29/02: em ano não-bissexto, quem nasceu em 29/02 comemora hoje (28/02)
        $incluir2902 = ($mes === 2 && $dia === 28 && !$this->ehBissexto($ano));

        $sql = "SELECT c.id, c.nascimento
                FROM clientes c
                JOIN usuarios u ON u.id = c.usuario_id
                WHERE c.nascimento IS NOT NULL
                  AND u.deleted_at IS NULL
                  AND ( (MONTH(c.nascimento) = :mes AND DAY(c.nascimento) = :dia)";
        $params = [':mes' => $mes, ':dia' => $dia];
        if ($incluir2902) {
            $sql .= " OR (MONTH(c.nascimento) = 2 AND DAY(c.nascimento) = 29)";
        }
        $sql .= " )";

        $st = $this->db->prepare($sql);
        $st->execute($params);

        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['id'];
            $chave = "aniversario:{$ano}";
            if (!$this->reservar($cid, $chave, 'aniversario')) continue;

            $idade = null;
            $anoNasc = (int)substr((string)$row['nascimento'], 0, 4);
            if ($anoNasc > 1900) $idade = $ano - $anoNasc;

            $ctx = [];
            if ($idade !== null) $ctx['aniversario_idade'] = $idade;

            $this->emitir($cid, 'aniversario', null, null, $ctx);
            $n++;
        }
        return $n;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SONDA: INATIVIDADE
    // ─────────────────────────────────────────────────────────────────────────
    // "Inativo" pressupõe que já foi ativo → só clientes com ultimo_login.
    // Quem nunca logou é caso do Bloco 2 (incentivo de cadastro).
    //
    // Emite UM evento por cliente por execução: o MAIOR limiar cruzado que
    // ainda não foi emitido nesta sessão de inatividade. A chave embute o
    // epoch do ultimo_login, então logar de novo reabre todos os limiares.

    private function sondaInatividade(): int
    {
        $agora = time();
        // menor limiar define quem é candidato
        $menor = min(self::LIMIARES_INATIVIDADE);
        $limiteMenor = date('Y-m-d H:i:s', $agora - $menor * 86400);

        $st = $this->db->prepare(
            "SELECT c.id, c.saldo_disponivel, u.ultimo_login
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE u.ultimo_login IS NOT NULL
               AND u.deleted_at IS NULL
               AND u.ultimo_login <= :limite"
        );
        $st->execute([':limite' => $limiteMenor]);

        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid       = (int)$row['id'];
            $loginTs   = strtotime((string)$row['ultimo_login']);
            if ($loginTs === false) continue;
            $diasInativo = (int)floor(($agora - $loginTs) / 86400);

            // O MAIOR limiar que o cliente cruzou nesta sessão de inatividade.
            // (LIMIARES_INATIVIDADE está em ordem decrescente, então é o 1º que casa.)
            $marco = null;
            foreach (self::LIMIARES_INATIVIDADE as $m) {
                if ($diasInativo >= $m) { $marco = $m; break; }
            }
            if ($marco === null) continue;

            // Emite o maior limiar, se ainda não foi emitido nesta sessão.
            // A chave embute o epoch do ultimo_login → logar de novo reabre tudo.
            $primeira = $this->reservar($cid, "inativo_{$marco}d:{$loginTs}", "inativo_{$marco}d");

            // Consome as chaves dos limiares MENORES nesta mesma sessão, para
            // não dispararem retroativamente depois (evita o 30d sair após o
            // 60d). Não impede o avanço: ao cruzar um limiar MAIOR no futuro,
            // aquela chave ainda estará livre.
            foreach (self::LIMIARES_INATIVIDADE as $menor) {
                if ($menor < $marco) {
                    $this->reservar($cid, "inativo_{$menor}d:{$loginTs}", "inativo_{$menor}d");
                }
            }

            if ($primeira) {
                $this->emitir($cid, "inativo_{$marco}d", null, null, [
                    'dias_inativo'     => $diasInativo,
                    'dias_marco'       => $marco,
                    'saldo_disponivel' => $this->moeda($row['saldo_disponivel']),
                ]);
                $n++;
            }
        }
        return $n;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SONDA: SALDO EXPIRANDO
    // ─────────────────────────────────────────────────────────────────────────
    // Crédito com expira_em dentro da janela, ainda não expirado, e cujo dono
    // ainda TEM saldo (não adianta avisar quem já gastou). Uma vez por
    // transação. Mostra o saldo ATUAL do cliente (honesto) + a data mais
    // próxima de expiração.

    private function sondaSaldoExpirando(): int
    {
        $dias   = max(1, (int)$this->getConfig('radar_saldo_expira_dias', '7'));
        $limite = date('Y-m-d H:i:s', time() + $dias * 86400);
        $agora  = date('Y-m-d H:i:s');

        $st = $this->db->prepare(
            "SELECT t.id AS transacao_id, t.cliente_id, t.valor, t.expira_em,
                    c.saldo_disponivel
             FROM cliente_saldo_transacoes t
             JOIN clientes c ON c.id = t.cliente_id
             WHERE t.expira_em IS NOT NULL
               AND t.expirado = 0
               AND t.tipo IN ('credito_devolucao','credito_manual','credito_promo')
               AND t.expira_em > :agora
               AND t.expira_em <= :limite
               AND c.saldo_disponivel >= 0.01
             ORDER BY t.expira_em ASC"
        );
        $st->execute([':agora' => $agora, ':limite' => $limite]);

        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid   = (int)$row['cliente_id'];
            $txId  = (int)$row['transacao_id'];
            $chave = "saldo_expirando:{$txId}";
            if (!$this->reservar($cid, $chave, 'saldo_expirando')) continue;

            $this->emitir($cid, 'saldo_expirando', 'transacao', $txId, [
                'saldo_expira_em'    => date('d/m/Y', strtotime((string)$row['expira_em'])),
                'saldo_expira_valor' => $this->moeda($row['valor']),
                'saldo_disponivel'   => $this->moeda($row['saldo_disponivel']),
            ]);
            $n++;
        }
        return $n;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EMISSÃO E ANTI-REPETIÇÃO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tenta reservar a chave (anti-repetição). @return bool true se é a
     * primeira vez (pode emitir); false se já foi emitido.
     */
    private function reservar(int $clienteId, string $chave, string $evento): bool
    {
        try {
            $st = $this->db->prepare(
                "INSERT IGNORE INTO cliente_radar_emissoes (cliente_id, chave, evento)
                 VALUES (:c, :k, :e)"
            );
            $st->execute([':c' => $clienteId, ':k' => mb_substr($chave, 0, 80), ':e' => mb_substr($evento, 0, 40)]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            $this->log('reservar', $e);
            return false; // na dúvida, não emite (evita duplicar)
        }
    }

    /** INSERT direto na tabela eventos (com token sentinela + cliente_id). */
    private function emitir(int $clienteId, string $tipo, ?string $entidadeTipo, ?int $entidadeId, array $contexto): void
    {
        $st = $this->db->prepare(
            "INSERT INTO eventos
             (visitante_token, cliente_id, sessao_id, tipo, entidade_tipo, entidade_id, contexto_json, criado_em)
             VALUES (:tok, :cid, 'radar', :tipo, :et, :ei, :ctx, NOW())"
        );
        $st->execute([
            ':tok'  => self::TOKEN,
            ':cid'  => $clienteId,
            ':tipo' => $tipo,
            ':et'   => $entidadeTipo,
            ':ei'   => $entidadeId,
            ':ctx'  => $contexto ? json_encode($contexto, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PURGE DAS EMISSÕES ANTIGAS (1×/dia)
    // ─────────────────────────────────────────────────────────────────────────

    private function purgarEmissoes(): int
    {
        $hoje = date('Y-m-d');
        if ($this->getConfig('radar_emissoes_purge_ultimo', '') === $hoje) return 0;

        $dias = (int)$this->getConfig('radar_emissoes_retencao', '400');
        if ($dias < 90) $dias = 400; // proteção contra config quebrada
        $corte = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

        $total = 0;
        for ($i = 0; $i < 20; $i++) {
            $st = $this->db->prepare("DELETE FROM cliente_radar_emissoes WHERE emitido_em < :c LIMIT 5000");
            $st->execute([':c' => $corte]);
            $apagou = $st->rowCount();
            $total += $apagou;
            if ($apagou < 5000) break;
        }
        $this->setConfig('radar_emissoes_purge_ultimo', $hoje);
        return $total;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /** Formata decimal como "R$ 1.234,56". */
    private function moeda($valor): string
    {
        return 'R$ ' . number_format((float)$valor, 2, ',', '.');
    }

    private function ehBissexto(int $ano): bool
    {
        return ($ano % 4 === 0 && $ano % 100 !== 0) || ($ano % 400 === 0);
    }

    private function getConfig(string $chave, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT valor FROM fluxo_motor_config WHERE chave=:k");
            $st->execute([':k' => $chave]);
            $v = $st->fetchColumn();
            return ($v !== false && $v !== null) ? (string)$v : $default;
        } catch (Throwable $e) { return $default; }
    }

    private function setConfig(string $chave, string $valor): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO fluxo_motor_config (chave, valor) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE valor = :v2"
            )->execute([':k' => $chave, ':v' => $valor, ':v2' => $valor]);
        } catch (Throwable $e) {}
    }

    private function log(string $onde, Throwable $e): void
    {
        if (class_exists('LogService')) {
            try { LogService::error("ClienteRadarService::$onde", ['erro' => $e->getMessage()]); } catch (Throwable $x) {}
        }
    }
}
