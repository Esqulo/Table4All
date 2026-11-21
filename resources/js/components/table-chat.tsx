import { router } from '@inertiajs/react';
import { MessageCircle, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import JoinController from '@/actions/App/Http/Controllers/Customer/JoinController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useLiveReload } from '@/hooks/use-live-reload';
import { newRequestKey } from '@/lib/request-key';
import { cn } from '@/lib/utils';

export type ChatMessage = {
    id: number;
    name: string;
    body: string;
    mine: boolean;
    at: string;
};

export type ChatReaction = {
    id: number;
    emoji: string;
    mine: boolean;
};

export type ChatFeed = {
    messages: ChatMessage[];
    reactions: ChatReaction[];
};

/** Reactions flying at once; more are dropped so old phones keep up. */
const MAX_FLOATING = 24;

/**
 * Chat refresh: quick while people are talking, slow once it goes quiet. Every
 * open phone polls the chat, so this is most of the server's traffic during
 * service (see docs/performance.md).
 */
const LIVELY_RELOAD_MS = 3_000;
const QUIET_RELOAD_MS = 12_000;
/** How long after the last message, reaction or tap on the chat it stays quick. */
const LIVELY_FOR_MS = 60_000;

/** Id of the newest entry someone else sent (0 if none). No findLast: old phones lack it. */
function lastOthersId(entries: { id: number; mine: boolean }[]): number {
    for (let i = entries.length - 1; i >= 0; i--) {
        if (!entries[i].mine) {
            return entries[i].id;
        }
    }

    return 0;
}

/**
 * Sends an emoji floating up the screen. Drawn straight into the overlay (not
 * React state) so a burst of reactions never re-renders the table screen.
 */
function launch(layer: HTMLElement | null, emoji: string) {
    if (!layer || layer.childElementCount >= MAX_FLOATING) {
        return;
    }

    const el = document.createElement('span');
    el.className = 't4a-float-up';
    el.textContent = emoji;
    el.style.left = `${8 + Math.random() * 80}%`;
    el.style.setProperty('--t4a-drift', `${(Math.random() - 0.5) * 120}px`);
    el.style.animationDelay = `${Math.random() * 150}ms`;
    el.addEventListener('animationend', () => el.remove(), { once: true });
    layer.appendChild(el);
}

/** Short messages among the customers at a table, plus emoji that float up on everyone's screen. */
export function TableChat({
    code,
    chat,
    reactions,
    maxLength,
    hidden = false,
}: {
    code: string;
    chat: ChatFeed;
    reactions: string[];
    maxLength: number;
    /** Hides the card but keeps it polling, so reactions still float up from any tab. */
    hidden?: boolean;
}) {
    const { t, i18n } = useTranslation();
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [requestKey, setRequestKey] = useState(newRequestKey);
    const layerRef = useRef<HTMLDivElement>(null);
    const listRef = useRef<HTMLDivElement>(null);
    // Reactions already shown here. Those on screen when the page opened are
    // skipped, so reopening the table doesn't replay them.
    const seen = useRef<Set<number> | null>(null);
    const lastMessageId = chat.messages.at(-1)?.id ?? 0;

    // Quick while the table is chatting (someone else wrote or reacted, or this
    // customer tapped the chat), slow after a quiet minute. The rest of the
    // screen refreshes on its own, slower schedule.
    const [lively, setLively] = useState(true);
    const [activity, setActivity] = useState(0);
    const wake = () => {
        setLively(true);
        setActivity((n) => n + 1);
    };

    // Others' activity, noticed while rendering (not in an effect) so the
    // quick schedule starts with this very render.
    const othersMark = `${lastOthersId(chat.messages)}:${lastOthersId(chat.reactions)}`;
    const [seenOthersMark, setSeenOthersMark] = useState(othersMark);

    if (seenOthersMark !== othersMark) {
        setSeenOthersMark(othersMark);
        wake();
    }

    useEffect(() => {
        if (!lively) {
            return;
        }

        const timer = setTimeout(() => setLively(false), LIVELY_FOR_MS);

        return () => clearTimeout(timer);
    }, [lively, activity]);

    useLiveReload({
        only: ['chat'],
        intervalMs: lively ? LIVELY_RELOAD_MS : QUIET_RELOAD_MS,
    });

    useEffect(() => {
        if (seen.current === null) {
            seen.current = new Set(chat.reactions.map((r) => r.id));

            return;
        }

        for (const reaction of chat.reactions) {
            if (seen.current.has(reaction.id)) {
                continue;
            }

            seen.current.add(reaction.id);

            // The sender saw theirs fly the moment they tapped.
            if (!reaction.mine) {
                launch(layerRef.current, reaction.emoji);
            }
        }
    }, [chat.reactions]);

    // Follow new messages, unless the customer scrolled up to read older ones.
    useEffect(() => {
        const list = listRef.current;

        if (!list) {
            return;
        }

        const nearBottom =
            list.scrollHeight - list.scrollTop - list.clientHeight < 120;

        if (nearBottom || chat.messages.at(-1)?.mine) {
            list.scrollTop = list.scrollHeight;
        }
    }, [lastMessageId, chat.messages]);

    function send(event: FormEvent) {
        event.preventDefault();

        const text = body.trim();

        if (text === '' || sending) {
            return;
        }

        setSending(true);
        wake();
        router.post(
            JoinController.sendMessage.url(code),
            { body: text, request_key: requestKey },
            {
                only: ['chat'],
                preserveScroll: true,
                // Chat is instant on screen; no page-loading bar for it.
                showProgress: false,
                onSuccess: () => {
                    setBody('');
                    setRequestKey(newRequestKey());
                },
                onFinish: () => setSending(false),
            },
        );
    }

    function react(emoji: string) {
        launch(layerRef.current, emoji);
        wake();
        router.post(
            JoinController.react.url(code),
            { emoji },
            // Async so quick taps don't cancel each other; no loading bar.
            {
                only: ['chat'],
                preserveScroll: true,
                async: true,
                showProgress: false,
            },
        );
    }

    const time = new Intl.DateTimeFormat(
        i18n.language === 'pt_BR' ? 'pt-BR' : 'en-US',
        { hour: '2-digit', minute: '2-digit' },
    );

    return (
        <>
            <div
                ref={layerRef}
                className="pointer-events-none fixed inset-0 z-50 overflow-hidden"
                aria-hidden="true"
            />
            <div
                className={cn(
                    'space-y-3 rounded-xl border border-border bg-card p-4',
                    hidden && 'hidden',
                )}
            >
                <p className="flex items-center gap-2 text-sm font-semibold">
                    <MessageCircle className="h-4 w-4 text-muted-foreground" />
                    {t('join_table.chat_title')}
                </p>

                <div
                    ref={listRef}
                    className="max-h-64 space-y-2 overflow-y-auto pr-1"
                    aria-live="polite"
                >
                    {chat.messages.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            {t('join_table.chat_empty')}
                        </p>
                    ) : (
                        chat.messages.map((message) => (
                            <div
                                key={message.id}
                                className={cn(
                                    'flex flex-col',
                                    message.mine ? 'items-end' : 'items-start',
                                )}
                            >
                                <div
                                    className={cn(
                                        'max-w-[85%] rounded-2xl px-3 py-1.5 text-sm break-words',
                                        message.mine
                                            ? 'rounded-br-sm bg-primary text-primary-foreground'
                                            : 'rounded-bl-sm bg-muted',
                                    )}
                                >
                                    {!message.mine && (
                                        <span className="block text-xs font-semibold opacity-70">
                                            {message.name}
                                        </span>
                                    )}
                                    {message.body}
                                </div>
                                <span className="mt-0.5 text-[0.65rem] text-muted-foreground tabular-nums">
                                    {time.format(new Date(message.at))}
                                </span>
                            </div>
                        ))
                    )}
                </div>

                <div className="flex flex-wrap justify-between gap-1">
                    {reactions.map((emoji) => (
                        <button
                            key={emoji}
                            type="button"
                            onClick={() => react(emoji)}
                            className="flex h-10 w-10 items-center justify-center rounded-full text-xl transition-transform hover:bg-muted active:scale-90"
                            aria-label={t('join_table.chat_react', { emoji })}
                        >
                            {emoji}
                        </button>
                    ))}
                </div>

                <form onSubmit={send} className="flex gap-2">
                    <Input
                        value={body}
                        onChange={(e) => {
                            setBody(e.target.value);
                            setRequestKey(newRequestKey());
                        }}
                        onFocus={wake}
                        maxLength={maxLength}
                        placeholder={t('join_table.chat_placeholder')}
                        aria-label={t('join_table.chat_placeholder')}
                        enterKeyHint="send"
                    />
                    <Button
                        type="submit"
                        size="icon"
                        disabled={sending || body.trim() === ''}
                        aria-label={t('join_table.chat_send')}
                    >
                        <Send className="h-4 w-4" />
                    </Button>
                </form>
            </div>
        </>
    );
}
