import { Link2, Lock } from 'lucide-react';
import { primaryButtonClass } from '@/components/account-styles';
import { SignInButton } from '@/components/sign-in-button';
import { useT } from '@/hooks/use-t';
import { link as googleLink } from '@/routes/account/identities/google';
import { link as steamLink } from '@/routes/account/identities/steam';
import type { ProposalViewer } from '@/types/proposals';

export function LockBlock({ state }: { state: ProposalViewer['state'] }) {
    const t = useT();

    return (
        <div className="grid justify-items-start gap-3.5">
            <Lock className="size-6 text-muted" aria-hidden="true" />
            <p className="max-w-[40ch] text-muted">
                {state === 'needs_identity'
                    ? t('props.needsIdentity')
                    : t('props.lockText')}
            </p>
            {state === 'needs_identity' ? (
                <div className="flex flex-wrap gap-2">
                    <a href={steamLink().url} className={primaryButtonClass}>
                        <Link2 className="size-[18px]" aria-hidden="true" />
                        {t('props.linkSteam')}
                    </a>
                    <a href={googleLink().url} className={primaryButtonClass}>
                        <Link2 className="size-[18px]" aria-hidden="true" />
                        {t('props.linkGoogle')}
                    </a>
                </div>
            ) : (
                <SignInButton />
            )}
        </div>
    );
}
