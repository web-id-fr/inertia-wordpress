<?php

namespace WebID\Inertia;

use Closure;

class Inertia
{
    protected static string $url;

    protected static array $props;

    protected static array $request;

    protected static string $version = 'main';

    protected static string $component;

    protected static array $shared_props = [];

    protected static string $root_view = 'app.php';

    public static function render(string $component, array $props = []): void
    {
        global $webIdInertiaPage;

        self::setRequest();

        self::setUrl();
        self::setComponent($component);
        self::setProps($props);

        $webIdInertiaPage = [
            'url' => self::$url,
            'props' => self::$props,
            'version' => self::$version,
            'component' => self::$component,
        ];

        if (InertiaHeaders::inRequest()) {
            InertiaHeaders::addToResponse();

            wp_send_json($webIdInertiaPage);
        }

        require_once get_stylesheet_directory() . '/' . self::$root_view;
    }

    public static function get(array $options): null|array
    {
        $defaults = [
            // Vite
            'vite_enabled' => true,
            'vite_input' => 'src/main.jsx',
            'vite_public_directory' => 'web/app',
            'vite_build_directory' => 'js/dist',

            // Inertia
            'id' => 'app',
            'className' => '',
            'publicDirectory' => 'public',
            'ssrInputFile' => 'bootstrap/ssr/ssr.js',
        ];
        $options = wp_parse_args($options, $defaults);

        if ($options['vite_enabled']) {
            new ViteHandler([
                'input' => $options['vite_input'],
                'publicDirectory' => $options['vite_public_directory'],
                'buildDirectory' => $options['vite_build_directory'],
            ]);
        }

        $rootDirectory = str_replace($options['publicDirectory'], '', WP_CONTENT_DIR);

        global $webIdInertiaPage;
        if (!isset($webIdInertiaPage)) {
            return null;
        }

        $ssrJsExists = file_exists(realpath(sprintf('%s/%s', $rootDirectory, $options['ssrInputFile'])));
        $headers = get_headers(INERTIA_SSR_URL);
        $ssrServerIsRunning = (bool) strpos($headers[0], '200');

        if ($ssrJsExists && $ssrServerIsRunning) {
            $res = wp_remote_post(INERTIA_SSR_URL, [
                'headers' => [
                    'content-type' => 'application/json',
                ],
                'body' => wp_json_encode($webIdInertiaPage),
                'data_format' => 'body',
            ]);
            $body = wp_remote_retrieve_body($res);
            $response = json_decode($body, true);
        } else {
            $page = htmlspecialchars(
                json_encode($webIdInertiaPage),
                ENT_QUOTES,
                'UTF-8',
                true
            );

            $response = [
                'head' => [],
                'body' => sprintf('<div id="%s" class="%s" data-page="%s"></div>', $options['id'], $options['classes'], $page)
            ];
        }

        return [
            'head' => implode("\n", $response['head']),
            'body' => $response['body'],
        ];
    }

    public static function setRootView(string $name): void
    {
        self::$root_view = $name;
    }

    public static function version(string $version = ''): void
    {
        self::$version = $version;
    }

    public static function share($key, $value = null): void
    {
        if (is_array($key)) {
            self::$shared_props = array_merge(self::$shared_props, $key);
        } else {
            InertiaHelper::arraySet(self::$shared_props, $key, $value);
        }
    }

    public static function lazy(callable $callback): LazyProp
    {
        return new LazyProp($callback);
    }

    protected static function setRequest(): void
    {
        global $wp;

        self::$request = array_merge([
            'WP-Inertia' => (array) $wp,
        ], InertiaHeaders::all());
    }

    protected static function setUrl(): void
    {
        self::$url = $_SERVER['REQUEST_URI'] ?? '/';
    }

    protected static function setProps(array $props): void
    {
        $props = array_merge($props, self::$shared_props);

        $partial_data = self::$request['x-inertia-partial-data'] ?? null;

        $only = array_filter(explode(',', $partial_data));

        $partial_component = self::$request['x-inertia-partial-component'] ?? null;

        $props = ($only && $partial_component === self::$component)
            ? InertiaHelper::arrayOnly($props, $only)
            : array_filter($props, function ($prop) {
                // remove lazy props when not calling for partials
                return !($prop instanceof LazyProp);
            });

        array_walk_recursive($props, function (&$prop) {
            if ($prop instanceof LazyProp) {
                $prop = $prop();
            }

            if ($prop instanceof Closure) {
                $prop = $prop();
            }
        });

        self::$props = $props;
    }

    protected static function setComponent(string $component): void
    {
        self::$component = $component;
    }
}
