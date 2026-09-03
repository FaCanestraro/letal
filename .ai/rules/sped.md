---
paths:
  - 'app/Services/Sped/**'
---

# Sped

## Conversor SPED: uma aba por registro-folha
Um registro vira linha na aba do seu próprio código apenas quando não tem filhos naquele arquivo; quando tem, vira prefixo das abas dos filhos. As colunas são ID_DT_INI|ID_DT_FIN|ID_CNPJ seguidas dos campos de toda a cadeia de ancestrais, cada bloco abrindo com sua coluna REG. Contadores (9900, X990, 9001, 9990, 9999) são descartados na ida e recalculados na volta — nunca guardados.

Regra validada célula a célula contra as planilhas de referência do cliente: 130 das 131 abas idênticas em 7,8 milhões de células. A única diferença é um bug da ferramenta de referência, que perde a última ocorrência do 9100 na ECF.

Na volta, ancestrais são reagrupados por valor mas FOLHAS NUNCA são fundidas: duas folhas idênticas são dois lançamentos, e fundi-las apaga linhas.
