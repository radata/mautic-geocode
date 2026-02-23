<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Service;

use Psr\Log\LoggerInterface;

class PdokProvider implements ProviderInterface
{
    private const API_URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free';

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'PDOK Locatieserver';
    }

    public function geocode(string $query): ?array
    {
        if ('' === trim($query)) {
            return null;
        }

        try {
            $url = self::API_URL.'?'.http_build_query([
                'q'    => $query,
                'rows' => 1,
                'fq'   => 'type:adres',
            ]);

            $context = stream_context_create([
                'http' => [
                    'header'  => "User-Agent: MauticGeocoder/1.0\r\n",
                    'timeout' => 10,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            if (false === $response) {
                $this->logger->warning('Geocoder PDOK: HTTP request failed for query "{query}".', [
                    'query' => $query,
                ]);

                return null;
            }

            $data = json_decode($response, true);

            if (empty($data['response']['docs'])) {
                $this->logger->info('Geocoder PDOK: no results for query "{query}".', [
                    'query' => $query,
                ]);

                return null;
            }

            $doc = $data['response']['docs'][0];
            $wkt = $doc['centroide_ll'] ?? '';

            // Parse WKT format: "POINT(longitude latitude)"
            if (preg_match('/POINT\(([0-9.-]+)\s+([0-9.-]+)\)/', $wkt, $matches)) {
                $lng = (float) $matches[1];
                $lat = (float) $matches[2];

                $this->logger->info('Geocoder PDOK: "{query}" → {lat}, {lng}', [
                    'query' => $query,
                    'lat'   => $lat,
                    'lng'   => $lng,
                ]);

                return ['lat' => $lat, 'lng' => $lng];
            }

            $this->logger->warning('Geocoder PDOK: could not parse centroide_ll "{wkt}".', [
                'wkt'   => $wkt,
                'query' => $query,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Geocoder PDOK: exception for query "{query}": {message}', [
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
        // Best: zipcode + house_number + addition (dedicated fields)
        if ('' !== $zipcode && '' !== $houseNumber) {
            $query = $zipcode.' '.$houseNumber;
            if ('' !== $houseNumberAddition) {
                $query .= $houseNumberAddition;
            }

            return $query;
        }

        // Fallback: zipcode + extract number from address1
        if ('' !== $zipcode) {
            $num = '';
            if ('' !== $address1 && preg_match('/(\d+)/', $address1, $matches)) {
                $num = $matches[1];
            }

            return '' !== $num ? $zipcode.' '.$num : $zipcode.' '.$city;
        }

        // Last resort: address1 + city
        return trim($address1.' '.$city);
    }
}
