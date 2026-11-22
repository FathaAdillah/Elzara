<?php

declare(strict_types=1);

namespace App\Platform\Domain\Exception;

/** Baris masih dirujuk foreign key RESTRICT sehingga tidak dapat dihapus. */
final class ResourceInUse extends DomainException {}
