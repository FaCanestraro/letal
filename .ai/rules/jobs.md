---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Conversão SPED roda na fila, com área de trabalho no disco
O upload só grava os arquivos em storage/app/private/sped-work/{ulid}/entrada/ (prefixo numérico preserva a ordem de envio), cria o SpedConversion como pending e despacha o job. Converter dentro da requisição não era viável: a ECD do acervo leva 13s na ida e ~32s na volta.

O ConversionRunner é quem apaga a área de trabalho, sempre — no finally, tenha dado certo ou não — e zera workspace_path. Se um dia algo passar a rodar fora do runner, a limpeza precisa acompanhar.

tries = 1 de propósito: reprocessar arquivo inválido dá o mesmo erro e a mensagem original é o que o usuário precisa ler. O failed() marca o registro como falho quando o próprio job morre (estouro de timeout).

A tela consulta o status com usePoll enquanto houver conversão pending/processing e para sozinha ao terminar.

## queue:work guarda o código em memória
O worker carrega as classes uma vez e não enxerga alterações de código. Depois de mexer em qualquer coisa que a fila execute — job, serviço, layout — é preciso `php artisan queue:restart` (ou matar e subir o worker de novo), senão ele segue rodando a versão antiga.

Isso já custou uma depuração inteira: a divisão de planilhas funcionava em teste isolado e não acontecia na fila, porque o worker estava com o SpedWorkbookWriter anterior em memória. O sintoma é traiçoeiro — não dá erro, só produz o resultado antigo.
