import { Form, Head, Link } from '@inertiajs/react';
import { useT } from '@/hooks/use-t';
import { show as accountShow } from '@/routes/account';

const primaryClass =
    'inline-flex min-h-13 w-full items-center justify-center gap-2 rounded-sm border border-primary bg-primary px-6.5 text-base leading-none font-semibold text-on-primary transition-[filter] duration-150 ease-out hover:brightness-110 disabled:opacity-55';

type LinkPurpose = 'confirmation' | 'email_change';

export default function AccountLink({
    purpose,
    consumeUrl,
}: {
    purpose: LinkPurpose | null;
    consumeUrl: string | null;
}) {
    const t = useT();

    const title =
        purpose === null
            ? t('account.link.invalidTitle')
            : purpose === 'email_change'
              ? t('account.link.emailTitle')
              : t('account.link.confirmTitle');

    const body =
        purpose === null
            ? t('account.link.invalid')
            : purpose === 'email_change'
              ? t('account.link.emailBody')
              : t('account.link.confirmBody');

    return (
        <>
            <Head title={title} />
            <div className="mx-auto mt-6 grid max-w-[480px] gap-7">
                <section
                    aria-labelledby="account-link-title"
                    className="grid gap-5.5 rounded-lg border border-border bg-surface px-5.5 py-7"
                >
                    <h1
                        id="account-link-title"
                        className="font-display text-[clamp(1.75rem,6vw,2.25rem)] leading-[1.1] font-bold tracking-[-0.03em]"
                    >
                        {title}
                    </h1>
                    <p className="text-muted">{body}</p>
                    {consumeUrl !== null ? (
                        <Form
                            action={{ url: consumeUrl, method: 'post' }}
                            className="grid"
                        >
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className={primaryClass}
                                >
                                    {t('account.link.action')}
                                </button>
                            )}
                        </Form>
                    ) : (
                        <Link href={accountShow().url} className={primaryClass}>
                            {t('account.link.back')}
                        </Link>
                    )}
                </section>
            </div>
        </>
    );
}
