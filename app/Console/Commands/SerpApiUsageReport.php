<?php

namespace App\Console\Commands;

use App\Services\PartSearch\PartSearchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

#[Signature('seibi:serpapi:uso {--dias=7 : Número de días a mostrar}')]
#[Description('Muestra las consultas a SerpApi registradas por SEIBI y el saldo del plan')]
class SerpApiUsageReport extends Command
{
    public function handle(PartSearchService $partSearch): int
    {
        $days = max(1, (int) $this->option('dias'));
        $rows = [];
        $totalProvider = 0;

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = now()->subDays($offset)->toDateString();
            $usage = $partSearch->usageFor($date);
            $provider = $usage['google_shopping']['provider'] + $usage['google_product']['provider'];
            $totalProvider += $provider;
            $rows[] = [
                $date,
                $usage['google_shopping']['provider'], $usage['google_shopping']['cache'],
                $usage['google_product']['provider'], $usage['google_product']['cache'],
                $provider,
            ];
        }

        $this->table(['Día', 'Búsquedas', 'Búsquedas en caché', 'Detalles', 'Detalles en caché', 'Créditos usados'], $rows);
        $this->line("Créditos consumidos por SEIBI en {$days} día(s): {$totalProvider}.");

        $apiKey = trim((string) config('services.serpapi.api_key'));
        if ($apiKey === '') {
            $this->warn('SERPAPI_API_KEY no está configurada; no se consultó el saldo del plan.');

            return self::SUCCESS;
        }

        try {
            // El endpoint de cuenta no consume búsquedas del plan.
            $account = Http::timeout(10)->get('https://serpapi.com/account.json', ['api_key' => $apiKey])->json();
        } catch (ConnectionException) {
            $this->warn('No fue posible consultar el saldo del plan.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Plan: %s · Búsquedas al mes: %s · Usadas este mes: %s · Restantes: %s · Límite por hora: %s',
            $account['plan_name'] ?? '—',
            $account['searches_per_month'] ?? '—',
            $account['this_month_usage'] ?? '—',
            $account['total_searches_left'] ?? '—',
            $account['account_rate_limit_per_hour'] ?? '—',
        ));

        return self::SUCCESS;
    }
}
