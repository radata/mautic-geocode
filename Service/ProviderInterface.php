<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Service;

interface ProviderInterface
{
    /**
     * Geocode a query string into coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $query): ?array;

    /**
     * Build an optimized query string from address components.
     */
    public function buildQuery(
        string $address1,
        string $city,
        string $zipcode,
        string $country,
    ): string;

    public function getName(): string;
}
