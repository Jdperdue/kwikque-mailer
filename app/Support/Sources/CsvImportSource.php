<?php

declare(strict_types=1);

namespace App\Support\Sources;

use App\Models\AudienceGroup;
use App\Models\RecipientLegacyId;
use App\Models\Tenant;
use App\Support\Audience\RecipientData;
use InvalidArgumentException;
use League\Csv\Reader;

/** Universal fallback source: a CSV file with an email column. */
class CsvImportSource implements AudienceSource
{
    private const EMAIL = ['email', 'e-mail', 'email address', 'emailaddress', 'email_address'];

    private const NAME = ['name', 'full name', 'fullname', 'full_name'];

    private const FIRST = ['first name', 'firstname', 'first_name'];

    private const LAST = ['last name', 'lastname', 'last_name'];

    private const EXTERNAL_ID = ['external_id', 'external id', 'id'];

    public function __construct(private readonly string $path) {}

    public function name(): string
    {
        return 'csv';
    }

    public function recipients(Tenant $tenant, AudienceGroup $group): iterable
    {
        $csv = Reader::createFromPath($this->path, 'r');
        $csv->setDelimiter($this->detectDelimiter());
        $csv->setHeaderOffset(0);

        $header = array_map(self::headerKey(...), $csv->getHeader());
        $map = [
            'email' => $this->find($header, self::EMAIL),
            'name' => $this->find($header, self::NAME),
            'first' => $this->find($header, self::FIRST),
            'last' => $this->find($header, self::LAST),
            'external_id' => $this->find($header, self::EXTERNAL_ID),
        ];
        $legacy = [];
        foreach (RecipientLegacyId::KINDS as $column => $kind) {
            if (($i = $this->find($header, [$column])) !== null) {
                $legacy[$kind] = $i;
            }
        }

        if ($map['email'] === null) {
            throw new InvalidArgumentException('The file has no "email" column.');
        }

        $row = 1; // header is line 1; first data row is line 2
        foreach ($csv->getRecords() as $record) {
            $row++;
            $values = array_values($record);

            $name = $this->cell($values, $map['name']);
            if ($name === null) {
                $name = trim(($this->cell($values, $map['first']) ?? '').' '.($this->cell($values, $map['last']) ?? '')) ?: null;
            }

            yield new RecipientData(
                row: $row,
                email: $this->cell($values, $map['email']),
                name: $name,
                externalId: $this->cell($values, $map['external_id']),
                consentSource: 'import:csv',
                legacyIds: array_filter(array_map(fn (int $i) => $this->cell($values, $i), $legacy), fn ($v) => $v !== null),
            );
        }
    }

    private static function headerKey(string $h): string
    {
        return mb_strtolower(trim(preg_replace('/^\x{FEFF}/u', '', $h) ?? $h));
    }

    private function find(array $header, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $i = array_search($alias, $header, true);
            if ($i !== false) {
                return (int) $i;
            }
        }

        return null;
    }

    private function cell(array $values, ?int $index): ?string
    {
        if ($index === null || ! isset($values[$index])) {
            return null;
        }

        $v = trim((string) $values[$index]);

        return $v === '' ? null : mb_substr($v, 0, 254);
    }

    private function detectDelimiter(): string
    {
        $first = (string) fgets(fopen($this->path, 'r'));
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);

        return array_key_first($counts) ?: ',';
    }
}
