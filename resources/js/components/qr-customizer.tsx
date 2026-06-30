import { QRCodeSVG } from 'qrcode.react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import type { Menu } from '@/types';

type QRMenu = Pick<Menu, 'id' | 'name'> & { url: string };

type Config = {
    fgColor: string;
    bgColor: string;
    text: string;
    textColor: string;
    textSize: number;
    frameEnabled: boolean;
    frameColor: string;
    frameWidth: number;
    frameRadius: number;
    framePadding: number;
    logo: string | null;
    logoSize: number;
};

const DEFAULT: Config = {
    fgColor: '#000000',
    bgColor: '#ffffff',
    text: '',
    textColor: '#000000',
    textSize: 20,
    frameEnabled: false,
    frameColor: '#000000',
    frameWidth: 2,
    frameRadius: 12,
    framePadding: 20,
    logo: null,
    logoSize: 50,
};

type Props = {
    menu: QRMenu | null;
    onClose: () => void;
};

export function QRCustomizerDialog({ menu, onClose }: Props) {
    const { t } = useTranslation();
    const [cfg, setCfg] = useState<Config>(DEFAULT);
    const printRef = useRef<HTMLDivElement>(null);
    const logoInputRef = useRef<HTMLInputElement>(null);

    const set = <K extends keyof Config>(key: K, value: Config[K]) =>
        setCfg((prev) => ({ ...prev, [key]: value }));

    const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (ev) => set('logo', ev.target?.result as string);
        reader.readAsDataURL(file);
        e.target.value = '';
    };

    const handlePrint = () => {
        if (!printRef.current) return;
        const win = window.open('', '_blank', 'width=600,height=700');
        if (!win) return;
        win.document.write(
            `<!DOCTYPE html><html><head><title>${menu?.name ?? 'QR Code'}</title>` +
            `<style>*{box-sizing:border-box;margin:0;padding:0}body{display:flex;justify-content:center;align-items:center;min-height:100vh;background:#fff;font-family:sans-serif;}</style>` +
            `</head><body>${printRef.current.outerHTML}</body></html>`,
        );
        win.document.close();
        win.focus();
        setTimeout(() => { win.print(); win.close(); }, 300);
    };

    return (
        <Dialog open={menu !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-3xl p-0 gap-0 flex flex-col h-[90vh]">
                <DialogHeader className="px-6 pt-5 pb-4 border-b border-border shrink-0">
                    <DialogTitle>
                        {t('menus.qr_title')} — {menu?.name}
                    </DialogTitle>
                </DialogHeader>

                <div className="flex flex-col md:flex-row flex-1 overflow-hidden min-h-0">
                    {/* Controls */}
                    <div className="overflow-y-auto md:w-72 shrink-0 p-5 space-y-5 border-b md:border-b-0 md:border-r border-border">
                        {/* Colors */}
                        <section className="space-y-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {t('menus.qr_colors')}
                            </p>
                            <ColorRow
                                label={t('menus.qr_fg_color')}
                                value={cfg.fgColor}
                                onChange={(v) => set('fgColor', v)}
                            />
                            <ColorRow
                                label={t('menus.qr_bg_color')}
                                value={cfg.bgColor}
                                onChange={(v) => set('bgColor', v)}
                            />
                        </section>

                        <Separator />

                        {/* Text */}
                        <section className="space-y-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {t('menus.qr_text_section')}
                            </p>
                            <div className="space-y-1">
                                <Label className="text-xs">{t('menus.qr_text_label')}</Label>
                                <Input
                                    value={cfg.text}
                                    onChange={(e) => set('text', e.target.value)}
                                    placeholder={t('menus.qr_text_placeholder')}
                                    maxLength={80}
                                />
                            </div>
                            {cfg.text && (
                                <div className="grid grid-cols-2 gap-2">
                                    <ColorRow
                                        label={t('menus.qr_text_color')}
                                        value={cfg.textColor}
                                        onChange={(v) => set('textColor', v)}
                                    />
                                    <NumRow
                                        label={t('menus.qr_text_size')}
                                        value={cfg.textSize}
                                        min={10} max={48}
                                        onChange={(v) => set('textSize', v)}
                                    />
                                </div>
                            )}
                        </section>

                        <Separator />

                        {/* Frame */}
                        <section className="space-y-3">
                            <div className="flex items-center justify-between">
                                <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {t('menus.qr_frame_section')}
                                </p>
                                <Toggle
                                    checked={cfg.frameEnabled}
                                    onChange={(v) => set('frameEnabled', v)}
                                />
                            </div>
                            {cfg.frameEnabled && (
                                <div className="space-y-3">
                                    <ColorRow
                                        label={t('menus.qr_frame_color')}
                                        value={cfg.frameColor}
                                        onChange={(v) => set('frameColor', v)}
                                    />
                                    <div className="grid grid-cols-3 gap-2">
                                        <NumRow label={t('menus.qr_frame_width')}   value={cfg.frameWidth}   min={1}  max={20} onChange={(v) => set('frameWidth', v)} />
                                        <NumRow label={t('menus.qr_frame_radius')}  value={cfg.frameRadius}  min={0}  max={60} onChange={(v) => set('frameRadius', v)} />
                                        <NumRow label={t('menus.qr_frame_padding')} value={cfg.framePadding} min={8}  max={64} onChange={(v) => set('framePadding', v)} />
                                    </div>
                                </div>
                            )}
                        </section>

                        <Separator />

                        {/* Logo */}
                        <section className="space-y-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {t('menus.qr_logo_section')}
                            </p>
                            <input
                                ref={logoInputRef}
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={handleLogoChange}
                            />
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => logoInputRef.current?.click()}
                                >
                                    {cfg.logo ? t('menus.qr_logo_change') : t('menus.qr_logo_select')}
                                </Button>
                                {cfg.logo && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => set('logo', null)}
                                    >
                                        {t('menus.qr_logo_remove')}
                                    </Button>
                                )}
                            </div>
                            {cfg.logo && (
                                <NumRow
                                    label={t('menus.qr_logo_size')}
                                    value={cfg.logoSize}
                                    min={20} max={100}
                                    onChange={(v) => set('logoSize', v)}
                                />
                            )}
                        </section>
                    </div>

                    {/* Preview + Print */}
                    <div className="flex flex-1 flex-col items-center justify-between gap-6 p-6 overflow-y-auto">
                        <p className="self-start text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            {t('menus.qr_preview')}
                        </p>

                        <div className="flex flex-1 items-center justify-center">
                            {/* printRef wraps ONLY the printable content — inline styles so outerHTML captures cleanly */}
                            <div
                                ref={printRef}
                                style={{
                                    display: 'inline-flex',
                                    flexDirection: 'column',
                                    alignItems: 'center',
                                    gap: '12px',
                                    padding: cfg.frameEnabled ? `${cfg.framePadding}px` : '0',
                                    border: cfg.frameEnabled
                                        ? `${cfg.frameWidth}px solid ${cfg.frameColor}`
                                        : 'none',
                                    borderRadius: cfg.frameEnabled ? `${cfg.frameRadius}px` : '0',
                                    backgroundColor: cfg.bgColor,
                                }}
                            >
                                {cfg.text && (
                                    <p
                                        style={{
                                            margin: 0,
                                            color: cfg.textColor,
                                            fontSize: `${cfg.textSize}px`,
                                            fontWeight: 'bold',
                                            textAlign: 'center',
                                            fontFamily: 'sans-serif',
                                            maxWidth: '240px',
                                            lineHeight: 1.3,
                                        }}
                                    >
                                        {cfg.text}
                                    </p>
                                )}
                                {menu && (
                                    <QRCodeSVG
                                        value={menu.url}
                                        size={200}
                                        fgColor={cfg.fgColor}
                                        bgColor={cfg.bgColor}
                                        level={cfg.logo ? 'H' : 'M'}
                                        imageSettings={
                                            cfg.logo
                                                ? {
                                                      src: cfg.logo,
                                                      width: cfg.logoSize,
                                                      height: cfg.logoSize,
                                                      excavate: true,
                                                  }
                                                : undefined
                                        }
                                    />
                                )}
                            </div>
                        </div>

                        <Button onClick={handlePrint} className="w-full">
                            {t('menus.qr_print')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function ColorRow({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
}) {
    return (
        <div className="space-y-1">
            <Label className="text-xs">{label}</Label>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="h-7 w-7 cursor-pointer rounded border border-border bg-transparent p-0.5"
                />
                <span className="font-mono text-xs text-muted-foreground">{value}</span>
            </div>
        </div>
    );
}

function NumRow({
    label,
    value,
    min,
    max,
    onChange,
}: {
    label: string;
    value: number;
    min: number;
    max: number;
    onChange: (v: number) => void;
}) {
    return (
        <div className="space-y-1">
            <Label className="text-xs">{label}</Label>
            <Input
                type="number"
                min={min}
                max={max}
                value={value}
                onChange={(e) => onChange(Number(e.target.value))}
                className="h-8"
            />
        </div>
    );
}

function Toggle({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={[
                'relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                checked ? 'bg-primary' : 'bg-muted',
            ].join(' ')}
        >
            <span
                className={[
                    'pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-lg ring-0 transition-transform',
                    checked ? 'translate-x-4' : 'translate-x-0',
                ].join(' ')}
            />
        </button>
    );
}
