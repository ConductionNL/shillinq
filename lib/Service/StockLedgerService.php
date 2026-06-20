<?php

/**
 * Stock Ledger Service
 *
 * Read-only stock-ledger aggregation service per REQ-SM-005 and
 * REQ-SM-009. Two responsibilities:
 *
 *   - quantityForLocation()  — recompute InventoryStock.quantity from
 *     the StockMove ledger: initialStock + SUM(destination posted) -
 *     SUM(source posted), excluding cancelled rows. Used by the Stock
 *     Levels Dashboard reconciliation pass + the JSON drill-down
 *     endpoint backing the Stock Ledger detail view.
 *
 *   - reservedForLocation()  — SUM(quantity WHERE lifecycleState=draft
 *     AND sourceLocationId=location) so the Available = quantity -
 *     reservedQty breakdown on the InventoryStock detail (REQ-SM-009)
 *     and the Reserved Stock index (REQ-SM-008) can render the same
 *     number end-to-end without divergence.
 *
 *   - traceLocation()        — drill-down listing of every posted,
 *     non-cancelled StockMove that contributes to a (sku, location,
 *     administration) balance, with a running cumulative total per
 *     REQ-SM-005 — the data the Stock Ledger detail view renders.
 *
 * No persistence. All reads via the real OR ObjectService API
 * (find / findAll). Integer-cent arithmetic.
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
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * REQ-SM-005 + REQ-SM-009 read-side aggregation for the Stock Ledger
 * and Reserved Stock manifest views.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 */
class StockLedgerService
{
    /**
     * Construct the service.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for register slug.
     * @param LoggerInterface    $logger    Logger for diagnostics; never logs full payloads.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Recompute the on-hand quantity at a bin location for an SKU from the
     * StockMove ledger per REQ-SM-005.
     *
     * @param string $administrationId Tenant scope.
     * @param string $locationId       Bin location id.
     * @param string $sku              Product SKU.
     * @param float  $initialStock     Optional initial-stock seed (default 0).
     *
     * @return float Recomputed on-hand quantity (rounded to 2 decimals).
     *
     * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
     */
    public function quantityForLocation(
        string $administrationId,
        string $locationId,
        string $sku,
        float $initialStock=0.0
    ): float {
        try {
            $moves    = $this->postedMovesForLocation(
                administrationId: $administrationId,
                locationId: $locationId,
                sku: $sku
            );
            $netCents = $this->cents($initialStock);
            foreach ($moves as $move) {
                $cents = $this->cents(($move['quantity'] ?? 0));
                if (((string) ($move['destinationLocationId'] ?? '')) === $locationId) {
                    $netCents += $cents;
                }

                if (((string) ($move['sourceLocationId'] ?? '')) === $locationId) {
                    $netCents -= $cents;
                }
            }

            return $this->fromCents($netCents);
        } catch (\Throwable $e) {
            $this->logger->error(
                'StockLedgerService: quantityForLocation failed',
                [
                    'administrationId' => $administrationId,
                    'locationId'       => $locationId,
                    'sku'              => $sku,
                    'exception'        => $e->getMessage(),
                ]
            );
            return 0.0;
        }//end try

    }//end quantityForLocation()

    /**
     * Sum reserved quantity (draft moves whose source is this location for
     * this SKU) per REQ-SM-009.
     *
     * @param string $administrationId Tenant scope.
     * @param string $locationId       Bin location id.
     * @param string $sku              Product SKU.
     *
     * @return float Reserved quantity (rounded to 2 decimals).
     *
     * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
     */
    public function reservedForLocation(string $administrationId, string $locationId, string $sku): float
    {
        try {
            $moves         = $this->draftMovesForSource(
                administrationId: $administrationId,
                locationId: $locationId,
                sku: $sku
            );
            $reservedCents = 0;
            foreach ($moves as $move) {
                $reservedCents += $this->cents(($move['quantity'] ?? 0));
            }

            return $this->fromCents($reservedCents);
        } catch (\Throwable $e) {
            $this->logger->error(
                'StockLedgerService: reservedForLocation failed',
                [
                    'administrationId' => $administrationId,
                    'locationId'       => $locationId,
                    'sku'              => $sku,
                    'exception'        => $e->getMessage(),
                ]
            );
            return 0.0;
        }//end try

    }//end reservedForLocation()

    /**
     * Build the drill-down trace for the Stock Ledger detail view: every
     * posted, non-cancelled StockMove touching (sku, location) with a
     * running cumulative total. Returned rows are ordered chronologically
     * by postedAt ASC so the operator can audit the balance per REQ-SM-005.
     *
     * @param string $administrationId Tenant scope.
     * @param string $locationId       Bin location id.
     * @param string $sku              Product SKU.
     *
     * @return array<int,array<string,mixed>> Trace rows: {movementNumber, postedAt, movementType, sign, quantity, runningTotal}.
     *
     * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
     */
    public function traceLocation(string $administrationId, string $locationId, string $sku): array
    {
        try {
            $moves = $this->postedMovesForLocation(
                administrationId: $administrationId,
                locationId: $locationId,
                sku: $sku
            );

            usort(
                $moves,
                static fn(array $left, array $right): int => strcmp(
                    (string) ($left['postedAt'] ?? ''),
                    (string) ($right['postedAt'] ?? '')
                )
            );

            $trace        = [];
            $runningCents = 0;
            foreach ($moves as $move) {
                $cents     = $this->cents(($move['quantity'] ?? 0));
                $sign      = '+';
                $signCents = $cents;
                if (((string) ($move['sourceLocationId'] ?? '')) === $locationId) {
                    $sign      = '-';
                    $signCents = -$cents;
                }

                $runningCents += $signCents;
                $trace[]       = [
                    'id'             => ($move['id'] ?? null),
                    'movementNumber' => ($move['movementNumber'] ?? null),
                    'postedAt'       => ($move['postedAt'] ?? null),
                    'movementType'   => ($move['movementType'] ?? null),
                    'sign'           => $sign,
                    'quantity'       => $this->fromCents($cents),
                    'runningTotal'   => $this->fromCents($runningCents),
                ];
            }

            return $trace;
        } catch (\Throwable $e) {
            $this->logger->error(
                'StockLedgerService: traceLocation failed',
                [
                    'administrationId' => $administrationId,
                    'locationId'       => $locationId,
                    'sku'              => $sku,
                    'exception'        => $e->getMessage(),
                ]
            );
            return [];
        }//end try

    }//end traceLocation()

    /**
     * Fetch all posted, non-cancelled StockMoves touching the (sku, location)
     * pair on either side. Combines two findAll() queries (source + destination)
     * and de-duplicates by id.
     *
     * @param string $administrationId Tenant scope.
     * @param string $locationId       Bin location id.
     * @param string $sku              Product SKU.
     *
     * @return array<int,array<string,mixed>>
     */
    private function postedMovesForLocation(string $administrationId, string $locationId, string $sku): array
    {
        if ($administrationId === '' || $locationId === '' || $sku === '') {
            return [];
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $base          = [
            'administrationId' => $administrationId,
            'itemId'           => $sku,
            'lifecycleState'   => 'posted',
        ];

        $sourceSide      = ($objectService
            ->setRegister($this->register())
            ->setSchema('StockMove')
            ->findAll(['filters' => array_merge($base, ['sourceLocationId' => $locationId])]) ?? []);
        $destinationSide = ($objectService
            ->setRegister($this->register())
            ->setSchema('StockMove')
            ->findAll(['filters' => array_merge($base, ['destinationLocationId' => $locationId])]) ?? []);

        if (is_array($sourceSide) === false) {
            $sourceSide = [];
        }

        if (is_array($destinationSide) === false) {
            $destinationSide = [];
        }

        $byId = [];
        foreach (array_merge($sourceSide, $destinationSide) as $move) {
            if (is_array($move) === false) {
                continue;
            }

            $id = (string) ($move['id'] ?? ($move['@self']['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $byId[$id] = $move;
        }

        return array_values($byId);

    }//end postedMovesForLocation()

    /**
     * Fetch all draft StockMoves whose source is this (sku, location).
     *
     * @param string $administrationId Tenant scope.
     * @param string $locationId       Bin location id.
     * @param string $sku              Product SKU.
     *
     * @return array<int,array<string,mixed>>
     */
    private function draftMovesForSource(string $administrationId, string $locationId, string $sku): array
    {
        if ($administrationId === '' || $locationId === '' || $sku === '') {
            return [];
        }

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $rows          = ($objectService
            ->setRegister($this->register())
            ->setSchema('StockMove')
            ->findAll(
                [
                    'filters' => [
                        'administrationId' => $administrationId,
                        'sourceLocationId' => $locationId,
                        'itemId'           => $sku,
                        'lifecycleState'   => 'draft',
                    ],
                ]
            ) ?? []);

        return is_array($rows) === true ? $rows : [];

    }//end draftMovesForSource()

    /**
     * Convert a money/quantity value to integer cents (multipleOf 0.01).
     *
     * @param mixed $value Schema number (float or int).
     *
     * @return int
     */
    private function cents(mixed $value): int
    {
        if (is_int($value) === true) {
            return ($value * 100);
        }

        return (int) round(((float) $value) * 100);

    }//end cents()

    /**
     * Convert integer cents back to a 2-decimal float.
     *
     * @param int $cents Integer cents.
     *
     * @return float
     */
    private function fromCents(int $cents): float
    {
        return ((float) $cents / 100.0);

    }//end fromCents()

    /**
     * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
     *
     * @return string
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;

    }//end register()
}//end class
