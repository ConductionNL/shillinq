<?php

/**
 * Shillinq repair-step trait: batched source-row reads
 *
 * Reads every row of an OpenRegister schema in explicit limit/offset batches.
 *
 * WHY THIS EXISTS: `ObjectService::findAll(['limit' => 0])` does NOT mean
 * "unlimited" — OpenRegister forwards it as a literal SQL `LIMIT 0`, returning
 * ZERO rows. A repair step written that way enumerates nothing, does nothing,
 * and still reports a clean summary — green, and dead, on every real instance
 * (issue #382; first found in FoldIntoOrder, #503/#381). Live-verified on 8080:
 * `findAll(['limit' => 0])` => 0 rows; omitting `limit` => all rows; `offset`
 * pages correctly. This trait makes the correct batched pattern the single,
 * reusable default so the class of bug cannot recur one step at a time.
 *
 * The read is unscoped (`_rbac` / `_multitenancy` false): a data migration must
 * see every tenant's rows regardless of the (session-less) repair context.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair\Support
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair\Support;

/**
 * Provides a batched, unscoped "read every source row" helper for repair steps.
 */
trait ReadsSourceRowsInBatches
{
    /**
     * How many source rows to read per findAll() page.
     *
     * Bounds memory and keeps the read off implicit "unlimited" semantics.
     */
    public const READ_BATCH_SIZE = 200;

    /**
     * Read every row of a source schema in explicit limit/offset batches.
     *
     * NEVER pass `'limit' => 0` hoping for "unlimited" — see the trait docblock.
     * Exceptions propagate: each caller keeps its own missing-schema handling
     * (some warn, some info, some skip) rather than have it swallowed here.
     *
     * @param object              $objectService The OR ObjectService.
     * @param string              $registerSlug  The register slug to read from.
     * @param string              $schema        The schema slug to read.
     * @param array<string,mixed> $filters       Optional top-level filters (OR does
     *                                           NOT support dot-path nested filters).
     *
     * @return array<int,mixed> Every matching row (may be empty).
     */
    protected function readAllRows(object $objectService, string $registerSlug, string $schema, array $filters=[]): array
    {
        $rows   = [];
        $offset = 0;

        while (true) {
            $config = [
                'limit'         => self::READ_BATCH_SIZE,
                'offset'        => $offset,
                '_rbac'         => false,
                '_multitenancy' => false,
            ];
            if ($filters !== []) {
                $config['filters'] = $filters;
            }

            $page = $objectService
                ->setRegister($registerSlug)
                ->setSchema($schema)
                ->findAll($config);

            if (is_array($page) === false || $page === []) {
                break;
            }

            foreach ($page as $row) {
                $rows[] = $row;
            }

            if (count($page) < self::READ_BATCH_SIZE) {
                break;
            }

            $offset += self::READ_BATCH_SIZE;
        }//end while

        return $rows;

    }//end readAllRows()
}//end trait
