<?php
/**
 * IAPermissaoService — quem pode o quê na Central de Marketing IA.
 *
 * As permissões do módulo (`marketing_ia`, `marketing_ia_aprovar`,
 * `marketing_ia_config`) nunca foram cadastradas no catálogo do RBAC: não
 * estão em Cargos.php nem na tela de promoção de usuário. Sem linha em
 * `admins.permissoes`, o adminTemPermissao() só passa pelo curto-circuito do
 * super — ou seja, a Central inteira era super-only sem que nada dissesse.
 *
 * Enquanto o cadastro granular não existe, cada permissão tem um cargo de
 * cobertura. É o mesmo fallback que o CLAUDE.md §6.4 descreve para as
 * notificações ("cadastre no sistema de permissões ou deixe cair no
 * requireAdminLevel").
 *
 * A checagem granular vem PRIMEIRO: no dia em que as permissões entrarem no
 * Cargos.php, quem tiver a permissão explícita passa por ela e este mapa
 * deixa de ter efeito — dá para apagá-lo sem tocar em nenhum controller.
 */
class IAPermissaoService
{
    /**
     * Cargo mínimo por permissão.
     *
     * 'super' passa em qualquer verificação pelo bypass do
     * AuthHelper::hasLevel (§4.4), então listar 'gerente' cobre os dois.
     *
     * marketing_ia_config guarda chaves de API dos provedores — mesma régua
     * que a §4.2 aplica a Integrações (Bling, Tray): super e mais ninguém.
     */
    private const CARGOS = [
        'marketing_ia'         => ['gerente'],
        'marketing_ia_aprovar' => ['gerente'],
        'marketing_ia_config'  => ['super'],
        // Catálogo de agentes de BI: persona, ferramentas, páginas. Muda o
        // que a IA diz ao time inteiro, mas não toca credencial — régua de
        // "config de automação" (§4.2), não de "integrações".
        'marketing_ia_agentes' => ['gerente'],
    ];

    /** Não-fatal: quem chama decide se bloqueia ou só muda o comportamento. */
    public function pode(string $permissao): bool
    {
        if (Session::adminTemPermissao($permissao)) {
            return true;
        }

        $cargos = self::CARGOS[$permissao] ?? [];

        // Permissão desconhecida não vira acesso liberado por descuido.
        return $cargos !== [] && AuthHelper::hasLevel(...$cargos);
    }
}
