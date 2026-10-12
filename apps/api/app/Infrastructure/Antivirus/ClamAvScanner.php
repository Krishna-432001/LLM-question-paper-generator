<?php

declare(strict_types=1);

namespace App\Infrastructure\Antivirus;

use App\Domain\Ingestion\Contracts\VirusScanner;
use App\Domain\Ingestion\DTOs\ScanResult;
use RuntimeException;

/**
 * Talks to a clamd daemon over TCP using the INSTREAM command.
 *
 * Streaming the bytes rather than passing a path means clamd does not need to
 * share a filesystem with the worker, so it can run as its own container.
 *
 * clamd's StreamMaxLength defaults to 25 MB, which a textbook easily exceeds.
 * Raise it in clamd.conf to at least examforge.uploads.max_bytes; otherwise
 * clamd answers "size limit exceeded" and the scan stage fails loudly rather
 * than passing a file it never finished reading.
 */
final class ClamAvScanner implements VirusScanner
{
    private const CHUNK_BYTES = 1024 * 1024;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 3310,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function name(): string
    {
        return 'clamav';
    }

    public function scan(string $absolutePath): ScanResult
    {
        $file = @fopen($absolutePath, 'rb');

        if ($file === false) {
            throw new RuntimeException("Cannot open {$absolutePath} for scanning.");
        }

        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $error,
            $this->timeoutSeconds,
        );

        if ($socket === false) {
            fclose($file);

            throw new RuntimeException(
                "ClamAV is unreachable at {$this->host}:{$this->port} ({$error}). "
                .'The upload stays quarantined until a scan succeeds.'
            );
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            fwrite($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK_BYTES);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        return $this->interpret($reply);
    }

    /**
     * Replies are "stream: OK", "stream: <Signature> FOUND", or
     * "<reason> ERROR". Anything else is treated as a failed scan, never as
     * clean.
     */
    public function interpret(string $reply): ScanResult
    {
        if (preg_match('/^stream: OK$/', $reply) === 1) {
            return new ScanResult(clean: true, engine: $this->name());
        }

        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match) === 1) {
            return new ScanResult(clean: false, engine: $this->name(), signature: $match[1]);
        }

        throw new RuntimeException('ClamAV could not scan the file: '.($reply !== '' ? $reply : 'empty reply'));
    }
}
