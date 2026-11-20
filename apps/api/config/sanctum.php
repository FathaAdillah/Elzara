<?php

declare(strict_types=1);

$statefulHosts = [];
foreach ([env('APP_URL', 'https://localhost'), env('ELZARA_ADMIN_ORIGIN')] as $origin) {
    if (is_string($origin) && ($host = parse_url($origin, PHP_URL_HOST)) !== null && $host !== false) {
        $port = parse_url($origin, PHP_URL_PORT);
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $nonDefaultPort = is_int($port) && ! ($scheme === 'http' && $port === 80) && ! ($scheme === 'https' && $port === 443);
        $statefulHosts[] = $nonDefaultPort ? $host.':'.$port : $host;
    }
}

return [
    'stateful' => array_values(array_unique($statefulHosts)),
];
