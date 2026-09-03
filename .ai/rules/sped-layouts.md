---
paths:
  - 'resources/sped-layouts/**'
---

# Sped Layouts

## Layouts SPED são dados derivados, não escritos à mão
Não existe layout oficial no projeto: os 4 JSON foram inferidos cruzando 90 arquivos .txt do cliente com as planilhas de referência, e a planilha é a fonte da verdade sobre o tipo de cada campo.

Armadilha central: 327 dos 1.121 campos parecem numéricos e TÊM de permanecer texto. CNPJ, IE, COD_MUN, COD_VER ("017"), CST ("060") e CFOP quebram silenciosamente se virarem número. Nunca inferir tipo pelo valor.

ECD e ECF mudaram ordem de campos entre 2023 e 2025: `ordem_campos` mapeia campo-do-txt para coluna, com chave "REG#vN" quando há mais de uma versão. Um registro que apareça com largura nova precisa de um par .txt + planilha para deduzir o mapa — o conversor não adivinha.

`registros_estruturais` lista registros que abrem bloco e nunca viram aba (hoje só o 9001 da ECF).
