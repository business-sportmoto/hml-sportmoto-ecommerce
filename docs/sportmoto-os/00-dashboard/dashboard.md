---
tipo: dashboard
status: ativo
atualizado: 2026-09-03
---

# SportMoto OS

## Acesso rápido

- [[../01-projeto/visao-geral|Visão geral]]
- [[../11-contexto-ia/00-leia-primeiro|Contexto para IA]]
- [[../11-contexto-ia/01-arquitetura-atual|Arquitetura atual]]
- [[../11-contexto-ia/02-convencoes|Convenções e armadilhas]]
- [[../11-contexto-ia/03-status-atual|Status atual]]
- [[../07-workers-cron/mapa-workers-cron|Workers e cron jobs]]
- [[../12-decisoes-tecnicas/bi-indice|BI — índice]]
- [[../12-decisoes-tecnicas/chat-indice|Chat / Instagram / IA — índice]]
- [[../13-comandos-snippets/chat-diagnostico|Chat — diagnóstico em 3 comandos]]
- [[../90-inbox/inbox|Inbox]]

## Projeto agora

### Em desenvolvimento

- **Painel — conteúdo editável.** Rodapé e criador de páginas.
  [[../12-decisoes-tecnicas/conteudo-editavel-rodape-e-paginas|→]]
- **Log de operações do Bling** com filtro, paginação e detalhe.
  [[../12-decisoes-tecnicas/bling-log-operacoes|→]]
- **Expedição** — etiqueta no pedido, bipagem por `sku_legado`, impressão.
  [[../12-decisoes-tecnicas/expedicao-etiqueta-e-impressao|→]]
- **Produtos** — topbar fixa, ordem das fotos, vínculo de Clips.
  [[../12-decisoes-tecnicas/admin-produtos-midia-e-clips|→]]

### Em homologação

- **Chat / Instagram / IA** — agente de IA respondendo comentários de
  Reel pelo direct. Aguardando o deploy de
  [[../10-deploy/chat-ia-instagram-checklist|chat-ia-instagram-checklist]].
  Contexto: [[../11-contexto-ia/04-sessao-chat-ia-instagram|sessão 31/08–03/09]].

- **Newsletter com cupom de boas-vindas** — fluxo aprovado, **bloqueado** pelo
  provedor de e-mail em sandbox.
  [[../12-decisoes-tecnicas/newsletter-cupom-boas-vindas|→]]
- **BI / Power BI** — camada semântica + painel interno.
  Aguardando reaplicar `sql/bi-fase2.sql` e os GRANTs novos.
  [[../12-decisoes-tecnicas/bi-indice|→]]

### Bugs críticos

- **17 perguntas paradas em `aguardando_ia`** — clientes esperando
  resposta na loja, sem cron varrendo a fila.
- **E-mail sai pelo sandbox do Mailgun** — só entrega para endereços
  autorizados; a newsletter não chega ao cliente real.

Ver [[../04-bugs/Bugs para resolver]].

### Próximos deploys

- Trocar o provedor de e-mail padrão para o AWS SES.
- Aplicar `log_comunicacoes_tipo_migration.sql`, `frete_fallback_seed.sql`
  e `devolucao_reversa_link_migration.sql`.

## Decisões técnicas

### Índices de módulo
- [[../12-decisoes-tecnicas/bi-indice|BI — índice]]
- [[../12-decisoes-tecnicas/ia-indice|Central de IA — índice]]
- [[../12-decisoes-tecnicas/pagamentos-indice|Pagamentos — índice]]
- [[../12-decisoes-tecnicas/bling-indice|Bling — índice]]
- [[../12-decisoes-tecnicas/chat-indice|Chat / Instagram / IA — índice]]

### Avulsos
- [[../12-decisoes-tecnicas/modulo-chat-whatsapp|Chat / WhatsApp]]
- [[../12-decisoes-tecnicas/expedicao-etiqueta-e-impressao|Expedição — etiqueta e impressão]]
- [[../12-decisoes-tecnicas/conteudo-editavel-rodape-e-paginas|Rodapé e criador de páginas]]
- [[../12-decisoes-tecnicas/newsletter-cupom-boas-vindas|Newsletter — cupom de boas-vindas]]
- [[../12-decisoes-tecnicas/admin-produtos-midia-e-clips|Produtos — mídia e Clips]]
- [[../12-decisoes-tecnicas/bling-log-operacoes|Bling — log de operações]]

## Operação

- [[../07-workers-cron/mapa-workers-cron|Mapa de workers e crons]]
- [[../06-infraestrutura|Infraestrutura]]
- [[../10-deploy|Deploys]]
- [[../08-integracoes|Integrações]]

## Captura rápida

Registre informações ainda não classificadas em:

# Arquitetura
- [[Fluxo de automações]]
