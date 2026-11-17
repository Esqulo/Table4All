<?php

namespace App\Models;

use App\Enums\AccountType;
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
 * @property AccountType $account_type
 * @property int|null $restaurant_id
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'phone', 'avatar', 'logo', 'banner', 'handle', 'profile_public', 'account_type', 'password', 'restaurant_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'search_name'])]
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
        });
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

    public function effectiveRestaurantId(): int
    {
        return $this->account_type === AccountType::WAITER
            ? (int) $this->restaurant_id
            : $this->id;
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
        ];
    }
}
