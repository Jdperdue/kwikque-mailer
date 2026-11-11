<?php

declare(strict_types=1);

namespace App\Support\Sources;

use App\Models\AudienceGroup;
use App\Models\Tenant;
use App\Support\Audience\RecipientData;

/**
 * Pasted text: one recipient per line as "email", "email, Name", "Name <email>" or tab-separated.
 * A line holding several whitespace/semicolon-separated addresses yields one recipient each.
 */
class PasteListSource implements AudienceSource
{
    public function __construct(private readonly string $text, private readonly string $label = 'paste') {}

    public function name(): string
    {
        return 'manual';
    }

    public function recipients(Tenant $tenant, AudienceGroup $group): iterable
    {
        $row = 0;

        foreach (preg_split('/\R/u', $this->text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row++;

            if (preg_match('/^(.*?)<([^<>]+)>\s*$/u', $line, $m)) {
                yield $this->make($row, $m[2], trim($m[1], " \t\"'"));

                continue;
            }

            if (str_contains($line, ',') || str_contains($line, "\t")) {
                $tokens = array_map('trim', preg_split('/[,\t]/', $line, 2) ?: []);
                $email = array_shift($tokens);
                yield $this->make($row, $email, $tokens[0] ?? null);

                continue;
            }

            $parts = preg_split('/[\s;]+/', $line) ?: [];
            foreach ($parts as $part) {
                yield $this->make($row, $part, null);
            }
        }
    }

    private function make(int $row, string $email, ?string $name): RecipientData
    {
        return new RecipientData(row: $row, email: $email, name: $name !== '' ? $name : null, consentSource: 'manual:'.$this->label);
    }
}
