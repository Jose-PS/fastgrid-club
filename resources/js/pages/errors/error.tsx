import { Head, Link } from '@inertiajs/react';
import { useT } from '@/hooks/use-t';
import { usePublicRoutes } from '@/hooks/use-public-routes';

type ErrorPageProps = {
    status: number;
};

export default function ErrorPage({ status }: ErrorPageProps) {
    const t = useT();
    const { home } = usePublicRoutes();

    return (
        <>
            <Head title={t(`error.${status}.title`)} />
            <section
                aria-labelledby="error-title"
                className="mx-auto mt-6 grid max-w-[480px] gap-4 rounded-lg border border-border bg-surface px-5.5 py-7"
            >
                <p className="font-display text-sm leading-none font-bold tracking-[0.08em] text-primary-ink">
                    {status}
                </p>
                <h1
                    id="error-title"
                    className="font-display text-[clamp(1.75rem,6vw,2.25rem)] leading-[1.1] font-bold tracking-[-0.03em]"
                >
                    {t(`error.${status}.title`)}
                </h1>
                <p className="text-muted">{t(`error.${status}.text`)}</p>
                <Link
                    href={home().url}
                    className="mt-2 inline-flex min-h-11 w-fit items-center justify-center rounded-sm border border-border-strong px-5 text-base leading-none font-semibold text-fg"
                >
                    {t('error.home')}
                </Link>
            </section>
        </>
    );
}
