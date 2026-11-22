<?php

namespace App\Support\Payments;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mercado Pago as a marketplace: the restaurant links its own Mercado Pago
 * account to the platform's application (OAuth), and customers pay on Checkout
 * Pro with a preference created with the restaurant's token. The money (and
 * Mercado Pago's fee) is the restaurant's; Mercado Pago withholds the platform's
 * commission as the preference's marketplace_fee.
 *
 * The account id is the restaurant's Mercado Pago user id; its tokens live on
 * the user (encrypted) and are renewed here before they expire.
 *
 * Unlike Stripe, the platform can't give part of its commission back through
 * Mercado Pago: what it owes a restaurant is paid outside (admin payouts).
 */
final class MercadoPagoGateway implements CheckoutGateway
{
    private const API = 'https://api.mercadopago.com';

    private const AUTHORIZE = 'https://auth.mercadopago.com/authorization';

    /** Renew a restaurant's token this long before it expires (it lasts 180 days). */
    private const RENEW_DAYS = 30;

    /** Payment statuses still on their way to approved (a Pix waiting to be paid, a card under review). */
    private const IN_FLIGHT = ['pending', 'in_process', 'authorized'];

    public function __construct(
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly ?string $webhookSecret,
        private readonly string $currency,
    ) {}

    public function configured(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret);
    }

    /** Where the restaurant's owner authorizes the platform on Mercado Pago. */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $this->ensureConfigured();

        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'platform_id' => 'mp',
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Trades the authorization code for the restaurant's tokens.
     *
     * @return array{user_id: string, access_token: string, refresh_token: string|null, expires_at: CarbonInterface}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $this->ensureConfigured();

        return $this->tokens($this->send(fn (PendingRequest $http) => $http->post(self::API.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ])));
    }

    public function createCheckout(string $accountId, CheckoutRequest $request): array
    {
        $expiresAt = CarbonImmutable::createFromTimestamp($request->expiresAt);

        $preference = [
            'items' => [[
                'id' => 'bill',
                'title' => $request->description,
                'quantity' => 1,
                'currency_id' => Str::upper($this->currency),
                'unit_price' => $request->chargeCents / 100,
            ]],
            'external_reference' => $request->reference,
            'metadata' => $request->metadata,
            'back_urls' => [
                'success' => $request->successUrl,
                'pending' => $request->successUrl,
                'failure' => $request->cancelUrl,
            ],
            'auto_return' => 'approved',
            'expires' => true,
            'expiration_date_from' => self::date(now()->subMinute()),
            'expiration_date_to' => self::date($expiresAt),
            // A Pix generated near the end dies shortly after the checkout, so it can't hold
            // the amount for long (Mercado Pago wants at least 30 minutes from now).
            'date_of_expiration' => self::date($expiresAt->addMinutes(30)),
            'payment_methods' => [
                // Boleto and ATM take days to confirm: not for a table being paid now.
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
                // Installments would cost the restaurant (or the customer) interest.
                'installments' => 1,
            ],
        ];

        if ($request->applicationFeeCents > 0) {
            $preference['marketplace_fee'] = $request->applicationFeeCents / 100;
        }

        if ($request->customerEmail !== null) {
            $preference['payer'] = ['email' => $request->customerEmail];
        }

        $created = $this->send(fn (PendingRequest $http) => $http
            ->withToken($this->token($accountId))
            ->withHeaders(['X-Idempotency-Key' => $request->idempotencyKey])
            ->post(self::API.'/checkout/preferences', $preference));

        return [
            'id' => (string) $created->json('id'),
            'url' => (string) $created->json('init_point'),
            'expires_at' => $request->expiresAt,
        ];
    }

    public function checkoutStatus(string $accountId, string $sessionId, string $reference): array
    {
        $payments = $this->paymentsFor($accountId, $reference);

        $approved = collect($payments)->firstWhere('status', 'approved');

        if ($approved !== null) {
            return ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => (string) $approved['id'], 'payment_intent_status' => 'approved'];
        }

        $waiting = collect($payments)->first(fn (array $p) => in_array($p['status'] ?? null, self::IN_FLIGHT, true));

        if ($waiting !== null) {
            return ['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => (string) $waiting['id'], 'payment_intent_status' => (string) $waiting['status']];
        }

        // Nothing paid nor on its way (a refused card can be tried again while the checkout is open).
        $preference = $this->send(fn (PendingRequest $http) => $http
            ->withToken($this->token($accountId))
            ->get(self::API.'/checkout/preferences/'.rawurlencode($sessionId)));

        $until = $preference->json('expiration_date_to');
        $expired = is_string($until) && $until !== '' && CarbonImmutable::parse($until)->isPast();

        return ['status' => $expired ? 'expired' : 'open', 'payment_status' => 'unpaid', 'payment_intent' => null, 'payment_intent_status' => null];
    }

    public function expireCheckout(string $accountId, string $sessionId, string $reference): void
    {
        foreach ($this->paymentsFor($accountId, $reference) as $payment) {
            if (in_array($payment['status'] ?? null, ['approved', ...self::IN_FLIGHT], true)) {
                throw new PaymentGatewayException('The checkout was paid (or is being paid) meanwhile.');
            }
        }

        $this->send(fn (PendingRequest $http) => $http
            ->withToken($this->token($accountId))
            ->put(self::API.'/checkout/preferences/'.rawurlencode($sessionId), [
                'expires' => true,
                'expiration_date_to' => self::date(now()),
            ]));
    }

    public function processingFee(string $accountId, string $paymentId): ?int
    {
        $payment = $this->payment($accountId, $paymentId);

        if (($payment['status'] ?? null) !== 'approved') {
            return null;
        }

        // Mercado Pago's own fee charged to the restaurant; the platform's commission is listed too.
        $fee = 0;
        foreach ($payment['fee_details'] ?? [] as $detail) {
            if (($detail['type'] ?? null) !== 'application_fee' && ($detail['fee_payer'] ?? 'collector') === 'collector') {
                $fee += (int) round(((float) ($detail['amount'] ?? 0)) * 100);
            }
        }

        return $fee;
    }

    public function refund(string $accountId, string $paymentId, int $cents, string $idempotencyKey): string
    {
        return (string) $this->send(fn (PendingRequest $http) => $http
            ->withToken($this->token($accountId))
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post(self::API.'/v1/payments/'.rawurlencode($paymentId).'/refunds', ['amount' => $cents / 100]))
            ->json('id');
    }

    public function refundsCommission(): bool
    {
        return false;
    }

    public function refundCommission(string $accountId, string $paymentId, int $cents, string $idempotencyKey): ?array
    {
        return null;
    }

    /**
     * One payment, as the restaurant's account sees it.
     *
     * @return array<string, mixed>
     */
    public function payment(string $accountId, string $paymentId): array
    {
        $payment = $this->send(fn (PendingRequest $http) => $http
            ->withToken($this->token($accountId))
            ->get(self::API.'/v1/payments/'.rawurlencode($paymentId)))
            ->json();

        return is_array($payment) ? $payment : [];
    }

    /**
     * Whether a notification was signed with the platform's webhook secret. The
     * signature header is "ts=…,v1=…": an HMAC-SHA256 of
     * "id:{data.id};request-id:{x-request-id};ts:{ts};".
     */
    public function validSignature(?string $signature, ?string $requestId, string $dataId): bool
    {
        if (blank($this->webhookSecret) || blank($signature)) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        if (($parts['ts'] ?? '') === '' || ($parts['v1'] ?? '') === '') {
            return false;
        }

        // Alphanumeric ids are signed in lower case.
        $id = ctype_alnum($dataId) ? Str::lower($dataId) : $dataId;
        $manifest = ($id !== '' ? "id:{$id};" : '')
            .(filled($requestId) ? "request-id:{$requestId};" : '')
            ."ts:{$parts['ts']};";

        return hash_equals(hash_hmac('sha256', $manifest, (string) $this->webhookSecret), $parts['v1']);
    }

    /**
     * The payments made on a checkout, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentsFor(string $accountId, string $reference): array
    {
        $results = $this->send(fn (PendingRequest $http) => $http
            ->withToken($this->token($accountId))
            ->get(self::API.'/v1/payments/search', [
                'external_reference' => $reference,
                'sort' => 'date_created',
                'criteria' => 'desc',
            ]))
            ->json('results');

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /** The restaurant's access token, renewed first when it is about to expire. */
    private function token(string $accountId): string
    {
        $restaurant = User::where('mercadopago_user_id', $accountId)
            ->whereNotNull('mercadopago_access_token')
            ->latest('updated_at')
            ->first(['id', 'mercadopago_user_id', 'mercadopago_access_token', 'mercadopago_refresh_token', 'mercadopago_token_expires_at']);

        if ($restaurant === null) {
            throw new PaymentGatewayException("Mercado Pago account {$accountId} is not linked.");
        }

        $expiresAt = $restaurant->mercadopago_token_expires_at;

        if ($restaurant->mercadopago_refresh_token !== null
            && ($expiresAt === null || $expiresAt->lt(now()->addDays(self::RENEW_DAYS)))) {
            try {
                $renewed = $this->renew((string) $restaurant->mercadopago_refresh_token);
                $restaurant->forceFill([
                    'mercadopago_access_token' => $renewed['access_token'],
                    'mercadopago_refresh_token' => $renewed['refresh_token'] ?? $restaurant->mercadopago_refresh_token,
                    'mercadopago_token_expires_at' => $renewed['expires_at'],
                ])->save();
            } catch (PaymentGatewayException $e) {
                // Still valid for a while: use it and try renewing next time.
                if ($expiresAt === null || $expiresAt->isPast()) {
                    throw $e;
                }
            }
        }

        return (string) $restaurant->mercadopago_access_token;
    }

    /**
     * @return array{user_id: string, access_token: string, refresh_token: string|null, expires_at: CarbonInterface}
     */
    private function renew(string $refreshToken): array
    {
        $this->ensureConfigured();

        return $this->tokens($this->send(fn (PendingRequest $http) => $http->post(self::API.'/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
        ])));
    }

    /**
     * @return array{user_id: string, access_token: string, refresh_token: string|null, expires_at: CarbonInterface}
     */
    private function tokens(Response $response): array
    {
        $accessToken = $response->json('access_token');
        $userId = $response->json('user_id');

        if (! is_string($accessToken) || $accessToken === '' || $userId === null) {
            throw new PaymentGatewayException('Mercado Pago did not return a token.');
        }

        $refreshToken = $response->json('refresh_token');

        return [
            'user_id' => (string) $userId,
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 15552000)),
        ];
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request(Http::acceptJson()->asJson()->timeout(15)->connectTimeout(5));
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException('Mercado Pago unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();

            throw new PaymentGatewayException('Mercado Pago refused ('.$response->status().'): '.(is_string($message) ? $message : 'error'), $response->status());
        }

        return $response;
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new PaymentGatewayException('Mercado Pago is not configured (MERCADOPAGO_CLIENT_ID / MERCADOPAGO_CLIENT_SECRET).');
        }
    }

    private static function date(CarbonInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s.vP');
    }
}
