<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/models/BiMeta.php
// ════════════════════════════════════════════════════════

/**
 * Metas comerciais do BI.
 *
 * Modelo genérico de propósito: uma linha é
 * (período × métrica × recorte). A mesma estrutura serve meta da
 * loja, do vendedor, da marca, da categoria e do canal — sem ALTER
 * quando aparecer o próximo recorte.
 */
class BiMeta {

    private PDO $db;

    /** Espelham o ENUM da tabela. Fonte única para validar e exibir. */
    public const METRICAS = [
        'faturamento'    => 'Faturamento',
        'pedidos'        => 'Pedidos',
        'ticket_medio'   => 'Ticket médio',
        'margem'         => 'Margem',
        'clientes'       => 'Clientes',
        'itens_vendidos' => 'Itens vendidos',
    ];

    public const DIMENSOES = [
        'loja'      => 'Loja inteira',
        'vendedor'  => 'Vendedor',
        'marca'     => 'Marca',
        'categoria' => 'Categoria',
        'canal'     => 'Canal',
    ];

    public const GRANULARIDADES = [
        'dia' => 'Diária', 'semana' => 'Semanal', 'mes' => 'Mensal',
        'trimestre' => 'Trimestral', 'ano' => 'Anual',
    ];

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function listar(array $filtros = []): array {
        $where  = [];
        $params = [];

        if (!empty($filtros['metrica'])) {
            $where[] = 'metrica = ?';
            $params[] = $filtros['metrica'];
        }
        if (!empty($filtros['dimensao'])) {
            $where[] = 'dimensao = ?';
            $params[] = $filtros['dimensao'];
        }
        if (!empty($filtros['ano'])) {
            $where[] = 'YEAR(periodo_ini) = ?';
            $params[] = (int)$filtros['ano'];
        }

        $sql = "SELECT * FROM bi_metas";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY periodo_ini DESC, metrica ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM bi_metas WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Cria ou atualiza. Devolve ['ok'=>bool,'msg'=>string,'id'=>?int].
     *
     * Valida tudo aqui e não confia no ENUM para barrar: um valor
     * fora da lista viraria erro 500 de SQL em vez de mensagem útil.
     */
    public function salvar(array $d, ?int $id = null): array {
        $metrica  = (string)($d['metrica']  ?? '');
        $dimensao = (string)($d['dimensao'] ?? 'loja');
        $gran     = (string)($d['granularidade'] ?? 'mes');

        if (!isset(self::METRICAS[$metrica]))       return ['ok'=>false,'msg'=>'Métrica inválida.'];
        if (!isset(self::DIMENSOES[$dimensao]))     return ['ok'=>false,'msg'=>'Dimensão inválida.'];
        if (!isset(self::GRANULARIDADES[$gran]))    return ['ok'=>false,'msg'=>'Granularidade inválida.'];

        $ini = $d['periodo_ini'] ?? '';
        $fim = $d['periodo_fim'] ?? '';
        if (!self::dataValida($ini) || !self::dataValida($fim)) {
            return ['ok'=>false,'msg'=>'Informe um período válido.'];
        }
        if ($fim < $ini) {
            return ['ok'=>false,'msg'=>'O fim do período não pode ser antes do início.'];
        }

        $valor = (float)str_replace(',', '.', (string)($d['valor_meta'] ?? '0'));
        if ($valor <= 0) {
            return ['ok'=>false,'msg'=>'A meta precisa ser maior que zero.'];
        }

        // O alvo vai em coluna diferente conforme a dimensão: canal é
        // string ('site','app'), as outras têm id numérico. 'loja' não
        // tem alvo nenhum.
        $dimId  = null;
        $dimVal = null;

        if ($dimensao === 'canal') {
            $dimVal = trim((string)($d['dimensao_id'] ?? ''));
            if ($dimVal === '') {
                return ['ok'=>false,'msg'=>'Escolha o canal da meta.'];
            }
            $dimVal = mb_substr($dimVal, 0, 60);
        } elseif ($dimensao !== 'loja') {
            $dimId = !empty($d['dimensao_id']) ? (int)$d['dimensao_id'] : null;
            if ($dimId === null) {
                return ['ok'=>false,'msg'=>'Escolha o alvo da meta (' . self::DIMENSOES[$dimensao] . ').'];
            }
        }

        $obs = isset($d['observacao']) && $d['observacao'] !== ''
             ? mb_substr((string)$d['observacao'], 0, 255) : null;

        try {
            if ($id) {
                $this->db->prepare(
                    "UPDATE bi_metas SET periodo_ini=?, periodo_fim=?, granularidade=?,
                            metrica=?, dimensao=?, dimensao_id=?, dimensao_valor=?,
                            valor_meta=?, observacao=?
                      WHERE id=?"
                )->execute([$ini,$fim,$gran,$metrica,$dimensao,$dimId,$dimVal,$valor,$obs,$id]);
                return ['ok'=>true,'msg'=>'Meta atualizada.','id'=>$id];
            }

            $this->db->prepare(
                "INSERT INTO bi_metas
                 (periodo_ini,periodo_fim,granularidade,metrica,dimensao,dimensao_id,
                  dimensao_valor,valor_meta,observacao,criado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            )->execute([$ini,$fim,$gran,$metrica,$dimensao,$dimId,$dimVal,$valor,$obs,
                        AuthHelper::usuarioId() ?: null]);

            return ['ok'=>true,'msg'=>'Meta criada.','id'=>(int)$this->db->lastInsertId()];

        } catch (\PDOException $e) {
            // 1062 = a UNIQUE (periodo × métrica × dimensão × alvo).
            // Duas metas para o mesmo recorte no mesmo período tornariam
            // "% atingido" ambíguo — qual das duas seria a verdade?
            if ((int)$e->errorInfo[1] === 1062) {
                return ['ok'=>false,'msg'=>'Já existe uma meta desta métrica para este período e recorte.'];
            }
            error_log('[BiMeta] salvar: ' . $e->getMessage());
            return ['ok'=>false,'msg'=>'Erro ao salvar a meta.'];
        }
    }

    public function excluir(int $id): bool {
        return $this->db->prepare("DELETE FROM bi_metas WHERE id = ?")->execute([$id]);
    }

    /**
     * Opções de alvo por dimensão, para o combo dependente do admin.
     *
     * Canais saem de `pedidos` (os que REALMENTE existem) em vez de
     * uma lista fixa: meta para canal que nunca vendeu nasce órfã e
     * nunca é atingida.
     */
    public function alvos(): array {
        return [
            'vendedor'  => $this->db->query(
                "SELECT id, nome FROM vendedores WHERE ativo = 1 ORDER BY nome"
            )->fetchAll(),
            'marca'     => $this->db->query(
                "SELECT id, nome FROM marcas WHERE ativo = 1 ORDER BY nome"
            )->fetchAll(),
            'categoria' => $this->db->query(
                "SELECT id, nome FROM categorias WHERE ativo = 1 ORDER BY nome"
            )->fetchAll(),
            'canal'     => $this->db->query(
                "SELECT DISTINCT canal AS id, canal AS nome
                   FROM pedidos WHERE canal <> '' ORDER BY canal"
            )->fetchAll(),
        ];
    }

    private static function dataValida(string $d): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}
