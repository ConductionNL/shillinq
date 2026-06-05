<?php

/**
 * FIFO Valuation Service
 *
 * ADR-031 exception: event-driven service implementing FIFO inventory cost-flow
 * on StockMovement creation events. Inbound movements extend on-hand quantity;
 * outbound movements traverse chronological inbound lots and compute COGS,
 * then delegate to CogsPosterService for GL posting.
 *
 * ADR-031 exception reason: lot-traversal (outbound FIFO costing) requires
 * ordered cross-object aggregation that is not yet expressible in the
 * declarative engine. Remove when OR gains ordered-lot aggregation support.
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
use OCA\Shillinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * IEventListener that processes FIFO inventory valuation on StockMovement creation.
 *
 * Subscribes to ObjectCreatedEvent, filters for StockMovement objects, and
 * maintains the InventoryValuation record for FIFO-costed items. Outbound
 * movements trigger COGS computation via lot-traversal and delegation to
 * CogsPosterService.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 */
class FifoValuationService implements IEventListener
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
     * Handle an ObjectCreatedEvent, processing FIFO valuation for StockMovement objects.
     *
     * Returns early for non-StockMovement events. Dispatches to handleInbound()
     * or handleOutbound() based on the movement's movementType.
     *
     * @param Event $event The event dispatched by OpenRegister.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
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
                'FifoValuationService: unhandled exception processing StockMovement (fail-closed)',
                ['movementUuid' => ($movement['uuid'] ?? ''), 'exception' => $e->getMessage()]
            );
        }//end try
    }//end handle()

    /**
     * Process an inbound StockMovement under FIFO valuation.
     *
     * Locates the active InventoryValuation for the item+warehouse combination.
     * If the valuation method is FIFO, increments on-hand quantity and
     * recalculates the weighted-average unit cost. Idempotency is enforced via
     * lastProcessedMovementUuid on the valuation record.
     *
     * @param array<string,mixed> $movement The StockMovement object array.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    private function handleInbound(array $movement): void
    {
        $valuation = $this->resolveValuation(movement: $movement);
        if ($valuation === null) {
            return;
        }

        if (($valuation['valuationMethod'] ?? '') !== 'FIFO') {
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

        // Weighted-average unit cost for FIFO on-hand valuation.
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
     * Process an outbound StockMovement under FIFO valuation.
     *
     * Locates the active InventoryValuation for the item+warehouse combination.
     * If the valuation method is FIFO, traverses inbound lots in chronological
     * order to compute COGS, reduces on-hand quantity, and delegates to
     * CogsPosterService for GL posting.
     *
     * @param array<string,mixed> $movement The StockMovement object array.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
     */
    private function handleOutbound(array $movement): void
    {
        $valuation = $this->resolveValuation(movement: $movement);
        if ($valuation === null) {
            return;
        }

        if (($valuation['valuationMethod'] ?? '') !== 'FIFO') {
            return;
        }

        // Idempotency: skip if this movement was already applied.
        $movementUuid = (string) ($movement['uuid'] ?? '');
        if ($movementUuid !== '' && ($valuation['lastProcessedMovementUuid'] ?? '') === $movementUuid) {
            return;
        }

        $outboundQty = (float) ($movement['quantity'] ?? 0);

        // Fetch open inbound lots ordered by movement date (FIFO = oldest first).
        $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        $register      = $this->resolveRegister();

        $itemId    = (string) ($movement['productId'] ?? ($movement['itemId'] ?? ''));
        $warehouse = (string) ($movement['warehouse'] ?? '');

        $lots = $objectService
            ->setRegister($register)
            ->setSchema('StockMovement')
            ->findAll(
                [
                    'filters' => [
                        'movementType' => 'inbound',
                        'productId'    => $itemId,
                        'warehouse'    => $warehouse,
                        '_order'       => 'movementDate',
                    ],
                ]
            );

        // Traverse lots chronologically and compute COGS.
        $cogsAmount = 0.0;
        $remaining  = $outboundQty;

        foreach ($lots as $lot) {
            if ($remaining <= 0.0) {
                break;
            }

            $lotQty      = (float) ($lot['remainingQuantity'] ?? ($lot['quantity'] ?? 0));
            $lotUnitCost = (float) ($lot['unitCost'] ?? 0);

            if ($lotQty <= 0.0) {
                continue;
            }

            $consumed    = min($remaining, $lotQty);
            $cogsAmount += $consumed * $lotUnitCost;
            $remaining  -= $consumed;
        }//end foreach

        $currentQty = (float) ($valuation['quantity'] ?? 0);
        $newQty     = max(0.0, $currentQty - $outboundQty);

        $currentUnitCost         = (float) ($valuation['unitCost'] ?? 0);
        $valuation['quantity']   = $newQty;
        $valuation['totalValue'] = round(num: $newQty * $currentUnitCost, precision: 2);
        $valuation['lastProcessedMovementUuid'] = $movementUuid;

        $objectService
            ->setRegister($register)
            ->setSchema('InventoryValuation')
            ->saveObject(object: $valuation);

        // Delegate COGS GL posting to CogsPosterService.
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
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
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
                'FifoValuationService: resolveValuation failed',
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
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
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
