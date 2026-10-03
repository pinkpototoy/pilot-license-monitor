<?php

namespace App\Domain\Documents;

/** Streams the file to clamd (INSTREAM protocol). Run ClamAV as a side container (SRS 24). */
class ClamAvScanner implements Scanner
{
    public function scan(string $absolutePath): array
    {
        $cfg = config('splms.documents.clamav');
        $socket = @fsockopen($cfg['host'], $cfg['port'], $errno, $errstr, 5);
        if (! $socket) {
            return ['result' => 'error', 'signature' => "clamd unreachable: {$errstr}"];
        }
        stream_set_timeout($socket, $cfg['timeout']);
        fwrite($socket, "zINSTREAM\0");
        $fh = fopen($absolutePath, 'rb');
        while (! feof($fh)) {
            $chunk = fread($fh, 8192);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }
        fclose($fh);
        fwrite($socket, pack('N', 0));
        $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        fclose($socket);

        if (str_ends_with($reply, 'OK')) {
            return ['result' => 'clean', 'signature' => null];
        }
        if (preg_match('/: (.+) FOUND$/', $reply, $m)) {
            return ['result' => 'infected', 'signature' => $m[1]];
        }

        return ['result' => 'error', 'signature' => $reply];
    }
}
