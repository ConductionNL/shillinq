<?php

/**
 * Hermetic in-memory OpenRegister ObjectService stub for the
 * DunningRunService + CreditScoreService unit tests.
 *
 * Implements the canonical fluent API used by the production services
 * (setRegister + setSchema + findAll + saveObject) and keeps records in
 * a per-schema array so the tests can seed + assert without touching
 * an actual OR runtime.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

/**
 * Minimal stub mimicking OCA\OpenRegister\Service\ObjectService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InMemoryObjectService
{
    /**
     * Records keyed by schema slug.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private array $records = [];

    /**
     * Auto-incrementing id counter.
     *
     * @var int
     */
    private int $nextId = 1;

    /**
     * Active schema (set via setSchema()).
     *
     * @var string
     */
    private string $schema = '';

    /**
     * @param string $register OR register slug (ignored — tests use one register).
     *
     * @return self
     */
    public function setRegister(string $register): self
    {
        return $this;

    }//end setRegister()

    /**
     * @param string $schema Active schema.
     *
     * @return self
     */
    public function setSchema(string $schema): self
    {
        $this->schema = $schema;
        return $this;

    }//end setSchema()

    /**
     * Pre-populate the in-memory store.
     *
     * @param string                                  $schema Schema slug.
     * @param array<int,array<string,mixed>>          $rows   Records to seed.
     *
     * @return void
     */
    public function seed(string $schema, array $rows): void
    {
        foreach ($rows as $row) {
            if (isset($row['id']) === false) {
                $row['id'] = 'auto-'.($this->nextId++);
            }
            $this->records[$schema][] = $row;
        }

    }//end seed()

    /**
     * Find all records on the active schema matching the filter map.
     *
     * @param array<string,mixed> $args ['filters' => ...].
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(array $args = []): array
    {
        $rows    = ($this->records[$this->schema] ?? []);
        $filters = (array) ($args['filters'] ?? []);
        if ($filters === []) {
            return $rows;
        }

        return array_values(
            array_filter(
                $rows,
                static function (array $row) use ($filters): bool {
                    foreach ($filters as $key => $expected) {
                        $actual = $row[$key] ?? null;
                        if ((string) $actual !== (string) $expected) {
                            return false;
                        }
                    }
                    return true;
                }
            )
        );

    }//end findAll()

    /**
     * Persist a record on the active schema.
     *
     * @param array<string,mixed> $data Record body.
     *
     * @return array<string,mixed> The persisted record (with id).
     */
    public function saveObject(array $data): array
    {
        if (isset($data['id']) === false) {
            $data['id'] = 'auto-'.($this->nextId++);
        }
        $this->records[$this->schema][] = $data;
        return $data;

    }//end saveObject()

}//end class
