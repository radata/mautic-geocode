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
                $this->logger->debug('Geocoder PDOK: no results for query "{query}".', [
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

                $this->logger->debug('Geocoder PDOK: resolved "{query}" to {lat}, {lng}.', [
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
        string $address1,
        string $city,
        string $zipcode,
        string $country,
    ): string {
        $parts = [];

        // For PDOK, zipcode + house number is the most accurate query
        if ('' !== $zipcode) {
            // Extract house number from address1 (e.g., "Lotusbloemweg 88" -> "88")
            $houseNumber = '';
            if ('' !== $address1 && preg_match('/(\d+)/', $address1, $matches)) {
                $houseNumber = $matches[1];
            }

            $parts[] = $zipcode;

            if ('' !== $houseNumber) {
                $parts[] = $houseNumber;
            }
        } elseif ('' !== $address1) {
            // Fallback: use full address
            $parts[] = $address1;
        }

        if ('' !== $city) {
            $parts[] = $city;
        }

        return implode(' ', $parts);
    }
}
