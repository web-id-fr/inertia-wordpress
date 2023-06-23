# Inertia.js WordPress Adapter

The unofficial [Inertia.js](https://inertiajs.com) server-side adapter for WordPress.

This is a fork form BoxyBird (Andrew Rhyand) work https://github.com/boxybird/inertia-wordpress

It also includes code from Kucrut (Dzikri Aziz) work https://github.com/kucrut/vite-for-wp

It adds [SSR](#ssr) support and requires PHP 8.2. See [Changelog](#changelog) section for more information

## Installation

Install the package via composer.

```
composer require web-id-fr/inertia-wordpress
```

## Root Template Example

> Location: /wp-content/themes/your-theme/app.php

```php
<!DOCTYPE html>
<html lang="fr">
<?php $inertia = WebID\Inertia\Inertia::get([
    'id' => 'my_app',
    'classes' => 'bg-blue-100 font-mono p-4'
]); ?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
    <?php echo $inertia['head']; ?>
</head>
<body>
<?php echo $inertia['body']; ?>
<?php wp_footer(); ?>
</body>
</html>
```

Available params for the `get` method:

- `id`: The React app ID
- `className`:
- `publicDirectory` => default `public`
- `ssrInputFile` => default `bootstrap/ssr/ssr.js`
- `vite_enabled`: (bool) enable the vite build system. Default `true`
- `vite_input`: default `src/main.jsx`
- `vite_public_directory` => default `web/app`
- `vite_build_directory` => default `js/dist`

### Root Template File Override

> Location: /wp-content/themes/your-theme/functions.php

By default the WordPress adapter will use the `app.php` from `.../your-theme/app.php`. If you would like to use a
different file name, you can change it. E.g. `.../your-theme/layout.php`.

```php
<?php

add_action('init', function () {
    Inertia::setRootView('layout.php');
});
```

### SSR

To handle SSR on your Inertia APP

- Generate a ssr file `vite build --outDir web/app/js/dist/ssr --ssr src/ssr.jsx`
- Run the node deamon  `run:ssr": "node web/app/js/dist/ssr/ssr.js`
- If necessary, override the constant `INERTIA_SSR_URL` with the URL of the node file which
  is `'http://127.0.0.1:13714/render'` by default.
- use the `Inertia::get()` method as explained earlier

## Inertia Response Examples

### Basic

> Location: /wp-content/themes/your-theme/index.php

```php
<?php

use WebID\Inertia\Inertia;

global $wp_query;

Inertia::render('Index', [
    'posts' => $wp_query->posts,
]);
```

### Less Basic

> Location: /wp-content/themes/your-theme/index.php

This may look busy, however it can be thought of as a "Controller". It gives you a place to handle all your business
logic. Leaving your JavaScript files easier to reason about.

```php
<?php

use WebID\Inertia\Inertia;

global $wp_query;

// Build $posts array
$posts = array_map(function ($post) {
    return [
        'id'      => $post->ID,
        'title'   => get_the_title($post->ID),
        'link'    => get_the_permalink($post->ID),
        'image'   => get_the_post_thumbnail_url($post->ID),
        'content' => apply_filters('the_content', get_the_content(null, false, $post->ID)),
    ];
}, $wp_query->posts);

// Build $pagination array
$current_page = isset($wp_query->query['paged']) ? (int) $wp_query->query['paged'] : 1;
$prev_page    = $current_page > 1 ? $current_page - 1 : false;
$next_page    = $current_page + 1;

$pagination = [
    'prev_page'    => $prev_page,
    'next_page'    => $next_page,
    'current_page' => $current_page,
    'total_pages'  => $wp_query->max_num_pages,
    'total_posts'  => (int) $wp_query->found_posts,
];

// Return Inertia view with data
Inertia::render('Posts/Index', [
    'posts'      => $posts,
    'pagination' => $pagination,
]);
```

## Shared data

> Location: /wp-content/themes/your-theme/functions.php

```php
add_action('init', function () {
    // Synchronously using key/value
    Inertia::share('site_name', get_bloginfo('name'));

    // Synchronously using array
    Inertia::share([
        'primary_menu' => array_map(function ($menu_item) {
            return [
                'id'   => $menu_item->ID,
                'link' => $menu_item->url,
                'name' => $menu_item->title,
            ];
        }, wp_get_nav_menu_items('Primary Menu'))
    ]);

    // Lazily using key/callback
    Inertia::share('auth', function () {
        if (is_user_logged_in()) {
            return [
                'user' => wp_get_current_user()
            ];
        }
    });

    // Lazily on partial reloads
    Inertia::share('auth', Inertia::lazy(function () {
        if (is_user_logged_in()) {
            return [
                'user' => wp_get_current_user()
            ];
        }
    }));

    // Multiple values
    Inertia::share([
        // Synchronously
        'site' => [
            'name'       => get_bloginfo('name'),
            'description'=> get_bloginfo('description'),
        ],
        // Lazily
        'auth' => function () {
            if (is_user_logged_in()) {
                return [
                    'user' => wp_get_current_user()
                ];
            }
        }
    ]);
});
```

## Asset Versioning

> Location: /wp-content/themes/your-theme/functions.php

Optional, but helps with cache busting.

```php
add_action('init', function () {
    // If you're using Laravel Mix, you can
    // use the mix-manifest.json for this.
    $version = md5_file(get_stylesheet_directory() . '/mix-manifest.json');

    Inertia::version($version);
});
```

## Changelog

### [1.0.0] - 2023-03-23

Init fork from https://github.com/boxybird/inertia-wordpress

### [1.0.1] - 2023-06-23

Fix autoload file case

#### Added

- SSR Support

#### Changed

- Requires PHP 8.2
- Publishes autoload files so it can go in plugins directory without running commands
- Includes Vite support from Kucrut plugin
