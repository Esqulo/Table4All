import { Head, useForm } from '@inertiajs/react';
import WaiterInvitationController from '@/actions/App/Http/Controllers/WaiterInvitationController';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    token: string;
    email: string;
    restaurant: { id: number; name: string };
    email_taken: boolean;
};

export default function WaiterAccept({ token, email, restaurant, email_taken }: Props) {
    const form = useForm({ name: '', password: '', password_confirmation: '' });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.post(WaiterInvitationController.store.url({ token }));
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-background px-4">
            <Head title={`Convite — ${restaurant.name}`} />

            <div className="w-full max-w-sm space-y-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <AppLogoIcon className="h-12 w-12" />
                    <h1 className="text-xl font-semibold">
                        Convite de <span className="text-primary">{restaurant.name}</span>
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Você foi convidado como garçom. O acesso será feito com:
                    </p>
                    <p className="rounded-md bg-muted px-3 py-1.5 text-sm font-medium">{email}</p>
                </div>

                {email_taken ? (
                    <div className="rounded-lg border border-border bg-card p-5 text-center space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Já existe uma conta com este e-mail.
                            Entre com sua conta existente e entre em contato com o restaurante.
                        </p>
                        <Button variant="outline" asChild className="w-full">
                            <a href="/login">Ir para o login</a>
                        </Button>
                    </div>
                ) : (
                    <form
                        onSubmit={handleSubmit}
                        className="rounded-lg border border-border bg-card p-5 shadow-xs space-y-4"
                    >
                        <div className="space-y-1">
                            <Label htmlFor="name">Seu nome</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="Nome completo"
                                autoComplete="name"
                                autoFocus
                            />
                            {form.errors.name && (
                                <p className="text-xs text-destructive">{form.errors.name}</p>
                            )}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="password">Senha</Label>
                            <Input
                                id="password"
                                type="password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                placeholder="Mínimo 8 caracteres"
                                autoComplete="new-password"
                            />
                            {form.errors.password && (
                                <p className="text-xs text-destructive">{form.errors.password}</p>
                            )}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="password_confirmation">Confirmar senha</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={form.data.password_confirmation}
                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                placeholder="Repita a senha"
                                autoComplete="new-password"
                            />
                            {form.errors.password_confirmation && (
                                <p className="text-xs text-destructive">{form.errors.password_confirmation}</p>
                            )}
                        </div>

                        <Button type="submit" className="w-full" disabled={form.processing}>
                            Criar conta e entrar
                        </Button>
                    </form>
                )}
            </div>
        </div>
    );
}
