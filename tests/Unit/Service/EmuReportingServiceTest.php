<?php

/**
 * Unit tests for EmuReportingService.
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
 * @spec openspec/changes/bookkeeping-emu-reporting/specs/bookkeeping-emu-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\EmuReportingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the EMU reporting pipeline logic (REQ-EMU-002/004/007/008/009).
 */
class EmuReportingServiceTest extends TestCase
{

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Service under test.
     *
     * @var EmuReportingService
     */
    private EmuReportingService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->service = new EmuReportingService(logger: $this->logger);
    }//end setUp()

    /**
     * REQ-EMU-002: an account 48xx line is classified as eliminatie-afschrijving,
     * saldo-verhogend.
     *
     * @return void
     */
    public function testClassifyAfschrijvingLine(): void
    {
        $adj = $this->service->classifyAdjustment(
            ['accountNumber' => '4801000', 'amount' => -1240000.0, 'description' => 'Afschrijving'],
            'emu-2026-q2'
        );

        self::assertNotNull($adj);
        self::assertSame('eliminatie-afschrijving', $adj['type']);
        self::assertSame('saldo-verhogend', $adj['richting']);
        // bedrag is the absolute value (richting carries the sign).
        self::assertSame(1240000.0, $adj['bedrag']);
        self::assertSame('emu-2026-q2', $adj['reportId']);
    }//end testClassifyAfschrijvingLine()

    /**
     * REQ-EMU-002: a 010 investment line is toevoeging-bruto-investering,
     * saldo-verlagend.
     *
     * @return void
     */
    public function testClassifyInvesteringLine(): void
    {
        $adj = $this->service->classifyAdjustment(['accountNumber' => '010500', 'amount' => 820000.0], 'r1');
        self::assertNotNull($adj);
        self::assertSame('toevoeging-bruto-investering', $adj['type']);
        self::assertSame('saldo-verlagend', $adj['richting']);
    }//end testClassifyInvesteringLine()

    /**
     * REQ-EMU-002 Regel 7: a line whose factuurmoment differs from its
     * betaalmoment becomes correctie-transactiemoment.
     *
     * @return void
     */
    public function testClassifyTransactiemomentCorrection(): void
    {
        $adj = $this->service->classifyAdjustment(
            [
                'accountNumber' => '4801000',
                'amount'        => 1000.0,
                'factuurmoment' => '2026-04-30T00:00:00+02:00',
                'betaalmoment'  => '2026-05-22T00:00:00+02:00',
            ],
            'r1'
        );
        self::assertNotNull($adj);
        self::assertSame('correctie-transactiemoment', $adj['type']);
    }//end testClassifyTransactiemomentCorrection()

    /**
     * A non-macro account returns null (ordinary cash transaction).
     *
     * @return void
     */
    public function testNonMacroAccountReturnsNull(): void
    {
        self::assertNull($this->service->classifyAdjustment(['accountNumber' => '8000', 'amount' => 100.0], 'r1'));
        self::assertNull($this->service->classifyAdjustment(['accountNumber' => '', 'amount' => 100.0], 'r1'));
    }//end testNonMacroAccountReturnsNull()

    /**
     * REQ-EMU-002: net effect sums verhogend minus verlagend; neutraal ignored.
     *
     * @return void
     */
    public function testNetAdjustmentEffect(): void
    {
        $adjustments = [
            ['bedrag' => 5200000.0, 'richting' => 'saldo-verhogend'],
            ['bedrag' => 8700000.0, 'richting' => 'saldo-verlagend'],
            ['bedrag' => 100000.0, 'richting' => 'saldo-neutraal'],
        ];
        // 5.2M - 8.7M = -3.5M (the design.md worked example).
        self::assertSame(-3500000.0, $this->service->netAdjustmentEffect($adjustments));
    }//end testNetAdjustmentEffect()

    /**
     * REQ-EMU-004: bruto schuld sums AF.2/3/4 flagged positions; AF.7 excluded.
     *
     * @return void
     */
    public function testComputeBrutoSchuld(): void
    {
        $positions = [
            ['categorieEurostat' => 'AF.4-loans', 'uitstaandeSchuld' => 18750000.0, 'teltMeeInEmuSchuld' => true],
            ['categorieEurostat' => 'AF.2-deposits', 'uitstaandeSchuld' => 2100000.0, 'teltMeeInEmuSchuld' => true],
            ['categorieEurostat' => 'AF.7-derivatives', 'uitstaandeSchuld' => 800000.0, 'teltMeeInEmuSchuld' => false],
        ];
        $result = $this->service->computeBrutoSchuld($positions);

        self::assertSame(20850000.0, $result['bruto']);
        self::assertArrayHasKey('AF.4-loans', $result['perCategorie']);
        self::assertArrayNotHasKey('AF.7-derivatives', $result['perCategorie'], 'Derivaten tellen niet mee');
    }//end testComputeBrutoSchuld()

    /**
     * A position flagged telt-mee but in a non-EMU category is still excluded.
     *
     * @return void
     */
    public function testBrutoSchuldExcludesOverigCategory(): void
    {
        $positions = [
            ['categorieEurostat' => 'overig', 'uitstaandeSchuld' => 500000.0, 'teltMeeInEmuSchuld' => true],
        ];
        self::assertSame(0.0, $this->service->computeBrutoSchuld($positions)['bruto']);
    }//end testBrutoSchuldExcludesOverigCategory()

    /**
     * REQ-EMU-007: variance and percentage match the design worked example.
     *
     * @return void
     */
    public function testComputeVariance(): void
    {
        // berekend -2.3M, begroot -1.8M → afwijking -0.5M, -27.8%.
        $result = $this->service->computeVariance(-2300000.0, -1800000.0);
        self::assertSame(-500000.0, $result['afwijking']);
        self::assertSame(-27.8, $result['afwijkingPercentage']);
    }//end testComputeVariance()

    /**
     * Variance percentage is 0 when begroot is zero (no division by zero).
     *
     * @return void
     */
    public function testVarianceZeroBudget(): void
    {
        $result = $this->service->computeVariance(1000.0, 0.0);
        self::assertSame(0.0, $result['afwijkingPercentage']);
        self::assertSame(1000.0, $result['afwijking']);
    }//end testVarianceZeroBudget()

    /**
     * REQ-EMU-007: top-3 contributors are the three largest by absolute bedrag.
     *
     * @return void
     */
    public function testTopContributors(): void
    {
        $adjustments = [
            ['bedrag' => 230000.0, 'type' => 'a'],
            ['bedrag' => 820000.0, 'type' => 'b'],
            ['bedrag' => 450000.0, 'type' => 'c'],
            ['bedrag' => 10000.0, 'type' => 'd'],
        ];
        $top = $this->service->topContributors($adjustments, 3);
        self::assertCount(3, $top);
        self::assertSame('b', $top[0]['type']);
        self::assertSame('c', $top[1]['type']);
        self::assertSame('a', $top[2]['type']);
    }//end testTopContributors()

    /**
     * REQ-EMU-008: alert fires at >= 80% of the referentiewaarde.
     *
     * @return void
     */
    public function testReferentiewaardeAlert(): void
    {
        // Cumulatief -7.1M tegen norm 8.5M = 83.5% → alert.
        self::assertTrue($this->service->shouldAlertReferentiewaarde(-7100000.0, 8500000.0));
        // 50% → no alert.
        self::assertFalse($this->service->shouldAlertReferentiewaarde(-4250000.0, 8500000.0));
        // No norm set → no alert.
        self::assertFalse($this->service->shouldAlertReferentiewaarde(-1000000.0, 0.0));
    }//end testReferentiewaardeAlert()

    /**
     * REQ-EMU-009: reconciliation succeeds when 4 quarters equal BBV + adjustments.
     *
     * @return void
     */
    public function testReconciliationSucceeds(): void
    {
        // BBV +4.2M, adjustments net -6.5M → expected sum of quarters -2.3M.
        $adjustments = [
            ['bedrag' => 6500000.0, 'richting' => 'saldo-verlagend'],
        ];
        $result = $this->service->reconcile(4200000.0, $adjustments, [-575000.0, -575000.0, -575000.0, -575000.0]);
        self::assertSame('geslaagd', $result['controle']);
        self::assertSame(0.0, $result['verschil']);
        self::assertSame(-6500000.0, $result['totaleAdjustments']);
    }//end testReconciliationSucceeds()

    /**
     * REQ-EMU-009: reconciliation fails outside tolerance.
     *
     * @return void
     */
    public function testReconciliationFails(): void
    {
        $this->logger->expects(self::once())->method('warning');
        $result = $this->service->reconcile(4200000.0, [], [-2300000.0, 0.0, 0.0, 0.0]);
        // Expected sum = BBV (4.2M) + 0 adjustments = 4.2M; actual = -2.3M → mismatch.
        self::assertSame('mislukt', $result['controle']);
        self::assertNotSame(0.0, $result['verschil']);
    }//end testReconciliationFails()
}//end class
