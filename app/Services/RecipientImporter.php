<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AudienceGroup;
use App\Models\ImportRun;
use App\Models\PlatformSuppression;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Models\Tenant;
use App\Support\Audience\EmailAddress;
use App\Support\Audience\RecipientData;
use App\Support\Audit;
use App\Support\Sources\AudienceSource;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Imports are idempotent and never override an opt-out: an address that is unsubscribed,
 * suppressed (this tenant or platform-wide) or already unsubscribed on the recipient row
 * is left opted out. Must run inside a tenant context.
 */
class RecipientImporter
{
    private const CHUNK = 500;

    private const MAX_ERRORS = 500;

    public function __construct(private readonly OptOuts $optOuts) {}

    public function import(ImportRun $run, AudienceSource $source, Tenant $tenant, AudienceGroup $group): ImportRun
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        $counts = ['total' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'invalid' => 0, 'duplicate' => 0, 'protected' => 0];
        $errors = [];
        $seen = [];
        $chunk = [];

        $flush = function () use (&$chunk, &$counts, $group, $source): void {
            if ($chunk !== []) {
                $this->processChunk($chunk, $group, $source->name(), $counts);
                $chunk = [];
            }
        };

        try {
            foreach ($source->recipients($tenant, $group) as $data) {
                $counts['total']++;
                $email = EmailAddress::normalize($data->email);

                if ($email === null) {
                    $counts['invalid']++;
                    $this->addError($errors, $data->row, $data->email, 'invalid_email');

                    continue;
                }

                if (isset($seen[$email])) {
                    $counts['duplicate']++;
                    $this->addError($errors, $data->row, $email, 'duplicate_in_file (first occurrence kept)');

                    continue;
                }

                $seen[$email] = true;
                $chunk[] = [$email, $data];

                if (count($chunk) >= self::CHUNK) {
                    $flush();
                }
            }
            $flush();
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'failure_message' => mb_substr($e::class.': '.$e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
            Audit::record('import.failed', $run);

            throw $e;
        }

        $run->update([
            'status' => 'completed',
            'total_rows' => $counts['total'],
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'unchanged_count' => $counts['unchanged'],
            'invalid_count' => $counts['invalid'],
            'duplicate_count' => $counts['duplicate'],
            'protected_count' => $counts['protected'],
            'errors' => $errors ?: null,
            'finished_at' => now(),
        ]);

        Audit::record('import.completed', $run, $counts);

        return $run;
    }

    /** @param  array<int, array{0: string, 1: RecipientData}>  $chunk */
    private function processChunk(array $chunk, AudienceGroup $group, string $sourceName, array &$counts): void
    {
        $emails = array_column($chunk, 0);

        /** @var Collection<string, Recipient> $existing */
        $existing = Recipient::whereIn('email', $emails)->get()->keyBy('email');
        $tenantSuppressed = Suppression::whereIn('email', $emails)->pluck('reason', 'email');
        // Platform list is global by design (hard bounces and complaints).
        $platformSuppressed = PlatformSuppression::whereIn('email', $emails)->pluck('reason', 'email');
        $inGroup = $group->recipients()->whereIn('recipients.id', $existing->pluck('id'))->pluck('recipients.id')->flip();
        $touchedIds = [];

        foreach ($chunk as [$email, $data]) {
            $blockReason = $tenantSuppressed[$email] ?? $platformSuppressed[$email] ?? null;
            $recipient = $existing[$email] ?? null;
            $isNew = $recipient === null;
            $fieldsChanged = false;

            if ($isNew) {
                $recipient = Recipient::create([
                    'email' => $email,
                    'name' => $data->name,
                    'external_id' => $data->externalId,
                    'source' => $sourceName,
                    'subscribed' => true,
                    'consent_source' => $data->consentSource,
                    'synced_at' => now(),
                ]);
                $counts['created']++;
            } else {
                $fill = array_filter([
                    'name' => $data->name,
                    'external_id' => $data->externalId,
                ], fn ($v, $k) => $v !== null && $v !== $recipient->{$k}, ARRAY_FILTER_USE_BOTH);

                if ($fill !== []) {
                    $recipient->update($fill);
                    $fieldsChanged = true;
                }
                $touchedIds[] = $recipient->id;
            }

            // Opt-out protection: never flip a subscription back on; stamp known blocks.
            if ($blockReason !== null && ! $recipient->isOptedOut()) {
                $this->optOuts->applyTo($recipient, $blockReason);
            }

            // "protected" overlays the other counts: rows that stay opted out whatever the file says.
            if ($recipient->isOptedOut() || ! $recipient->subscribed || $blockReason !== null) {
                $counts['protected']++;
            }

            $attached = false;
            if (! isset($inGroup[$recipient->id])) {
                $group->recipients()->attach($recipient->id);
                $attached = true;
            }

            if (! $isNew) {
                ($fieldsChanged || $attached) ? $counts['updated']++ : $counts['unchanged']++;
            }
        }

        // Record "seen in this sync" without touching updated_at, so re-imports stay no-ops.
        if ($touchedIds !== []) {
            Recipient::whereIn('id', $touchedIds)->toBase()->update(['synced_at' => now()]);
        }
    }

    private function addError(array &$errors, int $row, ?string $email, string $reason): void
    {
        if (count($errors) < self::MAX_ERRORS) {
            $errors[] = ['row' => $row, 'email' => $email !== null ? mb_substr($email, 0, 254) : null, 'reason' => $reason];
        }
    }
}
