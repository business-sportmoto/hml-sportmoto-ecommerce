<?php
declare(strict_types=1);

/**
 * IAAgenteService — a regra de negócio dos agentes de BI.
 *
 * Uma pergunta vira: (1) o contexto da página com as ferramentas padrão
 * já executadas pelo gateway — sem IA —, (2) UMA geração em ia_geracoes,
 * (3) o loop de tool use no orquestrador, (4) a conversa persistida,
 * (5) a resposta em seções, com procedência e a guarda de números.
 *
 * O que este serviço NÃO faz: SQL, cálculo, escolha de coluna. Tudo
 * isso é do gateway e do BiService. Aqui mora só a conversa.
 *
 * Injeção por construtor (padrão do projeto): em produção, new
 * IAAgenteService(); nos testes, um orquestrador com adapter fake — o
 * loop inteiro roda sem gastar um token.
 */
class IAAgenteService
{
    /** Tamanho máximo de uma pergunta. Acima disso é texto colado, não pergunta. */
    private const PERGUNTA_MAX = 1000;

    /** Mensagens do histórico reenviadas ao modelo (turnos user+assistant). */
    private const HISTORICO_MAX = 12;

    public const SECOES = ['RESUMO', 'INDICADORES', 'CAUSAS PROVÁVEIS', 'IMPACTO', 'RECOMENDAÇÕES', 'PRIORIDADE'];

    /** Perguntas de aprofundamento — valem para qualquer agente, no 2º turno. */
    private const APROFUNDAR = [
        'E por marca?', 'E por categoria?', 'E por canal?', 'Qual o impacto em reais?',
        'Quais os 5 produtos que mais explicam isso?', 'O que você recomenda fazer primeiro?',
        'Que dado eu precisaria cadastrar para completar essa análise?', 'Isso é tendência ou um mês fora da curva?',
    ];

    /**
     * O que o drawer precisa de cada agente ativo: nome, rótulo, sugestões,
     * perguntas por tema. Vem do catálogo (ia_agentes) — editável na
     * Central, sem código.
     * @return array<string, array{nome:string, curto:string, sugestoes:string[], perguntas:array, aprofundar:string[]}>
     */
    public static function catalogoParaTela(): array
    {
        $out = [];
        foreach (IAAgenteGateway::agentes() as $codigo => $a) {
            $out[$codigo] = [
                'nome'       => (string) $a['nome_exibicao'],
                'curto'      => (string) $a['rotulo_curto'],
                'sugestoes'  => array_values(array_filter($a['sugestoes'], 'is_string')),
                'perguntas'  => array_values(array_filter($a['perguntas'], fn($g) => is_array($g) && isset($g['tema'], $g['itens']))),
                'aprofundar' => self::APROFUNDAR,
            ];
        }
        return $out;
    }

    private IAOrchestrator    $orq;
    private IAAgenteGateway   $gw;
    private IACustoService    $custo;
    private IAAgenteConversa  $conversas;

    public function __construct(?IAOrchestrator $orq = null, ?IAAgenteGateway $gw = null)
    {
        $this->orq       = $orq ?? new IAOrchestrator();
        $this->gw        = $gw  ?? new IAAgenteGateway();
        $this->custo     = new IACustoService();
        $this->conversas = new IAAgenteConversa();
    }

    /* ------------------------------------------------------------------ */
    /* Catálogo                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * O agente pronto para o orquestrador: os campos do tipo de conteúdo
     * (id, instrucoes_sistema, max_tokens, modelo_id, nome) mais a
     * whitelist, o effort e o resto do catálogo. null = inexistente/inativo.
     */
    public function agente(string $codigo): ?array
    {
        $a = IAAgenteGateway::agentes()[$codigo] ?? null;
        if ($a === null) return null;

        return [
            'id'                 => (int) $a['tipo_conteudo_id'],
            'codigo'             => $a['codigo'],
            'nome'               => (string) $a['tipo_nome'],
            'instrucoes_sistema' => $a['instrucoes_sistema'],
            'max_tokens'         => (int) ($a['max_tokens'] ?: 2500),
            'modelo_id'          => $a['modelo_id'],
            'effort'             => in_array($a['effort'] ?? '', IAAgente::EFFORTS, true) ? $a['effort'] : 'medium',
            'whitelist'          => array_values(array_filter($a['ferramentas'], 'is_string')),
            'nome_exibicao'      => (string) $a['nome_exibicao'],
            'pergunta_agendada'  => $a['pergunta_agendada'],
            'pagina_agendada'    => $a['pagina_agendada'],
            'agendado_ativo'     => (int) $a['agendado_ativo'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Perguntar                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @param string      $agente       código do tipo (agente_financeiro…)
     * @param string      $pergunta     texto do usuário
     * @param array       $ctxPagina    ['pagina'=>'rentabilidade','periodo'=>'30d', ...filtros]
     * @param string|null $conversaUuid continua uma conversa existente
     * @param int|null    $usuarioId    usuarios.id; null = sistema (agendado/evento)
     * @param string      $modo         tempo_real | agendado | evento
     * @return array ok, msg?, conversa_uuid, resposta, secoes, prioridade, procedencia, numeros_sem_origem
     */
    public function perguntar(string $agente, string $pergunta, array $ctxPagina, ?string $conversaUuid, ?int $usuarioId, string $modo = 'tempo_real'): array
    {
        $pergunta = trim($pergunta);
        if ($pergunta === '') {
            return ['ok' => false, 'msg' => 'Escreva uma pergunta.'];
        }
        if (mb_strlen($pergunta) > self::PERGUNTA_MAX) {
            return ['ok' => false, 'msg' => 'Pergunta longa demais (máximo ' . self::PERGUNTA_MAX . ' caracteres).'];
        }

        $tipo = $this->agente($agente);
        if ($tipo === null) {
            return ['ok' => false, 'msg' => 'Agente desconhecido ou inativo.'];
        }
        $whitelist = $tipo['whitelist'];

        $pagina  = isset($ctxPagina['pagina']) ? preg_replace('/[^a-z_]/', '', (string) $ctxPagina['pagina']) : null;
        $periodo = in_array($ctxPagina['periodo'] ?? '', IAAgenteGateway::PERIODOS, true) ? (string) $ctxPagina['periodo'] : '30d';

        // ── Conversa: continua ou nasce ────────────────────────────────
        $conversa = null;
        if ($conversaUuid !== null && $conversaUuid !== '') {
            $conversa = $this->conversas->buscarPorUuid($conversaUuid);
            // 404 uniforme: conversa de outra pessoa ou de outro agente
            // "não existe" — confirmar que existe já vaza (CLAUDE.md §4.7).
            if ($conversa === null
                || $conversa['agente'] !== $agente
                || ($conversa['usuario_id'] !== null && (int) $conversa['usuario_id'] !== (int) $usuarioId)) {
                return ['ok' => false, 'msg' => 'Conversa não encontrada.'];
            }
        }
        $primeiroTurno = ($conversa === null);

        // ── Pré-carga: as ferramentas padrão da página, sem IA ────────
        $preCarregadas = [];
        if ($primeiroTurno) {
            foreach ($this->gw->padraoDaPagina((string) $pagina, $periodo) as [$nome, $params]) {
                if (!in_array($nome, $whitelist, true)) continue;
                $ex = $this->gw->executar($nome, $params, $whitelist);
                if ($ex['ok']) $preCarregadas[] = $ex;
            }
        }

        // ── Mensagens: histórico + turno atual ────────────────────────
        $mensagens = [];
        if (!$primeiroTurno) {
            $mensagens = $this->historicoParaModelo((int) $conversa['id']);
        }
        $textoUser = $primeiroTurno
            ? $this->primeiraMensagem($ctxPagina, $pagina, $periodo, $preCarregadas, $pergunta)
            : $this->mensagemSeguinte($conversa, $periodo, $pergunta);
        $mensagens[] = ['role' => 'user', 'content' => $textoUser];

        $ferramentasDefs = $this->gw->definicoes($whitelist);

        // ── Limites: gerais + o teto próprio dos agentes ──────────────
        $chars = mb_strlen((string) $tipo['instrucoes_sistema'])
               + mb_strlen(json_encode($mensagens, JSON_UNESCAPED_UNICODE))
               + mb_strlen(json_encode($ferramentasDefs, JSON_UNESCAPED_UNICODE));
        $custoEst = $this->custo->estimarTexto($this->custo->custoConfigPrimario('agente'), $chars, (int) $tipo['max_tokens']);
        $chk = $this->custo->podeGerarAgente((int) ($usuarioId ?? 0), $custoEst);
        if (!$chk['ok']) {
            return ['ok' => false, 'msg' => $chk['msg']];
        }

        // ── A geração (síncrona: nasce 'processando') ─────────────────
        // A conversa nova só é gravada DEPOIS da resposta: primeira
        // pergunta que falha (sem provedor, teto) não deixa uma conversa
        // vazia para trás — e não conta como "rodou hoje" para o cron.
        if ($primeiroTurno) {
            $conversaUuid = $this->uuidV4();
            $conversaId   = 0;
        } else {
            $conversaId   = (int) $conversa['id'];
            $conversaUuid = (string) $conversa['uuid'];
        }

        $uuid = $this->uuidV4();
        $id   = (new IAGeracao())->criar([
            'uuid'                     => $uuid,
            'usuario_id'               => $usuarioId,
            'produto_id'               => null,
            'campanha_id'              => null,
            'geracao_origem_id'        => null,
            'tipo_conteudo_id'         => (int) $tipo['id'],
            'capacidade'               => 'agente',
            'formato'                  => null,
            'angulo'                   => null,
            'prompt_template_id'       => null,
            'prompt_template_snapshot' => null,
            'prompt_final'             => $pergunta,
            'contexto'                 => json_encode([
                'agente' => $agente, 'conversa' => $conversaUuid, 'modo' => $modo,
                'pagina' => $pagina, 'periodo' => $periodo,
                'pre_carregadas' => array_column($preCarregadas, 'ferramenta'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'chave_dedup'              => hash('sha256', uniqid('agente|' . $agente . '|', true)),
            'custo_estimado_usd'       => $custoEst,
            'status'                   => 'processando',
        ]);
        if ($id <= 0) {
            return ['ok' => false, 'msg' => 'Não foi possível registrar a consulta ao agente.'];
        }

        $geracao = [
            'id' => $id, 'uuid' => $uuid, 'usuario_id' => $usuarioId,
            'capacidade' => 'agente', 'prompt_final' => $pergunta, 'custo_estimado_usd' => $custoEst,
        ];
        $tipoArr = [
            'instrucoes_sistema' => $tipo['instrucoes_sistema'],
            'max_tokens'         => (int) $tipo['max_tokens'],
            'modelo_id'          => $tipo['modelo_id'],
            'nome'               => $tipo['nome'],
            'mensagens'          => $mensagens,
            'ferramentas'        => $ferramentasDefs,
            // Sem ninguém esperando na tela, a análise pode pensar mais.
            'params_override'    => $modo === 'tempo_real' ? ['effort' => $tipo['effort']] : ['effort' => 'high'],
        ];

        $servico = new IAGeracaoService();
        $executar = fn(string $nome, array $params) => $this->gw->executar($nome, $params, $whitelist);

        try {
            $r = $this->orq->executarAgente($geracao, $tipoArr, $executar);
        } catch (\Throwable $e) {
            $servico->falhar($geracao, IAResultado::falha('excecao', mb_substr($e->getMessage(), 0, 500), false));
            LogService::exception($e, 'error', 'ia', ['geracao_id' => $id, 'agente' => $agente]);
            return ['ok' => false, 'msg' => 'O agente falhou ao responder. O erro foi registrado.'];
        }

        if (!$r->ok) {
            $servico->falhar($geracao, $r);
            return ['ok' => false, 'msg' => $this->mensagemDeFalha($r), 'erro' => $r->erroCodigo];
        }

        $servico->concluir($geracao, $r);

        // ── Persistência da conversa ──────────────────────────────────
        if ($primeiroTurno) {
            $conversaId = $this->conversas->criar([
                'uuid'       => $conversaUuid,
                'agente'     => $agente,
                'usuario_id' => $usuarioId,
                'modo'       => $modo,
                'pagina'     => $pagina,
                'periodo'    => $periodo,
                'contexto'   => $ctxPagina,
                'titulo'     => $pergunta,
            ]);
        }
        $this->conversas->adicionarMensagem(['conversa_id' => $conversaId, 'papel' => 'user', 'conteudo' => $textoUser]);
        foreach ($preCarregadas as $pc) {
            $this->conversas->adicionarMensagem([
                'conversa_id' => $conversaId, 'papel' => 'tool', 'ferramenta' => $pc['ferramenta'],
                'parametros'  => ['_pre_carregada' => true] + $pc['parametros'],
                'conteudo'    => json_encode($pc['dados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tempo_ms'    => $pc['ms'],
            ]);
        }
        foreach ($r->ferramentasUsadas as $u) {
            $this->conversas->adicionarMensagem([
                'conversa_id' => $conversaId, 'papel' => 'tool', 'ferramenta' => $u['nome'],
                'parametros'  => $u['parametros'],
                'conteudo'    => $u['ok'] ? json_encode($u['dados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                          : 'ERRO ' . $u['erro'],
                'tempo_ms'    => $u['ms'],
            ]);
        }
        $this->conversas->adicionarMensagem([
            'conversa_id' => $conversaId, 'papel' => 'assistant', 'conteudo' => (string) $r->texto,
            'geracao_id' => $id, 'tokens_in' => $r->tokensIn, 'tokens_out' => $r->tokensOut, 'tempo_ms' => $r->tempoMs,
        ]);
        $this->conversas->tocar($conversaId);

        // ── Guarda de números (spec §28) ──────────────────────────────
        // Aviso, não censura: uma diferença que o modelo calculou a partir
        // de dois números reais também cai aqui. O usuário vê "N números
        // sem origem direta" e decide o quanto confiar.
        $guarda = (new ChatIaAgenteService())->validarNumeros(
            (string) $r->texto,
            ['n' => $this->numerosPermitidos($preCarregadas, $r->ferramentasUsadas)]
        );
        // "R$ 0,00" virava invasor: a guarda normaliza para dígitos e tira
        // os zeros à esquerda, e zero fica vazio. Zero nunca é invenção.
        $guarda['invasores'] = array_values(array_filter(
            $guarda['invasores'],
            fn($n) => trim(preg_replace('/\D/', '', (string) $n), '0') !== ''
        ));

        $secoes = self::secoes((string) $r->texto);

        return [
            'ok'                 => true,
            'conversa_uuid'      => $conversaUuid,
            'geracao_id'         => $id,
            'resposta'           => (string) $r->texto,
            'secoes'             => $secoes,
            'prioridade'         => self::prioridade($secoes),
            'numeros_sem_origem' => $guarda['invasores'],
            'procedencia'        => [
                'provedor'      => (string) ($r->provedorCodigo ?? ''),
                'modelo'        => (string) ($r->modeloCodigo ?? ''),
                'custo_usd'     => $r->custoRealUsd,
                'tokens_in'     => $r->tokensIn,
                'tokens_out'    => $r->tokensOut,
                'cache_leitura' => $r->tokensCacheLeitura,
                'rodadas'       => $r->rodadas,
                'tempo_ms'      => $r->tempoMs,
                'ferramentas'   => array_values(array_unique(array_merge(
                    array_column($preCarregadas, 'ferramenta'),
                    array_column($r->ferramentasUsadas, 'nome')
                ))),
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Leitura                                                             */
    /* ------------------------------------------------------------------ */

    /** A conversa com os turnos (sem os tool, que são auditoria). null = não encontrada / de outra pessoa. */
    public function historico(string $uuid, ?int $usuarioId): ?array
    {
        $c = $this->conversas->buscarPorUuid($uuid);
        if ($c === null || ($c['usuario_id'] !== null && (int) $c['usuario_id'] !== (int) $usuarioId)) {
            return null;
        }
        $turnos = [];
        $ferramentas = [];
        foreach ($this->conversas->mensagens((int) $c['id']) as $m) {
            if ($m['papel'] === 'tool') { $ferramentas[] = $m['ferramenta']; continue; }
            if ($m['papel'] === 'user') {
                // O texto gravado do primeiro turno carrega o contexto e os
                // dados pré-carregados; a pessoa só escreveu a pergunta.
                $turnos[] = ['papel' => 'user', 'texto' => self::perguntaDe($m['conteudo']), 'criado_em' => $m['criado_em']];
                continue;
            }
            $secoes = self::secoes($m['conteudo']);
            $turnos[] = [
                'papel' => 'assistant', 'texto' => $m['conteudo'], 'secoes' => $secoes,
                'prioridade' => self::prioridade($secoes), 'ferramentas' => array_values(array_unique($ferramentas)),
                'geracao_id' => $m['geracao_id'], 'criado_em' => $m['criado_em'],
            ];
            $ferramentas = [];
        }
        return ['conversa' => $c, 'turnos' => $turnos];
    }

    /**
     * Lista para o botão "Histórico" do drawer: as conversas da pessoa com
     * este agente + as do sistema. Só metadados — o conteúdo vem por
     * historico() quando a pessoa abre uma.
     */
    public function listarConversas(string $agente, ?int $usuarioId, int $limite = 20): array
    {
        if (!isset(IAAgenteGateway::agentes()[$agente])) return [];
        $out = [];
        foreach ($this->conversas->listarPorAgente($agente, $usuarioId, $limite) as $c) {
            $out[] = [
                'uuid'    => $c['uuid'],
                'titulo'  => (string) ($c['titulo'] ?? ''),
                'modo'    => $c['modo'],
                'pagina'  => $c['pagina'],
                'periodo' => $c['periodo'],
                'minha'   => $c['usuario_id'] !== null,
                'quando'  => $c['atualizado_em'],
            ];
        }
        return $out;
    }

    /** A última análise agendada de um agente — o "resumo executivo de hoje". */
    public function ultimaAgendada(string $agente): ?array
    {
        $c = $this->conversas->ultimaPorAgente($agente, 'agendado');
        if ($c === null) return null;
        $m = $this->conversas->ultimaResposta((int) $c['id']);
        if ($m === null) return null;
        $secoes = self::secoes($m['conteudo']);
        return ['conversa' => $c, 'texto' => $m['conteudo'], 'secoes' => $secoes,
                'prioridade' => self::prioridade($secoes), 'criado_em' => $m['criado_em']];
    }

    /* ------------------------------------------------------------------ */
    /* Modo agendado e modo por evento (worker)                            */
    /* ------------------------------------------------------------------ */

    /**
     * Uma rodada agendada de um agente. Uma por dia — o cron pode rodar
     * de novo sem gastar (spec §16: "reduz a necessidade de consultar a
     * API"). `$forcar` ignora a dedup (uso manual).
     * @return array ok, pulado?, msg?, conversa_uuid?, prioridade?, notificado?
     */
    public function rodarAgendado(string $agente, bool $forcar = false): array
    {
        $a = $this->agente($agente);
        if ($a === null || $a['agendado_ativo'] !== 1 || trim((string) $a['pergunta_agendada']) === '') {
            return ['ok' => false, 'msg' => 'Agente sem rodada agendada: ' . $agente];
        }
        if (!$forcar && $this->conversas->existeAgendadoHoje($agente)) {
            return ['ok' => true, 'pulado' => true, 'msg' => 'Já rodou hoje.'];
        }

        $r = $this->perguntar(
            $agente,
            (string) $a['pergunta_agendada'],
            ['pagina' => $a['pagina_agendada'] ?: 'overview', 'periodo' => '30d', 'origem' => 'agendado'],
            null, null, 'agendado'
        );
        if (!$r['ok']) return $r;

        $r['notificado'] = ($r['prioridade'] === 'Alta')
            && $this->notificarAdmins($agente, 'Resumo do dia com prioridade ALTA', $r);
        return $r;
    }

    /**
     * Modo por evento (spec §17): só aciona o agente quando o sistema —
     * não a IA — já detectou um alerta CRÍTICO, e uma vez por alerta por
     * dia. Sem alerta, sem chamada. O roteamento é pelo assunto do
     * alerta; o que o BiService::alertas() classifica como crítico hoje
     * é ruptura ≤ 7 dias e queda de faturamento ≥ 20%.
     * @return array{ok:bool, disparados:array, ignorados:int, msg?:string}
     */
    public function rodarPorEvento(): array
    {
        $al = $this->gw->executar('consultar_alertas', ['periodo' => '30d'], ['consultar_alertas']);
        if (!$al['ok']) {
            return ['ok' => false, 'disparados' => [], 'ignorados' => 0, 'msg' => 'Não foi possível ler os alertas.'];
        }

        $disparados = [];
        $ignorados  = 0;
        foreach (($al['dados']['alertas'] ?? []) as $a) {
            if (($a['nivel'] ?? '') !== 'critico') { $ignorados++; continue; }

            $titulo  = (string) ($a['titulo'] ?? '');
            $gatilho = 'alerta:' . sha1($titulo);
            if ($this->conversas->existeEventoHoje($gatilho)) { $ignorados++; continue; }

            $agente = self::agenteDoAlerta($titulo);
            $r = $this->perguntar(
                $agente,
                'ALERTA CRÍTICO detectado pelo sistema: "' . $titulo . '" — ' . (string) ($a['detalhe'] ?? '')
                . '. Analise a causa provável, o impacto e recomende a ação imediata.',
                ['pagina' => ($this->agente($agente)['pagina_agendada'] ?? null) ?: 'overview', 'periodo' => '30d', 'origem' => 'evento',
                 'gatilho' => $gatilho, 'alerta' => $titulo],
                null, null, 'evento'
            );

            $item = ['alerta' => $titulo, 'agente' => $agente, 'ok' => $r['ok'], 'msg' => $r['msg'] ?? null,
                     'prioridade' => $r['prioridade'] ?? null, 'conversa_uuid' => $r['conversa_uuid'] ?? null];
            if ($r['ok']) {
                $item['notificado'] = $this->notificarAdmins($agente, 'Alerta crítico: ' . $titulo, $r);
            }
            $disparados[] = $item;

            // Uma falha de provedor (sem modelo, teto) vale para todos os
            // alertas desta rodada: parar aqui evita N gerações falhadas.
            if (!$r['ok'] && in_array($r['erro'] ?? '', ['sem_modelos', 'todos_falharam', 'chave_invalida'], true)) {
                break;
            }
        }
        return ['ok' => true, 'disparados' => $disparados, 'ignorados' => $ignorados];
    }

    /**
     * Ruptura é de estoque; aprovação/funil/carrinho é conversão; o resto é
     * financeiro. O preferido só vale se existir e estiver ativo no
     * catálogo — senão o primeiro agente ativo assume, e o alerta nunca
     * fica sem dono. Sem agente nenhum, devolve '' e o chamador falha limpo.
     */
    public static function agenteDoAlerta(string $titulo): string
    {
        $t = mb_strtolower($titulo);
        $preferido = 'agente_financeiro';
        if (str_contains($t, 'ruptura') || str_contains($t, 'estoque')) $preferido = 'agente_estoque';
        elseif (str_contains($t, 'aprovação') || str_contains($t, 'funil') || str_contains($t, 'carrinho')) $preferido = 'agente_analytics';

        $ativos = IAAgenteGateway::agentesAtivos();
        if (in_array($preferido, $ativos, true)) return $preferido;
        return $ativos[0] ?? '';
    }

    /**
     * Sino de todos os admins (CLAUDE.md §6) com o RESUMO da resposta.
     * Nunca derruba a rodada: notificação que falha vira log.
     */
    private function notificarAdmins(string $agente, string $titulo, array $r): bool
    {
        $categoria = ['agente_financeiro' => 'financeiro', 'agente_estoque' => 'estoque'][$agente] ?? 'sistema';
        $resumo    = trim((string) ($r['secoes']['RESUMO'] ?? $r['resposta'] ?? ''));
        $nomes     = IAAgenteGateway::agentes()[$agente]['nome_exibicao'] ?? $agente;
        try {
            if (!class_exists('NotificacaoService')) return false;
            $id = NotificacaoService::criarBroadcast([
                'categoria' => $categoria,
                'tipo'      => 'agente_bi',
                'titulo'    => $nomes . ' · ' . mb_substr($titulo, 0, 120),
                'mensagem'  => mb_substr($resumo, 0, 500),
                'url'       => (defined('BASE_URL') ? BASE_URL : '') . '/admin/power-bi',
            ], 'todos_admins');
            return $id !== null;
        } catch (\Throwable $e) {
            LogService::exception($e, 'warning', 'ia', ['agente' => $agente, 'onde' => 'notificarAdmins']);
            return false;
        }
    }

    /** As últimas rodadas agendadas, uma por agente, para o overview. */
    public function resumosParaTela(): array
    {
        $out = [];
        foreach (IAAgenteGateway::agentes() as $agente => $a) {
            if ((int) $a['agendado_ativo'] !== 1) continue;
            try {
                $u = $this->ultimaAgendada($agente);
            } catch (\Throwable $e) {
                // Tabela ainda não migrada: o painel abre sem o bloco.
                return [];
            }
            if ($u === null) continue;
            $out[$agente] = [
                'nome'       => (string) $a['nome_exibicao'],
                'resumo'     => $u['secoes']['RESUMO'] ?? mb_substr($u['texto'], 0, 400),
                'prioridade' => $u['prioridade'],
                'quando'     => $u['criado_em'],
                'uuid'       => $u['conversa']['uuid'],
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Resposta em seções (spec §27)                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Quebra o texto nos títulos do formato. Tolera markdown em volta
     * (`**RESUMO**`, `## RESUMO`, `RESUMO:`) e a grafia sem acento.
     * Sem nenhum título, tudo é RESUMO — a resposta não some.
     * @return array<string,string> título canônico => texto
     */
    public static function secoes(string $texto): array
    {
        $canon = [];
        foreach (self::SECOES as $s) {
            $canon[self::chave($s)] = $s;
        }

        $out = [];
        $atual = null;
        foreach (preg_split('/\R/u', $texto) as $linha) {
            $limpa = trim(preg_replace('/^[#*\s_>-]+|[*:\s_]+$/u', '', trim($linha)));
            $k = self::chave($limpa);
            if ($limpa !== '' && isset($canon[$k])) {
                $atual = $canon[$k];
                $out[$atual] = $out[$atual] ?? '';
                continue;
            }
            if ($atual === null) {
                if (trim($linha) === '') continue;
                $atual = 'RESUMO';
                $out[$atual] = $out[$atual] ?? '';
            }
            $out[$atual] .= ($out[$atual] === '' ? '' : "\n") . rtrim($linha);
        }
        return array_map('trim', $out);
    }

    /** Alta | Média | Baixa a partir da seção PRIORIDADE; null se não houver. */
    public static function prioridade(array $secoes): ?string
    {
        $t = mb_strtolower(trim($secoes['PRIORIDADE'] ?? ''));
        if ($t === '') return null;
        if (preg_match('/^\W*(alta|m[eé]dia|baixa)\b/u', $t, $m)) {
            return ['alta' => 'Alta', 'media' => 'Média', 'média' => 'Média', 'baixa' => 'Baixa'][$m[1]] ?? null;
        }
        return null;
    }

    private static function chave(string $s): string
    {
        $s = mb_strtoupper(trim($s));
        return strtr($s, ['Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I',
                          'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ç' => 'C']);
    }

    /* ------------------------------------------------------------------ */
    /* Montagem das mensagens                                              */
    /* ------------------------------------------------------------------ */

    private const MARCA_PERGUNTA = "\n\nPERGUNTA\n";

    /** Primeiro turno: contexto da página + dados pré-carregados + pergunta. */
    private function primeiraMensagem(array $ctx, ?string $pagina, string $periodo, array $preCarregadas, string $pergunta): string
    {
        $rotulos = ['7d' => 'últimos 7 dias', '30d' => 'últimos 30 dias', '90d' => 'últimos 90 dias', '12m' => 'últimos 12 meses'];
        $l = [];
        $l[] = 'CONTEXTO DO PAINEL';
        $l[] = 'Loja: SportMoto';
        $l[] = 'Página: ' . ($pagina ?: 'não informada');
        $l[] = 'Período selecionado: ' . ($rotulos[$periodo] ?? $periodo) . ' (' . $periodo . ')';
        $l[] = 'Data de hoje: ' . date('d/m/Y');
        foreach ($ctx as $k => $v) {
            if (in_array($k, ['pagina', 'periodo'], true) || !is_scalar($v)) continue;
            $l[] = ucfirst((string) $k) . ': ' . $v;
        }

        if ($preCarregadas !== []) {
            $l[] = '';
            $l[] = 'DADOS PRÉ-CARREGADOS (resultado das ferramentas padrão desta página; use-os antes de chamar outras)';
            foreach ($preCarregadas as $pc) {
                $l[] = '';
                $l[] = '### ' . $pc['ferramenta'] . ' ' . json_encode($pc['parametros'], JSON_UNESCAPED_UNICODE);
                $l[] = json_encode($pc['dados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return implode("\n", $l) . self::MARCA_PERGUNTA . $pergunta;
    }

    /** Turnos seguintes: só a pergunta — e o período, se mudou no painel. */
    private function mensagemSeguinte(array $conversa, string $periodo, string $pergunta): string
    {
        if (($conversa['periodo'] ?? null) !== null && $conversa['periodo'] !== $periodo) {
            return 'Período do painel agora: ' . $periodo . '.' . self::MARCA_PERGUNTA . $pergunta;
        }
        return $pergunta;
    }

    /** O que a pessoa escreveu, sem o contexto que o sistema prefixou. */
    public static function perguntaDe(string $conteudoUser): string
    {
        $pos = strrpos($conteudoUser, self::MARCA_PERGUNTA);
        return $pos === false ? $conteudoUser : substr($conteudoUser, $pos + strlen(self::MARCA_PERGUNTA));
    }

    /**
     * Histórico para o modelo: só user + assistant (texto). Os tool_use
     * das rodadas anteriores não voltam — o que importa deles já está na
     * resposta do agente, e reenviar tudo custaria tokens por turno.
     * Cauda de HISTORICO_MAX turnos; o primeiro user (com o contexto)
     * sempre entra, senão o agente esquece a página.
     */
    private function historicoParaModelo(int $conversaId): array
    {
        $todas = array_values(array_filter(
            $this->conversas->mensagens($conversaId),
            fn($m) => in_array($m['papel'], ['user', 'assistant'], true)
        ));
        if ($todas === []) return [];

        $primeira = array_shift($todas);
        $cauda    = array_slice($todas, -self::HISTORICO_MAX);

        $out = [['role' => 'user', 'content' => $primeira['conteudo']]];
        $ultimoPapel = 'user';
        foreach ($cauda as $m) {
            // A API exige alternância user/assistant. Se a cauda começa
            // com assistant (o par foi cortado), ou se uma pergunta falhou
            // sem resposta, o turno repetido é fundido — não descartado.
            if ($m['papel'] === $ultimoPapel) {
                $out[count($out) - 1]['content'] .= "\n\n" . $m['conteudo'];
                continue;
            }
            $out[] = ['role' => $m['papel'], 'content' => $m['conteudo']];
            $ultimoPapel = $m['papel'];
        }
        // O turno atual é user: o histórico precisa terminar em assistant.
        if ($ultimoPapel === 'user') {
            array_pop($out);
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Guarda de números                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Os números que o modelo tinha em mãos, em TODAS as formas que ele
     * pode escrevê-los: 1200 também vale como "1.200,00" (120000) e
     * 2026-08-05 também vale como "05/08/2026". Sem isso a guarda acusa
     * formatação, não invenção.
     */
    private function numerosPermitidos(array $preCarregadas, array $usadas): array
    {
        $fontes = array_merge(array_column($preCarregadas, 'dados'), array_column($usadas, 'dados'));
        $out = [date('Y-m-d'), date('d/m/Y'), date('d/m/y')];
        $add = function ($v) use (&$out, &$add) {
            if (is_array($v)) { foreach ($v as $x) $add($x); return; }
            if (is_bool($v) || $v === null) return;
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                $f = (float) $v;
                $out[] = (string) $v;
                $out[] = (string) (int) $f;
                $out[] = number_format($f, 2, '.', '');
                $out[] = number_format($f, 1, '.', '');
                $out[] = number_format(abs($f), 0, '', '');
                return;
            }
            if (is_string($v)) {
                $out[] = $v;
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
                    $out[] = "{$m[3]}/{$m[2]}/{$m[1]}";
                    $out[] = "{$m[3]}/{$m[2]}/" . substr($m[1], 2);
                    $out[] = "{$m[2]}/{$m[1]}";
                }
            }
        };
        $add($fontes);
        return array_values(array_unique($out));
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    private function mensagemDeFalha(IAResultado $r): string
    {
        return match ($r->erroCodigo) {
            'sem_modelos'       => 'Nenhum modelo de agente disponível. Ative o provedor Claude e configure a chave na Central de IA.',
            'todos_falharam'    => 'Os modelos de agente não responderam. Tente de novo em instantes.',
            'rate_limit',
            'sobrecarga'        => 'O provedor de IA está sobrecarregado. Tente de novo em instantes.',
            'chave_invalida'    => 'A chave do provedor de IA foi recusada. Verifique na Central de IA.',
            'content_filter'    => 'O provedor recusou esta pergunta.',
            'rodadas_esgotadas' => 'O agente não concluiu a análise no limite de rodadas. Tente uma pergunta mais específica.',
            default             => 'O agente não conseguiu responder' . ($r->erro ? ': ' . mb_substr($r->erro, 0, 160) : '.'),
        };
    }

    private function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
