<?php

namespace BoxyBird\Inertia;

class Inertia
{
    public static $request;

    public static $version;

    public static $share_props = [];

    public static function render(string $component, array $props = [])
    {
        global $bb_inertia_page;

        self::setRequest();

        $bb_inertia_page = [
            'component' => $component,
            'url'       => self::$request,
            'version'   => self::$version,
            'props'     => array_merge($props, self::$share_props),
        ];

        if (self::hasRequestHeaders()) {
            wp_send_json($bb_inertia_page);
        }

        return $bb_inertia_page;
    }

    public static function version(string $version = '')
    {
        self::$version = $version;
    }

    public static function share(array $props = [])
    {
        self::$share_props = array_merge(
            self::$share_props,
            $props
        );
    }

    public static function addResponseHeaders()
    {
        header('Vary: Accept');
        header('X-Inertia: true');
    }

    public static function hasRequestHeaders()
    {
        $headers = getallheaders();

        if (isset($headers['X-Requested-With'])
            && $headers['X-Requested-With'] === 'XMLHttpRequest'
            && isset($headers['X-Inertia'])
            && $headers['X-Inertia'] === 'true'
        ) {
            return true;
        }

        return false;
    }

    protected static function setRequest()
    {
        global $wp;

        self::$request = "/{$wp->request}";
    }
}