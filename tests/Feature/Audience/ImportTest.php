<?php

declare(strict_types=1);

use App\Jobs\ProcessRecipientImport;
use App\Models\AudienceGroup;
use App\Models\ImportRun;
use App\Models\PlatformSuppression;
use App\Models\Recipient;
use App\Models\RecipientLegacyId;
use App\Models\Suppression;
use App\Models\Tenant;
use App\Services\OptOuts;
use App\Services\RecipientImporter;
use App\Support\Sources\CsvImportSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

function groupFor(Tenant $tenant, string $name = 'Bidders'): AudienceGroup
{
    return inTenant($tenant, fn () => AudienceGroup::firstOrCreate(['slug' => str($name)->slug()->toString()], ['name' => $name]));
}

/** Writes $csv to a temp file, imports it into $group inside $tenant, returns the finished ImportRun. */
function importCsv(Tenant $tenant, string $csv, ?AudienceGroup $group = null): ImportRun
{
    $group ??= groupFor($tenant);
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $csv);

    try {
        return inTenant($tenant, function () use ($path, $group, $tenant) {
            $run = ImportRun::create(['audience_group_id' => $group->id, 'source' => 'csv', 'filename' => 'test.csv']);

            return app(RecipientImporter::class)->import($run, new CsvImportSource($path), $tenant, $group)->fresh();
        });
    } finally {
        @unlink($path);
    }
}

beforeEach(function () {
    $this->a = makeTenant(['name' => 'Tenant A']);
    $this->b = makeTenant(['name' => 'Tenant B']);
});

it('imports rows, normalizing and grouping them', function () {
    $run = importCsv($this->a, "email,name,external_id\n  Alice@Example.COM ,Alice A,100\nbob@example.com,Bob B,101\n");

    expect($run->status)->toBe('completed')
        ->and($run->total_rows)->toBe(2)
        ->and($run->created_count)->toBe(2);

    inTenant($this->a, function () {
        $alice = Recipient::where('email', 'alice@example.com')->sole();
        expect($alice->name)->toBe('Alice A')
            ->and($alice->external_id)->toBe('100')
            ->and($alice->subscribed)->toBeTrue()
            ->and($alice->source)->toBe('csv')
            ->and($alice->groups()->pluck('slug')->all())->toBe(['bidders']);
    });
});

it('reports invalid and duplicate rows with row numbers and imports the rest', function () {
    $run = importCsv($this->a, "email,name\ngood@example.com,Good\nnot-an-email,Bad\n,Blank\nGOOD@example.com,Dupe\nsecond@example.com,Two\n");

    expect($run->total_rows)->toBe(5)
        ->and($run->created_count)->toBe(2)
        ->and($run->invalid_count)->toBe(2)
        ->and($run->duplicate_count)->toBe(1)
        ->and($run->errors)->toHaveCount(3)
        ->and(collect($run->errors)->pluck('row')->all())->toBe([3, 4, 5])
        ->and($run->errors[0]['reason'])->toBe('invalid_email');
    expect(inTenant($this->a, fn () => Recipient::count()))->toBe(2);
});

it('is idempotent: re-importing the same file changes nothing', function () {
    $csv = "email,name\na@example.com,A\nb@example.com,B\nc@example.com,C\n";

    importCsv($this->a, $csv);
    $before = inTenant($this->a, fn () => Recipient::orderBy('id')->get(['id', 'email', 'name', 'updated_at'])->toArray());

    $second = importCsv($this->a, $csv);

    expect($second->created_count)->toBe(0)
        ->and($second->updated_count)->toBe(0)
        ->and($second->unchanged_count)->toBe(3)
        ->and(inTenant($this->a, fn () => Recipient::count()))->toBe(3)
        ->and(inTenant($this->a, fn () => Recipient::orderBy('id')->get(['id', 'email', 'name', 'updated_at'])->toArray()))->toBe($before)
        ->and(inTenant($this->a, fn () => groupFor($this->a)->recipients()->count()))->toBe(3);
});

it('updates changed fields and adds new group membership on re-import', function () {
    importCsv($this->a, "email,name\na@example.com,Old Name\n");
    $second = importCsv($this->a, "email,name\na@example.com,New Name\n", groupFor($this->a, 'Clients'));

    expect($second->updated_count)->toBe(1)->and($second->created_count)->toBe(0);

    inTenant($this->a, function () {
        $r = Recipient::where('email', 'a@example.com')->sole();
        expect($r->name)->toBe('New Name')
            ->and($r->groups()->orderBy('slug')->pluck('slug')->all())->toBe(['bidders', 'clients']);
    });
});

it('keeps the same email independent in two tenants', function () {
    importCsv($this->a, "email,name\nshared@example.com,Name in A\n");
    importCsv($this->b, "email,name\nshared@example.com,Name in B\n");

    expect(inTenant($this->a, fn () => Recipient::sole()->name))->toBe('Name in A')
        ->and(inTenant($this->b, fn () => Recipient::sole()->name))->toBe('Name in B');

    // An opt-out in A does not touch B.
    inTenant($this->a, fn () => app(OptOuts::class)->suppress('shared@example.com', 'unsubscribe'));

    expect(inTenant($this->a, fn () => Recipient::sole()->isOptedOut()))->toBeTrue()
        ->and(inTenant($this->b, fn () => Recipient::sole()->isOptedOut()))->toBeFalse();
});

it('never re-subscribes an unsubscribed recipient', function () {
    importCsv($this->a, "email,name\nquit@example.com,Quitter\n");
    inTenant($this->a, fn () => app(OptOuts::class)->suppress('quit@example.com', 'unsubscribe'));

    $run = importCsv($this->a, "email,name\nquit@example.com,Quitter Again\n");

    $r = inTenant($this->a, fn () => Recipient::where('email', 'quit@example.com')->sole());
    expect($r->subscribed)->toBeFalse()
        ->and($r->unsubscribed_at)->not->toBeNull()
        ->and($r->isMailable())->toBeFalse()
        ->and($run->protected_count)->toBe(1);
});

it('keeps a deleted-then-reimported unsubscribe opted out (the suppression record survives)', function () {
    importCsv($this->a, "email\nquit@example.com\n");
    inTenant($this->a, function () {
        app(OptOuts::class)->suppress('quit@example.com', 'unsubscribe');
        Recipient::where('email', 'quit@example.com')->delete(); // operator removes the row
    });

    importCsv($this->a, "email\nquit@example.com\n");

    $r = inTenant($this->a, fn () => Recipient::where('email', 'quit@example.com')->sole());
    expect($r->subscribed)->toBeFalse()->and($r->isMailable())->toBeFalse();
});

it('creates addresses on the tenant suppression list as suppressed, not subscribed', function () {
    inTenant($this->a, fn () => Suppression::create(['email' => 'blocked@example.com', 'reason' => 'manual']));

    $run = importCsv($this->a, "email\nblocked@example.com\nfine@example.com\n");

    inTenant($this->a, function () {
        $blocked = Recipient::where('email', 'blocked@example.com')->sole();
        expect($blocked->isMailable())->toBeFalse()->and($blocked->suppressed_at)->not->toBeNull()
            ->and(Recipient::where('email', 'fine@example.com')->sole()->isMailable())->toBeTrue();
    });
    expect($run->protected_count)->toBe(1);
});

it('honours the platform suppression list (hard bounces and complaints)', function () {
    PlatformSuppression::create(['email' => 'bounced@example.com', 'reason' => 'bounce']);

    importCsv($this->a, "email\nbounced@example.com\n");
    importCsv($this->b, "email\nbounced@example.com\n");

    expect(inTenant($this->a, fn () => Recipient::sole()->isMailable()))->toBeFalse()
        ->and(inTenant($this->b, fn () => Recipient::sole()->isMailable()))->toBeFalse();
});

it('does not turn a manually unsubscribed-flag recipient back on', function () {
    importCsv($this->a, "email\noff@example.com\n");
    inTenant($this->a, fn () => Recipient::where('email', 'off@example.com')->update(['subscribed' => false]));

    importCsv($this->a, "email\noff@example.com\n");

    expect(inTenant($this->a, fn () => Recipient::where('email', 'off@example.com')->sole()->subscribed))->toBeFalse();
});

it('handles a BOM, semicolon delimiters and first/last name columns', function () {
    $run = importCsv($this->a, "\xEF\xBB\xBFE-mail;First Name;Last Name\nzed@example.com;Zed;Zebra\n");

    expect($run->status)->toBe('completed')->and($run->created_count)->toBe(1);
    expect(inTenant($this->a, fn () => Recipient::sole()->name))->toBe('Zed Zebra');
});

it('fails the run when there is no email column, without creating anything', function () {
    expect(fn () => importCsv($this->a, "name,phone\nNo Email,555\n"))->toThrow(InvalidArgumentException::class);

    $run = inTenant($this->a, fn () => ImportRun::latest('id')->first());
    expect($run->status)->toBe('failed')
        ->and($run->failure_message)->toContain('no "email" column')
        ->and(inTenant($this->a, fn () => Recipient::count()))->toBe(0);
});

it('processes an uploaded file through the queued tenant job', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/1/f.csv', "email,name\njob@example.com,Via Job\n");

    $group = groupFor($this->a);
    $run = inTenant($this->a, fn () => ImportRun::create(['audience_group_id' => $group->id, 'stored_path' => 'imports/1/f.csv', 'filename' => 'f.csv']));

    $job = inTenant($this->a, fn () => new ProcessRecipientImport($run->id));
    expect($job->queue)->toBe('imports');

    app(TenantContext::class)->forget();
    app()->call([$job, 'handle']);

    expect(inTenant($this->a, fn () => ImportRun::find($run->id)->status))->toBe('completed')
        ->and(inTenant($this->a, fn () => Recipient::where('email', 'job@example.com')->exists()))->toBeTrue()
        ->and(inTenant($this->b, fn () => Recipient::count()))->toBe(0);
});

it('does not let tenant B see or reach tenant A import history or groups', function () {
    $run = importCsv($this->a, "email\nx@example.com\n");

    expect(inTenant($this->b, fn () => ImportRun::find($run->id)))->toBeNull()
        ->and(inTenant($this->b, fn () => ImportRun::count()))->toBe(0)
        ->and(inTenant($this->b, fn () => AudienceGroup::count()))->toBe(0);
});

it('stores legacy bidder, client and email ids per kind without them colliding', function () {
    importCsv($this->a, "bidder_id,email,name\n42,both@example.com,Both\n43,bid@example.com,Bid\n", groupFor($this->a, 'Bidders'));
    importCsv($this->a, "client_id,email,name\n42,both@example.com,\n7,cl@example.com,Cl\n", groupFor($this->a, 'Clients'));
    $run = importCsv($this->a, "email_id,email\n42,sub@example.com\n", groupFor($this->a, 'Subscribers'));

    expect($run->created_count)->toBe(1);
    $ids = inTenant($this->a, fn () => Recipient::with('legacyIds')->where('email', 'both@example.com')->first()->legacyIds->pluck('legacy_id', 'kind')->all());
    expect($ids)->toBe(['bidder' => '42', 'client' => '42'])
        ->and(inTenant($this->a, fn () => Recipient::where('email', 'sub@example.com')->first()->legacyIds->pluck('legacy_id', 'kind')->all()))->toBe(['email' => '42']);
});

it('keeps legacy id imports idempotent and updates a changed id', function () {
    $csv = "bidder_id,email,name\n42,x@example.com,X\n";
    importCsv($this->a, $csv);
    $again = importCsv($this->a, $csv);
    expect($again->created_count)->toBe(0)->and($again->updated_count)->toBe(0)->and($again->unchanged_count)->toBe(1);

    $changed = importCsv($this->a, "bidder_id,email,name\n99,x@example.com,X\n");
    expect($changed->updated_count)->toBe(1)
        ->and(inTenant($this->a, fn () => RecipientLegacyId::count()))->toBe(1)
        ->and(inTenant($this->a, fn () => RecipientLegacyId::first()->legacy_id))->toBe('99');
});

it('scopes legacy ids to the tenant', function () {
    importCsv($this->a, "bidder_id,email\n42,x@example.com\n");
    importCsv($this->b, "bidder_id,email\n42,x@example.com\n");

    expect(inTenant($this->a, fn () => RecipientLegacyId::count()))->toBe(1)
        ->and(inTenant($this->b, fn () => RecipientLegacyId::count()))->toBe(1)
        ->and(inTenant($this->a, fn () => RecipientLegacyId::first()->tenant_id))->toBe($this->a->id);
});

it('does not re-subscribe an opted-out address when its legacy file is re-imported', function () {
    importCsv($this->a, "bidder_id,email\n42,x@example.com\n");
    inTenant($this->a, fn () => app(OptOuts::class)->suppress('x@example.com', 'unsubscribe', 'test'));
    $run = importCsv($this->a, "bidder_id,email\n42,x@example.com\n");

    expect($run->protected_count)->toBe(1)
        ->and(inTenant($this->a, fn () => Recipient::where('email', 'x@example.com')->first()->isOptedOut()))->toBeTrue();
});
