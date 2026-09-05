<?php
declare(strict_types=1);

/**
 * app/services/BiEventoService.php — o BI publicando no stream de eventos.
 *
 * Fase C dos agentes de IA: em vez de regras fixas no worker ("alerta
 * crítico → agente → sino"), o que o sistema detecta vira EVENTO em
 * `eventos`, e o motor de automação v2 (fluxo-worker + canvas) decide o
 * que fazer com ele — o mesmo caminho de `produto_visto` ou `aniversario`.
 *
 * Três famílias de evento, todas SEM pessoa (cliente_id NULL):
 *
 *   bi_alerta_critico / bi_alerta_alto / bi_alerta_medio
 *       um por alerta de BiService::alertas() por dia; contexto traz
 *       nivel, titulo, detalhe, gatilho (o mesmo do ia-agentes-worker,
 *       para a dedup dos dois lados casar) e agente_sugerido.
 *
 *   bi_meta_risco
 *       meta em curso cujo % atingido está METAS_ATRASO_PONTOS ou mais
 *       abaixo do % de tempo decorrido. Um por meta por dia.
 *
 *   agenda_06h … agenda_18h
 *       o relógio como evento: publicado uma vez por dia assim que a hora
 *       passa. É o "cron" editável no canvas.
 *
 * Regras que evitam lixo e custo:
 *   - só publica tipos que algum FLUXO PUBLICADO escuta (ouvintes());
 *   - alertas e metas são lidos pelo gateway dos agentes (cache de 15 min,
 *     sem nome de cliente — LGPD) e no máximo a cada THROTTLE_S;
 *   - visitante_token (NOT NULL na tabela) é sintético e único por
 *     tipo+chave+dia: é a dedup do publicador E a chave da reentrada do
 *     motor — "nunca" ainda deixa o mesmo alerta voltar no dia seguinte.
 */
class BiEventoService
{
    public const ALERTAS = ['critico' => 'bi_alerta_critico', 'alto' => 'bi_alerta_alto', 'medio' => 'bi_alerta_medio'];
    public const META_RISCO = 'bi_meta_risco';
    public const HORAS_AGENDA = [6, 7, 8, 12, 18];

    /** Alertas/metas: intervalo mínimo entre leituras do BI (segundos). */
    public const THROTTLE_S = 900;
    /** Meta "em risco" = pct atingido abaixo do pct de tempo decorrido por esta margem. */
    public const METAS_ATRASO_PONTOS = 10;
    /** Período que o BI olha para os alertas (mesmo do ia-agentes-worker). */
    public const PERIODO = '30d';

    private const CFG_ULTIMO_SCAN = 'bi_eventos_ultimo_scan';

    private PDO $db;
    private IAAgenteGateway $gw;

    public function __construct(?IAAgenteGateway $gw = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->gw = $gw ?? new IAAgenteGateway();
    }

    /* ------------------------------------------------------------------ */
    /* Catálogo                                                            */
    /* ------------------------------------------------------------------ */

    /** tipo => rótulo, na ordem da paleta do canvas e do diagnóstico. */
    public static function tipos(): array
    {
        $t = [
            self::ALERTAS['critico'] => 'Alerta crítico do BI',
            self::ALERTAS['alto']    => 'Alerta alto do BI',
            self::ALERTAS['medio']   => 'Alerta médio do BI',
            self::META_RISCO         => 'Meta em risco',
        ];
        foreach (self::HORAS_AGENDA as $h) $t[self::tipoAgenda($h)] = sprintf('Todo dia às %02dh', $h);
        return $t;
    }

    public static function tipoAgenda(int $hora): string
    {
        return sprintf('agenda_%02dh', $hora);
    }

    /** Evento do sistema (sem pessoa): o trigger não deve exigir "apenas logados". */
    public static function ehSistema(string $tipo): bool
    {
        return str_starts_with($tipo, 'bi_') || str_starts_with($tipo, 'agenda_');
    }

    /* ------------------------------------------------------------------ */
    /* Quem escuta                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Fluxos publicados cujo trigger_evento escuta o tipo.
     * @return array<int, array{id:int, nome:string}>
     */
    public static function fluxosPublicados(string $tipo): array
    {
        return self::ouvintes()[$tipo] ?? [];
    }

    /**
     * Tipos de evento do BI/agenda com pelo menos um fluxo publicado.
     * @return array<string, array<int, array{id:int, nome:string}>> tipo => fluxos
     */
    public static function ouvintes(): array
    {
        $db = Database::getInstance()->getConnection();
        try {
            $st = $db->query(
                "SELECT f.id, f.nome, n.config_json
                   FROM fluxo_v2 f
                   JOIN fluxo_nos n ON n.fluxo_id = f.id
                                   AND n.versao = f.versao_publicada
                                   AND n.tipo_no = 'trigger_evento'
                  WHERE f.status = 'publicado' AND f.versao_publicada >= 1"
            );
        } catch (\PDOException $e) {
            return []; // motor v2 ainda não instalado
        }
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cfg    = json_decode($r['config_json'] ?? '{}', true) ?: [];
            $evento = (string) ($cfg['evento'] ?? '');
            if ($evento === '' || !self::ehSistema($evento)) continue;
            $out[$evento][] = ['id' => (int) $r['id'], 'nome' => (string) $r['nome']];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Publicação                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Uma rodada do publicador (chamada pelo fluxo-worker a cada minuto).
     * `$forcar` ignora a lista de ouvintes e o throttle — para o
     * diagnóstico e os testes, nunca para o cron.
     */
    public function publicar(bool $forcar = false): array
    {
        $stats = ['ok' => true, 'agenda' => 0, 'alertas' => 0, 'metas' => 0, 'pulado' => null, 'ouvintes' => []];

        $ouvintes = $forcar ? array_keys(self::tipos()) : array_keys(self::ouvintes());
        $stats['ouvintes'] = $ouvintes;
        if (!$ouvintes) {
            $stats['pulado'] = 'sem_fluxos';
            return $stats;
        }

        // Relógio: barato (uma consulta de dedup por hora escutada), roda sempre.
        foreach (self::HORAS_AGENDA as $h) {
            $tipo = self::tipoAgenda($h);
            if (!in_array($tipo, $ouvintes, true) || (int) date('G') < $h) continue;
            if ($this->publicarEvento($tipo, 'dia', ['hora' => $h, 'data' => date('d/m/Y')]) !== null) {
                $stats['agenda']++;
            }
        }

        // Alertas e metas: leitura do BI, no máximo a cada THROTTLE_S.
        $escutaAlerta = (bool) array_intersect(array_values(self::ALERTAS), $ouvintes);
        $escutaMeta   = in_array(self::META_RISCO, $ouvintes, true);
        if (!$escutaAlerta && !$escutaMeta) return $stats;

        $ultimo = (int) $this->getConfig(self::CFG_ULTIMO_SCAN, '0');
        if (!$forcar && (time() - $ultimo) < self::THROTTLE_S) {
            $stats['pulado'] = 'throttle';
            return $stats;
        }
        $this->setConfig(self::CFG_ULTIMO_SCAN, (string) time());

        if ($escutaAlerta) $stats['alertas'] = $this->publicarAlertas($ouvintes);
        if ($escutaMeta)   $stats['metas']   = $this->publicarMetas();

        return $stats;
    }

    /**
     * Grava um evento do sistema, uma vez por tipo+chave+dia.
     * @return int|null id do evento, null se já existia hoje
     */
    public function publicarEvento(string $tipo, string $chave, array $ctx): ?int
    {
        $tipo  = mb_substr($tipo, 0, 40);
        $token = $this->tokenDoDia($tipo, $chave);

        $st = $this->db->prepare(
            "SELECT 1 FROM eventos WHERE tipo = :t AND visitante_token = :tok AND criado_em >= CURDATE() LIMIT 1"
        );
        $st->execute([':t' => $tipo, ':tok' => $token]);
        if ($st->fetchColumn()) return null;

        // Só escalares entram: é o que FluxoTriggerService::contextoDoEvento
        // expõe como {{vars}} aos nós do fluxo.
        $ctx = array_filter($ctx, fn($v) => is_scalar($v) || $v === null);

        $ins = $this->db->prepare(
            "INSERT INTO eventos (visitante_token, cliente_id, sessao_id, tipo, entidade_tipo, entidade_id, contexto_json)
             VALUES (:tok, NULL, NULL, :t, 'bi', NULL, :ctx)"
        );
        $ins->execute([':tok' => $token, ':t' => $tipo,
                       ':ctx' => json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return (int) $this->db->lastInsertId() ?: null;
    }

    /** char(32) NOT NULL na tabela; único por tipo+chave+dia. */
    public function tokenDoDia(string $tipo, string $chave): string
    {
        return md5('bi|' . $tipo . '|' . $chave . '|' . date('Y-m-d'));
    }

    /* ------------------------------------------------------------------ */
    /* Fontes                                                               */
    /* ------------------------------------------------------------------ */

    private function publicarAlertas(array $ouvintes): int
    {
        $al = $this->gw->executar('consultar_alertas', ['periodo' => self::PERIODO], ['consultar_alertas']);
        if (!$al['ok']) return 0;

        $n = 0;
        foreach (($al['dados']['alertas'] ?? []) as $a) {
            $nivel = (string) ($a['nivel'] ?? '');
            $tipo  = self::ALERTAS[$nivel] ?? null;
            if ($tipo === null || !in_array($tipo, $ouvintes, true)) continue;

            $titulo  = trim((string) ($a['titulo'] ?? ''));
            if ($titulo === '') continue;
            $gatilho = 'alerta:' . sha1($titulo);   // idêntico ao IAAgenteService::rodarPorEvento

            $id = $this->publicarEvento($tipo, $gatilho, [
                'nivel'           => $nivel,
                'titulo'          => $titulo,
                'detalhe'         => mb_substr((string) ($a['detalhe'] ?? ''), 0, 300),
                'gatilho'         => $gatilho,
                'agente_sugerido' => class_exists('IAAgenteService') ? IAAgenteService::agenteDoAlerta($titulo) : '',
            ]);
            if ($id !== null) $n++;
        }
        return $n;
    }

    private function publicarMetas(): int
    {
        $m = $this->gw->executar('consultar_metas', ['periodo' => self::PERIODO], ['consultar_metas']);
        if (!$m['ok']) return 0;

        $hoje = new DateTimeImmutable('today');
        $n = 0;
        foreach (($m['dados']['metas'] ?? []) as $meta) {
            $pct = $meta['pct'] ?? null;
            if ($pct === null || empty($meta['periodo_ini']) || empty($meta['periodo_fim'])) continue;

            $ini = new DateTimeImmutable((string) $meta['periodo_ini']);
            $fim = new DateTimeImmutable((string) $meta['periodo_fim']);
            if ($hoje < $ini || $hoje > $fim) continue;            // só meta em curso

            $total     = max(1, $ini->diff($fim)->days + 1);
            $decorrido = $ini->diff($hoje)->days + 1;
            $esperado  = round(100 * $decorrido / $total, 1);
            if ((float) $pct >= $esperado - self::METAS_ATRASO_PONTOS) continue;

            $alvo = trim((string) ($meta['alvo_label'] ?? $meta['dimensao_valor'] ?? $meta['dimensao'] ?? 'loja'));
            $id = $this->publicarEvento(self::META_RISCO, 'meta:' . (int) ($meta['meta_id'] ?? 0), [
                'meta_id'      => (int) ($meta['meta_id'] ?? 0),
                'metrica'      => (string) ($meta['metrica'] ?? ''),
                'alvo'         => $alvo,
                'valor_meta'   => (float) ($meta['valor_meta'] ?? 0),
                'realizado'    => round((float) ($meta['realizado'] ?? 0), 2),
                'pct'          => (float) $pct,
                'esperado_pct' => $esperado,
                'falta'        => round((float) ($meta['falta'] ?? 0), 2),
                'periodo_fim'  => (string) $meta['periodo_fim'],
                'titulo'       => sprintf('Meta de %s (%s) em %s%% — esperado %s%%', $meta['metrica'] ?? '?', $alvo, $pct, $esperado),
                'gatilho'      => 'meta:' . (int) ($meta['meta_id'] ?? 0),
            ]);
            if ($id !== null) $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------ */
    /* Config do motor (mesma tabela do FluxoTriggerService)                */
    /* ------------------------------------------------------------------ */

    private function getConfig(string $chave, string $default): string
    {
        try {
            $st = $this->db->prepare("SELECT valor FROM fluxo_motor_config WHERE chave = :k");
            $st->execute([':k' => $chave]);
            $v = $st->fetchColumn();
            return $v !== false && $v !== null ? (string) $v : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function setConfig(string $chave, string $valor): void
    {
        $this->db->prepare(
            "INSERT INTO fluxo_motor_config (chave, valor) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE valor = :v2"
        )->execute([':k' => $chave, ':v' => $valor, ':v2' => $valor]);
    }
}
