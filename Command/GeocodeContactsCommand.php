<?php

declare(strict_types=1);

namespace MauticPlugin\MauticGeocoderBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\MauticGeocoderBundle\Helper\FieldInstaller;
use MauticPlugin\MauticGeocoderBundle\Service\GeocoderService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mautic:contacts:geocode',
    description: 'Geocode contact addresses into latitude/longitude coordinates',
)]
class GeocodeContactsCommand extends Command
{
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

    public function __construct(
        private GeocoderService $geocoderService,
        private FieldInstaller $fieldInstaller,
        private LeadModel $leadModel,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Contacts per batch', 100)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max contacts to process (0 = unlimited)', 0)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-geocode contacts that already have coordinates')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be geocoded without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $batch    = (int) $input->getOption('batch');
        $limit    = (int) $input->getOption('limit');
        $force    = (bool) $input->getOption('force');
        $dryRun   = (bool) $input->getOption('dry-run');

        $io->title('Mautic Contact Geocoder');

        // Ensure custom fields exist
        if (!$this->fieldInstaller->fieldsExist()) {
            $io->note('Creating latitude/longitude custom fields...');
            $this->fieldInstaller->installFields();
        }

        // Build query for contacts to geocode
        $conn = $this->entityManager->getConnection();
        $qb   = $conn->createQueryBuilder();

        $qb->select('l.id')
            ->from(MAUTIC_TABLE_PREFIX.'leads', 'l')
            ->where(
                $qb->expr()->or(
                    "l.address1 IS NOT NULL AND l.address1 != ''",
                    "l.zipcode IS NOT NULL AND l.zipcode != ''",
                    "l.city IS NOT NULL AND l.city != ''"
                )
            )
            ->orderBy('l.id', 'ASC');

        if (!$force) {
            $qb->andWhere('(l.latitude IS NULL OR l.latitude = 0)');
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $contactIds = array_column($qb->executeQuery()->fetchAllAssociative(), 'id');
        $total      = \count($contactIds);

        if (0 === $total) {
            $io->success('No contacts to geocode.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contacts to geocode.', $total));

        if ($dryRun) {
            $io->note('DRY RUN - no changes will be made.');
        }

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        $geocoded = 0;
        $failed   = 0;
        $skipped  = 0;

        $batches = array_chunk($contactIds, $batch);

        foreach ($batches as $batchIds) {
            foreach ($batchIds as $contactId) {
                $lead = $this->leadModel->getEntity($contactId);

                if (!$lead) {
                    ++$skipped;
                    $progressBar->advance();
                    continue;
                }

                $address1 = trim((string) $lead->getFieldValue('address1'));
                $city     = trim((string) $lead->getFieldValue('city'));
                $zipcode  = trim((string) $lead->getFieldValue('zipcode'));

                if ('' === $address1 && '' === $city && '' === $zipcode) {
                    ++$skipped;
                    $progressBar->advance();
                    continue;
                }

                if ($dryRun) {
                    $query = sprintf('%s, %s %s', $address1, $zipcode, $city);
                    $io->writeln(sprintf('  Would geocode #%d: %s', $contactId, trim($query, ', ')));
                    ++$geocoded;
                    $progressBar->advance();
                    continue;
                }

                $coords = $this->geocoderService->geocodeContact($lead, true);

                if (null !== $coords) {
                    $lead->addUpdatedField('latitude', (string) $coords['lat']);
                    $lead->addUpdatedField('longitude', (string) $coords['lng']);

                    // Save PDOK address details
                    $this->applyAddressDetails($lead, $coords);

                    $this->leadModel->saveEntity($lead);
                    ++$geocoded;
                } else {
                    ++$failed;
                }

                $progressBar->advance();
            }

            // Clear entity manager to prevent memory leaks
            $this->entityManager->clear();
        }

        $progressBar->finish();
        $output->writeln('');

        $io->newLine();
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total processed', (string) $total],
                ['Geocoded', (string) $geocoded],
                ['Failed / no result', (string) $failed],
                ['Skipped', (string) $skipped],
            ]
        );

        $io->success('Geocoding complete.');

        return Command::SUCCESS;
    }

    /**
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
