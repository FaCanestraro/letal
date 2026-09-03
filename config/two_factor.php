<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Segundo fator de autenticação (código por e-mail)
    |--------------------------------------------------------------------------
    |
    | O sistema envia um código numérico de uso único para o e-mail do usuário
    | após a validação da senha. As configurações do provedor de e-mail ficam
    | em config/mail.php e são lidas do .env (MAIL_MAILER, MAIL_HOST, ...).
    |
    */

    'enabled' => (bool) env('TWO_FACTOR_ENABLED', true),

    // Quantidade de dígitos do código enviado.
    'code_length' => (int) env('TWO_FACTOR_CODE_LENGTH', 6),

    // Validade do código, em minutos.
    'expires_in_minutes' => (int) env('TWO_FACTOR_EXPIRES', 10),

    // Tentativas erradas aceitas antes de invalidar o código.
    'max_attempts' => (int) env('TWO_FACTOR_MAX_ATTEMPTS', 5),

    // Intervalo mínimo entre dois envios, em segundos.
    'resend_cooldown' => (int) env('TWO_FACTOR_RESEND_COOLDOWN', 60),

    // Validade da sessão intermediária (senha ok, aguardando código), em minutos.
    'challenge_ttl_minutes' => (int) env('TWO_FACTOR_CHALLENGE_TTL', 15),

    /*
     | Atalho de desenvolvimento: exibe o código na própria tela de verificação
     | enquanto não houver um provedor de e-mail configurado. Liga sozinho apenas
     | em APP_ENV=local com MAIL_MAILER=log, e pode ser forçado pelo .env.
     */
    'expose_code_on_screen' => (bool) env(
        'TWO_FACTOR_SHOW_CODE_ON_SCREEN',
        env('APP_ENV') === 'local' && env('MAIL_MAILER') === 'log',
    ),

];
