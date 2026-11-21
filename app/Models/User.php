<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\Permission;
use App\Enums\StaffFeature;
use App\Enums\StaffRole;
use App\Jobs\GeocodeRestaurant;
use App\Support\RestaurantHandle;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $search_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $avatar
 * @property string|null $logo
 * @property string|null $banner
 * @property string|null $handle
 * @property bool $profile_public
 * @property string|null $address
 * @property string|null $ifood_url
 * @property int|null $price_level
 * @property int|null $table_capacity
 * @property bool $table_assignment_enabled
 * @property string|null $service_fee_type 'fixed' or 'percent' (App\Support\ServiceFee), null for none
 * @property string|null $service_fee_value
 * @property string|null $service_fee_cap
 * @property float|null $latitude
 * @property float|null $longitude
 * @property AccountType $account_type
 * @property int|null $restaurant_id
 * @property StaffRole|null $staff_role
 * @property array<string, string>|null $staff_permissions
 * @property string|null $stripe_account_id
 * @property bool $stripe_charges_enabled
 * @property bool $stripe_details_submitted
 * @property string|null $fee_tier
 * @property bool $platform_pays_stripe_fees
 * @property string|null $fee_percent_override The restaurant's own commission, over its tier's
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $terms_accepted_at
 * @property string|null $terms_version
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'avatar', 'logo', 'banner', 'handle', 'profile_public', 'address', 'ifood_url', 'price_level', 'table_capacity', 'account_type', 'password', 'restaurant_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'search_name', 'search_vector', 'stripe_account_id'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            // Every restaurant has a public @handle; it starts from its name and the owner can change it.
            if ($user->account_type === AccountType::RESTAURANT && blank($user->handle)) {
                $user->handle = RestaurantHandle::generate($user->name, $user->id);
            }

            // Accent-free copy of the name for the restaurant search ("cafe" finds "Café").
            if ($user->isDirty('name') || $user->search_name === null) {
                $user->search_name = self::searchable((string) $user->name);
            }

            // The coordinates belong to the old address: "closest first" must not use them.
            if ($user->isDirty('address') && $user->exists) {
                $user->latitude = null;
                $user->longitude = null;
            }
        });

        static::saved(function (User $user): void {
            // Finds the new address on the map, on the queue (an outside service, seconds away).
            if ($user->account_type === AccountType::RESTAURANT && $user->wasChanged('address') && filled($user->address)) {
                GeocodeRestaurant::dispatch($user->id)->afterCommit();
            }
        });
    }

    /** Proof of consent (LGPD Art. 8 §2): when and which version of the terms was accepted. */
    public function recordTermsAcceptance(): void
    {
        $this->forceFill([
            'terms_accepted_at' => now(),
            'terms_version' => (string) config('legal.version'),
        ])->save();
    }

    /** Lowercase without accents: how names and search terms are compared. */
    public static function searchable(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    /** Whether $viewer can see this restaurant's public profile and its posts. */
    public function publicProfileVisibleTo(?User $viewer): bool
    {
        if ($this->account_type !== AccountType::RESTAURANT) {
            return false;
        }

        return $this->profile_public
            || ($viewer !== null && $viewer->account_type === AccountType::RESTAURANT && $viewer->id === $this->id);
    }

    /** A member of a restaurant's team (waiter or manager), still linked to it. */
    public function isStaff(): bool
    {
        return $this->account_type === AccountType::WAITER && $this->restaurant_id !== null;
    }

    /** Waiter unless made a manager; accounts from before roles existed are waiters. */
    public function staffRole(): StaffRole
    {
        return $this->staff_role ?? StaffRole::WAITER;
    }

    public function isManager(): bool
    {
        return $this->isStaff() && $this->staffRole() === StaffRole::MANAGER;
    }

    /**
     * What was granted to this staff member per feature, before the role is applied.
     * Null (never set) means the waiter defaults.
     *
     * @return array<string, string>
     */
    public function grantedPermissions(): array
    {
        return $this->staff_permissions ?? StaffFeature::waiterDefaults();
    }

    /**
     * How far this account can go in a feature of its restaurant: the owner writes
     * everywhere; staff get what was granted, managers at least reading everything,
     * never above the feature's ceiling for staff; anyone else nothing.
     */
    public function access(StaffFeature $feature): Permission
    {
        if ($this->account_type === AccountType::RESTAURANT) {
            return Permission::WRITE;
        }

        if (! $this->isStaff()) {
            return Permission::NONE;
        }

        $manager = $this->staffRole() === StaffRole::MANAGER;

        if ($feature === StaffFeature::STAFF) {
            return $manager ? Permission::WRITE : Permission::NONE;
        }

        $granted = Permission::tryFrom((string) ($this->grantedPermissions()[$feature->value] ?? '')) ?? Permission::NONE;

        if ($manager) {
            $granted = Permission::max($granted, Permission::READ);
        }

        return Permission::min($granted, $feature->staffCeiling());
    }

    /**
     * access() for every feature, for the browser (sidebar, read-only screens).
     *
     * @return array<string, string>
     */
    public function accessMap(): array
    {
        $map = [];

        foreach (StaffFeature::cases() as $feature) {
            $map[$feature->value] = $this->access($feature)->value;
        }

        return $map;
    }

    /** The restaurant account this one works for: itself for the owner, the owner for staff. */
    public function restaurantAccount(): User
    {
        if ($this->account_type === AccountType::RESTAURANT) {
            return $this;
        }

        /** @var User $owner */
        $owner = $this->restaurant()->firstOrFail();

        return $owner;
    }

    public function effectiveRestaurantId(): int
    {
        return $this->account_type === AccountType::WAITER
            ? (int) $this->restaurant_id
            : $this->id;
    }

    /** Whether customers can pay this restaurant's tables in the app. */
    public function acceptsOnlinePayments(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_charges_enabled;
    }

    /** The restaurant's tier, falling back to the default when unset or no longer offered. */
    public function feeTier(): string
    {
        return $this->fee_tier !== null && FeeTier::where('key', $this->fee_tier)->exists()
            ? $this->fee_tier
            : FeeTier::defaultKey();
    }

    /**
     * The platform's share of this restaurant's online payments, in basis points (4.5% = 450):
     * its own commission when the admin set one, otherwise its tier's.
     */
    public function feeRateBps(): int
    {
        $percent = $this->fee_percent_override
            ?? FeeTier::where('key', $this->feeTier())->value('fee_percent')
            ?? 0;

        return (int) round(((float) $percent) * 100);
    }

    /** @return HasMany<User, $this> */
    public function waiters(): HasMany
    {
        return $this->hasMany(User::class, 'restaurant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restaurant_id');
    }

    /** @return HasMany<RestaurantOpeningHour, $this> */
    public function openingHours(): HasMany
    {
        return $this->hasMany(RestaurantOpeningHour::class);
    }

    /** @return HasMany<RestaurantPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(RestaurantPhoto::class);
    }

    /** @return HasMany<RestaurantPost, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(RestaurantPost::class);
    }

    /**
     * Reviews this restaurant received.
     *
     * @return HasMany<RestaurantReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(RestaurantReview::class, 'restaurant_id');
    }

    protected $appends = ['avatar_url', 'logo_url', 'banner_url'];

    /** @return Attribute<string|null, never> */
    protected function avatarUrl(): Attribute
    {
        /** @phpstan-ignore return.type */
        return Attribute::get(
            fn (): ?string => $this->avatar ? Storage::disk('public')->url($this->avatar) : null
        );
    }

    /** @return Attribute<string|null, never> */
    protected function logoUrl(): Attribute
    {
        /** @phpstan-ignore return.type */
        return Attribute::get(
            fn (): ?string => $this->logo ? Storage::disk('public')->url($this->logo) : null
        );
    }

    /** @return Attribute<string|null, never> */
    protected function bannerUrl(): Attribute
    {
        /** @phpstan-ignore return.type */
        return Attribute::get(
            fn (): ?string => $this->banner ? Storage::disk('public')->url($this->banner) : null
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'account_type' => AccountType::class,
            'profile_public' => 'boolean',
            'stripe_charges_enabled' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            'platform_pays_stripe_fees' => 'boolean',
            'fee_percent_override' => 'decimal:2',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'staff_role' => StaffRole::class,
            'staff_permissions' => 'array',
            'price_level' => 'integer',
            'table_capacity' => 'integer',
            'table_assignment_enabled' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}
