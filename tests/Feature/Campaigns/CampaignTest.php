<?php

declare(strict_types=1);

use App\Enums\TenantRole;
use App\Models\AudienceGroup;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Services\CampaignRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->a = makeTenant([
        'name' => 'Rene Bates Auctioneers', 'slug' => 'tenant-a', 'timezone' => 'America/Chicago',
        'from_name' => 'Rene Bates', 'from_address' => 'auctions@example.com', 'postal_address' => "1 Main St\nWaco, TX 76701",
        'branding' => ['accent_color' => '#aa3300'],
    ]);
    $this->b = makeTenant(['name' => 'Tenant B', 'slug' => 'tenant-b', 'timezone' => 'UTC']);

    [$this->groupA, $this->item1, $this->item2, $this->item3] = inTenant($this->a, fn () => [
        AudienceGroup::create(['name' => 'Bidders', 'slug' => 'bidders']),
        ContentItem::create(['title' => 'First auction', 'url' => 'https://example.com/a1', 'description' => "Line one\nLine two", 'starts_at' => '2026-12-01 15:00:00', 'ends_at' => '2026-12-08 15:00:00']),
        ContentItem::create(['title' => 'Second <b>auction</b>', 'url' => 'javascript:alert(1)', 'image_url' => 'data:text/html;base64,AAAA']),
        ContentItem::create(['title' => 'Third auction', 'url' => 'https://example.com/a3']),
    ]);
    [$this->groupB, $this->itemB] = inTenant($this->b, fn () => [
        AudienceGroup::create(['name' => 'Bidders', 'slug' => 'bidders']),
        ContentItem::create(['title' => 'B auction']),
    ]);

    $this->operator = makeMember($this->a, TenantRole::Operator);
    $this->viewer = makeMember($this->a, TenantRole::Viewer);
    $this->admin = makeMember($this->a, TenantRole::Admin);
});

function makeCampaign(array $over = []): Campaign
{
    $c = Campaign::create($over + ['name' => 'Weekly', 'subject' => 'This week: auctions', 'preheader' => 'Preview text', 'intro' => 'Hello & welcome']);
    $c->items()->sync([test()->item1->id => ['sort_order' => 0], test()->item2->id => ['sort_order' => 1]]);
    $c->groups()->sync([test()->groupA->id]);

    return $c;
}

it('lets operators build a draft with ordered items and groups', function () {
    actingInTenant($this->operator, $this->a);
    $when = now()->addDay()->setTimezone('America/Chicago')->format('Y-m-d\TH:i');

    Volt::test('pages.campaigns.edit')
        ->set('name', 'Weekly')->set('subject', 'Auctions this week')->set('scheduledAt', $when)
        ->set('groupIds', [(string) $this->groupA->id])
        ->set('addItemId', (string) $this->item2->id)->call('addItem')
        ->set('addItemId', (string) $this->item1->id)->call('addItem')
        ->set('addItemId', (string) $this->item3->id)->call('addItem')
        ->call('moveItem', (string) $this->item3->id, -1)
        ->call('save')->assertHasNoErrors();

    $c = inTenant($this->a, fn () => Campaign::with('items', 'groups')->firstOrFail());
    expect($c->status)->toBe('draft')
        ->and($c->items->pluck('title')->all())->toBe(['Second <b>auction</b>', 'Third auction', 'First auction'])
        ->and($c->groups->pluck('name')->all())->toBe(['Bidders'])
        ->and($c->scheduled_at->setTimezone('America/Chicago')->format('Y-m-d\TH:i'))->toBe($when)
        ->and($c->tenant_id)->toBe($this->a->id)
        ->and(DB::table('campaign_items')->pluck('tenant_id')->unique()->all())->toBe([$this->a->id]);
});

it('never attaches another tenant\'s groups or items', function () {
    actingInTenant($this->operator, $this->a);

    Volt::test('pages.campaigns.edit')
        ->set('name', 'X')->set('subject', 'Y')
        ->set('groupIds', [(string) $this->groupB->id, (string) $this->groupA->id])
        ->set('itemIds', [(string) $this->itemB->id, (string) $this->item1->id])
        ->call('save');

    $c = inTenant($this->a, fn () => Campaign::with('items', 'groups')->firstOrFail());
    expect($c->groups->pluck('id')->all())->toBe([$this->groupA->id])
        ->and($c->items->pluck('id')->all())->toBe([$this->item1->id]);
});

it('keeps campaigns tenant-scoped across pages', function () {
    $mine = inTenant($this->a, fn () => makeCampaign(['name' => 'Mine']));
    $theirs = inTenant($this->b, fn () => Campaign::create(['name' => 'Theirs', 'subject' => 'S']));

    $this->actingAs($this->operator)->get(route('campaigns'))->assertOk()->assertSee('Mine')->assertDontSee('Theirs');
    $this->actingAs($this->operator)->get(route('campaigns.preview', $theirs->id))->assertNotFound();
    $this->actingAs($this->operator)->get(route('campaigns.edit', $theirs->id))->assertNotFound();
    $this->actingAs($this->operator)->get(route('campaigns.preview', $mine->id))->assertOk();
    $this->actingAs($this->operator)->get(route('campaigns.create'))->assertOk()->assertSee('Internal name');
    $this->actingAs($this->operator)->get(route('campaigns.edit', $mine->id))->assertOk()->assertSee('First auction');
});

it('lets viewers see the list and preview but not edit or test send', function () {
    $c = inTenant($this->a, fn () => makeCampaign());

    $this->actingAs($this->viewer)->get(route('campaigns'))->assertOk();
    $this->actingAs($this->viewer)->get(route('campaigns.preview', $c->id))->assertOk()->assertDontSee('Send test');
    $this->actingAs($this->viewer)->get(route('campaigns.create'))->assertForbidden();
    $this->actingAs($this->viewer)->get(route('campaigns.edit', $c->id))->assertForbidden();

    actingInTenant($this->viewer, $this->a);
    Volt::test('pages.campaigns.preview', ['campaign' => $c])->set('testTo', 'x@example.com')->call('sendTest')->assertForbidden();
});

it('refuses to schedule until the campaign and tenant are complete, then schedules and locks it', function () {
    $noAddress = makeTenant(['name' => 'Bare', 'slug' => 'bare']);
    $op = makeMember($noAddress, TenantRole::Operator);
    $g = inTenant($noAddress, fn () => AudienceGroup::create(['name' => 'G', 'slug' => 'g']));
    $i = inTenant($noAddress, fn () => ContentItem::create(['title' => 'I']));
    actingInTenant($op, $noAddress);

    $c = Volt::test('pages.campaigns.edit')
        ->set('name', 'N')->set('subject', 'S')->set('groupIds', [(string) $g->id])->set('itemIds', [(string) $i->id])
        ->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))->call('schedule');
    expect(implode(' ', $c->get('problems')))->toContain('from address')->toContain('postal address');
    expect(inTenant($noAddress, fn () => Campaign::first()->status))->toBe('draft');

    // Complete tenant: past times are refused, a future time schedules.
    actingInTenant($this->operator, $this->a);
    $c = Volt::test('pages.campaigns.edit')
        ->set('name', 'N')->set('subject', 'S')->set('groupIds', [(string) $this->groupA->id])->set('itemIds', [(string) $this->item1->id])
        ->set('scheduledAt', now()->subDay()->format('Y-m-d\TH:i'))->call('schedule');
    expect($c->get('problems'))->toContain('The send time must be in the future.');

    $c->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))->call('schedule');
    expect($c->get('problems'))->toBe([])
        ->and(inTenant($this->a, fn () => Campaign::latest('id')->first()->status))->toBe('scheduled')
        ->and(inTenant($this->a, fn () => AuditLog::where('action', 'campaign.scheduled')->count()))->toBe(1);

    // Scheduled campaigns are locked until unscheduled; only drafts delete.
    $c->set('subject', 'Changed')->call('save')->assertStatus(422);
    expect(inTenant($this->a, fn () => Campaign::latest('id')->first()->subject))->toBe('S');
    $saved = inTenant($this->a, fn () => Campaign::latest('id')->first());
    $fresh = Volt::test('pages.campaigns.edit', ['campaign' => $saved])->call('unschedule');
    expect(inTenant($this->a, fn () => Campaign::latest('id')->first()->status))->toBe('draft');
    $fresh->call('delete');
    expect(inTenant($this->a, fn () => Campaign::count()))->toBe(0);
});

it('requires a subject, items and groups before scheduling', function () {
    actingInTenant($this->operator, $this->a);

    $c = Volt::test('pages.campaigns.edit')->set('name', 'N')->set('subject', 'S')
        ->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))->call('schedule');

    expect($c->get('problems'))->toContain('Add at least one content item.')->toContain('Choose at least one audience group.');
});

it('renders html and plain text from one data set, escaped and with the compliance footer', function () {
    $c = inTenant($this->a, fn () => makeCampaign());
    $m = inTenant($this->a, fn () => app(CampaignRenderer::class)->render($c, $this->a));

    expect($m->subject)->toBe('This week: auctions')
        // order, escaping, safe links only
        ->and(strpos($m->html, 'First auction'))->toBeLessThan(strpos($m->html, 'Second &lt;b&gt;auction&lt;/b&gt;'))
        ->and($m->html)->toContain('href="https://example.com/a1"')->toContain('Hello &amp; welcome')
        ->and($m->html)->not->toContain('javascript:')->not->toContain('data:text')->not->toContain('<b>auction</b>')
        // branding and footer
        ->and($m->html)->toContain('#aa3300')->toContain('Waco, TX 76701')->toContain('Rene Bates')
        ->and($m->html)->toContain('href="'.CampaignRenderer::UNSUBSCRIBE_PLACEHOLDER.'"')
        // times in the tenant's timezone (15:00 UTC = 9:00 AM CST)
        ->and($m->html)->toContain('Tue, Dec 1, 2026 9:00 AM CST')
        // plain text: raw (not HTML-escaped), same content
        ->and($m->text)->toContain('Second <b>auction</b>')->toContain('Hello & welcome')->toContain('https://example.com/a1')
        ->and($m->text)->toContain('Waco, TX 76701')->toContain('Unsubscribe: '.CampaignRenderer::UNSUBSCRIBE_PLACEHOLDER)
        ->and($m->text)->not->toContain('javascript:');
});

it('falls back to a safe accent colour and skips an empty postal address line', function () {
    $this->a->update(['branding' => ['accent_color' => 'red;background:url(x)'], 'postal_address' => null]);
    $c = inTenant($this->a, fn () => makeCampaign());
    $m = inTenant($this->a, fn () => app(CampaignRenderer::class)->render($c, $this->a->fresh()));

    expect($m->html)->toContain('#1f2937')->not->toContain('url(x)');
});

it('uses a per-tenant template override when one exists', function () {
    $dir = resource_path('views/mail/tenants/tenant-a');
    mkdir($dir, 0775, true);
    file_put_contents($dir.'/campaign.blade.php', 'CUSTOM {{ $subject }} {{ $unsubscribeUrl }}');

    try {
        $c = inTenant($this->a, fn () => makeCampaign());
        $m = inTenant($this->a, fn () => app(CampaignRenderer::class)->render($c, $this->a));
        $other = inTenant($this->b, fn () => app(CampaignRenderer::class)->render(Campaign::create(['name' => 'n', 'subject' => 'S']), $this->b));

        expect($m->html)->toContain('CUSTOM This week: auctions')->and($other->html)->not->toContain('CUSTOM');
    } finally {
        @unlink($dir.'/campaign.blade.php');
        @rmdir($dir);
    }
});

it('previews the same HTML that the test send delivers', function () {
    config(['mail.default' => 'array']);
    $c = inTenant($this->a, fn () => makeCampaign());
    actingInTenant($this->operator, $this->a);

    $component = Volt::test('pages.campaigns.preview', ['campaign' => $c])
        ->set('testTo', ' Tester@Example.com ')->call('sendTest')->assertHasNoErrors();

    $expected = inTenant($this->a, fn () => app(CampaignRenderer::class)->render($c, $this->a, '#'));
    $sent = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect($sent->getHtmlBody())->toBe($expected->html)
        ->and($sent->getTextBody())->toBe($expected->text)
        ->and($sent->getSubject())->toBe('[TEST] This week: auctions')
        ->and($sent->getFrom()[0]->getAddress())->toBe('auctions@example.com')
        ->and($sent->getTo()[0]->getAddress())->toBe('tester@example.com');

    // The preview iframe carries exactly that HTML.
    $component->assertSee(e($expected->html), false);
    expect(inTenant($this->a, fn () => AuditLog::where('action', 'campaign.test_sent')->count()))->toBe(1);
});

it('validates and rate limits test sends and needs a from address', function () {
    config(['mail.default' => 'array']);
    $c = inTenant($this->a, fn () => makeCampaign());
    actingInTenant($this->operator, $this->a);

    Volt::test('pages.campaigns.preview', ['campaign' => $c])->set('testTo', 'nope')->call('sendTest')->assertHasErrors('testTo');

    $this->a->update(['from_address' => null]);
    Volt::test('pages.campaigns.preview', ['campaign' => $c])->set('testTo', 'x@example.com')->call('sendTest')->assertHasErrors('testTo');
    $this->a->update(['from_address' => 'auctions@example.com']);

    RateLimiter::clear('campaign-test:'.$this->operator->id);
    $v = Volt::test('pages.campaigns.preview', ['campaign' => $c]);
    foreach (range(1, 20) as $_) {
        $v->set('testTo', 'x@example.com')->call('sendTest')->assertHasNoErrors();
    }
    $v->call('sendTest')->assertHasErrors('testTo');
});

it('lets only tenant admins edit settings and keeps them per tenant', function () {
    $this->actingAs($this->operator)->get(route('settings'))->assertForbidden();
    $this->actingAs($this->viewer)->get(route('settings'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('settings'))->assertOk()->assertSee('Postal address');

    actingInTenant($this->admin, $this->a);
    Volt::test('pages.settings')
        ->set('from_name', 'New Name')->set('from_address', 'NEW@Example.com')->set('postal_address', '2 Side St')
        ->set('logo_url', 'http://insecure.example.com/l.png')->set('accent_color', 'blue')
        ->call('save')->assertHasErrors(['logo_url', 'accent_color']);

    Volt::test('pages.settings')
        ->set('from_name', 'New Name')->set('from_address', 'NEW@Example.com')->set('postal_address', '2 Side St')
        ->set('logo_url', 'https://example.com/l.png')->set('accent_color', '#112233')
        ->call('save')->assertHasNoErrors();

    $a = $this->a->fresh();
    expect($a->from_address)->toBe('new@example.com')->and($a->postal_address)->toBe('2 Side St')
        ->and($a->branding)->toBe(['logo_url' => 'https://example.com/l.png', 'accent_color' => '#112233'])
        ->and($this->b->fresh()->from_address)->toBeNull();
});
