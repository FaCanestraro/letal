---
paths:
  - app/Http/Requests/StoreSpedConversionRequest.php
---

# Requests

## max_file_uploads do PHP corta o lote em silêncio
O padrão do PHP é max_file_uploads=20. Acima disso os arquivos excedentes são descartados sem erro, sem aviso e sem log — o usuário recebe uma planilha faltando períodos e não tem como perceber. Foi exatamente o que aconteceu enquanto a aplicação anunciava um teto de 120.

Por isso o formulário manda expected_files com a contagem que o navegador enviou, e a validação recusa o lote quando chegam menos arquivos que o esperado. Nunca remova essa checagem: ela é a única barreira contra conversão silenciosamente incompleta.

Ao mudar StoreSpedConversionRequest::MAX_FILES, ajuste junto no php.ini: max_file_uploads, post_max_size e upload_max_filesize. A tela avisa sozinha quando o teto do PHP está abaixo do teto da aplicação.
