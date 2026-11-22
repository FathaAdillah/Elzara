<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use RuntimeException;

/** Elasticsearch tidak terjangkau atau membalas 5xx; dipetakan ke 503 oleh controller. */
final class SearchUnavailable extends RuntimeException {}
