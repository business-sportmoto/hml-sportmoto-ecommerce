<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/BlingContatoService.php
//
// Sincroniza CLIENTES da loja como CONTATOS no Bling, via FILA.
//
// Três gatilhos, uma fonte de verdade:
//   1. Botão individual (admin)  → sincronizarCliente() direto,
//      SÍNCRONO, ignora o teto (admin forçou, quer resultado já)
//   2. Pós-ativação da conta     → enfileirarPorUsuario() marca
//      'pendente'; o cron drena. Bling fora NÃO trava o login.
//   3. Botão "sincronizar todos"  → enfileirarTodos() marca em
//      lote; o cron drena. Não sincroniza inline (evita timeout
//      e estouro de rate limit numa request web).
//
// TETO DE 3 TENTATIVAS: enforçado no SELETOR da fila (idsNaFila),
// não dentro de sincronizarCliente. Assim o cron para de bater
// num cliente quebrado, mas o admin ainda força pelo botão.
//
// Arquitetura: serviço DEDICADO — não toca o BlingOrderService
// (fluxo de pedido, produção, caminho de dinheiro). Trade-off:
// a busca CPF→e-mail fica duplicada por ora; unificar depois.
//
// ⚠ TELEFONE opcional. Se o Bling exigir para criar contato
// (não confirmado), a criação falha com o erro do Bling — que
// vira bling_sync_erro e aparece no painel.
// ════════════════════════════════════════════════════════

final class BlingContatoService {

    private const MAX_TENTATIVAS = 3;

    private BlingApiClient $api;
    private PDO $db;

    public function __construct() {
        $this->api = new BlingApiClient();
        $this->db  = Database::getInstance()->getConnection();
    }

    // ══════════════════════════════════════════════════
    // ENFILEIRAMENTO (não chama o Bling — só marca estado)
    // ══════════════════════════════════════════════════

    /**
     * Enfileira o cliente de um usuário recém-ativado.
     * Chamado no ponto de ativação (validateLoginCode / verifyEmail).
     * Idempotente e barato: um UPDATE, sem I/O externo, não trava
     * o login mesmo com o Bling fora.
     *
     * WHERE bling_id IS NULL: quem já está no Bling não é re-enfileirado.
     */
    public function enfileirarPorUsuario(int $usuarioId): void {
        $this->db->prepare(
            "UPDATE clientes
             SET bling_sync_status = 'pendente',
                 bling_sync_tentativas = 0,
                 bling_sync_erro = NULL
             WHERE usuario_id = ? AND bling_id IS NULL"
        )->execute([$usuarioId]);
    }

    /**
     * Enfileira TODOS os clientes elegíveis ainda não sincronizados.
     * Usado pelo botão "sincronizar todos". Retorna quantos entraram
     * na fila. O cron é quem processa — a resposta é imediata.
     *
     * @return int clientes enfileirados
     */
    public function enfileirarTodos(): int {
        $stmt = $this->db->prepare(
            "UPDATE clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             SET c.bling_sync_status = 'pendente',
                 c.bling_sync_tentativas = 0,
                 c.bling_sync_erro = NULL
             WHERE u.email_verificado = 1
               AND c.bling_id IS NULL
               AND (c.cpf IS NOT NULL OR u.email IS NOT NULL)"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ══════════════════════════════════════════════════
    // PROCESSAMENTO DA FILA (cron)
    // ══════════════════════════════════════════════════

    /**
     * Drena a fila: pega 'pendente' abaixo do teto e sincroniza.
     * Respiro entre chamadas para não estourar o rate limit do Bling.
     *
     * @return array{total:int, ok:int, falhas:int, detalhes:array}
     */
    public function processarFila(int $limite = 50): array {
        $ids = $this->idsNaFila($limite);

        $ok = 0; $falhas = 0; $detalhes = [];
        foreach ($ids as $id) {
            $r = $this->sincronizarCliente($id);
            if ($r['ok']) {
                $ok++;
            } else {
                $falhas++;
                $detalhes[] = ['cliente_id' => $id, 'msg' => $r['msg']];
            }
            usleep(300000); // 0,3s entre chamadas
        }

        return ['total' => count($ids), 'ok' => $ok,
                'falhas' => $falhas, 'detalhes' => $detalhes];
    }

    /** Clientes 'pendente' abaixo do teto de tentativas. */
    private function idsNaFila(int $limite): array {
        $stmt = $this->db->prepare(
            "SELECT c.id
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.bling_sync_status = 'pendente'
               AND c.bling_sync_tentativas < ?
               AND u.email_verificado = 1
             ORDER BY c.bling_sync_tentativas ASC, c.id ASC
             LIMIT ?"
        );
        $stmt->execute([self::MAX_TENTATIVAS, $limite]);
        return array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }

    // ══════════════════════════════════════════════════
    // SINCRONIZAÇÃO (worker — SÍNCRONA)
    // ══════════════════════════════════════════════════

    /**
     * Sincroniza UM cliente AGORA. Usada pelo botão individual
     * (força, ignora o teto) e pelo processarFila.
     *
     * O gate "só verificado" mora aqui: manual, automático e lote
     * respeitam a mesma regra de não poluir o Bling.
     *
     * @return array{ok:bool, bling_id?:string, msg:string}
     */
    public function sincronizarCliente(int $clienteId): array {
        $c = $this->carregarDadosCliente($clienteId);

        if (!$c) {
            return ['ok' => false, 'msg' => 'Cliente não encontrado.'];
        }
        if (empty($c['email_verificado'])) {
            // Não elegível — não penaliza tentativa, só recusa
            return ['ok' => false,
                    'msg' => 'Cliente ainda não ativou a conta.'];
        }
        if (empty($c['cpf']) && empty($c['email'])) {
            // Falha determinística (nunca vai passar) — conta p/ teto
            $this->registrarFalha($clienteId, 'Sem CPF nem e-mail.');
            return ['ok' => false, 'msg' => 'Cliente sem CPF nem e-mail.'];
        }

        try {
            $contatoId = $this->upsertContato($c);
        } catch (\Throwable $e) {
            $this->registrarFalha($clienteId, $e->getMessage());
            return ['ok' => false, 'msg' => 'Bling recusou: ' . $e->getMessage()];
        }

        $this->registrarSucesso($clienteId, $contatoId);
        return ['ok' => true, 'bling_id' => $contatoId,
                'msg' => 'Sincronizado (contato ' . $contatoId . ').'];
    }

    private function registrarSucesso(int $clienteId, string $contatoId): void {
        $this->db->prepare(
            "UPDATE clientes
             SET bling_id = ?, bling_sincronizado_em = NOW(),
                 bling_sync_status = 'sincronizado', bling_sync_erro = NULL
             WHERE id = ?"
        )->execute([$contatoId, $clienteId]);
    }

    /**
     * Incrementa tentativa e congela em 'erro' ao atingir o teto.
     * MySQL avalia o + 1 sobre o valor ANTIGO da coluna, então o
     * IF já enxerga o novo total. Congelar faz o cron parar; o
     * admin ainda força pelo botão (chama sincronizarCliente direto).
     */
    private function registrarFalha(int $clienteId, string $msg): void {
        $this->db->prepare(
            "UPDATE clientes
             SET bling_sync_tentativas = bling_sync_tentativas + 1,
                 bling_sync_erro = ?,
                 bling_sync_status = IF(bling_sync_tentativas + 1 >= ?, 'erro', 'pendente')
             WHERE id = ?"
        )->execute([mb_substr($msg, 0, 500), self::MAX_TENTATIVAS, $clienteId]);
    }

    // ══════════════════════════════════════════════════
    // INTERNOS
    // ══════════════════════════════════════════════════

    private function carregarDadosCliente(int $clienteId): ?array {
        $stmt = $this->db->prepare(
            "SELECT c.id, c.cpf, c.telefone, c.celular, c.bling_id,
                    u.nome, u.email, u.email_verificado
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$clienteId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Busca contato (CPF → e-mail) e cria/atualiza. Espelha a lógica
     * validada do BlingOrderService::upsertContato, para CLIENTE.
     * Telefone OPCIONAL.
     *
     * @return string ID do contato no Bling
     */
    private function upsertContato(array $c): string {
        $cpfLimpo = preg_replace('/\D/', '', (string)($c['cpf'] ?? ''));
        $email    = strtolower(trim((string)($c['email'] ?? '')));

        $contatoId = null;

        // 1. Busca por CPF
        if ($cpfLimpo) {
            try {
                $lista = $this->normalizarListaBling(
                    $this->api->get('/contatos', ['pesquisa' => $cpfLimpo])
                );
                foreach ((array)$lista as $ct) {
                    $cpfBling = preg_replace('/\D+/', '',
                        $ct['numeroDocumento'] ?? $ct['cpfCnpj'] ?? $ct['cpf'] ?? '');
                    if ($cpfBling === $cpfLimpo) { $contatoId = (string)$ct['id']; break; }
                }
            } catch (\Throwable $e) {
                LogService::error('[BlingContato] busca CPF: ' . $e->getMessage());
            }
        }

        // 2. Fallback por e-mail
        if (!$contatoId && $email) {
            try {
                $lista = $this->normalizarListaBling(
                    $this->api->get('/contatos', ['pesquisa' => $email])
                );
                foreach ((array)$lista as $ct) {
                    if (strtolower($ct['email'] ?? '') === $email) {
                        $contatoId = (string)$ct['id']; break;
                    }
                }
            } catch (\Throwable $e) {
                LogService::error('[BlingContato] busca e-mail: ' . $e->getMessage());
            }
        }

        // Payload — nome obrigatório, fallback em cascata
        $nome = trim((string)($c['nome'] ?? ''));
        if (!$nome && $email) $nome = explode('@', $email)[0];
        if (!$nome)           $nome = 'Cliente ' . ($cpfLimpo ?: $email);

        $dados = [
            'nome'     => $nome,
            'tipo'     => 'F',
            'situacao' => 'A',
        ];
        if ($cpfLimpo) $dados['cpfCnpj'] = $cpfLimpo;
        if ($email)    $dados['email']   = $c['email'];

        $tel = preg_replace('/\D/', '', (string)($c['celular'] ?? $c['telefone'] ?? ''));

        if ($contatoId) {
            // Atualização de contato EXISTENTE não exige telefone
            if ($tel) $dados['telefone'] = $tel;
            try { $this->api->put("/contatos/{$contatoId}", $dados); }
            catch (\Throwable $e) { error_log('[BlingContato] update: ' . $e->getMessage()); }
            return $contatoId;
        }

        // CRIAÇÃO: o Bling EXIGE telefone (VALIDATION_ERROR campo 'fone').
        // Sem telefone, bloqueia com mensagem específica — não gasta a
        // chamada e o registrarFalha grava em bling_sync_erro pra cobrar.
        if (!$tel) {
            throw new \RuntimeException('Cliente sem telefone cadastrado — o Bling exige telefone para criar o contato.');
        }
        $dados['telefone'] = $tel;

        $resp   = $this->api->post('/contatos', $dados);
        $novoId = (string)($resp['data']['id'] ?? $resp['id'] ?? '');
        if (!$novoId) {
            throw new \RuntimeException('Bling não retornou ID do contato. Resposta: ' . json_encode($resp));
        }
        return $novoId;
    }

    private function normalizarListaBling($resultado): array {
        if (!is_array($resultado)) return [];
        $lista = $resultado['data'] ?? $resultado;
        while (is_array($lista) && count($lista) === 1 && isset($lista[0])
               && is_array($lista[0]) && array_is_list($lista[0])) {
            $lista = $lista[0];
        }
        return is_array($lista) ? $lista : [];
    }


    /**
     * Cria/atualiza o contato de um usuário (por usuario_id).
     * Usado no gatilho pós-ativação, que tem usuario_id, não cliente_id.
     *
     * @return array{ok:bool, bling_id?:string, msg:string}
     */
    public function sincronizarPorUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM clientes WHERE usuario_id = ? LIMIT 1"
        );
        $stmt->execute([$usuarioId]);
        $clienteId = (int)$stmt->fetchColumn();

        if ($clienteId <= 0) {
            return ['ok' => false, 'msg' => 'Cliente não encontrado para o usuário.'];
        }
        return $this->sincronizarCliente($clienteId);
    }
}