<?php

namespace App\Domain\Documents;

/**
 * DEVELOPMENT ONLY — detects the standard EICAR antivirus test file so the quarantine
 * path can be exercised without ClamAV. Production must set SPLMS_SCANNER=clamav.
 */
class SignatureScanner implements Scanner
{
    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    public function scan(string $absolutePath): array
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false) {
            return ['result' => 'error', 'signature' => null];
        }

        return str_contains($content, self::EICAR)
            ? ['result' => 'infected', 'signature' => 'EICAR-Test-File']
            : ['result' => 'clean', 'signature' => null];
    }
}
