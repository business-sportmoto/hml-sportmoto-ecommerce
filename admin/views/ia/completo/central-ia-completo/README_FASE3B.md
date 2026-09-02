# Central de Marketing IA — Fase 3 · Bloco B: Telas de Campanhas

As três telas sobre o motor do 3A — **17/17 asserções verdes** no nível dos
endpoints, incluindo a grade renderizada com pares em estados mistos e a
curadoria em massa. Com este bloco, a **Fase 3 está completa**.

---

## As telas

- **Lista** (`/admin/ia/campanhas`): cards com pill de status, linha-resumo
  (produtos × tipos = pares · ok · falhas · custo real / teto), barra de
  progresso e ação contextual (Continuar rascunho | Abrir). Polling 20s.
- **Wizard** (`/admin/ia/campanhas/nova`, `?id=` continua rascunho):
  4 seções num fluxo só — dados+briefing (mesmo vocabulário do avulso:
  objetivo/público/tom/condição) e teto de orçamento opcional; produtos por
  busca (reusa o `produto-busca` existente) em chips removíveis + **atalho
  aprovado**: adicionar categoria ou marca inteira, sempre sob o teto de 60;
  tipos em checkboxes por grupo, com config inline ao marcar (banner exige
  layout; imagem escolhe proporção); revisão com **estimativa antes de
  gastar** (breakdown por tipo + avisos de produto sem foto) e os dois
  botões: "Salvar rascunho" e "Aprovar e gerar".
- **Detalhe** (`/admin/ia/campanha?id=`): cabeçalho com contadores ao vivo
  e ações condicionais ao status (Pausar/Retomar/Cancelar/Refazer falhas N/
  Aprovar concluídas/Arquivar), e a **grade produto × tipo** — cada célula é
  a pill de estado da geração; clicou, abre o drawer com o MESMO parcial do
  histórico (`/ia/historico/detalhe`) e as mesmas ações (curadoria, refazer,
  publicar banner, copiar). Polling 10s.

## Decisões de implementação

- **Drawer pelo componente do projeto.** O detalhe chama
  `adminDrawer({titulo, conteudo, tamanho:'lg'})` — a assinatura REAL do
  helper global — com fallback local se o componente não estiver na página.
  (O histórico chutava a assinatura; aqui está alinhado ao doc.)
- **Controller fino.** Toda regra vive no `IACampanhaService` (3A);
  o controller só traduz HTTP↔service, com `requirePermission('marketing_ia')`
  em tudo e CSRF nos POSTs. A grade nasce como parcial server-side
  (`campanhas/_grade`) devolvida por JSON — o polling troca o HTML inteiro.
- **Ação em massa nova**: `aprovar-concluidas` aprova a curadoria de tudo
  que concluiu e ainda estava pendente (com contagem no toast e badge
  `patch-check` nas células).
- **XSS**: cards e chips montados via `.text()`/`textContent`; a grade
  escapa com `ia_e()` no parcial.

## Instalação (delta — sem SQL neste bloco)

Copie: `app/controllers/IACampanhaController.php` (novo),
`app/views/ia/campanhas/` (4 arquivos novos: `index`, `nova`, `detalhe`,
`_grade`), `app/views/ia/_estilos.php` e `routes.ia.php` (**48 rotas**).
Autoloader: nada novo (controllers já registrado).

**Menu — AJUSTE:** adicione o link no menu do admin, ao lado dos demais da
Central: `Campanhas → /admin/ia/campanhas` (ícone sugerido:
`bi-collection-play`).

## Teste de fumaça

1. `/admin/ia/campanhas` → **Nova campanha** → nome + objetivo/tom.
2. Produtos: busque 2–3, ou use o atalho de categoria (repare no contador
   x/60). Tipos: marque uma legenda e o banner (escolha o layout).
3. **Calcular estimativa** → confira o total e os avisos → **Aprovar e
   gerar** → cai no detalhe.
4. Veja as células saírem de "—" para "Na fila" → "Gerando" → "OK" no ritmo
   de 4; clique numa célula e use o drawer normalmente (inclusive Publicar
   banner nas de composição).
5. Ao final, o sino avisa a conclusão; "Aprovar concluídas" fecha a
   curadoria em um clique.

## Fase 3 — encerrada

Motor (3A) + telas (3B): campanhas de N produtos × M formatos com custo
estimado antes, teto de orçamento, progresso ao vivo, falhas isoladas e
curadoria em massa — tudo sobre a MESMA máquina de gerações das Fases 1–2.
Próximos degraus do roadmap da Central: Fase 4 (vídeo) e Fase 5
(distribuição/agendamento).
