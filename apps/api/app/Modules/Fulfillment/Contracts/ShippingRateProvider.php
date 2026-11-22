<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Contracts;

interface ShippingRateProvider
{
    /** @return list<ShippingRate>
     * @throws InvalidDestination
     * @throws ProviderUnavailable
     */
    public function rates(ShippingRateRequest $request): array;
}
