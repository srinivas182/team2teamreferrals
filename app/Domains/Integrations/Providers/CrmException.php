<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use RuntimeException;

/**
 * A CRM call that didn't work. `auth` means the CRM refused the key (the connection needs the team's attention, so
 * retrying won't help); `gone` means the record no longer exists there; anything else is worth retrying.
 */
final class CrmException extends RuntimeException
{
    public function __construct(string $message, public readonly string $kind = 'retry')
    {
        parent::__construct($message);
    }

    public static function fromStatus(string $crm, int $status, string $body): self
    {
        $detail = mb_substr(trim(strip_tags($body)), 0, 120);

        return match (true) {
            $status === 401, $status === 403 => new self("{$crm} refused the API key (HTTP {$status}). Check the key and that it still has access.", 'auth'),
            $status === 404 => new self("{$crm} no longer has that record (HTTP 404).", 'gone'),
            $status === 429 => new self("{$crm} asked us to slow down (HTTP 429). It will be retried."),
            $status >= 500 => new self("{$crm} had a problem (HTTP {$status}). It will be retried."),
            default => new self("{$crm} didn’t accept it (HTTP {$status})".($detail !== '' ? ": {$detail}" : '.'), 'reject'),
        };
    }
}
