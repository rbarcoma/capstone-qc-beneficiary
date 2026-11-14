<?php

namespace App\Services;

class DataFingerprint
{
    public static function hash(mixed $data): string
    {
        return hash('sha256', json_encode(self::sort($data), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        ksort($value);

        return array_map(self::sort(...), $value);
    }
}
