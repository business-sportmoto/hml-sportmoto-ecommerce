<?php
/**
 * app/services/ChatTemplateService.php
 *
 * Espelho local dos templates HSM aprovados na Meta.
 *
 * POR QUE ESPELHAR: o construtor de fluxo e o formulário de campanha precisam
 * saber quantas variáveis o template tem, se o header é de mídia e se ele está
 * aprovado — em cada abertura de tela. Bater na Graph API toda vez é lento e
 * consome quota. Sincronizamos sob demanda e servimos do banco.
 */
class ChatTemplateService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    // =========================================================================
    // SINCRONIZAÇÃO
    // =========================================================================

    /** @return array{ok:bool, total:int, novos:int, erro?:string} */
    public function sincronizar(): array
    {
        try {
            $cliente = new ChatMetaClient();
            $lista   = $cliente->listarTemplates();
        } catch (Throwable $e) {
            return ['ok' => false, 'total' => 0, 'novos' => 0, 'erro' => $e->getMessage()];
        }

        $novos = 0;
        $vistos = [];

        foreach ($lista as $t) {
            $nome   = (string)($t['name'] ?? '');
            $idioma = (string)($t['language'] ?? 'pt_BR');
            if ($nome === '') continue;

            $analise = $this->analisarComponentes((array)($t['components'] ?? []));
            $vistos[] = $nome . '|' . $idioma;

            $st = $this->db->prepare(
                "INSERT INTO chat_templates
                    (meta_id, nome, idioma, categoria, status, componentes_json,
                     vars_body, vars_header, header_tipo, botoes_url, corpo_preview, sincronizado_em)
                 VALUES (:mid, :n, :i, :cat, :s, :cj, :vb, :vh, :ht, :bu, :cp, NOW())
                 ON DUPLICATE KEY UPDATE
                    meta_id = VALUES(meta_id), categoria = VALUES(categoria),
                    status = VALUES(status), componentes_json = VALUES(componentes_json),
                    vars_body = VALUES(vars_body), vars_header = VALUES(vars_header),
                    header_tipo = VALUES(header_tipo), botoes_url = VALUES(botoes_url),
                    corpo_preview = VALUES(corpo_preview), sincronizado_em = NOW()"
            );
            $st->execute([
                ':mid' => isset($t['id']) ? (string)$t['id'] : null,
                ':n'   => mb_substr($nome, 0, 120),
                ':i'   => mb_substr($idioma, 0, 12),
                ':cat' => isset($t['category']) ? mb_substr((string)$t['category'], 0, 30) : null,
                ':s'   => mb_substr((string)($t['status'] ?? 'PENDING'), 0, 24),
                ':cj'  => json_encode($t['components'] ?? [], JSON_UNESCAPED_UNICODE),
                ':vb'  => $analise['vars_body'],
                ':vh'  => $analise['vars_header'],
                ':ht'  => $analise['header_tipo'],
                ':bu'  => $analise['botoes_url'],
                ':cp'  => $analise['corpo'],
            ]);
            if ($st->rowCount() === 1) $novos++;   // 1 = insert, 2 = update
        }

        // Some da Meta = sumiu daqui. Marcar em vez de apagar preserva o
        // histórico de campanhas que apontam para o template.
        if ($vistos) {
            $ph = implode(',', array_fill(0, count($vistos), '?'));
            $st = $this->db->prepare(
                "UPDATE chat_templates SET status = 'REMOVIDO'
                 WHERE CONCAT(nome, '|', idioma) NOT IN ($ph)"
            );
            $st->execute($vistos);
        }

        return ['ok' => true, 'total' => count($lista), 'novos' => $novos];
    }

    /**
     * Lê os componentes e extrai o que a UI precisa saber.
     * @return array{vars_body:int, vars_header:int, header_tipo:?string, botoes_url:int, corpo:string}
     */
    private function analisarComponentes(array $componentes): array
    {
        $out = ['vars_body' => 0, 'vars_header' => 0, 'header_tipo' => null, 'botoes_url' => 0, 'corpo' => ''];

        foreach ($componentes as $c) {
            $tipo = strtoupper((string)($c['type'] ?? ''));

            if ($tipo === 'BODY') {
                $texto = (string)($c['text'] ?? '');
                $out['corpo']     = mb_substr($texto, 0, 2000);
                $out['vars_body'] = $this->contarVariaveis($texto);
            } elseif ($tipo === 'HEADER') {
                $formato = strtoupper((string)($c['format'] ?? 'TEXT'));
                $out['header_tipo'] = $formato;
                if ($formato === 'TEXT') {
                    $out['vars_header'] = $this->contarVariaveis((string)($c['text'] ?? ''));
                } else {
                    $out['vars_header'] = 1;   // mídia é sempre 1 parâmetro
                }
            } elseif ($tipo === 'BUTTONS') {
                foreach (($c['buttons'] ?? []) as $b) {
                    if (strtoupper((string)($b['type'] ?? '')) === 'URL'
                        && $this->contarVariaveis((string)($b['url'] ?? '')) > 0) {
                        $out['botoes_url']++;
                    }
                }
            }
        }
        return $out;
    }

    /** Maior índice de {{n}} — é isso que define quantos params a Meta espera. */
    private function contarVariaveis(string $texto): int
    {
        if (!preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $texto, $m)) return 0;
        return (int)max(array_map('intval', $m[1]));
    }

    // =========================================================================
    // LEITURA
    // =========================================================================

    public function listar(bool $soAprovados = false): array
    {
        $where = $soAprovados ? "WHERE status = 'APPROVED'" : '';
        return $this->db->query(
            "SELECT * FROM chat_templates $where ORDER BY status = 'APPROVED' DESC, nome"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function aprovados(): array
    {
        return $this->listar(true);
    }

    public function obter(string $nome, string $idioma = 'pt_BR'): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM chat_templates WHERE nome = :n AND idioma = :i LIMIT 1"
        );
        $st->execute([':n' => $nome, ':i' => $idioma]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Monta os componentes da Meta a partir do mapeamento salvo na campanha.
     *
     * @param array $mapa ['body'=>['{{nome}}','SM-01'], 'header'=>'...', 'botao'=>'...']
     * @param array $vars variáveis do contato para interpolar
     */
    public function montarComponentes(array $mapa, array $vars): array
    {
        $componentes = [];

        $header = trim((string)($mapa['header'] ?? ''));
        if ($header !== '') {
            $componentes[] = [
                'type'       => 'header',
                'parameters' => [['type' => 'text', 'text' => ChatContatoService::interpolar($header, $vars)]],
            ];
        }

        $body = array_values((array)($mapa['body'] ?? []));
        if ($body) {
            $componentes[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn($v) => ['type' => 'text', 'text' => ChatContatoService::interpolar((string)$v, $vars)],
                    $body
                ),
            ];
        }

        $botao = trim((string)($mapa['botao'] ?? ''));
        if ($botao !== '') {
            $componentes[] = [
                'type' => 'button', 'sub_type' => 'url', 'index' => '0',
                'parameters' => [['type' => 'text', 'text' => ChatContatoService::interpolar($botao, $vars)]],
            ];
        }

        return $componentes;
    }

    /** Preview com as variáveis já substituídas — o que o cliente vai ler. */
    public function preview(string $nome, string $idioma, array $mapa, array $vars = []): string
    {
        $tpl = $this->obter($nome, $idioma);
        if (!$tpl) return '';

        $texto = (string)$tpl['corpo_preview'];
        $body  = array_values((array)($mapa['body'] ?? []));

        return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($m) use ($body, $vars) {
            $i = (int)$m[1] - 1;
            $v = $body[$i] ?? $m[0];
            return ChatContatoService::interpolar((string)$v, $vars);
        }, $texto) ?? $texto;
    }
}
