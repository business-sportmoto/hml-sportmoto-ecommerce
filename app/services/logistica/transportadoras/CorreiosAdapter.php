<?php
/**
 * Correios (integração DIRETA via CWS — Correios Web Services).
 *
 * IMPORTANTE: para a maioria das lojas, o caminho recomendado é o
 * MelhorEnvioAdapter, que já opera Correios (PAC, SEDEX, Mini) além de
 * Jadlog/Loggi/Azul/LATAM/J&T — sem contrato direto com os Correios.
 *
 * Este adapter existe para quem tem CONTRATO PRÓPRIO com os Correios e
 * quer falar direto com o CWS. As APIs do CWS (Preço, Prazo, PrePostagem,
 * SRO Rastro) dependem do contrato/cartão de postagem do cliente e dos
 * escopos liberados — por isso as operações abaixo estão marcadas como
 * pontos de extensão, para serem completadas com os dados do SEU contrato.
 *
 * Config esperada em log_transportadoras.config (JSON):
 *   usuario           (usuário do CWS)
 *   codigo_acesso     (código de acesso/API do CWS)
 *   cartao_postagem   (número do cartão de postagem)
 *   contrato          (número do contrato)
 *
 * Autenticação CWS (visão geral): obtém-se um token Bearer em
 *   POST {base}/token/v1/autentica/cartaopostagem
 *   Header Authorization: Basic base64(usuario:codigo_acesso)
 *   Body   { "numero": "<cartao_postagem>" }
 * O token retornado é reutilizado até expirar (cacheável em config_extra,
 * padrão análogo ao garantirProdutoAvulso() da Vindi nos pagamentos).
 */
class CorreiosAdapter extends TransportadoraBase
{
    private function baseUrl(): string
    {
        $amb = $this->transportadora['ambiente'] ?? 'producao';
        // Homologação dos Correios usa host próprio; produção usa api.correios.com.br.
        return $amb === 'producao'
            ? 'https://api.correios.com.br'
            : 'https://apihom.correios.com.br';
    }

    private function credenciaisOk(): bool
    {
        return !empty($this->config['usuario'])
            && !empty($this->config['codigo_acesso'])
            && !empty($this->config['cartao_postagem']);
    }

    /**
     * Obtém (e futuramente cacheia) o token Bearer do CWS.
     * Retorna ['ok'=>bool, 'token'=>?string, 'erro'=>?string].
     */
    private function autenticar(): array
    {
        if (!$this->credenciaisOk()) {
            return ['ok' => false, 'token' => null, 'erro' => 'Credenciais do CWS incompletas (usuário, código de acesso e cartão de postagem).'];
        }
        $basic = base64_encode($this->config['usuario'] . ':' . $this->config['codigo_acesso']);
        $r = $this->requisicaoHttp(
            'POST',
            $this->baseUrl() . '/token/v1/autentica/cartaopostagem',
            ['numero' => (string)$this->config['cartao_postagem']],
            ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Basic ' . $basic]
        );
        $ok = $r['erro'] === null && $r['status'] >= 200 && $r['status'] < 300 && !empty($r['json']['token']);
        $this->logComunicacao(
            'teste',
            ['acao' => 'autenticar_cws'],
            ['status' => $r['status'], 'erro' => $r['erro'], 'expira' => $r['json']['expiraEm'] ?? null],
            $ok,
            $r['status'] ?: null,
            $r['ms']
        );
        if (!$ok) {
            $msg = $r['json']['msgs'][0] ?? ($r['json']['message'] ?? ('Falha na autenticação CWS (HTTP ' . $r['status'] . ')'));
            return ['ok' => false, 'token' => null, 'erro' => (string)$msg];
        }
        return ['ok' => true, 'token' => (string)$r['json']['token'], 'expiraEm' => $r['json']['expiraEm'] ?? null, 'erro' => null];
    }

    public function testarConexao(): array
    {
        $auth = $this->autenticar();
        if ($auth['ok']) {
            return ['ok' => true, 'mensagem' => 'Autenticação CWS OK — token obtido.'];
        }
        return ['ok' => false, 'mensagem' => $auth['erro'] ?? 'Falha ao autenticar no CWS.'];
    }

    /* -----------------------------------------------------------------
       Pontos de extensão — completar com as APIs do SEU contrato CWS.
       Cada método já autentica e devolve retorno padronizado; basta
       preencher a chamada específica (Preço/Prazo, PrePostagem, SRO).
       ----------------------------------------------------------------- */

    public function cotar(array $params): array
    {
        $cepOrigem  = preg_replace('/\D/', '', (string)($params['cep_origem'] ?? $this->cepOrigem())) ?? '';
        $cepDestino = preg_replace('/\D/', '', (string)($params['cep_destino'] ?? '')) ?? '';
        if (strlen($cepDestino) !== 8 || strlen($cepOrigem) !== 8) return ['ok' => true, 'opcoes' => []];

        $servicos = $this->servicosContrato();
        if (!$servicos) return ['ok' => true, 'opcoes' => []]; // nenhum serviço habilitado

        $token = $this->tokenCorreios();
        if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios (verifique usuário, código de acesso e cartão).'];

        $dim         = $this->dimensoesDe($params);
        $contrato    = (string)($this->config['contrato'] ?? '');
        $dr          = (int)($this->config['dr'] ?? 0);
        $tpObjeto    = (int)($this->config['tp_objeto'] ?? 2);
        // Valor declarado (serviço adicional 019) só quando a transportadora está
        // configurada para usá-lo. Antes o adapter ignorava a flag e anexava o 019
        // sempre que havia valor de carrinho — e todo serviço que não aceita o
        // adicional era recusado inteiro pela API (ERP-054), derrubando a cotação.
        $usaValorDeclarado = !empty($this->transportadora['usa_valor_declarado']);
        $vlDeclarado = $usaValorDeclarado
            ? (float)($params['valor_declarado'] ?? $params['valor'] ?? 0)
            : 0.0;

        // 1) Prazo por serviço (/prazo/v1/nacional/{cod})
        $prazos = [];
        foreach ($servicos as $s) {
            $pz = $this->prazoCorreios($token, (string)$s['codigo'], $cepOrigem, $cepDestino);
            if ($pz !== null) $prazos[(string)$s['codigo']] = $pz;
        }

        // 2) Preço em lote (/preco/v1/nacional) — psObjeto em GRAMAS, dimensões em cm.
        $itemBase = [
            'nuContrato'  => (string)$contrato,
            'nuDR'        => (int)$dr,
            'cepOrigem'   => $cepOrigem,
            'cepDestino'  => $cepDestino,
            'psObjeto'    => (string)max(1, (int)round($dim['peso_g'])),  // GRAMAS
            'tpObjeto'    => (string)$tpObjeto,
            'comprimento' => (string)(int)round($dim['comprimento_cm']),
            'largura'     => (string)(int)round($dim['largura_cm']),
            'altura'      => (string)(int)round($dim['altura_cm']),
            'dtEvento'    => date('d/m/Y'),
        ];

        $codigos = array_map(static fn($s) => substr((string)$s['codigo'], 0, 10), $servicos);
        $lista   = $this->precoLote($token, $codigos, $itemBase, $vlDeclarado);
        if ($lista === null) return ['ok' => false, 'erro' => $this->ultimoErroPreco ?: 'Falha na cotação Correios'];

        // Um serviço que o contrato não atende (ERP-006) ou que recusa um adicional
        // não pode sumir calado: sem isso, a tela mostra "sem opções" e não há como
        // saber que a origem/destino é que não é atendida.
        $recusados = [];
        $opcoes = [];
        foreach ($lista as $res) {
            if (!is_array($res)) continue;
            $cod = (string)($res['coProduto'] ?? '');
            if ($cod === '') continue;
            if (!empty($res['txErro'])) {
                $recusados[] = ['servico' => $cod, 'nome' => $this->nomeServico($servicos, $cod), 'erro' => (string)$res['txErro']];
                continue;
            }
            $valor = self::precoFinal($res);
            if ($valor <= 0) continue;
            $opcoes[] = [
                'servico_codigo' => $cod,
                'servico_nome'   => $this->nomeServico($servicos, $cod),
                'prazo_dias'     => $prazos[$cod] ?? null,
                'valor'          => $this->aplicarMargem($valor),
                'tipo_postagem'  => 'postagem',
                'categoria'      => 'correios',
                'avisos'         => [],
            ];
        }

        if ($recusados && class_exists('LogService')) {
            LogService::warning('Correios recusou serviço(s) na cotação', [
                'cep_origem' => $cepOrigem, 'cep_destino' => $cepDestino, 'recusados' => $recusados,
            ]);
        }

        return ['ok' => true, 'opcoes' => $opcoes, 'recusados' => $recusados];
    }

    /** Guarda o erro da última tentativa de preço, para a mensagem ao chamador. */
    private ?string $ultimoErroPreco = null;

    /**
     * Lista de produtos da resposta de /preco, venha ela em 200, 206 ou erro.
     *
     * O lote responde um array de {coProduto, pcFinal|txErro}. Só se considera
     * falha de verdade quando nem isso vem — aí não há o que reaproveitar.
     *
     * @return array<int,array>|null
     */
    private static function listaProdutos(array $r): ?array
    {
        $j = $r['json'] ?? null;
        if (!is_array($j) || !$j) return null;
        foreach ($j as $item) {
            if (is_array($item) && isset($item['coProduto'])) return $j;
        }
        return null;
    }

    /**
     * Preço em lote, com uma segunda tentativa sem o serviço adicional 019.
     *
     * Os Correios recusam o produto INTEIRO (ERP-054) quando o adicional não vale
     * para aquele serviço — e o lote volta 206 com o resto ok. Antes isso derrubava
     * a opção silenciosamente; agora quem foi recusado só por causa do adicional é
     * recotado sem ele, de modo que uma cotação nunca se perde por esse motivo.
     *
     * @param string[] $codigos
     * @return array<int,array>|null  null = falha de transporte/HTTP
     */
    private function precoLote(string $token, array $codigos, array $itemBase, float $vlDeclarado): ?array
    {
        $this->ultimoErroPreco = null;

        $monta = function (array $codigos, bool $comAdicional) use ($itemBase, $vlDeclarado): array {
            $payload = ['idLote' => '1', 'parametrosProduto' => []];
            foreach (array_values($codigos) as $i => $cod) {
                $item = ['coProduto' => $cod, 'nuRequisicao' => (string)($i + 1)] + $itemBase;
                if ($comAdicional && $vlDeclarado > 0) {
                    $item['vlDeclarado'] = number_format($vlDeclarado, 2, '.', '');
                    $item['servicosAdicionais'] = [['coServAdicional' => '019']];
                }
                foreach ($item as $k => $v) { if ($v === '' || $v === null) unset($item[$k]); }
                $payload['parametrosProduto'][] = $item;
            }
            return $payload;
        };

        $r = $this->reqCorreios('POST', '/preco/v1/nacional', $monta($codigos, true), $token, 'preco');
        $lista = self::listaProdutos($r);

        // Quando TODOS os produtos falham, a API responde fora da faixa 2xx (e não
        // 206 parcial). Abortar aqui impediria a segunda tentativa justamente no
        // caso em que ela mais importa, então o corpo é aproveitado mesmo assim —
        // desde que traga a lista de produtos com o erro de cada um.
        if ($lista === null) {
            $this->ultimoErroPreco = $this->erroCorreios($r, 'Falha na cotação Correios');
            return null;
        }
        if ($vlDeclarado <= 0) return $lista;

        // Quem caiu especificamente por causa do adicional ganha uma segunda chance.
        $refazer = [];
        foreach ($lista as $res) {
            if (is_array($res) && !empty($res['txErro']) && stripos((string)$res['txErro'], 'ERP-054') !== false) {
                $refazer[] = (string)$res['coProduto'];
            }
        }
        if (!$refazer) return $lista;

        $r2 = $this->reqCorreios('POST', '/preco/v1/nacional', $monta($refazer, false), $token, 'preco');
        $novos = self::listaProdutos($r2);
        if ($novos === null) return $lista;   // mantém o resultado parcial

        $porCodigo = [];
        foreach ($novos as $n) if (is_array($n) && !empty($n['coProduto'])) $porCodigo[(string)$n['coProduto']] = $n;
        foreach ($lista as $i => $res) {
            $cod = is_array($res) ? (string)($res['coProduto'] ?? '') : '';
            if ($cod !== '' && isset($porCodigo[$cod])) $lista[$i] = $porCodigo[$cod];
        }
        return $lista;
    }

    /* -------- token (cacheado em memória + na config da transportadora) -------- */

    private static array $tokenMem = [];

    private function tokenCorreios(): ?string
    {
        $chave = (string)($this->config['cartao_postagem'] ?? $this->config['usuario'] ?? 'co');
        if (isset(self::$tokenMem[$chave]) && self::$tokenMem[$chave]['exp'] > time() + 60) return self::$tokenMem[$chave]['tok'];

        $tok = (string)($this->config['_token'] ?? '');
        $exp = strtotime((string)($this->config['_token_exp'] ?? '')) ?: 0;
        if ($tok !== '' && $exp > time() + 60) { self::$tokenMem[$chave] = ['tok' => $tok, 'exp' => $exp]; return $tok; }

        $auth = $this->autenticar();
        if (empty($auth['ok'])) return null;
        $expIso = (string)($auth['expiraEm'] ?? date('c', time() + 3600));
        $expTs  = strtotime($expIso) ?: (time() + 3600);
        self::$tokenMem[$chave] = ['tok' => $auth['token'], 'exp' => $expTs];
        $this->persistirToken((string)$auth['token'], $expIso);
        return (string)$auth['token'];
    }

    private function persistirToken(string $token, string $expiraEm): void
    {
        $this->config['_token'] = $token;
        $this->config['_token_exp'] = $expiraEm;
        $id = $this->transportadoraId();
        if (!$id) return;
        try {
            $pdo = Database::getInstance()->getConnection();
            $st = $pdo->prepare("SELECT config FROM log_transportadoras WHERE id = :id");
            $st->execute([':id' => $id]);
            $cfg = json_decode((string)$st->fetchColumn(), true) ?: [];
            $cfg['_token'] = $token;
            $cfg['_token_exp'] = $expiraEm;
            $pdo->prepare("UPDATE log_transportadoras SET config = :c WHERE id = :id")
                ->execute([':c' => json_encode($cfg, JSON_UNESCAPED_UNICODE), ':id' => $id]);
        } catch (\Throwable $e) { /* cache é best-effort */ }
    }

    /* -------- cotação: helpers -------- */

    private function prazoCorreios(string $token, string $cod, string $cepO, string $cepD): ?int
    {
        $hoje = date('d-m-Y');
        $final = date('d-m-Y', strtotime('+30 days'));
        $path = '/prazo/v1/nacional/' . rawurlencode($cod)
            . '?cepOrigem=' . $cepO . '&cepDestino=' . $cepD
            . '&dtEvento=' . $hoje . '&dataFinal=' . $final;
        $r = $this->reqCorreios('GET', $path, null, $token, 'prazo');
        if (!$this->okHttp($r)) return null;
        $j = $r['json'] ?? [];
        $pz = $j['prazoEntrega'] ?? ($j[0]['prazoEntrega'] ?? null);
        return $pz !== null ? (int)$pz : null;
    }

    /** Extrai o preço final (Correios manda string BR: "1.234,56"). */
    private static function precoFinal(array $res): float
    {
        $v = $res['pcFinal'] ?? $res['pcProduto'] ?? $res['pcBase'] ?? '';
        if ($v === '' || $v === null) return 0.0;
        $s = (string)$v;
        if (str_contains($s, ',')) $s = str_replace('.', '', $s);
        return (float)str_replace(',', '.', $s);
    }

    /**
     * Serviços habilitados para frete de IDA.
     *
     * Os códigos de logística reversa (03247 Pac Reverso, 03301 Sedex Reverso)
     * ficam na mesma tabela e cotam normalmente na API — sem este filtro eles
     * apareciam como opção de envio na vitrine, com prazo e preço de reversa.
     * A reversa tem caminho próprio (ReversaService/SOAP) e não passa por aqui.
     */
    private function servicosContrato(): array
    {
        $id = $this->transportadoraId();
        if (!$id) return [];
        try {
            $pdo = Database::getInstance()->getConnection();
            $st = $pdo->prepare(
                "SELECT codigo, nome, modalidade
                   FROM log_transportadora_servicos
                  WHERE transportadora_id = :id
                    AND habilitado = 1
                    AND (modalidade IS NULL OR modalidade <> 'reverso')
                  ORDER BY nome"
            );
            $st->execute([':id' => $id]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }
    }

    private function nomeServico(array $servicos, string $cod): string
    {
        foreach ($servicos as $s) if ((string)$s['codigo'] === $cod) return (string)($s['nome'] ?? $cod);
        return $cod;
    }

    /** Peso (g) e dimensões (cm) — soma pesos e usa a maior medida por eixo. */
    private function dimensoesDe(array $params): array
    {
        $md = ['peso_g' => 500.0, 'comprimento_cm' => 16.0, 'largura_cm' => 11.0, 'altura_cm' => 11.0];
        $vols = is_array($params['volumes'] ?? null) ? array_values($params['volumes']) : [];
        if (!$vols) {
            return ['peso_g' => (float)($params['peso_g'] ?? $md['peso_g']), 'comprimento_cm' => $md['comprimento_cm'], 'largura_cm' => $md['largura_cm'], 'altura_cm' => $md['altura_cm']];
        }
        $peso = 0.0; $c = 0.0; $l = 0.0; $a = 0.0;
        foreach ($vols as $v) {
            $peso += (float)($v['peso_g'] ?? $v['peso_cobranca_g'] ?? 0);
            $c = max($c, (float)($v['comprimento_cm'] ?? $v['comprimento'] ?? $v['length'] ?? 0));
            $l = max($l, (float)($v['largura_cm'] ?? $v['largura'] ?? $v['width'] ?? 0));
            $a = max($a, (float)($v['altura_cm'] ?? $v['altura'] ?? $v['height'] ?? 0));
        }
        return [
            'peso_g'         => $peso > 0 ? $peso : $md['peso_g'],
            'comprimento_cm' => $c ?: $md['comprimento_cm'],
            'largura_cm'     => $l ?: $md['largura_cm'],
            'altura_cm'      => $a ?: $md['altura_cm'],
        ];
    }

    /* -------- infra REST (token Bearer) -------- */

    private function reqCorreios(string $metodo, string $path, $corpo, string $token, string $tipo): array
    {
        $r = $this->requisicaoHttp($metodo, $this->baseUrl() . $path, $corpo,
            ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json']);
        $this->logComunicacao('correios_' . $tipo,
            ['path' => $path, 'corpo' => is_array($corpo) ? $corpo : []],
            $r['json'] ?? ['body' => mb_substr($r['body'] ?? '', 0, 500)],
            $this->okHttp($r), $r['status'] ?? null, $r['ms'] ?? null);
        return $r;
    }

    private function okHttp(array $r): bool
    {
        $s = (int)($r['status'] ?? 0);
        return $s >= 200 && $s < 300 && empty($r['erro']);
    }

    private function erroCorreios(array $r, string $fallback): string
    {
        $j = $r['json'] ?? null;
        if (is_array($j)) {
            if (!empty($j['msgs'][0])) return (string)$j['msgs'][0];
            foreach (['txErro', 'message', 'msg', 'erro'] as $k) if (!empty($j[$k]) && is_string($j[$k])) return $j[$k];
            // erro em lote: array de {coProduto, txErro}
            if (isset($j[0]['txErro'])) return 'Correios: ' . implode(' | ', array_map(static fn($x) => ($x['coProduto'] ?? '') . ' ' . ($x['txErro'] ?? ''), $j));
        }
        if (!empty($r['erro'])) return (string)$r['erro'];
        $s = (int)($r['status'] ?? 0);
        return $s ? "{$fallback} (HTTP {$s})" : $fallback;
    }

    public function rastrear(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') return ['ok' => false, 'erro' => 'Código de rastreio vazio.'];
        $token = $this->tokenCorreios();
        if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios.'];

        $r = $this->reqCorreios('GET', '/srorastro/v1/objetos/' . rawurlencode($codigo) . '?resultado=T', null, $token, 'sro');
        if (!$this->okHttp($r)) return ['ok' => false, 'erro' => $this->erroCorreios($r, 'Falha ao rastrear nos Correios')];
        $obj = $r['json']['objetos'][0] ?? [];
        $lista = is_array($obj['eventos'] ?? null) ? $obj['eventos'] : [];

        $eventos = [];
        foreach ($lista as $ev) {
            $desc = (string)($ev['descricao'] ?? '');
            $eventos[] = [
                'data'                  => self::dataSro($ev['dtHrCriado'] ?? ''),
                'status_transportadora' => trim((string)($ev['codigo'] ?? '') . '/' . (string)($ev['tipo'] ?? ''), '/'),
                'status_interno'        => self::statusSro($desc),
                'descricao'             => $desc,
                'local'                 => self::localSro($ev),
            ];
        }
        $eventos = array_reverse($eventos); // SRO vem do mais recente -> cronológico

        $atual = (string)($lista[0]['descricao'] ?? '');
        return [
            'ok'              => true,
            'status_interno'  => $atual !== '' ? self::statusSro($atual) : null,
            'eventos'         => $eventos,
            'codigo_rastreio' => $codigo,
        ];
    }

    /** Mapeia a descrição do evento SRO para o estado interno do módulo. */
    public static function statusSro(string $descricao): string
    {
        $d = mb_strtolower(trim($descricao));
        if ($d === '') return 'em_transito';
        if (mb_strpos($d, 'entregue') !== false) return 'entregue';
        if (mb_strpos($d, 'saiu para entrega') !== false || mb_strpos($d, 'saiu para') !== false) return 'saiu_entrega';
        if (mb_strpos($d, 'devolv') !== false) return 'devolucao';
        if (mb_strpos($d, 'postado') !== false || mb_strpos($d, 'postagem') !== false) return 'postado';
        if (mb_strpos($d, 'etiqueta emitida') !== false || mb_strpos($d, 'pré-postagem') !== false || mb_strpos($d, 'pre-postagem') !== false) return 'postado';
        foreach (['não entregue', 'nao entregue', 'ausente', 'endereço', 'endereco incorreto', 'roubo', 'furto', 'extraviad', 'avaria', 'recus', 'devolução ao remetente'] as $oc) {
            if (mb_strpos($d, $oc) !== false) return 'ocorrencia';
        }
        return 'em_transito';
    }

    private static function dataSro(string $iso): string
    {
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i:s', $ts) : (string)$iso;
    }

    private static function localSro(array $ev): string
    {
        $u = $ev['unidade'] ?? [];
        $end = is_array($u['endereco'] ?? null) ? $u['endereco'] : [];
        $cidade = (string)($end['cidade'] ?? '');
        $uf = (string)($end['uf'] ?? '');
        if ($cidade !== '' || $uf !== '') return trim($cidade . ($uf !== '' ? '/' . $uf : ''), '/');
        return (string)($u['tipo'] ?? '');
    }

    public function gerarEtiqueta(array $params): array
    {
        $token = $this->tokenCorreios();
        if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios.'];
        $dest = is_array($params['destinatario'] ?? null) ? $params['destinatario'] : [];
        if (!$dest || empty($dest['cep'] ?? $dest['postal_code'] ?? '')) {
            return ['ok' => false, 'erro' => 'Destinatário/CEP ausente para a etiqueta.'];
        }

        // 1) Cria a pré-postagem (POST /prepostagem/v1/prepostagens)
        $body = $this->corpoPrepostagem($params, $dest);
        $r = $this->reqCorreios('POST', '/prepostagem/v1/prepostagens', $body, $token, 'prepostagem');
        if (!$this->okHttp($r)) return ['ok' => false, 'erro' => $this->erroCorreios($r, 'Falha ao criar pré-postagem')];
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        $id = (string)($j['id'] ?? '');
        if ($id === '') return ['ok' => false, 'erro' => $this->erroCorreios($r, 'Pré-postagem sem id de retorno')];
        $codObj = (string)($j['codigoObjeto'] ?? $j['numeroObjeto'] ?? '');

        // 2) Gera o rótulo (código do objeto sai aqui) — best-effort.
        $rot = $this->rotuloPdf([$id], $token);
        if ($codObj === '') $codObj = (string)($rot['codigo_objeto'] ?? '');

        return [
            'ok'              => true,
            'external_id'     => $id,                       // id da pré-postagem (PR...)
            'codigo_rastreio' => $codObj !== '' ? $codObj : $id,
            'url_pdf'         => $rot['url'] ?? null,
            'pdf_base64'      => $rot['pdf_base64'] ?? null, // quando o PDF vem em bytes
            'id_recibo'       => $rot['id_recibo'] ?? null,  // p/ baixar o rótulo depois
            'aviso'           => $rot['aviso'] ?? null,
            'valor'           => (float)($j['precoPostagem'] ?? $params['valor_frete'] ?? 0),
        ];
    }

    public function imprimirEtiqueta(array $externalIds, string $modo = 'private'): array
    {
        $ids = array_values(array_filter(array_map('strval', $externalIds)));
        if (!$ids) return ['ok' => false, 'erro' => 'Nenhuma etiqueta informada.'];
        $token = $this->tokenCorreios();
        if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios.'];
        $rot = $this->rotuloPdf($ids, $token, $modo === 'private' ? 'P' : 'R');
        if (empty($rot['ok'])) return ['ok' => false, 'erro' => $rot['erro'] ?? 'Falha ao gerar rótulo.'];
        return ['ok' => true, 'url_pdf' => $rot['url'] ?? null, 'pdf_base64' => $rot['pdf_base64'] ?? null, 'id_recibo' => $rot['id_recibo'] ?? null, 'aviso' => $rot['aviso'] ?? null];
    }

    /** Monta o corpo da pré-postagem (remetente = LOJA, destinatário = CLIENTE). */
    private function corpoPrepostagem(array $params, array $dest): array
    {
        $loja = $this->reversaConfig()['loja']; // reusa endereço/CNPJ da loja
        $dim  = $this->dimensoesDe($params);
        $telL = self::telefonesCorreios($loja['telefone'] ?? $loja['celular'] ?? '');
        $telD = self::telefonesCorreios($dest['celular'] ?? $dest['telefone'] ?? $dest['phone'] ?? '');
        $so   = static fn($v) => preg_replace('/\D/', '', (string)$v) ?? '';

        return [
            'codigoServico' => (string)($params['servico_codigo'] ?? ''),
            'remetente' => [
                'nome'        => mb_substr((string)($loja['nome'] ?? ''), 0, 50),
                'dddTelefone' => $telL['dddTelefone'],
                'telefone'    => $telL['telefone'],
                'dddCelular'  => $telL['dddCelular'],
                'celular'     => $telL['celular'],
                'email'       => (string)($loja['email'] ?? ''),
                'cpfCnpj'     => $so($loja['documento'] ?? ''),
                'endereco'    => [
                    'cep'         => $so($loja['cep'] ?? ''),
                    'logradouro'  => (string)($loja['logradouro'] ?? ''),
                    'numero'      => (string)($loja['numero'] ?? 'S/N'),
                    'complemento' => (string)($loja['complemento'] ?? ''),
                    'bairro'      => (string)($loja['bairro'] ?? ''),
                    'cidade'      => (string)($loja['cidade'] ?? ''),
                    'uf'          => (string)($loja['uf'] ?? ''),
                ],
            ],
            'destinatario' => [
                'nome'        => mb_substr((string)($dest['nome'] ?? $dest['name'] ?? ''), 0, 50),
                'dddTelefone' => $telD['dddTelefone'],
                'telefone'    => $telD['telefone'],
                'dddCelular'  => $telD['dddCelular'],
                'celular'     => $telD['celular'],
                'email'       => (string)($dest['email'] ?? ''),
                'cpfCnpj'     => $so($dest['cpf'] ?? $dest['documento'] ?? $dest['cpf_cnpj'] ?? ''),
                'endereco'    => [
                    'cep'         => $so($dest['cep'] ?? $dest['postal_code'] ?? ''),
                    'logradouro'  => (string)($dest['logradouro'] ?? $dest['endereco'] ?? $dest['address'] ?? ''),
                    'numero'      => (string)($dest['numero'] ?? $dest['number'] ?? 'S/N'),
                    'complemento' => (string)($dest['complemento'] ?? $dest['complement'] ?? ''),
                    'bairro'      => (string)($dest['bairro'] ?? $dest['district'] ?? ''),
                    'cidade'      => (string)($dest['cidade'] ?? $dest['city'] ?? ''),
                    'uf'          => (string)($dest['uf'] ?? $dest['state_abbr'] ?? ''),
                ],
            ],
            // Declaração de Conteúdo (obrigatória quando não há NF — erro PPN-347).
            'itensDeclaracaoConteudo' => self::itensDeclaracao($params),
            'codigoFormatoObjetoInformado' => (string)($this->config['tp_objeto'] ?? '2'), // 1=env,2=caixa,3=rolo
            'pesoInformado'        => (string)max(1, (int)round($dim['peso_g'])),           // GRAMAS
            'alturaInformada'      => (string)(int)round($dim['altura_cm']),                // cm
            'larguraInformada'     => (string)(int)round($dim['largura_cm']),
            'comprimentoInformado' => (string)(int)round($dim['comprimento_cm']),
            'diametroInformado'    => '0',
            'cienteObjetoNaoProibido' => 1,
            'observacao'           => mb_substr((string)($params['comentarios'] ?? $params['observacao'] ?? ''), 0, 100),
        ];
    }

    /** POST do rótulo assíncrono em PDF; devolve URL/código do objeto quando disponível. */
    /**
     * Rótulo assíncrono em 2 etapas: POST solicita (recebe idRecibo) e GET baixa o PDF.
     * Trata também o caso do PDF vir direto na 1ª resposta. Devolve url OU pdf_base64.
     */
    private function rotuloPdf(array $ids, string $token, string $tipo = 'P'): array
    {
        // 1) Solicita o rótulo.
        $body = ['idsPrePostagem' => array_values($ids), 'tipoRotulo' => $tipo, 'formatoRotulo' => 'ET'];
        $r = $this->reqCorreios('POST', '/prepostagem/v1/prepostagens/rotulo/assincrono/pdf', $body, $token, 'rotulo_solicita');
        if (!$this->okHttp($r)) return ['ok' => false, 'erro' => $this->erroCorreios($r, 'Falha ao solicitar rótulo')];
        $j = is_array($r['json'] ?? null) ? $r['json'] : [];

        // PDF já veio direto? (url/base64)
        $url = $j['dados'] ?? $j['url'] ?? $j['pdf'] ?? null;
        if ($url) return ['ok' => true, 'url' => $url, 'id_recibo' => $j['idRecibo'] ?? $j['id'] ?? null];

        $idRecibo = $j['idRecibo'] ?? $j['id'] ?? $j['recibo'] ?? null;
        if ($idRecibo === null) {
            return ['ok' => true, 'url' => null, 'raw' => $j, 'aviso' => 'Rótulo solicitado; retorno sem idRecibo/URL conhecidos (confira o corpo em log_comunicacoes).'];
        }

        // Assíncrono: 1 tentativa rápida (caso já esteja pronto). Senão, devolve o
        // idRecibo para a 2ª etapa (baixar depois) — ver rotuloPorRecibo().
        usleep(500000);
        $d = $this->baixarRotulo((string)$idRecibo, $token);
        if (!empty($d['ok'])) return $d + ['id_recibo' => $idRecibo];

        return ['ok' => true, 'url' => null, 'id_recibo' => $idRecibo, 'aviso' => 'Rótulo em processamento. Baixe pelo idRecibo ' . $idRecibo . '.'];
    }

    /** 2ª etapa do rótulo: baixa o PDF já solicitado, pelo idRecibo. */
    public function rotuloPorRecibo(string $idRecibo): array
    {
        $idRecibo = trim($idRecibo);
        if ($idRecibo === '') return ['ok' => false, 'erro' => 'idRecibo vazio.'];
        $token = $this->tokenCorreios();
        if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios.'];
        $d = $this->baixarRotulo($idRecibo, $token);
        if (!empty($d['ok'])) return ['ok' => true, 'url_pdf' => $d['url'] ?? null, 'pdf_base64' => $d['pdf_base64'] ?? null];
        if (!empty($d['processando'])) return ['ok' => false, 'processando' => true, 'erro' => 'Rótulo ainda em processamento. Tente novamente em instantes.'];
        return ['ok' => false, 'erro' => $d['erro'] ?? 'Falha ao baixar o rótulo.'];
    }

    /**
     * Consulta o AR Eletrônico (Aviso de Recebimento) de um objeto entregue.
     * POST https://apps.correios.com.br/areletronico-rs/v1/ars/ultimoevento
     * body {objetos:[codigo]} -> [AREletronico{codigo, imagemBase64, mensagem, ...}].
     * Só há imagem se o objeto foi entregue COM AR contratado.
     */
    public function consultarAr(string $codigoObjeto): array
    {
        $codigoObjeto = strtoupper(trim($codigoObjeto));
        if ($codigoObjeto === '') return ['ok' => false, 'erro' => 'Código do objeto vazio.'];
        $token = $this->tokenCorreios();
        if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios.'];

        $base = trim((string)($this->config['ar_base'] ?? '')) ?: 'https://apps.correios.com.br/areletronico-rs';
        $url = rtrim($base, '/') . '/v1/ars/ultimoevento';
        $r = $this->requisicaoHttp('POST', $url, ['objetos' => [$codigoObjeto]],
            ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json']);
        $this->logComunicacao('ar_eletronico',
            ['url' => $url, 'objeto' => $codigoObjeto],
            $r['json'] ?? ['body' => mb_substr($r['body'] ?? '', 0, 300)],
            $this->okHttp($r), $r['status'] ?? null, $r['ms'] ?? null);
        if (!$this->okHttp($r)) return ['ok' => false, 'erro' => $this->erroCorreios($r, 'Falha ao consultar o AR')];

        $j = $r['json'] ?? [];
        $ar = null;
        if (is_array($j)) {
            if (isset($j['imagemBase64']) || isset($j['mensagem'])) $ar = $j;         // objeto único
            elseif (isset($j[0]) && is_array($j[0])) $ar = $j[0];                       // array
        }
        if (!$ar) return ['ok' => false, 'erro' => 'AR não disponível para este objeto.'];

        $img = (string)($ar['imagemBase64'] ?? '');
        if ($img === '') return ['ok' => false, 'erro' => (string)($ar['mensagem'] ?? 'AR ainda não disponível para este objeto.')];
        return ['ok' => true, 'imagem_base64' => $img, 'tipo' => (string)($ar['tipo'] ?? ''), 'mensagem' => (string)($ar['mensagem'] ?? '')];
    }

    /**
     * Consulta os dados da POSTAGEM real de um objeto já postado.
     * GET /prepostagem/v1/prepostagens/postada?codigoObjeto=... -> MovimentoPostagemDTO.
     * Retorna o valor cobrado (valorAtendimento), data e peso tarifado. Se ainda não
     * foi postado, devolve ['ok'=>false,'nao_postada'=>true] (sem erro).
     */
    public function consultarPostagem(string $codigoObjeto): array
    {
        $codigoObjeto = strtoupper(trim($codigoObjeto));
        if ($codigoObjeto === '') return ['ok' => false, 'erro' => 'Código do objeto vazio.'];
        $token = $this->tokenCorreios();
        if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios.'];

        $r = $this->reqCorreios('GET', '/prepostagem/v1/prepostagens/postada?codigoObjeto=' . rawurlencode($codigoObjeto), null, $token, 'postada');
        $s = (int)($r['status'] ?? 0);
        if ($s === 404 || $s === 400) return ['ok' => false, 'nao_postada' => true]; // ainda não postado
        if (!$this->okHttp($r)) return ['ok' => false, 'erro' => $this->erroCorreios($r, 'Falha ao consultar a postagem')];

        $j = is_array($r['json'] ?? null) ? $r['json'] : [];
        if (($j['codigoObjeto'] ?? '') === '' && !isset($j['valorAtendimento'])) {
            return ['ok' => false, 'nao_postada' => true];
        }
        $pesoTar = $j['pesoTarifadoObjeto'] ?? null;
        return [
            'ok'              => true,
            'valor'           => (float)($j['valorAtendimento'] ?? 0),
            'data_postagem'   => self::dataSro((string)($j['dataPostagem'] ?? $j['dataHoraAtendimento'] ?? '')),
            'peso_tarifado_g' => ($pesoTar !== null && $pesoTar !== '') ? (int)round((float)str_replace(',', '.', (string)$pesoTar)) : null,
            'plp'             => $j['numeroPlp'] ?? null,
            'servico'         => $j['codigoServico'] ?? null,
            'nome_servico'    => $j['nomeServico'] ?? null,
        ];
    }
    private function baixarRotulo(string $idRecibo, string $token): array
    {
        // Endpoint oficial (OpenAPI v3): /rotulo/download/assincrono/{idRecibo}
        $d = $this->reqCorreios('GET', '/prepostagem/v1/prepostagens/rotulo/download/assincrono/' . rawurlencode($idRecibo), null, $token, 'rotulo_download');
        $s = (int)($d['status'] ?? 0);
        if ($s === 202 || $s === 425) return ['ok' => false, 'processando' => true]; // ainda gerando
        if (!$this->okHttp($d)) {
            // 400/500 costuma significar "recibo ainda processando" logo após solicitar.
            if ($s === 400 || $s === 404) return ['ok' => false, 'processando' => true];
            return ['ok' => false, 'erro' => $this->erroCorreios($d, 'Falha ao baixar rótulo')];
        }
        $dj = is_array($d['json'] ?? null) ? $d['json'] : [];
        // A resposta traz o PDF em base64 (campo "dados"/"rotulo"/"pdf") ou uma URL.
        $b64 = $dj['dados'] ?? $dj['rotulo'] ?? $dj['pdf'] ?? $dj['arquivo'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            if (str_starts_with($b64, 'http')) return ['ok' => true, 'url' => $b64];
            return ['ok' => true, 'pdf_base64' => $b64];
        }
        $url = $dj['url'] ?? null;
        if ($url) return ['ok' => true, 'url' => $url];
        // Corpo binário do PDF?
        $body = (string)($d['body'] ?? '');
        if ($body !== '' && str_starts_with($body, '%PDF')) return ['ok' => true, 'pdf_base64' => base64_encode($body)];
        return ['ok' => false, 'processando' => true];
    }

    public function cancelarEtiqueta(string $externalId): array
    {
        $externalId = trim($externalId);
        if ($externalId === '') return ['ok' => false, 'erro' => 'Sem identificador para cancelar.'];

        // Pré-postagem (ida): id começa com "PR" -> DELETE REST.
        if (stripos($externalId, 'PR') === 0) {
            $token = $this->tokenCorreios();
            if (!$token) return ['ok' => false, 'erro' => 'Não foi possível autenticar nos Correios.'];
            $r = $this->reqCorreios('DELETE', '/prepostagem/v1/prepostagens/' . rawurlencode($externalId), null, $token, 'cancelar_prepostagem');
            return $this->okHttp($r) ? ['ok' => true] : ['ok' => false, 'erro' => $this->erroCorreios($r, 'Falha ao cancelar a pré-postagem')];
        }

        // Reversa: número da coleta -> SOAP cancelarPedido no WS de Logística Reversa.
        $cfg = $this->reversaConfig();
        if ($this->reversaFaltando($cfg)) {
            return ['ok' => false, 'erro' => 'Reversa Correios não configurada para cancelamento.'];
        }
        $envelope = self::xmlCancelarReversa($externalId, $cfg);
        $r = $this->postSoapReversa($envelope, $cfg);
        $okHttp = $r['status'] >= 200 && $r['status'] < 300 && empty($r['erro']);
        $this->logComunicacao(
            'cancelar_reversa',
            ['endpoint' => $cfg['endpoint'], 'xml' => $envelope],
            ['status' => $r['status'], 'body' => mb_substr($r['body'] ?? '', 0, 800)],
            $okHttp, $r['status'] ?? null, $r['ms'] ?? null
        );
        if (!empty($r['erro'])) return ['ok' => false, 'erro' => 'Falha de comunicação com os Correios (cancelamento): ' . $r['erro']];
        $res = self::parseCancelarReversa($r['body'] ?? '');
        return !empty($res['ok']) ? ['ok' => true] : ['ok' => false, 'erro' => $res['erro'] ?? 'Falha ao cancelar a coleta nos Correios.'];
    }

    /** Envelope SOAP de cancelamento de pedido reverso. */
    public static function xmlCancelarReversa(string $numeroColeta, array $cfg): string
    {
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.logisticareversa.correios.com.br/">'
            . '<soapenv:Header/><soapenv:Body><ser:cancelarPedido>'
            . '<codAdministrativo>' . $e($cfg['cod_administrativo'] ?? '') . '</codAdministrativo>'
            . '<numeroPedido>' . $e($numeroColeta) . '</numeroPedido>'
            . '<tipo>' . $e($cfg['tipo'] ?? 'A') . '</tipo>'
            . '</ser:cancelarPedido></soapenv:Body></soapenv:Envelope>';
    }

    /** Interpreta a resposta do cancelamento (sucesso quando cod_erro '00'/'0' ou retorno vazio de erro). */
    public static function parseCancelarReversa(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || stripos($xml, '<') === false) return ['ok' => false, 'erro' => 'Resposta vazia dos Correios no cancelamento.'];
        $sx = @simplexml_load_string($xml);
        if ($sx === false) return ['ok' => false, 'erro' => 'Resposta inválida (XML) no cancelamento.'];
        $pega = static function (string $n) use ($sx): ?string {
            $r = $sx->xpath('//*[local-name()="' . $n . '"]');
            return ($r && isset($r[0])) ? trim((string)$r[0]) : null;
        };
        if ($f = $pega('faultstring')) return ['ok' => false, 'erro' => 'Correios: ' . $f];
        $desc = $pega('descricao_erro') ?? $pega('msg_erro') ?? $pega('descricao');
        $cod = $pega('cod_erro') ?? $pega('codigo_erro');
        if ($cod !== null && !in_array($cod, ['00', '0'], true)) {
            return ['ok' => false, 'erro' => 'Correios (cod ' . $cod . '): ' . ($desc ?? 'falha ao cancelar')];
        }
        // Alguns retornos trazem <return>true</return> / <sucesso>true</sucesso>.
        $ret = strtolower((string)($pega('return') ?? $pega('sucesso') ?? ''));
        if ($ret === 'false') return ['ok' => false, 'erro' => $desc ?? 'Correios recusou o cancelamento.'];
        return ['ok' => true];
    }

    /* =================================================================
       Logística reversa — SOAP "solicitarPostagemReversa"
       (web service de Logística Reversa dos Correios; usa o contrato).
       Destinatário = LOJA (para onde volta); remetente = CLIENTE.
       ================================================================= */

    public function gerarReversa(array $params): array
    {
        $cfg = $this->reversaConfig();
        if ($falta = $this->reversaFaltando($cfg)) {
            return ['ok' => false, 'erro' => "Reversa Correios não configurada: falta {$falta}."];
        }

        $cliente = is_array($params['cliente'] ?? null) ? $params['cliente'] : [];
        if (!$cliente || empty($cliente['cep'])) {
            return ['ok' => false, 'erro' => 'Endereço do cliente (remetente da volta) ausente para a reversa.'];
        }
        $produtos = is_array($params['produtos'] ?? null) ? $params['produtos'] : [];
        if (!$produtos && is_array($params['volumes'] ?? null)) {
            foreach ($params['volumes'] as $i => $_) $produtos[] = ['descricao' => 'Volume ' . ($i + 1)];
        }
        $cfg['valor_declarado'] = (float)($params['valor'] ?? $params['valor_declarado'] ?? 0);

        $envelope = self::xmlPostagemReversa($cliente, $produtos, $cfg, (string)($params['comentarios'] ?? ''));
        $r = $this->postSoapReversa($envelope, $cfg);
        $okHttp = $r['status'] >= 200 && $r['status'] < 300 && empty($r['erro']);
        $this->logComunicacao(
            'gerar_reversa',
            ['endpoint' => $cfg['endpoint'], 'xml' => $envelope],
            ['status' => $r['status'], 'body' => mb_substr($r['body'] ?? '', 0, 1000)],
            $okHttp, $r['status'] ?? null, $r['ms'] ?? null
        );

        if (!empty($r['erro'])) {
            return ['ok' => false, 'erro' => 'Falha de comunicação com os Correios (reversa): ' . $r['erro']];
        }
        $res = self::parseRespostaReversa($r['body'] ?? '');
        if (empty($res['ok'])) {
            return ['ok' => false, 'erro' => $res['erro'] ?? 'Falha ao solicitar a postagem reversa.'];
        }

        return [
            'ok'              => true,
            'external_id'     => $res['numero'],
            'codigo_rastreio' => $res['numero'],
            'url_pdf'         => null,             // reversa Correios: cliente apresenta o número na agência (sem PDF)
            'validade'        => $res['prazo'] ?? null,
        ];
    }

    /**
     * Correios opera reversa AQUI quando o bloco `reversa` está configurado
     * (web service + código administrativo + endereço da loja). Assim a tela
     * de reversa só oferece os Correios quando dá pra gerar de fato.
     */
    public function suportaReversa(): bool
    {
        $c = $this->reversaConfig();
        return $c['endpoint'] !== '' && $c['cod_administrativo'] !== ''
            && $c['loja']['nome'] !== '' && $c['loja']['cep'] !== '';
    }

    /* ---------------- reversa: helpers ---------------- */

    /** Monta a config de reversa a partir dos campos do formulário (planos
     *  'reversa_*' / 'reversa_loja_*') OU de um bloco aninhado 'reversa'. */
    private function reversaConfig(): array
    {
        $c = $this->config;
        $r = is_array($c['reversa'] ?? null) ? $c['reversa'] : [];
        $loja = is_array($r['loja'] ?? null) ? $r['loja'] : [];

        $v  = static fn($k) => (string)($r[$k] ?? ($c['reversa_' . $k] ?? ''));
        $vl = static fn($k) => (string)($loja[$k] ?? ($c['reversa_loja_' . $k] ?? ''));

        $cartao = $v('cartao');
        return [
            'endpoint'           => $v('endpoint'),
            'ws_user'            => $v('ws_user'),
            'ws_senha'           => $v('ws_senha'),
            'cod_administrativo' => $v('cod_administrativo'),
            'codigo_servico'     => $v('codigo_servico'),
            'cartao'             => $cartao !== '' ? $cartao : (string)($c['cartao_postagem'] ?? $c['cartaopostagem'] ?? ''),
            'tipo'               => $v('tipo') !== '' ? $v('tipo') : 'A',
            'loja' => [
                'nome'       => $vl('nome'),
                'documento'  => $vl('documento') !== '' ? $vl('documento') : (class_exists('ConfigHelper') ? (string)(ConfigHelper::get('site_cnpj') ?? '') : ''),
                'logradouro' => $vl('logradouro'),
                'numero'     => $vl('numero'),
                'bairro'     => $vl('bairro'),
                'cidade'     => $vl('cidade'),
                'uf'         => $vl('uf'),
                'cep'        => $vl('cep'),
                'telefone'   => $vl('telefone'),
                'email'      => $vl('email'),
            ],
        ];
    }

    /** Retorna o primeiro campo obrigatório ausente (ou null se tudo ok). */
    private function reversaFaltando(array $cfg): ?string
    {
        if (empty($cfg['endpoint']))          return 'endpoint do web service';
        if (empty($cfg['cod_administrativo'])) return 'código administrativo';
        if (empty($cfg['codigo_servico']))    return 'código do serviço (ex.: 03301)';
        if (empty($cfg['cartao']))            return 'cartão de postagem';
        if (empty($cfg['loja']['nome']) || empty($cfg['loja']['cep'])) return 'endereço da loja (destinatário da volta)';
        return null;
    }

    /** POST do envelope SOAP (Basic auth, como o login/password do SoapClient legado). */
    private function postSoapReversa(string $envelope, array $cfg): array
    {
        $ini = microtime(true);
        $ch = curl_init((string)$cfg['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $envelope,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""', 'Accept: text/xml'],
            CURLOPT_USERPWD        => (string)($cfg['ws_user'] ?? '') . ':' . (string)($cfg['ws_senha'] ?? ''),
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false, // o web service legado dos Correios costuma exigir isso
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => (string)$body, 'erro' => $err ?: null, 'ms' => (int)round((microtime(true) - $ini) * 1000)];
    }

    /* ---------------- reversa: puro (testável) ---------------- */

    /** Monta o envelope SOAP de solicitarPostagemReversa (valores XML-escapados). */
    public static function xmlPostagemReversa(array $cliente, array $produtos, array $cfg, string $obs = ''): string
    {
        $e  = static fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $so = static fn($v) => preg_replace('/\D/', '', (string)$v) ?? '';
        $loja = is_array($cfg['loja'] ?? null) ? $cfg['loja'] : [];

        $telC = self::dividirTelefone($cliente['telefone'] ?? $cliente['celular'] ?? '');
        $celC = self::dividirTelefone($cliente['celular'] ?? $cliente['telefone'] ?? '');
        $telL = self::dividirTelefone($loja['telefone'] ?? '');
        $idCliente = mb_substr('DEV_' . (string)($cfg['id_cliente'] ?? substr(sha1(json_encode($cliente) . microtime(true)), 0, 12)), 0, 30);
        $valor = (float)($cfg['valor_declarado'] ?? 0);

        // obj_col: 1 bloco; <item> = quantidade total (1..10); <desc> = itens.
        $qtd = max(1, min(10, count($produtos) ?: 1));
        $descItem = '';
        foreach ($produtos as $p) {
            $d = trim((string)($p['descricao'] ?? $p['nome'] ?? ''));
            if ($d !== '') $descItem .= ($descItem !== '' ? '; ' : '') . $d;
        }
        $descItem = mb_substr($descItem !== '' ? $descItem : 'Devolução', 0, 255);

        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.logisticareversa.correios.com.br/">'
            . '<soapenv:Header/><soapenv:Body><ser:solicitarPostagemReversa>'
            . '<codAdministrativo>' . $e($cfg['cod_administrativo'] ?? '') . '</codAdministrativo>'
            . '<codigo_servico>' . $e($cfg['codigo_servico'] ?? '') . '</codigo_servico>'
            . '<cartao>' . $e($cfg['cartao'] ?? '') . '</cartao>'
            . '<destinatario>' // = a LOJA (para onde volta)
            .   '<nome>' . $e(mb_substr((string)($loja['nome'] ?? ''), 0, 60)) . '</nome>'
            .   '<logradouro>' . $e(mb_substr((string)($loja['logradouro'] ?? ''), 0, 72)) . '</logradouro>'
            .   '<numero>' . $e($loja['numero'] ?? 'S/N') . '</numero>'
            .   '<complemento>' . $e(mb_substr((string)($loja['complemento'] ?? ''), 0, 30)) . '</complemento>'
            .   '<bairro>' . $e(mb_substr((string)($loja['bairro'] ?? ''), 0, 50)) . '</bairro>'
            .   '<referencia></referencia>'
            .   '<cidade>' . $e(mb_substr((string)($loja['cidade'] ?? ''), 0, 36)) . '</cidade>'
            .   '<uf>' . $e($loja['uf'] ?? '') . '</uf>'
            .   '<cep>' . $e($so($loja['cep'] ?? '')) . '</cep>'
            .   '<ddd>' . $e($loja['ddd'] ?? $telL['ddd']) . '</ddd>'
            .   '<telefone>' . $e($telL['numero']) . '</telefone>'
            .   '<email>' . $e(mb_substr((string)($loja['email'] ?? ''), 0, 72)) . '</email>'
            .   '<identificacao>' . $e($so($loja['documento'] ?? '')) . '</identificacao>'
            .   '<ciencia_conteudo_proibido>N</ciencia_conteudo_proibido>'
            . '</destinatario>'
            . '<coletas_solicitadas>'
            .   '<tipo>' . $e($cfg['tipo'] ?? 'A') . '</tipo>'
            .   '<id_cliente>' . $e($idCliente) . '</id_cliente>'
            .   '<valor_declarado>' . ($valor > 0 ? number_format($valor, 2, '.', '') : '') . '</valor_declarado>'
            .   '<descricao>' . $e(mb_substr($obs, 0, 255)) . '</descricao>' // observação/instruções
            .   '<cklist></cklist>'
            .   '<documento></documento>'
            .   '<remetente>' // = o CLIENTE (quem envia a devolução)
            .     '<nome>' . $e(self::abreviarNome($cliente['nome'] ?? $cliente['name'] ?? '')) . '</nome>'
            .     '<logradouro>' . $e(mb_substr((string)($cliente['logradouro'] ?? $cliente['endereco'] ?? $cliente['address'] ?? ''), 0, 72)) . '</logradouro>'
            .     '<numero>' . $e($cliente['numero'] ?? 'S/N') . '</numero>'
            .     '<complemento>' . $e(mb_substr((string)($cliente['complemento'] ?? ''), 0, 30)) . '</complemento>'
            .     '<bairro>' . $e(mb_substr((string)($cliente['bairro'] ?? ''), 0, 80)) . '</bairro>'
            .     '<referencia></referencia>'
            .     '<cidade>' . $e(mb_substr((string)($cliente['cidade'] ?? $cliente['municipio'] ?? $cliente['city'] ?? ''), 0, 40)) . '</cidade>'
            .     '<uf>' . $e($cliente['uf'] ?? $cliente['estado'] ?? $cliente['state'] ?? '') . '</uf>'
            .     '<cep>' . $e($so($cliente['cep'] ?? '')) . '</cep>'
            .     '<ddd>' . $e($telC['ddd']) . '</ddd>'
            .     '<telefone>' . $e($telC['numero']) . '</telefone>'
            .     '<email>' . $e(mb_substr((string)($cliente['email'] ?? ''), 0, 72)) . '</email>'
            .     '<identificacao>' . $e($so($cliente['cpf'] ?? $cliente['documento'] ?? $cliente['cpf_cnpj'] ?? '')) . '</identificacao>'
            .     '<ddd_celular>' . $e($celC['ddd']) . '</ddd_celular>'
            .     '<celular>' . $e($celC['numero']) . '</celular>'
            .     '<sms>N</sms>'
            .     '<restricao_anac>S</restricao_anac>'
            .   '</remetente>'
            .   '<numero></numero>'
            .   '<ag></ag>'
            .   '<cartao></cartao>'
            .   '<servico_adicional></servico_adicional>'
            .   '<ar></ar>'
            .   '<obj_col>'
            .     '<item>' . $qtd . '</item>'
            .     '<id>' . $e($idCliente) . '</id>'
            .     '<desc>' . $e($descItem) . '</desc>'
            .   '</obj_col>'
            . '</coletas_solicitadas>'
            . '</ser:solicitarPostagemReversa></soapenv:Body></soapenv:Envelope>';
    }

    /** Interpreta a resposta do web service (robusto a prefixo de namespace). */
    public static function parseRespostaReversa(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '' || stripos($xml, '<') === false) {
            return ['ok' => false, 'erro' => 'Resposta vazia dos Correios: ' . mb_substr($xml, 0, 200)];
        }
        $sx = @simplexml_load_string($xml);
        if ($sx === false) return ['ok' => false, 'erro' => 'Resposta inválida (XML) dos Correios.'];

        $pega = static function (string $nome) use ($sx): ?string {
            $r = $sx->xpath('//*[local-name()="' . $nome . '"]');
            return ($r && isset($r[0])) ? trim((string)$r[0]) : null;
        };

        if ($fault = $pega('faultstring')) return ['ok' => false, 'erro' => 'Correios: ' . $fault];

        $desc = $pega('descricao_erro') ?? $pega('descricao');
        $codErro = $pega('cod_erro');       // '00' = processado
        if ($codErro !== null && $codErro !== '00') {
            return ['ok' => false, 'erro' => 'Correios (cod_erro ' . $codErro . '): ' . ($desc ?? 'falha no processamento')];
        }
        $codigoErro = $pega('codigo_erro'); // '0' = solicitação ok
        if ($codigoErro !== null && $codigoErro !== '0') {
            return ['ok' => false, 'erro' => 'Correios: ' . ($desc ?? ('codigo_erro ' . $codigoErro))];
        }
        $numero = $pega('numero_coleta');
        if (!$numero) return ['ok' => false, 'erro' => trim('Correios não retornou o número da coleta. ' . ($desc ?? ''))];

        return ['ok' => true, 'numero' => $numero, 'prazo' => $pega('prazo'), 'status' => $pega('status_objeto')];
    }

    /** Divide um telefone BR em ['ddd'=>.., 'numero'=>..]. */
    public static function dividirTelefone(string $telefone): array
    {
        $d = preg_replace('/\D/', '', $telefone) ?? '';
        if (strlen($d) > 11 && str_starts_with($d, '55')) $d = substr($d, 2); // tira DDI
        if (strlen($d) >= 10) return ['ddd' => substr($d, 0, 2), 'numero' => substr($d, 2)];
        return ['ddd' => '', 'numero' => $d];
    }

    /**
     * Separa um telefone nos campos que os Correios esperam: número de 9 dígitos
     * vai em celular/dddCelular; de 8 dígitos em telefone/dddTelefone. Evita o erro
     * "Telefone do remetente inválido" (celular no campo de telefone fixo).
     */
    public static function telefonesCorreios(string $telefone): array
    {
        $t = self::dividirTelefone($telefone);
        $ddd = $t['ddd'];
        $num = $t['numero'];
        $out = ['dddTelefone' => '', 'telefone' => '', 'dddCelular' => '', 'celular' => ''];
        if (strlen($num) === 8) {
            $out['dddTelefone'] = $ddd; $out['telefone'] = $num;
        } elseif (strlen($num) >= 9) {
            $out['dddCelular'] = $ddd; $out['celular'] = substr($num, -9);
        }
        return $out;
    }

    /** Monta os itens da Declaração de Conteúdo a partir dos produtos do pedido. */
    public static function itensDeclaracao(array $params): array
    {
        $produtos = is_array($params['produtos'] ?? null) ? $params['produtos'] : [];
        $itens = [];
        foreach ($produtos as $p) {
            $itens[] = [
                'conteudo'   => mb_substr((string)($p['descricao'] ?? $p['nome'] ?? 'Produto'), 0, 60),
                'quantidade' => (string)max(1, (int)($p['quantidade'] ?? 1)),
                'valor'      => number_format((float)($p['valor'] ?? $p['preco'] ?? 0), 2, '.', ''),
            ];
        }
        if (!$itens) {
            $valor = (float)($params['valor_declarado'] ?? $params['valor'] ?? 1);
            $itens[] = ['conteudo' => 'Peças e acessórios', 'quantidade' => '1', 'valor' => number_format($valor > 0 ? $valor : 1, 2, '.', '')];
        }
        return $itens;
    }

    /** Abrevia o nome respeitando o limite dos Correios (~50 chars). */
    public static function abreviarNome(string $nome, int $limite = 50): string
    {
        $nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
        if (mb_strlen($nome) <= $limite) return $nome;
        $partes = explode(' ', $nome);
        // mantém primeiro e último; abrevia os do meio
        if (count($partes) > 2) {
            $primeiro = array_shift($partes);
            $ultimo = array_pop($partes);
            $meio = array_map(static fn($p) => mb_substr($p, 0, 1) . '.', $partes);
            $nome = $primeiro . ' ' . implode(' ', $meio) . ' ' . $ultimo;
        }
        return mb_substr($nome, 0, $limite);
    }
}