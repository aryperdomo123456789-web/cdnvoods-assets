# Arquitetura Single XUI + LB

Data: 2026-07-31

Estado decidido para este projeto:

- proteger apenas `1` XUI
- manter a máquina atual como `main`
- usar LB para absorver a carga pesada de stream
- não evoluir o fluxo principal para `multi-XUI`

## Papel de cada máquina

- `main`
  - painel
  - SQLite
  - jobs
  - rastreabilidade
  - decisão de roteamento
- `lb`
  - entrega do proxy público
  - banda
  - telemetria

## Regra operacional

Para tirar carga da máquina atual, o cliente precisa entrar pelo domínio público
apontado para o LB. O `main` continua como cérebro e fallback.

## Multi-XUI

O material de `multi-XUI` fica tratado como legado/experimental e não deve
orientar novas mudanças da arquitetura principal.
