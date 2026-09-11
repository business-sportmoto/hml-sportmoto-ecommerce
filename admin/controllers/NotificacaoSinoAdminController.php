<?php
// ════════════════════════════════════════════════════════
// admin/controllers/NotificacaoSinoAdminController.php
//
// Porta do painel para o sino: /admin/notificacoes/contador, /listar,
// /marcar-lida, /marcar-todas.
//
// ── POR QUE ESTE ARQUIVO EXISTE ─────────────────────────
//
// O controller do sino mora em app/controllers/NotificacaoController.php e
// serve as duas caixas (cliente e admin — a rota escolhe). Mas o autoloader
// do painel (admin/index.php) NÃO procura em app/controllers/: as rotas do
// admin apontando direto para NotificacaoController davam
//
//     Fatal error: Class "NotificacaoController" not found
//
// Acrescentar app/controllers/ ao autoloader do painel não é saída segura:
// seis classes têm o mesmo nome nas duas pastas (ApiController,
// HelpFaqController, EmailMarketingController…), e qual carrega passaria a
// depender da ordem da lista.
//
// Então o painel carrega aquele arquivo de forma explícita, e só ele.
// ════════════════════════════════════════════════════════

require_once ROOT_PATH . '/app/controllers/NotificacaoController.php';

final class NotificacaoSinoAdminController extends NotificacaoController
{
    // Sem corpo: as portas do painel (contadorAdmin, listarAdmin,
    // marcarLidaAdmin, marcarTodasAdmin) já estão no pai e forçam a caixa do
    // ADMIN. Esta classe existe só para o autoloader do painel encontrar.
}
