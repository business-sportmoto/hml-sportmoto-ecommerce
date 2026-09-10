<?php
declare(strict_types=1);

/**
 * ════════════════════════════════════════════════════════
 * app/helpers/DevolucaoStatus.php
 *
 * Rótulo e cor dos 14 status de `solicitacoes_devolucao`.
 *
 * ── POR QUE ISTO EXISTE ─────────────────────────────────
 *
 * Até 10/09/2026 o mapa estava copiado em QUATRO lugares:
 *
 *   admin/views/devolucoes/index.php     (com `cor`)
 *   admin/views/devolucoes/show.php      (com `cor`)
 *   views/customer/devolucoes/index.php  (com `cor` e `icon`)
 *   views/customer/devolucoes/show.php   (com `cor` e `desc`)
 *
 * E as cópias já tinham divergido. `pre_aprovado` era `success` numa tela e
 * `info` na outra. `solicitado` era "Solicitado" em três e "Aguardando
 * análise" na quarta. Pior: a cópia do cliente **não tinha `expirado`** —
 * então, assim que o cron de expiração começou a gravar esse status, ele caía
 * no fallback e a tela mostrava o slug cru "expirado" numa pílula azul.
 *
 * É o modo de falha típico de mapa duplicado: some um valor numa cópia e
 * ninguém percebe até alguém gravar aquele valor.
 *
 * ── POR QUE UM HELPER, E NÃO O PRESENTER ────────────────
 *
 * `DevolucaoPresenter` já tinha os 14 rótulos, e bem escritos — mas vive em
 * `app/presenters/`, que **não está** no autoloader do admin
 * (admin/index.php:17-35). Usá-lo numa view do painel daria "class not found",
 * a mesma armadilha que os adapters de pagamento já pegaram por ali.
 *
 * Fora isso, o Presenter monta payload da API do app: depende de
 * `PresenterContext` e `PrecoPresenter`. Arrastar essa camada para o painel
 * por causa de um mapa de rótulos seria acoplar o lado errado.
 *
 * `app/helpers/` está no autoloader das TRÊS frentes (loja, painel e CLI).
 *
 * ── O QUE FICA NA VIEW ──────────────────────────────────
 *
 * `label` e `cor` vêm daqui, sempre. O que é copy DAQUELA tela continua na
 * tela: o `desc` que explica o próximo passo ao cliente, o `icon` da
 * listagem, e rótulos deliberadamente diferentes onde a tela ganha com isso
 * (a listagem do cliente diz "Postar produto" em vez de "Aguardando
 * postagem" — é uma chamada para ação, e é melhor assim).
 *
 * Merge, não substituição:
 *
 *     $st = DevolucaoStatus::info($s) + ['desc' => '…'];
 * ════════════════════════════════════════════════════════
 */
final class DevolucaoStatus
{
    /**
     * Os 14 valores do ENUM, em português de gente.
     *
     * O texto vem do `DevolucaoPresenter`, que é onde estava mais bem
     * resolvido — inclusive no gênero: "solicitação" é feminino, e as cópias
     * do painel diziam "Solicitado", "Aprovado", "Negado".
     */
    private const MAPA = [
        'solicitado'             => ['label' => 'Solicitação enviada',    'cor' => 'warning'],
        'pre_aprovado'           => ['label' => 'Pré-aprovada',           'cor' => 'info'],
        'aguardando_aprovacao'   => ['label' => 'Em análise',             'cor' => 'warning'],
        'aprovado'               => ['label' => 'Aprovada',               'cor' => 'info'],
        'negado'                 => ['label' => 'Negada',                 'cor' => 'danger'],
        'aguardando_postagem'    => ['label' => 'Aguardando postagem',    'cor' => 'warning'],
        'em_transito_reverso'    => ['label' => 'A caminho da loja',      'cor' => 'primary'],
        'item_recebido'          => ['label' => 'Recebida pela loja',     'cor' => 'info'],
        'inspecionado_aprovado'  => ['label' => 'Inspeção aprovada',      'cor' => 'success'],
        'inspecionado_reprovado' => ['label' => 'Inspeção reprovada',     'cor' => 'danger'],
        'concluido'              => ['label' => 'Concluída',              'cor' => 'success'],
        'concluido_reprovado'    => ['label' => 'Encerrada sem reembolso','cor' => 'danger'],
        'cancelado'              => ['label' => 'Cancelada',              'cor' => 'danger'],
        'expirado'               => ['label' => 'Prazo expirado',         'cor' => 'gray'],
    ];

    /**
     * Rótulo + cor de um status.
     *
     * Nunca devolve null. Status desconhecido (ENUM ampliado sem passar por
     * aqui) volta com o slug legível e cor neutra — feio, mas não quebra a
     * tela nem mostra `em_transito_reverso` cru para o cliente.
     */
    public static function info(string $status): array
    {
        return self::MAPA[$status] ?? [
            'label' => ucfirst(str_replace('_', ' ', $status)),
            'cor'   => 'info',
        ];
    }

    public static function label(string $status): string
    {
        return self::info($status)['label'];
    }

    public static function cor(string $status): string
    {
        return self::info($status)['cor'];
    }

    /** O mapa inteiro, na ordem do fluxo. Para selects e chips de filtro. */
    public static function todos(): array
    {
        return self::MAPA;
    }

    /**
     * Status em que a solicitação ainda está em andamento.
     *
     * O complemento de `DESFECHOS` — quem não terminou. Útil para contar fila
     * aberta sem repetir a lista de desfechos em cada consulta.
     */
    public const DESFECHOS = [
        'negado', 'cancelado', 'expirado', 'concluido', 'concluido_reprovado',
    ];

    public static function emAndamento(string $status): bool
    {
        return !in_array($status, self::DESFECHOS, true);
    }
}
