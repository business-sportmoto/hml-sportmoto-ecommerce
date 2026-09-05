<?php
declare(strict_types=1);

/**
 * IAAgente — o catálogo de agentes de BI (ia_agentes ⋈ ia_tipos_conteudo).
 *
 * Um agente é uma linha aqui MAIS a linha do tipo de conteúdo: persona,
 * ferramentas, modelo e max_tokens vivem em ia_tipos_conteudo (é de lá que
 * o orquestrador lê); nome de exibição, páginas, sugestões, perguntas e a
 * rodada agendada vivem aqui. Este model devolve as duas juntas.
 */
class IAAgente
{
    public const EFFORTS = ['low', 'medium', 'high', 'xhigh'];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    private const SELECT = "
        SELECT a.*,
               t.nome              AS tipo_nome,
               t.instrucoes_sistema,
               t.ferramentas,
               t.modelo_id,
               t.max_tokens,
               t.ativo             AS tipo_ativo,
               m.codigo_modelo,
               m.nome              AS modelo_nome
          FROM ia_agentes a
          JOIN ia_tipos_conteudo t ON t.id = a.tipo_conteudo_id
          LEFT JOIN ia_modelos m   ON m.id = t.modelo_id";

    /** Todos, ativos primeiro, na ordem. Decodifica os JSON. */
    public function listar(): array
    {
        $rows = $this->db->query(self::SELECT . ' ORDER BY a.ativo DESC, a.ordem ASC, a.id ASC')
                         ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'decodificar'], $rows);
    }

    /** Só os ativos (o que o painel usa). */
    public function listarAtivos(): array
    {
        $rows = $this->db->query(self::SELECT . ' WHERE a.ativo = 1 AND t.ativo = 1 ORDER BY a.ordem ASC, a.id ASC')
                         ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'decodificar'], $rows);
    }

    public function buscar(int $id): ?array
    {
        $st = $this->db->prepare(self::SELECT . ' WHERE a.id = :id LIMIT 1');
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->decodificar($r) : null;
    }

    public function buscarPorCodigo(string $codigo): ?array
    {
        $st = $this->db->prepare(self::SELECT . ' WHERE a.codigo = :c LIMIT 1');
        $st->execute([':c' => $codigo]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->decodificar($r) : null;
    }

    /**
     * Cria o tipo de conteúdo E o agente, numa transação.
     * $d: codigo, nome_exibicao, rotulo_curto, descricao, instrucoes_sistema,
     *     ferramentas[], modelo_id(?), max_tokens, effort, paginas[],
     *     sugestoes[], perguntas[], agendado_ativo, pergunta_agendada,
     *     pagina_agendada, ordem, ativo, criado_por
     * @return int id do agente
     */
    public function criar(array $d): int
    {
        // Transação própria só quando ninguém abriu uma antes: chamado de
        // dentro de outra (testes, importadores) participa dela.
        $proprio = !$this->db->inTransaction();
        if ($proprio) $this->db->beginTransaction();
        try {
            $tipoId = (new IATipoConteudo())->criar([
                'codigo'             => $d['codigo'],
                'nome'               => $d['nome_exibicao'] . ' (BI)',
                'grupo'              => 'sistema',
                'capacidade'         => 'agente',
                'saida'              => 'texto',
                'modelo_id'          => $d['modelo_id'] ?? null,
                'instrucoes_sistema' => $d['instrucoes_sistema'],
                'ferramentas'        => $d['ferramentas'],
                'max_tokens'         => (int) $d['max_tokens'],
                'ordem'              => 900 + (int) ($d['ordem'] ?? 0),
                'ativo'              => (int) ($d['ativo'] ?? 1),
            ]);

            $st = $this->db->prepare(
                'INSERT INTO ia_agentes
                    (tipo_conteudo_id, codigo, nome_exibicao, rotulo_curto, descricao, paginas, sugestoes, perguntas,
                     effort, agendado_ativo, pergunta_agendada, pagina_agendada, ordem, ativo, criado_por)
                 VALUES
                    (:tipo, :codigo, :nome, :curto, :descricao, :paginas, :sugestoes, :perguntas,
                     :effort, :ag_ativo, :ag_pergunta, :ag_pagina, :ordem, :ativo, :criado_por)'
            );
            $st->execute([
                ':tipo'        => $tipoId,
                ':codigo'      => $d['codigo'],
                ':nome'        => $d['nome_exibicao'],
                ':curto'       => $d['rotulo_curto'],
                ':descricao'   => $d['descricao'] ?? null,
                ':paginas'     => $this->json($d['paginas'] ?? []),
                ':sugestoes'   => $this->json($d['sugestoes'] ?? []),
                ':perguntas'   => $this->json($d['perguntas'] ?? []),
                ':effort'      => in_array($d['effort'] ?? '', self::EFFORTS, true) ? $d['effort'] : 'medium',
                ':ag_ativo'    => (int) ($d['agendado_ativo'] ?? 1),
                ':ag_pergunta' => ($d['pergunta_agendada'] ?? '') !== '' ? $d['pergunta_agendada'] : null,
                ':ag_pagina'   => ($d['pagina_agendada'] ?? '') !== '' ? $d['pagina_agendada'] : null,
                ':ordem'       => (int) ($d['ordem'] ?? 0),
                ':ativo'       => (int) ($d['ativo'] ?? 1),
                ':criado_por'  => $d['criado_por'] ?? null,
            ]);
            $id = (int) $this->db->lastInsertId();
            if ($proprio) $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($proprio) $this->db->rollBack();
            throw $e;
        }
    }

    /** Atualiza as duas tabelas. `codigo` não muda: é a chave do painel e das conversas. */
    public function atualizar(int $id, array $d): bool
    {
        $atual = $this->buscar($id);
        if ($atual === null) return false;

        // Transação própria só quando ninguém abriu uma antes: chamado de
        // dentro de outra (testes, importadores) participa dela.
        $proprio = !$this->db->inTransaction();
        if ($proprio) $this->db->beginTransaction();
        try {
            (new IATipoConteudo())->atualizar((int) $atual['tipo_conteudo_id'], [
                'nome'               => $d['nome_exibicao'] . ' (BI)',
                'modelo_id'          => $d['modelo_id'] ?? null,
                'instrucoes_sistema' => $d['instrucoes_sistema'],
                'ferramentas'        => $d['ferramentas'],
                'max_tokens'         => (int) $d['max_tokens'],
                'ativo'              => (int) ($d['ativo'] ?? 1),
            ]);

            $st = $this->db->prepare(
                'UPDATE ia_agentes SET
                    nome_exibicao = :nome, rotulo_curto = :curto, descricao = :descricao,
                    paginas = :paginas, sugestoes = :sugestoes, perguntas = :perguntas,
                    effort = :effort, agendado_ativo = :ag_ativo, pergunta_agendada = :ag_pergunta,
                    pagina_agendada = :ag_pagina, ordem = :ordem, ativo = :ativo
                  WHERE id = :id LIMIT 1'
            );
            $st->execute([
                ':nome'        => $d['nome_exibicao'],
                ':curto'       => $d['rotulo_curto'],
                ':descricao'   => $d['descricao'] ?? null,
                ':paginas'     => $this->json($d['paginas'] ?? []),
                ':sugestoes'   => $this->json($d['sugestoes'] ?? []),
                ':perguntas'   => $this->json($d['perguntas'] ?? []),
                ':effort'      => in_array($d['effort'] ?? '', self::EFFORTS, true) ? $d['effort'] : 'medium',
                ':ag_ativo'    => (int) ($d['agendado_ativo'] ?? 1),
                ':ag_pergunta' => ($d['pergunta_agendada'] ?? '') !== '' ? $d['pergunta_agendada'] : null,
                ':ag_pagina'   => ($d['pagina_agendada'] ?? '') !== '' ? $d['pagina_agendada'] : null,
                ':ordem'       => (int) ($d['ordem'] ?? 0),
                ':ativo'       => (int) ($d['ativo'] ?? 1),
                ':id'          => $id,
            ]);
            if ($proprio) $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($proprio) $this->db->rollBack();
            throw $e;
        }
    }

    /** Liga/desliga o agente E o tipo — os dois têm que andar juntos. */
    public function alternar(int $id): ?int
    {
        $a = $this->buscar($id);
        if ($a === null) return null;
        $novo = (int) $a['ativo'] === 1 ? 0 : 1;
        // Transação própria só quando ninguém abriu uma antes: chamado de
        // dentro de outra (testes, importadores) participa dela.
        $proprio = !$this->db->inTransaction();
        if ($proprio) $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE ia_agentes SET ativo = :a WHERE id = :id')->execute([':a' => $novo, ':id' => $id]);
            $this->db->prepare('UPDATE ia_tipos_conteudo SET ativo = :a WHERE id = :id')
                     ->execute([':a' => $novo, ':id' => (int) $a['tipo_conteudo_id']]);
            if ($proprio) $this->db->commit();
            return $novo;
        } catch (Throwable $e) {
            if ($proprio) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Exclui o agente e o tipo. Só sem conversas: apagar um agente com
     * histórico apagaria a procedência de respostas que já foram lidas.
     * @return array{ok:bool, msg:string}
     */
    public function excluir(int $id): array
    {
        $a = $this->buscar($id);
        if ($a === null) return ['ok' => false, 'msg' => 'Agente não encontrado.'];

        $st = $this->db->prepare('SELECT COUNT(*) FROM ia_agente_conversas WHERE agente = :c');
        $st->execute([':c' => $a['codigo']]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            return ['ok' => false, 'msg' => "Este agente tem {$n} conversa(s) no histórico. Desative em vez de excluir."];
        }

        // O tipo tem FK das gerações: se houver geração, o DELETE do tipo falha
        // e a transação volta — o agente fica. É o comportamento certo.
        // Transação própria só quando ninguém abriu uma antes: chamado de
        // dentro de outra (testes, importadores) participa dela.
        $proprio = !$this->db->inTransaction();
        if ($proprio) $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM ia_agentes WHERE id = :id')->execute([':id' => $id]);
            $this->db->prepare('DELETE FROM ia_tipos_conteudo WHERE id = :id')->execute([':id' => (int) $a['tipo_conteudo_id']]);
            if ($proprio) $this->db->commit();
            return ['ok' => true, 'msg' => 'Agente excluído.'];
        } catch (Throwable $e) {
            if ($proprio) $this->db->rollBack();
            return ['ok' => false, 'msg' => 'Este agente já gerou respostas; não pode ser excluído. Desative-o.'];
        }
    }

    /**
     * Páginas já atendidas por OUTRO agente ativo — uma página tem um só
     * botão, então um só agente.
     * @return array<string,string> pagina => codigo do agente que a tem
     */
    public function paginasOcupadas(?int $excetoId = null): array
    {
        $out = [];
        foreach ($this->listarAtivos() as $a) {
            if ($excetoId !== null && (int) $a['id'] === $excetoId) continue;
            foreach ($a['paginas'] as $p) $out[$p] = $a['codigo'];
        }
        return $out;
    }

    public function codigoExiste(string $codigo): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM ia_tipos_conteudo WHERE codigo = :c LIMIT 1');
        $st->execute([':c' => $codigo]);
        return (bool) $st->fetchColumn();
    }

    /* ------------------------------------------------------------------ */

    private function decodificar(array $r): array
    {
        foreach (['paginas', 'sugestoes', 'perguntas', 'ferramentas'] as $k) {
            $v = json_decode((string) ($r[$k] ?? ''), true);
            $r[$k] = is_array($v) ? $v : [];
        }
        $r['ativo']          = (int) $r['ativo'];
        $r['agendado_ativo'] = (int) $r['agendado_ativo'];
        return $r;
    }

    private function json(array $v): string
    {
        return json_encode(array_values($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
