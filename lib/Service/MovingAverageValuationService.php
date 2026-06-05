<?php

/**
 * Moving Average Valuation Service
 *
 * ADR-031 exception: event-driven service implementing weighted moving-average
 * inventory cost-flow on StockMovement creation events. Inbound movements
 * recompute the weighted-average unit cost; outbound movements reduce on-hand
 * quantity and delegate COGS posting to CogsPosterService.
 *
 * ADR-031 exception reason: moving-average recomputation requires per-event
 * state mutation with idempotency tracking that is not yet expressible in the
 * declarative engine. Remove when OR gains stateful aggregation with idempotency.
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
use OCA\Shillinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * IEventListener that processes weighted moving-average inventory valuation on StockMovement creation.
 *
 * Subscribes to ObjectCreatedEvent and filters for StockMovement objects whose
 * linked InventoryValuation uses the 'average' method. Inbound movements
 * recompute the weighted-average unit cost; outbound movements deduct quantity
 * and post COGS at the current unit cost via CogsPosterService.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
 */
class MovingAverageValuationService implements IEventListener
{
    /**
     * Construct the service with lazy DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService and CogsPosterService resolution.
     * @param IAppConfig         $appConfig App config for the register slug.
     * @param LoggerInterface    $logger    Logger for fail-closed error diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an ObjectCreatedEvent, processing moving-average valuation for StockMovement objects.
     *
     * Returns early for non-StockMovement events. Dispatches to inbound or
     * outbound processing based on the movement's movementType.
     *
     * @param Event $event The event dispatched by OpenRegister.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false) {
            return;
        }

        $objectEntity = $event->getObject();

        if ($objectEntity->getSchema() !== 'StockMovement') {
            return;
        }

        $movement = $objectEntity->getObject();
        if ($movement === null) {
            return;
        }

        $movementType = (string) ($movement['movementType'] ?? '');

        try {
            if ($movementType === 'inbound') {
                $this->handleInbound(movement: $movement);
                return;
            }

            if ($movementType === 'outbound') {
                $this->handleOutbound(movement: $movement);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'MovingAverageValuationService: unhandled exception processing StockMovement (fail-closed)',
                ['movementUuid' => ($movement['uuid'] ?? ''), 'exception' => $e->getMessage()]
            );
        }//end try
    }//end handle()

    /**
     * Process an inbound StockMovement under moving-average valuation.
     *
     * Recomputes weighted-average unit cost:
     *   new_unitCost = (current_qty * current_unitCost + receipt_qty * receipt_unitCost)
     *                  / (current_qty + receipt_qty)
     *
     * Idempotency is enforced via lastProcessedMovementUuid on the valuation.
     *
     * @param array<string,mixed> $movement The StockMovement object array.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
     */
    private function handleInbound(array $movement): void
    {
        $valuation = $this->resolveValuation(movement: $movement);
        if ($valuation === null) {
            return;
        }

        if (($valuation['valuationMethod'] ?? '') !== 'average') {
            return;
        }

        // Idempotency: skip if this movement was already applied.
        $movementUuid = (string) ($movement['uuid'] ?? '');
        if ($movementUuid !== '' && ($valuation['lastProcessedMovementUuid'] ?? '') === $movementUuid) {
            return;
        }

        $currentQty      = (float) ($valuation['quantity'] ?? 0);
        $currentUnitCost = (float) ($valuation['unitCost'] ?? 0);
        $receiptQty      = (float) ($movement['quantity'] ?? 0);
        $receiptUnitCost = (float) ($movement['unitCost'] ?? 0);

        $newQty = $currentQty + $receiptQty;

        $newUnitCost = $receiptUnitCost;
        if ($newQty > 0) {
            $newUnitCost = (($currentQty * $currentUnitCost) + ($receiptQty * $receiptUnitCost)) / $newQty;
        }

        $valuation['quantity']   = $newQty;
        $valuation['unitCost']   = round(num: $newUnitCost, precision: 4);
        $valuation['totalValue'] = round(num: $newQty * $newUnitCost, precision: 2);
        $valuation['lastProcessedMovementUuid'] = $movementUuid;

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $register      = $this->resolveRegister();

        $objectService
            ->setRegister($register)
            ->setSchema('InventoryValuation')
            ->saveObject(object: $valuation);
    }//end handleInbound()

    /**
     * Process an outbound StockMovement under moving-average valuation.
     *
     * COGS amount = movement_qty * current_unitCost. Reduces on-hand quantity,
     * recalculates totalValue, and delegates COGS posting to CogsPosterService.
     *
     * Idempotency is enforced via lastProcessedMovementUuid on the valuation.
     *
     * @param array<string,mixed> $movement The StockMovement object array.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
     */
    private function handleOutbound(array $movement): void
    {
        $valuation = $this->resolveValuation(movement: $movement);
        if ($valuation === null) {
            return;
        }

        if (($valuation['valuationMethod'] ?? '') !== 'average') {
            return;
        }

        // Idempotency: skip if this movement was already applied.
        $movementUuid = (string) ($movement['uuid'] ?? '');
        if ($movementUuid !== '' && ($valuation['lastProcessedMovementUuid'] ?? '') === $movementUuid) {
            return;
        }

        $currentQty      = (float) ($valuation['quantity'] ?? 0);
        $currentUnitCost = (float) ($valuation['unitCost'] ?? 0);
        $outboundQty     = (float) ($movement['quantity'] ?? 0);

        $cogsAmount = round(num: $outboundQty * $currentUnitCost, precision: 2);
        $newQty     = max(0.0, $currentQty - $outboundQty);

        $valuation['quantity']   = $newQty;
        $valuation['totalValue'] = round(num: $newQty * $currentUnitCost, precision: 2);
        $valuation['lastProcessedMovementUuid'] = $movementUuid;

        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $register      = $this->resolveRegister();

        $objectService
            ->setRegister($register)
            ->setSchema('InventoryValuation')
            ->saveObject(object: $valuation);

        // Delegate COGS GL posting.
        // phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
        // @var CogsPosterService $cogsPoster
        $cogsPoster = $this->container->get(CogsPosterService::class);
        $cogsPoster->postCogs(movement: $movement, valuation: $valuation, cogsAmount: $cogsAmount);
    }//end handleOutbound()

    /**
     * Resolve the active InventoryValuation for the movement's item+warehouse.
     *
     * Queries ObjectService for an InventoryValuation with status=active that
     * matches the movement's productId (itemId) and warehouse. Returns null when
     * no active valuation exists.
     *
     * @param array<string,mixed> $movement The StockMovement object array.
     *
     * @return array<string,mixed>|null The InventoryValuation object, or null.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
     */
    private function resolveValuation(array $movement): ?array
    {
        try {
            $itemId    = (string) ($movement['productId'] ?? ($movement['itemId'] ?? ''));
            $warehouse = (string) ($movement['warehouse'] ?? '');

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->resolveRegister();

            $results = $objectService
                ->setRegister($register)
                ->setSchema('InventoryValuation')
                ->findAll(
                    [
                        'filters' => [
                            'status'    => 'active',
                            'productId' => $itemId,
                            'warehouse' => $warehouse,
                        ],
                    ]
                );

            foreach ($results as $result) {
                if (is_array(value: $result) === true) {
                    return $result;
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error(
                'MovingAverageValuationService: resolveValuation failed',
                ['movement' => ($movement['uuid'] ?? ''), 'exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end resolveValuation()

    /**
     * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
     *
     * @return string The register slug.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
     */
    private function resolveRegister(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;
    }//end resolveRegister()
}//end class
