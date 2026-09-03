<?php
/**
 * IACampanhaService — campanhas em lote (Fase 3 · Bloco A).
 *
 * O desenho aprovado:
 *  - O PLANO é o cross join: faltante = (ia_campanha_produtos ×
 *    ia_campanha_tipos) sem geração do par (campanha, produto, tipo).
 *    Progresso é COUNT; não existe cursor nem tabela de jobs.
 *  - Idempotência por construção: chave_dedup determinística
 *    hash('campanha|cid|pid|tid|rN') — re-rodar o driver nunca duplica;
 *    retomar após pausa é gratuito.
 *  - O DRIVER roda dentro do ia-worker (mesmo cron, mesmo lock) e apenas
 *    ENFILEIRA, no ritmo de RITMO_POR_CAMPANHA gerações em voo por
 *    campanha; quem executa é a máquina de sempre (Fases 1/2).
 *  - Limites globais continuam sendo A trava: se podeGerar barrar, o
 *    driver encerra a rodada e volta no próximo minuto.
 *  - Orçamento opcional por campanha: atingiu → status 'pausada' +
 *    notificação aos admins (sino). Conclusão idem.
 *  - Falha-de-plano (ex.: produto sem foto num tipo de banner) vira
 *    geração 'falhou' com erro '[plano] …' e dedup do par — o driver
 *    NUNCA loopa tentando o mesmo par impossível.
 */
class IACampanhaService
{
    /** AJUSTE: gerações simultâneas em voo por campanha, por rodada do worker. */
    private const RITMO_POR_CAMPANHA = 4;
    private const MAX_PRODUTOS = 60;

    private PDO $db;
    private IACustoService $custo;

    public function __construct()
    {
        $this->db    = Database::getInstance()->getConnection();
        $this->custo = new IACustoService();
    }

    /* ================================================================ */
    /* CRUD                                                              */
    /* ================================================================ */

    public function criar(array $d, int $usuarioId): array
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            return ['ok' => false, 'msg' => 'Dê um nome à campanha.'];
        }

        $orcamento = isset($d['orcamento_max_usd']) && $d['orcamento_max_usd'] !== ''
            ? max(0, (float) $d['orcamento_max_usd']) : null;

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ia_campanhas (nome, briefing, orcamento_max_usd, status, criado_por)
                 VALUES (:n, :b, :o, \'rascunho\', :u)'
            );
            $stmt->execute([
                ':n' => mb_substr($nome, 0, 200),
                ':b' => json_encode(is_array($d['briefing'] ?? null) ? $d['briefing'] : [], JSON_UNESCAPED_UNICODE),
                ':o' => $orcamento,
                ':u' => $usuarioId,
            ]);
            $id = (int) $this->db->lastInsertId();
            LogService::audit('ia_campanha_criada', ['campanha_id' => $id, 'usuario_id' => $usuarioId]);
            return ['ok' => true, 'id' => $id];
        } catch (Throwable $e) {
            LogService::error('ia_campanha_criar_erro', ['erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro ao criar a campanha.'];
        }
    }

    public function atualizar(int $id, array $d): array
    {
        $c = $this->buscar($id);
        if ($c === null) {
            return ['ok' => false, 'msg' => 'Campanha não encontrada.'];
        }
        if (!in_array($c['status'], ['rascunho', 'pausada'], true)) {
            return ['ok' => false, 'msg' => 'Só rascunhos e campanhas pausadas podem ser editados.'];
        }

        try {
            $campos = [];
            $binds  = [':id' => $id];
            if (isset($d['nome']) && trim((string) $d['nome']) !== '') {
                $campos[] = 'nome = :n';
                $binds[':n'] = mb_substr(trim((string) $d['nome']), 0, 200);
            }
            if (array_key_exists('briefing', $d) && is_array($d['briefing'])) {
                $campos[] = 'briefing = :b';
                $binds[':b'] = json_encode($d['briefing'], JSON_UNESCAPED_UNICODE);
            }
            if (array_key_exists('orcamento_max_usd', $d)) {
                $campos[] = 'orcamento_max_usd = :o';
                $binds[':o'] = ($d['orcamento_max_usd'] === '' || $d['orcamento_max_usd'] === null)
                    ? null : max(0, (float) $d['orcamento_max_usd']);
            }
            if (empty($campos)) {
                return ['ok' => true];
            }
            $this->db->prepare('UPDATE ia_campanhas SET ' . implode(', ', $campos) . ' WHERE id = :id')->execute($binds);
            return ['ok' => true];
        } catch (Throwable $e) {
            LogService::error('ia_campanha_atualizar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro ao atualizar.'];
        }
    }

    /** Substitui o conjunto de produtos (transação; teto de segurança). */
    public function definirProdutos(int $campanhaId, array $produtoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $produtoIds), fn ($v) => $v > 0)));
        if (count($ids) > self::MAX_PRODUTOS) {
            return ['ok' => false, 'msg' => 'Máximo de ' . self::MAX_PRODUTOS . ' produtos por campanha — divida em duas.'];
        }
        $c = $this->buscar($campanhaId);
        if ($c === null || !in_array($c['status'], ['rascunho', 'pausada'], true)) {
            return ['ok' => false, 'msg' => 'Produtos só podem ser alterados em rascunho/pausada.'];
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare('DELETE FROM ia_campanha_produtos WHERE campanha_id = :c')->execute([':c' => $campanhaId]);
            if (!empty($ids)) {
                $stmt = $this->db->prepare('INSERT IGNORE INTO ia_campanha_produtos (campanha_id, produto_id)
                                            SELECT :c, id FROM produtos WHERE id = :p');
                foreach ($ids as $pid) {
                    $stmt->execute([':c' => $campanhaId, ':p' => $pid]);
                }
            }
            $this->db->commit();
            return ['ok' => true, 'total' => $this->contar('ia_campanha_produtos', $campanhaId)];
        } catch (Throwable $e) {
            $this->db->rollBack();
            LogService::error('ia_campanha_produtos_erro', ['id' => $campanhaId, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro ao gravar os produtos.'];
        }
    }

    /** Substitui os tipos. Cada item: ['tipo_conteudo_id'=>int, 'config'=>array]. */
    public function definirTipos(int $campanhaId, array $itens): array
    {
        $c = $this->buscar($campanhaId);
        if ($c === null || !in_array($c['status'], ['rascunho', 'pausada'], true)) {
            return ['ok' => false, 'msg' => 'Tipos só podem ser alterados em rascunho/pausada.'];
        }

        $layoutsValidos = array_column((new IAComposicaoService())->listarLayouts(), 'codigo');
        $tiposModel = new IATipoConteudo();
        $validados = [];

        foreach ($itens as $item) {
            $tid  = (int) ($item['tipo_conteudo_id'] ?? 0);
            $tipo = $tid > 0 ? $tiposModel->buscar($tid) : null;
            if ($tipo === null || (int) $tipo['ativo'] !== 1 || $tipo['grupo'] === 'sistema') {
                return ['ok' => false, 'msg' => "Tipo de conteúdo inválido (id {$tid})."];
            }
            $config = is_array($item['config'] ?? null) ? $item['config'] : [];

            if ($tipo['capacidade'] === 'composicao') {
                $layout = trim((string) ($config['layout'] ?? ''));
                if ($layout === '' || !in_array($layout, $layoutsValidos, true)) {
                    return ['ok' => false, 'msg' => "O tipo \"{$tipo['nome']}\" exige um layout válido."];
                }
            }
            $validados[$tid] = $config;
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare('DELETE FROM ia_campanha_tipos WHERE campanha_id = :c')->execute([':c' => $campanhaId]);
            $stmt = $this->db->prepare('INSERT INTO ia_campanha_tipos (campanha_id, tipo_conteudo_id, config) VALUES (:c, :t, :cfg)');
            foreach ($validados as $tid => $cfg) {
                $stmt->execute([':c' => $campanhaId, ':t' => $tid,
                                ':cfg' => json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            $this->db->commit();
            return ['ok' => true, 'total' => count($validados)];
        } catch (Throwable $e) {
            $this->db->rollBack();
            LogService::error('ia_campanha_tipos_erro', ['id' => $campanhaId, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro ao gravar os tipos.'];
        }
    }

    public function buscar(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM ia_campanhas WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $c = $stmt->fetch(PDO::FETCH_ASSOC);
            return $c ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Lista com agregados para os cards (poucas campanhas — subqueries ok). */
    public function listar(): array
    {
        try {
            return $this->db->query(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM ia_campanha_produtos cp WHERE cp.campanha_id = c.id) AS n_produtos,
                        (SELECT COUNT(*) FROM ia_campanha_tipos ct WHERE ct.campanha_id = c.id)    AS n_tipos,
                        (SELECT COUNT(*) FROM ia_geracoes g WHERE g.campanha_id = c.id AND g.status <> 'cancelada') AS n_geradas,
                        (SELECT COUNT(*) FROM ia_geracoes g WHERE g.campanha_id = c.id AND g.status = 'concluida')  AS n_concluidas,
                        (SELECT COUNT(*) FROM ia_geracoes g WHERE g.campanha_id = c.id AND g.status = 'falhou')     AS n_falhas,
                        (SELECT COALESCE(SUM(g.custo_real_usd),0) FROM ia_geracoes g WHERE g.campanha_id = c.id)    AS custo_real
                   FROM ia_campanhas c
               ORDER BY c.id DESC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_campanha_listar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    public function contadores(int $campanhaId): array
    {
        $tot = $this->contar('ia_campanha_produtos', $campanhaId) * $this->contar('ia_campanha_tipos', $campanhaId);
        $q = $this->db->prepare(
            "SELECT COUNT(*) total,
                    SUM(status = 'concluida') concluidas,
                    SUM(status = 'falhou') falhas,
                    SUM(status IN ('na_fila','processando','aguardando_provedor')) em_voo,
                    COALESCE(SUM(custo_real_usd),0) custo_real
               FROM ia_geracoes WHERE campanha_id = :c AND status <> 'cancelada'"
        );
        $q->execute([':c' => $campanhaId]);
        $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'pares'      => $tot,
            'geradas'    => (int) ($r['total'] ?? 0),
            'concluidas' => (int) ($r['concluidas'] ?? 0),
            'falhas'     => (int) ($r['falhas'] ?? 0),
            'em_voo'     => (int) ($r['em_voo'] ?? 0),
            'custo_real' => (float) ($r['custo_real'] ?? 0),
        ];
    }

    /* ================================================================ */
    /* Estimativa (antes de gastar)                                      */
    /* ================================================================ */

    public function estimativa(int $campanhaId): array
    {
        $produtos = $this->produtosDa($campanhaId);
        $tipos    = $this->tiposDa($campanhaId);
        $n        = count($produtos);

        $porTipo = [];
        $total   = 0.0;
        $avisos  = [];

        $temMidiaComFoto = false;
        foreach ($tipos as $t) {
            if (in_array($t['capacidade'], ['composicao'], true)) {
                $temMidiaComFoto = true;
            }
        }
        $semFoto = $temMidiaComFoto ? $this->produtosSemFoto($produtos) : [];
        if (!empty($semFoto)) {
            $avisos[] = count($semFoto) . ' produto(s) sem foto — os banners deles falharão ([plano]): ids ' . implode(', ', $semFoto) . '.';
        }

        foreach ($tipos as $t) {
            $cap = (string) $t['capacidade'];
            if ($cap === 'texto') {
                $unit = $this->custo->estimarTexto($this->custo->custoConfigPrimarioTexto(),
                    1200 + mb_strlen((string) $t['instrucoes_sistema']), (int) ($t['max_tokens'] ?? 800));
                $sub  = $unit * $n;
            } elseif ($cap === 'imagem') {
                $unit = $this->custo->estimarImagem($this->custo->custoConfigPrimario('imagem'));
                $sub  = $unit * $n;
            } else { // composicao
                $cena    = $this->custo->estimarImagem($this->custo->custoConfigPrimario('imagem'));
                $bria    = $this->custo->estimarImagem($this->custo->custoConfigPrimario('remocao_fundo'));
                $frios   = $this->produtosComRecorteFrio($produtos);
                $sub     = ($cena * $n) + ($bria * count($frios));
                $unit    = $n > 0 ? $sub / $n : 0;
            }
            $porTipo[] = ['tipo' => $t['nome'], 'capacidade' => $cap,
                          'unitario_usd' => round($unit, 6), 'subtotal_usd' => round($sub, 6)];
            $total += $sub;
        }

        return ['ok' => true, 'produtos' => $n, 'tipos' => count($tipos),
                'pares' => $n * count($tipos), 'total_usd' => round($total, 6),
                'por_tipo' => $porTipo, 'avisos' => $avisos];
    }

    /* ================================================================ */
    /* Transições de estado                                              */
    /* ================================================================ */

    public function iniciar(int $id, int $usuarioId): array
    {
        $c = $this->buscar($id);
        if ($c === null) {
            return ['ok' => false, 'msg' => 'Campanha não encontrada.'];
        }
        if (!in_array($c['status'], ['rascunho', 'pausada'], true)) {
            return ['ok' => false, 'msg' => 'Só rascunhos e pausadas podem iniciar.'];
        }
        if ($this->contar('ia_campanha_produtos', $id) === 0 || $this->contar('ia_campanha_tipos', $id) === 0) {
            return ['ok' => false, 'msg' => 'A campanha precisa de pelo menos 1 produto e 1 tipo de conteúdo.'];
        }

        $this->db->prepare(
            "UPDATE ia_campanhas SET status = 'gerando', criado_por = COALESCE(criado_por, :u) WHERE id = :id"
        )->execute([':u' => $usuarioId, ':id' => $id]);

        LogService::audit('ia_campanha_iniciada', ['campanha_id' => $id, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'msg' => 'Campanha em geração — o worker cuida do resto no ritmo dos limites.'];
    }

    public function pausar(int $id): array   { return $this->mudarStatus($id, ['gerando'], 'pausada'); }
    public function retomar(int $id): array  { return $this->iniciar($id, (int) ($this->buscar($id)['criado_por'] ?? 0)); }
    public function arquivar(int $id): array { return $this->mudarStatus($id, ['concluida', 'cancelada'], 'arquivada'); }

    /** Cancela a campanha e as gerações que ainda não gastaram (na_fila). */
    public function cancelar(int $id): array
    {
        $r = $this->mudarStatus($id, ['rascunho', 'gerando', 'pausada'], 'cancelada');
        if ($r['ok']) {
            $this->db->prepare(
                "UPDATE ia_geracoes SET status = 'cancelada' WHERE campanha_id = :c AND status = 'na_fila'"
            )->execute([':c' => $id]);
        }
        return $r;
    }

    /* ================================================================ */
    /* DRIVER — chamado pelo ia-worker a cada rodada                     */
    /* ================================================================ */

    /** Retorna string-resumo para o log do worker. */
    public function processarCampanhas(): string
    {
        $ativas = $this->db->query("SELECT * FROM ia_campanhas WHERE status = 'gerando' ORDER BY id ASC")
                           ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // String vazia = nada digno de log. O worker chama isto a cada volta do
        // loop (a cada ~2s com a fila vazia); devolver texto no caso ocioso
        // encheria o ia-worker.log de "nenhuma ativa" a perder de vista.
        if (empty($ativas)) {
            return '';
        }

        $enfileiradas = 0;
        $svc = new IAGeracaoService();

        foreach ($ativas as $c) {
            $cid  = (int) $c['id'];
            $cont = $this->contadores($cid);

            // Orçamento: real das terminais + estimado das em-voo (conservador)
            if ($c['orcamento_max_usd'] !== null) {
                $gasto = $this->gastoProjetado($cid);
                if ($gasto >= (float) $c['orcamento_max_usd']) {
                    $this->mudarStatus($cid, ['gerando'], 'pausada');
                    LogService::warning('ia_campanha_pausada_orcamento', ['campanha_id' => $cid, 'gasto' => $gasto]);
                    $this->notificar($cid, 'ia_campanha_pausada',
                        "Campanha \"{$c['nome']}\" pausada: orçamento de US$ " . number_format((float) $c['orcamento_max_usd'], 2) . ' atingido.');
                    continue;
                }
            }

            // Conclusão: nada faltando e nada em voo
            $faltantes = $this->paresFaltantes($cid, 1);
            if (empty($faltantes) && $cont['em_voo'] === 0) {
                $this->mudarStatus($cid, ['gerando'], 'concluida');
                LogService::audit('ia_campanha_concluida', ['campanha_id' => $cid] + $cont);
                $this->notificar($cid, 'ia_campanha_concluida',
                    "Campanha \"{$c['nome']}\" concluída: {$cont['concluidas']} geradas" .
                    ($cont['falhas'] > 0 ? ", {$cont['falhas']} falha(s) para revisar" : '') . '.');
                continue;
            }

            // Ritmo: completa até RITMO_POR_CAMPANHA em voo
            $vaga = self::RITMO_POR_CAMPANHA - $cont['em_voo'];
            if ($vaga <= 0) {
                continue;
            }

            foreach ($this->paresFaltantes($cid, $vaga) as $par) {
                $res = $this->enfileirarPar($svc, $c, $par);
                if ($res === 'barrado') {
                    LogService::info('ia_campanha_driver_barrado_limites', ['campanha_id' => $cid]);
                    return "campanhas: {$enfileiradas} enfileirada(s); rodada encerrada pelos limites globais";
                }
                if ($res === 'ok') {
                    $enfileiradas++;
                }
                // 'falha_plano' e 'ja_existe' seguem para o próximo par
            }
        }

        return 'campanhas: ' . count($ativas) . ' ativa(s), ' . $enfileiradas . ' enfileirada(s)';
    }

    /** Re-enfileira as falhas (tentativa nova por par; respeita limites). */
    public function refazerFalhas(int $campanhaId, int $usuarioId): array
    {
        $c = $this->buscar($campanhaId);
        if ($c === null) {
            return ['ok' => false, 'msg' => 'Campanha não encontrada.'];
        }

        $falhas = $this->db->prepare(
            "SELECT id, produto_id, tipo_conteudo_id FROM ia_geracoes
              WHERE campanha_id = :c AND status = 'falhou' ORDER BY id ASC"
        );
        $falhas->execute([':c' => $campanhaId]);
        $linhas = $falhas->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($linhas)) {
            return ['ok' => true, 'refeitas' => 0, 'msg' => 'Nenhuma falha para refazer.'];
        }

        $tipos = [];
        foreach ($this->tiposDa($campanhaId) as $t) {
            $t['tipo_conteudo_id'] = (int) $t['tipo_conteudo_id'];
            $t['config'] = json_decode((string) ($t['config'] ?? ''), true) ?: [];
            $tipos[$t['tipo_conteudo_id']] = $t;
        }

        $svc = new IAGeracaoService();
        $refeitas = 0;
        foreach ($linhas as $f) {
            $tid = (int) $f['tipo_conteudo_id'];
            if (!isset($tipos[$tid])) {
                continue; // tipo saiu da campanha
            }
            $par = ['produto_id' => (int) $f['produto_id'], 'tipo' => $tipos[$tid], 'origem_id' => (int) $f['id']];
            $res = $this->enfileirarPar($svc, $c, $par);
            if ($res === 'barrado') {
                return ['ok' => true, 'refeitas' => $refeitas,
                        'msg' => "Limites globais atingiram o teto: {$refeitas} refeita(s); o restante fica para as próximas rodadas — clique de novo em instantes."];
            }
            if ($res === 'ok') {
                $refeitas++;
            }
        }

        // Falhas geram nova tentativa em voo — a campanha volta a gerar
        if ($refeitas > 0 && $c['status'] === 'concluida') {
            $this->mudarStatus($campanhaId, ['concluida'], 'gerando');
        }
        LogService::audit('ia_campanha_refazer_falhas', ['campanha_id' => $campanhaId, 'refeitas' => $refeitas, 'usuario_id' => $usuarioId]);
        return ['ok' => true, 'refeitas' => $refeitas, 'msg' => "{$refeitas} geração(ões) re-enfileirada(s)."];
    }

    /* ================================================================ */
    /* Internos                                                          */
    /* ================================================================ */

    /** Pares (produto × tipo) sem NENHUMA geração do par nesta campanha. */
    private function paresFaltantes(int $campanhaId, int $limite): array
    {
        $stmt = $this->db->prepare(
            "SELECT cp.produto_id, ct.tipo_conteudo_id, ct.config,
                    t.id AS t_id, t.nome AS t_nome, t.capacidade AS t_capacidade
               FROM ia_campanha_produtos cp
         CROSS JOIN ia_campanha_tipos ct
         INNER JOIN ia_tipos_conteudo t ON t.id = ct.tipo_conteudo_id
              WHERE cp.campanha_id = :c1 AND ct.campanha_id = :c2
                AND NOT EXISTS (
                      SELECT 1 FROM ia_geracoes g
                       WHERE g.campanha_id = :c3
                         AND g.produto_id = cp.produto_id
                         AND g.tipo_conteudo_id = ct.tipo_conteudo_id
                         AND g.status <> 'cancelada')
           ORDER BY cp.produto_id, ct.tipo_conteudo_id
              LIMIT " . max(1, $limite)
        );
        $stmt->execute([':c1' => $campanhaId, ':c2' => $campanhaId, ':c3' => $campanhaId]);

        $pares = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $pares[] = [
                'produto_id' => (int) $r['produto_id'],
                'tipo'       => ['tipo_conteudo_id' => (int) $r['tipo_conteudo_id'], 'id' => (int) $r['t_id'],
                                 'nome' => $r['t_nome'], 'capacidade' => $r['t_capacidade'],
                                 'config' => json_decode((string) ($r['config'] ?? ''), true) ?: []],
                'origem_id'  => null,
            ];
        }
        return $pares;
    }

    /** 'ok' | 'barrado' (limites globais) | 'falha_plano' | 'ja_existe'. */
    private function enfileirarPar(IAGeracaoService $svc, array $campanha, array $par): string
    {
        $cid    = (int) $campanha['id'];
        $pid    = (int) $par['produto_id'];
        $tipo   = $par['tipo'];
        $tid    = (int) $tipo['tipo_conteudo_id'];
        $config = is_array($tipo['config'] ?? null) ? $tipo['config'] : [];

        $tentativa = (int) $this->db->query(
            "SELECT COUNT(*) FROM ia_geracoes WHERE campanha_id = {$cid} AND produto_id = {$pid} AND tipo_conteudo_id = {$tid}"
        )->fetchColumn();

        $entrada = [
            'usuario_id'       => (int) ($campanha['criado_por'] ?? 0),
            'produto_id'       => $pid,
            'tipo_conteudo_id' => $tid,
            'campanha_id'      => $cid,
            'chave_dedup'      => hash('sha256', "campanha|{$cid}|{$pid}|{$tid}|r{$tentativa}"),
            'geracao_origem_id' => $par['origem_id'],
            'origem_id'        => $par['origem_id'],
            'briefing'         => json_decode((string) ($campanha['briefing'] ?? ''), true) ?: [],
            'angulo'           => trim((string) ($config['angulo'] ?? '')),
            'prompt_custom'    => '',
            'variacoes'        => 1,
            'proporcao'        => trim((string) ($config['proporcao'] ?? '1:1')),
            'usar_referencia'  => !empty($config['usar_referencia']),
            'layout'           => trim((string) ($config['layout'] ?? '')),
            'banner_headline'  => trim((string) ($config['banner_headline'] ?? '')),
            'banner_subtitulo' => trim((string) ($config['banner_subtitulo'] ?? '')),
        ];

        if ($entrada['usuario_id'] <= 0) {
            LogService::warning('ia_campanha_sem_criador', ['campanha_id' => $cid]);
            $this->mudarStatus($cid, ['gerando'], 'pausada');
            return 'barrado';
        }

        $res = $svc->enfileirar($entrada);
        if (!empty($res['ok'])) {
            return 'ok';
        }

        $msg = (string) ($res['msg'] ?? 'falha ao enfileirar');
        if (stripos($msg, 'limite') !== false || stripos($msg, 'teto') !== false) {
            return 'barrado'; // limites globais — não é culpa do par
        }
        if (stripos($msg, 'já solicitad') !== false || stripos($msg, 'ja solicitad') !== false) {
            return 'ja_existe';
        }

        // Falha-de-plano: registra 'falhou' com o dedup DO PAR — o driver não loopa
        $this->registrarFalhaDePlano($campanha, $par, $entrada['chave_dedup'], $msg);
        return 'falha_plano';
    }

    private function registrarFalhaDePlano(array $campanha, array $par, string $dedup, string $msg): void
    {
        try {
            $id = (new IAGeracao())->criar([
                'uuid'                     => $this->uuidV4(),
                'usuario_id'               => (int) $campanha['criado_por'],
                'produto_id'               => (int) $par['produto_id'],
                'campanha_id'              => (int) $campanha['id'],
                'geracao_origem_id'        => $par['origem_id'],
                'tipo_conteudo_id'         => (int) $par['tipo']['tipo_conteudo_id'],
                'capacidade'               => (string) $par['tipo']['capacidade'],
                'formato'                  => null,
                'angulo'                   => null,
                'prompt_template_id'       => null,
                'prompt_template_snapshot' => null,
                'prompt_final'             => '[plano] geração não criada',
                'contexto'                 => json_encode(['plano' => $msg], JSON_UNESCAPED_UNICODE),
                'chave_dedup'              => $dedup,
                'custo_estimado_usd'       => 0,
                'status'                   => 'falhou',
            ]);
            if ($id !== null) {
                $this->db->prepare('UPDATE ia_geracoes SET erro = :e, concluido_em = NOW() WHERE id = :id')
                         ->execute([':e' => mb_substr('[plano] ' . $msg, 0, 500), ':id' => $id]);
            }
            LogService::warning('ia_campanha_falha_plano', [
                'campanha_id' => (int) $campanha['id'], 'produto_id' => (int) $par['produto_id'],
                'tipo_id' => (int) $par['tipo']['tipo_conteudo_id'], 'motivo' => $msg,
            ]);
        } catch (Throwable $e) {
            LogService::error('ia_campanha_falha_plano_erro', ['erro' => $e->getMessage()]);
        }
    }

    /** Real das terminais + estimado das em-voo (projeção conservadora). */
    private function gastoProjetado(int $campanhaId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(CASE
                       WHEN status IN ('concluida','falhou') THEN COALESCE(custo_real_usd, 0)
                       WHEN status IN ('na_fila','processando','aguardando_provedor') THEN COALESCE(custo_estimado_usd, 0)
                       ELSE 0 END), 0)
               FROM ia_geracoes WHERE campanha_id = :c"
        );
        $stmt->execute([':c' => $campanhaId]);
        return (float) $stmt->fetchColumn();
    }

    private function notificar(int $campanhaId, string $tipo, string $titulo): void
    {
        if (!class_exists('NotificacaoService')) {
            return; // integração opcional — o sino pode não estar instalado
        }
        try {
            NotificacaoService::criarBroadcast([
                'categoria' => 'sistema',
                'tipo'      => $tipo,
                'titulo'    => $titulo,
                'url'       => '/admin/ia/campanhas/' . $campanhaId,
            ], 'todos_admins');
        } catch (Throwable $e) {
            LogService::warning('ia_campanha_notificacao_erro', ['erro' => $e->getMessage()]);
        }
    }

    private function mudarStatus(int $id, array $de, string $para): array
    {
        try {
            $marcadores = implode(',', array_fill(0, count($de), '?'));
            $stmt = $this->db->prepare("UPDATE ia_campanhas SET status = ? WHERE id = ? AND status IN ({$marcadores})");
            $stmt->execute(array_merge([$para, $id], $de));
            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'msg' => 'Transição de status inválida para o estado atual.'];
            }
            LogService::audit('ia_campanha_status', ['campanha_id' => $id, 'para' => $para]);
            return ['ok' => true];
        } catch (Throwable $e) {
            LogService::error('ia_campanha_status_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return ['ok' => false, 'msg' => 'Erro na transição de status.'];
        }
    }

    private function produtosDa(int $campanhaId): array
    {
        $stmt = $this->db->prepare('SELECT produto_id FROM ia_campanha_produtos WHERE campanha_id = :c');
        $stmt->execute([':c' => $campanhaId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function tiposDa(int $campanhaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ct.tipo_conteudo_id, ct.config, t.nome, t.capacidade, t.instrucoes_sistema, t.max_tokens
               FROM ia_campanha_tipos ct INNER JOIN ia_tipos_conteudo t ON t.id = ct.tipo_conteudo_id
              WHERE ct.campanha_id = :c ORDER BY t.ordem'
        );
        $stmt->execute([':c' => $campanhaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function produtosSemFoto(array $produtoIds): array
    {
        if (empty($produtoIds)) {
            return [];
        }
        $in = implode(',', array_map('intval', $produtoIds));
        $com = $this->db->query(
            "SELECT DISTINCT produto_id FROM produto_imagens WHERE produto_id IN ({$in})"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_diff($produtoIds, array_map('intval', $com)));
    }

    private function produtosComRecorteFrio(array $produtoIds): array
    {
        if (empty($produtoIds)) {
            return [];
        }
        $in = implode(',', array_map('intval', $produtoIds));
        $quentes = $this->db->query(
            "SELECT DISTINCT r.produto_id
               FROM ia_recortes_produto r
         INNER JOIN produto_imagens pi ON pi.id = r.produto_imagem_id
              WHERE r.produto_id IN ({$in})
                AND r.hash_origem = SHA2(pi.arquivo, 256)"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_diff($produtoIds, array_map('intval', $quentes)));
    }

    private function contar(string $tabela, int $campanhaId): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$tabela} WHERE campanha_id = {$campanhaId}")->fetchColumn();
    }

    private function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
