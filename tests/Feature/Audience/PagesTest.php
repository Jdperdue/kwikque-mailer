<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Http\Middleware\ResolveTenant;
use App\Models\AudienceGroup;
use App\Models\AuditLog;
use App\Models\ContentItem;
use App\Models\ImportRun;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Models\TenantSource;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->a = makeTenant(['name' => 'Tenant A', 'timezone' => 'America/Chicago']);
    $this->b = makeTenant(['name' => 'Tenant B']);
    $this->groupA = inTenant($this->a, fn () => AudienceGroup::create(['name' => 'Bidders', 'slug' => 'bidders']));
    $this->groupB = inTenant($this->b, fn () => AudienceGroup::create(['name' => 'Bidders', 'slug' => 'bidders']));
    $this->operator = makeMember($this->a, TenantRole::Operator);
    $this->viewer = makeMember($this->a, TenantRole::Viewer);
});

it('renders every Phase 1 page for a tenant member', function (string $route) {
    $this->actingAs($this->viewer)->get(route($route))->assertOk();
})->with(['audience', 'audience.import', 'audience.suppressions', 'content']);

it('requires login and a tenant for the Phase 1 pages', function () {
    $this->get('/audience')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get('/audience')->assertForbidden();
});

it('lets viewers read but not write', function () {
    actingInTenant($this->viewer, $this->a);

    Volt::test('pages.audience.index')
        ->set('newEmail', 'v@example.com')->set('newGroup', (string) $this->groupA->id)
        ->call('addRecipient')->assertForbidden();

    Volt::test('pages.audience.suppressions')->set('email', 'v@example.com')->call('add')->assertForbidden();
    Volt::test('pages.content.index')->set('title', 'X')->call('save')->assertForbidden();

    expect(inTenant($this->a, fn () => Recipient::count()))->toBe(0)
        ->and(inTenant($this->a, fn () => Suppression::count()))->toBe(0)
        ->and(inTenant($this->a, fn () => ContentItem::count()))->toBe(0);
});

it('adds a recipient manually and pastes a list in several formats, reporting bad lines', function () {
    actingInTenant($this->operator, $this->a);

    Volt::test('pages.audience.index')
        ->set('newEmail', ' Manual@Example.com ')->set('newName', 'Man Ual')->set('newGroup', (string) $this->groupA->id)
        ->call('addRecipient')
        ->set('pasteGroup', (string) $this->groupA->id)
        ->set('pasteText', "one@example.com\ntwo@example.com, Two Person\nThree Person <three@example.com>\nnot-an-email\nONE@example.com\nfour@example.com;five@example.com")
        ->call('paste')
        ->assertSee('Added 5')->assertSee('invalid 1')->assertSee('duplicate 1');

    inTenant($this->a, function () {
        expect(Recipient::count())->toBe(6)
            ->and(Recipient::where('email', 'manual@example.com')->sole()->name)->toBe('Man Ual')
            ->and(Recipient::where('email', 'three@example.com')->sole()->name)->toBe('Three Person')
            ->and(Recipient::where('email', 'two@example.com')->sole()->name)->toBe('Two Person')
            ->and(Recipient::where('email', 'five@example.com')->exists())->toBeTrue();
    });
    expect(inTenant($this->b, fn () => Recipient::count()))->toBe(0);
});

it('keeps opt-outs through remove and re-add via the UI', function () {
    actingInTenant($this->operator, $this->a);
    $c = Volt::test('pages.audience.index')
        ->set('newEmail', 'gone@example.com')->set('newGroup', (string) $this->groupA->id)->call('addRecipient');

    $id = inTenant($this->a, fn () => Recipient::where('email', 'gone@example.com')->value('id'));
    $c->call('optOut', $id)->call('remove', $id)
        ->set('newEmail', 'gone@example.com')->set('newGroup', (string) $this->groupA->id)->call('addRecipient');

    $r = inTenant($this->a, fn () => Recipient::where('email', 'gone@example.com')->sole());
    expect($r->isMailable())->toBeFalse()
        ->and(inTenant($this->a, fn () => Suppression::where('email', 'gone@example.com')->exists()))->toBeTrue();
    expect(inTenant($this->a, fn () => AuditLog::where('action', 'recipient.removed')->count()))->toBe(1);
});

it('edits a recipient name and group membership', function () {
    actingInTenant($this->operator, $this->a);
    $second = inTenant($this->a, fn () => AudienceGroup::create(['name' => 'Clients', 'slug' => 'clients']));
    $c = Volt::test('pages.audience.index')
        ->set('newEmail', 'edit@example.com')->set('newGroup', (string) $this->groupA->id)->call('addRecipient');
    $id = inTenant($this->a, fn () => Recipient::sole()->id);

    $c->call('startEdit', $id)->set('editName', 'Edited')->set('editGroups', [(string) $second->id])->call('saveEdit');

    inTenant($this->a, function () use ($second) {
        $r = Recipient::sole();
        expect($r->name)->toBe('Edited')->and($r->groups()->pluck('audience_groups.id')->all())->toBe([$second->id]);
    });
});

it('cannot touch another tenant\'s recipient from the UI', function () {
    $other = inTenant($this->b, fn () => Recipient::create(['email' => 'theirs@example.com']));
    actingInTenant($this->operator, $this->a);

    expect(fn () => Volt::test('pages.audience.index')->call('remove', $other->id))
        ->toThrow(ModelNotFoundException::class);

    expect(inTenant($this->b, fn () => Recipient::count()))->toBe(1);
});

it('only lists the current tenant\'s recipients and groups', function () {
    inTenant($this->a, fn () => Recipient::create(['email' => 'mine@example.com']));
    inTenant($this->b, fn () => Recipient::create(['email' => 'theirs@example.com']));

    $this->actingAs($this->viewer)->get('/audience')->assertSee('mine@example.com')->assertDontSee('theirs@example.com');
});

it('uploads a CSV, queues the import and shows it in history', function () {
    Storage::fake('local');
    actingInTenant($this->operator, $this->a);

    $file = UploadedFile::fake()->createWithContent('bidders.csv', "email,name\nup1@example.com,Up One\nbad\n");

    Volt::test('pages.audience.import')
        ->set('file', $file)->set('groupId', (string) $this->groupA->id)
        ->call('upload')->assertHasNoErrors();

    $run = inTenant($this->a, fn () => ImportRun::sole());
    expect($run->status)->toBe('completed') // queue is sync under test
        ->and($run->created_count)->toBe(1)->and($run->invalid_count)->toBe(1)
        ->and($run->filename)->toBe('bidders.csv')
        ->and($run->file_hash)->toHaveLength(64);

    $this->actingAs($this->operator)->get(route('audience.imports.show', $run->id))->assertOk()->assertSee('invalid_email');
});

it('rejects a non-CSV upload and a missing group', function () {
    Storage::fake('local');
    actingInTenant($this->operator, $this->a);

    Volt::test('pages.audience.import')
        ->set('file', UploadedFile::fake()->image('x.png'))->set('groupId', (string) $this->groupA->id)
        ->call('upload')->assertHasErrors(['file']);

    Volt::test('pages.audience.import')
        ->set('file', UploadedFile::fake()->createWithContent('a.csv', "email\na@example.com\n"))
        ->call('upload')->assertHasErrors(['groupId']);
});

it('404s on another tenant\'s import run instead of leaking it', function () {
    $run = inTenant($this->b, fn () => ImportRun::create(['audience_group_id' => $this->groupB->id, 'filename' => 'secret.csv']));

    $this->actingAs($this->viewer)->get(route('audience.imports.show', $run->id))->assertNotFound();
});

it('refuses to import into another tenant\'s group', function () {
    Storage::fake('local');
    actingInTenant($this->operator, $this->a);

    expect(fn () => Volt::test('pages.audience.import')
        ->set('file', UploadedFile::fake()->createWithContent('a.csv', "email\na@example.com\n"))
        ->set('groupId', (string) $this->groupB->id)->call('upload'))
        ->toThrow(ModelNotFoundException::class);
});

it('blocks addresses manually and validates them', function () {
    actingInTenant($this->operator, $this->a);

    Volt::test('pages.audience.suppressions')->set('email', 'nope')->call('add')->assertHasErrors(['email']);
    Volt::test('pages.audience.suppressions')->set('email', ' Block@Example.com ')->set('note', 'asked by phone')->call('add')->assertHasNoErrors();

    $s = inTenant($this->a, fn () => Suppression::sole());
    expect($s->email)->toBe('block@example.com')->and($s->reason)->toBe('manual');
    expect(inTenant($this->b, fn () => Suppression::count()))->toBe(0);
});

it('creates, edits and removes content items, converting tenant-local times to UTC', function () {
    actingInTenant($this->operator, $this->a);

    $c = Volt::test('pages.content.index')
        ->set('title', 'Spring Equipment Auction')->set('type', 'auction')
        ->set('starts_at', '2026-12-01T10:00')->set('ends_at', '2026-12-02T18:30')
        ->set('url', 'https://example.com/a/1')
        ->call('save')->assertHasNoErrors();

    $item = inTenant($this->a, fn () => ContentItem::sole());
    // 10:00 America/Chicago in December (CST, UTC-6) = 16:00 UTC
    expect($item->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 16:00')
        ->and($item->source)->toBe('manual');

    $c->call('edit', $item->id)->assertSet('starts_at', '2026-12-01T10:00')
        ->set('title', 'Renamed')->call('save');
    expect(inTenant($this->a, fn () => ContentItem::sole()->title))->toBe('Renamed');

    $c->call('remove', $item->id);
    expect(inTenant($this->a, fn () => ContentItem::count()))->toBe(0);
});

it('validates content items', function () {
    actingInTenant($this->operator, $this->a);

    Volt::test('pages.content.index')->set('title', '')->call('save')->assertHasErrors(['title']);
    Volt::test('pages.content.index')->set('title', 'T')->set('url', 'javascript:alert(1)')->call('save')->assertHasErrors(['url']);
    Volt::test('pages.content.index')->set('title', 'T')->set('starts_at', '2026-12-02T10:00')->set('ends_at', '2026-12-01T10:00')->call('save')->assertHasErrors(['ends_at']);
    Volt::test('pages.content.index')->set('title', 'T')->set('type', 'bogus')->call('save')->assertHasErrors(['type']);
});

it('keeps content items per tenant', function () {
    inTenant($this->a, fn () => ContentItem::create(['title' => 'A item', 'source' => 'manual']));
    inTenant($this->b, fn () => ContentItem::create(['title' => 'B item', 'source' => 'manual']));

    $this->actingAs($this->viewer)->get('/content')->assertSee('A item')->assertDontSee('B item');
});

it('rejects an arbitrary encrypted tenant-source settings leak in serialization', function () {
    $src = inTenant($this->a, fn () => TenantSource::create(['kind' => 'audience', 'adapter' => 'X', 'settings' => ['token' => 'secret-value']]));

    expect(json_encode($src->fresh()))->not->toContain('secret-value')
        ->and(DB::table('tenant_sources')->value('settings'))->not->toContain('secret-value')
        ->and(inTenant($this->a, fn () => TenantSource::find($src->id)->settings['token']))->toBe('secret-value');
});

it('runs the tenant middleware on Livewire update requests (so components get tenant context)', function () {
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_contains((string) $r->getName(), 'livewire.update'));

    expect($route)->not->toBeNull();
    expect(app('router')->gatherRouteMiddleware($route))->toContain(ResolveTenant::class);
});
