<?php
/**
 * ClienteBuscaService — busca de cliente por CPF para a logística reversa.
 *
 * Estrutura: `usuarios` é o cadastro principal e `clientes` referencia
 * `clientes.usuario_id = usuarios.id`. A busca é por CPF (em `clientes`) e
 * devolve identidade + endereço para autopreencher o formulário de reversa.
 *
 * ┌───────────────────────────────────────────────────────────────────────┐
 * │  AJUSTE AQUI se os nomes de tabela/coluna forem diferentes no seu banco │
 * └───────────────────────────────────────────────────────────────────────┘
 * Se as colunas de ENDEREÇO não existirem em `clientes` (ou tiverem outro
 * nome), a busca faz fallback automático para só identidade (nome/cpf/tel/
 * email) — o endereço continua editável na mão. Ajuste os nomes conforme
 * necessário; nada mais no módulo precisa mudar.
 */
class ClienteBuscaService
{
    // -- tabelas --
    private const T_USUARIOS = 'usuarios';
    private const T_CLIENTES = 'clientes';

    // -- usuarios (principal) --
    private const C_USU_ID    = 'id';
    private const C_USU_NOME  = 'nome';
    private const C_USU_EMAIL = 'email';

    // -- clientes --
    private const C_CLI_ID   = 'id';
    private const C_CLI_USU  = 'usuario_id';   // clientes.usuario_id -> usuarios.id
    private const C_CLI_CPF  = 'cpf';
    private const C_CLI_FONE = 'telefone';

    // -- endereço (em clientes) — opcional; ajuste os nomes se preciso --
    private const C_CLI_CEP         = 'cep';
    private const C_CLI_LOGRADOURO  = 'logradouro';
    private const C_CLI_NUMERO      = 'numero';
    private const C_CLI_COMPLEMENTO = 'complemento';
    private const C_CLI_BAIRRO      = 'bairro';
    private const C_CLI_CIDADE      = 'cidade';
    private const C_CLI_UF          = 'uf';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       Puros
       ================================================================= */

    public static function soDigitos(string $s): string
    {
        return preg_replace('/\D+/', '', $s) ?? '';
    }

    public static function formatarCpf(string $cpf): string
    {
        $d = self::soDigitos($cpf);
        if (strlen($d) !== 11) return $cpf;
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }

    /* =================================================================
       Busca
       ================================================================= */

    /**
     * Busca clientes cujo CPF começa com os dígitos informados.
     * @return array<int,array> lista normalizada (nome, cpf, telefone, email, endereco{...})
     */
    public function buscarPorCpf(string $cpf, int $limite = 8): array
    {
        $dig = self::soDigitos($cpf);
        if (strlen($dig) < 3) return [];             // evita varrer a base com 1-2 dígitos
        $lim = max(1, min(20, $limite));

        // Tenta com endereço; se as colunas não existirem, cai para identidade.
        foreach ([true, false] as $comEndereco) {
            try {
                $st = $this->pdo->prepare($this->sql($comEndereco, $lim));
                $st->execute([':cpf' => $dig . '%']);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                return array_map(fn($r) => $this->normalizar($r, $comEndereco), $rows);
            } catch (\Throwable $e) {
                if ($comEndereco) continue;           // tenta de novo só com identidade
                LogService::error('Falha na busca de cliente por CPF', ['erro' => $e->getMessage()]);
                return [];
            }
        }
        return [];
    }

    /* =================================================================
       Internos
       ================================================================= */

    private function sql(bool $comEndereco, int $lim): string
    {
        $u = self::T_USUARIOS; $c = self::T_CLIENTES;
        $cols = [
            'c.' . self::C_CLI_ID . ' AS cliente_id',
            'u.' . self::C_USU_ID . ' AS usuario_id',
            'u.' . self::C_USU_NOME . ' AS nome',
            'u.' . self::C_USU_EMAIL . ' AS email',
            'c.' . self::C_CLI_CPF . ' AS cpf',
            'c.' . self::C_CLI_FONE . ' AS telefone',
        ];
        if ($comEndereco) {
            $cols[] = 'c.' . self::C_CLI_CEP . ' AS cep';
            $cols[] = 'c.' . self::C_CLI_LOGRADOURO . ' AS logradouro';
            $cols[] = 'c.' . self::C_CLI_NUMERO . ' AS numero';
            $cols[] = 'c.' . self::C_CLI_COMPLEMENTO . ' AS complemento';
            $cols[] = 'c.' . self::C_CLI_BAIRRO . ' AS bairro';
            $cols[] = 'c.' . self::C_CLI_CIDADE . ' AS cidade';
            $cols[] = 'c.' . self::C_CLI_UF . ' AS uf';
        }
        // Compara só dígitos (aceita CPF salvo com ou sem máscara).
        $cpfLimpo = "REPLACE(REPLACE(REPLACE(c." . self::C_CLI_CPF . ",'.',''),'-',''),' ','')";

        return 'SELECT ' . implode(', ', $cols)
            . " FROM {$c} c JOIN {$u} u ON u." . self::C_USU_ID . ' = c.' . self::C_CLI_USU
            . " WHERE {$cpfLimpo} LIKE :cpf"
            . ' ORDER BY u.' . self::C_USU_NOME . " ASC LIMIT {$lim}";
    }

    private function normalizar(array $r, bool $comEndereco): array
    {
        $out = [
            'cliente_id'    => isset($r['cliente_id']) ? (int)$r['cliente_id'] : null,
            'usuario_id'    => isset($r['usuario_id']) ? (int)$r['usuario_id'] : null,
            'nome'          => $r['nome'] ?? '',
            'cpf'           => self::soDigitos((string)($r['cpf'] ?? '')),
            'cpf_formatado' => self::formatarCpf((string)($r['cpf'] ?? '')),
            'email'         => $r['email'] ?? null,
            'telefone'      => $r['telefone'] ?? null,
        ];
        $out['endereco'] = $comEndereco ? [
            'cep'         => $r['cep'] ?? null,
            'logradouro'  => $r['logradouro'] ?? null,
            'numero'      => $r['numero'] ?? null,
            'complemento' => $r['complemento'] ?? null,
            'bairro'      => $r['bairro'] ?? null,
            'cidade'      => $r['cidade'] ?? null,
            'uf'          => $r['uf'] ?? null,
        ] : [];
        return $out;
    }
}
