<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class GeocoderIntegration extends AbstractIntegration
{
    protected bool $coreIntegration = false;

    public function getName(): string
    {
        return 'Geocoder';
    }

    public function getDisplayName(): string
    {
        return 'Geocoder - Address to Lat/Lng';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getSecretKeys(): array
    {
        return [];
    }

    public function getRequiredKeyFields(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFormSettings(): array
    {
        return [
            'requires_callback'      => false,
            'requires_authorization' => false,
            'default_features'       => [],
            'enable_data_priority'   => false,
        ];
    }

    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' !== $formArea) {
            return;
        }

        $builder->add(
            'auto_geocode',
            CheckboxType::class,
            [
                'label'    => 'geocoder.config.auto_geocode',
                'required' => false,
                'data'     => (bool) ($data['auto_geocode'] ?? true),
                'attr'     => [
                    'tooltip' => 'geocoder.config.auto_geocode.tooltip',
                ],
            ]
        );

        $builder->add(
            'default_provider',
            ChoiceType::class,
            [
                'label'   => 'geocoder.config.default_provider',
                'choices' => [
                    'PDOK Locatieserver (Dutch)'  => 'pdok',
                    'Nominatim (International)'   => 'nominatim',
                ],
                'data'     => $data['default_provider'] ?? 'pdok',
                'required' => true,
                'attr'     => [
                    'class' => 'form-control',
                ],
            ]
        );

        $builder->add(
            'fallback_to_nominatim',
            CheckboxType::class,
            [
                'label'    => 'geocoder.config.fallback_to_nominatim',
                'required' => false,
                'data'     => (bool) ($data['fallback_to_nominatim'] ?? true),
                'attr'     => [
                    'tooltip' => 'geocoder.config.fallback_to_nominatim.tooltip',
                ],
            ]
        );

        $builder->add(
            'nominatim_user_agent',
            TextType::class,
            [
                'label'    => 'geocoder.config.nominatim_user_agent',
                'required' => false,
                'data'     => $data['nominatim_user_agent'] ?? 'MauticGeocoder/1.0 (hollandworx.nl)',
                'attr'     => [
                    'class'       => 'form-control',
                    'tooltip'     => 'geocoder.config.nominatim_user_agent.tooltip',
                    'placeholder' => 'MauticGeocoder/1.0 (yourdomain.com)',
                ],
            ]
        );

        $builder->add(
            'rate_limit_ms',
            NumberType::class,
            [
                'label'    => 'geocoder.config.rate_limit_ms',
                'required' => false,
                'data'     => (int) ($data['rate_limit_ms'] ?? 1100),
                'attr'     => [
                    'class'       => 'form-control',
                    'tooltip'     => 'geocoder.config.rate_limit_ms.tooltip',
                    'placeholder' => '1100',
                ],
            ]
        );

        $builder->add(
            'overwrite_existing',
            CheckboxType::class,
            [
                'label'    => 'geocoder.config.overwrite_existing',
                'required' => false,
                'data'     => (bool) ($data['overwrite_existing'] ?? false),
                'attr'     => [
                    'tooltip' => 'geocoder.config.overwrite_existing.tooltip',
                ],
            ]
        );

        $builder->add(
            'dutch_country_values',
            TextType::class,
            [
                'label'    => 'geocoder.config.dutch_country_values',
                'required' => false,
                'data'     => $data['dutch_country_values'] ?? 'Netherlands,Nederland,NL,',
                'attr'     => [
                    'class'       => 'form-control',
                    'tooltip'     => 'geocoder.config.dutch_country_values.tooltip',
                    'placeholder' => 'Netherlands,Nederland,NL,',
                ],
            ]
        );
    }
}
