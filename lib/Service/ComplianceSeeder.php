<?php

/**
 * Shillinq Compliance Seeder
 *
 * Imports the T3 compliance reference data (BTW tariffs, BBV taakvelden,
 * RGS↔BBV mapping, Selectielijst retention rules, rate-card templates) into
 * OpenRegister, idempotently. Each seed file is record-based (`records[]`);
 * records are matched on a per-file key field so operator-authored overrides
 * are preserved across repair re-runs (ADR-022 seed-override contract,
 * REQ-BBV-006, REQ-ARC-008, Task 3.11).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-archiefwet-retention/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Seeds T3 compliance reference data into OpenRegister.
 *
 * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-archiefwet-retention/spec.md
 */
class ComplianceSeeder
{
    /**
     * Mapping of T3 compliance seed file → target schema + dedup key field + items array key.
     *
     * @var array<int, array{file: string, schema: string, key: string, itemsKey: string}>
     */
    private const COMPLIANCE_SEEDS = [
        ['file' => 'btw-tariffs-2026.json',             'schema' => 'VatTariff',         'key' => 'code',               'itemsKey' => 'tariffs'],
        ['file' => 'bbv-taakvelden-2024.json',          'schema' => 'BbvTaakveld',       'key' => 'code',               'itemsKey' => 'taakvelden'],
        ['file' => 'rgs-to-bbv-mapping.json',           'schema' => 'BbvAccountMapping', 'key' => 'accountNumber',      'itemsKey' => 'records'],
        ['file' => 'selectielijst-gemeenten-2020.json', 'schema' => 'RetentionRule', 'key' => 'selectielijstCode', 'itemsKey' => 'retentionRules'],
        ['file' => 'rate-card-templates.json',          'schema' => 'RateCard',          'key' => 'level',              'itemsKey' => 'rateCards'],
    ];

    /**
     * Construct the seeder.
     *
     * @param ContainerInterface $container    DI container for OR's ObjectService.
     * @param LoggerInterface    $logger       Nextcloud logger.
     * @param string             $registerSlug The configured OR register slug.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly string $registerSlug,
    ) {
    }//end __construct()

    /**
     * Seed every configured compliance file, idempotently.
     *
     * @return array<string,mixed> Result with success flag and per-schema counts.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-archiefwet-retention/spec.md (Task 3.11)
     */
    public function seedAll(): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: could not resolve ObjectService for compliance seeding',
                ['exception' => $e->getMessage()]
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $counts = [];
        foreach (self::COMPLIANCE_SEEDS as $seed) {
            $records = $this->loadRecords(file: $seed['file'], itemsKey: $seed['itemsKey']);
            $counts[$seed['schema']] = $this->importRecords(
                objectService: $objectService,
                records: $records,
                schema: $seed['schema'],
                keyField: $seed['key']
            );
        }

        return [
            'success' => true,
            'message' => 'Compliance reference data seeded.',
            'counts'  => $counts,
        ];

    }//end seedAll()

    /**
     * Load and decode the items array from a seed file using the given key.
     *
     * Missing or invalid files are logged and yield an empty array so the rest
     * of the run continues.
     *
     * @param string $file     Seed filename under lib/Settings/seeds/.
     * @param string $itemsKey Top-level JSON key holding the items array.
     *
     * @return array<int, array<string,mixed>>
     */
    private function loadRecords(string $file, string $itemsKey): array
    {
        $seedPath = __DIR__.'/../Settings/seeds/'.$file;
        if (file_exists($seedPath) === false) {
            $this->logger->warning('Shillinq: compliance seed file not found: '.$file);
            return [];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            $this->logger->warning('Shillinq: failed to read compliance seed file: '.$file);
            return [];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning(
                'Shillinq: failed to parse compliance seed file: '.$file.' — '.json_last_error_msg()
            );
            return [];
        }

        $records = ($data[$itemsKey] ?? []);
        if (is_array($records) === false) {
            return [];
        }

        return $records;

    }//end loadRecords()

    /**
     * Import a list of seed records into a schema, skipping existing ones.
     *
     * @param object                          $objectService OpenRegister ObjectService.
     * @param array<int, array<string,mixed>> $records       Records to import.
     * @param string                          $schema        Target schema slug.
     * @param string                          $keyField      Dedup key field.
     *
     * @return array{seeded: int, skipped: int}
     */
    private function importRecords(object $objectService, array $records, string $schema, string $keyField): array
    {
        $seeded  = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (isset($record[$keyField]) === false) {
                continue;
            }

            $existing = $objectService
                ->setRegister($this->registerSlug)
                ->setSchema($schema)
                ->findAll(
                    [
                        'filters' => [$keyField => $record[$keyField]],
                        'limit'   => 1,
                    ]
                );

            if (empty($existing) === false) {
                $skipped++;
                continue;
            }

            $objectService->saveObject(
                object: $record,
                register: $this->registerSlug,
                schema: $schema,
            );
            $seeded++;
        }//end foreach

        return ['seeded' => $seeded, 'skipped' => $skipped];

    }//end importRecords()
}//end class
