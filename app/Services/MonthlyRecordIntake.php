<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Meter;
use App\Models\MonthlyRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MonthlyRecordIntake
{
    public const COLUMNS = ['building_code', 'month', 'kwh', 'billed_demand_kw', 'cost_php', 'power_factor', 'notes'];

    /** @return array<string, list<string>> */
    public static function valueRules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m-d', 'regex:/^\d{4}-\d{2}-01$/', 'after_or_equal:1900-01-01', 'before:'.now('Asia/Manila')->startOfMonth()->toDateString()],
            'kwh' => ['required', 'numeric', 'min:0', 'max:999999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'billed_demand_kw' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'cost_php' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'],
            'power_factor' => ['nullable', 'numeric', 'between:0,1', 'regex:/^\d+(\.\d{1,4})?$/'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array{importedCount: int, warnings: list<string>} */
    public function import(UploadedFile $file, User $user): array
    {
        Gate::forUser($user)->authorize('create', MonthlyRecord::class);
        $contents = $file->getContent();
        if (! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw ValidationException::withMessages(['csv' => 'Use a UTF-8 CSV file without null bytes.']);
        }
        $this->validateCsvStructure(str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents);

        $stream = fopen($file->getPathname(), 'r');
        if ($stream === false) {
            throw ValidationException::withMessages(['csv' => 'The CSV file could not be read.']);
        }

        try {
            if (str_starts_with($contents, "\xEF\xBB\xBF")) {
                fseek($stream, 3);
            }
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header === false) {
                throw ValidationException::withMessages(['csv' => 'The CSV file is empty.']);
            }
            $header = array_map(fn ($field) => trim((string) $field), $header);
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
            if (count(array_unique($header)) !== count($header)
                || array_diff(['building_code', 'month', 'kwh'], $header)
                || array_diff($header, self::COLUMNS)) {
                throw ValidationException::withMessages(['csv' => 'Use unique template headers: building_code, month, kwh; optional billed_demand_kw, cost_php, power_factor, notes.']);
            }

            $rows = [];
            $number = 1;
            while (($fields = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $number++;
                if ($fields === [null]) {
                    continue;
                }
                if ($number > 1001) {
                    throw ValidationException::withMessages(['csv' => 'Upload at most 1,000 CSV rows at a time.']);
                }
                if (count($fields) !== count($header)) {
                    throw ValidationException::withMessages(["rows.$number" => "CSV row $number must have the same number of fields as the header."]);
                }
                $rows[$number] = array_combine($header, array_map(fn ($value) => trim((string) $value) === '' ? null : trim((string) $value), $fields));
            }
        } finally {
            fclose($stream);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['csv' => 'Include at least one data row.']);
        }

        return $this->storeRows($rows, $user, 'csv');
    }

    private function validateCsvStructure(string $contents): void
    {
        $state = 'start';
        for ($i = 0, $length = strlen($contents); $i < $length; $i++) {
            $char = $contents[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($contents[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }

                continue;
            }
            if ($char === ',' || $char === "\r" || $char === "\n") {
                $state = 'start';
            } elseif ($char === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                throw ValidationException::withMessages(['csv' => 'Malformed CSV quoting. Use the template and quote fields containing commas or line breaks.']);
            } else {
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            throw ValidationException::withMessages(['csv' => 'Malformed CSV: an enclosed field is missing its closing quote.']);
        }
    }

    /**
     * Validate the entire batch before writing any record. Locking the meter
     * serializes concurrent uploads; the database unique key is the final guard.
     *
     * @param  array<int, array<string, mixed>>  $rows  CSV record numbers, header = row 1
     * @return array{importedCount: int, warnings: list<string>}
     */
    public function storeRows(array $rows, User $user, string $source, ?int $explicitMeterId = null): array
    {
        Gate::forUser($user)->authorize('create', MonthlyRecord::class);

        return DB::transaction(function () use ($rows, $user, $source, $explicitMeterId) {
            // All intake paths lock in the same order: buildings, then meters.
            $buildings = Building::query()->when($user->role->isBuildingRestricted(),
                fn ($query) => $query->whereIn('id', $user->allowedBuildingIds() ?? []))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            $meters = Meter::query()->whereIn('building_id', $buildings->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $errors = [];
            $accepted = [];
            $seen = [];

            foreach ($rows as $number => $row) {
                $validator = Validator::make($row, [
                    'building_code' => ['required', 'string', 'max:32'], ...self::valueRules(),
                ]);
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["rows.$number.$field"] = "CSV row $number: ".$messages[0];
                }
                $building = is_string($row['building_code'] ?? null) ? $buildings->get($row['building_code']) : null;
                if (! $building) {
                    $errors["rows.$number.building_code"] = "CSV row $number: unknown or unassigned building code.";

                    continue;
                }
                $candidates = $meters->where('building_id', $building->id);
                $meter = $explicitMeterId !== null ? $candidates->firstWhere('id', $explicitMeterId)
                    : ($candidates->count() === 1 ? $candidates->first() : null);
                if (! $meter) {
                    $errors["rows.$number.meter"] = "CSV row $number: select a registered meter using manual entry; CSV building mapping requires exactly one registered meter.";

                    continue;
                }
                if ($validator->fails()) {
                    continue;
                }
                $key = $meter->id.'|'.$row['month'];
                if (isset($seen[$key]) || MonthlyRecord::where('meter_id', $meter->id)->where('month', $row['month'])->exists()) {
                    $errors["rows.$number.month"] = "CSV row $number: this meter/month is duplicated in the file or already stored. No existing record will be overwritten.";
                }
                $seen[$key] = true;
                $accepted[] = [
                    ...$validator->safe()->except(['building_code']), 'meter_id' => $meter->id,
                    'verification_status' => 'draft', 'uploaded_by' => $user->id, 'last_edited_by' => $user->id, 'source' => $source,
                ];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            foreach ($accepted as $values) {
                $record = MonthlyRecord::create($values);
                AuditLog::create([
                    'actor_id' => $user->id, 'event' => 'monthly_record.uploaded',
                    'monthly_record_id' => $record->id, 'after' => $record->only(array_keys($values)),
                ]);
            }

            $warnings = [];
            foreach (array_unique(array_column($accepted, 'meter_id')) as $meterId) {
                $months = MonthlyRecord::where('meter_id', $meterId)->orderBy('month')->pluck('month')->map(fn ($month) => Carbon::parse($month)->toDateString())->all();
                $gaps = [];
                $cursor = Carbon::parse($months[0]);
                $last = Carbon::parse($months[count($months) - 1]);
                while ($cursor->lt($last)) {
                    if (! in_array($cursor->toDateString(), $months, true)) {
                        $gaps[] = $cursor->format('Y-m');
                        if (count($gaps) === 24) {
                            break;
                        }
                    }
                    $cursor->addMonth();
                }
                if ($gaps !== []) {
                    $warnings[] = 'Meter #'.$meterId.': missing months within uploaded/stored history (up to 24 shown): '.implode(', ', $gaps).'. No values were filled.';
                }
            }

            return ['importedCount' => count($accepted), 'warnings' => $warnings];
        });
    }
}
