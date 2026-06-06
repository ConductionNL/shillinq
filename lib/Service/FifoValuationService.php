<?php

/**
 * FIFO Valuation Service
 *
 * Processes StockMovement events for items using the FIFO (First-In, First-Out)
 * costing method. On inbound movements, records the new cost lot reference.
 * On outbound movements, traverses open inbound lots in chronological order,
 * deducts quantity, computes weighted COGS, and updates the InventoryValuation
 * snapshot. Delegates COGS GL posting to CogsPosterService.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listens to ObjectCreatedEvent for StockMovement objects and applies FIFO
 * valuation logic to the linked InventoryValuation snapshot.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 */
class FifoValuationService implements IEventListener
{
    /**
     * Construct the service with DI dependencies.
     *
     * @param ContainerInterface $container  DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig  App config for the register slug.
     * @param LoggerInterface    $logger     Logger.
     * @param CogsPosterService  $cogsPoster COGS GL posting service.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly CogsPosterService $cogsPoster,
    ) {
    }//end __construct()

    /**
     * Handle ObjectCreatedEvent for StockMovement schema objects.
     *
     * Ignores events for other schemas. For FIFO-method items:
     * - inbound: records the cost lot (updates InventoryValuation snapshot).
     * - outbound: traverses open lots, posts COGS, updates snapshot.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
            return;
        }

        $objectEntity = $event->getObject();
        if ($objectEntity->getSchema() !== 'StockMovement') {
            return;
        }

        $movement = $objectEntity->getObject();
        if (is_array($movement) === false) {
            return;
        }

        $movementType = ($movement['movementType'] ?? '');
        $itemId       = ($movement['itemId'] ?? '');
        $warehouse    = ($movement['warehouse'] ?? '');

        if ($itemId === '') {
            return;
        }

        try {
            $register      = $this->resolveRegister();
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $valuation = $this->findOrCreateValuation(
                objectService: $objectService,
                register: $register,
                itemId: $itemId,
                warehouse: $warehouse,
                movement: $movement,
            );

            if ($valuation === null || ($valuation['valuationMethod'] ?? '') !== 'FIFO') {
                return;
            }

            $movementUuid = ($movement['uuid'] ?? '');
            if ($movementUuid !== '' && ($valuation['lastProcessedMovementUuid'] ?? '') === $movementUuid) {
                return;
            }

            if ($movementType === 'inbound') {
                $this->processInbound(
                    objectService: $objectService,
                    register: $register,
                    valuation: $valuation,
                    movement: $movement,
                );
            } else if ($movementType === 'outbound') {
                $this->processOutbound(
                    objectService: $objectService,
                    register: $register,
                    valuation: $valuation,
                    movement: $movement,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'FifoValuationService: processing failed',
                ['movementType' => $movementType, 'itemId' => $itemId, 'exception' => $e->getMessage()]
            );
        }//end try

    }//end handle()

    /**
     * Process an inbound FIFO movement: increase quantity and update snapshot.
     *
     * For FIFO, inbound movements add a cost lot. The snapshot quantity increases
     * and the running weighted average unit cost is recalculated over all open lots.
     *
     * @param object              $objectService OR ObjectService instance.
     * @param string              $register      Register slug.
     * @param array<string,mixed> $valuation     Current InventoryValuation record.
     * @param array<string,mixed> $movement      The inbound StockMovement.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    private function processInbound(object $objectService, string $register, array $valuation, array $movement): void
    {
        $receiptQty  = (float) ($movement['quantity'] ?? 0);
        $receiptCost = (float) ($movement['unitCost'] ?? 0);
        $currentQty  = (float) ($valuation['quantity'] ?? 0);
        $currentCost = (float) ($valuation['unitCost'] ?? 0);

        if ($receiptQty <= 0) {
            return;
        }

        $newQty  = $currentQty + $receiptQty;
        $newCost = $receiptCost;
        if ($newQty > 0) {
            $newCost = ($currentQty * $currentCost + $receiptQty * $receiptCost) / $newQty;
        }

        $valuation['quantity']   = $newQty;
        $valuation['unitCost']   = round(num: $newCost, precision: 4);
        $valuation['totalValue'] = round(num: $newQty * $newCost, precision: 2);
        $valuation['lastProcessedMovementUuid'] = ($movement['uuid'] ?? '');

        $objectService->saveObject(
            object: $valuation,
            register: $register,
            schema: 'InventoryValuation',
        );
    }//end processInbound()

    /**
     * Process an outbound FIFO movement: traverse lots, compute COGS, update snapshot.
     *
     * Fetches all open inbound StockMovements for this item+warehouse in date order,
     * deducts from the oldest first, computes weighted COGS total, then posts to the GL.
     *
     * @param object              $objectService OR ObjectService instance.
     * @param string              $register      Register slug.
     * @param array<string,mixed> $valuation     Current InventoryValuation record.
     * @param array<string,mixed> $movement      The outbound StockMovement.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    private function processOutbound(object $objectService, string $register, array $valuation, array $movement): void
    {
        $outboundQty = (float) ($movement['quantity'] ?? 0);
        $currentQty  = (float) ($valuation['quantity'] ?? 0);

        if ($outboundQty <= 0) {
            return;
        }

        $openLots = $objectService
            ->setRegister($register)
            ->setSchema('StockMovement')
            ->findAll(
                    [
                        'filters' => [
                            'itemId'       => ($movement['itemId'] ?? ''),
                            'warehouse'    => ($movement['warehouse'] ?? ''),
                            'movementType' => 'inbound',
                        ],
                        '_order'  => 'date',
                    ]
                    );

        $remainingOut = $outboundQty;
        $cogsTotal    = 0.0;

        foreach ($openLots as $lot) {
            if (is_array($lot) === false || $remainingOut <= 0) {
                break;
            }

            $lotQty        = (float) ($lot['quantity'] ?? 0);
            $lotCost       = (float) ($lot['unitCost'] ?? 0);
            $consumed      = min($lotQty, $remainingOut);
            $cogsTotal    += $consumed * $lotCost;
            $remainingOut -= $consumed;
        }

        $newQty      = max(0.0, $currentQty - $outboundQty);
        $currentCost = (float) ($valuation['unitCost'] ?? 0);

        $valuation['quantity']   = $newQty;
        $valuation['totalValue'] = round(num: $newQty * $currentCost, precision: 2);
        $valuation['lastProcessedMovementUuid'] = ($movement['uuid'] ?? '');

        $updated = $objectService->saveObject(
            object: $valuation,
            register: $register,
            schema: 'InventoryValuation',
        );

        $passedValuation = $valuation;
        if (is_array($updated) === true) {
            $passedValuation = $updated;
        }

        $this->cogsPoster->postCogs(
            movement: $movement,
            valuation: $passedValuation,
            cogsAmount: round(num: $cogsTotal, precision: 2),
        );
    }//end processOutbound()

    /**
     * Find the active InventoryValuation for an item+warehouse, or return null.
     *
     * @param object              $objectService OR ObjectService instance.
     * @param string              $register      Register slug.
     * @param string              $itemId        Product/item identifier.
     * @param string              $warehouse     Warehouse identifier.
     * @param array<string,mixed> $movement      The StockMovement being processed.
     *
     * @return array<string,mixed>|null The valuation record, or null when not found.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    private function findOrCreateValuation(
        object $objectService,
        string $register,
        string $itemId,
        string $warehouse,
        array $movement,
    ): ?array {
        $results = $objectService
            ->setRegister($register)
            ->setSchema('InventoryValuation')
            ->findAll(
                    [
                        'filters' => [
                            'productId' => $itemId,
                            'warehouse' => $warehouse,
                            'status'    => 'active',
                        ],
                        '_limit'  => 1,
                    ]
                    );

        foreach ($results as $result) {
            if (is_array($result) === true) {
                return $result;
            }
        }

        return null;
    }//end findOrCreateValuation()

    /**
     * Resolve the configured register slug, defaulting to 'shillinq'.
     *
     * @return string The register slug.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    private function resolveRegister(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;
    }//end resolveRegister()
}//end class
