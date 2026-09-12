<?php
/**
 * Áreas de entrega, operação diária e feriados de uma transportadora.
 *
 * Nasceu da LogManager, que cota localmente: antes era `ceps_atendidos` (uma
 * string) mais `d1_valor_base` (um preço). Isso responde "atende?" e "quanto?",
 * mas não "quanto ALI" — e o bairro ao lado não custa o mesmo que atravessar a
 * região metropolitana.
 *
 * ── A separação que sustenta o módulo ─────────────────────────────────────
 *   ÁREA  = custo da transportadora    -> `log_cotacao_opcoes.valor_original`
 *   REGRA = política comercial da loja -> `valor_final` (MotorRegras)
 *
 * Se as duas virarem a mesma coisa, ninguém responde "o frete subiu por custo
 * ou por promoção?" — e é `valor_original` que a detecção de divergência usa
 * como previsto.
 *
 * ── Compatibilidade ───────────────────────────────────────────────────────
 * Sem nenhuma área cadastrada, `resolver()` devolve null e o adapter segue no
 * `ceps_atendidos` antigo. A migração é por transportadora e sem data.
 */
class AreaEntregaService
{
    public const BANDAS = [
        'proxima'  => 'Áreas próximas',
        'media'    => 'Áreas de distância média',
        'distante' => 'Áreas distantes',
    ];

    /** Nome curto de cada dia, na ordem do MySQL/PHP (0 = domingo). */
    public const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       GRAMÁTICA DE CEP (pura — a mesma de ceps_atendidos)
       ================================================================= */

    /**
     * O CEP cai em alguma das faixas?
     *
     * Cada item (separado por vírgula) pode ser prefixo ("90", "90230-000") ou
     * faixa ("90000000-91999999", "900 a 919"). É a mesma gramática que o
     * `LogManagerAdapter` já usava — vive aqui para existir uma só, e não duas
     * que envelhecem separadas.
     */
    public static function cepNaFaixa(string $cep, string $faixas): bool
    {
        $cep = preg_replace('/\D/', '', $cep) ?? '';
        if (strlen($cep) !== 8) return false;
        $n = (int) $cep;

        foreach (explode(',', $faixas) as $entrada) {
            $entrada = trim($entrada);
            if ($entrada === '') continue;

            [$ini, $fim] = self::faixa($entrada);
            if ($ini !== null) {
                if ($n >= $ini && $n <= $fim) return true;
            } else {
                $pref = preg_replace('/\D/', '', $entrada) ?? '';
                if ($pref !== '' && str_starts_with($cep, $pref)) return true;
            }
        }
        return false;
    }

    /**
     * Interpreta "A..B" como faixa. Devolve [iniInt, fimInt] ou [null, null]
     * quando é prefixo. O início completa com zeros e o fim com noves, então
     * "900 a 919" vira 90000000..91999999.
     *
     * @return array{0:?int,1:?int}
     */
    public static function faixa(string $entrada): array
    {
        $entrada = trim($entrada);
        if ($entrada === '') return [null, null];
        // CEP completo formatado é PREFIXO, não faixa — o "-" ali é do formato.
        if (preg_match('/^\d{5}-\d{3}$/', $entrada)) return [null, null];

        $partes = preg_split('/\s*(?:\.\.|:|–|—|-|\s+a\s+)\s*/u', $entrada);
        if (is_array($partes) && count($partes) === 2 && trim($partes[0]) !== '' && trim($partes[1]) !== '') {
            $a = preg_replace('/\D/', '', $partes[0]) ?? '';
            $b = preg_replace('/\D/', '', $partes[1]) ?? '';
            if ($a !== '' && $b !== '') {
                $ini = (int) str_pad(substr($a, 0, 8), 8, '0');
                $fim = (int) str_pad(substr($b, 0, 8), 8, '9');
                if ($fim < $ini) { [$ini, $fim] = [$fim, $ini]; }
                return [$ini, $fim];
            }
        }
        return [null, null];
    }

    /* =================================================================
       ÁREAS
       ================================================================= */

    /** @return array<int,array> áreas da transportadora, na ordem da tela. */
    public function areas(int $transportadoraId, bool $somenteAtivas = false): array
    {
        try {
            $sql = "SELECT * FROM log_areas_entrega WHERE transportadora_id = :t"
                 . ($somenteAtivas ? " AND ativa = 1" : "")
                 . " ORDER BY FIELD(banda,'proxima','media','distante'), ordem ASC, nome ASC";
            $st = $this->pdo->prepare($sql);
            $st->execute([':t' => $transportadoraId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar áreas de entrega', ['transportadora_id' => $transportadoraId, 'erro' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Qual área cobre este CEP.
     *
     * A PRIMEIRA que casar vence, na ordem da tela (próximas → médias →
     * distantes, e dentro da banda por `ordem`). Faixas sobrepostas são
     * normais — "Centro" mora dentro de "POA" — e a ordem é o desempate
     * explícito: a área mais específica fica antes.
     *
     * @return array|null a área, ou null quando nenhuma cobre (ou não há áreas)
     */
    public function resolver(int $transportadoraId, string $cep): ?array
    {
        foreach ($this->areas($transportadoraId, true) as $a) {
            if (self::cepNaFaixa($cep, (string) $a['faixas_cep'])) return $a;
        }
        return null;
    }

    /** @return array{ok:bool,id?:int,erros?:array<string,string>} */
    public function salvarArea(array $d, ?int $usuarioId = null): array
    {
        $erros = self::validarArea($d);
        if ($erros) return ['ok' => false, 'erros' => $erros];

        $id = (int) ($d['id'] ?? 0);
        $campos = [
            'transportadora_id' => (int) $d['transportadora_id'],
            'nome'           => trim((string) $d['nome']),
            'banda'          => isset(self::BANDAS[$d['banda'] ?? '']) ? $d['banda'] : 'proxima',
            'faixas_cep'     => self::normalizarFaixas((string) $d['faixas_cep']),
            'valor_base'     => round((float) str_replace(',', '.', (string) ($d['valor_base'] ?? 0)), 2),
            'prazo_dias'     => self::intOuNulo($d['prazo_dias'] ?? null, 0, 60),
            'cutoff_hora'    => self::intOuNulo($d['cutoff_hora'] ?? null, 0, 23),
            'max_envios_dia' => self::intOuNulo($d['max_envios_dia'] ?? null, 0, 9999),
            'ativa'          => !empty($d['ativa']) ? 1 : 0,
            'ordem'          => (int) ($d['ordem'] ?? 100),
            'mapa_lat'       => self::floatOuNulo($d['mapa_lat'] ?? null, -90, 90),
            'mapa_lng'       => self::floatOuNulo($d['mapa_lng'] ?? null, -180, 180),
            'mapa_raio_km'   => self::floatOuNulo($d['mapa_raio_km'] ?? null, 0.1, 500),
        ];

        try {
            if ($id > 0) {
                $sets = implode(', ', array_map(static fn($k) => "`$k` = :$k", array_keys($campos)));
                $st = $this->pdo->prepare("UPDATE log_areas_entrega SET $sets WHERE id = :id");
                $st->execute($campos + ['id' => $id]);
            } else {
                $cols = implode('`,`', array_keys($campos));
                $vals = implode(',:', array_keys($campos));
                $st = $this->pdo->prepare("INSERT INTO log_areas_entrega (`$cols`) VALUES (:$vals)");
                $st->execute($campos);
                $id = (int) $this->pdo->lastInsertId();
            }
        } catch (\Throwable $e) {
            LogService::error('Falha ao salvar área de entrega', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar a área.'];
        }

        FreteCacheService::invalidar(); // a área mexe no preço cotado
        LogService::audit('Área de entrega salva', ['area_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'id' => $id];
    }

    public function excluirArea(int $id, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->prepare("DELETE FROM log_areas_entrega WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao excluir área de entrega', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao excluir a área.'];
        }
        FreteCacheService::invalidar();
        LogService::audit('Área de entrega excluída', ['area_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /** Liga/desliga sem abrir o formulário (o checkbox da lista). */
    public function alternarArea(int $id, bool $ativa, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->prepare("UPDATE log_areas_entrega SET ativa = :a WHERE id = :id")
                      ->execute([':a' => $ativa ? 1 : 0, ':id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'erro' => 'Não foi possível alterar a área.'];
        }
        FreteCacheService::invalidar();
        LogService::audit('Área de entrega alternada', ['area_id' => $id, 'ativa' => $ativa, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'ativa' => $ativa];
    }

    /** @return array<string,string> campo => erro (vazio = ok) */
    public static function validarArea(array $d): array
    {
        $e = [];
        if ((int) ($d['transportadora_id'] ?? 0) <= 0) $e['transportadora_id'] = 'Transportadora inválida.';

        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') $e['nome'] = 'Dê um nome à área.';
        elseif (mb_strlen($nome) > 120) $e['nome'] = 'Nome muito longo (máx. 120).';

        $faixas = self::normalizarFaixas((string) ($d['faixas_cep'] ?? ''));
        if ($faixas === '') {
            $e['faixas_cep'] = 'Informe ao menos um CEP ou faixa.';
        } else {
            // Faixa que não casa com CEP nenhum é área morta: o operador
            // acha que cadastrou cobertura e o checkout nunca oferece.
            $valida = false;
            foreach (explode(',', $faixas) as $p) {
                $p = trim($p);
                if ($p === '') continue;
                [$ini] = self::faixa($p);
                if ($ini !== null || preg_replace('/\D/', '', $p) !== '') { $valida = true; break; }
            }
            if (!$valida) $e['faixas_cep'] = 'Nenhuma faixa reconhecida. Use prefixos (90) ou faixas (90000000-91999999).';
        }

        $valor = (float) str_replace(',', '.', (string) ($d['valor_base'] ?? 0));
        if ($valor < 0) $e['valor_base'] = 'O custo não pode ser negativo.';

        return $e;
    }

    /** Tira espaço sobrando e itens vazios, mantendo a ordem digitada. */
    public static function normalizarFaixas(string $bruto): string
    {
        $itens = array_filter(array_map('trim', explode(',', $bruto)), static fn($s) => $s !== '');
        return implode(', ', $itens);
    }

    /* =================================================================
       OPERAÇÃO DIÁRIA
       ================================================================= */

    /**
     * Os 7 dias, sempre. Dia sem linha no banco volta com o padrão — a tela
     * nunca precisa lidar com "e se faltar a quarta-feira".
     *
     * @return array<int,array> indexado por dia_semana (0..6)
     */
    public function operacao(int $transportadoraId): array
    {
        $linhas = [];
        try {
            $st = $this->pdo->prepare("SELECT * FROM log_transportadora_operacao WHERE transportadora_id = :t");
            $st->execute([':t' => $transportadoraId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) $linhas[(int) $l['dia_semana']] = $l;
        } catch (\Throwable $e) {
            LogService::error('Falha ao ler operação da transportadora', ['erro' => $e->getMessage()]);
        }

        $out = [];
        for ($d = 0; $d <= 6; $d++) {
            $out[$d] = $linhas[$d] ?? [
                'transportadora_id' => $transportadoraId,
                'dia_semana'     => $d,
                'opera'          => ($d >= 1 && $d <= 5) ? 1 : 0, // útil por padrão
                'mesmo_dia'      => 1,
                'cutoff_hora'    => null,
                'entrega_inicio' => null,
                'entrega_fim'    => null,
                'max_envios'     => null,
            ];
            $out[$d]['dia_nome'] = self::DIAS[$d];
        }
        return $out;
    }

    /**
     * Grava os dias recebidos. Aceita 1 ou 7 — a tela manda os cinco dias
     * úteis de uma vez quando o operador edita a linha "Segunda a Sexta".
     */
    public function salvarOperacao(int $transportadoraId, array $dias, ?int $usuarioId = null): array
    {
        try {
            $this->pdo->beginTransaction();
            $st = $this->pdo->prepare(
                "INSERT INTO log_transportadora_operacao
                   (transportadora_id, dia_semana, opera, mesmo_dia, cutoff_hora, entrega_inicio, entrega_fim, max_envios)
                 VALUES (:t,:d,:op,:md,:co,:ei,:ef,:mx)
                 ON DUPLICATE KEY UPDATE
                   opera=VALUES(opera), mesmo_dia=VALUES(mesmo_dia), cutoff_hora=VALUES(cutoff_hora),
                   entrega_inicio=VALUES(entrega_inicio), entrega_fim=VALUES(entrega_fim), max_envios=VALUES(max_envios)"
            );
            foreach ($dias as $d) {
                $dia = (int) ($d['dia_semana'] ?? -1);
                if ($dia < 0 || $dia > 6) continue;
                $st->execute([
                    ':t'  => $transportadoraId,
                    ':d'  => $dia,
                    ':op' => !empty($d['opera']) ? 1 : 0,
                    ':md' => !empty($d['mesmo_dia']) ? 1 : 0,
                    ':co' => self::intOuNulo($d['cutoff_hora'] ?? null, 0, 23),
                    ':ei' => self::horaOuNulo($d['entrega_inicio'] ?? null),
                    ':ef' => self::horaOuNulo($d['entrega_fim'] ?? null),
                    ':mx' => self::intOuNulo($d['max_envios'] ?? null, 0, 9999),
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            LogService::error('Falha ao salvar operação da transportadora', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar a operação.'];
        }
        FreteCacheService::invalidar(); // o corte muda o prazo cotado
        LogService::audit('Operação de transportadora salva', ['transportadora_id' => $transportadoraId, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /* =================================================================
       FERIADOS
       ================================================================= */

    /**
     * Próximos feriados + a decisão já tomada para cada um.
     *
     * O calendário é calculado (FeriadosBR); o banco guarda só a exceção. Por
     * isso a lista não precisa ser realimentada todo ano.
     */
    public function feriados(int $transportadoraId, int $quantos = 4, string $uf = 'RS'): array
    {
        $lista = FeriadosBR::proximos($quantos, $uf);
        $decidido = [];
        try {
            $st = $this->pdo->prepare("SELECT `data`, opera FROM log_transportadora_feriados WHERE transportadora_id = :t");
            $st->execute([':t' => $transportadoraId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $f) $decidido[$f['data']] = (int) $f['opera'];
        } catch (\Throwable $e) {
            LogService::error('Falha ao ler feriados da transportadora', ['erro' => $e->getMessage()]);
        }

        foreach ($lista as &$f) {
            $f['opera'] = $decidido[$f['data']] ?? 0; // não operar é o padrão seguro
        }
        return $lista;
    }

    public function salvarFeriado(int $transportadoraId, string $data, bool $opera, ?int $usuarioId = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            return ['ok' => false, 'erro' => 'Data inválida.'];
        }
        try {
            $this->pdo->prepare(
                "INSERT INTO log_transportadora_feriados (transportadora_id, `data`, opera)
                 VALUES (:t,:d,:o) ON DUPLICATE KEY UPDATE opera = VALUES(opera)"
            )->execute([':t' => $transportadoraId, ':d' => $data, ':o' => $opera ? 1 : 0]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao salvar feriado', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar o feriado.'];
        }
        FreteCacheService::invalidar();
        LogService::audit('Feriado de transportadora salvo', ['transportadora_id' => $transportadoraId, 'data' => $data, 'opera' => $opera, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /* =================================================================
       LIMITES DO ENVIO
       ================================================================= */

    /** Teto de dimensões e peso por envio, do `config` da transportadora. */
    public function limites(int $transportadoraId): array
    {
        $cfg = [];
        try {
            $st = $this->pdo->prepare("SELECT config FROM log_transportadoras WHERE id = :t");
            $st->execute([':t' => $transportadoraId]);
            $cfg = json_decode((string) $st->fetchColumn(), true) ?: [];
        } catch (\Throwable $e) { /* devolve os vazios abaixo */ }

        return [
            'largura_cm'       => $cfg['dim_max_largura_cm'] ?? '',
            'altura_cm'        => $cfg['dim_max_altura_cm'] ?? '',
            'profundidade_cm'  => $cfg['dim_max_profundidade_cm'] ?? '',
            'peso_kg'          => $cfg['dim_max_peso_kg'] ?? '',
        ];
    }

    /**
     * Grava os limites no `config`, preservando o resto.
     *
     * Vai no JSON e não em coluna porque é teto de exibição — o que de fato
     * bloqueia um envio é a embalagem escolhida (`log_embalagens`). Coluna
     * nova aqui sugeriria uma validação que não existe.
     */
    public function salvarLimites(int $transportadoraId, array $d, ?int $usuarioId = null): array
    {
        try {
            $st = $this->pdo->prepare("SELECT config FROM log_transportadoras WHERE id = :t");
            $st->execute([':t' => $transportadoraId]);
            $cfg = json_decode((string) $st->fetchColumn(), true) ?: [];

            foreach ([
                'dim_max_largura_cm'      => 'largura_cm',
                'dim_max_altura_cm'       => 'altura_cm',
                'dim_max_profundidade_cm' => 'profundidade_cm',
                'dim_max_peso_kg'         => 'peso_kg',
            ] as $chave => $campo) {
                $v = trim((string) ($d[$campo] ?? ''));
                if ($v === '') { unset($cfg[$chave]); continue; }
                $n = (float) str_replace(',', '.', $v);
                if ($n > 0) $cfg[$chave] = round($n, 2);
            }

            $this->pdo->prepare("UPDATE log_transportadoras SET config = :c WHERE id = :t")
                      ->execute([':c' => json_encode($cfg, JSON_UNESCAPED_UNICODE), ':t' => $transportadoraId]);
        } catch (\Throwable $e) {
            LogService::error('Falha ao salvar limites de envio', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'erro' => 'Erro ao salvar os limites.'];
        }
        LogService::audit('Limites de envio salvos', ['transportadora_id' => $transportadoraId, 'usuario_id' => $usuarioId]);
        return ['ok' => true];
    }

    /* =================================================================
       RESUMO (os cartões do topo)
       ================================================================= */

    /**
     * @return array{areas_ativas:int,areas_total:int,exposicao:string,max_util:?int,max_sabado:?int,envios_hoje:int,opera_hoje:bool,motivo:?string}
     */
    public function resumo(int $transportadoraId, ?string $agora = null): array
    {
        $agora = $agora ?: date('Y-m-d H:i:s');
        $hoje  = substr($agora, 0, 10);
        $dow   = (int) date('w', strtotime($hoje));
        $hora  = (int) date('H', strtotime($agora));

        $areas = $this->areas($transportadoraId);
        $ativas = array_filter($areas, static fn($a) => (int) $a['ativa'] === 1);

        $op = $this->operacao($transportadoraId);
        $dia = $op[$dow];

        $motivo = null;
        $opera = (int) $dia['opera'] === 1;
        if (!$opera) {
            $motivo = 'Não opera ' . mb_strtolower(self::DIAS[$dow]);
        } else {
            $nomeFeriado = FeriadosBR::nomeDe($hoje);
            if ($nomeFeriado !== null) {
                $dec = $this->feriados($transportadoraId, 8);
                foreach ($dec as $f) {
                    if ($f['data'] === $hoje && (int) $f['opera'] !== 1) {
                        $opera = false;
                        $motivo = 'Feriado: ' . $nomeFeriado;
                    }
                }
            }
        }

        // "Chegará hoje" só vale dentro do corte e num dia que opera.
        $corte = $dia['cutoff_hora'] !== null ? (int) $dia['cutoff_hora'] : null;
        $exposicao = 'Sem entrega hoje';
        if ($opera && (int) $dia['mesmo_dia'] === 1) {
            $exposicao = ($corte === null || $hora < $corte) ? 'Chegará hoje' : 'Chegará amanhã';
        } elseif ($opera) {
            $exposicao = 'Chegará amanhã';
        }

        return [
            'areas_ativas' => count($ativas),
            'areas_total'  => count($areas),
            'exposicao'    => $exposicao,
            'max_util'     => $op[1]['max_envios'] !== null ? (int) $op[1]['max_envios'] : null,
            'max_sabado'   => $op[6]['max_envios'] !== null ? (int) $op[6]['max_envios'] : null,
            'envios_hoje'  => $this->enviosHoje($transportadoraId, $hoje),
            'opera_hoje'   => $opera,
            'motivo'       => $motivo,
        ];
    }

    /** Etiquetas criadas hoje para esta transportadora — base do teto diário. */
    public function enviosHoje(int $transportadoraId, ?string $dia = null): int
    {
        $dia = $dia ?: date('Y-m-d');
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM log_etiquetas
                  WHERE transportadora_id = :t AND DATE(criado_em) = :d AND status <> 'cancelada'"
            );
            $st->execute([':t' => $transportadoraId, ':d' => $dia]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* =================================================================
       Helpers privados
       ================================================================= */

    private static function intOuNulo($v, int $min, int $max): ?int
    {
        if ($v === null || $v === '' || $v === false) return null;
        $n = (int) $v;
        return ($n < $min || $n > $max) ? null : $n;
    }

    private static function floatOuNulo($v, float $min, float $max): ?float
    {
        if ($v === null || $v === '' || $v === false) return null;
        $n = (float) str_replace(',', '.', (string) $v);
        return ($n < $min || $n > $max) ? null : round($n, 7);
    }

    private static function horaOuNulo($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v) ? substr($v, 0, 5) . ':00' : null;
    }
}
