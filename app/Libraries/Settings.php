<?php

namespace App\Libraries;

// small key value store for settings changed at runtime
class Settings
{
    private static array $cache = [];

    public static function get(string $name, string $default = ''): string
    {
        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }

        try {
            $row = \Config\Database::connect()->table('app_settings')->where('name', $name)->get()->getRowArray();
        } catch (\Throwable $e) {
            return $default;
        }

        return self::$cache[$name] = $row === null ? $default : (string) $row['value'];
    }

    public static function set(string $name, string $value): void
    {
        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        if ($db->table('app_settings')->where('name', $name)->countAllResults() > 0) {
            $db->table('app_settings')->where('name', $name)->update(['value' => $value, 'updated_at' => $now]);
        } else {
            $db->table('app_settings')->insert(['name' => $name, 'value' => $value, 'updated_at' => $now]);
        }

        self::$cache[$name] = $value;
    }
}
