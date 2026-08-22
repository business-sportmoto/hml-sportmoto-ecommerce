<?php
// app/services/app/AppSessionBridge.php
// A ponte entre o token do dispositivo e a $_SESSION do PHP.
//
// O motivo desta classe existir: praticamente toda a lógica de negócio da loja
// resolve o usuário atual lendo a sessão por dentro. Em vez de reescrever tudo
// de forma stateless (caro e arriscado), o dispositivo carrega um php_session_id
// estável, e este serviço o instala antes de Session::start(). Com isso passam
// a funcionar sem nenhuma alteração:
//
//   Cart::getOrCreateCarrinhoId()  — chaveia o carrinho anônimo por session_id()
//   Cart::getOrCreate()
//   CheckoutState                  — SESSION_KEY = 'checkout_state'
//   VeiculoService::getAtivo()     — $_SESSION['meu_veiculo']
//   CouponController               — Session::get('cupom_aplicado')
//   ClipController::sessionKey()   — likes e views anônimos
//   Product::getList()/getCatalog() — o campo `favoritado`
//
// Três armadilhas reais do projeto, todas tratadas aqui:
//   1. index.php:7 liga session.use_strict_mode. Com ele, o PHP IGNORA um id
//      que ainda não existe e gera outro — a ponte quebraria em silêncio.
//      O bootstrap da API desliga (é seguro: o id vem do banco, não do cliente).
//   2. Session::start() faz session_regenerate_id(false) ao nascer a sessão
//      (core/Session.php:50) e loginCliente() faz session_regenerate_id(true)
//      (core/Session.php:123). Nos dois casos o id muda e precisa ser regravado.
//   3. Session::validateFingerprint() destrói a sessão quando o User-Agent muda
//      (core/Session.php:60). Neutralizado pelo guard APP_API naquele método.

class AppSessionBridge
{
    private static ?array $dispositivo = null;
    private static bool $aberta = false;

    /**
     * Instala a sessão do dispositivo e a inicia.
     * Devolve o session id efetivamente em uso (pode diferir do armazenado, se
     * o PHP regenerou durante o start — nesse caso já foi persistido).
     */
    public static function abrir(array $dispositivo): string
    {
        if (self::$aberta) {
            return session_id();
        }

        self::$dispositivo = $dispositivo;

        $sid      = trim((string)($dispositivo['php_session_id'] ?? ''));
        $nascendo = ($sid === '' || !self::sidValido($sid));

        if ($nascendo) {
            $sid = self::novoSid();
        }

        session_id($sid);
        Session::start();
        self::$aberta = true;

        // Armadilha 2: ao nascer a sessão, Session::start() faz
        // session_regenerate_id(false) (core/Session.php:50) e o id muda. O
        // `false` preserva a sessão antiga de propósito — na web isso evita que
        // requests AJAX paralelos percam o login. Aqui a sessão antiga é vazia
        // e ninguém a referencia, então limpamos para não acumular uma linha
        // órfã em app_sessoes a cada instalação nova do app.
        self::sincronizarSid((int)$dispositivo['id'], $sid, $nascendo);

        // Marca a sessão como pertencente ao app. Serve de guard em pontos que
        // precisam distinguir app de navegador.
        Session::set('_app_dispositivo_id', (int)$dispositivo['id']);

        // Espelha o cliente autenticado do dispositivo para dentro da sessão.
        if (!empty($dispositivo['cliente_id'])) {
            self::espelharCliente($dispositivo);
        }

        return session_id();
    }

    /**
     * Preenche a sessão a partir do vínculo já gravado em app_dispositivos.
     * Não usa Session::loginCliente() de propósito: aquele método regenera o id
     * (o que aqui só faria o device perder o carrinho) e é para o ato de login,
     * não para restaurar um login que já aconteceu.
     */
    private static function espelharCliente(array $dispositivo): void
    {
        if (Session::isClienteLogado() && (int)Session::get('cliente_id') === (int)$dispositivo['cliente_id']) {
            return; // já espelhado
        }

        try {
            $pdo = Database::getInstance()->getConnection();
            $st = $pdo->prepare(
                "SELECT u.id AS usuario_id, u.nome, u.email, c.id AS cliente_id
                 FROM clientes c
                 INNER JOIN usuarios u ON u.id = c.usuario_id
                 WHERE c.id = :cid AND u.ativo = 1 AND u.deleted_at IS NULL
                 LIMIT 1"
            );
            $st->execute([':cid' => (int)$dispositivo['cliente_id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            LogService::error('AppSessionBridge: falha ao espelhar cliente', ['erro' => $e->getMessage()]);
            return;
        }

        if (!$row) {
            // Conta desativada ou removida — o dispositivo volta a anônimo.
            (new AppDeviceService())->desvincularCliente((int)$dispositivo['id']);
            return;
        }

        Session::set('cliente_logado', true);
        Session::set('cliente_id',    (int)$row['cliente_id']);
        Session::set('usuario_id',    (int)$row['usuario_id']);
        Session::set('cliente_nome',  $row['nome']);
        Session::set('cliente_email', $row['email']);
    }

    /**
     * Chamar SEMPRE depois de qualquer operação que regenere o id de sessão
     * (Session::loginCliente(), logoutCliente()). Sem isso o dispositivo aponta
     * para uma sessão que não existe mais e o carrinho some.
     */
    public static function sincronizarSid(
        int $dispositivoId,
        ?string $sidAnterior = null,
        bool $descartarAnterior = false
    ): void {
        $atual = session_id();
        if ($atual === '' || $atual === $sidAnterior) {
            return;
        }

        (new AppDeviceService())->salvarSessionId($dispositivoId, $atual);

        if (self::$dispositivo !== null) {
            self::$dispositivo['php_session_id'] = $atual;
        }

        // Só descarta quando temos certeza de que a sessão anterior nasceu e
        // morreu neste mesmo request, sem dados. Nunca chamar com true depois
        // de um login: ali a sessão antiga pode conter o carrinho de visitante.
        if ($descartarAnterior && $sidAnterior) {
            try {
                Database::getInstance()->getConnection()
                    ->prepare("DELETE FROM app_sessoes WHERE id = :id AND (dados IS NULL OR LENGTH(dados) = 0)")
                    ->execute([':id' => $sidAnterior]);
            } catch (\Throwable $e) { /* órfã vazia não justifica derrubar o request */ }
        }
    }

    /**
     * Fecha a sessão para escrita, liberando qualquer lock e permitindo que os
     * requests paralelos do app avancem. Os dados já lidos continuam em
     * $_SESSION para leitura no resto do request.
     */
    public static function liberar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** Descarta a sessão atual e devolve o dispositivo a um estado anônimo limpo. */
    public static function reciclar(int $dispositivoId): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        $novo = self::novoSid();
        session_id($novo);
        session_start();
        $_SESSION = ['_app_dispositivo_id' => $dispositivoId];

        (new AppDeviceService())->salvarSessionId($dispositivoId, $novo);
        return $novo;
    }

    public static function dispositivoId(): ?int
    {
        return self::$dispositivo ? (int)self::$dispositivo['id'] : null;
    }

    private static function novoSid(): string
    {
        return bin2hex(random_bytes(26)); // 52 chars, cabe em VARCHAR(128)
    }

    private static function sidValido(string $sid): bool
    {
        // O PHP rejeita ids fora de [A-Za-z0-9,-]; validamos antes para não
        // deixar um valor corrompido no banco derrubar a sessão inteira.
        return (bool)preg_match('/^[A-Za-z0-9,\-]{22,128}$/', $sid);
    }
}
