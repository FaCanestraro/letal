/**
 * Espelho das rotas nomeadas do Laravel (routes/web.php e routes/auth.php).
 * Mantido manualmente para evitar uma dependência extra de geração de rotas.
 */
export const routes = {
    home: '/',

    register: '/cadastro',
    login: '/login',
    logout: '/logout',

    passwordRequest: '/esqueci-a-senha',
    passwordEmail: '/esqueci-a-senha',
    passwordStore: '/redefinir-senha',
    passwordReset: (token: string) => `/redefinir-senha/${token}`,

    twoFactor: '/verificacao',
    twoFactorResend: '/verificacao/reenviar',

    dashboard: '/painel',
    sped: '/sped',
    spedStore: '/sped',
    spedBatch: '/sped/lotes',
    spedBatchUpload: (id: number) => `/sped/lotes/${id}/arquivos`,
    spedBatchConvert: (id: number) => `/sped/lotes/${id}/converter`,
    spedBatchCancel: (id: number) => `/sped/lotes/${id}`,

    settings: {
        profile: '/configuracoes/perfil',
        password: '/configuracoes/senha',
        twoFactor: '/configuracoes/dois-fatores',
    },
} as const;
