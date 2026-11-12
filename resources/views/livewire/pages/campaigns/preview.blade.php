<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\Recipient;
use App\Services\PublicLinks;
use App\Services\CampaignRenderer;
use App\Support\Audit;
use App\Support\RenderedMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    #[Locked]
    public int $campaignId;

    public string $testTo = '';
    public ?string $message = null;

    public function mount(Campaign $campaign): void
    {
        $this->campaignId = $campaign->id;
    }

    private function buildMessage(string $unsubscribeUrl = '#'): RenderedMessage
    {
        $tenant = app(TenantContext::class)->current();

        // The on-screen preview is inert ('#'); a test send uses a real signed link when the address is a recipient.
        return app(CampaignRenderer::class)->render(Campaign::findOrFail($this->campaignId), $tenant, $unsubscribeUrl);
    }

    public function sendTest(): void
    {
        Gate::authorize('manage-campaigns');
        $this->testTo = mb_strtolower(trim($this->testTo));
        $this->validate(['testTo' => ['required', 'email', 'max:254']]);

        $tenant = app(TenantContext::class)->current();
        if (trim((string) $tenant->from_address) === '') {
            $this->addError('testTo', 'Set the from address in Settings first.');

            return;
        }

        $sent = RateLimiter::attempt('campaign-test:'.auth()->id(), 20, function () use ($tenant): void {
            $to = mb_strtolower(trim($this->testTo));
            $recipient = Recipient::where('email', $to)->first();
            $links = app(PublicLinks::class);
            $url = $recipient !== null ? $links->unsubscribeUrl($recipient) : null;
            $m = $this->buildMessage($url ?? '#');
            Mail::send(
                ['html' => new HtmlString($m->html), 'text' => new HtmlString($m->text)],
                [],
                function ($msg) use ($tenant, $m, $url, $links): void {
                    $msg->to(mb_strtolower(trim($this->testTo)))
                        ->from($tenant->from_address, $tenant->from_name ?: $tenant->name)
                        ->subject('[TEST] '.$m->subject);
                    foreach ($url !== null ? $links->listUnsubscribeHeaders($url) : [] as $name => $value) {
                        $msg->getHeaders()->addTextHeader($name, $value);
                    }
                    if (trim((string) $tenant->reply_to) !== '') {
                        $msg->replyTo($tenant->reply_to);
                    }
                },
            );
        }, 3600);

        if (! $sent) {
            $this->addError('testTo', 'Test send limit reached (20 per hour). Try again later.');

            return;
        }

        Audit::record('campaign.test_sent', Campaign::findOrFail($this->campaignId), ['to_hash' => hash('sha256', mb_strtolower(trim($this->testTo)))]);
        $this->message = 'Test message sent to '.mb_strtolower(trim($this->testTo)).'.';
    }

    public function with(): array
    {
        $campaign = Campaign::findOrFail($this->campaignId);

        return [
            'campaign' => $campaign,
            'm' => $this->buildMessage(),
            'canManage' => Gate::allows('manage-campaigns'),
        ];
    }
};
?>

<div x-data="{ tab: 'html' }">
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Preview') }}: {{ $campaign->name }}</h2>
            <div class="space-x-4 text-sm">
                @if ($canManage)<a href="{{ route('campaigns.edit', $campaign->id) }}" wire:navigate class="text-indigo-600 hover:underline">{{ $campaign->isDraft() ? __('Edit') : __('Open') }}</a>@endif
                <a href="{{ route('campaigns') }}" wire:navigate class="text-indigo-600 hover:underline">{{ __('All campaigns') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if ($message)<div class="bg-green-50 text-green-800 rounded-md p-3 text-sm" role="status">{{ $message }}</div>@endif

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <div class="text-sm mb-3"><span class="text-gray-500">Subject:</span> {{ $m->subject }}</div>
                <div class="flex gap-6 border-b mb-4 text-sm" role="tablist">
                    <button type="button" role="tab" x-on:click="tab = 'html'" x-bind:class="tab === 'html' ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500'" class="-mb-px pb-2 border-b-2 font-medium">HTML</button>
                    <button type="button" role="tab" x-on:click="tab = 'text'" x-bind:class="tab === 'text' ? 'border-indigo-500 text-gray-900' : 'border-transparent text-gray-500'" class="-mb-px pb-2 border-b-2 font-medium">Plain text</button>
                </div>
                <div x-show="tab === 'html'">
                    <iframe title="HTML preview" sandbox srcdoc="{{ $m->html }}" class="w-full border rounded-md" style="height: 700px"></iframe>
                </div>
                <pre x-show="tab === 'text'" style="display: none" class="whitespace-pre-wrap text-sm bg-gray-50 border rounded-md p-4">{{ $m->text }}</pre>
                <p class="mt-3 text-xs text-gray-500">The unsubscribe link is inert in previews and test messages.</p>
            </div>

            @if ($canManage)
                <form wire:submit="sendTest" class="bg-white shadow-sm sm:rounded-lg p-6 flex flex-wrap items-start gap-3">
                    <div>
                        <x-input-label for="testTo" value="Send a test to" />
                        <x-text-input wire:model="testTo" id="testTo" type="email" class="block mt-1 w-72" />
                        <x-input-error :messages="$errors->get('testTo')" class="mt-1" />
                    </div>
                    <div class="self-end"><x-primary-button>{{ __('Send test') }}</x-primary-button></div>
                </form>
            @endif
        </div>
    </div>
</div>
