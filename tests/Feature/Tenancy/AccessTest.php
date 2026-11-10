<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

it('shows a tenant member their own tenant', function () {
    $tenant = makeTenant(['name' => 'Acme Auctions']);

    $this->actingAs(makeMember($tenant))->get('/dashboard')->assertOk()->assertSee('Acme Auctions');
});

it('denies a user with no tenant membership', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertForbidden();
});

it('does not let a member switch into a tenant they do not belong to', function () {
    $mine = makeTenant();
    $other = makeTenant(['name' => 'Someone Else']);
    $user = makeMember($mine);

    $this->actingAs($user)->post('/tenant/switch', ['tenant_id' => $other->id])->assertForbidden();

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('Someone Else');
});

it('ignores a tampered session tenant id', function () {
    $mine = makeTenant();
    $other = makeTenant(['name' => 'Someone Else']);

    $this->actingAs(makeMember($mine))
        ->withSession(['tenant_id' => $other->id])
        ->get('/dashboard')->assertOk()->assertDontSee('Someone Else');
});

it('lets a member of two tenants switch between them', function () {
    $a = makeTenant(['name' => 'Alpha']);
    $b = makeTenant(['name' => 'Bravo']);
    $user = makeMember($a);
    $user->tenants()->attach($b, ['role' => 'viewer']);

    $this->actingAs($user)->post('/tenant/switch', ['tenant_id' => $b->id])->assertRedirect('/dashboard');
    $this->actingAs($user)->get('/dashboard')->assertSee('Bravo');
});

it('does not give a suspended tenant to its members', function () {
    $tenant = makeTenant(['status' => 'suspended']);

    $this->actingAs(makeMember($tenant))->get('/dashboard')->assertForbidden();
});

it('lets a super-admin switch into any tenant', function () {
    $tenant = makeTenant(['name' => 'Anywhere']);
    $admin = makeSuperAdmin();

    $this->actingAs($admin)->post('/tenant/switch', ['tenant_id' => $tenant->id])->assertRedirect('/dashboard');
    $this->actingAs($admin)->get('/dashboard')->assertSee('Anywhere');
});

it('sends a super-admin with no tenant selected to the tenants page', function () {
    $this->actingAs(makeSuperAdmin())->get('/dashboard')->assertRedirect(route('platform.tenants'));
});

it('forbids the platform area and Horizon to non-super-admins', function () {
    $user = makeMember(makeTenant(), TenantRole::Admin);

    $this->actingAs($user)->get('/platform/tenants')->assertForbidden();
    $this->actingAs($user)->get('/horizon')->assertForbidden();
});

it('requires login for the platform area', function () {
    $this->get('/platform/tenants')->assertRedirect('/login');
});

it('enforces per-tenant roles', function () {
    Route::middleware(['web', 'auth', 'tenant.required', 'role:operator'])->get('/_t/operator', fn () => 'ok');

    $tenant = makeTenant();

    $this->actingAs(makeMember($tenant, TenantRole::Viewer))->get('/_t/operator')->assertForbidden();
    $this->actingAs(makeMember($tenant, TenantRole::Operator))->get('/_t/operator')->assertOk();
    $this->actingAs(makeMember($tenant, TenantRole::Admin))->get('/_t/operator')->assertOk();
});

it('scopes roles per tenant: admin in one tenant is not admin in another', function () {
    $a = makeTenant();
    $b = makeTenant();
    $user = makeMember($a, TenantRole::Admin);
    $user->tenants()->attach($b, ['role' => TenantRole::Viewer->value]);

    expect($user->roleIn($a))->toBe(TenantRole::Admin)
        ->and($user->roleIn($b))->toBe(TenantRole::Viewer)
        ->and($user->roleIn(makeTenant()))->toBeNull();
});

it('lets a super-admin create a second tenant', function () {
    $this->actingAs(makeSuperAdmin());

    Volt::test('pages.platform.tenants')
        ->set('name', 'Second Tenant')
        ->set('timezone', 'America/New_York')
        ->set('mailgun_domain', 'Mail.Second.Example')
        ->call('create')
        ->assertHasNoErrors();

    $tenant = Tenant::where('slug', 'second-tenant')->first();
    expect($tenant)->not->toBeNull()
        ->and($tenant->mailgun_domain)->toBe('mail.second.example')
        ->and($tenant->domain_status)->toBe('unverified');
});

it('rejects tenant creation by a non-super-admin', function () {
    $this->actingAs(makeMember(makeTenant()));

    Volt::test('pages.platform.tenants')->set('name', 'Nope')->call('create')->assertForbidden();

    expect(Tenant::where('name', 'Nope')->exists())->toBeFalse();
});

it('rejects a duplicate sending domain', function () {
    makeTenant(['mailgun_domain' => 'mail.taken.example']);
    $this->actingAs(makeSuperAdmin());

    Volt::test('pages.platform.tenants')
        ->set('name', 'Dup')
        ->set('mailgun_domain', 'mail.taken.example')
        ->call('create')
        ->assertHasErrors(['mailgun_domain']);
});

it('does not leave tenant context behind after a request', function () {
    $this->actingAs(makeMember(makeTenant()))->get('/dashboard')->assertOk();

    expect(app(TenantContext::class)->has())->toBeFalse();
});

it('has no public registration route', function () {
    $this->get('/register')->assertNotFound();
});

it('seeds Rene Bates and a super-admin from env, and is idempotent', function () {
    config(['platform.admin_email' => 'admin@app.kwikque.com', 'platform.admin_password' => 'a-long-test-password-1']);

    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@app.kwikque.com')->sole();
    expect($admin->is_super_admin)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Tenant::where('slug', 'rene-bates')->count())->toBe(1)
        ->and($admin->tenants()->count())->toBe(1);
});

it('refuses to seed without ADMIN_PASSWORD', function () {
    config(['platform.admin_email' => 'admin@app.kwikque.com', 'platform.admin_password' => null]);

    $this->seed(DatabaseSeeder::class);
})->throws(RuntimeException::class);
