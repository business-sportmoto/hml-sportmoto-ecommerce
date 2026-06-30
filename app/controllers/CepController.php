<?php
// app/controllers/CepController.php
// Gerencia o CEP global do cliente usado em todos os cálculos de frete.

class CepController extends Controller {

    /**
     * Retorna as informações do CEP ativo para o header.
     * Prioridade: endereço principal logado > cookie > nada.
     */
    public function info(): void {
        $result = self::getCepAtivo();
        $this->json($result);
    }

    /**
     * Salva o CEP informado manualmente (visitante ou logado sem endereço).
     * Busca os dados do CEP via ViaCEP e salva em cookie.
     */
    public function save(): void {
        $cep = preg_replace('/\D/', '', $_POST['cep'] ?? '');

        if (strlen($cep) !== 8) {
            $this->json(['ok' => false, 'msg' => 'CEP inválido.']);
        }

        // Consulta ViaCEP
        $dados = self::consultarViaCep($cep);
        if (!$dados) {
            $this->json(['ok' => false, 'msg' => 'CEP não encontrado.']);
        }

        // Salva em cookie por 30 dias
        setcookie('ec_cep', $cep, [
            'expires'  => time() + (86400 * 30),
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => false, // JS precisa ler
            'samesite' => 'Lax',
        ]);

        // Se cliente logado sem endereço: salva o endereço completo
        if (Session::isClienteLogado() && !empty($_POST['salvar_endereco'])) {
            $this->salvarEnderecoLogado($dados, $cep);
        }

        $this->json([
            'ok'         => true,
            'cep'        => $cep,
            'cep_fmt'    => self::formatCep($cep),
            'localidade' => $dados['localidade'] ?? '',
            'uf'         => $dados['uf'] ?? '',
            'display'    => ($dados['localidade'] ?? '') . ' — ' . ($dados['uf'] ?? ''),
        ]);
    }

    public function remove(): void {
        setcookie('ec_cep', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        $this->json(['ok' => true]);
    }

    // ── Helpers estáticos (usados em outras partes) ───────────

    /**
     * Retorna o CEP ativo com suas informações completas.
     * Ordem de prioridade:
     *   1. Endereço principal do cliente logado
     *   2. Cookie ec_cep
     *   3. Nenhum
     */
    public static function getCepAtivo(): array {
        // 1. Cliente logado com endereço principal
        if (Session::isClienteLogado()) {
            $clienteId = Session::getClienteId();
            $db        = Database::getInstance()->getConnection();
            $stmt      = $db->prepare(
                "SELECT cep, logradouro, bairro, cidade, estado
                 FROM enderecos
                 WHERE cliente_id = ? AND principal = 1
                 LIMIT 1"
            );
            $stmt->execute([$clienteId]);
            $end = $stmt->fetch();

            if ($end && !empty($end['cep'])) {
                $cep = preg_replace('/\D/', '', $end['cep']);
                return [
                    'tem_cep'    => true,
                    'cep'        => $cep,
                    'cep_fmt'    => self::formatCep($cep),
                    'localidade' => $end['cidade'],
                    'uf'         => $end['estado'],
                    'bairro'     => $end['bairro'],
                    'display'    => $end['cidade'] . ' — ' . $end['estado'],
                    'origem'     => 'endereco', // veio do endereço cadastrado
                    'logado'     => true,
                    'tem_endereco' => true,
                ];
            }

            // Logado mas sem endereço
            return [
                'tem_cep'      => false,
                'logado'       => true,
                'tem_endereco' => false,
                'display'      => null,
            ];
        }

        // 2. Cookie
        $cookieCep = preg_replace('/\D/', '', $_COOKIE['ec_cep'] ?? '');
        if (strlen($cookieCep) === 8) {
            $dados = self::consultarViaCep($cookieCep);
            return [
                'tem_cep'    => true,
                'cep'        => $cookieCep,
                'cep_fmt'    => self::formatCep($cookieCep),
                'localidade' => $dados['localidade'] ?? '',
                'uf'         => $dados['uf']         ?? '',
                'display'    => ($dados['localidade'] ?? '') . ' — ' . ($dados['uf'] ?? ''),
                'origem'     => 'cookie',
                'logado'     => false,
                'tem_endereco' => false,
            ];
        }

        // 3. Nenhum
        return [
            'tem_cep'      => false,
            'logado'       => false,
            'tem_endereco' => false,
            'display'      => null,
        ];
    }

    public static function formatCep(string $cep): string {
        $cep = preg_replace('/\D/', '', $cep);
        return strlen($cep) === 8
            ? substr($cep, 0, 5) . '-' . substr($cep, 5)
            : $cep;
    }

    public static function consultarViaCep(string $cep): ?array {
        $cep = preg_replace('/\D/', '', $cep);
        if (strlen($cep) !== 8) return null;

        // Cache local por 24h para evitar muitas chamadas à API
        $cacheKey  = 'cep_' . $cep;
        $cached    = CacheHelper::get($cacheKey);
        if ($cached) return $cached;

        $ctx  = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $res  = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
        if (!$res) return null;

        $data = json_decode($res, true);
        if (!$data || isset($data['erro'])) return null;

        CacheHelper::set($cacheKey, $data, 86400);
        return $data;
    }

    private function salvarEnderecoLogado(array $dados, string $cep): void {
        try {
            $clienteId = Session::getClienteId();
            $db        = Database::getInstance()->getConnection();

            // Verifica se já existe algum endereço
            $stmt = $db->prepare("SELECT COUNT(*) FROM enderecos WHERE cliente_id = ?");
            $stmt->execute([$clienteId]);
            $total = (int) $stmt->fetchColumn();

            $db->prepare(
                "INSERT INTO enderecos
                 (cliente_id, nome_destinatario, cep, logradouro, bairro,
                  cidade, estado, numero, principal)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                $clienteId,
                Session::get('cliente_nome'),
                $cep,
                $dados['logradouro'] ?? '',
                $dados['bairro']     ?? '',
                $dados['localidade'] ?? '',
                $dados['uf']         ?? '',
                'S/N',
                $total === 0 ? 1 : 0, // principal se for o primeiro
            ]);
        } catch (Exception) {}
    }
}