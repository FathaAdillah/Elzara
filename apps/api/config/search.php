<?php

declare(strict_types=1);

// Elasticsearch Basic hanya proyeksi katalog (Doc 01 §3.1); PostgreSQL tetap sumber kebenaran.
return [
    'url' => env('ELASTICSEARCH_URL'),
    'api_key' => env('ELASTICSEARCH_API_KEY'),
    'timeout' => (int) env('ELASTICSEARCH_TIMEOUT', 5),
    'products_alias' => env('ELASTICSEARCH_PRODUCTS_ALIAS', 'elzara_products'),

    // Sinonim eksplisit hasil kurasi (format Solr), dipakai saat query lewat synonym_graph.
    'synonyms' => [
        'kaos, t-shirt, tshirt, tee',
        'jaket, jacket, hoodie',
        'tas, bag, totebag, tote bag',
        'boneka, plush, plushie, doll',
        'gantungan kunci, keychain, ganci',
        'stiker, sticker',
        'figur, figure, figurine',
        'poster, print',
        'topi, cap, hat',
        'pin, badge, enamel pin',
    ],
];
