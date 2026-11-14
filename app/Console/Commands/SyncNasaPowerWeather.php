<?php

namespace App\Console\Commands;

use App\Services\NasaPowerWeather;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SyncNasaPowerWeather extends Command
{
    protected $signature = 'weather:sync {start : First completed month, YYYY-MM} {end? : Last month, defaults to start}';

    protected $description = 'Sync source-tracked NASA POWER daily data into complete calendar-month aggregates';

    public function handle(NasaPowerWeather $weather): int
    {
        try {
            $result = $weather->sync((string) $this->argument('start'), (string) ($this->argument('end') ?? $this->argument('start')));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }
        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
