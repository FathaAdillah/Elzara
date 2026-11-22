<?php

declare(strict_types=1);

namespace App\Platform\Domain\Exception;

/** K6: `version` kiriman klien tidak sama dengan baris terkini, tulis ditolak alih-alih menimpa. */
final class VersionStale extends DomainException {}
