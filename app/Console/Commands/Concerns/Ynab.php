<?php
declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

trait Ynab
{
    protected function getClient(): PendingRequest
    {
        return Http::baseUrl('https://api.ynab.com/v1/')
            ->acceptJson()
            ->asJson()
            ->throw()
            ->withToken(config('services.ynab.token'));
    }
}
