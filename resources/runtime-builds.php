<?php

// Runtime builds known to this Arco release. Generated with bin/runtime-manifest-add; do not edit by hand.
// Each build is an immutable release of github.com/multi-api/arco-runtime.
// The default build is the newest build of default_php; see docs/maintainers/runtime-builds.md.
return [
    'default_php' => '8.5',
    'builds' => [
        'php8.5.11-fp1.13.0-r1' => [
            'url' => 'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r1/frankenphp-linux-aarch64',
            'sha256' => '605ff4ae0ae4b8273026d92ee125405b8a589234a196e930f05eff067e9f2ca5',
            'size' => 168140016,
            'frankenphp' => '1.13.0',
            'php' => '8.5.11',
        ],
        'php8.5.11-fp1.13.0-r2' => [
            'url' => 'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r2/frankenphp-linux-aarch64',
            'sha256' => '590762f2aea6b66cdc1d18b262a21ee1b0b938953a617598b8c970b453f2b1f1',
            'size' => 168140016,
            'frankenphp' => '1.13.0',
            'php' => '8.5.11',
            'php_cli' => [
                'url' => 'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.0-r2/php-linux-aarch64',
                'sha256' => '408921e0132b1874980b5443dcefbd7bfacc52c4cb12864da04e76664d647e76',
                'size' => 95099864,
            ],
        ],
        'php8.5.12-fp1.13.1-r1' => [
            'url' => 'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.1-r1/frankenphp-linux-aarch64',
            'sha256' => '9cb3a31d10c7e8bf2448d599937bda889bdff8d3934011ee1b6a40e4fab5c5a5',
            'size' => 168138896,
            'frankenphp' => '1.13.1',
            'php' => '8.5.12',
            'php_cli' => [
                'url' => 'https://github.com/multi-api/arco-runtime/releases/download/frankenphp-1.13.1-r1/php-linux-aarch64',
                'sha256' => '5a0fb99033ecf48b07f368b11b5fad7a3c41cc030e700b980999ea8f1b277e49',
                'size' => 95099992,
            ],
        ],
    ],
    'aliases' => [
        '1.13.0-r1' => 'php8.5.11-fp1.13.0-r1',
        '1.13.0-r2' => 'php8.5.11-fp1.13.0-r2',
    ],
];
