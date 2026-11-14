<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/** Shared HTTP handling: timeouts, and turning CRM answers into CrmException. */
abstract class HttpCrm implements CrmProvider
{
    /** @param array<string, string> $creds */
    abstract protected function client(array $creds): PendingRequest;

    /**
     * @param  array<string, string>  $creds
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function call(array $creds, string $method, string $path, array $data = []): array
    {
        try {
            $req = $this->client($creds)->acceptJson()->timeout(15)->connectTimeout(5);
            /** @var Response $res */
            $res = match ($method) {
                'GET' => $req->get($path, $data),
                'POST' => $req->post($path, $data),
                'PUT' => $req->put($path, $data),
                default => $req->send($method, $path, ['json' => $data]),
            };
        } catch (ConnectionException) {
            throw new CrmException("Couldn’t reach {$this->label()}. It will be retried.");
        }
        if (! $res->successful()) {
            throw CrmException::fromStatus($this->label(), $res->status(), $res->body());
        }

        return (array) ($res->json() ?? []);
    }

    /** @param array<string, mixed> $contact */
    protected static function str(array $contact, string $key): string
    {
        return trim((string) ($contact[$key] ?? ''));
    }
}
