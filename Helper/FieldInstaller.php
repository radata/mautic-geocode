<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Helper;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Psr\Log\LoggerInterface;

class FieldInstaller
{
    // Contact custom fields
    private const LEAD_FIELDS = [
        [
            'alias'   => 'house_number',
            'label'   => 'House Number',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => true,
            'properties' => [],
        ],
        [
            'alias'   => 'house_number_addition',
            'label'   => 'House Number Addition',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => true,
            'properties' => [],
        ],
        [
            'alias'   => 'straatnaam',
            'label'   => 'Street Name',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'gemeente_code',
            'label'   => 'Municipality Code',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'gemeente_naam',
            'label'   => 'Municipality Name',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'provincie_code',
            'label'   => 'Province Code',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'latitude',
            'label'   => 'Latitude',
            'type'    => 'number',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => false,
            'properties' => ['roundmode' => 4, 'scale' => 8],
        ],
        [
            'alias'   => 'longitude',
            'label'   => 'Longitude',
            'type'    => 'number',
            'group'   => 'core',
            'object'  => 'lead',
            'visible' => false,
            'properties' => ['roundmode' => 4, 'scale' => 8],
        ],
    ];

    // Company custom fields (aliases follow Mautic's company* prefix convention)
    private const COMPANY_FIELDS = [
        [
            'alias'   => 'companyhouse_number',
            'label'   => 'Company House Number',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => true,
            'properties' => [],
        ],
        [
            'alias'   => 'companyhouse_number_addition',
            'label'   => 'Company House Number Addition',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => true,
            'properties' => [],
        ],
        [
            'alias'   => 'companystraatnaam',
            'label'   => 'Company Street Name',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'companygemeente_code',
            'label'   => 'Company Municipality Code',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'companygemeente_naam',
            'label'   => 'Company Municipality Name',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'companyprovincie_code',
            'label'   => 'Company Province Code',
            'type'    => 'text',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => false,
            'properties' => [],
        ],
        [
            'alias'   => 'companylatitude',
            'label'   => 'Company Latitude',
            'type'    => 'number',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => false,
            'properties' => ['roundmode' => 4, 'scale' => 8],
        ],
        [
            'alias'   => 'companylongitude',
            'label'   => 'Company Longitude',
            'type'    => 'number',
            'group'   => 'core',
            'object'  => 'company',
            'visible' => false,
            'properties' => ['roundmode' => 4, 'scale' => 8],
        ],
    ];

    public function __construct(
        private FieldModel $fieldModel,
        private LoggerInterface $logger,
    ) {
    }

    private function allFields(): array
    {
        return array_merge(self::LEAD_FIELDS, self::COMPANY_FIELDS);
    }

    public function installFields(): void
    {
        foreach ($this->allFields() as $config) {
            $existing = $this->fieldModel->getEntityByAlias($config['alias']);

            if ($existing) {
                // Update visibility if it changed
                $shouldBeVisible = $config['visible'] ?? true;
                if ($existing->getIsVisible() !== $shouldBeVisible) {
                    $existing->setIsVisible($shouldBeVisible);
                    $this->fieldModel->saveEntity($existing);
                    $this->logger->info('Geocoder: updated visibility for field "{alias}" → {visible}.', [
                        'alias'   => $config['alias'],
                        'visible' => $shouldBeVisible ? 'visible' : 'hidden',
                    ]);
                }
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
                $field->setIsVisible($config['visible'] ?? true);
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
        foreach ($this->allFields() as $config) {
            $field = $this->fieldModel->getEntityByAlias($config['alias']);
            if (null === $field) {
                return false;
            }
            // Also return false if visibility needs updating
            $shouldBeVisible = $config['visible'] ?? true;
            if ($field->getIsVisible() !== $shouldBeVisible) {
                return false;
            }
        }

        return true;
    }
}
