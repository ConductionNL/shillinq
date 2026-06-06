<?php

/**
 * Moving Average Valuation Service
 *
 * Processes StockMovement events for items using the moving-average
 * (gewogen voortschrijdend gemiddelde) costing method.
 *
 * On inbound movements, recalculates the running weighted average unit cost:
 *   new_avg = (cur_qty × cur_cost + rcv_qty × rcv_cost) / (cur_qty + rcv_qty)
 *
 * On outbound movements, posts COGS at the current average cost.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
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
 * Listens to ObjectCreatedEvent for StockMovement objects and applies
 * moving-average valuation logic to the linked InventoryValuation snapshot.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
 */
class MovingAverageValuationService implements IEventListener
{
    /**
     * Construct the service with DI dependencies.
     *
     * @param ContainerInterface $container  DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig  App config for the register slug.
     * @param LoggerInterface    $logger     Logger.
     * @param CogsPosterService  $cogsPoster COGS GL posting service.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
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
     * Ignores events for other schemas. For moving-average method items:
     * - inbound: recalculates running weighted average unit cost and updates snapshot.
     * - outbound: posts COGS at current average cost.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
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

            $valuation = $this->findValuation(
                objectService: $objectService,
                register: $register,
                itemId: $itemId,
                warehouse: $warehouse,
            );

            if ($valuation === null || ($valuation['valuationMethod'] ?? '') !== 'average') {
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
                'MovingAverageValuationService: processing failed',
                ['movementType' => $movementType, 'itemId' => $itemId, 'exception' => $e->getMessage()]
            );
        }//end try

    }//end handle()

    /**
     * Process an inbound movement: recalculate running weighted average unit cost.
     *
     * Formula: new_unitCost = (cur_qty × cur_cost + rcv_qty × rcv_cost) / (cur_qty + rcv_qty)
     * unitCost rounded to 4 decimal places; totalValue rounded to 2 decimal places
     * per REQ-INV-004 and design D3.
     *
     * @param object              $objectService OR ObjectService instance.
     * @param string              $register      Register slug.
     * @param array<string,mixed> $valuation     Current InventoryValuation record.
     * @param array<string,mixed> $movement      The inbound StockMovement.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
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
     * Process an outbound movement: post COGS at the current average cost.
     *
     * No lot traversal required for moving-average; COGS = qty × current unitCost.
     *
     * @param object              $objectService OR ObjectService instance.
     * @param string              $register      Register slug.
     * @param array<string,mixed> $valuation     Current InventoryValuation record.
     * @param array<string,mixed> $movement      The outbound StockMovement.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
     */
    private function processOutbound(object $objectService, string $register, array $valuation, array $movement): void
    {
        $outboundQty = (float) ($movement['quantity'] ?? 0);
        $currentCost = (float) ($valuation['unitCost'] ?? 0);
        $currentQty  = (float) ($valuation['quantity'] ?? 0);

        if ($outboundQty <= 0) {
            return;
        }

        $cogsAmount = round(num: $outboundQty * $currentCost, precision: 2);
        $newQty     = max(0.0, $currentQty - $outboundQty);

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
            cogsAmount: $cogsAmount,
        );
    }//end processOutbound()

    /**
     * Find the active InventoryValuation for an item+warehouse.
     *
     * @param object $objectService OR ObjectService instance.
     * @param string $register      Register slug.
     * @param string $itemId        Product/item identifier.
     * @param string $warehouse     Warehouse identifier.
     *
     * @return array<string,mixed>|null The valuation record, or null when not found.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
     */
    private function findValuation(
        object $objectService,
        string $register,
        string $itemId,
        string $warehouse,
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
    }//end findValuation()

    /**
     * Resolve the configured register slug, defaulting to 'shillinq'.
     *
     * @return string The register slug.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
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
