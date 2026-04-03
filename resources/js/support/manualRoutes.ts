import type { RouteDefinition, RouteFormDefinition, RouteQueryOptions } from '@/wayfinder';

const route = <T extends 'get' | 'post' | 'delete'>(
    url: string,
    method: T,
): RouteDefinition<T> =>
    ({
        url,
        method,
    }) as unknown as RouteDefinition<T>;

const form = <T extends 'get' | 'post' | 'delete'>(
    action: string,
    method: T,
): RouteFormDefinition<T> => ({
    action,
    method,
});

export const passwordRequest = (_options?: RouteQueryOptions) => route('/forgot-password', 'get');
passwordRequest.form = (_options?: RouteQueryOptions) => form('/forgot-password', 'get');

export const passwordEmail = (_options?: RouteQueryOptions) => route('/forgot-password', 'post');
passwordEmail.form = (_options?: RouteQueryOptions) => form('/forgot-password', 'post');

export const passwordUpdate = (_options?: RouteQueryOptions) => route('/reset-password', 'post');
passwordUpdate.form = (_options?: RouteQueryOptions) => form('/reset-password', 'post');

export const registerStore = (_options?: RouteQueryOptions) => route('/register', 'post');
registerStore.form = (_options?: RouteQueryOptions) => form('/register', 'post');

export const verificationSend = (_options?: RouteQueryOptions) => route('/email/verification-notification', 'post');
verificationSend.form = (_options?: RouteQueryOptions) => form('/email/verification-notification', 'post');

export const twoFactorConfirm = (_options?: RouteQueryOptions) => route('/user/confirmed-two-factor-authentication', 'post');
twoFactorConfirm.form = (_options?: RouteQueryOptions) => form('/user/confirmed-two-factor-authentication', 'post');

export const twoFactorEnable = (_options?: RouteQueryOptions) => route('/user/two-factor-authentication', 'post');
twoFactorEnable.form = (_options?: RouteQueryOptions) => form('/user/two-factor-authentication', 'post');

export const twoFactorDisable = (_options?: RouteQueryOptions) => route('/user/two-factor-authentication', 'delete');
twoFactorDisable.form = (_options?: RouteQueryOptions) => form('/user/two-factor-authentication', 'delete');

export const twoFactorQrCode = (_options?: RouteQueryOptions) => route('/user/two-factor-qr-code', 'get');
export const twoFactorRecoveryCodes = (_options?: RouteQueryOptions) => route('/user/two-factor-recovery-codes', 'get');
export const twoFactorRegenerateRecoveryCodes = (_options?: RouteQueryOptions) => route('/user/two-factor-recovery-codes', 'post');
twoFactorRegenerateRecoveryCodes.form = (_options?: RouteQueryOptions) => form('/user/two-factor-recovery-codes', 'post');
export const twoFactorSecretKey = (_options?: RouteQueryOptions) => route('/user/two-factor-secret-key', 'get');

export const twoFactorLoginStore = (_options?: RouteQueryOptions) => route('/two-factor-challenge', 'post');
twoFactorLoginStore.form = (_options?: RouteQueryOptions) => form('/two-factor-challenge', 'post');
