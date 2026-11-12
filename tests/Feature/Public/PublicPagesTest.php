<?php

declare(strict_types=1);

use App\Models\AudienceGroup;
use App\Models\PlatformSuppression;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Services\PublicLinks;
use App\Services\TokenService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->a = makeTenant(['name' => 'Tenant A', 'slug' => 'tenant-a', 'from_address' => 'a@example.com', 'postal_address' => '1 Main St']);
    $this->b = makeTenant(['name' => 'Tenant B', 'slug' => 'tenant-b']);
    $this->tokens = app(TokenService::class);

    [$this->pub, $this->internal, $this->ra] = inTenant($this->a, fn () => [
        AudienceGroup::create(['name' => 'Subscribers', 'slug' => 'subscribers', 'is_public' => true]),
        AudienceGroup::create(['name' => 'Bidders', 'slug' => 'bidders']),
        Recipient::create(['email' => 'same@example.com', 'subscribed' => true]),
    ]);
    $this->rb = inTenant($this->b, fn () => Recipient::create(['email' => 'same@example.com', 'subscribed' => true]));
});

function unsubToken($recipient): string
{
    return app(TokenService::class)->forRecipient($recipient, TokenService::UNSUBSCRIBE);
}

it('shows a landing page and unsubscribes only on POST', function () {
    $t = unsubToken($this->ra);

    $this->get(route('public.unsubscribe', $t))->assertOk()->assertSee('Unsubscribe me');
    expect(inTenant($this->a, fn () => Recipient::first()->isMailable()))->toBeTrue();

    $this->post(route('public.unsubscribe.do', $t))->assertOk()->assertSee('You have been unsubscribed');

    inTenant($this->a, function () {
        expect(Recipient::first()->isMailable())->toBeFalse();
        expect(Suppression::where('email', 'same@example.com')->value('reason'))->toBe('unsubscribe');
    });
});

it('accepts an RFC 8058 one-click POST with no CSRF token and is idempotent', function () {
    $t = unsubToken($this->ra);

    $this->post('/u/'.$t, ['List-Unsubscribe' => 'One-Click'])->assertOk();
    $this->post('/u/'.$t, ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect(inTenant($this->a, fn () => Suppression::count()))->toBe(1);
});

it('never lets one tenant\'s token touch the same address in another tenant', function () {
    $this->post(route('public.unsubscribe.do', unsubToken($this->ra)))->assertOk();

    expect(inTenant($this->b, fn () => Recipient::first()->isMailable()))->toBeTrue();
    expect(inTenant($this->b, fn () => Suppression::count()))->toBe(0);
});

it('rejects tampered, swapped-tenant, wrong-purpose and garbage tokens', function () {
    $t = unsubToken($this->ra);
    [$body, $sig] = explode('.', $t);
    $forged = rtrim(strtr(base64_encode(json_encode(['t' => $this->b->id, 'r' => $this->rb->id, 'p' => 'unsubscribe', 'e' => null])), '+/', '-_'), '=');

    $this->post("/u/{$forged}.{$sig}")->assertNotFound();
    $this->post("/u/{$body}x.{$sig}")->assertNotFound();
    $this->post('/u/garbage')->assertNotFound();
    $this->post('/u/'.$this->tokens->forRecipient($this->ra, TokenService::PREFERENCES))->assertNotFound();

    expect(inTenant($this->b, fn () => Recipient::first()->isMailable()))->toBeTrue();
});

it('treats a recipient id from the wrong tenant as not found', function () {
    // A validly signed token whose recipient id belongs to another tenant must change nothing.
    $other = new Recipient(['tenant_id' => $this->a->id]);
    $other->id = $this->rb->id;
    $other->tenant_id = $this->a->id;

    $this->post(route('public.unsubscribe.do', $this->tokens->forRecipient($other, TokenService::UNSUBSCRIBE)))->assertOk();

    expect(inTenant($this->b, fn () => Recipient::first()->isMailable()))->toBeTrue();
});

it('builds RFC 8058 headers', function () {
    $h = app(PublicLinks::class)->listUnsubscribeHeaders('https://x.test/u/abc');

    expect($h)->toBe(['List-Unsubscribe' => '<https://x.test/u/abc>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
});

it('subscribes with double opt-in: pending until the emailed link is confirmed', function () {
    Mail::fake();
    $this->get('/s/tenant-a')->assertOk()->assertSee('Subscribers')->assertDontSee('Bidders');

    $this->post('/s/tenant-a', ['email' => ' New@Example.com ', 'groups' => [$this->pub->id, $this->internal->id]])
        ->assertOk()->assertSee('Check your email');

    $r = inTenant($this->a, fn () => Recipient::where('email', 'new@example.com')->first());
    expect($r->subscribed)->toBeFalse()->and($r->pending_confirmation)->toBeTrue();
    Mail::assertSentCount(1);
    expect(inTenant($this->a, fn () => Recipient::mailable()->where('email', 'new@example.com')->exists()))->toBeFalse();

    $token = $this->tokens->forRecipient($r, TokenService::CONFIRM, now()->addDay(), ['g' => [$this->pub->id, $this->internal->id]]);

    $this->get(route('public.confirm', $token))->assertOk()->assertSee('Yes, subscribe me');
    expect(inTenant($this->a, fn () => Recipient::find($r->id)->subscribed))->toBeFalse(); // GET does not confirm

    $this->post(route('public.confirm.do', $token))->assertOk()->assertSee('is confirmed');

    inTenant($this->a, function () use ($r) {
        $r = Recipient::find($r->id);
        expect($r->isMailable())->toBeTrue()->and($r->pending_confirmation)->toBeFalse()->and($r->consent_source)->toBe('public_form');
        expect($r->groups()->pluck('audience_groups.id')->all())->toBe([$this->pub->id]); // internal group ignored
    });
});

it('rejects expired confirm tokens', function () {
    $r = inTenant($this->a, fn () => Recipient::create(['email' => 'p@example.com', 'subscribed' => false, 'pending_confirmation' => true]));
    $token = $this->tokens->forRecipient($r, TokenService::CONFIRM, now()->subMinute());

    $this->post(route('public.confirm.do', $token))->assertNotFound();
    expect(inTenant($this->a, fn () => Recipient::find($r->id)->subscribed))->toBeFalse();
});

it('gives the same answer and sends nothing for suppressed or already-subscribed addresses', function () {
    Mail::fake();
    inTenant($this->a, fn () => Suppression::create(['email' => 'blocked@example.com', 'reason' => 'complaint']));
    PlatformSuppression::create(['email' => 'bounced@example.com', 'reason' => 'bounce']);

    foreach (['blocked@example.com', 'bounced@example.com', 'same@example.com'] as $email) {
        $this->post('/s/tenant-a', ['email' => $email])->assertOk()->assertSee('Check your email');
    }

    Mail::assertNothingSent();
    expect(inTenant($this->a, fn () => Recipient::where('email', 'blocked@example.com')->exists()))->toBeFalse();
});

it('lets an unsubscribed person re-subscribe only by confirming, and never overrides a block', function () {
    Mail::fake();
    $this->post(route('public.unsubscribe.do', unsubToken($this->ra)))->assertOk();

    $this->post('/s/tenant-a', ['email' => 'same@example.com'])->assertOk();
    Mail::assertSentCount(1);
    expect(inTenant($this->a, fn () => Recipient::first()->isMailable()))->toBeFalse(); // not yet

    $token = $this->tokens->forRecipient($this->ra, TokenService::CONFIRM, now()->addDay());
    $this->post(route('public.confirm.do', $token))->assertOk()->assertSee('is confirmed');
    inTenant($this->a, function () {
        expect(Recipient::first()->isMailable())->toBeTrue();
        expect(Suppression::count())->toBe(0);
    });

    // A platform bounce arriving after the email was sent blocks the confirm.
    PlatformSuppression::create(['email' => 'same@example.com', 'reason' => 'bounce']);
    inTenant($this->a, fn () => Recipient::first()->forceFill(['subscribed' => false])->save());
    $this->post(route('public.confirm.do', $token))->assertOk()->assertSee('be subscribed through this link');
    expect(inTenant($this->a, fn () => Recipient::first()->subscribed))->toBeFalse();
});

it('swallows honeypot submissions and rate-limits confirmation emails per address', function () {
    Mail::fake();
    $this->post('/s/tenant-a', ['email' => 'bot@example.com', 'website' => 'http://spam'])->assertOk()->assertSee('Check your email');
    Mail::assertNothingSent();

    RateLimiter::clear('subscribe-confirm:'.$this->a->id.':'.hash('sha256', 'again@example.com'));
    foreach (range(1, 5) as $_) {
        $this->post('/s/tenant-a', ['email' => 'again@example.com']);
    }
    Mail::assertSentCount(3);
});

it('404s the subscribe page for unknown or inactive tenants', function () {
    $this->get('/s/nope')->assertNotFound();
    $this->a->update(['status' => 'suspended']);
    $this->get('/s/tenant-a')->assertNotFound();
});

it('lets a recipient change only public group memberships', function () {
    inTenant($this->a, fn () => $this->ra->groups()->attach([$this->pub->id, $this->internal->id]));
    $t = $this->tokens->forRecipient($this->ra, TokenService::PREFERENCES);

    $this->get(route('public.preferences', $t))->assertOk()->assertSee('Subscribers')->assertDontSee('Bidders');
    $this->post(route('public.preferences.do', $t), ['groups' => [$this->internal->id]])->assertOk();

    expect(inTenant($this->a, fn () => $this->ra->groups()->pluck('audience_groups.id')->all()))->toBe([$this->internal->id]);
});

it('pre-selects a public group from ?group= and hides the picker; ignores non-public or unknown slugs', function () {
    Mail::fake();
    $this->get('/s/tenant-a?group=subscribers')->assertOk()
        ->assertSee('name="groups[]" value="'.$this->pub->id.'"', false)->assertDontSee('I\'m interested in', false);
    $this->get('/s/tenant-a?group=bidders')->assertOk()->assertSee('interested in', false);
    $this->get('/s/tenant-a?group[]=x')->assertOk();

    $this->post('/s/tenant-a', ['email' => 'embed@example.com', 'groups' => [$this->pub->id]])->assertOk();
    $r = inTenant($this->a, fn () => Recipient::where('email', 'embed@example.com')->first());
    $this->post(route('public.confirm.do', $this->tokens->forRecipient($r, TokenService::CONFIRM, now()->addDay(), ['g' => [$this->pub->id]])))->assertOk();

    expect(inTenant($this->a, fn () => $r->groups()->pluck('audience_groups.slug')->all()))->toBe(['subscribers']);
});
