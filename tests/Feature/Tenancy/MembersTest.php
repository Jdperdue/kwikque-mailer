<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->a = makeTenant(['name' => 'Tenant A']);
    $this->b = makeTenant(['name' => 'Tenant B']);
    $this->super = makeSuperAdmin();
});

it('is platform-admin only, including for a tenant admin', function () {
    $tenantAdmin = makeMember($this->a, TenantRole::Admin);

    $this->get(route('platform.tenants.members', $this->a))->assertRedirect('/login');
    $this->actingAs($tenantAdmin)->get(route('platform.tenants.members', $this->a))->assertForbidden();
    $this->actingAs($this->super)->get(route('platform.tenants.members', $this->a))->assertOk()->assertSee('Add a member');
});

it('creates a login with a one-time temporary password scoped to the one tenant', function () {
    $this->actingAs($this->super);

    $c = Volt::test('pages.platform.members', ['tenant' => $this->a])
        ->set('email', '  New.Admin@Example.com ')->set('name', 'New Admin')->set('role', 'admin')
        ->call('add')->assertHasNoErrors();

    $password = $c->get('tempPassword');
    $user = User::where('email', 'new.admin@example.com')->firstOrFail();

    expect($password)->toBeString()->toHaveLength(16)
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->is_super_admin)->toBeFalse()
        ->and($user->roleIn($this->a))->toBe(TenantRole::Admin)
        ->and($user->roleIn($this->b))->toBeNull()
        ->and(inTenant($this->a, fn () => AuditLog::where('action', 'member.added')->count()))->toBe(1)
        ->and(json_encode(inTenant($this->a, fn () => AuditLog::all()->toArray())))->not->toContain($password);

    // The password disappears on the next action.
    $c->set('email', 'other@example.com')->set('name', 'Other')->call('add');
    expect($c->get('tempPassword'))->not->toBe($password);
});

it('attaches an existing user without touching their password, and rejects duplicates and platform admins', function () {
    $existing = makeMember($this->b, TenantRole::Viewer);
    $hash = $existing->password;
    $this->actingAs($this->super);

    $c = Volt::test('pages.platform.members', ['tenant' => $this->a])
        ->set('email', $existing->email)->set('role', 'operator')->call('add')->assertHasNoErrors();

    expect($c->get('tempPassword'))->toBeNull()
        ->and($existing->fresh()->password)->toBe($hash)
        ->and($existing->fresh()->roleIn($this->a))->toBe(TenantRole::Operator)
        ->and($existing->fresh()->roleIn($this->b))->toBe(TenantRole::Viewer);

    $c->set('email', $existing->email)->call('add')->assertHasErrors('email');
    $c->set('email', $this->super->email)->call('add')->assertHasErrors('email');
    $c->set('email', 'new@example.com')->set('name', '')->call('add')->assertHasErrors('name');
});

it('changes roles and removes members only within the tenant', function () {
    $mine = makeMember($this->a, TenantRole::Viewer);
    $theirs = makeMember($this->b, TenantRole::Viewer);
    $this->actingAs($this->super);

    $c = Volt::test('pages.platform.members', ['tenant' => $this->a]);
    $c->call('setRole', $mine->id, 'admin');
    expect($mine->fresh()->roleIn($this->a))->toBe(TenantRole::Admin);

    expect(fn () => $c->call('setRole', $theirs->id, 'admin'))->toThrow(ModelNotFoundException::class);
    expect(fn () => $c->call('remove', $theirs->id))->toThrow(ModelNotFoundException::class);
    expect($theirs->fresh()->roleIn($this->b))->toBe(TenantRole::Viewer);

    $c->call('remove', $mine->id);
    expect($mine->fresh()->roleIn($this->a))->toBeNull()->and(User::whereKey($mine->id)->exists())->toBeTrue()
        ->and(inTenant($this->a, fn () => AuditLog::whereIn('action', ['member.role_changed', 'member.removed'])->count()))->toBe(2);
});

it('refuses a non-super-admin driving the component directly', function () {
    $tenantAdmin = makeMember($this->a, TenantRole::Admin);
    $this->actingAs($tenantAdmin);

    Volt::test('pages.platform.members', ['tenant' => $this->a])->assertForbidden();
});
