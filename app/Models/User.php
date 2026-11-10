<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TenantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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
            'is_super_admin' => 'boolean',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')->withPivot('role')->withTimestamps();
    }

    /** Tenants this user may act in (super-admins: every active tenant). */
    public function accessibleTenants(): Collection
    {
        $query = $this->is_super_admin ? Tenant::query() : $this->tenants();

        return $query->where('tenants.status', 'active')->orderBy('tenants.name')->get();
    }

    public function roleIn(Tenant $tenant): ?TenantRole
    {
        if ($this->is_super_admin) {
            return TenantRole::Admin;
        }

        $role = $this->tenants()->where('tenants.id', $tenant->getKey())->first()?->pivot->role;

        return $role === null ? null : TenantRole::from($role);
    }
}
