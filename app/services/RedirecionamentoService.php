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
        $stmt  = $this->db->prepare(
            "SELECT id, origem, destino, tipo, ativo
               FROM redirecionamentos
              WHERE origem_hash = ? AND ativo = 1
              LIMIT 1"
        );

        $primeiro = null;
        $vistos   = [];

        for ($i = 0; $i < self::MAX_SALTOS; $i++) {
            $stmt->execute([sha1($atual)]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
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

        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'msg' => 'Tipo inválido. Use 301, 302 ou 410.'];
        }
        if ($origem === '' || $origem === '/') {
            return ['ok' => false, 'msg' => 'Informe o endereço de origem (não pode ser a home).'];
        }

        $destino = trim((string) ($dados['destino'] ?? ''));

        if ($tipo === 410) {
            // "Essa página acabou" não leva a lugar nenhum.
            $destino = '';
        } else {
            $erro = $this->validarDestino($destino);
            if ($erro !== '') return ['ok' => false, 'msg' => $erro];

            $destino = preg_match('#^https?://#i', $destino)
                ? $destino
                : self::normalizar($destino);

            if ($destino === $origem) {
                return ['ok' => false, 'msg' => 'A origem e o destino são o mesmo endereço.'];
            }
        }

        $aviso = '';
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
                            motivo = ?, observacao = ?, ativo = ?
                      WHERE id = ?"
                )->execute([$origem, sha1($origem), $destino, $tipo, $motivo, $obs, $ativo, $id]);
            } else {
                $this->db->prepare(
                    "INSERT INTO redirecionamentos
                     (origem, origem_hash, destino, tipo, motivo, observacao, ativo, criado_por)
                     VALUES (?,?,?,?,?,?,?,?)"
                )->execute([
                    $origem, sha1($origem), $destino, $tipo, $motivo, $obs, $ativo,
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

        return ['ok' => true, 'msg' => 'Redirecionamento salvo.', 'id' => $id, 'aviso' => $aviso];
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
