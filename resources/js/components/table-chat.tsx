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
}: {
    code: string;
    chat: ChatFeed;
    reactions: string[];
    maxLength: number;
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

    // Messages and reactions arrive quickly; the rest of the screen refreshes slower.
    useLiveReload({ only: ['chat'], intervalMs: 3_000 });

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
        <div className="space-y-3 rounded-xl border border-border bg-card p-4">
            <div
                ref={layerRef}
                className="pointer-events-none fixed inset-0 z-50 overflow-hidden"
                aria-hidden="true"
            />

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
    );
}
