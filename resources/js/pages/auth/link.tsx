import { Form, Head, Link } from '@inertiajs/react';
import { BrandSymbol } from '@/components/brand-symbol';
import { useT } from '@/hooks/use-t';
import { login } from '@/routes';

const primaryClass =
    'inline-flex min-h-13 w-full items-center justify-center gap-2 rounded-sm border border-primary bg-primary px-6.5 text-base leading-none font-semibold text-on-primary transition-[filter] duration-150 ease-out hover:brightness-110 disabled:opacity-55';

export default function LoginLink({
    consumeUrl,
}: {
    consumeUrl: string | null;
}) {
    const t = useT();
    const valid = consumeUrl !== null;

    return (
        <>
            <Head title={valid ? t('link.title') : t('link.invalidTitle')} />
            <div className="mx-auto mt-6 grid max-w-[480px] gap-7">
                <section
                    aria-labelledby="link-title"
                    className="grid gap-5.5 rounded-lg border border-border bg-surface px-5.5 py-7"
                >
                    <div className="flex items-center gap-2 font-display text-[17px] leading-none font-bold tracking-[-0.01em]">
                        <BrandSymbol size={26} />
                        <span>FastGrid</span>
                    </div>
                    <h1
                        id="link-title"
                        className="font-display text-[clamp(1.75rem,6vw,2.25rem)] leading-[1.1] font-bold tracking-[-0.03em]"
                    >
                        {valid ? t('link.title') : t('link.invalidTitle')}
                    </h1>
                    <p className="text-muted">
                        {valid ? t('link.body') : t('link.invalid')}
                    </p>
                    {valid ? (
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
                                    {t('link.action')}
                                </button>
                            )}
                        </Form>
                    ) : (
                        <Link href={login().url} className={primaryClass}>
                            {t('link.toLogin')}
                        </Link>
                    )}
                </section>
            </div>
        </>
    );
}
