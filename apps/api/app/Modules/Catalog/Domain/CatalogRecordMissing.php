<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** @extends ModelNotFoundException<Model> */
final class CatalogRecordMissing extends ModelNotFoundException {}
