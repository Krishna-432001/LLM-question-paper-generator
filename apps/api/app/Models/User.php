<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;
    use HasRoles;
    use HasUuidV7;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
            // PII at rest: transparently encrypted, never queried directly.
            'phone' => 'encrypted',
            'mfa_secret' => 'encrypted',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot(['status', 'is_default', 'joined_at']);
    }

    public function preferredLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'preferred_language_id');
    }

    /**
     * The tenant this user lands in when no organization is specified.
     * Every user has one, including individual students, whose personal
     * organization is provisioned at registration.
     */
    public function defaultOrganization(): ?Organization
    {
        $membership = $this->memberships()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('joined_at')
            ->first();

        return $membership?->organization;
    }

    public function organizationIfMember(string $organizationId): ?Organization
    {
        return $this->memberships()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first()?->organization;
    }

    public function membershipFor(string $organizationId): ?OrganizationUser
    {
        return $this->memberships()->where('organization_id', $organizationId)->first();
    }

    public function belongsToOrg(?string $organizationId): bool
    {
        return $organizationId !== null
            && $this->memberships()->where('organization_id', $organizationId)->exists();
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }
}
