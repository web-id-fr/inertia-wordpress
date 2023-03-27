<?php
/**
 * Vite integration for WordPress
 *
 * @package ViteForWp
 */

declare(strict_types=1);

namespace WebID\Inertia;

use Exception;

const VITE_CLIENT_SCRIPT_HANDLE = 'vite-client';

class ViteHandler
{
    private array $options;
    private string $rootDirectory;
    private bool $hot = false;
    private string $url;
    private array $manifest = [];

    public function __construct(array $options)
    {
        $this->parseOptions($options);
        $this->rootDirectory = str_replace($this->options['publicDirectory'], '', WP_CONTENT_DIR);

        add_action('wp_enqueue_scripts', [$this, 'enqueueViteScripts']);
    }

    public function enqueueViteScripts(): void
    {
        $assets = $this->registerAsset();
        if (is_null($assets)) {
            return;
        }

        $map = [
            'scripts' => 'wp_enqueue_script',
            'styles' => 'wp_enqueue_style',
        ];

        foreach ($assets as $group => $handles) {
            $func = $map[$group];

            foreach ($handles as $handle) {
                $func($handle);
            }
        }
    }

    private function getManifest(): void
    {
        if (is_readable($this->rootDirectory . $this->options['hotFile'])) {
            $this->hot = true;
            return;
        }

        $manifestDir = sprintf('%s/%s/%s', $this->rootDirectory, $this->options['publicDirectory'], $this->options['buildDirectory']);
        $manifestPath = sprintf('%s/manifest.json', $manifestDir);

        if (!is_readable($manifestPath)) {
            throw new Exception(sprintf('[Vite] No manifest found in %s.', $manifestDir));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $manifest_content = file_get_contents($manifestPath);


        if (!$manifest_content) {
            throw new Exception(sprintf('[Vite] Failed to read manifest %s.', $manifestPath));
        }

        $manifest = json_decode($manifest_content, true);

        if (json_last_error()) {
            throw new Exception(sprintf('[Vite] Manifest %s contains invalid data.', $manifestPath));
        }

        $this->manifest = apply_filters('wordpress_vite_plugin__manifest', $manifest);
    }

    private function filterScriptTag(string $handle): void
    {
        add_filter('script_loader_tag', fn(...$args) => $this->setScriptTypeAttribute($handle, ...$args), 10, 3);
    }

    private function setScriptTypeAttribute(string $target_handle, string $tag, string $handle): string
    {
        if ($target_handle !== $handle) {
            return $tag;
        }

        $attribute = 'type="module"';
        $script_type_regex = '/type=(["\'])([\w\/]+)(["\'])/';

        if (preg_match($script_type_regex, $tag)) {
            // Pre-HTML5.
            $tag = preg_replace($script_type_regex, $attribute, $tag);
        } else {
            $pattern = $handle === VITE_CLIENT_SCRIPT_HANDLE
                ? '#(<script)(.*)#'
                : '#(<script)(.*></script>)#';
            $tag = preg_replace($pattern, sprintf('$1 %s$2', $attribute), $tag);
        }

        return $tag;
    }

    private function hotAsset(string $entry): string
    {
        return sprintf('%s/%s', untrailingslashit($this->url), trim($entry));
    }

    private function registerViteClientScript(): void
    {
        wp_register_script(VITE_CLIENT_SCRIPT_HANDLE, $this->hotAsset('@vite/client'), [], null);
        $this->filterScriptTag(VITE_CLIENT_SCRIPT_HANDLE);
    }

    private function registerReactRefreshScriptPreamble(): void
    {
        $script = sprintf(
            <<< EOS
                import RefreshRuntime from '%s'
                RefreshRuntime.injectIntoGlobalHook(window)
                window.\$RefreshReg$ = () => {}
                window.\$RefreshSig$ = () => (type) => type
                window.__vite_plugin_react_preamble_installed__ = true
            EOS,
            $this->hotAsset('@react-refresh')
        );

        wp_add_inline_script(VITE_CLIENT_SCRIPT_HANDLE, $script);
    }

    private function loadDevelopmentAsset(): ?array
    {
        $this->registerViteClientScript();

        if ($this->options['reactRefresh']) {
            $this->registerReactRefreshScriptPreamble();
        }

        $src = $this->hotAsset($this->options['input']);

        $this->filterScriptTag($this->options['handle']);

        // This is a development script, browsers shouldn't cache it.
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        if (!wp_register_script($this->options['handle'], $src, [], null)) {
            return null;
        }

        $assets = [
            'scripts' => [VITE_CLIENT_SCRIPT_HANDLE, $this->options['handle']],
            'styles' => [],
        ];

        /**
         * Filter registered development assets
         *
         * @param array $assets Registered assets.
         * @param object $manifest Manifest object.
         * @param array $options Enqueue options.
         */
        return apply_filters('wordpress_vite_plugin__development_assets', $assets, $this->manifest, $this->options);
    }

    private function loadBuildAsset(): ?array
    {
        if (!isset($this->manifest[$this->options['input']])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                wp_die(esc_html(sprintf('[Vite] Input %s not found.', $this->options['input'])));
            }

            return null;
        }

        $assets = [
            'scripts' => [],
            'styles' => [],
        ];

        $item = $this->manifest[$this->options['input']];
        $src = sprintf('%s/%s', $this->url, $item['file']);

        $this->filterScriptTag($this->options['handle']);

        if (wp_register_script($this->options['handle'], $src, [], null)) {
            $assets['scripts'][] = $this->options['handle'];
        }

        if (!empty($item['css'])) {
            foreach ($item['css'] as $index => $cssFilePath) {
                $style_handle = "{$this->options['handle']}-{$index}";
                if (wp_register_style($style_handle, "{$this->url}/{$cssFilePath}", [], null)) {
                    $assets['styles'][] = $style_handle;
                }
            }
        }

        return apply_filters('wordpress_vite_plugin__build_assets', $assets, $this->manifest, $this->options);
    }

    private function parseOptions(array $options): void
    {
        $defaults = [
            'input' => null,
            'publicDirectory' => 'public',
            'buildDirectory' => 'build',
            'ssrOutputDirectory' => 'public/build/ssr',
            'reactRefresh' => true,
            'handle' => 'wordpress-vite-plugin',
        ];

        $parsed = wp_parse_args($options, $defaults);
        $parsed['hotFile'] = $options['hotFile'] ?? sprintf('%s/hot', $parsed['publicDirectory']);

        $this->options = $parsed;
    }

    private function prepareAssetUrl(): void
    {
        if ($this->hot) {
            $this->url = file_get_contents($this->rootDirectory . $this->options['hotFile']);
        } else {
            $this->url = sprintf('%s/%s', content_url(), $this->options['buildDirectory']);
        }
    }

    private function registerAsset(): ?array
    {
        try {
            $this->getManifest();
            $this->prepareAssetUrl();
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                wp_die(esc_html($e->getMessage()));
            }
            return null;
        }

        return $this->hot
            ? $this->loadDevelopmentAsset()
            : $this->loadBuildAsset();
    }

}
