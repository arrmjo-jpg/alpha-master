<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Users\Enums\AccountType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * account_type and the status columns are deliberately NOT fillable: they change
 * only through the dedicated Users actions (ADR-0002).
 *
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property AccountType $account_type
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $banned_at
 * @property string|null $ban_reason
 * @property string|null $preferred_locale
 */
#[Fillable(['name', 'username', 'email', 'password', 'preferred_locale'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Single guard for all roles/permissions (ADR-0003 §5, ADR-0004 §1). */
    protected string $guard_name = 'web';

    protected $attributes = [
        'account_type' => 'user',
    ];

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
            'account_type' => AccountType::class,
            'deactivated_at' => 'datetime',
            'banned_at' => 'datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    protected function username(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    public function isAdmin(): bool
    {
        return $this->account_type === AccountType::Admin;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /** Usable = may authenticate and act (not banned, not deactivated). */
    public function isUsable(): bool
    {
        return ! $this->isBanned() && ! $this->isDeactivated();
    }

    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('banned_at')->whereNull('deactivated_at');
    }
}
