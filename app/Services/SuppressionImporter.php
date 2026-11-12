<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ImportRun;
use App\Models\Recipient;
use App\Models\Suppression;
use App\Support\Audience\EmailAddress;
use App\Support\Audit;
use InvalidArgumentException;
use League\Csv\Reader;
use Throwable;

/**
 * Bulk opt-outs from a CSV or one-address-per-line file, for the current tenant only.
 * Never writes platform_suppressions (that list is written only from provider events).
 * Existing suppressions are never changed. Run inside a tenant context.
 */
class SuppressionImporter
{
    public const REASONS = ['unsubscribe', 'manual', 'bounce', 'complaint'];

    private const EMAIL = ['email', 'e-mail', 'email address', 'emailaddress', 'email_address'];

    private const CHUNK = 500;

    private const MAX_ERRORS = 500;

    public function __construct(private readonly OptOuts $optOuts) {}

    /** Counts what the file would do, writing nothing. @return array<string, mixed> */
    public function preview(string $path, string $defaultReason): array
    {
        return $this->analyse($path, $defaultReason, false);
    }

    public function apply(ImportRun $run, string $path, string $defaultReason): ImportRun
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $r = $this->analyse($path, $defaultReason, true);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'failure_message' => mb_substr($e::class.': '.$e->getMessage(), 0, 1000), 'finished_at' => now()]);
            Audit::record('suppression.import.failed', $run);

            throw $e;
        }

        $run->update([
            'status' => 'completed',
            'total_rows' => $r['total'],
            'created_count' => $r['new'],
            'updated_count' => $r['deactivated'],
            'unchanged_count' => $r['existing'],
            'invalid_count' => $r['invalid'],
            'duplicate_count' => $r['duplicate'],
            'errors' => $r['errors'] ?: null,
            'finished_at' => now(),
        ]);
        Audit::record('suppression.import.completed', $run, ['new' => $r['new'], 'deactivated' => $r['deactivated']]);

        return $run;
    }

    /** @return array{total:int,invalid:int,duplicate:int,new:int,existing:int,deactivated:int,sample:array<int,string>,errors:array<int,array<string,mixed>>} */
    private function analyse(string $path, string $defaultReason, bool $write): array
    {
        $c = ['total' => 0, 'invalid' => 0, 'duplicate' => 0, 'new' => 0, 'existing' => 0, 'deactivated' => 0, 'sample' => [], 'errors' => []];
        $seen = [];
        $chunk = [];

        $flush = function () use (&$chunk, &$c, $write): void {
            if ($chunk === []) {
                return;
            }
            $emails = array_column($chunk, 0);
            $existing = Suppression::whereIn('email', $emails)->pluck('email')->flip();
            $mailable = Recipient::mailable()->whereIn('email', $emails)->pluck('email')->flip();

            foreach ($chunk as [$email, $reason, $note]) {
                if (isset($existing[$email])) {
                    $c['existing']++;

                    continue;
                }
                $c['new']++;
                if (isset($mailable[$email])) {
                    $c['deactivated']++;
                }
                if (count($c['sample']) < 5) {
                    $c['sample'][] = $email;
                }
                if ($write) {
                    $this->optOuts->suppress($email, $reason, $note);
                }
            }
            $chunk = [];
        };

        foreach ($this->rows($path) as [$row, $rawEmail, $rawReason, $note]) {
            $c['total']++;
            $email = EmailAddress::normalize($rawEmail);

            if ($email === null) {
                $c['invalid']++;
                $this->addError($c['errors'], $row, $rawEmail, 'invalid_email');

                continue;
            }

            $reason = $rawReason === null ? $defaultReason : mb_strtolower($rawReason);
            if (! in_array($reason, self::REASONS, true)) {
                $c['invalid']++;
                $this->addError($c['errors'], $row, $email, 'unknown_reason');

                continue;
            }

            if (isset($seen[$email])) {
                $c['duplicate']++;
                $this->addError($c['errors'], $row, $email, 'duplicate_in_file (first occurrence kept)');

                continue;
            }

            $seen[$email] = true;
            $chunk[] = [$email, $reason, $note];
            if (count($chunk) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();

        return $c;
    }

    /** @return \Generator<int, array{0:int,1:?string,2:?string,3:?string}> row, email, reason, note */
    private function rows(string $path): \Generator
    {
        $first = '';
        $h = fopen($path, 'r');
        while ($h && ($line = fgets($h)) !== false) {
            if (trim($line) !== '') {
                $first = preg_replace('/^\x{FEFF}/u', '', $line) ?? $line;
                break;
            }
        }
        if ($h) {
            fclose($h);
        }

        // A header-less list always starts with an address; a first line without '@' is a header row.
        $headerMode = ! str_contains($first, '@');

        if (! $headerMode) {
            $row = 0;
            foreach (preg_split('/\R/u', (string) file_get_contents($path)) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row++;
                $token = preg_split('/[,;\t\s]+/', $line)[0] ?? '';
                yield [$row, $token, null, null];
            }

            return;
        }

        $csv = Reader::from($path, 'r');
        $csv->setDelimiter(substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ','));
        $csv->setHeaderOffset(0);
        $header = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\x{FEFF}/u', '', (string) $h) ?? (string) $h)), $csv->getHeader());

        $emailCol = null;
        foreach (self::EMAIL as $alias) {
            if (($i = array_search($alias, $header, true)) !== false) {
                $emailCol = (int) $i;
                break;
            }
        }
        if ($emailCol === null) {
            throw new InvalidArgumentException('The file has no "email" column.');
        }
        $reasonCol = array_search('reason', $header, true);
        $noteCol = array_search('note', $header, true);

        $row = 1;
        foreach ($csv->getRecords() as $record) {
            $row++;
            $v = array_values($record);
            $cell = fn ($i) => $i === false || $i === null || ! isset($v[$i]) || trim((string) $v[$i]) === '' ? null : trim((string) $v[$i]);

            yield [$row, $cell($emailCol), $cell($reasonCol), $cell($noteCol) !== null ? mb_substr((string) $cell($noteCol), 0, 255) : null];
        }
    }

    private function addError(array &$errors, int $row, ?string $email, string $reason): void
    {
        if (count($errors) < self::MAX_ERRORS) {
            $errors[] = ['row' => $row, 'email' => $email !== null ? mb_substr($email, 0, 254) : null, 'reason' => $reason];
        }
    }
}
