# Documentação — Automações + Vida Útil

Pacote de referência e teste do Motor de Automação v2 e do subsistema de
dicas de cuidado.

## Conteúdo

```
docs/manual-automacoes.md   O motor completo: arquitetura, catálogo dos 19 nós
                            (config integral + pegadinhas), variáveis,
                            guard-rails, catálogo de eventos, 8 receitas com o
                            grafo pronto, e a seção 11 — GAPS CONHECIDOS.
docs/manual-vida-util.md    Filosofia, mecânica, regras de comportamento,
                            primeira rodada em produção, troubleshooting.
docs/plano-de-testes.md     Rodadas R0–R9 executáveis (comando → esperado →
                            onde conferir) + matriz sintoma → onde olhar.
cli/fluxo-testar.php        Roda uma jornada AGORA e imprime cada passo.
                            --acordar atravessa esperas de dias em segundos.
cli/fluxo-simular-evento.php Injeta eventos no stream como se fosse o cliente.
                            --detectar roda a detecção na hora.
```

## Instalação dos scripts

Copie os dois arquivos para `cli/` no servidor. Sem migração, sem rota — eles
usam apenas as APIs já instaladas do motor.

## Por onde começar

1. Leia a **seção 11 do manual de automações** (gaps conhecidos) — tem pelo
   menos uma instrumentação pendente (`pedido_criado`) que afeta duas
   funcionalidades importantes.
2. Rode a rodada **R0** (pré-voo) e a **R1** (smoke de 5 minutos).
3. Siga as rodadas na ordem; a R8 fecha validando a observabilidade contra
   tudo que você provocou nas anteriores.
