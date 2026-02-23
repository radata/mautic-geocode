<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\EventListener;

use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticGeocoderBundle\Service\GeocoderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class LeadSubscriber implements EventSubscriberInterface
{
    private const GEO_FIELDS = ['latitude', 'longitude'];

    /**
     * Guard against re-entry when we save lat/lng back to the contact.
     */
    private bool $geocoding = false;

    public function __construct(
        private GeocoderService $geocoderService,
        private LeadModel $leadModel,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string|array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_POST_SAVE => ['onLeadPostSave', -10],
        ];
    }

    /**
     * Map of PDOK result keys → Mautic field aliases.
     */
    private const ADDRESS_FIELD_MAP = [
        'straatnaam'      => 'straatnaam',
        'huisnummer'      => 'house_number',
        'huisletter'      => 'house_number_addition',
        'gemeente_code'   => 'gemeente_code',
        'gemeente_naam'   => 'gemeente_naam',
        'provincie_code'  => 'provincie_code',
        'provincie_naam'  => 'state',
        'woonplaatsnaam'  => 'city',
    ];

    public function onLeadPostSave(LeadEvent $event): void
    {
        // Re-entry guard: we're already inside a geocode save
        if ($this->geocoding) {
            return;
        }

        try {
            if (!$this->geocoderService->isEnabled()) {
                $this->logger->debug('Geocoder: disabled, skipping.');
                return;
            }

            $lead = $event->getLead();
            $leadId = $lead->getId();

            // Check if contact has address data worth geocoding
            $address1 = trim((string) $lead->getFieldValue('address1'));
            $city     = trim((string) $lead->getFieldValue('city'));
            $zipcode  = trim((string) $lead->getFieldValue('zipcode'));

            if ('' === $address1 && '' === $city && '' === $zipcode) {
                $this->logger->debug('Geocoder: contact #{id} has no address fields, skipping.', [
                    'id' => $leadId,
                ]);
                return;
            }

            // Check if coordinates already exist
            $currentLat = $lead->getFieldValue('latitude');
            $currentLng = $lead->getFieldValue('longitude');
            $hasCoords  = !empty($currentLat) && !empty($currentLng)
                && 0.0 !== (float) $currentLat && 0.0 !== (float) $currentLng;

            $settings  = $this->geocoderService->getSettings();
            $overwrite = !empty($settings['overwrite_existing']);

            if ($hasCoords && !$overwrite) {
                $this->logger->debug('Geocoder: contact #{id} already has coords, skipping.', [
                    'id' => $leadId,
                ]);
                return;
            }

            // Geocode
            $this->logger->info('Geocoder: geocoding contact #{id}...', ['id' => $leadId]);

            $coords = $this->geocoderService->geocodeContact($lead);

            if (null === $coords) {
                $this->logger->warning('Geocoder: no result for contact #{id}.', ['id' => $leadId]);
                return;
            }

            // Save coordinates and address details with re-entry guard
            $this->geocoding = true;
            try {
                $lead->addUpdatedField('latitude', (string) $coords['lat']);
                $lead->addUpdatedField('longitude', (string) $coords['lng']);

                // Save PDOK address details when available
                $this->applyAddressDetails($lead, $coords);

                $this->leadModel->saveEntity($lead);

                $this->logger->info('Geocoder: contact #{id} → {lat}, {lng}', [
                    'id'  => $leadId,
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                ]);
            } finally {
                $this->geocoding = false;
            }
        } catch (\Exception $e) {
            $this->geocoding = false;
            $this->logger->error('Geocoder: error for contact #{id}: {message}', [
                'id'      => $event->getLead()->getId(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply PDOK address detail fields to the contact.
     *
     * @param array<string, mixed> $result
     */
    private function applyAddressDetails(\Mautic\LeadBundle\Entity\Lead $lead, array $result): void
    {
        foreach (self::ADDRESS_FIELD_MAP as $resultKey => $fieldAlias) {
            $value = trim((string) ($result[$resultKey] ?? ''));

            if ('' === $value) {
                continue;
            }

            $lead->addUpdatedField($fieldAlias, $value);
        }

        // Build address1 from straatnaam + huisnummer + huisletter
        $straat = trim((string) ($result['straatnaam'] ?? ''));
        $nummer = trim((string) ($result['huisnummer'] ?? ''));
        $letter = trim((string) ($result['huisletter'] ?? ''));

        if ('' !== $straat && '' !== $nummer) {
            $address1 = $straat.' '.$nummer;
            if ('' !== $letter) {
                $address1 .= $letter;
            }
            $lead->addUpdatedField('address1', $address1);
        }
    }
}
