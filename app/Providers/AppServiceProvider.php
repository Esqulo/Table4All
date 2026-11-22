<?php

namespace App\Providers;

use App\Support\Payments\MercadoPagoGateway;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\StripePaymentGateway;
use App\Support\Video\FfmpegVideoTranscoder;
use App\Support\Video\VideoTranscoder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(VideoTranscoder::class, fn (): VideoTranscoder => new FfmpegVideoTranscoder(
            (string) config('services.ffmpeg.ffmpeg'),
            (string) config('services.ffmpeg.ffprobe'),
        ));

        $this->app->singleton(PaymentGateway::class, fn (): PaymentGateway => new StripePaymentGateway(
            config('services.stripe.secret'),
            array_values(array_filter(array_map('trim', explode(',', (string) config('services.stripe.webhook_secret'))))),
            (string) config('payments.currency'),
        ));

        $this->app->singleton(MercadoPagoGateway::class, fn (): MercadoPagoGateway => new MercadoPagoGateway(
            config('services.mercadopago.client_id'),
            config('services.mercadopago.client_secret'),
            config('services.mercadopago.webhook_secret'),
            (string) config('payments.currency'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
