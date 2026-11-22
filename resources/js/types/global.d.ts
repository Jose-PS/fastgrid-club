import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: Auth;
            locale: string;
            social: Record<'discord' | 'instagram' | 'x' | 'bluesky', string>;
            translations: Record<string, string>;
            [key: string]: unknown;
        };
    }
}
