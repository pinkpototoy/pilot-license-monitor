<?php

namespace App\Domain\Documents;

/** FR-034 — malware scanning behind an interface so the engine can change without code changes elsewhere. */
interface Scanner
{
    /** @return array{result: 'clean'|'infected'|'error', signature: ?string} */
    public function scan(string $absolutePath): array;
}
