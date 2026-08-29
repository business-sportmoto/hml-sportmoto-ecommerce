<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoNoCatalogo.php
 *
 * Fonte única dos tipos de nó do fluxo de pagamento: portas, campos de
 * configuração e rótulos.
 *
 * Quem consome:
 *   - o editor Drawflow, para montar paleta, saídas e painel de config;
 *   - o validador, para recusar grafo inconsistente antes de publicar;
 *   - o PagamentoRoteador, que executa esses mesmos tipos.
 *
 * Adicionar um tipo de nó = declarar aqui E implementar em
 * PagamentoRoteador::executarNo(). O `validarGrafo()` avisa se um tipo
 * aparecer no grafo sem estar no catálogo.
 */
class PagamentoNoCatalogo
{
    /**
     * Portas de saída do nó que chama adquirente. São o coração da regra de
     * fallback: cada classe de recusa tem a sua saída, e o motor recusa ligar
     * as de emissor em outra adquirente (ver PagamentoErroClassifier).
     */
    public const PORTAS_TENTATIVA = [
        'aprovado', 'pendente', 'negado_saldo', 'negado_antifraude',
        'negado_dados', 'negado_generico', 'erro_tecnico', 'indisponivel',
    ];

    /**
     * @return array<string, array{
     *   rotulo:string, grupo:string, portas:array, entrada:bool,
     *   descricao:string, campos:array
     * }>
     */
    public static function todos(): array
    {
        return [
            'entrada' => [
                'rotulo'    => 'Início',
                'grupo'     => 'fluxo',
                'portas'    => ['saida'],
                'entrada'   => true,
                'descricao' => 'Onde todo pagamento deste método começa. Só pode haver um.',
                'campos'    => [],
            ],

            'cond_parcelas' => [
                'rotulo'    => 'Se parcelas entre',
                'grupo'     => 'condicao',
                'portas'    => ['sim', 'nao'],
                'entrada'   => false,
                'descricao' => 'Roteia por número de parcelas. Ex.: 2 a 6 numa adquirente, 7 a 12 noutra.',
                'campos'    => [
                    ['nome' => 'min', 'rotulo' => 'De (parcelas)', 'tipo' => 'numero', 'padrao' => 1],
                    ['nome' => 'max', 'rotulo' => 'Até (parcelas)', 'tipo' => 'numero', 'padrao' => 6],
                ],
            ],

            'cond_valor' => [
                'rotulo'    => 'Se valor entre',
                'grupo'     => 'condicao',
                'portas'    => ['sim', 'nao'],
                'entrada'   => false,
                'descricao' => 'Roteia por valor do pedido, em centavos.',
                'campos'    => [
                    ['nome' => 'min', 'rotulo' => 'De (centavos)', 'tipo' => 'numero', 'padrao' => 0],
                    ['nome' => 'max', 'rotulo' => 'Até (centavos)', 'tipo' => 'numero', 'padrao' => 100000],
                ],
            ],

            'cond_bandeira' => [
                'rotulo'    => 'Se bandeira',
                'grupo'     => 'condicao',
                'portas'    => ['sim', 'nao'],
                'entrada'   => false,
                'descricao' => 'Roteia por bandeira do cartão.',
                'campos'    => [
                    ['nome' => 'bandeiras', 'rotulo' => 'Bandeiras', 'tipo' => 'multi',
                     'opcoes' => ['visa' => 'Visa', 'mastercard' => 'Mastercard',
                                  'elo' => 'Elo', 'amex' => 'Amex', 'hipercard' => 'Hipercard'],
                     'padrao' => []],
                ],
            ],

            'tentar_adquirente' => [
                'rotulo'    => 'Tentar adquirente',
                'grupo'     => 'acao',
                'portas'    => self::PORTAS_TENTATIVA,
                'entrada'   => false,
                'descricao' => 'Chama a adquirente. Cada porta é uma classe de resultado — '
                             . 'ligue erro técnico e indisponível numa alternativa; '
                             . 'as portas de recusa do emissor devem terminar em Recusar.',
                'campos'    => [
                    ['nome' => 'adquirente', 'rotulo' => 'Adquirente', 'tipo' => 'adquirente', 'padrao' => ''],
                ],
            ],

            'antifraude' => [
                'rotulo'    => 'Antifraude',
                'grupo'     => 'acao',
                'portas'    => ['aprovado', 'analise', 'reprovado', 'erro'],
                'entrada'   => false,
                'descricao' => 'Decide pelo score do cliente e, quando necessario, consulta a '
                             . 'ClearSale. Nem todo pedido chega a gastar consulta: cliente '
                             . 'recorrente sem devolucao passa direto.',
                'campos'    => [
                    ['nome' => 'modo', 'rotulo' => 'Momento da captura', 'tipo' => 'select',
                     'opcoes' => [
                        'pos_captura' => 'Ja capturado — reprovar exige estorno',
                        'pre_captura' => 'Apenas autorizado — reprovar so cancela',
                     ],
                     'padrao' => 'pos_captura'],
                    ['nome' => 'pular_se_aprovado_local', 'rotulo' => 'Dispensar consulta quando o score permitir',
                     'tipo' => 'select',
                     'opcoes' => ['1' => 'Sim (recomendado)', '0' => 'Nao, sempre consultar'],
                     'padrao' => '1'],
                    ['nome' => 'pular_se_liability_shift', 'rotulo' => 'Dispensar quando o 3DS transferir a responsabilidade',
                     'tipo' => 'select',
                     'opcoes' => ['0' => 'Nao (padrao)', '1' => 'Sim, aprovar direto'],
                     'padrao' => '0',
                     'ajuda' => 'Vale so para disputa de FRAUDE. "Nao recebi" e "nao era o que '
                              . 'comprei" continuam sendo seus — nenhum 3DS evita isso.'],
                ],
            ],

            'aprovar' => [
                'rotulo'    => 'Aprovar',
                'grupo'     => 'fim',
                'portas'    => [],
                'entrada'   => false,
                'descricao' => 'Encerra o fluxo com sucesso.',
                'campos'    => [],
            ],

            'reter_analise' => [
                'rotulo'    => 'Reter para analise',
                'grupo'     => 'fim',
                'portas'    => [],
                'entrada'   => false,
                'descricao' => 'Encerra o fluxo com o pedido RETIDO, aguardando decisao humana '
                             . 'na fila de analise. Nao libera mercadoria e nao recusa o pagamento.',
                'campos'    => [],
            ],

            'recusar' => [
                'rotulo'    => 'Recusar',
                'grupo'     => 'fim',
                'portas'    => [],
                'entrada'   => false,
                'descricao' => 'Encerra o fluxo sem pagamento.',
                'campos'    => [],
            ],
        ];
    }

    public static function existe(string $tipo): bool
    {
        return isset(self::todos()[$tipo]);
    }

    public static function portas(string $tipo): array
    {
        return self::todos()[$tipo]['portas'] ?? [];
    }

    /** Portas que representam julgamento do emissor — não podem retentar. */
    public static function portasDeRecusaDoEmissor(): array
    {
        return ['negado_saldo', 'negado_antifraude', 'negado_dados', 'negado_generico'];
    }

    /**
     * Valida um grafo antes de publicar.
     *
     * Publicar um fluxo quebrado é pior do que não publicar: o cliente chega
     * no checkout e o roteamento não sabe para onde ir. Estes erros barram a
     * publicação; os avisos apenas alertam.
     *
     * @param array $nos      [{no_ref, tipo, config}]
     * @param array $conexoes [{no_origem, porta_origem, no_destino}]
     * @return array{erros:string[], avisos:string[]}
     */
    public static function validarGrafo(array $nos, array $conexoes): array
    {
        $erros = [];
        $avisos = [];
        $catalogo = self::todos();

        $porRef = [];
        foreach ($nos as $n) $porRef[$n['no_ref']] = $n;

        // ── Tipos conhecidos ────────────────────────────────────────
        foreach ($nos as $n) {
            if (!isset($catalogo[$n['tipo']])) {
                $erros[] = "Nó '{$n['no_ref']}' tem tipo desconhecido: {$n['tipo']}.";
            }
        }

        // ── Exatamente uma entrada ──────────────────────────────────
        $entradas = array_filter($nos, static fn($n) => ($n['tipo'] ?? '') === 'entrada');
        if (count($entradas) === 0) {
            $erros[] = 'O fluxo precisa de um nó de Início.';
        } elseif (count($entradas) > 1) {
            $erros[] = 'Só pode haver um nó de Início (encontrados ' . count($entradas) . ').';
        }

        // ── Pelo menos um desfecho ──────────────────────────────────
        $fins = array_filter($nos, static fn($n) => in_array($n['tipo'] ?? '', ['aprovar', 'recusar'], true));
        if (!$fins) {
            $erros[] = 'O fluxo precisa de pelo menos um nó de Aprovar ou Recusar.';
        }

        // ── Toda tentativa precisa de adquirente escolhida ──────────
        foreach ($nos as $n) {
            if (($n['tipo'] ?? '') !== 'tentar_adquirente') continue;
            $cfg = is_array($n['config'] ?? null) ? $n['config'] : (json_decode((string) ($n['config'] ?? ''), true) ?: []);
            if (empty($cfg['adquirente'])) {
                $erros[] = "O nó '{$n['no_ref']}' não tem adquirente escolhida.";
            }
        }

        // ── Arestas apontando para nó inexistente ───────────────────
        $saidas = [];
        foreach ($conexoes as $c) {
            if (!isset($porRef[$c['no_destino']])) {
                $erros[] = "Conexão de '{$c['no_origem']}' aponta para um nó que não existe.";
            }
            if (!isset($porRef[$c['no_origem']])) {
                $erros[] = "Conexão parte de um nó que não existe: '{$c['no_origem']}'.";
                continue;
            }
            $saidas[$c['no_origem']][] = $c['porta_origem'];
        }

        // ── Retentativa proibida desenhada no grafo ─────────────────
        // O motor bloqueia em execução, mas avisar aqui evita que alguém
        // desenhe algo que nunca vai acontecer e fique esperando.
        $recusas = self::portasDeRecusaDoEmissor();
        foreach ($conexoes as $c) {
            if (!in_array($c['porta_origem'], $recusas, true)) continue;
            $destino = $porRef[$c['no_destino']] ?? null;
            if ($destino && ($destino['tipo'] ?? '') === 'tentar_adquirente') {
                $avisos[] = "A saída '{$c['porta_origem']}' de '{$c['no_origem']}' liga em outra adquirente. "
                          . 'O motor vai recusar essa passagem: recusa do emissor nunca é retentada '
                          . '(regra das bandeiras). Ligue em Recusar.';
            }
        }

        // ── Portas importantes sem destino ──────────────────────────
        foreach ($nos as $n) {
            if (($n['tipo'] ?? '') !== 'tentar_adquirente') continue;
            $usadas = $saidas[$n['no_ref']] ?? [];
            foreach (['aprovado', 'negado_generico'] as $obrigatoria) {
                if (!in_array($obrigatoria, $usadas, true)) {
                    $avisos[] = "O nó '{$n['no_ref']}' não tem destino para a saída '{$obrigatoria}'. "
                              . 'O fluxo encerra ali com o resultado da tentativa.';
                }
            }
        }

        // ── Porta de analise ligada em Aprovar ──────────────────────
        // Erro grave: liberaria mercadoria de um pedido que o antifraude
        // mandou reter. Barra a publicacao.
        foreach ($conexoes as $c) {
            if ($c['porta_origem'] !== 'analise') continue;
            $origem  = $porRef[$c['no_origem']]  ?? null;
            $destino = $porRef[$c['no_destino']] ?? null;
            if (($origem['tipo'] ?? '') !== 'antifraude') continue;
            if (($destino['tipo'] ?? '') === 'aprovar') {
                $erros[] = "A saida 'analise' de '{$c['no_origem']}' liga em Aprovar. "
                         . 'Isso libera um pedido que deveria ficar retido. '
                         . 'Use o no "Reter para analise".';
            }
        }

        // ── Nó órfão (fora da entrada) ──────────────────────────────
        if (count($entradas) === 1) {
            $entrada    = array_values($entradas)[0]['no_ref'];
            $alcancavel = self::alcancaveis($entrada, $conexoes);
            foreach ($nos as $n) {
                if ($n['no_ref'] !== $entrada && !isset($alcancavel[$n['no_ref']])) {
                    $avisos[] = "O nó '{$n['no_ref']}' não é alcançável a partir do Início.";
                }
            }
        }

        return ['erros' => $erros, 'avisos' => $avisos];
    }

    /** Busca em largura a partir da entrada. */
    private static function alcancaveis(string $inicio, array $conexoes): array
    {
        $adj = [];
        foreach ($conexoes as $c) $adj[$c['no_origem']][] = $c['no_destino'];

        $vistos = [];
        $fila   = [$inicio];
        while ($fila) {
            $atual = array_shift($fila);
            foreach ($adj[$atual] ?? [] as $prox) {
                if (isset($vistos[$prox])) continue;
                $vistos[$prox] = true;
                $fila[] = $prox;
            }
        }
        return $vistos;
    }
}
