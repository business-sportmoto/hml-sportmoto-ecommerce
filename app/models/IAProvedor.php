<?php
/**
 * IAProvedor — acesso a ia_provedores.
 *
 * Regras de segurança:
 *  - api_key_enc NUNCA aparece em listagens; buscar() expõe apenas o flag
 *    tem_chave e o api_key_last4.
 *  - chaveDecifrada() é de uso interno do backend (adapters da Fase 1);
 *    jamais enviar o retorno em resposta AJAX.
 */
class IAProvedor
{
    private PDO $db;

    /** Campos permitidos em atualizar() — allowlist. */
    private const CAMPOS_EDITAVEIS = ['nome', 'base_url', 'limite_diario_usd', 'timeout_padrao_s', 'ativo'];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Lista provedores com contagem de modelos ativos (sem a chave). */
    public function listar(): array
    {
        try {
            $sql = "SELECT p.id, p.codigo, p.nome, p.base_url, p.api_key_last4,
                           (p.api_key_enc IS NOT NULL) AS tem_chave,
                           p.ativo, p.limite_diario_usd, p.timeout_padrao_s, p.atualizado_em,
                           (SELECT COUNT(*) FROM ia_modelos m
                             WHERE m.provedor_id = p.id AND m.ativo = 1) AS modelos_ativos
                      FROM ia_provedores p
                  ORDER BY p.nome ASC";

            $linhas = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            return $linhas ?: [];
        } catch (Throwable $e) {
            LogService::error('ia_provedor_listar_erro', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /** Busca um provedor por id (sem o blob da chave). */
    public function buscar(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT p.id, p.codigo, p.nome, p.base_url, p.api_key_last4,
                        (p.api_key_enc IS NOT NULL) AS tem_chave,
                        p.ativo, p.limite_diario_usd, p.timeout_padrao_s
                   FROM ia_provedores p
                  WHERE p.id = :id
                  LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            $linha = $stmt->fetch(PDO::FETCH_ASSOC);
            return $linha ?: null;
        } catch (Throwable $e) {
            LogService::error('ia_provedor_buscar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }

    /** Atualiza campos permitidos (allowlist). */
    public function atualizar(int $id, array $dados): bool
    {
        $set    = [];
        $params = [':id' => $id];

        foreach (self::CAMPOS_EDITAVEIS as $campo) {
            if (array_key_exists($campo, $dados)) {
                $set[] = "`{$campo}` = :{$campo}";
                $params[":{$campo}"] = $dados[$campo];
            }
        }

        if (empty($set)) {
            return true; // nada a alterar
        }

        try {
            $sql  = 'UPDATE ia_provedores SET ' . implode(', ', $set) . ' WHERE id = :id LIMIT 1';
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (Throwable $e) {
            LogService::error('ia_provedor_atualizar_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Cifra e grava a chave de API. O texto claro não é logado em hipótese alguma.
     */
    public function definirChave(int $id, string $chaveClara): bool
    {
        try {
            $enc   = IACriptoService::cifrar($chaveClara);
            $last4 = IACriptoService::last4($chaveClara);

            $stmt = $this->db->prepare(
                'UPDATE ia_provedores
                    SET api_key_enc = :enc, api_key_last4 = :l4
                  WHERE id = :id
                  LIMIT 1'
            );
            return $stmt->execute([':enc' => $enc, ':l4' => $last4, ':id' => $id]);
        } catch (Throwable $e) {
            LogService::error('ia_provedor_definir_chave_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Chave em texto claro — SOMENTE para os adapters no backend (Fase 1).
     * Nunca retornar este valor em JSON/HTML.
     */
    public function chaveDecifrada(int $id): ?string
    {
        try {
            $stmt = $this->db->prepare('SELECT api_key_enc FROM ia_provedores WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
            $blob = $stmt->fetchColumn();
            return $blob ? IACriptoService::decifrar((string) $blob) : null;
        } catch (Throwable $e) {
            LogService::error('ia_provedor_chave_erro', ['id' => $id, 'erro' => $e->getMessage()]);
            return null;
        }
    }
}
