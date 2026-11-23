<?php

// A hand-written .vite/manifest.php (see assetManifestPlugin) for tests that
// render templates without a build.

return [
    'entries' => [
        'base' => ['js' => null, 'css' => ['css/base-B1a2c3d4.css'], 'preload' => []],
        'bootstrap' => ['js' => null, 'css' => ['css/bootstrap-C2b3d4e5.css'], 'preload' => []],
        'bootstrap-rtl' => ['js' => null, 'css' => ['css/bootstrap-rtl-D3c4e5f6.css'], 'preload' => []],
        'bootstrap-skin' => ['js' => null, 'css' => ['css/bootstrap-skin-E4d5f6a7.css'], 'preload' => []],
        'site' => [
            'js' => 'js/site-F5e6a7b8.js',
            'css' => ['css/site-A6f7b8c9.css', 'css/custom-content-B7a8c9d0.css'],
            'preload' => ['js/chunks/i18n-C8b9d0e1.js'],
        ],
        'ui-bootstrap' => ['js' => 'js/ui-bootstrap-D9c0e1f2.js', 'css' => [], 'preload' => []],
        'users' => [
            'js' => 'js/users-E0d1f2a3.js',
            'css' => [],
            'preload' => ['js/chunks/i18n-C8b9d0e1.js', 'js/chunks/uploads-F1e2a3b4.js'],
        ],
        'admin' => [
            'js' => 'js/admin-A2f3b4c5.js',
            'css' => ['css/admin-B3a4c5d6.css', 'css/custom-content-B7a8c9d0.css'],
            'preload' => ['js/chunks/i18n-C8b9d0e1.js'],
        ],
    ],
    'translations' => [
        'site' => ['en' => 'js/i18n/en-Aa11Bb22.js', 'ru' => 'js/i18n/ru-Cc33Dd44.js', 'pt-br' => 'js/i18n/pt-br-Ee55Ff66.js'],
        'admin' => ['en' => 'js/i18n/en-admin-Gg77Hh88.js', 'ru' => 'js/i18n/ru-admin-Ii99Jj00.js'],
    ],
];
