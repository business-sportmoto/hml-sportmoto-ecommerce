<?php
/**
 * app/services/AutomacaoDispatchService.php
 *
 * Para cada item da fila:
 *   1. Verifica supressão (comprou? descadastrou?)
 *   2. Busca dados do cliente e contexto (produto, carrinho, etc.)
 *   3. Gera cupom se necessário
 *   4. Envia via EmailDispatchService ou via email_campanha_destinatarios
 *   5. Registra em automacao_historico
 */
class AutomacaoDispatchService
{
    /** @var PDO */
    private $db;
    /** @var AutomacaoModel */
    private $model;
    /** @var AutomacaoCupomService */
    private $cupomSvc;

    public function __construct()
    {
        $this->db       = Database::getInstance()->getConnection();
        $this->model    = new AutomacaoModel();
        $this->cupomSvc = new AutomacaoCupomService();
    }

    /**
     * Processa um item da fila.
     * Retorna 'enviado', 'suprimido' ou 'erro'.
     */
    public function processar(array $item): string
    {
        $clienteId = (int)$item['cliente_id'];
        $fluxoId   = (int)$item['fluxo_id'];
        $passoId   = (int)$item['passo_id'];
        $fluxoTipo = $item['fluxo_tipo'];
        $cfg       = json_decode($item['fluxo_config'] ?? '{}', true) ?: [];
        $contexto  = json_decode($item['contexto_json'] ?? '{}', true) ?: [];

        // 1. Verifica supressão
        $supressao = $this->verificarSupressao($clienteId, $fluxoTipo);
        if ($supressao) {
            $this->model->marcarEnviado((int)$item['id']); // cancela silenciosamente
            $this->model->registrarHistorico([
                'fila_id'    => $item['id'],
                'cliente_id' => $clienteId,
                'fluxo_id'   => $fluxoId,
                'passo_id'   => $passoId,
                'resultado'  => 'suprimido',
                'detalhe'    => $supressao,
            ]);
            return 'suprimido';
        }

        // 2. Dados do cliente
        $cliente = ($clienteId > 0) ? $this->buscarCliente($clienteId) : null;

        // LogService::error('erro no cliente = busca => '.);

        if (!$cliente) {
            // Visitante anônimo — tenta montar cliente com dados salvos no contexto
            $emailCtx = $contexto['_email'] ?? '';
            if (!$emailCtx) {
                $this->model->marcarErro((int)$item['id'], 'cliente não encontrado e sem email no contexto');
                LogService::error('erro no cliente', $item);
                return 'erro';
            }
            $cliente = [
                'id'         => 0,
                'nome'       => $contexto['_primeiro_nome'] ?? 'Cliente',
                'email'      => $emailCtx,
                'newsletter' => 1,    // pediu aviso explicitamente — pode enviar
                'nascimento' => null,
            ];
        }
        if (!$cliente['newsletter'] && !in_array($fluxoTipo, ['boas_vindas','aniversario'], true)) {
            $this->model->marcarEnviado((int)$item['id']);
            $this->registrar($item, 'suprimido', 'newsletter desativada');
            return 'suprimido';
        }

        // 3. Template
        $templateId = (int)($item['template_id'] ?? 0);
        if (!$templateId) {
            $this->model->marcarErro((int)$item['id'], 'template não configurado');
            return 'erro';
        }

        // 4. Cupom (se necessário)
        $cupomId     = null;
        $cupomCodigo = null;

        if ($fluxoTipo === 'aniversario' && (int)$item['passo_ordem'] === 2) {
            // No dia do aniversário: gera cupom APENAS se não usado ainda
            if (!$this->cupomSvc->aniversarioJaUsado($clienteId)) {
                $pct = (float)($cfg['cupom_pct'] ?? 10);
                $dias = (int)($cfg['cupom_dias_validade'] ?? 7);
                $c = $this->cupomSvc->gerarAniversario($clienteId, $pct, $dias);
                $cupomId = $c['id']; $cupomCodigo = $c['codigo'];
            }
            // Se já usou, envia sem cupom (só feliz aniversário)
        }

        if ($fluxoTipo === 'reengajamento' && (int)$item['passo_ordem'] === 3) {
            $pct = (float)($cfg['cupom_pct'] ?? 10);
            $dias = (int)($cfg['cupom_dias_validade'] ?? 15);
            $c = $this->cupomSvc->gerarReengajamento($clienteId, $pct, $dias);
            $cupomId = $c['id']; $cupomCodigo = $c['codigo'];
        }

        // 5. Monta variáveis do template
        $vars = $this->montarVars($cliente, $contexto, $fluxoTipo, $cupomCodigo);

        // 6. Envia
        try {
            $resultado = $this->enviar($cliente, $templateId, $vars);
            $this->model->marcarEnviado((int)$item['id']);
            $this->registrar($item, 'enviado', null, $cupomId, $cupomCodigo);
            
            // Atualiza cupom_id na fila se gerou
            if ($cupomId) {
                $this->db->prepare(
                    "UPDATE automacao_fila SET cupom_id = :c WHERE id = :id"
                )->execute([':c' => $cupomId, ':id' => $item['id']]);
            }

            return 'enviado';
        } catch (Throwable $e) {
            $this->model->marcarErro((int)$item['id'], $e->getMessage());
            $this->registrar($item, 'erro', $e->getMessage());
            
            return 'erro';
        }
    }

    // =========================================================================
    // SUPRESSÃO
    // =========================================================================
    private function verificarSupressao(int $clienteId, string $tipo): ?string
    {
        // Suprime se email em email_supressoes
        $st = $this->db->prepare(
            "SELECT 1 FROM email_supressoes es
             JOIN clientes c ON c.id = :cid
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE es.email = u.email
               AND (es.expira_em IS NULL OR es.expira_em > NOW())
             LIMIT 1"
        );
        $st->execute([':cid' => $clienteId]);
        if ($st->fetchColumn()) return 'email suprimido';

        // Para fluxos de comportamento: cancela se comprou recentemente
        $comportamentais = [
            'carrinho_abandonado', 'produto_visitado',
            'categoria_visitada', 'wishlist', 'reengajamento'
        ];
        if (in_array($tipo, $comportamentais, true)) {
            $st = $this->db->prepare(
                "SELECT 1 FROM pedidos
                 WHERE cliente_id = :c
                   AND status_pagamento = 'aprovado'
                   AND criado_em > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 LIMIT 1"
            );
            $st->execute([':c' => $clienteId]);
            if ($st->fetchColumn()) return 'comprou recentemente';
        }
        return null;
    }

    // =========================================================================
    // DADOS DO CLIENTE
    // =========================================================================
    private function buscarCliente(int $clienteId): ?array
    {
        $st = $this->db->prepare(
            "SELECT c.id, c.newsletter, c.nascimento,
                    u.nome, u.email
             FROM clientes c
             JOIN usuarios u ON u.id = c.usuario_id
             WHERE c.id = :id AND u.ativo = '1' AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $st->execute([':id' => $clienteId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    // =========================================================================
    // CONTEXTO ENRIQUECIDO
    // =========================================================================
    private function montarVars(array $cliente, array $ctx, string $tipo, ?string $cupomCodigo): array
    {
        $base = defined('BASE_URL') ? BASE_URL : '';
        $nome = explode(' ', $cliente['nome'])[0];

        $vars = [
            'nome'           => $cliente['nome'],
            'primeiro_nome'  => $nome,
            'email'          => $cliente['email'],
            'cupom'          => $cupomCodigo ?? '',
            'site_nome'      => defined('SITE_NAME') ? SITE_NAME : 'SportMoto',
            'url_site'       => $base,
            'url_descadastro'=> $base . '/email/descadastrar/' . urlencode($cliente['email']),
            'data_atual'     => date('d/m/Y'),
            'logo_loja'      => BASE_URL.'/uploads'.ConfigHelper::get('site_logo'),
            'cor_padrao'     => ConfigHelper::get('cor_padrao', '#000')
        ];

        // Adiciona contexto específico por tipo
        switch ($tipo) {
            case 'carrinho_abandonado':
                if (!empty($ctx['carrinho_id'])) {
                    $itens = $this->buscarItensCarrinho((int)$ctx['carrinho_id']);
                    $vars['carrinho_itens'] = $itens;
                    $vars['carrinho_url']   = $base . '/carrinho';
                    $vars['carrinho_total'] = number_format(
                        array_sum(array_column($itens, 'subtotal')), 2, ',', '.'
                    );
                }
                break;

            case 'produto_visitado':
            case 'wishlist':
            case 'lancamento_moto':
                if (!empty($ctx['produto_id'])) {
                    $p = $this->buscarProduto((int)$ctx['produto_id']);
                    if ($p) {
                        $vars['produto_nome']  = $p['nome'];
                        $vars['produto_preco'] = 'R$ ' . number_format($p['preco'], 2, ',', '.');
                        $vars['produto_url']   = $base . '/produto/' . $p['slug'];
                        $vars['produto_img']   = $p['imagem_principal'] ?? '';
                    }
                }
                break;

            case 'categoria_visitada':
                if (!empty($ctx['categoria_id'])) {
                    $cat = $this->buscarCategoria((int)$ctx['categoria_id']);
                    if ($cat) {
                        $vars['categoria_nome'] = $cat['nome'];
                        $vars['categoria_url']  = $base . '/categoria/' . $cat['slug'];
                    }
                    // Top produtos da categoria
                    $vars['produtos_categoria'] = $this->buscarTopProdutosCategoria((int)$ctx['categoria_id']);
                }
                break;

            case 'pos_compra_complementar':
            case 'pos_compra_avaliacao':
                if (!empty($ctx['pedido_id'])) {
                    $vars['pedido_id']  = $ctx['pedido_id'];
                    $vars['pedido_url'] = $base . '/minha-conta/avaliacoes#' . $ctx['pedido_id'];
                    $itensPed = $this->buscarItensPedido((int)$ctx['pedido_id']);
                    $vars['pedido_itens'] = $itensPed;
                }
                break;

            case 'aniversario':
                if ($cliente['nascimento']) {
                    $vars['idade'] = (string)(date('Y') - (int)date('Y', strtotime($cliente['nascimento'])));
                }
                break;
            case 'queda_preco':
                // Contexto já traz tudo pronto — o preço antigo não existe
                // mais no banco no momento do envio, por isso foi salvo no contexto.
                $vars['produto_nome'] = $ctx['produto_nome'] ?? '';
                $vars['produto_url']  = $ctx['produto_url']  ?? '';
                $vars['produto_img']  = $ctx['produto_img']  ?? '';
                $vars['preco_antigo'] = $ctx['preco_antigo'] ?? '';
                $vars['preco_novo']   = $ctx['preco_novo']   ?? '';
                $vars['desconto_pct'] = (string)($ctx['desconto_pct'] ?? '');
                break;

            case 'volta_estoque':
                $vars['produto_nome']  = $ctx['produto_nome']  ?? '';
                $vars['produto_url']   = $ctx['produto_url']   ?? '';
                $vars['produto_img']   = $ctx['produto_img']   ?? '';
                $vars['produto_preco'] = $ctx['produto_preco'] ?? '';
                break;
        }
        return $vars;
    }

    // =========================================================================
    // ENVIO — usa o EmailDispatchService com provedor padrão
    // =========================================================================
    private function enviar(array $cliente, int $templateId, array $vars): bool
    {
        LogService::info('teste de variaveis', $vars);
        // 1. Busca e renderiza o template
        $st = $this->db->prepare("SELECT * FROM email_templates WHERE id = :id LIMIT 1");
        $st->execute([':id' => $templateId]);
        $tpl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) throw new RuntimeException("Template $templateId não encontrado");

        $tplSvc  = new EmailTemplateService();
        $assunto = $tplSvc->renderInline($tpl['assunto'], $vars);
        $html    = $tplSvc->render($tpl['html'], $vars);
        $texto   = $tplSvc->htmlToText($html);
        [$html, $texto] = $tplSvc->injectUnsubscribe(
            $html, $texto, $vars['url_descadastro']
        );

        // 2. Instancia o provider via EmailProviderService (descriptografa corretamente)
        $provSvc  = new EmailProviderService();
        $provider = $provSvc->buildPadrao();
        // Busca config do padrão separado (para remetente_email/nome)
        $stProv = $this->db->query(
            "SELECT * FROM email_provedores WHERE ativo = 1 AND padrao = 1 LIMIT 1"
        );
        if (!$stProv) {
            $stProv = $this->db->query(
                "SELECT * FROM email_provedores WHERE ativo = 1 LIMIT 1"
            );
        }
        $cfg = $stProv->fetch(PDO::FETCH_ASSOC);
        if (!$cfg) throw new RuntimeException('Nenhum provedor de email ativo');

        // 3. Envia
        $resultado = $provider->send([
            'from_email' => $cfg['remetente_email'],
            'from_name'  => $cfg['remetente_nome'] ?? '',
            'reply_to'   => $cfg['reply_to'] ?? null,
            'to_email'   => $cliente['email'],
            'to_name'    => $cliente['nome'],
            'subject'    => $assunto,
            'html'       => $html,
            'text'       => $texto,
        ]);

        if (!$resultado->success) {
            throw new RuntimeException($resultado->error ?? 'Falha no envio');
        }
        return true;
    }

    private function resolveProviderClass(string $tipo): string
    {
        $map = [
            'mailgun'  => 'MailgunEmailProvider',
            'ses'      => 'SesEmailProvider',
            'sendgrid' => 'SendgridEmailProvider',
            'brevo'    => 'BrevoEmailProvider',
            'smtp'     => 'SmtpEmailProvider',
        ];
        $class = $map[strtolower($tipo)] ?? null;
        if (!$class || !class_exists($class)) {
            throw new RuntimeException("Provider '$tipo' não encontrado");
        }
        return $class;
    }

    // =========================================================================
    // HELPERS DE CONTEXTO
    // =========================================================================
    private function buscarItensCarrinho(int $carrinhoId): array
    {
        $st = $this->db->prepare(
            "SELECT ci.quantidade, ci.preco_unitario, ci.subtotal,
                    ci.produto_id,
                    p.nome AS produto_nome, p.slug
            FROM carrinho_itens ci
            JOIN produtos p ON p.id = ci.produto_id
            WHERE ci.carrinho_id = :c LIMIT 10"
        );
        $st->execute([':c' => $carrinhoId]);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens as &$item) {
            $item['imagem'] = class_exists('ImageHelper')
                ? ImageHelper::getPrincipal((int)$item['produto_id'])
                : '';
        }
        unset($item);

        return $itens;
    }

    private function buscarProduto(int $produtoId): ?array
    {
        $st = $this->db->prepare(
            "SELECT p.id, p.nome, p.slug, p.preco
            FROM produtos p
            WHERE p.id = :id 
            AND p.ativo = 1
            LIMIT 1"
        );

        $st->execute([':id' => $produtoId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        if (!$r) {
            return null;
        }

        $r['imagem_principal'] = ImageHelper::getPrincipal($produtoId);

        return $r;
    }

    private function buscarCategoria(int $categoriaId): ?array
    {
        $st = $this->db->prepare(
            "SELECT id, nome, slug FROM categorias WHERE id = :id AND ativo = 1 LIMIT 1"
        );
        $st->execute([':id' => $categoriaId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function buscarTopProdutosCategoria(int $categoriaId): array
    {
        $st = $this->db->prepare(
            "SELECT p.id, p.nome, p.slug, p.preco
            FROM produtos p
            WHERE p.categoria_id = :c AND p.ativo = 1 AND p.deleted_at IS NULL
            ORDER BY p.vendidos DESC, p.destaque DESC
            LIMIT 4"
        );
        $st->execute([':c' => $categoriaId]);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens as &$item) {
            $item['imagem_principal'] = class_exists('ImageHelper')
                ? ImageHelper::getPrincipal((int)$item['id'])
                : '';
            $item['url'] = (defined('BASE_URL') ? BASE_URL : '') . '/produto/' . $item['slug'];
        }
        unset($item);

        return $itens;
    }

    private function buscarItensPedido(int $pedidoId): array
    {
        $st = $this->db->prepare(
            "SELECT pi.quantidade, pi.preco_unitario,
                    p.id, p.nome, p.slug
            FROM pedido_itens pi
            JOIN produtos p ON p.id = pi.produto_id
            WHERE pi.pedido_id = :pid LIMIT 5"
        );
        $st->execute([':pid' => $pedidoId]);
        $itens = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($itens as &$item) {
            $item['imagem'] = class_exists('ImageHelper')
                ? ImageHelper::getPrincipal((int)$item['id'])
                : '';
        }
        unset($item);

        return $itens;
    }

    private function registrar(array $item, string $resultado, ?string $detalhe = null,
                                ?int $cupomId = null, ?string $cupomCodigo = null): void
    {
        $this->model->registrarHistorico([
            'fila_id'      => $item['id'],
            'cliente_id'   => $item['cliente_id'],
            'fluxo_id'     => $item['fluxo_id'],
            'passo_id'     => $item['passo_id'],
            'cupom_id'     => $cupomId,
            'cupom_codigo' => $cupomCodigo,
            'resultado'    => $resultado,
            'detalhe'      => $detalhe,
        ]);
    }
}
