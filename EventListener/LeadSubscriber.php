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
    private const ADDRESS_FIELDS = ['address1', 'address2', 'city', 'state', 'zipcode', 'country'];
    private const GEO_FIELDS     = ['latitude', 'longitude'];

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

    public function onLeadPostSave(LeadEvent $event): void
    {
        try {
            if (!$this->geocoderService->isEnabled()) {
                return;
            }

            $lead    = $event->getLead();
            $changes = $lead->getChanges(true);

            // Prevent infinite loop: if only geo fields changed, we caused this event
            if (!empty($changes['fields'])) {
                $changedFields = array_keys($changes['fields']);
                if (empty(array_diff($changedFields, self::GEO_FIELDS))) {
                    return;
                }
            }

            // Check if any address field changed
            $addressChanged = false;
            if (!empty($changes['fields'])) {
                foreach (self::ADDRESS_FIELDS as $field) {
                    if (isset($changes['fields'][$field])) {
                        $addressChanged = true;
                        break;
                    }
                }
            }

            // Check current coordinate state
            $currentLat = $lead->getFieldValue('latitude');
            $currentLng = $lead->getFieldValue('longitude');
            $hasCoords  = !empty($currentLat) && !empty($currentLng);

            $settings  = $this->geocoderService->getSettings();
            $overwrite = !empty($settings['overwrite_existing']);
            $isNew     = $event->isNew();

            // Decide whether to geocode:
            // 1. New contact with address but no coords → geocode
            // 2. Address changed and no coords → geocode
            // 3. Address changed and has coords and overwrite enabled → geocode
            // 4. Otherwise → skip
            if ($hasCoords && !$overwrite) {
                return;
            }

            if (!$isNew && !$addressChanged) {
                return;
            }

            $coords = $this->geocoderService->geocodeContact($lead);

            if (null === $coords) {
                return;
            }

            $lead->addUpdatedField('latitude', (string) $coords['lat']);
            $lead->addUpdatedField('longitude', (string) $coords['lng']);
            $this->leadModel->saveEntity($lead);

            $this->logger->info('Geocoder: geocoded contact #{id} to {lat}, {lng}.', [
                'id'  => $lead->getId(),
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Geocoder: failed to geocode contact #{id}: {message}', [
                'id'      => $event->getLead()->getId(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
