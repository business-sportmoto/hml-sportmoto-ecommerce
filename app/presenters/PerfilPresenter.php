<?php
// app/presenters/PerfilPresenter.php
// A área do cliente no app.
//
// Espelha views/customer/conta.php: cabeçalho com avatar, nome, selo de tier e
// pontuação, os destaques (crédito e score) e os contadores que alimentam os
// itens de menu.
//
// O `tier` sai como CÓDIGO ('gold') mais rótulo ('Ouro'). Mandar só o rótulo
// obrigaria o app a comparar string traduzida para escolher a cor do selo — e a
// tradução mudaria a cor no dia em que alguém corrigisse um acento.

final class PerfilPresenter
{
    /** Os quatro níveis, na ordem do menor para o maior. */
    private const TIERS = [
        'bronze'   => 'Bronze',
        'silver'   => 'Prata',
        'gold'     => 'Ouro',
        'platinum' => 'Platinum',
    ];

    /** O que a tela "Minha conta" precisa, numa resposta. */
    public static function resumo(
        array $perfil,
        array $stats,
        array $contadores,
        PresenterContext $ctx
    ): array {
        $tier = (string)($stats['tier'] ?? 'bronze');

        return [
            'cliente' => [
                'id'            => (int)($perfil['cliente_id'] ?? 0),
                'nome'          => $perfil['nome'] ?? '',
                'primeiro_nome' => trim(explode(' ', trim((string)($perfil['nome'] ?? '')))[0] ?? ''),
                'email'         => $perfil['email'] ?? '',
                'avatar'        => self::avatar($perfil['avatar'] ?? null, $ctx),
                'membro_desde'  => self::data($perfil['membro_desde'] ?? null),
                'verificado'    => !empty($perfil['verificado']),
            ],

            'nivel' => [
                'codigo' => isset(self::TIERS[$tier]) ? $tier : 'bronze',
                'rotulo' => self::TIERS[$tier] ?? 'Bronze',
                'score'  => (int)($stats['score'] ?? 0),
            ],

            // Os dois cartões de destaque do topo. `credito` só aparece na tela
            // quando é maior que zero — a decisão fica no app, mas o valor vem
            // sempre, para não precisar de uma segunda chamada quando entra
            // saldo.
            'credito' => PrecoPresenter::dec($perfil['saldo_disponivel'] ?? 0),

            // Contadores dos itens de menu. Um badge vazio é pior que nenhum:
            // zero vira null e o app não desenha nada.
            'contadores' => [
                'pedidos'     => self::ouNulo($contadores['pedidos'] ?? 0),
                'devolucoes'  => self::ouNulo($contadores['devolucoes'] ?? 0),
                'favoritos'   => self::ouNulo($contadores['favoritos'] ?? 0),
                'enderecos'   => self::ouNulo($contadores['enderecos'] ?? 0),
                'cartoes'     => self::ouNulo($contadores['cartoes'] ?? 0),
                'motos'       => self::ouNulo($contadores['motos'] ?? 0),
                'sessoes'     => self::ouNulo($contadores['sessoes'] ?? 0),
                // Este é o único que vale destacar mesmo pequeno: é uma ação
                // pendente do cliente, não um total.
                'avaliar'     => self::ouNulo($contadores['avaliar'] ?? 0),
            ],

            'seguranca' => [
                'email_verificado' => !empty($perfil['email_verificado']),
                'dois_fatores'     => !empty($contadores['dois_fatores']),
                'app_autenticador' => !empty($contadores['totp']),
            ],
        ];
    }

    /** Dados editáveis do perfil. */
    public static function detalhe(array $p, PresenterContext $ctx): array
    {
        return [
            'cliente_id'  => (int)($p['cliente_id'] ?? 0),
            'nome'        => $p['nome'] ?? '',
            'email'       => $p['email'] ?? '',
            'email_verificado' => !empty($p['email_verificado']),
            'cpf'         => self::cpf($p['cpf'] ?? null),
            'telefone'    => $p['telefone'] ?: null,
            'celular'     => $p['celular'] ?: null,
            'nascimento'  => $p['nascimento'] ?: null,   // YYYY-MM-DD
            'genero'      => $p['genero'] ?: null,
            'newsletter'  => !empty($p['newsletter']),
            'avatar'      => self::avatar($p['avatar'] ?? null, $ctx),
            'membro_desde'=> self::data($p['membro_desde'] ?? null),

            // O CPF é imutável depois de preenchido: ele amarra pedido, nota
            // fiscal e antifraude. Trocar exige suporte, e a tela precisa saber
            // disso para bloquear o campo em vez de deixar salvar e falhar.
            'cpf_editavel'=> empty($p['cpf']),
        ];
    }

    /** Cartão salvo — bandeira e últimos 4, NUNCA o token do vault. */
    public static function cartao(array $c): array
    {
        return [
            'id'        => (int)$c['id'],
            'bandeira'  => $c['bandeira'] ?: null,
            'ultimos_4' => $c['ultimos_4'] ?: null,
            'titular'   => $c['nome_titular'] ?: null,
            'apelido'   => $c['apelido'] ?: null,
            'validade'  => $c['validade'] ?: null,
            'principal' => !empty($c['principal']),
        ];
    }

    /** Sessão ativa em sessoes_persistentes. */
    public static function sessao(array $s): array
    {
        return [
            'id'          => (int)$s['id'],
            'dispositivo' => $s['dispositivo'] ?? 'Dispositivo desconhecido',
            'ip'          => $s['ip'] ?: null,
            'atual'       => !empty($s['atual']),
            'ultima'      => self::data($s['ultima_atividade'] ?? $s['criado_em'] ?? null),
            'ultima_texto'=> $s['ultima_fmt'] ?? null,
            'expira_em'   => self::data($s['expira_em'] ?? null),
        ];
    }

    /** Produto comprado, pendente ou não de avaliação. */
    public static function itemAvaliavel(array $i, PresenterContext $ctx): array
    {
        return [
            'produto_id' => (int)$i['produto_id'],
            'pedido_id'  => (int)($i['pedido_id'] ?? 0),
            'nome'       => $i['nome'] ?? '',
            'slug'       => $i['slug'] ?? '',
            'imagem'     => $ctx->url($i['img_capa'] ?? null),
            'preco_pago' => PrecoPresenter::dec($i['preco_pago'] ?? 0),
            'ja_avaliou' => !empty($i['ja_avaliou']),
            'avaliacao'  => [
                'media' => round((float)($i['nota_media'] ?? 0), 1),
                'total' => (int)($i['total_avaliacoes'] ?? 0),
            ],
        ];
    }

    /**
     * Avatares moram em uploads/avatars/. PresenterContext::url() recebe a
     * RAIZ ('upload' | 'asset' | 'base'), nao uma subpasta — passar 'avatars'
     * ali caia no padrao e devolvia a URL sem a pasta, quebrando a imagem.
     */
    private static function avatar(?string $arquivo, PresenterContext $ctx): ?string
    {
        $arquivo = trim((string)$arquivo);
        if ($arquivo === '') {
            return null;
        }
        // Ja absoluto (veio do Google, por exemplo): devolve como esta.
        if (preg_match('#^https?://#i', $arquivo)) {
            return $arquivo;
        }
        return $ctx->url('avatars/' . ltrim($arquivo, '/'));
    }

    private static function ouNulo(int $n): ?int
    {
        return $n > 0 ? $n : null;
    }

    /** 000.000.000-00 — a coluna guarda só dígitos. */
    private static function cpf(?string $cpf): ?string
    {
        $d = preg_replace('/\D/', '', (string)$cpf);
        if (strlen((string)$d) !== 11) {
            return $cpf ?: null;
        }
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9);
    }

    private static function data(?string $valor): ?string
    {
        if (!$valor) return null;
        $ts = strtotime($valor);
        return $ts ? date(DATE_ATOM, $ts) : null;
    }
}
