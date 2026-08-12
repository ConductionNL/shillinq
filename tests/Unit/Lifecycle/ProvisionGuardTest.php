<?php

/**
 * Unit tests for ProvisionGuard (bookkeeping-voorzieningen-claims).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-voorzieningen-claims/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ProvisionGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests ProvisionGuard lifecycle preconditions for the IAS 37 / RJ 252
 * voorzieningen registers. Covers the integration scenarios T29..T35 by
 * exercising the activation + close transitions against in-flight objects;
 * every guard fails closed and inline-object cases never touch the container.
 */
class ProvisionGuardTest extends TestCase
{

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IAppConfig.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig&MockObject $appConfig;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * The guard under test.
     *
     * @var ProvisionGuard
     */
    private ProvisionGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->guard = new ProvisionGuard(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()


    /**
     * Build a minimally complete activation-ready Provision payload (garantie
     * type, immaterial, short-term, no extra detail FKs).
     *
     * @return array<string,mixed> The provision payload.
     */
    private function completeImmaterialProvision(): array
    {
        return [
            'provisionType'                 => 'garantie',
            'description'                   => 'Waarborg 2026',
            'recognitionDate'               => '2026-01-01',
            'recognitionRationale'          => '1.5% claim-rate × omzet',
            'legalOrConstructiveObligation' => 'constructive',
            'obligatingEvent'               => 'Verkoop met waarborg 12 maanden',
            'probabilityOfOutflow'          => 0.8,
            'bestEstimate'                  => 50000.0,
            'bestEstimateRationale'         => 'EUR 50K op basis van historische claim-rate',
            'expectedTiming'                => [
                'shortTerm'  => 50000.0,
                'mediumTerm' => 0.0,
                'longTerm'   => 0.0,
            ],
            'presentationOnBalanceSheet'    => 'current',
            'linkedAccount'                 => '1900',
            'status'                        => 'draft',
            'priorYearBalanceTotal'         => 70000000.0,
            'administrationId'              => 'adm-1',
        ];

    }//end completeImmaterialProvision()


    // ---------------------------------------------------------------------- //
    // T29: three-criteria gating                                             //
    // ---------------------------------------------------------------------- //


    /**
     * REQ-PROV-001: a complete immaterial provision satisfying all three
     * criteria activates.
     *
     * @return void
     */
    public function testCompleteImmaterialProvisionCanActivate(): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->canActivateProvision(provisionId: 'p-1', object: $this->completeImmaterialProvision()));

    }//end testCompleteImmaterialProvisionCanActivate()


    /**
     * REQ-PROV-001: missing legal/constructive obligation blocks activation.
     *
     * @return void
     */
    public function testMissingObligationClassificationBlocksActivation(): void
    {
        $object = $this->completeImmaterialProvision();
        unset($object['legalOrConstructiveObligation']);

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-1', object: $object));

    }//end testMissingObligationClassificationBlocksActivation()


    /**
     * REQ-PROV-001: empty obligatingEvent blocks activation (whitespace-only
     * counts as empty).
     *
     * @return void
     */
    public function testEmptyObligatingEventBlocksActivation(): void
    {
        $object                    = $this->completeImmaterialProvision();
        $object['obligatingEvent'] = '   ';

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-1', object: $object));

    }//end testEmptyObligatingEventBlocksActivation()


    /**
     * REQ-PROV-001 / REQ-PROV-007: probability ≤ 0.5 blocks Provision
     * activation and re-routes to ContingentLiability (asserted by T35).
     *
     * @return void
     */
    public function testProbabilityAtOrBelowHalfBlocksActivation(): void
    {
        $object                         = $this->completeImmaterialProvision();
        $object['probabilityOfOutflow'] = 0.5;

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-1', object: $object));

    }//end testProbabilityAtOrBelowHalfBlocksActivation()


    /**
     * REQ-PROV-001: empty bestEstimate blocks activation.
     *
     * @return void
     */
    public function testZeroBestEstimateBlocksActivation(): void
    {
        $object                 = $this->completeImmaterialProvision();
        $object['bestEstimate'] = 0.0;

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-1', object: $object));

    }//end testZeroBestEstimateBlocksActivation()


    /**
     * REQ-PROV-001: empty bestEstimateRationale blocks activation.
     *
     * @return void
     */
    public function testEmptyBestEstimateRationaleBlocksActivation(): void
    {
        $object                          = $this->completeImmaterialProvision();
        $object['bestEstimateRationale'] = '';

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-1', object: $object));

    }//end testEmptyBestEstimateRationaleBlocksActivation()


    // ---------------------------------------------------------------------- //
    // T30: disconteringsvoet enforcement                                     //
    // ---------------------------------------------------------------------- //


    /**
     * REQ-PROV-003: longTerm > 0 without discountRateApplied blocks activation.
     *
     * @return void
     */
    public function testLongTermOutflowWithoutDiscountRateBlocksActivation(): void
    {
        $object                                     = $this->completeImmaterialProvision();
        $object['expectedTiming']['longTerm']       = 300000.0;
        $object['bestEstimate']                     = 80000.0;
        $object['bestEstimateRationale']            = 'EUR 800K oddly small ratio just to exercise the gate';
        // No discountRateApplied populated.
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-disc', object: $object));

    }//end testLongTermOutflowWithoutDiscountRateBlocksActivation()


    /**
     * REQ-PROV-003: longTerm > 0 WITH a positive discountRateApplied passes the
     * disconteringsvoet gate.
     *
     * @return void
     */
    public function testLongTermOutflowWithDiscountRateActivates(): void
    {
        $object                                     = $this->completeImmaterialProvision();
        $object['expectedTiming']['longTerm']       = 300000.0;
        $object['discountRateApplied']              = 0.03;
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->canActivateProvision(provisionId: 'p-disc', object: $object));

    }//end testLongTermOutflowWithDiscountRateActivates()


    // ---------------------------------------------------------------------- //
    // T31: materiality peer-review + CFO sign-off                            //
    // ---------------------------------------------------------------------- //


    /**
     * REQ-PROV-010 / REQ-PROV-018: bestEstimate > EUR 100K requires
     * peerReviewer + peerReviewDate + cfoApprover + cfoApprovalDate before
     * activation.
     *
     * @return void
     */
    public function testMaterialProvisionWithoutSignOffBlocksActivation(): void
    {
        $object                              = $this->completeImmaterialProvision();
        $object['bestEstimate']              = 200000.0;
        $object['bestEstimateRationale']     = 'EUR 200K cleanup raming';
        $object['expectedTiming']            = [
            'shortTerm'  => 200000.0,
            'mediumTerm' => 0.0,
            'longTerm'   => 0.0,
        ];
        $object['priorYearBalanceTotal']     = 70000000.0;

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-mat', object: $object));

    }//end testMaterialProvisionWithoutSignOffBlocksActivation()


    /**
     * REQ-PROV-010 / REQ-PROV-018: > 1% of priorYearBalanceTotal triggers the
     * materiality gate even when the absolute amount is < EUR 100K.
     *
     * @return void
     */
    public function testRatioMaterialityTriggersSignOffGate(): void
    {
        $object                          = $this->completeImmaterialProvision();
        $object['bestEstimate']          = 90000.0;
        $object['priorYearBalanceTotal'] = 5000000.0;
        $object['expectedTiming']        = [
            'shortTerm'  => 90000.0,
            'mediumTerm' => 0.0,
            'longTerm'   => 0.0,
        ];
        // Absolute under EUR 100K but 90/5000 = 1.8% > 1% of balance => material.
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-ratio', object: $object));

    }//end testRatioMaterialityTriggersSignOffGate()


    /**
     * REQ-PROV-010 / REQ-PROV-018: complete sign-off chain on a material
     * provision activates.
     *
     * @return void
     */
    public function testMaterialProvisionWithSignOffActivates(): void
    {
        $object                              = $this->completeImmaterialProvision();
        $object['bestEstimate']              = 200000.0;
        $object['bestEstimateRationale']     = 'EUR 200K cleanup raming';
        $object['expectedTiming']            = [
            'shortTerm'  => 200000.0,
            'mediumTerm' => 0.0,
            'longTerm'   => 0.0,
        ];
        $object['priorYearBalanceTotal']     = 70000000.0;
        $object['peerReviewer']              = 'user-reviewer';
        $object['peerReviewDate']            = '2026-01-10';
        $object['cfoApprover']               = 'user-cfo';
        $object['cfoApprovalDate']           = '2026-01-12';

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->canActivateProvision(provisionId: 'p-mat', object: $object));

    }//end testMaterialProvisionWithSignOffActivates()


    // ---------------------------------------------------------------------- //
    // T34 + T20: herstructurering type-specific gate                         //
    // ---------------------------------------------------------------------- //


    /**
     * REQ-PROV-005: herstructurering provision without a linked detail FK
     * blocks activation.
     *
     * @return void
     */
    public function testHerstructureringProvisionWithoutDetailFkBlocksActivation(): void
    {
        $object                  = $this->completeImmaterialProvision();
        $object['provisionType'] = 'herstructurering';
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-her', object: $object));

    }//end testHerstructureringProvisionWithoutDetailFkBlocksActivation()


    // ---------------------------------------------------------------------- //
    // T29 / T35: claims type-specific gate                                   //
    // ---------------------------------------------------------------------- //


    /**
     * REQ-PROV-006: claims provision without a linked detail FK blocks
     * activation.
     *
     * @return void
     */
    public function testClaimsProvisionWithoutDetailFkBlocksActivation(): void
    {
        $object                  = $this->completeImmaterialProvision();
        $object['provisionType'] = 'claims';
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canActivateProvision(provisionId: 'p-claim', object: $object));

    }//end testClaimsProvisionWithoutDetailFkBlocksActivation()


    // ---------------------------------------------------------------------- //
    // T32 + T16: ProvisionMovement close / immutability                      //
    // ---------------------------------------------------------------------- //


    /**
     * REQ-PROV-004 / REQ-PROV-016: a movement with provision, period, opening,
     * closing and a linked journal entry closes.
     *
     * @return void
     */
    public function testCompleteMovementCanClose(): void
    {
        $object = [
            'provision'            => 'provision-garantie-2026',
            'period'               => '2026-12',
            'openingBalance'       => 0.0,
            'closingBalance'       => 75000.0,
            'linkedJournalEntries' => ['je-001', 'je-002'],
        ];
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->canCloseMovement(movementId: 'pm-1', object: $object));

    }//end testCompleteMovementCanClose()


    /**
     * REQ-PROV-016: a movement without linkedJournalEntries cannot be closed
     * (audit trail must be closed before period immutability engages).
     *
     * @return void
     */
    public function testMovementWithoutJournalEntriesBlocksClose(): void
    {
        $object = [
            'provision'            => 'provision-garantie-2026',
            'period'               => '2026-12',
            'openingBalance'       => 0.0,
            'closingBalance'       => 75000.0,
            'linkedJournalEntries' => [],
        ];
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canCloseMovement(movementId: 'pm-1', object: $object));

    }//end testMovementWithoutJournalEntriesBlocksClose()


    /**
     * REQ-PROV-004: a movement missing the period identifier cannot be closed.
     *
     * @return void
     */
    public function testMovementWithoutPeriodBlocksClose(): void
    {
        $object = [
            'provision'            => 'provision-garantie-2026',
            'period'               => '',
            'openingBalance'       => 0.0,
            'closingBalance'       => 75000.0,
            'linkedJournalEntries' => ['je-001'],
        ];
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canCloseMovement(movementId: 'pm-1', object: $object));

    }//end testMovementWithoutPeriodBlocksClose()


    /**
     * REQ-PROV-004: a movement with non-numeric closingBalance cannot close
     * (the calculation must have run).
     *
     * @return void
     */
    public function testMovementWithoutClosingBalanceBlocksClose(): void
    {
        $object = [
            'provision'            => 'provision-garantie-2026',
            'period'               => '2026-12',
            'openingBalance'       => 0.0,
            'closingBalance'       => null,
            'linkedJournalEntries' => ['je-001'],
        ];
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->canCloseMovement(movementId: 'pm-1', object: $object));

    }//end testMovementWithoutClosingBalanceBlocksClose()


}//end class
