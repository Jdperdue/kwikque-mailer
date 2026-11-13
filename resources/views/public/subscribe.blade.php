<x-layouts.public :tenant="$tenant" title="Subscribe">
@if ($sent)
    <h1>Check your email</h1>
    <p>We've sent an email to that address. If it isn't subscribed yet, it contains a confirmation link, and you'll be subscribed once you click it.</p>
@else
    <h1>Subscribe to {{ $tenant->name }}</h1>
    <form method="POST" action="{{ route('public.subscribe.do', $tenant->slug) }}">@csrf
        <label for="email">Email address</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email">
        @error('email')<div class="err">{{ $message }}</div>@enderror
        <label for="name">Name (optional)</label>
        <input id="name" type="text" name="name" value="{{ old('name') }}" maxlength="120">
        @if ($fixed)
            <input type="hidden" name="groups[]" value="{{ $fixed->id }}">
        @elseif ($groups->isNotEmpty())
            <label>I'm interested in</label>
            @foreach ($groups as $g)
                <div class="check"><input type="checkbox" id="g{{ $g->id }}" name="groups[]" value="{{ $g->id }}"><label for="g{{ $g->id }}" style="margin:0">{{ $g->name }}</label></div>
            @endforeach
        @endif
        <div class="hp" aria-hidden="true"><label>Leave blank <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <button type="submit">Subscribe</button>
    </form>
@endif
</x-layouts.public>
