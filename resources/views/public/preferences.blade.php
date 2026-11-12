<x-layouts.public :tenant="$tenant" title="Email preferences">
@if ($recipient === null || ! $recipient->isMailable())
    <h1>Email preferences</h1>
    <p>This address isn't currently subscribed.</p>
@else
    <h1>Email preferences</h1>
    @if ($saved)<p>Your preferences have been saved.</p>@endif
    <form method="POST" action="{{ route('public.preferences.do', $token) }}">@csrf
        @forelse ($groups as $g)
            <div class="check"><input type="checkbox" id="g{{ $g->id }}" name="groups[]" value="{{ $g->id }}" @checked(in_array($g->id, $mine, true))><label for="g{{ $g->id }}" style="margin:0">{{ $g->name }}</label></div>
        @empty
            <p>There are no preferences to choose.</p>
        @endforelse
        @if ($groups->isNotEmpty())<button type="submit">Save preferences</button>@endif
    </form>
@endif
</x-layouts.public>
