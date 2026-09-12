<?php
/**
 * app/services/RedirecionamentoService.php
 *
 * O mapa origem → destino do site. Consultado SÓ quando a página não existe
 * (ver Erro404Service::interceptar), então não custa nada em página que
 * existe.
 *
 * Três tipos:
 *   301  mudou de endereço de vez — o Google transfere a autoridade
 *   302  temporário — o Google mantém o endereço antigo no índice
 *   410  acabou — o Google tira do índice mais rápido que com 404
 *
 * Quem mexe: só `super` (decisão do dono, 11/09/2026). Um redirecionamento
 * manda o visitante para onde quem o criou quiser, inclusive para fora.
 */
class RedirecionamentoService
{
    /** Tipos aceitos. 410 é o único que dispensa destino. */
    public const TIPOS = [301, 302, 410];

    /** Saltos seguidos ao resolver. Cadeia longa queima autoridade no Google. */
    private const MAX_SALTOS = 3;

    /** Regras por padrão já lidas nesta requisição (são poucas). */
    private ?array $cachePadroes = null;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?: Database::getInstance()->getConnection();
    }

    // ── Normalização ──────────────────────────────────────────────
    //
    // O mesmo endereço chega de muitas formas: com barra no fim, com
    // maiúscula, com query, com barras duplicadas. Tudo vira uma forma só
    // antes de virar hash — senão /Capacetes e /capacetes/ seriam dois
    // registros diferentes para a mesma página quebrada.

    public static function normalizar(string $caminho): string
    {
        $caminho = trim($caminho);

        // URL absoluta (o admin pode colar a URL inteira): fica só o caminho.
        if (preg_match('#^https?://#i', $caminho)) {
            $caminho = (string) (parse_url($caminho, PHP_URL_PATH) ?? '/');
        }

        // Query e âncora não entram na chave.
        $caminho = (string) strtok($caminho, '?');
        $caminho = (string) strtok($caminho, '#');

        $caminho = preg_replace('#/+#', '/', $caminho) ?? $caminho;
        $caminho = '/' . ltrim($caminho, '/');
        if ($caminho !== '/') {
            $caminho = rtrim($caminho, '/');
        }

        // Os slugs do site são minúsculos; casar sem diferenciar evita
        // registro duplicado por causa de link com maiúscula.
        $caminho = mb_strtolower($caminho, 'UTF-8');

        return mb_substr($caminho, 0, 700, 'UTF-8');
    }

    public static function hash(string $caminho): string
    {
        return sha1(self::normalizar($caminho));
    }

    // ── Resolução (o caminho quente) ──────────────────────────────

    /**
     * Acha o redirecionamento ativo para este caminho.
     *
     * Segue cadeia (A→B, B→C devolve C) até MAX_SALTOS, para o visitante não
     * pagar dois saltos e o Google não perder autoridade no meio. Ciclo
     * (A→B, B→A) para no limite e devolve o último salto válido.
     */
    public function resolver(string $caminho): ?array
    {
        $atual = self::normalizar($caminho);

        $primeiro = null;
        $vistos   = [];

        for ($i = 0; $i < self::MAX_SALTOS; $i++) {
            $r = $this->casar($atual);
            if (!$r) break;

            $primeiro ??= $r;
            $vistos[$atual] = true;

            // 410 encerra: não há para onde seguir.
            if ((int) $r['tipo'] === 410) return $r;

            $proximo = self::normalizar((string) $r['destino']);
            if (isset($vistos[$proximo])) break;   // ciclo

            // Destino externo não tem como continuar a cadeia.
            if (preg_match('#^https?://#i', trim((string) $r['destino']))) return $r;

            $ultimo = $r;
            $atual  = $proximo;
        }

        // Devolve o ÚLTIMO salto (destino final), mantendo o tipo do primeiro:
        // se o admin marcou 301, o visitante recebe 301.
        if (isset($ultimo) && $primeiro && $ultimo !== $primeiro) {
            $ultimo['tipo'] = $primeiro['tipo'];
            $ultimo['id']   = $primeiro['id'];
            return $ultimo;
        }

        return $primeiro;
    }

    /**
     * A regra que atende este caminho: **exata primeiro**, padrão depois.
     *
     * A exata ganha de propósito — é assim que se abre exceção dentro de um
     * padrão: `/vestuario/*` manda tudo para a categoria, e `/vestuario/promo`
     * pode ir para outro lugar.
     */
    private function casar(string $caminho): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, origem, destino, tipo, ativo, padrao
               FROM redirecionamentos
              WHERE origem_hash = ? AND ativo = 1 AND padrao = 0
              LIMIT 1"
        );
        $stmt->execute([sha1($caminho)]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;

        foreach ($this->padroes() as $regra) {
            $destino = self::aplicarPadrao(
                (string) $regra['origem'], (string) $regra['destino'], $caminho
            );
            if ($destino !== null) {
                $regra['destino'] = $destino;
                return $regra;
            }
        }

        return null;
    }

    /** As regras por padrão ativas, na ordem de prioridade. São poucas. */
    private function padroes(): array
    {
        if ($this->cachePadroes !== null) return $this->cachePadroes;

        $stmt = $this->db->query(
            "SELECT id, origem, destino, tipo, ativo, padrao
               FROM redirecionamentos
              WHERE padrao = 1 AND ativo = 1
           ORDER BY prioridade ASC, id ASC"
        );

        return $this->cachePadroes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Casa o caminho com um padrão e devolve o destino já montado.
     *
     * O `*` vale UMA vez na origem e captura o resto:
     *   `/vestuario/*` + `/categoria/*`  →  /vestuario/jaquetas vira /categoria/jaquetas
     *   `/promo/*`     + `/promocoes`    →  tudo cai no mesmo lugar
     *
     * Devolve null quando não casa.
     */
    public static function aplicarPadrao(string $origem, string $destino, string $caminho): ?string
    {
        $pos = strpos($origem, '*');
        if ($pos === false) return null;

        $prefixo = substr($origem, 0, $pos);
        $sufixo  = substr($origem, $pos + 1);

        if ($prefixo !== '' && strncmp($caminho, $prefixo, strlen($prefixo)) !== 0) return null;

        $resto = substr($caminho, strlen($prefixo));

        if ($sufixo !== '') {
            if (strlen($resto) < strlen($sufixo)) return null;
            if (substr($resto, -strlen($sufixo)) !== $sufixo) return null;
            $resto = substr($resto, 0, -strlen($sufixo));
        }

        // Curinga vazio não conta: /vestuario/* não deve casar /vestuario.
        if ($resto === '') return null;

        return strpos($destino, '*') === false
            ? $destino
            : str_replace('*', $resto, $destino);
    }

    /**
     * O slug de um registro mudou: o endereço antigo ganha 301 para o novo.
     *
     * Chamado pelos controllers DEPOIS que a gravação deu certo — regra de
     * redirecionamento para um salvamento que falhou seria pior que nada.
     *
     * Antes de criar, apaga uma regra automática cuja origem seja o endereço
     * NOVO: é o caso de renomear e voltar atrás (`a → b`, depois `b → a`), que
     * deixaria as duas regras apontando uma para a outra. Regra criada à mão
     * não é tocada — quem escreveu decide.
     *
     * @return array|null null quando não havia troca de fato.
     */
    public function aoTrocarSlug(string $antigo, string $novo, int $autorUsuarioId = 0): ?array
    {
        $antigo = self::normalizar($antigo);
        $novo   = self::normalizar($novo);

        if ($antigo === '' || $novo === '' || $antigo === $novo) return null;
        if ($antigo === '/' || $novo === '/') return null;

        try {
            $this->db->prepare(
                "DELETE FROM redirecionamentos WHERE origem_hash = ? AND motivo = 'slug'"
            )->execute([sha1($novo)]);

            $this->cachePadroes = null;

            return $this->salvar([
                'origem'     => $antigo,
                'destino'    => $novo,
                'tipo'       => 301,
                'motivo'     => 'slug',
                'observacao' => 'Criado sozinho ao trocar o endereço no painel.',
                'ativo'      => 1,
            ], $autorUsuarioId);
        } catch (Throwable $e) {
            // Isto roda DEPOIS do commit do registro. Uma falha aqui (base sem
            // a migração, banco fora) não pode virar erro 500 num produto que
            // já foi salvo — vira log, e o endereço antigo fica só como 404 na
            // tela do rastreador.
            if (class_exists('LogService')) {
                LogService::exception($e, 'error', 'seo',
                    ['onde' => 'aoTrocarSlug', 'antigo' => $antigo, 'novo' => $novo]);
            }
            return null;
        }
    }

    /**
     * Palpites de destino para um endereço quebrado.
     *
     * O pré-voo da migração descobre o destino perguntando ao site; aqui, na
     * tela, a resposta vem do banco — mais rápido e sem 4 requisições por
     * linha.
     *
     * Duas passadas, por fonte (produto, categoria, marca, página, montadora):
     *
     *   1. **Slug igual.** É o caso da migração: a Tray usava outro prefixo
     *      (`/vestuario/jaquetas`), o slug final é o mesmo. O último segmento
     *      vale 100; um segmento anterior (`/vestuario/...` → a categoria
     *      "vestuario") vale 80.
     *   2. **Parecido.** Busca pelos pedaços mais longos do slug e ordena por
     *      `similar_text`. Abaixo de 45% não vira sugestão — palpite ruim na
     *      tela é pior que nenhum, porque convida a aceitar sem olhar.
     *
     * Quem decide é sempre o admin: isto preenche o campo, não grava nada.
     */
    public function sugerirDestinos(string $caminho, int $limite = 5): array
    {
        $caminho = self::normalizar($caminho);
        $segs    = array_values(array_filter(explode('/', $caminho)));
        if (!$segs) return [];

        // Último segmento primeiro: é o que costuma carregar o nome.
        $alvos = array_values(array_unique(array_reverse($segs)));
        $alvo  = $alvos[0];

        $fontes = [
            ['produtos',        'nome',   '/produto/',   'Produto',   'deleted_at IS NULL AND ativo = 1'],
            ['categorias',      'nome',   '/categoria/', 'Categoria', 'ativo = 1'],
            ['marcas',          'nome',   '/marca/',     'Marca',     'ativo = 1'],
            ['paginas',         'titulo', '/',           'Página',    'ativo = 1'],
            ['moto_montadoras', 'nome',   '/montadora/', 'Montadora', 'ativo = 1'],
        ];

        $achados = [];

        foreach ($fontes as [$tabela, $colNome, $prefixo, $rotulo, $filtro]) {
            try {
                // 1. Slug igual
                $st = $this->db->prepare(
                    "SELECT slug, {$colNome} AS nome FROM {$tabela}
                      WHERE slug = ? AND {$filtro} LIMIT 1"
                );
                foreach ($alvos as $i => $candidato) {
                    $st->execute([$candidato]);
                    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                        $achados[] = [
                            'destino' => $prefixo . $r['slug'],
                            'titulo'  => (string) $r['nome'],
                            'tipo'    => $rotulo,
                            'score'   => $i === 0 ? 100 : 80,
                            'motivo'  => $i === 0 ? 'mesmo endereço final' : 'segmento do caminho',
                        ];
                    }
                }

                // 2. Parecido
                $tokens = array_values(array_filter(
                    explode('-', $alvo), static fn($t) => mb_strlen($t) >= 4
                ));
                usort($tokens, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                $tokens = array_slice($tokens, 0, 3);
                if (!$tokens) continue;

                $likes = implode(' OR ', array_fill(0, count($tokens), 'slug LIKE ?'));
                $st2   = $this->db->prepare(
                    "SELECT slug, {$colNome} AS nome FROM {$tabela}
                      WHERE ({$likes}) AND {$filtro} LIMIT 40"
                );
                $st2->execute(array_map(static fn($t) => '%' . $t . '%', $tokens));

                foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    similar_text($alvo, (string) $r['slug'], $pct);
                    if ($pct < 45) continue;
                    $achados[] = [
                        'destino' => $prefixo . $r['slug'],
                        'titulo'  => (string) $r['nome'],
                        'tipo'    => $rotulo,
                        'score'   => (int) round($pct),
                        'motivo'  => 'endereço parecido',
                    ];
                }
            } catch (PDOException $e) {
                // Tabela ausente numa base antiga não pode derrubar a tela.
                continue;
            }
        }

        // Um destino, o melhor palpite dele. E nunca sugerir o próprio caminho.
        $porDestino = [];
        foreach ($achados as $a) {
            if ($a['destino'] === $caminho) continue;
            $d = $a['destino'];
            if (!isset($porDestino[$d]) || $a['score'] > $porDestino[$d]['score']) {
                $porDestino[$d] = $a;
            }
        }

        $lista = array_values($porDestino);
        usort($lista, static fn($x, $y) => $y['score'] <=> $x['score']);

        return array_slice($lista, 0, max(1, $limite));
    }

    /** Contador de uso — mostra na tela quais regras estão vivas. */
    public function registrarAcesso(int $id): void
    {
        $this->db->prepare(
            "UPDATE redirecionamentos
                SET acessos = acessos + 1, ultimo_acesso = NOW()
              WHERE id = ?"
        )->execute([$id]);
    }

    // ── CRUD (tela do painel) ─────────────────────────────────────

    public function porId(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM redirecionamentos WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function porOrigem(string $caminho): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM redirecionamentos WHERE origem_hash = ? LIMIT 1");
        $stmt->execute([self::hash($caminho)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param array $f  ['busca' => string, 'tipo' => int, 'ativo' => 0|1]
     */
    public function listar(array $f = [], int $pagina = 1, int $porPagina = 30): array
    {
        [$where, $params] = $this->filtros($f);
        $offset = max(0, ($pagina - 1) * $porPagina);

        $stmt = $this->db->prepare(
            "SELECT r.*, u.nome AS criado_por_nome
               FROM redirecionamentos r
          LEFT JOIN usuarios u ON u.id = r.criado_por
              {$where}
           ORDER BY r.atualizado_em DESC
              LIMIT ? OFFSET ?"
        );
        foreach ($params as $i => $v) $stmt->bindValue($i + 1, $v);
        $stmt->bindValue(count($params) + 1, $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function contar(array $f = []): int
    {
        [$where, $params] = $this->filtros($f);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM redirecionamentos r {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function filtros(array $f): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['busca'])) {
            $where[]  = '(r.origem LIKE ? OR r.destino LIKE ?)';
            $params[] = '%' . $f['busca'] . '%';
            $params[] = '%' . $f['busca'] . '%';
        }
        if (!empty($f['tipo'])) {
            $where[]  = 'r.tipo = ?';
            $params[] = (int) $f['tipo'];
        }
        if (isset($f['ativo']) && $f['ativo'] !== '') {
            $where[]  = 'r.ativo = ?';
            $params[] = (int) $f['ativo'];
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /**
     * Cria ou edita. Devolve ['ok' => bool, 'msg' => string, 'id' => int,
     * 'aviso' => string] — `aviso` sai quando a regra é válida mas merece
     * o olho do admin (cadeia, por exemplo).
     */
    public function salvar(array $dados, int $autorUsuarioId = 0): array
    {
        $id     = (int) ($dados['id'] ?? 0);
        $origem = self::normalizar((string) ($dados['origem'] ?? ''));
        $tipo   = (int) ($dados['tipo'] ?? 301);
        $motivo = (string) ($dados['motivo'] ?? 'manual');
        $ativo  = !empty($dados['ativo']) ? 1 : 0;
        $obs    = trim((string) ($dados['observacao'] ?? '')) ?: null;
        $prio   = max(0, min(65535, (int) ($dados['prioridade'] ?? 100)));

        // O curinga na origem é o que define a regra por padrão — não um campo
        // à parte que pudesse discordar do texto digitado.
        $padrao = substr_count($origem, '*') > 0 ? 1 : 0;

        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'msg' => 'Tipo inválido. Use 301, 302 ou 410.'];
        }
        if ($origem === '' || $origem === '/') {
            return ['ok' => false, 'msg' => 'Informe o endereço de origem (não pode ser a home).'];
        }
        if (substr_count($origem, '*') > 1) {
            return ['ok' => false, 'msg' => 'Use no máximo um * na origem.'];
        }

        $destino = trim((string) ($dados['destino'] ?? ''));

        if ($tipo === 410) {
            // "Essa página acabou" não leva a lugar nenhum.
            $destino = '';
        } else {
            $erro = $this->validarDestino($destino);
            if ($erro !== '') return ['ok' => false, 'msg' => $erro];

            if (substr_count($destino, '*') > 1) {
                return ['ok' => false, 'msg' => 'Use no máximo um * no destino.'];
            }
            if (!$padrao && strpos($destino, '*') !== false) {
                return ['ok' => false,
                        'msg' => 'O destino tem * mas a origem não — o * do destino repete o pedaço capturado na origem.'];
            }

            $destino = preg_match('#^https?://#i', $destino)
                ? $destino
                : self::normalizar($destino);

            if ($destino === $origem) {
                return ['ok' => false, 'msg' => 'A origem e o destino são o mesmo endereço.'];
            }
        }

        $aviso = '';
        if ($padrao && ($origem === '/*' || $origem === '/**')) {
            $aviso = 'Esta regra pega o site inteiro. Confira a prioridade e as exceções exatas.';
        }
        if ($tipo !== 410 && !preg_match('#^https?://#i', $destino)) {
            $encadeia = $this->porOrigem($destino);
            if ($encadeia && (int) $encadeia['ativo'] === 1 && (int) $encadeia['id'] !== $id) {
                $aviso = 'O destino também é origem de outro redirecionamento — '
                       . 'o visitante será levado direto ao endereço final.';
            }
        }

        try {
            if ($id > 0) {
                $this->db->prepare(
                    "UPDATE redirecionamentos
                        SET origem = ?, origem_hash = ?, destino = ?, tipo = ?,
                            padrao = ?, prioridade = ?, motivo = ?, observacao = ?, ativo = ?
                      WHERE id = ?"
                )->execute([$origem, sha1($origem), $destino, $tipo, $padrao, $prio,
                            $motivo, $obs, $ativo, $id]);
            } else {
                $this->db->prepare(
                    "INSERT INTO redirecionamentos
                     (origem, origem_hash, destino, tipo, padrao, prioridade,
                      motivo, observacao, ativo, criado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $origem, sha1($origem), $destino, $tipo, $padrao, $prio,
                    $motivo, $obs, $ativo,
                    $autorUsuarioId > 0 ? $autorUsuarioId : null,
                ]);
                $id = (int) $this->db->lastInsertId();
            }
        } catch (PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return ['ok' => false, 'msg' => 'Já existe um redirecionamento para ' . $origem . '.'];
            }
            throw $e;
        }

        $this->cachePadroes = null;   // a lista de padrões mudou

        return ['ok' => true, 'msg' => 'Redirecionamento salvo.', 'id' => $id,
                'aviso' => $aviso, 'padrao' => $padrao];
    }

    /**
     * Destino aceito: caminho interno começando com "/" ou URL http(s).
     * `javascript:` e `data:` viram redirecionamento aberto para XSS.
     */
    private function validarDestino(string $destino): string
    {
        if ($destino === '') {
            return 'Informe o destino (ou use o tipo 410, para página que acabou).';
        }
        if (preg_match('#^https?://#i', $destino)) {
            return filter_var($destino, FILTER_VALIDATE_URL) ? '' : 'URL de destino inválida.';
        }
        if ($destino[0] !== '/') {
            return 'O destino deve começar com "/" (interno) ou com http:// | https://.';
        }
        return '';
    }

    public function alternarAtivo(int $id): array
    {
        $r = $this->porId($id);
        if (!$r) return ['ok' => false, 'msg' => 'Redirecionamento não encontrado.'];

        $novo = (int) $r['ativo'] ? 0 : 1;
        $this->db->prepare("UPDATE redirecionamentos SET ativo = ? WHERE id = ?")->execute([$novo, $id]);

        return ['ok' => true, 'ativo' => $novo];
    }

    public function excluir(int $id): array
    {
        $this->db->prepare("DELETE FROM redirecionamentos WHERE id = ?")->execute([$id]);
        return ['ok' => true, 'msg' => 'Redirecionamento excluído.'];
    }
}
