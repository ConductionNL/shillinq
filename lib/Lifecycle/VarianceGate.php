<?php

/**
 * Variance Gate
 *
 * ADR-031 exception-path lifecycle guard for InventoryCycleCount state transitions.
 * Two guards are registered here because the declarative x-openregister-lifecycle
 * engine cannot yet express cross-schema child-line aggregation queries needed for:
 * 1. countScopeIsValid — conditional field-presence validation on the parent object.
 * 2. allFlaggedLinesHaveReasonCodes — cross-schema aggregation (any child line where
 *    requiresReason=true AND reasonCode=null).
 *
 * The method requiresInvestigation() provides the ADR-031 exception fallback for the
 * InventoryCycleCountLine.requiresReason calculation when the OR calculation engine
 * cannot resolve parent-schema metadata references (@config.threshold).
 *
 * ADR-031 exception reason: cross-schema child-line aggregation and parent-metadata
 * resolution are not yet expressible in the declarative lifecycle DSL. When the engine
 * gains those capabilities, replace these references with declarative conditions and
 * delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for InventoryCycleCount transitions.
 *
 * Referenced from shillinq_register.json InventoryCycleCount.x-openregister-lifecycle:
 * - transitions.submit.requires: OCA\Shillinq\Lifecycle\VarianceGate::countScopeIsValid
 * - transitions.post.requires:   OCA\Shillinq\Lifecycle\VarianceGate::allFlaggedLinesHaveReasonCodes
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
 */
class VarianceGate
{
    /**
     * Construct the guard with DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for register slug.
     * @param LoggerInterface    $logger    Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns true iff the cycle count has a valid scope for its countType.
     *
     * Full counts require no filter. Partial counts require either locationFilter
     * or categoryFilter (or both) to be set per REQ-ICC-002 and REQ-ICC-008.
     *
     * Fail-closed: returns false on any exception (CWE-863).
     *
     * @param string $countId The InventoryCycleCount.id to check.
     *
     * @return bool True when the count scope is valid and may be submitted.
     *
     * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
     */
    public function countScopeIsValid(string $countId): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->getRegister();

            $count = $objectService
                ->setRegister($register)
                ->setSchema('InventoryCycleCount')
                ->find($countId);

            if ($count === null) {
                return false;
            }

            if (($count['countType'] ?? '') === 'full') {
                return true;
            }

            // Partial count: needs at least one scope filter.
            $hasLocation = isset($count['locationFilter']) && $count['locationFilter'] !== '' && $count['locationFilter'] !== null;
            $hasCategory = isset($count['categoryFilter']) && $count['categoryFilter'] !== '' && $count['categoryFilter'] !== null;

            return $hasLocation || $hasCategory;
        } catch (\Throwable $e) {
            $this->logger->error(
                'VarianceGate: count scope validation failed — denying submit transition (fail-closed)',
                ['countId' => $countId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end countScopeIsValid()

    /**
     * Returns true iff all flagged lines have a reason code attached.
     *
     * Queries all InventoryCycleCountLine records for the given countId where
     * requiresReason is true, and verifies every one has a non-null reasonCode.
     * A single unfilled flagged line blocks the counting → posted transition.
     *
     * Fail-closed: returns false on any exception (REQ-ICC-004 / CWE-863).
     *
     * @param string $countId The InventoryCycleCount.id to check.
     *
     * @return bool True when all flagged lines have reason codes and count may be posted.
     *
     * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
     */
    public function allFlaggedLinesHaveReasonCodes(string $countId): bool
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->getRegister();

            $flaggedLines = $objectService
                ->setRegister($register)
                ->setSchema('InventoryCycleCountLine')
                ->findAll(['filters' => ['countId' => $countId, 'requiresReason' => true]]);

            foreach ($flaggedLines as $line) {
                $reasonCode = $line['reasonCode'] ?? null;
                if ($reasonCode === null || $reasonCode === '') {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'VarianceGate: flagged-line check failed — denying post transition (fail-closed)',
                ['countId' => $countId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end allFlaggedLinesHaveReasonCodes()

    /**
     * Returns true iff a count line's variance exceeds the configured thresholds.
     *
     * ADR-031 exception fallback for InventoryCycleCountLine.requiresReason when
     * the x-openregister-calculations engine cannot resolve parent-schema metadata
     * config references. Called directly by the calculation fallback if configured.
     *
     * @param float $quantityVariance  Absolute quantity variance (countedQty - expectedQty).
     * @param float $expectedQuantity  Expected quantity for % threshold calculation.
     * @param float $valueVariance     Absolute cost variance (countedValue - expectedValue).
     * @param float $thresholdPercent  Quantity variance % threshold (default 5.0%).
     * @param float $thresholdAbsolute Absolute cost variance threshold in EUR (default 500.0).
     *
     * @return bool True when the line exceeds either threshold and requires a reason code.
     *
     * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
     */
    public function requiresInvestigation(
        float $quantityVariance,
        float $expectedQuantity,
        float $valueVariance,
        float $thresholdPercent=5.0,
        float $thresholdAbsolute=500.0,
    ): bool {
        if ($expectedQuantity <= 0) {
            return abs($valueVariance) > $thresholdAbsolute;
        }

        $quantityThreshold = $expectedQuantity * ($thresholdPercent / 100.0);
        $exceedsQty        = abs($quantityVariance) > $quantityThreshold;
        $exceedsCost       = abs($valueVariance) > $thresholdAbsolute;

        return $exceedsQty || $exceedsCost;
    }//end requiresInvestigation()

    /**
     * Returns the configured register slug, defaulting to 'shillinq'.
     *
     * @return string The OpenRegister register slug.
     */
    private function getRegister(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;
    }//end getRegister()
}//end class
