<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class NominatimProvider implements ProviderInterface
{
    private const API_URL = 'https://nominatim.openstreetmap.org/search';

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Nominatim';
    }

    public function geocode(string $query): ?array
    {
        if ('' === trim($query)) {
            return null;
        }

        try {
            $url = self::API_URL.'?'.http_build_query([
                'q'      => $query,
                'format' => 'json',
                'limit'  => 1,
            ]);

            $userAgent = $this->getUserAgent();

            $context = stream_context_create([
                'http' => [
                    'header'  => "User-Agent: {$userAgent}\r\n",
                    'timeout' => 10,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            if (false === $response) {
                $this->logger->warning('Geocoder Nominatim: HTTP request failed for query "{query}".', [
                    'query' => $query,
                ]);

                return null;
            }

            $data = json_decode($response, true);

            if (empty($data[0])) {
                $this->logger->debug('Geocoder Nominatim: no results for query "{query}".', [
                    'query' => $query,
                ]);

                return null;
            }

            $lat = (float) $data[0]['lat'];
            $lng = (float) $data[0]['lon'];

            $this->logger->debug('Geocoder Nominatim: resolved "{query}" to {lat}, {lng}.', [
                'query' => $query,
                'lat'   => $lat,
                'lng'   => $lng,
            ]);

            return ['lat' => $lat, 'lng' => $lng];
        } catch (\Exception $e) {
            $this->logger->error('Geocoder Nominatim: exception for query "{query}": {message}', [
                'query'   => $query,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function buildQuery(
        string $zipcode,
        string $houseNumber,
        string $houseNumberAddition,
        string $address1,
        string $city,
        string $country,
    ): string {
        // If we have house_number but address1 doesn't include it, prepend it
        $street = $address1;
        if ('' !== $houseNumber && '' !== $address1 && !str_contains($address1, $houseNumber)) {
            $num = $houseNumber;
            if ('' !== $houseNumberAddition) {
                $num .= $houseNumberAddition;
            }
            $street = $address1.' '.$num;
        }

        $parts = array_filter([
            $street,
            $city,
            $zipcode,
            $country,
        ], static fn (string $v): bool => '' !== $v);

        return implode(', ', $parts);
    }

    private function getUserAgent(): string
    {
        $integration = $this->integrationHelper->getIntegrationObject('Geocoder');

        if (!$integration) {
            return 'MauticGeocoder/1.0';
        }

        $settings = $integration->getIntegrationSettings()->getFeatureSettings();

        return $settings['nominatim_user_agent'] ?? 'MauticGeocoder/1.0';
    }
}
