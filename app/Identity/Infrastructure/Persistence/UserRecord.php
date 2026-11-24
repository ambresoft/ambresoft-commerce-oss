<?php

namespace App\Identity\Infrastructure\Persistence;

use App\Authorization\Infrastructure\Persistence\RoleRecord;
use App\Identity\Domain\Model\PasswordPolicy;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

/** @property bool $staff_password Whether the current password meets the staff minimum (administration requires it). */
final class UserRecord extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'immutable_datetime', 'is_active' => 'boolean', 'staff_password' => 'boolean', 'preferred_locale_id' => 'integer'];
    }

    /** @return BelongsToMany<RoleRecord, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RoleRecord::class, 'role_user', 'user_id', 'role_id');
    }

    public function preferredLocale(): string
    {
        return app(LocaleCatalog::class)->forPreference($this->preferred_locale_id);
    }

    /**
     * Hashes a plain password and records whether it meets the staff minimum, wherever it is set (registration,
     * reset, staff administration). Already hashed values (rehash on login) keep the recorded strength.
     *
     * @return Attribute<string, string>
     */
    protected function password(): Attribute
    {
        return Attribute::make(set: fn (string $value): array => Hash::isHashed($value) ? ['password' => $value] : ['password' => Hash::make($value), 'staff_password' => PasswordPolicy::meetsStaffMinimum($value)]);
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }
}
