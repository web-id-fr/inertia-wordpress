<?php

namespace WebID\Inertia;

class InertiaHeaders
{
    public static function all(): false|array
    {
        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }

        return array_change_key_case($headers);
    }

    public static function inRequest(): bool
    {
        $headers = self::all();

        if (isset($headers['x-requested-with'])
            && $headers['x-requested-with'] === 'XMLHttpRequest'
            && isset($headers['x-inertia'])
            && $headers['x-inertia'] === 'true'
        ) {
            return true;
        }

        return false;
    }

    public static function addToResponse(): void
    {
        header('Vary: Accept');
        header('X-Inertia: true');
    }
}
