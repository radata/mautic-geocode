<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\EventListener;

use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\MauticGeocoderBundle\Helper\FieldInstaller;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private FieldInstaller $fieldInstaller,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string|array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onInstall', 0],
            PluginEvents::ON_PLUGIN_UPDATE  => ['onUpdate', 0],
        ];
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        if (!$event->checkContext('Geocoder')) {
            return;
        }

        $this->logger->info('Geocoder: plugin install - creating custom fields.');
        $this->fieldInstaller->installFields();
    }

    public function onUpdate(PluginUpdateEvent $event): void
    {
        if (!$event->checkContext('Geocoder')) {
            return;
        }

        $this->logger->info('Geocoder: plugin update - ensuring custom fields exist.');
        $this->fieldInstaller->installFields();
    }
}
