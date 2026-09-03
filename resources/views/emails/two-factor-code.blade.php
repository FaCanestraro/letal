<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Código de acesso</title>
</head>
<body style="margin:0;padding:0;background-color:#F2EFE9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F2EFE9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #E2DCD1;">
                    <tr>
                        <td style="background-color:#0F1C2E;padding:28px 32px;">
                            <div style="color:#C9A227;font-size:11px;letter-spacing:.22em;text-transform:uppercase;font-weight:600;">
                                {{ config('app.name') }}
                            </div>
                            <div style="color:#ffffff;font-size:20px;font-weight:600;margin-top:6px;">
                                Verificação em duas etapas
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 8px;color:#16293C;font-size:15px;">Olá, {{ $user->name }}.</p>
                            <p style="margin:0 0 24px;color:#5A6472;font-size:14px;line-height:1.6;">
                                Recebemos uma tentativa de acesso à sua conta. Use o código abaixo para concluir o login.
                            </p>

                            <div style="background-color:#F7F5F1;border:1px solid #E2DCD1;border-radius:10px;padding:22px;text-align:center;">
                                <div style="font-size:34px;letter-spacing:12px;font-weight:700;color:#0F1C2E;font-family:'SF Mono',Menlo,Consolas,monospace;">
                                    {{ $code }}
                                </div>
                                <div style="margin-top:8px;color:#8A8579;font-size:12px;">
                                    Válido por {{ $minutes }} minutos — até {{ $expiresAt->timezone(config('app.timezone'))->format('d/m/Y H:i') }}.
                                </div>
                            </div>

                            <p style="margin:24px 0 0;color:#5A6472;font-size:13px;line-height:1.6;">
                                Se não foi você quem solicitou este acesso, ignore este e-mail e troque sua senha por precaução.
                                Nunca compartilhe este código com terceiros — nossa equipe jamais irá solicitá-lo.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px;background-color:#FAF8F5;border-top:1px solid #E2DCD1;color:#8A8579;font-size:12px;">
                            Mensagem automática de {{ config('app.name') }}. Não responda a este e-mail.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
