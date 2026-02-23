<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Helper;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Psr\Log\LoggerInterface;

class FieldInstaller
{
    private const FIELDS = [
        [
            'alias'   => 'latitude',
            'label'   => 'Latitude',
            'type'    => 'number',
            'group'   => 'core',
            'object'  => 'lead',
            'properties' => ['roundmode' => 4, 'scale' => 8],
        ],
        [
            'alias'   => 'longitude',
            'label'   => 'Longitude',
            'type'    => 'number',
            'group'   => 'core',
            'object'  => 'lead',
            'properties' => ['roundmode' => 4, 'scale' => 8],
        ],
    ];

    public function __construct(
        private FieldModel $fieldModel,
        private LoggerInterface $logger,
    ) {
    }

    public function installFields(): void
    {
        foreach (self::FIELDS as $config) {
            $existing = $this->fieldModel->getEntityByAlias($config['alias']);

            if ($existing) {
                $this->logger->info('Geocoder: field "{alias}" already exists, skipping.', [
                    'alias' => $config['alias'],
                ]);
                continue;
            }

            try {
                $field = new LeadField();
                $field->setAlias($config['alias']);
                $field->setLabel($config['label']);
                $field->setType($config['type']);
                $field->setGroup($config['group']);
                $field->setObject($config['object']);
                $field->setIsPublished(true);
                $field->setIsListable(true);
                $field->setIsVisible(true);
                $field->setProperties($config['properties']);

                $this->fieldModel->saveEntity($field);

                $this->logger->info('Geocoder: created custom field "{alias}".', [
                    'alias' => $config['alias'],
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Geocoder: failed to create field "{alias}": {message}', [
                    'alias'   => $config['alias'],
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function fieldsExist(): bool
    {
        return null !== $this->fieldModel->getEntityByAlias('latitude')
            && null !== $this->fieldModel->getEntityByAlias('longitude');
    }
}
