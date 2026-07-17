<?php
/**
 * app/services/FluxoNoRegistry.php
 *
 * Registry dos tipos de nó + TODAS as classes de nó da Fase 1.
 * As classes vivem neste arquivo de propósito: o acesso é sempre via
 * registry, então o spl_autoload (que procura 1 classe = 1 arquivo)
 * nunca precisa encontrá-las individualmente.
 *
 * CONTRATO DE UM NÓ:
 *   executar(array &$exec, array $config, PDO $db): string
 *     → retorna o NOME DA PORTA de saída ('saida','true','false','a','b')
 *     → ou uma das constantes especiais FluxoNo::*
 *   O nó pode escrever em $exec['contexto'] (array) e $exec['dormir_ate'].
 *
 * Adicionar um nó novo no futuro = 1 classe aqui + 1 linha no MAPA.
 */

// ─────────────────────────────────────────────────────────────────────────────
abstract class FluxoNo
{
    /** Retornos especiais (não são portas) */
    public const DORMIR   = '__dormir';    // nó setou $exec['dormir_ate']
    public const ENCERRAR = '__encerrar';
    public const ERRO     = '__erro';      // nó setou $exec['erro_detalhe']
    public const AGUARDAR_EVENTO = '__aguardar_evento';   // ← ADICIONAR    

    abstract public function executar(array &$exec, array $config, PDO $db): string;

    /** Portas que o tipo declara (validação e canvas) */
    abstract public function portas(): array;

    /** true se o nó é ponto de entrada (trigger) */
    public function ehTrigger(): bool { return false; }

    // ── Helpers compartilhados ───────────────────────────────────────────────

    protected function clienteId(array $exec): ?int
    {
        $id = (int)($exec['cliente_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    protected function ctx(array $exec): array
    {
        return is_array($exec['contexto'] ?? null) ? $exec['contexto'] : [];
    }

    /**
     * Monta as variáveis de template da execução:
     * cliente (nome/email), contexto (produto), moto principal.
     */
    protected function montarVars(array $exec, PDO $db): array
    {
        $vars = [
            'site_nome'  => defined('SITE_NAME') ? SITE_NAME : 'SportMoto',
            'url_site'   => defined('BASE_URL') ? BASE_URL : '',
            'ano'        => date('Y'),
            'data_atual' => date('d/m/Y'),
        ];
        $ctx = $this->ctx($exec);

        // Cliente
        $cid = $this->clienteId($exec);
        if ($cid) {
            $st = $db->prepare(
                "SELECT u.nome, u.email FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = :id LIMIT 1"
            );
            $st->execute([':id' => $cid]);
            if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                $vars['nome']          = $c['nome'];
                $vars['primeiro_nome'] = explode(' ', trim($c['nome']))[0] ?: 'Cliente';
                $vars['email']         = $c['email'];
            }
        }
        if (empty($vars['email']) && !empty($ctx['_email'])) {
            $vars['email']         = $ctx['_email'];
            $vars['primeiro_nome'] = $ctx['_primeiro_nome'] ?? 'Cliente';
            $vars['nome']          = $vars['primeiro_nome'];
        }
        $vars['url_descadastro'] = ($vars['url_site'] ?? '')
            . '/email/descadastrar/' . urlencode($vars['email'] ?? '');

        // Produto do contexto
        if (!empty($ctx['produto_id'])) {
            $st = $db->prepare(
                "SELECT id, nome, slug, preco, preco_promo, promo_inicio, promo_fim
                 FROM produtos WHERE id = :id LIMIT 1"
            );
            $st->execute([':id' => (int)$ctx['produto_id']]);
            if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
                $vars['produto_nome'] = $p['nome'];
                $vars['produto_url']  = ($vars['url_site'] ?? '') . '/produto/' . ($p['slug'] ?: $p['id']);
                $preco = (!empty($p['preco_promo']) && $p['preco_promo'] > 0
                          && time() >= (int)strtotime((string)$p['promo_inicio'] ?: '1970-01-01')
                          && time() <= ((int)strtotime((string)$p['promo_fim'] ?: '2100-01-01')))
                       ? (float)$p['preco_promo'] : (float)$p['preco'];
                $vars['produto_preco'] = 'R$ ' . number_format($preco, 2, ',', '.');
                if (class_exists('ImageHelper')) {
                    try { $vars['produto_img'] = ImageHelper::getPrincipal((int)$p['id']); } catch (Throwable $e) {}
                }
            }
        }

        // Moto principal (banco, não sessão — worker roda sem sessão)
        if ($cid) {
            $st = $db->prepare(
                "SELECT cv.apelido, cv.ano,
                        mm.nome AS montadora, mo.nome AS modelo
                 FROM cliente_veiculos cv
                 JOIN moto_montadoras mm ON mm.id = cv.montadora_id
                 LEFT JOIN moto_modelos mo ON mo.id = cv.modelo_id
                 WHERE cv.cliente_id = :c AND cv.principal = 1 LIMIT 1"
            );
            try {
                $st->execute([':c' => $cid]);
                if ($m = $st->fetch(PDO::FETCH_ASSOC)) {
                    $partes = array_filter([$m['montadora'], $m['modelo'], $m['ano']]);
                    $vars['moto_label']   = implode(' ', $partes);
                    $vars['moto_apelido'] = $m['apelido'] ?: $vars['moto_label'];
                }
            } catch (Throwable $e) {}
        }

        // Contexto cru vence (preco_antigo, desconto_pct etc. gravados no gatilho)
        foreach ($ctx as $k => $v) {
            if ($k[0] !== '_' && is_scalar($v)) $vars[$k] = $v;
        }
        return $vars;
    }

    /** Substitui {{var}} simples numa string. */
    protected function interpolar(string $texto, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',
            fn($m) => (string)($vars[$m[1]] ?? ''), $texto);
    }

    /** Quiet hours: retorna DATETIME da próxima janela permitida, ou null se agora está ok. */
    protected function foraDaJanela(PDO $db): ?string
    {
        try {
            $st  = $db->query("SELECT chave, valor FROM fluxo_motor_config
                               WHERE chave IN ('quiet_hours_inicio','quiet_hours_fim')");
            $cfg = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            $ini = (int)($cfg['quiet_hours_inicio'] ?? 8);
            $fim = (int)($cfg['quiet_hours_fim'] ?? 21);
        } catch (Throwable $e) { $ini = 8; $fim = 21; }

        $h = (int)date('G');
        if ($h >= $ini && $h < $fim) return null;                    // dentro da janela
        $dia = ($h >= $fim) ? 'tomorrow' : 'today';
        return date('Y-m-d ', strtotime($dia)) . sprintf('%02d:00:00', $ini);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// TRIGGERS (pontos de entrada — executar é passthrough)
// ═════════════════════════════════════════════════════════════════════════════

class FluxoNoTriggerEvento extends FluxoNo
{
    // config: {"evento":"produto_visto","entidade_tipo":"produto"|null,
    //          "min_ocorrencias":1,"janela_dias":7,"apenas_logados":true}
    public function ehTrigger(): bool { return true; }
    public function portas(): array { return ['saida']; }
    public function executar(array &$exec, array $config, PDO $db): string { return 'saida'; }
}

class FluxoNoTriggerManual extends FluxoNo
{
    // Disparado por código: FluxoMotor::iniciarExecucao(...)
    public function ehTrigger(): bool { return true; }
    public function portas(): array { return ['saida']; }
    public function executar(array &$exec, array $config, PDO $db): string { return 'saida'; }
}

// ═════════════════════════════════════════════════════════════════════════════
// FLUXO
// ═════════════════════════════════════════════════════════════════════════════

class FluxoNoEsperar extends FluxoNo
{
    // config: {"minutos":30} | {"horas":2} | {"dias":3}  (somam se combinados)
    public function portas(): array { return ['saida']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        // Se já dormiu neste nó e acordou, segue adiante
        $ctx = $this->ctx($exec);
        $marca = '_dormiu_' . ($exec['no_atual'] ?? '');
        if (!empty($ctx[$marca])) {
            unset($ctx[$marca]);
            $exec['contexto'] = $ctx;
            return 'saida';
        }

        $seg = ((int)($config['minutos'] ?? 0)) * 60
             + ((int)($config['horas'] ?? 0)) * 3600
             + ((int)($config['dias'] ?? 0)) * 86400;
        if ($seg <= 0) return 'saida'; // esperar 0 = passa direto

        $ctx[$marca] = 1;
        $exec['contexto']   = $ctx;
        $exec['dormir_ate'] = date('Y-m-d H:i:s', time() + $seg);
        return self::DORMIR;
    }
}

class FluxoNoEncerrar extends FluxoNo
{
    public function portas(): array { return []; }
    public function executar(array &$exec, array $config, PDO $db): string
    { return self::ENCERRAR; }
}

class FluxoNoSplitAb extends FluxoNo
{
    // config: {"pesos":[70,30]} → portas a/b (persistente por execução)
    public function portas(): array { return ['a','b']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $ctx = $this->ctx($exec);
        $marca = '_split_' . ($exec['no_atual'] ?? '');
        if (!empty($ctx[$marca])) return $ctx[$marca]; // decisão já tomada

        $pesos = $config['pesos'] ?? [50, 50];
        $pa = max(0, (int)($pesos[0] ?? 50));
        $pb = max(0, (int)($pesos[1] ?? 50));
        $total = $pa + $pb ?: 1;
        $porta = (mt_rand(1, $total) <= $pa) ? 'a' : 'b';

        $ctx[$marca] = $porta;
        $exec['contexto'] = $ctx;
        return $porta;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// CONDIÇÕES (true/false)
// ═════════════════════════════════════════════════════════════════════════════

class FluxoNoCondEventoOcorreu extends FluxoNo
{
    // config: {"evento":"produto_visto","janela_dias":7,"min":1,
    //          "mesma_entidade":false}
    // mesma_entidade=true → filtra pela entidade do contexto (produto_id...)
    public function portas(): array { return ['true','false']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        if (!$cid) return 'false';

        $evento = (string)($config['evento'] ?? '');
        if ($evento === '') return 'false';
        $dias = max(1, (int)($config['janela_dias'] ?? 7));
        $min  = max(1, (int)($config['min'] ?? 1));

        $sql = "SELECT COUNT(*) FROM eventos
                WHERE cliente_id = :c AND tipo = :t
                  AND criado_em > DATE_SUB(NOW(), INTERVAL :d DAY)";
        $params = [':c' => $cid, ':t' => $evento, ':d' => $dias];

        if (!empty($config['mesma_entidade'])) {
            $ctx = $this->ctx($exec);
            if (!empty($ctx['produto_id'])) {
                $sql .= " AND entidade_tipo='produto' AND entidade_id = :e";
                $params[':e'] = (int)$ctx['produto_id'];
            }
        }
        try {
            $st = $db->prepare($sql);
            foreach ($params as $k => $v) $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $st->execute();
            return ((int)$st->fetchColumn() >= $min) ? 'true' : 'false';
        } catch (Throwable $e) { return 'false'; }
    }
}

class FluxoNoCondTotalGasto extends FluxoNo
{
    // config: {"operador":">=","valor":500,"janela_dias":null}
    public function portas(): array { return ['true','false']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        if (!$cid) return 'false';

        $sql = "SELECT COALESCE(SUM(total),0) FROM pedidos
                WHERE cliente_id = :c AND status_pagamento = 'aprovado'";
        if (!empty($config['janela_dias'])) {
            $sql .= " AND criado_em > DATE_SUB(NOW(), INTERVAL " . (int)$config['janela_dias'] . " DAY)";
        }
        try {
            $st = $db->prepare($sql);
            $st->execute([':c' => $cid]);
            $soma = (float)$st->fetchColumn();
        } catch (Throwable $e) { return 'false'; }

        $v  = (float)($config['valor'] ?? 0);
        $op = (string)($config['operador'] ?? '>=');
        $ok = match ($op) {
            '>'  => $soma >  $v,
            '>=' => $soma >= $v,
            '<'  => $soma <  $v,
            '<=' => $soma <= $v,
            '='  => abs($soma - $v) < 0.01,
            default => false,
        };
        return $ok ? 'true' : 'false';
    }
}

class FluxoNoCondTemTag extends FluxoNo
{
    // config: {"tag":"vip"}
    public function portas(): array { return ['true','false']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        $tag = trim((string)($config['tag'] ?? ''));
        if (!$cid || $tag === '') return 'false';
        try {
            $st = $db->prepare("SELECT 1 FROM cliente_tags WHERE cliente_id=:c AND tag=:t LIMIT 1");
            $st->execute([':c' => $cid, ':t' => $tag]);
            return $st->fetchColumn() ? 'true' : 'false';
        } catch (Throwable $e) { return 'false'; }
    }
}

class FluxoNoCondAceitaMarketing extends FluxoNo
{
    // config: {"canal":"email"}  — via NotifPrefsService (opt-out padrão)
    public function portas(): array { return ['true','false']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        if (!$cid) return 'true'; // anônimo pediu explicitamente (avise-me)
        $canal = (string)($config['canal'] ?? 'email');
        if (!class_exists('NotifPrefsService')) return 'true';
        try {
            return NotifPrefsService::pode($cid, $canal, 'marketing') ? 'true' : 'false';
        } catch (Throwable $e) { return 'true'; }
    }
}

class FluxoNoCondTemMoto extends FluxoNo
{
    // config: {} — tem moto principal na garagem?
    public function portas(): array { return ['true','false']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        if (!$cid) return 'false';
        try {
            $st = $db->prepare(
                "SELECT 1 FROM cliente_veiculos WHERE cliente_id=:c AND principal=1 LIMIT 1"
            );
            $st->execute([':c' => $cid]);
            return $st->fetchColumn() ? 'true' : 'false';
        } catch (Throwable $e) { return 'false'; }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// AÇÕES
// ═════════════════════════════════════════════════════════════════════════════

class FluxoNoAcaoEmail extends FluxoNo
{
    // config: {"template_id":21,"quiet_hours":false}
    public function portas(): array { return ['saida']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        if (!empty($config['quiet_hours'])) {
            if ($ate = $this->foraDaJanela($db)) {
                $exec['dormir_ate'] = $ate;
                return self::DORMIR;
            }
        }

        $vars = $this->montarVars($exec, $db);
        $para = (string)($vars['email'] ?? '');
        if ($para === '') { $exec['erro_detalhe'] = 'sem email do destinatário'; return self::ERRO; }

        $tplId = (int)($config['template_id'] ?? 0);
        try {
            $st = $db->prepare("SELECT * FROM email_templates WHERE id=:id LIMIT 1");
            $st->execute([':id' => $tplId]);
            $tpl = $st->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) { $exec['erro_detalhe'] = "template $tplId não encontrado"; return self::ERRO; }

            $tplSvc  = new EmailTemplateService();
            $assunto = $tplSvc->renderInline($tpl['assunto'], $vars);
            $html    = $tplSvc->render($tpl['html'], $vars);
            $texto   = $tplSvc->htmlToText($html);
            [$html, $texto] = $tplSvc->injectUnsubscribe($html, $texto, (string)$vars['url_descadastro']);

            // Mesmo caminho comprovado do AutomacaoDispatchService pós-correção
            $provSvc  = new EmailProviderService();
            $provider = $provSvc->buildPadrao();
            $stP = $db->query("SELECT * FROM email_provedores WHERE ativo=1 AND padrao=1 LIMIT 1");
            $cfgProv = $stP ? $stP->fetch(PDO::FETCH_ASSOC) : null;
            if (!$cfgProv) {
                $stP = $db->query("SELECT * FROM email_provedores WHERE ativo=1 LIMIT 1");
                $cfgProv = $stP ? $stP->fetch(PDO::FETCH_ASSOC) : null;
            }
            if (!$cfgProv) { $exec['erro_detalhe'] = 'nenhum provedor de email ativo'; return self::ERRO; }

            $r = $provider->send([
                'from_email' => $cfgProv['remetente_email'],
                'from_name'  => $cfgProv['remetente_nome'] ?? '',
                'to_email'   => $para,
                'to_name'    => (string)($vars['nome'] ?? ''),
                'subject'    => $assunto,
                'html'       => $html,
                'text'       => $texto,
            ]);
            if (!$r->success) { $exec['erro_detalhe'] = (string)($r->error ?? 'falha no envio'); return self::ERRO; }
            return 'saida';

        } catch (Throwable $e) {
            $exec['erro_detalhe'] = mb_substr($e->getMessage(), 0, 400);
            return self::ERRO;
        }
    }
}

class FluxoNoAcaoNotificacao extends FluxoNo
{
    // config: {"categoria":"promocao","titulo":"...{{primeiro_nome}}...",
    //          "mensagem":null,"url":null,"imagem_url":null}
    public function portas(): array { return ['saida']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        if (!$cid) return 'saida'; // in-app exige conta — pula silencioso p/ anônimo
        if (!class_exists('NotificacaoService')) {
            $exec['erro_detalhe'] = 'NotificacaoService indisponível'; return self::ERRO;
        }
        $vars = $this->montarVars($exec, $db);
        try {
            NotificacaoService::criar([
                'categoria'  => (string)($config['categoria'] ?? 'sistema'),
                'tipo'       => 'fluxo_v2',
                'titulo'     => $this->interpolar((string)($config['titulo'] ?? ''), $vars),
                'mensagem'   => isset($config['mensagem']) ? $this->interpolar((string)$config['mensagem'], $vars) : null,
                'url'        => isset($config['url']) ? $this->interpolar((string)$config['url'], $vars) : null,
                'imagem_url' => $config['imagem_url'] ?? ($vars['produto_img'] ?? null),
            ], [['tipo' => 'cliente', 'id' => $cid]]);
            return 'saida';
        } catch (Throwable $e) {
            $exec['erro_detalhe'] = mb_substr($e->getMessage(), 0, 400);
            return self::ERRO;
        }
    }
}

class FluxoNoAcaoWhatsapp extends FluxoNo
{
    // config: {"template":"pedido_criado","body_params":["{{primeiro_nome}}","{{pedido_codigo}}"],
    //          "header_param":null,"botao_url_param":null,"quiet_hours":true}
    public function portas(): array { return ['saida']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        // WhatsApp respeita quiet hours por padrão
        $qh = array_key_exists('quiet_hours', $config) ? (bool)$config['quiet_hours'] : true;
        if ($qh && ($ate = $this->foraDaJanela($db))) {
            $exec['dormir_ate'] = $ate;
            return self::DORMIR;
        }

        $cid = $this->clienteId($exec);
        if (!$cid) return 'saida'; // sem cadastro = sem telefone confiável
        if (!class_exists('WhatsappService') || !class_exists('MetaCloudService')) {
            return 'saida'; // canal indisponível — não é erro do fluxo
        }

        try {
            $st = $db->prepare(
                "SELECT c.id, c.celular, u.nome FROM clientes c
                 JOIN usuarios u ON u.id = c.usuario_id WHERE c.id=:id LIMIT 1"
            );
            $st->execute([':id' => $cid]);
            $cli = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cli || empty($cli['celular'])) return 'saida'; // sem telefone — pula

            $vars = $this->montarVars($exec, $db);
            $resolve = fn($p) => $this->interpolar((string)$p, $vars);

            $componentes = [];
            if (!empty($config['header_param'])) {
                $componentes[] = MetaCloudService::headerTexto($resolve($config['header_param']));
            }
            $bodyParams = array_map($resolve, (array)($config['body_params'] ?? []));
            if ($bodyParams) $componentes[] = MetaCloudService::body(...$bodyParams);
            if (!empty($config['botao_url_param'])) {
                $componentes[] = MetaCloudService::botaoUrl(0, $resolve($config['botao_url_param']));
            }

            WhatsappService::sendTemplate(
                (string)$cli['celular'],
                'fluxo_v2',
                (string)($config['template'] ?? ''),
                $componentes, 'pt_BR', $cid, []
            );
            return 'saida';
        } catch (Throwable $e) {
            // WhatsApp indisponível não deve matar a jornada — loga e segue
            if (class_exists('LogService')) {
                try { LogService::warning('fluxo acao_whatsapp', ['erro' => $e->getMessage()]); } catch (Throwable $x) {}
            }
            return 'saida';
        }
    }
}

class FluxoNoAcaoTag extends FluxoNo
{
    // config: {"acao":"adicionar|remover","tag":"lead_quente"}
    public function portas(): array { return ['saida']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $cid = $this->clienteId($exec);
        $tag = mb_substr(trim((string)($config['tag'] ?? '')), 0, 60);
        if (!$cid || $tag === '') return 'saida';
        try {
            if (($config['acao'] ?? 'adicionar') === 'remover') {
                $db->prepare("DELETE FROM cliente_tags WHERE cliente_id=:c AND tag=:t")
                   ->execute([':c' => $cid, ':t' => $tag]);
            } else {
                $db->prepare("INSERT IGNORE INTO cliente_tags (cliente_id, tag) VALUES (:c,:t)")
                   ->execute([':c' => $cid, ':t' => $tag]);
            }
        } catch (Throwable $e) {}
        return 'saida';
    }
}


// ═════════════════════════════════════════════════════════════════════════════
// REGISTRY
// ═════════════════════════════════════════════════════════════════════════════

class FluxoNoRegistry
{
    /** tipo_no → classe */
    private const MAPA = [
        'trigger_evento'        => FluxoNoTriggerEvento::class,
        'trigger_manual'        => FluxoNoTriggerManual::class,
        'esperar'               => FluxoNoEsperar::class,
        'encerrar'              => FluxoNoEncerrar::class,
        'split_ab'              => FluxoNoSplitAb::class,
        'cond_evento_ocorreu'   => FluxoNoCondEventoOcorreu::class,
        'cond_total_gasto'      => FluxoNoCondTotalGasto::class,
        'cond_tem_tag'          => FluxoNoCondTemTag::class,
        'cond_aceita_marketing' => FluxoNoCondAceitaMarketing::class,
        'cond_tem_moto'         => FluxoNoCondTemMoto::class,
        'acao_email'            => FluxoNoAcaoEmail::class,
        'acao_notificacao'      => FluxoNoAcaoNotificacao::class,
        'acao_whatsapp'         => FluxoNoAcaoWhatsapp::class,
        'acao_tag'              => FluxoNoAcaoTag::class,
        'esperar_evento'        => FluxoNoEsperarEvento::class,   // ← ADICIONAR
        'acao_webhook'          => FluxoNoAcaoWebhook::class,      // ← ADICIONAR
    ];

    /** @var array<string,FluxoNo> instâncias (stateless, reutilizáveis) */
    private static array $inst = [];

    public static function existe(string $tipo): bool
    {
        return isset(self::MAPA[$tipo]);
    }

    public static function obter(string $tipo): ?FluxoNo
    {
        if (!isset(self::MAPA[$tipo])) return null;
        return self::$inst[$tipo] ??= new (self::MAPA[$tipo])();
    }

    public static function ehTrigger(string $tipo): bool
    {
        $no = self::obter($tipo);
        return $no ? $no->ehTrigger() : false;
    }

    /** Lista tipos + portas (para o admin e o canvas da Fase 2). */
    public static function catalogo(): array
    {
        $out = [];
        foreach (self::MAPA as $tipo => $classe) {
            $no = self::obter($tipo);
            $out[$tipo] = ['portas' => $no->portas(), 'trigger' => $no->ehTrigger()];
        }
        return $out;
    }
}


/**
 * esperar_evento — dorme até um evento ocorrer OU o timeout estourar.
 * O nó mais poderoso do catálogo: ramifica a jornada pela REAÇÃO do cliente.
 *
 * config: {
 *   "evento": "produto_visto",          // tipo do evento no stream
 *   "entidade_tipo": null,              // opcional: exige entidade (produto...)
 *   "mesma_entidade": false,            // true = espera o MESMO produto do contexto
 *   "timeout": {"dias":2,"horas":0,"minutos":0}   // padrão 24h se ausente/zero
 * }
 * portas: evento (ocorreu na janela) | timeout (não ocorreu a tempo)
 *
 * Mecânica: na 1ª execução grava a "spec" no contexto e devolve AGUARDAR_EVENTO
 * (o motor põe a execução em status 'aguardando_evento'). O worker, na fase de
 * resolução (FluxoMotor::resolverEsperasEvento), detecta evento/timeout e
 * reativa a execução com o marcador da porta — a 2ª execução do nó lê o
 * marcador e segue pela porta certa. Mesmo padrão do nó 'esperar'.
 */
class FluxoNoEsperarEvento extends FluxoNo
{
    public function portas(): array { return ['evento', 'timeout']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $ctx   = $this->ctx($exec);
        $chave = (string)($exec['no_atual'] ?? '');
        $mResolvido = '_ee_resolvido_' . $chave;

        // 2ª passada: o resolver já decidiu — segue pela porta e limpa marcadores
        if (isset($ctx[$mResolvido])) {
            $porta = $ctx[$mResolvido];
            unset($ctx[$mResolvido], $ctx['_ee_spec_' . $chave]);
            $exec['contexto'] = $ctx;
            return in_array($porta, ['evento', 'timeout'], true) ? $porta : 'timeout';
        }

        // 1ª passada: monta a espera
        $evento = trim((string)($config['evento'] ?? ''));
        if ($evento === '') { $exec['erro_detalhe'] = 'esperar_evento sem evento'; return self::ERRO; }

        // Sem cliente nem token não há como o evento ser observado → vai de timeout
        $temSujeito = !empty($exec['cliente_id']) || !empty($ctx['_visitante_token']);

        // Entidade alvo (mesma_entidade usa o produto do contexto)
        $entidadeTipo = $config['entidade_tipo'] ?? null;
        $entidadeId   = null;
        if (!empty($config['mesma_entidade']) && !empty($ctx['produto_id'])) {
            $entidadeTipo = 'produto';
            $entidadeId   = (int)$ctx['produto_id'];
        }

        $seg = ((int)($config['timeout']['minutos'] ?? 0)) * 60
             + ((int)($config['timeout']['horas'] ?? 0)) * 3600
             + ((int)($config['timeout']['dias'] ?? 0)) * 86400;
        if ($seg <= 0) $seg = 86400; // padrão 24h

        $agora     = date('Y-m-d H:i:s');
        $timeoutEm = date('Y-m-d H:i:s', time() + $seg);

        $ctx['_ee_spec_' . $chave] = [
            'evento'        => $evento,
            'entidade_tipo' => $entidadeTipo,
            'entidade_id'   => $entidadeId,
            'desde'         => $agora,
            'timeout_em'    => $timeoutEm,
            'observavel'    => $temSujeito ? 1 : 0,
        ];
        $exec['contexto']         = $ctx;
        $exec['evento_aguardado'] = $evento;
        $exec['timeout_em']       = $timeoutEm;
        return self::AGUARDAR_EVENTO;
    }
}


/**
 * acao_webhook — dispara um POST JSON para uma URL externa.
 * Integra o fluxo com qualquer sistema (ERP, CRM, planilha, Zapier...).
 *
 * config: {
 *   "url": "https://exemplo.com/hook",
 *   "headers": {"X-Chave": "..."},        // opcional
 *   "hmac_secret": null,                  // opcional: assina o corpo (X-Signature-SHA256)
 *   "parar_se_falhar": false              // padrão: loga e segue (não mata a jornada)
 * }
 * portas: saida
 *
 * Segurança: recusa URLs para rede interna/loopback (anti-SSRF), sem redirect,
 * timeout curto para não travar o worker.
 */
class FluxoNoAcaoWebhook extends FluxoNo
{
    public function portas(): array { return ['saida']; }

    public function executar(array &$exec, array $config, PDO $db): string
    {
        $url = trim((string)($config['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $exec['erro_detalhe'] = 'webhook sem URL válida';
            return self::ERRO;
        }
        if ($this->urlPerigosa($url)) {
            $exec['erro_detalhe'] = 'webhook bloqueado: URL aponta para rede interna';
            return self::ERRO;
        }

        $vars = $this->montarVars($exec, $db);
        $ctx  = $this->ctx($exec);

        $payload = [
            'evento_fluxo' => true,
            'fluxo_id'     => (int)($exec['fluxo_id'] ?? 0),
            'cliente_id'   => $exec['cliente_id'] ?? null,
            'enviado_em'   => date('c'),
            'cliente'      => [
                'nome'  => $vars['nome']  ?? null,
                'email' => $vars['email'] ?? null,
            ],
            'contexto'     => array_filter($ctx, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY),
        ];
        if (!empty($vars['produto_nome'])) {
            $payload['produto'] = ['nome' => $vars['produto_nome'], 'url' => $vars['produto_url'] ?? null];
        }
        if (!empty($vars['moto_label'])) {
            $payload['moto'] = ['label' => $vars['moto_label'], 'apelido' => $vars['moto_apelido'] ?? null];
        }

        $body    = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type: application/json', 'User-Agent: SportMoto-Fluxo/1.0'];
        foreach ((array)($config['headers'] ?? []) as $k => $v) {
            if (is_string($k) && is_scalar($v)) $headers[] = $k . ': ' . $v;
        }
        if (!empty($config['hmac_secret'])) {
            $headers[] = 'X-Signature-SHA256: ' . hash_hmac('sha256', $body, (string)$config['hmac_secret']);
        }

        $ok = false; $detalhe = '';
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_FOLLOWLOCATION => false, // sem redirect (anti-SSRF)
                ]);
                curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                $ok = ($code >= 200 && $code < 300);
                $detalhe = $ok ? '' : ($err ?: "HTTP $code");
            } else {
                $ctxHttp = stream_context_create(['http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body,
                    'timeout'       => 8,
                    'ignore_errors' => true,
                ]]);
                $resp = @file_get_contents($url, false, $ctxHttp);
                $code = 0;
                if (isset($http_response_header[0]) &&
                    preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
                    $code = (int)$m[1];
                }
                $ok = ($code >= 200 && $code < 300);
                $detalhe = $ok ? '' : "HTTP $code";
            }
        } catch (Throwable $e) {
            $detalhe = mb_substr($e->getMessage(), 0, 300);
        }

        if ($ok) return 'saida';

        // Falhou: por padrão loga e segue (webhook não deve matar a jornada)
        if (class_exists('LogService')) {
            try { LogService::warning('fluxo acao_webhook falhou', ['url' => $url, 'detalhe' => $detalhe]); } catch (Throwable $x) {}
        }
        if (!empty($config['parar_se_falhar'])) {
            $exec['erro_detalhe'] = 'webhook falhou: ' . $detalhe;
            return self::ERRO;
        }
        return 'saida';
    }

    /** Bloqueia loopback, redes privadas e link-local (anti-SSRF). */
    private function urlPerigosa(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return true;

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false; // não resolveu: deixa o curl decidir

        // IPv6 loopback/local
        if (strpos($ip, ':') !== false) {
            return ($ip === '::1' || stripos($ip, 'fc') === 0 || stripos($ip, 'fd') === 0 || stripos($ip, 'fe80') === 0);
        }

        $long = ip2long($ip);
        if ($long === false) return false;
        $blocos = [
            ['0.0.0.0',     '255.0.0.0'],       // this-network
            ['127.0.0.0',   '255.0.0.0'],       // loopback
            ['10.0.0.0',    '255.0.0.0'],       // privado
            ['172.16.0.0',  '255.240.0.0'],     // privado
            ['192.168.0.0', '255.255.0.0'],     // privado
            ['169.254.0.0', '255.255.0.0'],     // link-local
        ];
        foreach ($blocos as [$net, $mask]) {
            if (($long & ip2long($mask)) === (ip2long($net) & ip2long($mask))) return true;
        }
        return false;
    }
}

