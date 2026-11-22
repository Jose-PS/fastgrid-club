import { router } from '@inertiajs/react';
import { NotSupportedError, UserCancelledError } from '@laravel/passkeys';
import type { PasskeyError } from '@laravel/passkeys';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { KeyRound } from 'lucide-react';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import type { Translate } from '@/hooks/use-t';
import { login, loginOptions } from '@/routes/passkey';

function errorMessage(error: PasskeyError, t: Translate): string {
    if (error instanceof UserCancelledError) {
        return t('login.passkeyCancelled');
    }

    if (error instanceof NotSupportedError) {
        return t('login.passkeyUnsupported');
    }

    return t('login.passkeyError');
}

export function PasskeyLoginButton({ className }: { className: string }) {
    const t = useT();
    const { home } = usePublicRoutes();
    const { verify, isLoading, errorInstance, isSupported } = usePasskeyVerify({
        routes: { options: loginOptions().url, submit: login().url },
        onSuccess: (response) => {
            router.visit(response.redirect ?? home().url);
        },
    });

    const messageId = 'passkey-login-message';
    const message = !isSupported
        ? t('login.passkeyUnsupported')
        : errorInstance
          ? errorMessage(errorInstance, t)
          : null;

    return (
        <div className="grid gap-2">
            <button
                type="button"
                onClick={() => void verify()}
                disabled={!isSupported || isLoading}
                aria-describedby={message ? messageId : undefined}
                className={className}
            >
                <KeyRound className="size-[18px]" aria-hidden="true" />
                {isLoading ? t('login.passkeyLoading') : t('login.passkey')}
            </button>
            {message ? (
                <p
                    id={messageId}
                    role={errorInstance ? 'alert' : undefined}
                    className={
                        errorInstance
                            ? 'text-[13px] text-err'
                            : 'text-[13px] text-muted'
                    }
                >
                    {message}
                </p>
            ) : null}
        </div>
    );
}
