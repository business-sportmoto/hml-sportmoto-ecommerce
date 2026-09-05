<?php
declare(strict_types=1);

/**
 * IAAgenteCatalogoService — as regras do catálogo de agentes (Fase A).
 *
 * O controller recebe o POST e devolve JSON; o que decide se um agente
 * pode existir do jeito que veio — código, páginas sem dono duplicado,
 * ferramentas que existem, modelo de capacidade `agente`, persona com
 * tamanho de persona — mora aqui. Também traduz os dois textareas
 * (sugestões e perguntas por tema) de e para JSON.
 */
class IAAgenteCatalogoService
{
    /** Menos que isso não é persona; mais que isso é livro. */
    public const PERSONA_MIN = 80;
    public const PERSONA_MAX = 12000;

    public const SUGESTOES_MAX  = 4;
    public const TEMAS_MAX      = 12;
    public const ITENS_POR_TEMA = 20;

    private IAAgente        $modelo;
    private IAAgenteGateway $gw;
    private PDO             $db;

    public function __construct(?IAAgente $modelo = null, ?IAAgenteGateway $gw = null)
    {
        $this->modelo = $modelo ?? new IAAgente();
        $this->gw     = $gw ?? new IAAgenteGateway();
        $this->db     = Database::getInstance()->getConnection();
    }

    /**
     * Valida e normaliza o POST do formulário.
     * @param array      $post  $_POST cru
     * @param array|null $atual o agente sendo editado (null = criação)
     * @return array{ok:bool, msg?:string, dados?:array, aviso?:string}
     */
    public function validar(array $post, ?array $atual): array
    {
        $nome    = trim((string) ($post['nome_exibicao'] ?? ''));
        $curto   = trim((string) ($post['rotulo_curto'] ?? ''));
        $desc    = trim((string) ($post['descricao'] ?? ''));
        $persona = trim((string) ($post['instrucoes_sistema'] ?? ''));

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 80)   return $this->erro('Nome de exibição: entre 3 e 80 caracteres.');
        if (mb_strlen($curto) < 2 || mb_strlen($curto) > 30) return $this->erro('Rótulo curto: entre 2 e 30 caracteres.');
        if (mb_strlen($desc) > 255)                          return $this->erro('Descrição: no máximo 255 caracteres.');
        if (mb_strlen($persona) < self::PERSONA_MIN)         return $this->erro('A persona precisa de pelo menos ' . self::PERSONA_MIN . ' caracteres — é ela que define o agente.');
        if (mb_strlen($persona) > self::PERSONA_MAX)         return $this->erro('A persona passou de ' . self::PERSONA_MAX . ' caracteres.');

        // Código: só na criação. Depois é a chave das conversas e do painel.
        if ($atual === null) {
            $codigo = self::normalizarCodigo((string) ($post['codigo'] ?? ''));
            if ($codigo === null) return $this->erro('Código inválido: use letras minúsculas, números e _ (até 40).');
            if ($this->modelo->codigoExiste($codigo)) return $this->erro("Já existe um tipo com o código {$codigo}.");
        } else {
            $codigo = (string) $atual['codigo'];
        }
        $id = $atual !== null ? (int) $atual['id'] : null;

        // Ferramentas: só as que o gateway conhece. Vazia é permitido — e avisado.
        $ferramentas = array_values(array_unique(array_filter(
            (array) ($post['ferramentas'] ?? []),
            fn($f) => is_string($f) && $this->gw->existe($f)
        )));

        // Páginas: só as do painel; nenhuma já ocupada por OUTRO agente ativo.
        $paginas = array_values(array_unique(array_filter(
            (array) ($post['paginas'] ?? []),
            fn($p) => is_string($p) && isset(IAAgenteGateway::PAGINAS[$p])
        )));
        $ativo = !empty($post['ativo']) ? 1 : 0;
        if ($ativo === 1) {
            $ocupadas = $this->modelo->paginasOcupadas($id);
            $conflito = array_values(array_intersect($paginas, array_keys($ocupadas)));
            if ($conflito !== []) {
                $p = $conflito[0];
                return $this->erro('A página "' . (IAAgenteGateway::PAGINAS[$p] ?? $p) . '" já é atendida por '
                    . $ocupadas[$p] . '. Uma página tem um só agente.');
            }
        }

        $modeloId  = (int) ($post['modelo_id'] ?? 0);
        $modelosOk = array_map('intval', array_column($this->modelosDeAgente(), 'id'));
        if ($modeloId > 0 && !in_array($modeloId, $modelosOk, true)) {
            return $this->erro('Modelo inválido: escolha um modelo de capacidade "agente".');
        }

        $effort = (string) ($post['effort'] ?? 'medium');
        if (!in_array($effort, IAAgente::EFFORTS, true)) $effort = 'medium';

        $maxTokens = max(500, min((int) ($post['max_tokens'] ?? 2500), 8000));

        $agendadoAtivo = !empty($post['agendado_ativo']) ? 1 : 0;
        $perguntaAg    = trim((string) ($post['pergunta_agendada'] ?? ''));
        $paginaAg      = (string) ($post['pagina_agendada'] ?? '');
        if (!isset(IAAgenteGateway::PAGINAS[$paginaAg])) $paginaAg = $paginas[0] ?? 'overview';
        if ($agendadoAtivo === 1 && mb_strlen($perguntaAg) < 20) {
            return $this->erro('Com a rodada agendada ligada, escreva a pergunta padrão (mínimo 20 caracteres).');
        }

        $dados = [
            'codigo'             => $codigo,
            'nome_exibicao'      => $nome,
            'rotulo_curto'       => $curto,
            'descricao'          => $desc !== '' ? $desc : null,
            'instrucoes_sistema' => $persona,
            'ferramentas'        => $ferramentas,
            'modelo_id'          => $modeloId > 0 ? $modeloId : null,
            'max_tokens'         => $maxTokens,
            'effort'             => $effort,
            'paginas'            => $paginas,
            'sugestoes'          => self::linhas((string) ($post['sugestoes'] ?? ''), self::SUGESTOES_MAX, 160),
            'perguntas'          => self::perguntasDoTexto((string) ($post['perguntas'] ?? '')),
            'agendado_ativo'     => $agendadoAtivo,
            'pergunta_agendada'  => $perguntaAg,
            'pagina_agendada'    => $paginaAg,
            'ordem'              => max(0, min((int) ($post['ordem'] ?? 0), 999)),
            'ativo'              => $ativo,
        ];

        return ['ok' => true, 'dados' => $dados,
                'aviso' => $ferramentas === [] ? 'Sem ferramentas, o agente responde só com o contexto da página.' : null];
    }

    /** Ativar um agente inativo: as páginas dele não podem ter outro dono ativo. */
    public function podeAtivar(array $agente): array
    {
        $conflito = array_values(array_intersect($agente['paginas'], array_keys($this->modelo->paginasOcupadas((int) $agente['id']))));
        if ($conflito !== []) {
            $p = $conflito[0];
            return $this->erro('Não dá para ativar: a página "' . (IAAgenteGateway::PAGINAS[$p] ?? $p) . '" já tem outro agente ativo. Edite as páginas antes.');
        }
        return ['ok' => true];
    }

    /** Modelos de capacidade `agente` ativos, com o estado do provedor (para o select). */
    public function modelosDeAgente(): array
    {
        try {
            return $this->db->query(
                "SELECT m.id, m.codigo_modelo, m.nome, p.codigo AS provedor, p.ativo AS provedor_ativo,
                        (p.api_key_enc IS NOT NULL) AS tem_chave
                   FROM ia_modelos m JOIN ia_provedores p ON p.id = m.provedor_id
                  WHERE m.capacidade = 'agente' AND m.ativo = 1
                  ORDER BY m.prioridade ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** codigo => quantidade de conversas — o histórico de cada agente. */
    public function conversasPorAgente(): array
    {
        try {
            return $this->db->query('SELECT agente, COUNT(*) FROM ia_agente_conversas GROUP BY agente')
                            ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Texto ↔ JSON                                                        */
    /* ------------------------------------------------------------------ */

    /** 'agente_' + slug; null se inválido. */
    public static function normalizarCodigo(string $bruto): ?string
    {
        $slug = strtolower(trim($bruto));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug);
        $slug = trim(preg_replace('/_+/', '_', (string) $slug), '_');
        $slug = preg_replace('/^agente_/', '', $slug);
        if ($slug === '' || strlen($slug) > 40) return null;
        return 'agente_' . $slug;
    }

    /** Textarea de um item por linha → lista limpa. */
    public static function linhas(string $texto, int $max, int $tamanho): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $texto) as $l) {
            $l = trim((string) preg_replace('/^[\s\-•*]+/u', '', $l));
            if ($l === '') continue;
            $out[] = mb_substr($l, 0, $tamanho);
            if (count($out) >= $max) break;
        }
        return $out;
    }

    /**
     * Textarea de perguntas por tema → JSON do catálogo.
     *   Faturamento e crescimento:
     *   - Como foi o faturamento contra o período anterior?
     * Linha terminada em ":" abre um tema; as demais são perguntas do
     * tema corrente. Pergunta antes de qualquer tema cai em "Geral".
     * @return array<int, array{tema:string, itens:string[]}>
     */
    public static function perguntasDoTexto(string $texto): array
    {
        $grupos = [];
        $tema   = null;
        foreach (preg_split('/\R/u', $texto) as $l) {
            $l = trim($l);
            if ($l === '') continue;
            if (!str_starts_with($l, '-') && preg_match('/^(.{2,60}):$/u', $l, $m)) {
                $tema = trim($m[1]);
                $grupos[$tema] = $grupos[$tema] ?? [];
                continue;
            }
            $item = trim((string) preg_replace('/^[\s\-•*]+/u', '', $l));
            if ($item === '') continue;
            $tema = $tema ?? 'Geral';
            $grupos[$tema] = $grupos[$tema] ?? [];
            if (count($grupos[$tema]) < self::ITENS_POR_TEMA) $grupos[$tema][] = mb_substr($item, 0, 200);
        }
        $out = [];
        foreach ($grupos as $t => $itens) {
            if ($itens === []) continue;
            $out[] = ['tema' => (string) $t, 'itens' => array_values(array_unique($itens))];
            if (count($out) >= self::TEMAS_MAX) break;
        }
        return $out;
    }

    /** O inverso: JSON do catálogo → textarea. */
    public static function perguntasParaTexto(array $perguntas): string
    {
        $blocos = [];
        foreach ($perguntas as $g) {
            if (!is_array($g) || !isset($g['tema'], $g['itens'])) continue;
            $blocos[] = $g['tema'] . ":\n" . implode("\n", array_map(fn($i) => '- ' . $i, (array) $g['itens']));
        }
        return implode("\n\n", $blocos);
    }

    private function erro(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }
}
