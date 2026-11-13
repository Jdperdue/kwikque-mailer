<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Mail\AlreadySubscribed;
use App\Mail\ConfirmSubscription;
use App\Models\AudienceGroup;
use App\Models\PlatformSuppression;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Models\Tenant;
use App\Services\OptOuts;
use App\Services\PublicLinks;
use App\Services\TokenService;
use App\Support\Audit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public, unauthenticated, per-tenant pages. The tenant always comes from a verified token
 * (or, for the subscribe form only, the slug); every data touch happens inside TenantContext::run().
 */
class PublicPagesController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private TokenService $tokens,
        private OptOuts $optOuts,
    ) {}

    // ---- Unsubscribe -------------------------------------------------------------------------------------

    public function unsubscribeShow(string $token)
    {
        return $this->withToken($token, TokenService::UNSUBSCRIBE, fn (Tenant $tenant) => view('public.unsubscribe', ['tenant' => $tenant, 'token' => $token, 'done' => false]));
    }

    /** Handles both the page button and RFC 8058 one-click POSTs. Idempotent. */
    public function unsubscribe(string $token)
    {
        return $this->withToken($token, TokenService::UNSUBSCRIBE, function (Tenant $tenant, ?Recipient $recipient) {
            if ($recipient !== null) {
                $this->optOuts->suppress($recipient->email, 'unsubscribe');
                Audit::record('recipient.unsubscribed', $recipient, ['via' => 'public_link']);
            }

            return view('public.unsubscribe', ['tenant' => $tenant, 'token' => null, 'done' => true]);
        });
    }

    // ---- Subscribe (double opt-in) -----------------------------------------------------------------------

    /**
     * `?group=<slug>` pre-selects one public group and hides the picker, so a link or form on the tenant's own site
     * can sign people up to that group. Still double opt-in. An unknown or non-public slug falls back to the picker.
     */
    public function subscribeShow(Request $request, string $slug)
    {
        $tenant = $this->tenantBySlug($slug);

        return $this->context->run($tenant, function () use ($request, $tenant) {
            $public = AudienceGroup::where('is_public', true)->orderBy('name')->get();
            $fixed = is_string($request->query('group')) ? $public->firstWhere('slug', $request->query('group')) : null;

            return view('public.subscribe', ['tenant' => $tenant, 'groups' => $public, 'fixed' => $fixed, 'sent' => false]);
        });
    }

    public function subscribe(Request $request, string $slug)
    {
        $tenant = $this->tenantBySlug($slug);

        // Honeypot: bots fill the hidden field; answer exactly like success.
        if ($request->filled('website')) {
            return $this->context->run($tenant, fn () => view('public.subscribe', ['tenant' => $tenant, 'groups' => collect(), 'fixed' => null, 'sent' => true]));
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'name' => ['nullable', 'string', 'max:120'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['integer'],
        ]);
        $email = mb_strtolower(trim($data['email']));

        return $this->context->run($tenant, function () use ($tenant, $data, $email) {
            // Same response whatever happens below, so the form never reveals who is on the list.
            $this->requestConfirmation($tenant, $email, trim((string) ($data['name'] ?? '')), (array) ($data['groups'] ?? []));

            return view('public.subscribe', ['tenant' => $tenant, 'groups' => collect(), 'fixed' => null, 'sent' => true]);
        });
    }

    private function requestConfirmation(Tenant $tenant, string $email, string $name, array $groupIds): void
    {
        // Platform-wide bounce/complaint, or a tenant block other than the person's own unsubscribe: never.
        if (PlatformSuppression::where('email', $email)->exists()) {
            return;
        }
        $suppression = Suppression::where('email', $email)->value('reason');
        if ($suppression !== null && $suppression !== 'unsubscribe') {
            return;
        }

        $existing = Recipient::where('email', $email)->first();

        // One mail per address per hour window (3), whichever kind we send.
        if (! RateLimiter::attempt('subscribe-confirm:'.$tenant->getKey().':'.hash('sha256', $email), 3, fn () => true, 3600)) {
            return;
        }

        if ($existing !== null && $existing->isMailable()) {
            // The page answers the same for everyone; the real answer goes only to the mailbox owner.
            $links = app(PublicLinks::class);
            Mail::to($existing->email)->send(new AlreadySubscribed($tenant, $links->preferencesUrl($existing), $links->unsubscribeUrl($existing)));

            return;
        }

        $recipient = $existing ?? Recipient::create([
            'email' => $email, 'name' => $name !== '' ? $name : null, 'source' => 'public_form',
            'subscribed' => false, 'pending_confirmation' => true,
        ]);
        if ($existing !== null && ! $existing->isOptedOut()) {
            $existing->forceFill(['pending_confirmation' => true])->save();
        }

        $groups = AudienceGroup::where('is_public', true)->whereIn('id', $groupIds)->pluck('id')->all();
        $token = $this->tokens->forRecipient($recipient, TokenService::CONFIRM, now()->addDays(7), ['g' => $groups]);
        $url = route('public.confirm', $token);

        Mail::to($recipient->email)->send(new ConfirmSubscription($tenant, $url));
    }

    public function confirmShow(string $token)
    {
        return $this->withToken($token, TokenService::CONFIRM, fn (Tenant $tenant, ?Recipient $r) => view('public.confirm', [
            'tenant' => $tenant, 'token' => $token, 'done' => false, 'ok' => $r !== null,
        ]));
    }

    /** Needs a POST so mail-scanner link prefetching can never confirm for someone. Idempotent. */
    public function confirm(string $token)
    {
        return $this->withToken($token, TokenService::CONFIRM, function (Tenant $tenant, ?Recipient $recipient, array $extra) {
            $ok = false;

            if ($recipient !== null) {
                $email = $recipient->email;
                $suppression = Suppression::where('email', $email)->value('reason');
                $blocked = PlatformSuppression::where('email', $email)->exists()
                    || ($suppression !== null && $suppression !== 'unsubscribe');

                if (! $blocked) {
                    if ($suppression === 'unsubscribe') {
                        $this->optOuts->lift($email, 'Re-subscribed by the recipient through the confirmed public form');
                        $recipient = Recipient::findOrFail($recipient->getKey());
                    }
                    $recipient->forceFill([
                        'subscribed' => true, 'pending_confirmation' => false,
                        'unsubscribed_at' => null, 'consent_source' => 'public_form',
                        'consent_at' => $recipient->consent_at ?? now(),
                    ])->save();

                    $ids = AudienceGroup::where('is_public', true)->whereIn('id', (array) ($extra['g'] ?? []))->pluck('id')->all();
                    $recipient->groups()->syncWithoutDetaching($ids);
                    Audit::record('recipient.confirmed', $recipient, ['via' => 'double_opt_in']);
                    $ok = true;
                }
            }

            return view('public.confirm', ['tenant' => $tenant, 'token' => null, 'done' => true, 'ok' => $ok]);
        });
    }

    // ---- Preferences -------------------------------------------------------------------------------------

    public function preferencesShow(string $token)
    {
        return $this->withToken($token, TokenService::PREFERENCES, fn (Tenant $tenant, ?Recipient $recipient) => view('public.preferences', [
            'tenant' => $tenant, 'token' => $token, 'saved' => false, 'recipient' => $recipient,
            'groups' => AudienceGroup::where('is_public', true)->orderBy('name')->get(),
            'mine' => $recipient?->groups()->pluck('audience_groups.id')->all() ?? [],
        ]));
    }

    public function preferences(Request $request, string $token)
    {
        $data = $request->validate(['groups' => ['nullable', 'array'], 'groups.*' => ['integer']]);

        return $this->withToken($token, TokenService::PREFERENCES, function (Tenant $tenant, ?Recipient $recipient) use ($data, $token) {
            if ($recipient !== null && $recipient->isMailable()) {
                // Only public groups are the recipient's to change; internal memberships are left alone.
                $publicIds = AudienceGroup::where('is_public', true)->pluck('id')->all();
                $chosen = array_values(array_intersect($publicIds, array_map('intval', (array) ($data['groups'] ?? []))));
                $recipient->groups()->detach(array_diff($publicIds, $chosen));
                $recipient->groups()->syncWithoutDetaching($chosen);
                Audit::record('recipient.preferences_updated', $recipient, ['via' => 'public_link']);
            }

            return view('public.preferences', [
                'tenant' => $tenant, 'token' => $token, 'saved' => $recipient !== null && $recipient->isMailable(), 'recipient' => $recipient,
                'groups' => AudienceGroup::where('is_public', true)->orderBy('name')->get(),
                'mine' => $recipient?->groups()->pluck('audience_groups.id')->all() ?? [],
            ]);
        });
    }

    // ---- helpers -----------------------------------------------------------------------------------------

    /** Verify the token, enter its tenant, load its recipient (null if gone), run the callback in that context. */
    private function withToken(string $token, string $purpose, \Closure $callback)
    {
        $claims = $this->tokens->verify($token, $purpose);
        $tenant = $claims !== null ? Tenant::find($claims['tenant_id']) : null;

        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return $this->context->run($tenant, function () use ($claims, $tenant, $callback) {
            // BelongsToTenant scope: a recipient id from another tenant is simply not found here.
            $recipient = Recipient::find($claims['recipient_id']);

            return $callback($tenant, $recipient, $claims['extra']);
        });
    }

    private function tenantBySlug(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->first();
        abort_if($tenant === null || ! $tenant->isActive(), 404);

        return $tenant;
    }
}
