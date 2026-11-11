<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TenantRole;
use App\Models\AudienceGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('platform.admin_email');
        $password = config('platform.admin_password');

        if (! $email || ! $password) {
            throw new RuntimeException('Set ADMIN_EMAIL and ADMIN_PASSWORD in .env before seeding.');
        }

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'rene-bates'],
            [
                'name' => 'Rene Bates Auctioneers',
                'from_name' => 'Rene Bates Auctioneers',
                'timezone' => 'America/Chicago',
            ],
        );

        app(TenantContext::class)->run($tenant, function (): void {
            foreach (['Bidders', 'Clients', 'Subscribers'] as $name) {
                AudienceGroup::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
            }
        });

        $admin = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Platform Admin', 'password' => $password],
        );
        $admin->forceFill(['is_super_admin' => true, 'email_verified_at' => $admin->email_verified_at ?? now()])->save();

        $admin->tenants()->syncWithoutDetaching([$tenant->getKey() => ['role' => TenantRole::Admin->value]]);
    }
}
