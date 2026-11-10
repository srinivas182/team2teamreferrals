<?php

declare(strict_types=1);

namespace App\Support\Scanning;

/** ClamAV over its network protocol (INSTREAM): the file is streamed in chunks; nothing is shared on disk. */
final class ClamdScanner implements VirusScanner
{
    public function __construct(private readonly string $host, private readonly int $port = 3310, private readonly int $timeout = 30) {}

    public function scan(string $absolutePath): string
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        $file = @fopen($absolutePath, 'rb');
        if ($socket === false || $file === false) {
            return 'unavailable';
        }
        stream_set_timeout($socket, $this->timeout);
        fwrite($socket, "zINSTREAM\0");
        while (! feof($file)) {
            $chunk = (string) fread($file, 8192);
            if ($chunk !== '') {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
        }
        fwrite($socket, pack('N', 0));
        $reply = trim((string) stream_get_contents($socket), "\0\n ");
        fclose($file);
        fclose($socket);

        return match (true) {
            str_ends_with($reply, 'OK') => 'clean',
            str_ends_with($reply, 'FOUND') => 'infected',
            default => 'unavailable',
        };
    }
}
