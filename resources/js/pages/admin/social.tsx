import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, Download, ExternalLink } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { fieldErrorClass, inputClass } from '@/components/account-styles';
import { SocialAccounts } from '@/components/admin/social-accounts';
import type { SocialAccount } from '@/components/admin/social-accounts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/tabs';
import { useT } from '@/hooks/use-t';
import { copyText } from '@/lib/clipboard';
import { formatDate } from '@/lib/datetime';
import { showToast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { approve, reject, retry, update } from '@/routes/admin/social';

type Channel = {
    status: string;
    url: string | null;
    error: string | null;
};

type SocialPost = {
    id: number;
    type: string;
    status: string;
    text: string;
    image_url: string | null;
    channels: Record<string, Channel>;
    created_at: string | null;
    approved_at: string | null;
    published_at: string | null;
};

const COPIED_FEEDBACK_MS = 2000;

function PostCard({
    post,
    maxLength,
}: {
    post: SocialPost;
    maxLength: number;
}) {
    const t = useT();
    const { locale } = usePage<{ locale: string }>().props;
    const form = useForm<{ text: string }>({ text: post.text });
    const [copied, setCopied] = useState(false);
    const pending = post.status === 'pending';
    const errors = form.errors as Record<string, string | undefined>;
    const errorMessage = errors.text ?? errors.post;
    const overLimit = form.data.text.length > maxLength;
    const dirty = form.data.text !== post.text;
    const dateOptions: Intl.DateTimeFormatOptions = {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    };
    const format = (value: string): string =>
        formatDate(new Date(value), locale, dateOptions);

    const save = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        form.clearErrors();
        form.patch(update(post.id).url, {
            preserveScroll: true,
            onSuccess: () =>
                showToast({
                    type: 'success',
                    message: t('admin.social.saved'),
                }),
        });
    };

    const act = (url: string, confirmKey: string, doneKey: string): void => {
        if (!window.confirm(t(confirmKey))) {
            return;
        }

        form.clearErrors();
        form.post(url, {
            preserveScroll: true,
            onSuccess: () =>
                showToast({ type: 'success', message: t(doneKey) }),
        });
    };

    const copy = async (): Promise<void> => {
        const ok = await copyText(form.data.text);

        if (!ok) {
            showToast({ type: 'error', message: t('admin.social.copyFailed') });

            return;
        }

        setCopied(true);
        window.setTimeout(() => setCopied(false), COPIED_FEEDBACK_MS);
    };

    const textId = `post-${post.id}-text`;

    return (
        <li
            id={`post-${post.id}`}
            className="grid scroll-mt-40 gap-4 rounded-lg border border-border bg-surface px-5.5 py-6"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="grid gap-1">
                    <h3 className="font-display text-lg font-bold">
                        {t(`admin.social.type.${post.type}`)}
                    </h3>
                    <p className="text-sm text-muted">
                        {post.created_at === null
                            ? null
                            : t('admin.social.created', {
                                  date: format(post.created_at),
                              })}
                        {post.approved_at === null
                            ? null
                            : ` · ${t('admin.social.approvedAt', { date: format(post.approved_at) })}`}
                        {post.published_at === null
                            ? null
                            : ` · ${t('admin.social.publishedAt', { date: format(post.published_at) })}`}
                    </p>
                </div>
                {pending ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            disabled={
                                form.processing || post.image_url === null
                            }
                            onClick={() =>
                                act(
                                    approve(post.id).url,
                                    'admin.social.confirmApprove',
                                    'admin.social.approved',
                                )
                            }
                        >
                            {t('admin.social.approve')}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={form.processing}
                            onClick={() =>
                                act(
                                    reject(post.id).url,
                                    'admin.social.confirmReject',
                                    'admin.social.rejected',
                                )
                            }
                        >
                            {t('admin.social.reject')}
                        </Button>
                        {post.image_url === null ? (
                            <p
                                role="status"
                                className="w-full text-sm text-muted"
                            >
                                {t('admin.social.waitingImage')}
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <Badge
                        variant={
                            post.status === 'failed' ||
                            post.status === 'rejected'
                                ? 'destructive'
                                : 'default'
                        }
                    >
                        {t(`admin.social.status.${post.status}`)}
                    </Badge>
                )}
            </div>
            <div className="grid gap-4 sm:grid-cols-[minmax(0,14rem)_1fr]">
                <div className="aspect-[4/5] w-full overflow-hidden rounded-md border border-border bg-surface-2">
                    {post.image_url === null ? (
                        <div className="grid size-full place-items-center p-4 text-center text-sm text-muted">
                            {t('admin.social.imagePending')}
                        </div>
                    ) : (
                        <img
                            src={post.image_url}
                            alt={t('admin.social.imageAlt')}
                            className="size-full object-cover"
                        />
                    )}
                </div>
                <form
                    onSubmit={save}
                    noValidate
                    className="grid content-start gap-2"
                >
                    <label htmlFor={textId} className="text-sm font-semibold">
                        {t('admin.social.textLabel')}
                    </label>
                    <textarea
                        id={textId}
                        rows={8}
                        readOnly={!pending}
                        value={form.data.text}
                        aria-invalid={Boolean(errorMessage) || overLimit}
                        aria-describedby={`${textId}-meta`}
                        onChange={(event) => {
                            form.clearErrors();
                            form.setData('text', event.target.value);
                        }}
                        className={cn(
                            inputClass,
                            'min-h-40 py-2 leading-relaxed',
                        )}
                    />
                    <div
                        id={`${textId}-meta`}
                        className="flex flex-wrap items-center justify-between gap-2"
                    >
                        {pending ? (
                            <span
                                className={cn(
                                    'mono text-xs',
                                    overLimit ? 'text-err' : 'text-muted',
                                )}
                            >
                                {t('admin.social.count', {
                                    n: form.data.text.length,
                                    max: maxLength,
                                })}
                            </span>
                        ) : (
                            <span />
                        )}
                        {errorMessage ? (
                            <p role="alert" className={fieldErrorClass}>
                                {errorMessage}
                            </p>
                        ) : null}
                    </div>
                    {!pending && Object.keys(post.channels).length > 0 ? (
                        <div className="grid gap-1 text-sm">
                            <span className="font-semibold">
                                {t('admin.social.channelsLabel')}
                            </span>
                            <ul className="grid gap-1">
                                {Object.entries(post.channels).map(
                                    ([name, channel]) => (
                                        <li
                                            key={name}
                                            className="flex flex-wrap items-center gap-2"
                                        >
                                            <span>
                                                {t(
                                                    `admin.social.channel.${name}`,
                                                )}
                                            </span>
                                            <Badge
                                                variant={
                                                    channel.status === 'failed'
                                                        ? 'destructive'
                                                        : 'default'
                                                }
                                            >
                                                {t(
                                                    `admin.social.channelStatus.${channel.status}`,
                                                )}
                                            </Badge>
                                            {channel.url === null ? null : (
                                                <a
                                                    href={channel.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 underline"
                                                >
                                                    {t('admin.social.viewPost')}
                                                    <ExternalLink
                                                        className="size-3.5"
                                                        aria-hidden="true"
                                                    />
                                                </a>
                                            )}
                                            {channel.error === null ? null : (
                                                <span className="text-err">
                                                    {channel.error}
                                                </span>
                                            )}
                                        </li>
                                    ),
                                )}
                            </ul>
                        </div>
                    ) : null}
                    <div className="flex flex-wrap gap-2">
                        {pending ? (
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    !dirty ||
                                    overLimit ||
                                    form.data.text.trim() === ''
                                }
                            >
                                {t('admin.social.save')}
                            </Button>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={copy}
                            aria-live="polite"
                        >
                            {copied ? <Check /> : <Copy />}
                            {copied
                                ? t('admin.social.copied')
                                : t('admin.social.copy')}
                        </Button>
                        {post.image_url === null ? null : (
                            <Button type="button" variant="outline" asChild>
                                <a href={post.image_url} download>
                                    <Download />
                                    {t('admin.social.downloadImage')}
                                </a>
                            </Button>
                        )}
                        {post.status === 'failed' ? (
                            <Button
                                type="button"
                                disabled={form.processing}
                                onClick={() =>
                                    act(
                                        retry(post.id).url,
                                        'admin.social.confirmRetry',
                                        'admin.social.retried',
                                    )
                                }
                            >
                                {t('admin.social.retry')}
                            </Button>
                        ) : null}
                    </div>
                </form>
            </div>
        </li>
    );
}

function PostList({
    posts,
    empty,
    maxLength,
}: {
    posts: SocialPost[];
    empty: string;
    maxLength: number;
}) {
    if (posts.length === 0) {
        return (
            <p role="status" className="text-muted">
                {empty}
            </p>
        );
    }

    return (
        <ul className="grid max-w-4xl gap-4">
            {posts.map((post) => (
                <PostCard key={post.id} post={post} maxLength={maxLength} />
            ))}
        </ul>
    );
}

export default function AdminSocial({
    pending,
    approved,
    rejected,
    accounts,
    maxLength,
}: {
    pending: SocialPost[];
    approved: SocialPost[];
    rejected: SocialPost[];
    accounts: SocialAccount[];
    maxLength: number;
}) {
    const t = useT();

    useEffect(() => {
        const hash = window.location.hash;

        if (hash.startsWith('#post-')) {
            document.getElementById(hash.slice(1))?.scrollIntoView({
                block: 'start',
            });
        }
    }, []);

    return (
        <>
            <Head title={t('admin.social.title')} />
            <div className="grid gap-7">
                <div>
                    <h1 className="font-display text-[clamp(2rem,6vw,2.75rem)] leading-[1.05] font-bold tracking-[-0.03em]">
                        {t('admin.social.title')}
                    </h1>
                    <p className="mt-2 max-w-[60ch] text-muted">
                        {t('admin.social.sub')}
                    </p>
                </div>
                <Tabs
                    label={t('admin.social.tabsLabel')}
                    queryKey="tab"
                    defaultId="pendientes"
                    items={[
                        {
                            id: 'pendientes',
                            label: t('admin.social.tabPending'),
                            badge: pending.length,
                            indicatorLabel: t('admin.social.pendingIndicator'),
                            panel: (
                                <PostList
                                    posts={pending}
                                    empty={t('admin.social.emptyPending')}
                                    maxLength={maxLength}
                                />
                            ),
                        },
                        {
                            id: 'aprobadas',
                            label: t('admin.social.tabApproved'),
                            panel: (
                                <PostList
                                    posts={approved}
                                    empty={t('admin.social.emptyApproved')}
                                    maxLength={maxLength}
                                />
                            ),
                        },
                        {
                            id: 'rechazadas',
                            label: t('admin.social.tabRejected'),
                            panel: (
                                <PostList
                                    posts={rejected}
                                    empty={t('admin.social.emptyRejected')}
                                    maxLength={maxLength}
                                />
                            ),
                        },
                        {
                            id: 'cuentas',
                            label: t('admin.social.tabAccounts'),
                            panel: <SocialAccounts accounts={accounts} />,
                        },
                    ]}
                />
            </div>
        </>
    );
}
