import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Clock, Mail, Users, Vote } from 'lucide-react';
import { BrandSymbol } from '@/components/brand-symbol';
import { PasskeyLoginButton } from '@/components/passkey-login-button';
import { usePublicRoutes } from '@/hooks/use-public-routes';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import googleG from '../../../images/google-g.svg';
import steamLogoInverse from '../../../images/steam-logo-inverse.svg';
import steamLogoPositive from '../../../images/steam-logo-positive.svg';
import { redirect as googleRedirect } from '@/routes/auth/google';
import { redirect as steamRedirect } from '@/routes/auth/steam';
import { store } from '@/routes/login/link';

const brandButtonClass =
    'brand-button inline-flex min-h-13 w-full items-center justify-center rounded-sm border border-(--brand-stroke) bg-(--brand-bg) px-3 text-sm leading-5 font-medium text-(--brand-text)';

const methodClass =
    'inline-flex min-h-13 w-full items-center justify-center gap-2 rounded-sm border border-border-strong px-6.5 text-base leading-none font-semibold text-fg';

export default function Login() {
    const t = useT();
    const { home } = usePublicRoutes();
    const flash = usePage().flash as {
        linkSent?: string;
        steamError?: string;
        googleError?: string;
    };

    const benefits = [
        { icon: Vote, text: t('login.l1') },
        { icon: Users, text: t('login.l2') },
        { icon: Clock, text: t('login.l3') },
    ];

    return (
        <>
            <Head title={t('login.title')} />
            <div className="mx-auto mt-6 grid max-w-[480px] gap-7">
                <section
                    aria-labelledby="login-title"
                    className="grid gap-5.5 rounded-lg border border-border bg-surface px-5.5 py-7"
                >
                    <div className="flex items-center gap-2 font-display text-[17px] leading-none font-bold tracking-[-0.01em]">
                        <BrandSymbol size={26} />
                        <span>FastGrid</span>
                    </div>
                    <h1
                        id="login-title"
                        className="font-display text-[clamp(1.75rem,6vw,2.25rem)] leading-[1.1] font-bold tracking-[-0.03em]"
                    >
                        {t('login.title')}
                    </h1>
                    <ul className="grid gap-3">
                        {benefits.map(({ icon: Icon, text }) => (
                            <li key={text} className="flex items-start gap-3">
                                <Icon
                                    className="mt-0.75 size-[22px] flex-none text-primary-ink"
                                    aria-hidden="true"
                                />
                                <span>{text}</span>
                            </li>
                        ))}
                    </ul>
                    {flash.steamError ? (
                        <p
                            role="alert"
                            className="rounded-md border border-err/45 px-3.5 py-3 font-medium text-err"
                        >
                            {flash.steamError}
                        </p>
                    ) : null}
                    {flash.googleError ? (
                        <p
                            role="alert"
                            className="rounded-md border border-err/45 px-3.5 py-3 font-medium text-err"
                        >
                            {flash.googleError}
                        </p>
                    ) : null}
                    <div className="grid gap-3">
                        <a
                            href={steamRedirect().url}
                            className={brandButtonClass}
                        >
                            <img
                                src={steamLogoPositive}
                                width={66}
                                height={20}
                                alt=""
                                className="brand-logo-positive mr-3 h-5 w-auto"
                            />
                            <img
                                src={steamLogoInverse}
                                width={66}
                                height={20}
                                alt=""
                                className="brand-logo-inverse mr-3 h-5 w-auto"
                            />
                            {t('login.steam')}
                        </a>
                        <a
                            href={googleRedirect().url}
                            className={brandButtonClass}
                        >
                            <img
                                src={googleG}
                                width={20}
                                height={20}
                                alt=""
                                className="mr-2.5 size-5"
                            />
                            {t('login.google')}
                        </a>
                    </div>
                    <p className="text-[13px] text-muted">{t('login.or')}</p>
                    <Form
                        action={store()}
                        resetOnSuccess
                        className="grid gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <label
                                        htmlFor="login-email"
                                        className="text-sm font-semibold"
                                    >
                                        {t('login.email')}
                                    </label>
                                    <input
                                        id="login-email"
                                        name="email"
                                        type="email"
                                        inputMode="email"
                                        autoComplete="email"
                                        maxLength={254}
                                        required
                                        aria-invalid={
                                            errors.email ? true : undefined
                                        }
                                        aria-describedby={
                                            errors.email
                                                ? 'login-email-error'
                                                : undefined
                                        }
                                        className="min-h-11 w-full rounded-sm border border-border-strong bg-surface px-3 text-fg"
                                    />
                                    {errors.email ? (
                                        <p
                                            id="login-email-error"
                                            role="alert"
                                            className="text-[13px] text-err"
                                        >
                                            {errors.email}
                                        </p>
                                    ) : null}
                                </div>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className={cn(
                                        methodClass,
                                        'border-primary bg-primary text-on-primary transition-[filter] duration-150 ease-out hover:brightness-110 disabled:opacity-55',
                                    )}
                                >
                                    <Mail
                                        className="size-[18px]"
                                        aria-hidden="true"
                                    />
                                    {t('login.emailBtn')}
                                </button>
                            </>
                        )}
                    </Form>
                    {flash.linkSent ? (
                        <p
                            role="status"
                            className="rounded-md border border-info/45 px-3.5 py-3 font-medium text-info"
                        >
                            {flash.linkSent}
                        </p>
                    ) : null}
                    <PasskeyLoginButton
                        className={cn(methodClass, 'disabled:opacity-55')}
                    />
                    <p className="text-[13px] text-muted">{t('login.note')}</p>
                    <p className="text-xs text-muted">{t('login.trademark')}</p>
                    <Link
                        href={home().url}
                        className="inline-flex min-h-11 items-center justify-self-start px-1 text-sm text-muted underline underline-offset-3"
                    >
                        {t('login.back')}
                    </Link>
                </section>
            </div>
        </>
    );
}
