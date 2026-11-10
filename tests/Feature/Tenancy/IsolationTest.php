<?php

declare(strict_types=1);

/*
| Tenant-isolation suite. Two tenants with overlapping data. This must pass in CI.
| Extend it whenever you add a model, route, export or job.
| It uses a test-only tenant-owned table so Phase 0 needs no product tables.
*/

use App\Exceptions\MissingTenantContext;
use App\Jobs\TenantJob;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class IsolationItem extends Model
{
    use BelongsToTenant;

    protected $table = 'isolation_items';

    protected $guarded = [];
}

class IsolationJob extends TenantJob
{
    public static array $seen = [];

    public function process(): void
    {
        self::$seen[] = IsolationItem::pluck('email')->all();
    }
}

beforeEach(function () {
    Schema::create('isolation_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
        $table->string('email');
        $table->timestamps();
        $table->unique(['tenant_id', 'email']); // every unique index includes tenant_id
    });

    $this->a = makeTenant(['name' => 'Tenant A']);
    $this->b = makeTenant(['name' => 'Tenant B']);

    // Overlapping data: the same email lives independently in both tenants.
    inTenant($this->a, fn () => IsolationItem::create(['email' => 'same@example.com']));
    inTenant($this->a, fn () => IsolationItem::create(['email' => 'only-a@example.com']));
    inTenant($this->b, fn () => IsolationItem::create(['email' => 'same@example.com']));
    inTenant($this->b, fn () => IsolationItem::create(['email' => 'only-b@example.com']));

    IsolationJob::$seen = [];
});

afterEach(function () {
    Schema::dropIfExists('isolation_items');
});

it('only returns the current tenant\'s rows', function () {
    expect(inTenant($this->a, fn () => IsolationItem::orderBy('email')->pluck('email')->all()))
        ->toBe(['only-a@example.com', 'same@example.com']);
    expect(inTenant($this->b, fn () => IsolationItem::orderBy('email')->pluck('email')->all()))
        ->toBe(['only-b@example.com', 'same@example.com']);
});

it('cannot reach another tenant\'s row by id', function () {
    $bId = inTenant($this->b, fn () => IsolationItem::where('email', 'only-b@example.com')->value('id'));

    expect(inTenant($this->a, fn () => IsolationItem::find($bId)))->toBeNull();
});

it('cannot update or delete another tenant\'s rows through the scope', function () {
    inTenant($this->a, function () {
        IsolationItem::where('email', 'same@example.com')->update(['email' => 'hacked@example.com']);
        IsolationItem::where('email', 'only-a@example.com')->delete();
    });

    // Tenant B is untouched, including its copy of the overlapping address.
    expect(inTenant($this->b, fn () => IsolationItem::orderBy('email')->pluck('email')->all()))
        ->toBe(['only-b@example.com', 'same@example.com']);
});

it('stamps tenant_id from the context on create', function () {
    $item = inTenant($this->a, fn () => IsolationItem::create(['email' => 'new@example.com']));

    expect($item->tenant_id)->toBe($this->a->id);
});

it('refuses to create a row for a different tenant than the context', function () {
    inTenant($this->a, fn () => IsolationItem::create(['email' => 'x@example.com', 'tenant_id' => $this->b->id]));
})->throws(MissingTenantContext::class);

it('enforces uniqueness per tenant, not globally', function () {
    // same@example.com exists in both tenants (set up above); a duplicate inside one tenant fails.
    inTenant($this->a, fn () => IsolationItem::create(['email' => 'same@example.com']));
})->throws(QueryException::class);

it('fails closed when querying with no tenant context', function () {
    IsolationItem::count();
})->throws(MissingTenantContext::class);

it('fails closed when creating with no tenant context', function () {
    IsolationItem::create(['email' => 'orphan@example.com']);
})->throws(MissingTenantContext::class);

it('restores the previous context after run()', function () {
    $context = app(TenantContext::class);
    $context->set($this->a);

    $context->run($this->b, fn () => expect($context->id())->toBe($this->b->id));

    expect($context->id())->toBe($this->a->id);
});

it('allows an explicit, commented bypass of the scope only when asked for', function () {
    // Super-admin style cross-tenant read: must be deliberate.
    expect(IsolationItem::withoutGlobalScope(TenantScope::class)->count())->toBe(4);
});

it('refuses to dispatch a tenant job without tenant context', function () {
    new IsolationJob;
})->throws(MissingTenantContext::class);

it('captures the tenant at dispatch and restores it when the job runs', function () {
    $job = inTenant($this->a, fn () => new IsolationJob);

    // A worker has no tenant context before the job runs.
    app(TenantContext::class)->forget();
    $job->handle();

    expect(IsolationJob::$seen)->toHaveCount(1)
        ->and(IsolationJob::$seen[0])->toEqualCanonicalizing(['same@example.com', 'only-a@example.com'])
        ->and(app(TenantContext::class)->has())->toBeFalse();
});

it('fails loudly when the job\'s tenant no longer exists', function () {
    $job = new IsolationJob($this->b->id);
    Tenant::whereKey($this->b->id)->delete();

    $job->handle();
})->throws(MissingTenantContext::class);

it('runs two tenants\' jobs back to back without mixing data', function () {
    $jobA = new IsolationJob($this->a->id);
    $jobB = new IsolationJob($this->b->id);

    $jobA->handle();
    $jobB->handle();

    expect(IsolationJob::$seen[0])->toContain('only-a@example.com')->not->toContain('only-b@example.com')
        ->and(IsolationJob::$seen[1])->toContain('only-b@example.com')->not->toContain('only-a@example.com');
});
