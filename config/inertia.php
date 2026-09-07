<?php

declare(strict_types=1);

return [

    /*
     * Halaman Inertia berada di resources/js/pages (huruf kecil), mengikuti resolver di
     * resources/js/app.tsx. Path ini dipakai oleh assertInertia() untuk memastikan
     * komponen yang dirender controller benar-benar ada, sehingga typo nama halaman
     * gagal di test, bukan di browser.
     */
    'testing' => [
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'tsx',
        ],
    ],

];
