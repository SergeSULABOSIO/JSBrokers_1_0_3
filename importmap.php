<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    'tom-select' => [
        'version' => '2.3.1',
    ],
    'tom-select/dist/css/tom-select.bootstrap5.css' => [
        'version' => '2.3.1',
        'type' => 'css',
    ],
    'chart.js' => [
        'version' => '3.9.1',
    ],
    'bootstrap' => [
        'version' => '5.3.6',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.6',
        'type' => 'css',
    ],
    'tom-select/dist/css/tom-select.default.css' => [
        'version' => '2.4.3',
        'type' => 'css',
    ],
    'tom-select/dist/css/tom-select.bootstrap4.css' => [
        'version' => '2.4.3',
        'type' => 'css',
    ],
    'marked' => [
        'version' => '18.0.6',
    ],
    'dompurify' => [
        'version' => '3.4.16',
    ],
    'html2canvas' => [
        'version' => '1.4.1',
    ],
    'quill' => [
        'version' => '2.0.2',
    ],
    'quill/dist/quill.snow.css' => [
        'version' => '2.0.2',
        'type' => 'css',
    ],
    'es-module-shims' => [
        'version' => '1.10.0',
    ],
    'lodash-es' => [
        'version' => '4.18.1',
    ],
    'parchment' => [
        'version' => '3.0.0',
    ],
    'quill-delta' => [
        'version' => '5.1.0',
    ],
    'eventemitter3' => [
        'version' => '5.0.1',
    ],
    'fast-diff' => [
        'version' => '1.3.0',
    ],
    'lodash.clonedeep' => [
        'version' => '4.5.0',
    ],
    'lodash.isequal' => [
        'version' => '4.5.0',
    ],
];
