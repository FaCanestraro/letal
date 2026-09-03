---
paths:
  - app/Http/Controllers/SpedController.php
---

# Controllers

## Download de resultado: assinado e retomável
O resultado de uma conversão pode passar de 7 GB, e isso quebra o download comum de duas formas.

1) `Storage::download()` devolve um StreamedResponse, que não anuncia Accept-Ranges. Qualquer queda de conexão reinicia os 7 GB do zero. Use `response()->download($caminhoAbsoluto, $nome)`: o BinaryFileResponse trata Range e o download retoma de onde parou.

2) A rota não pode depender da sessão. Um download desse tamanho dura mais que SESSION_LIFETIME, e o gerenciador do sistema refaz a requisição sozinho, sem cookie — o usuário recebia um login.html no lugar do arquivo. Por isso a rota fica FORA do grupo `auth`, com middleware `signed`, e a tela recebe uma URL temporária pronta.

Nunca ler o arquivo para entregá-lo (`file_get_contents`): estoura o memory_limit.

Em desenvolvimento, `php artisan serve` é um processo só e qualquer requisição paralela corta o download. Precisa de `--no-reload` junto de PHP_CLI_SERVER_WORKERS — sem a flag o Laravel ignora a variável e avisa.
