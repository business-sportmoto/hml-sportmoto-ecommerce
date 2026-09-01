<?php
// app/controllers/AppPerfilController.php
// A área do cliente: resumo, perfil, cartões, sessões, segurança e avaliações.
//
// Reusa CustomerController inteiro no que é regra — Customer::getFullProfile(),
// getDashboardStats(), getProdutosParaAvaliar(), SessionManager, TwoFactorService
// e TotpService. Aqui só há tradução para o envelope da API.
//
// O que NÃO é reusado é o CustomerController em si: aquele fluxo é de navegador
// (CSRF em $_POST, render de view, flash). É a mesma separação de
// AppAuthService.

class AppPerfilController extends AppApiController
{
    /**
     * GET /api/app/v1/conta/resumo
     *
     * A tela "Minha conta" inteira, numa requisição. São seis contagens além do
     * perfil — se cada item de menu buscasse a sua, abrir a aba de conta
     * custaria sete idas ao servidor.
     */
    public function resumo(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $clienteId = (int)$this->clienteId;
        $modelo    = new Customer();

        try {
            $perfil = $modelo->getFullProfile($clienteId);
            $stats  = $modelo->getDashboardStats($clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'resumo_conta']);
            $this->falha(500, 'falha_perfil', 'Não foi possível carregar sua conta.');
        }

        if (!$perfil) {
            $this->falha(404, 'nao_encontrado', 'Perfil não encontrado.');
        }

        $this->ok(['conta' => PerfilPresenter::resumo(
            $perfil,
            $stats,
            $this->contadores($clienteId, (int)$perfil['usuario_id']),
            $this->contexto()
        )]);
    }

    /**
     * GET /api/app/v1/conta/perfil/dados
     * Os campos editáveis. Separado do /resumo porque a tela de edição não
     * precisa dos contadores e o resumo não precisa de nascimento e gênero.
     */
    public function dados(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $perfil = (new Customer())->getFullProfile((int)$this->clienteId);

        if (!$perfil) {
            $this->falha(404, 'nao_encontrado', 'Perfil não encontrado.');
        }

        $this->ok(['perfil' => PerfilPresenter::detalhe($perfil, $this->contexto())]);
    }

    /**
     * PATCH /api/app/v1/conta/perfil
     * Corpo: { nome?, cpf?, telefone?, celular?, nascimento?, genero?, newsletter? }
     *
     * As mesmas validações de CustomerController::saveProfile().
     */
    public function atualizar(): void
    {
        $this->bootCliente();

        $corpo     = $this->corpo();
        $clienteId = (int)$this->clienteId;
        $modelo    = new Customer();
        $perfil    = $modelo->getFullProfile($clienteId);

        if (!$perfil) {
            $this->falha(404, 'nao_encontrado', 'Perfil não encontrado.');
        }

        $erros = [];

        $nome = array_key_exists('nome', $corpo)
            ? trim(SecurityHelper::sanitizeString((string)$corpo['nome']))
            : (string)$perfil['nome'];

        if (mb_strlen($nome) < 3) {
            $erros['nome'] = 'Informe seu nome completo.';
        }

        // O CPF só pode ser DEFINIDO, nunca trocado: ele amarra pedido, nota
        // fiscal e antifraude. Quem já tem CPF e manda outro está corrigindo um
        // erro que precisa passar pelo suporte, não pela tela de perfil.
        $cpfAtual = preg_replace('/\D/', '', (string)($perfil['cpf'] ?? ''));
        $cpf      = $cpfAtual;

        if (array_key_exists('cpf', $corpo)) {
            $novo = preg_replace('/\D/', '', (string)$corpo['cpf']);

            if ($cpfAtual !== '' && $novo !== '' && $novo !== $cpfAtual) {
                $erros['cpf'] = 'O CPF não pode ser alterado. Fale com o atendimento.';
            } elseif ($novo !== '') {
                if (!SecurityHelper::validateCpf($novo)) {
                    $erros['cpf'] = 'CPF inválido.';
                } elseif ((new User())->cpfExists($novo, $clienteId)) {
                    $erros['cpf'] = 'Este CPF já está cadastrado.';
                } else {
                    $cpf = $novo;
                }
            }
        }

        $celular = array_key_exists('celular', $corpo)
            ? preg_replace('/\D/', '', (string)$corpo['celular'])
            : preg_replace('/\D/', '', (string)($perfil['celular'] ?? ''));

        if ($celular !== '' && (strlen((string)$celular) < 10 || strlen((string)$celular) > 11)) {
            $erros['celular'] = 'Informe o DDD e o número.';
        }

        $nascimento = array_key_exists('nascimento', $corpo)
            ? trim((string)$corpo['nascimento'])
            : (string)($perfil['nascimento'] ?? '');

        if ($nascimento !== '' && !$this->dataValida($nascimento)) {
            $erros['nascimento'] = 'Data inválida.';
        }

        if ($erros) {
            $this->falha(422, 'dados_invalidos', 'Confira os dados informados.', ['erros' => $erros]);
        }

        try {
            $modelo->updateProfile($clienteId, [
                'usuario_id' => (int)$perfil['usuario_id'],
                'nome'       => $nome,
                'cpf'        => $cpf,
                'telefone'   => array_key_exists('telefone', $corpo)
                    ? SecurityHelper::sanitizeString((string)$corpo['telefone'])
                    : (string)($perfil['telefone'] ?? ''),
                'celular'    => $celular,
                'nascimento' => $nascimento,
                'genero'     => array_key_exists('genero', $corpo)
                    ? (string)$corpo['genero']
                    : (string)($perfil['genero'] ?? ''),
                'newsletter' => array_key_exists('newsletter', $corpo)
                    ? !empty($corpo['newsletter'])
                    : !empty($perfil['newsletter']),
            ]);

            // A sessão carrega o nome para o cabeçalho da loja; sem atualizar,
            // a web continuaria mostrando o nome antigo até o próximo login.
            Session::set('cliente_nome', $nome);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'atualizar_perfil']);
            $this->falha(500, 'falha_perfil', 'Não foi possível salvar suas alterações.');
        }

        $this->liberarSessao();

        $this->ok([
            'perfil' => PerfilPresenter::detalhe(
                $modelo->getFullProfile($clienteId),
                $this->contexto()
            ),
        ]);
    }

    /**
     * POST /api/app/v1/conta/perfil/avatar   (multipart: arquivo)
     */
    public function avatar(): void
    {
        $this->bootCliente();

        if (empty($_FILES['arquivo']['name'])) {
            $this->falha(422, 'arquivo_ausente', 'Envie uma imagem.');
        }

        if (!SecurityHelper::validateUploadedImage($_FILES['arquivo'])) {
            $this->falha(422, 'imagem_invalida', 'Use JPG, PNG ou WEBP de até 5 MB.');
        }

        $clienteId = (int)$this->clienteId;
        $modelo    = new Customer();
        $perfil    = $modelo->getFullProfile($clienteId);

        try {
            $arquivo = (new UploadHelper())->saveImage($_FILES['arquivo'], 'avatars', 200, 200);

            if (!$arquivo) {
                $this->falha(422, 'imagem_invalida', 'Não foi possível processar a imagem.');
            }

            // Remove o anterior só DEPOIS de o novo existir: falhar no meio
            // deixaria o cliente sem avatar nenhum.
            if (!empty($perfil['avatar'])) {
                @unlink(UPLOAD_PATH . '/avatars/' . $perfil['avatar']);
            }

            $modelo->updateAvatar($clienteId, $arquivo);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'avatar']);
            $this->falha(500, 'falha_avatar', 'Não foi possível salvar sua foto.');
        }

        $this->liberarSessao();

        $this->ok([
            'perfil' => PerfilPresenter::detalhe($modelo->getFullProfile($clienteId), $this->contexto()),
        ]);
    }

    /**
     * POST /api/app/v1/conta/senha
     * Corpo: { senha_atual, nova_senha }
     */
    public function trocarSenha(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['senha_atual', 'nova_senha']);

        $perfil    = (new Customer())->getFullProfile((int)$this->clienteId);
        $usuarioId = (int)$perfil['usuario_id'];
        $userModel = new User();

        if (!$userModel->verifyCurrentPassword($usuarioId, (string)$corpo['senha_atual'])) {
            $this->falha(422, 'senha_incorreta', 'Senha atual incorreta.');
        }

        if (!SecurityHelper::validatePassword((string)$corpo['nova_senha'])) {
            $this->falha(422, 'senha_fraca', 'Use 8+ caracteres, com maiúscula, minúscula e número.');
        }

        try {
            $userModel->updatePassword($usuarioId, (string)$corpo['nova_senha']);

            // Trocar a senha derruba as outras sessões, mas NÃO a atual: quem
            // acabou de provar a senha antiga não deve ser expulso do app.
            (new SessionManager())->revokeAllExceptCurrent($usuarioId);
            $this->tokensDeOutrosDispositivos($usuarioId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'trocar_senha']);
            $this->falha(500, 'falha_senha', 'Não foi possível alterar sua senha.');
        }

        LogService::audit('Senha alterada pelo app', ['usuario_id' => $usuarioId]);

        $this->liberarSessao();
        $this->ok(['alterada' => true, 'mensagem' => 'Senha alterada. As outras sessões foram encerradas.']);
    }

    /* =================================================================
       CARTÕES
       ================================================================= */

    /**
     * POST /api/app/v1/conta/cartoes/{id}/principal
     */
    public function cartaoPrincipal(string $id = '0'): void
    {
        $this->bootCliente();

        $modelo = new Customer();

        if (!(new CartaoSalvo())->findOwned((int)$id, (int)$this->clienteId)) {
            $this->falha(404, 'nao_encontrado', 'Cartão não encontrado.');
        }

        $modelo->setPrincipalCard((int)$this->clienteId, (int)$id);
        $this->liberarSessao();

        $this->ok(['cartoes' => array_values(array_map(
            static fn(array $c) => PerfilPresenter::cartao($c),
            $modelo->getCards((int)$this->clienteId)
        ))]);
    }

    /* =================================================================
       SESSÕES
       ================================================================= */

    /**
     * GET /api/app/v1/conta/sessoes
     */
    public function sessoes(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $perfil = (new Customer())->getFullProfile((int)$this->clienteId);

        try {
            $linhas = (new SessionManager())->getActiveSessions((int)$perfil['usuario_id']);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'sessoes']);
            $linhas = [];
        }

        $this->ok(['sessoes' => array_values(array_map(
            static fn(array $s) => PerfilPresenter::sessao($s),
            $linhas
        ))]);
    }

    /**
     * DELETE /api/app/v1/conta/sessoes/{id}
     */
    public function encerrarSessao(string $id = '0'): void
    {
        $this->bootCliente();

        $perfil = (new Customer())->getFullProfile((int)$this->clienteId);
        $ok = (new SessionManager())->revokeSession((int)$id, (int)$perfil['usuario_id']);

        if (!$ok) {
            $this->falha(404, 'nao_encontrado', 'Sessão não encontrada.');
        }

        LogService::audit('Sessão encerrada pelo app', [
            'usuario_id' => (int)$perfil['usuario_id'],
            'sessao_id'  => (int)$id,
        ]);

        $this->liberarSessao();
        $this->ok(['encerrada' => true]);
    }

    /**
     * DELETE /api/app/v1/conta/sessoes
     * Encerra todas as outras — a atual continua.
     */
    public function encerrarOutras(): void
    {
        $this->bootCliente();

        $perfil    = (new Customer())->getFullProfile((int)$this->clienteId);
        $usuarioId = (int)$perfil['usuario_id'];

        $total = (new SessionManager())->revokeAllExceptCurrent($usuarioId);
        $apps  = $this->tokensDeOutrosDispositivos($usuarioId);

        LogService::audit('Sessões encerradas em massa pelo app', [
            'usuario_id' => $usuarioId,
            'web'        => $total,
            'app'        => $apps,
        ]);

        $this->liberarSessao();
        $this->ok(['encerradas' => $total + $apps]);
    }

    /* =================================================================
       SEGURANÇA
       ================================================================= */

    /**
     * GET /api/app/v1/conta/seguranca
     */
    public function seguranca(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        $perfil    = (new Customer())->getFullProfile((int)$this->clienteId);
        $usuarioId = (int)$perfil['usuario_id'];

        $totp = new TotpService();

        $this->ok(['seguranca' => [
            'email_verificado' => !empty($perfil['email_verificado']),
            // O 2FA por ENVIO (e-mail, WhatsApp, SMS) e o app autenticador são
            // dois interruptores diferentes — ver AuthController::getCanais2FA.
            'dois_fatores'     => (new TwoFactorService())->isAtivo($usuarioId),
            'app_autenticador' => $totp->isAtivo($usuarioId),
            // Quantos códigos de backup ainda valem. Zero com o TOTP ativo é
            // uma conta a um aparelho perdido de ficar inacessível — a tela
            // precisa saber disso para avisar.
            'codigos_backup'   => $totp->isAtivo($usuarioId)
                ? $totp->contarCodigosBackupRestantes($usuarioId)
                : 0,
            'tem_celular'      => strlen((string)preg_replace('/\D/', '', (string)($perfil['celular'] ?? ''))) >= 10,
            'sessoes_ativas'   => count((new SessionManager())->getActiveSessions($usuarioId)),
        ]]);
    }

    /**
     * POST /api/app/v1/conta/seguranca/2fa   Corpo: { ativo }
     *
     * Só o 2FA por envio. O app autenticador tem fluxo próprio, em
     * AppTotpController — são dois interruptores independentes, e a loja os
     * trata separado (ver AuthController::getCanais2FA).
     */
    public function alternar2fa(): void
    {
        $this->bootCliente();
        $corpo = $this->exigirCampos(['ativo']);

        $perfil    = (new Customer())->getFullProfile((int)$this->clienteId);
        $usuarioId = (int)$perfil['usuario_id'];
        $ativar    = !empty($corpo['ativo']);

        $servico = new TwoFactorService();

        // Ligar o 2FA por envio sem canal utilizável trancaria a conta no
        // próximo login. O e-mail sempre existe, então na prática isto só
        // barra conta sem e-mail — mas é a checagem que impede o pior caso.
        if ($ativar && empty($perfil['email'])) {
            $this->falha(422, 'sem_canal', 'Sua conta precisa de um e-mail confirmado para ativar a verificação.');
        }

        try {
            $ativar ? $servico->ativar($usuarioId) : $servico->desativar($usuarioId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'toggle_2fa']);
            $this->falha(500, 'falha_2fa', 'Não foi possível alterar a verificação em duas etapas.');
        }

        LogService::audit($ativar ? '2FA ativado pelo app' : '2FA desativado pelo app', [
            'usuario_id' => $usuarioId,
        ]);

        $this->liberarSessao();
        $this->ok(['dois_fatores' => $ativar]);
    }

    /* =================================================================
       AVALIAÇÕES
       ================================================================= */

    /**
     * GET /api/app/v1/conta/avaliacoes
     *
     * Produtos comprados, com marcação do que já foi avaliado. A mesma consulta
     * de CustomerController::minhasAvaliacoes().
     */
    public function avaliacoes(): void
    {
        $this->bootCliente();
        $this->liberarSessao();

        try {
            $itens = (new Customer())->getProdutosParaAvaliar((int)$this->clienteId);
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'avaliacoes']);
            $itens = [];
        }

        $ctx = $this->contexto();
        $lista = array_values(array_map(
            static fn(array $i) => PerfilPresenter::itemAvaliavel($i, $ctx),
            $itens
        ));

        $avaliados = count(array_filter($lista, static fn(array $i) => $i['ja_avaliou']));

        $this->ok([
            'itens'     => $lista,
            'avaliados' => $avaliados,
            'pendentes' => count($lista) - $avaliados,
        ]);
    }

    /* =================================================================
       INTERNOS
       ================================================================= */

    /**
     * Os números dos badges do menu. Uma query por contador, todas por índice —
     * mais barato que trazer as listas inteiras só para contar.
     */
    private function contadores(int $clienteId, int $usuarioId): array
    {
        $conta = function (string $sql, array $params): int {
            try {
                $st = $this->db()->prepare($sql);
                $st->execute($params);
                return (int)$st->fetchColumn();
            } catch (\Throwable $e) {
                return 0;
            }
        };

        return [
            'pedidos'    => $conta("SELECT COUNT(*) FROM pedidos WHERE cliente_id = ?", [$clienteId]),
            'devolucoes' => $conta("SELECT COUNT(*) FROM solicitacoes_devolucao WHERE cliente_id = ?", [$clienteId]),
            'favoritos'  => $conta(
                "SELECT COUNT(*) FROM wishlist_itens wi
                 JOIN wishlist w ON w.id = wi.wishlist_id
                 WHERE w.cliente_id = ?", [$clienteId]
            ),
            'enderecos'  => $conta("SELECT COUNT(*) FROM enderecos WHERE cliente_id = ?", [$clienteId]),
            'cartoes'    => $conta("SELECT COUNT(*) FROM cartoes_salvos WHERE cliente_id = ? AND ativo = 1", [$clienteId]),
            'motos'      => $conta("SELECT COUNT(*) FROM cliente_veiculos WHERE cliente_id = ?", [$clienteId]),
            'sessoes'    => $conta(
                "SELECT COUNT(*) FROM sessoes_persistentes WHERE usuario_id = ? AND expira_em > NOW()",
                [$usuarioId]
            ),
            // Produtos entregues que ainda não têm avaliação deste cliente.
            'avaliar'    => $conta(
                "SELECT COUNT(DISTINCT pi.produto_id)
                   FROM pedido_itens pi
                   JOIN pedidos p ON p.id = pi.pedido_id
                  WHERE p.cliente_id = :c
                    AND p.status_pedido = 'entregue'
                    AND NOT EXISTS (
                        SELECT 1 FROM avaliacoes a
                         WHERE a.produto_id = pi.produto_id AND a.cliente_id = :c2
                    )",
                [':c' => $clienteId, ':c2' => $clienteId]
            ),
            'dois_fatores' => (new TwoFactorService())->isAtivo($usuarioId),
            'totp'         => (new TotpService())->isAtivo($usuarioId),
        ];
    }

    /**
     * Revoga os tokens do app nos OUTROS dispositivos, preservando este.
     * SessionManager cuida só das sessões web (`sessoes_persistentes`); sem
     * isto, "encerrar as outras sessões" deixaria outro celular logado.
     */
    private function tokensDeOutrosDispositivos(int $usuarioId): int
    {
        try {
            $st = $this->db()->prepare(
                "UPDATE app_tokens
                    SET revogado_em = NOW(), motivo_revogacao = 'encerrar_sessoes'
                  WHERE usuario_id = :u
                    AND dispositivo_id <> :d
                    AND revogado_em IS NULL"
            );
            $st->execute([':u' => $usuarioId, ':d' => (int)$this->dispositivo['id']]);
            return $st->rowCount();
        } catch (\Throwable $e) {
            AppLog::exception($e, ['acao' => 'revogar_outros_dispositivos']);
            return 0;
        }
    }

    private function dataValida(string $valor): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $valor);
        return $d && $d->format('Y-m-d') === $valor && $d <= new DateTime();
    }
}
