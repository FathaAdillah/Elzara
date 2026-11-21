<?php

declare(strict_types=1);

namespace App\Modules\Settings\Domain;

use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** @extends ModelNotFoundException<FeatureFlag> */
final class MissingFeatureFlag extends ModelNotFoundException {}
