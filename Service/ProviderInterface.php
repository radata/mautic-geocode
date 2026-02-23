<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Service;

interface ProviderInterface
{
    /**
     * Geocode a query string into coordinates (and optional address details).
     *
     * @return array{lat: float, lng: float, straatnaam?: string, huisnummer?: string, huisletter?: string, postcode?: string, woonplaatsnaam?: string, gemeente_code?: string, gemeente_naam?: string, provincie_code?: string, provincie_naam?: string}|null
     */
    public function geocode(string $query): ?array;

    /**
     * Build an optimized query string from address components.
     */
    public function buildQuery(
        string $zipcode,
        string $houseNumber,
        string $houseNumberAddition,
        string $address1,
        string $city,
        string $country,
    ): string;

    public function getName(): string;
}
