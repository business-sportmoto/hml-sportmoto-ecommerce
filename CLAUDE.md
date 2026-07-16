# Projeto SportMoto

Este é um e-commerce existente, desenvolvido em PHP com arquitetura MVC.

## Contexto obrigatório

@docs/sportmoto-os/11-contexto-ia/00-leia-primeiro.md
@docs/sportmoto-os/11-contexto-ia/01-arquitetura-atual.md
@docs/sportmoto-os/11-contexto-ia/02-convencoes.md
@docs/sportmoto-os/11-contexto-ia/03-status-atual.md

## Regras fundamentais

- Analise o código existente antes de propor alterações.
- Não invente arquivos, tabelas, colunas, rotas, serviços ou integrações.
- Preserve a arquitetura MVC atual.
- Não introduza frameworks sem autorização.
- Não exponha credenciais, tokens, senhas ou variáveis sensíveis.
- Antes de alterar uma funcionalidade, identifique controllers, models, services, helpers, views e tabelas envolvidos.
- Prefira alterações incrementais e compatíveis com o projeto atual.
- Ao concluir uma alteração relevante, informe quais documentos do Vault precisam ser atualizados.
- Registre novas decisões arquiteturais em `12-decisoes-tecnicas`.
- Registre bugs corrigidos em `04-bugs`.
- Registre workers e crons em `07-workers-cron`.