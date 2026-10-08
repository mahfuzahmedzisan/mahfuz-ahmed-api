<?php

namespace App\Services;

use Socket\Raw\Factory as SocketFactory;
use Throwable;
use Xenolope\Quahog\Client;

final class ClamAvScanner
{
    public function rejectionReason(string $path): ?string
    {
        $socket = config('media-hls.clamav_socket');

        if (! is_string($socket) || $socket === '') {
            return null;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 'The file could not be scanned.';
        }

        try {
            $client = new Client((new SocketFactory)->createClient($socket), 30, PHP_NORMAL_READ);
            $result = $client->scanResourceStream($handle);
        } catch (Throwable) {
            return 'The file could not be scanned.';
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($result->isError()) {
            return 'The file could not be scanned.';
        }

        if (! $result->isOk()) {
            return 'This file was rejected by the malware scan.';
        }

        return null;
    }
}
