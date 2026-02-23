<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Service;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class GeocoderService
{
    private float $lastRequestTime = 0;

    public function __construct(
        private PdokProvider $pdokProvider,
        private NominatimProvider $nominatimProvider,
        private IntegrationHelper $integrationHelper,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        $integration = $this->integrationHelper->getIntegrationObject('Geocoder');

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return false;
        }

        $settings = $integration->getIntegrationSettings()->getFeatureSettings();

        // Default to enabled when feature settings haven't been saved yet
        if (empty($settings)) {
            return true;
        }

        return !empty($settings['auto_geocode']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $integration = $this->integrationHelper->getIntegrationObject('Geocoder');

        if (!$integration) {
            return [];
        }

        return $integration->getIntegrationSettings()->getFeatureSettings() ?? [];
    }

    /**
     * Geocode a contact's address into coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocodeContact(Lead $lead, bool $batchMode = false): ?array
    {
        $address1             = trim((string) $lead->getFieldValue('address1'));
        $city                 = trim((string) $lead->getFieldValue('city'));
        $zipcode              = trim((string) $lead->getFieldValue('zipcode'));
        $country              = trim((string) $lead->getFieldValue('country'));
        $houseNumber          = trim((string) $lead->getFieldValue('house_number'));
        $houseNumberAddition  = trim((string) $lead->getFieldValue('house_number_addition'));

        // Need at least one address component to geocode
        if ('' === $address1 && '' === $city && '' === $zipcode) {
            return null;
        }

        return $this->geocodeAddress($zipcode, $houseNumber, $houseNumberAddition, $address1, $city, $country, $batchMode);
    }

    /**
     * Geocode raw address components into coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocodeAddress(
        string $zipcode,
        string $houseNumber,
        string $houseNumberAddition,
        string $address1,
        string $city,
        string $country,
        bool $batchMode = false,
    ): ?array {
        $settings = $this->getSettings();
        $isDutch  = $this->isDutchAddress($country);
        $provider = $this->selectProvider($isDutch);

        $query = $provider->buildQuery($zipcode, $houseNumber, $houseNumberAddition, $address1, $city, $country);

        if ('' === trim($query)) {
            return null;
        }

        // Rate limiting only in batch mode (web requests should not block)
        if ($batchMode) {
            $this->enforceRateLimit();
        }

        $this->logger->debug('Geocoder: using {provider} for query "{query}".', [
            'provider' => $provider->getName(),
            'query'    => $query,
        ]);

        $result = $provider->geocode($query);

        // Try fallback if primary returned nothing and fallback is enabled
        if (null === $result && $this->shouldFallback($isDutch)) {
            $fallback = $this->getFallbackProvider($isDutch);

            if (null !== $fallback) {
                $fallbackQuery = $fallback->buildQuery($zipcode, $houseNumber, $houseNumberAddition, $address1, $city, $country);

                if ($batchMode) {
                    $this->enforceRateLimit();
                }

                $this->logger->debug('Geocoder: falling back to {provider} for query "{query}".', [
                    'provider' => $fallback->getName(),
                    'query'    => $fallbackQuery,
                ]);

                $result = $fallback->geocode($fallbackQuery);
            }
        }

        return $result;
    }

    public function isDutchAddress(string $country): bool
    {
        $settings    = $this->getSettings();
        $dutchValues = $settings['dutch_country_values'] ?? 'Netherlands,Nederland,NL,';
        $dutchList   = array_map('trim', explode(',', $dutchValues));

        return \in_array($country, $dutchList, true);
    }

    private function selectProvider(bool $isDutch): ProviderInterface
    {
        $settings        = $this->getSettings();
        $defaultProvider = $settings['default_provider'] ?? 'pdok';

        if ('pdok' === $defaultProvider && $isDutch) {
            return $this->pdokProvider;
        }

        if ('nominatim' === $defaultProvider) {
            return $this->nominatimProvider;
        }

        // Default provider is PDOK but address is not Dutch - use Nominatim if fallback enabled
        if (!$isDutch && !empty($settings['fallback_to_nominatim'])) {
            return $this->nominatimProvider;
        }

        // Use whatever the default is
        return 'pdok' === $defaultProvider ? $this->pdokProvider : $this->nominatimProvider;
    }

    private function shouldFallback(bool $isDutch): bool
    {
        $settings = $this->getSettings();

        // If primary was PDOK and it failed, try Nominatim
        if ('pdok' === ($settings['default_provider'] ?? 'pdok') && !empty($settings['fallback_to_nominatim'])) {
            return true;
        }

        return false;
    }

    private function getFallbackProvider(bool $isDutch): ?ProviderInterface
    {
        $settings = $this->getSettings();

        if ('pdok' === ($settings['default_provider'] ?? 'pdok') && !empty($settings['fallback_to_nominatim'])) {
            return $this->nominatimProvider;
        }

        return null;
    }

    private function enforceRateLimit(): void
    {
        $settings = $this->getSettings();
        $rateMs   = (int) ($settings['rate_limit_ms'] ?? 1100);
        $now      = microtime(true) * 1000;
        $elapsed  = $now - $this->lastRequestTime;

        if ($this->lastRequestTime > 0 && $elapsed < $rateMs) {
            usleep((int) (($rateMs - $elapsed) * 1000));
        }

        $this->lastRequestTime = microtime(true) * 1000;
    }
}
