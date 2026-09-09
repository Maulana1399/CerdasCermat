<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        if ($row === null) {
            return $default;
        }

        $value = $row->value;

        if (in_array($value, ['true', 'false'], true)) {
            return $value === 'true';
        }

        if (is_numeric($value)) {
            return (int) $value == (float) $value ? (int) $value : (float) $value;
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $stored = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored],
        );
    }
}
