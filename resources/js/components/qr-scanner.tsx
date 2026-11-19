import { ScanLine } from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Pulls the 6-digit table code out of a scanned QR payload. The table QR
 * encodes `<origin>/mesa/<code>`; a bare code is accepted too.
 */
export function extractTableCode(text: string): string | null {
    const value = text.trim();

    if (/^\d{6}$/.test(value)) {
        return value;
    }

    try {
        const match = new URL(value).pathname.match(
            /^\/(?:customer\/)?mesa\/(\d{6})\/?$/,
        );

        return match ? match[1] : null;
    } catch {
        return null;
    }
}

type BarcodeDetectorLike = {
    detect(source: CanvasImageSource): Promise<{ rawValue: string }[]>;
};

type BarcodeDetectorCtor = {
    new (options: { formats: string[] }): BarcodeDetectorLike;
    getSupportedFormats(): Promise<string[]>;
};

type Decoder = (video: HTMLVideoElement) => Promise<string | null>;

const SCAN_INTERVAL_MS = 200;
// Downscale frames for the JS decoder: a QR filling part of the view is still
// readable at this size and old phones stay responsive.
const MAX_FRAME_SIDE = 480;

async function createDecoder(): Promise<Decoder> {
    const Native = (window as { BarcodeDetector?: BarcodeDetectorCtor })
        .BarcodeDetector;

    if (Native) {
        try {
            const formats = await Native.getSupportedFormats();

            if (formats.includes('qr_code')) {
                const detector = new Native({ formats: ['qr_code'] });

                return async (video) => {
                    const codes = await detector.detect(video);

                    return codes[0]?.rawValue ?? null;
                };
            }
        } catch {
            // Fall through to the JS decoder.
        }
    }

    // iOS Safari and Firefox have no BarcodeDetector; load jsQR only then.
    const { default: jsQR } = await import('jsqr');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    return async (video) => {
        if (!context || !video.videoWidth) {
            return null;
        }

        const scale = Math.min(
            1,
            MAX_FRAME_SIDE / Math.max(video.videoWidth, video.videoHeight),
        );
        const width = Math.round(video.videoWidth * scale);
        const height = Math.round(video.videoHeight * scale);

        canvas.width = width;
        canvas.height = height;
        context.drawImage(video, 0, 0, width, height);

        const { data } = context.getImageData(0, 0, width, height);

        return (
            jsQR(data, width, height, { inversionAttempts: 'dontInvert' })
                ?.data ?? null
        );
    };
}

type ScanError = 'permission_denied' | 'unavailable' | null;

function QrScannerView({ onCode }: { onCode: (code: string) => void }) {
    const { t } = useTranslation();
    const videoRef = useRef<HTMLVideoElement>(null);
    const [error, setError] = useState<ScanError>(null);
    const [starting, setStarting] = useState(true);
    const [notATable, setNotATable] = useState(false);
    const onCodeRef = useRef(onCode);

    useEffect(() => {
        onCodeRef.current = onCode;
    }, [onCode]);

    useEffect(() => {
        let cancelled = false;
        let stream: MediaStream | null = null;
        let timer: number | undefined;

        async function start() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false,
                });
            } catch (e) {
                if (!cancelled) {
                    setError(
                        e instanceof DOMException &&
                            e.name === 'NotAllowedError'
                            ? 'permission_denied'
                            : 'unavailable',
                    );
                    setStarting(false);
                }

                return;
            }

            const video = videoRef.current;

            if (cancelled || !video) {
                stream.getTracks().forEach((track) => track.stop());

                return;
            }

            video.srcObject = stream;

            try {
                await video.play();
            } catch {
                // Autoplay of a muted inline video is allowed; ignore aborts.
            }

            const decode = await createDecoder();

            if (cancelled) {
                return;
            }

            setStarting(false);

            const tick = async () => {
                if (cancelled) {
                    return;
                }

                let text: string | null = null;

                if (video.readyState >= video.HAVE_CURRENT_DATA) {
                    try {
                        text = await decode(video);
                    } catch {
                        text = null;
                    }
                }

                if (cancelled) {
                    return;
                }

                if (text !== null) {
                    const code = extractTableCode(text);

                    if (code) {
                        cancelled = true;
                        stream?.getTracks().forEach((track) => track.stop());
                        onCodeRef.current(code);

                        return;
                    }

                    setNotATable(true);
                }

                timer = window.setTimeout(tick, SCAN_INTERVAL_MS);
            };

            tick();
        }

        start();

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
            stream?.getTracks().forEach((track) => track.stop());
        };
    }, []);

    if (error) {
        return (
            <p className="py-6 text-center text-sm text-destructive">
                {t(`join_table.scan_${error}`)}
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <div className="relative aspect-square w-full overflow-hidden rounded-xl bg-black">
                <video
                    ref={videoRef}
                    className="h-full w-full object-cover"
                    playsInline
                    muted
                    autoPlay
                />
                <div className="pointer-events-none absolute inset-[15%] rounded-2xl border-2 border-white/80" />
                {starting && (
                    <p className="absolute inset-0 flex items-center justify-center text-sm text-white/80">
                        {t('join_table.scan_starting')}
                    </p>
                )}
            </div>
            <p
                className="min-h-5 text-center text-sm text-muted-foreground"
                aria-live="polite"
            >
                {notATable ? t('join_table.scan_not_a_table') : ''}
            </p>
        </div>
    );
}

const noSubscription = () => () => {};
const cameraSupported = () => !!navigator.mediaDevices?.getUserMedia;
// SSR renders without the button; the client adds it when a camera API exists.
const cameraSupportedOnServer = () => false;

export function QrScanButton({ onCode }: { onCode: (code: string) => void }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const supported = useSyncExternalStore(
        noSubscription,
        cameraSupported,
        cameraSupportedOnServer,
    );

    if (!supported) {
        return null;
    }

    return (
        <>
            <Button
                type="button"
                variant="outline"
                size="lg"
                onClick={() => setOpen(true)}
            >
                <ScanLine />
                {t('join_table.scan_button')}
            </Button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('join_table.scan_title')}</DialogTitle>
                        <DialogDescription>
                            {t('join_table.scan_description')}
                        </DialogDescription>
                    </DialogHeader>
                    {open && (
                        <QrScannerView
                            onCode={(code) => {
                                setOpen(false);
                                onCode(code);
                            }}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
