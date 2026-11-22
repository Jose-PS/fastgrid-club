import { Link } from '@inertiajs/react';
import { LogIn } from 'lucide-react';
import { useT } from '@/hooks/use-t';
import { cn } from '@/lib/utils';
import { login } from '@/routes';

export function SignInButton({ className }: { className?: string }) {
    const t = useT();

    return (
        <Link
            href={login().url}
            className={cn(
                'inline-flex min-h-11 items-center justify-center gap-2 rounded-sm border border-primary bg-primary px-4.5 text-sm leading-none font-semibold whitespace-nowrap text-on-primary transition-[filter] duration-150 ease-out hover:brightness-110',
                className,
            )}
        >
            <LogIn className="size-[18px]" aria-hidden="true" />
            {t('nav.signin')}
        </Link>
    );
}
