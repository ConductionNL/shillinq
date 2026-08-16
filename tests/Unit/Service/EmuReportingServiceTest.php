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
class EmuReportingServiceTest extends TestCase {

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
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new EmuReportingService(logger: $this->logger);
	}//end setUp()

	/**
	 * REQ-EMU-002: an account 48xx line is classified as eliminatie-afschrijving,
	 * saldo-verhogend.
	 *
	 * @return void
	 */
	public function testClassifyAfschrijvingLine(): void {
		$adj = $this->service->classifyAdjustment(
			['accountNumber' => '4801000', 'amount' => -1240000.0, 'description' => 'Afschrijving'],
			'emu-2026-q2'
		);

		self::assertNotNull($adj);
		self::assertSame('elimination-depreciation', $adj['type']);
		self::assertSame('balance-increasing', $adj['direction']);
		// bedrag is the absolute value (richting carries the sign).
		self::assertSame(1240000.0, $adj['amount']);
		self::assertSame('emu-2026-q2', $adj['reportId']);
	}//end testClassifyAfschrijvingLine()

	/**
	 * REQ-EMU-002: a 010 investment line is toevoeging-bruto-investering,
	 * saldo-verlagend.
	 *
	 * @return void
	 */
	public function testClassifyInvesteringLine(): void {
		$adj = $this->service->classifyAdjustment(['accountNumber' => '010500', 'amount' => 820000.0], 'r1');
		self::assertNotNull($adj);
		self::assertSame('addition-gross-investment', $adj['type']);
		self::assertSame('balance-decreasing', $adj['direction']);
	}//end testClassifyInvesteringLine()

	/**
	 * REQ-EMU-002 Regel 7: a line whose factuurmoment differs from its
	 * betaalmoment becomes correctie-transactiemoment.
	 *
	 * @return void
	 */
	public function testClassifyTransactiemomentCorrection(): void {
		$adj = $this->service->classifyAdjustment(
			[
				'accountNumber' => '4801000',
				'amount' => 1000.0,
				'invoiceMoment' => '2026-04-30T00:00:00+02:00',
				'paymentMoment' => '2026-05-22T00:00:00+02:00',
			],
			'r1'
		);
		self::assertNotNull($adj);
		self::assertSame('correction-transaction-moment', $adj['type']);
	}//end testClassifyTransactiemomentCorrection()

	/**
	 * A non-macro account returns null (ordinary cash transaction).
	 *
	 * @return void
	 */
	public function testNonMacroAccountReturnsNull(): void {
		self::assertNull($this->service->classifyAdjustment(['accountNumber' => '8000', 'amount' => 100.0], 'r1'));
		self::assertNull($this->service->classifyAdjustment(['accountNumber' => '', 'amount' => 100.0], 'r1'));
	}//end testNonMacroAccountReturnsNull()

	/**
	 * REQ-EMU-002: net effect sums verhogend minus verlagend; neutraal ignored.
	 *
	 * @return void
	 */
	public function testNetAdjustmentEffect(): void {
		$adjustments = [
			['amount' => 5200000.0, 'direction' => 'balance-increasing'],
			['amount' => 8700000.0, 'direction' => 'balance-decreasing'],
			['amount' => 100000.0, 'direction' => 'balance-neutral'],
		];
		// 5.2M - 8.7M = -3.5M (the design.md worked example).
		self::assertSame(-3500000.0, $this->service->netAdjustmentEffect($adjustments));
	}//end testNetAdjustmentEffect()

	/**
	 * REQ-EMU-004: bruto schuld sums AF.2/3/4 flagged positions; AF.7 excluded.
	 *
	 * @return void
	 */
	public function testComputeBrutoSchuld(): void {
		$positions = [
			['categoryEurostat' => 'off_4-loans', 'outstandingDebt' => 18750000.0, 'teltMeeInEmuDebt' => true],
			['categoryEurostat' => 'off_2-deposits', 'outstandingDebt' => 2100000.0, 'teltMeeInEmuDebt' => true],
			['categoryEurostat' => 'off_7-derivatives', 'outstandingDebt' => 800000.0, 'teltMeeInEmuDebt' => false],
		];
		$result = $this->service->computeBrutoSchuld($positions);

		self::assertSame(20850000.0, $result['gross']);
		self::assertArrayHasKey('off_4-loans', $result['perCategory']);
		self::assertArrayNotHasKey('off_7-derivatives', $result['perCategory'], 'Derivaten tellen niet mee');
	}//end testComputeBrutoSchuld()

	/**
	 * A position flagged telt-mee but in a non-EMU category is still excluded.
	 *
	 * @return void
	 */
	public function testBrutoSchuldExcludesOverigCategory(): void {
		$positions = [
			['categoryEurostat' => 'other', 'outstandingDebt' => 500000.0, 'teltMeeInEmuDebt' => true],
		];
		self::assertSame(0.0, $this->service->computeBrutoSchuld($positions)['gross']);
	}//end testBrutoSchuldExcludesOverigCategory()

	/**
	 * REQ-EMU-007: variance and percentage match the design worked example.
	 *
	 * @return void
	 */
	public function testComputeVariance(): void {
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
	public function testVarianceZeroBudget(): void {
		$result = $this->service->computeVariance(1000.0, 0.0);
		self::assertSame(0.0, $result['afwijkingPercentage']);
		self::assertSame(1000.0, $result['afwijking']);
	}//end testVarianceZeroBudget()

	/**
	 * REQ-EMU-007: top-3 contributors are the three largest by absolute bedrag.
	 *
	 * @return void
	 */
	public function testTopContributors(): void {
		$adjustments = [
			['amount' => 230000.0, 'type' => 'a'],
			['amount' => 820000.0, 'type' => 'b'],
			['amount' => 450000.0, 'type' => 'c'],
			['amount' => 10000.0, 'type' => 'd'],
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
	public function testReferentiewaardeAlert(): void {
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
	public function testReconciliationSucceeds(): void {
		// BBV +4.2M, adjustments net -6.5M → expected sum of quarters -2.3M.
		$adjustments = [
			['amount' => 6500000.0, 'direction' => 'balance-decreasing'],
		];
		$result = $this->service->reconcile(4200000.0, $adjustments, [-575000.0, -575000.0, -575000.0, -575000.0]);
		self::assertSame('succeeded', $result['controle']);
		self::assertSame(0.0, $result['verschil']);
		self::assertSame(-6500000.0, $result['totaleAdjustments']);
	}//end testReconciliationSucceeds()

	/**
	 * REQ-EMU-009: reconciliation fails outside tolerance.
	 *
	 * @return void
	 */
	public function testReconciliationFails(): void {
		$this->logger->expects(self::once())->method('warning');
		$result = $this->service->reconcile(4200000.0, [], [-2300000.0, 0.0, 0.0, 0.0]);
		// Expected sum = BBV (4.2M) + 0 adjustments = 4.2M; actual = -2.3M → mismatch.
		self::assertSame('failed', $result['controle']);
		self::assertNotSame(0.0, $result['verschil']);
	}//end testReconciliationFails()

	/**
	 * REQ-EMU-007 / Task 18: quarterly trend deltas + cumulatief saldo.
	 *
	 * @return void
	 */
	public function testComputeTrendDeltasAndCumulatief(): void {
		$result = $this->service->computeTrend([-100000.0, -150000.0, -200000.0, -300000.0]);
		self::assertSame(-750000.0, $result['cumulatief']);
		self::assertSame([0.0, -50000.0, -50000.0, -100000.0], $result['deltas']);
	}//end testComputeTrendDeltasAndCumulatief()

	/**
	 * REQ-EMU-007: empty trend input returns 0/empty (no first-quarter NPE).
	 *
	 * @return void
	 */
	public function testComputeTrendEmptyInputReturnsZero(): void {
		$result = $this->service->computeTrend([]);
		self::assertSame(0.0, $result['cumulatief']);
		self::assertSame([], $result['deltas']);
	}//end testComputeTrendEmptyInputReturnsZero()

	/**
	 * REQ-EMU-008 / Task 20: sector macro-ruimte alert above threshold.
	 *
	 * @return void
	 */
	public function testShouldAlertSectorMacroAboveThreshold(): void {
		// 850M / 1000M = 85% > 80% → alert.
		self::assertTrue($this->service->shouldAlertSectorMacro(850000000.0, 1000000000.0));
	}//end testShouldAlertSectorMacroAboveThreshold()

	/**
	 * REQ-EMU-008: sector macro alert false below threshold + zero-norm guard.
	 *
	 * @return void
	 */
	public function testShouldAlertSectorMacroBelowThresholdAndZeroNorm(): void {
		self::assertFalse($this->service->shouldAlertSectorMacro(300000000.0, 1000000000.0));
		self::assertFalse($this->service->shouldAlertSectorMacro(100.0, 0.0));
	}//end testShouldAlertSectorMacroBelowThresholdAndZeroNorm()

	/**
	 * REQ-EMU-003 / Task 29: the 10 CBS-tussenregels are rendered in order.
	 *
	 * @return void
	 */
	public function testRenderCbsTussenregelsTenRows(): void {
		$adjustments = [
			['type' => 'elimination-depreciation', 'direction' => 'balance-increasing', 'amount' => 1240000.0],
			['type' => 'addition-gross-investment', 'direction' => 'balance-decreasing', 'amount' => 820000.0],
			['type' => 'elimination-provision-contribution', 'direction' => 'balance-increasing', 'amount' => 450000.0],
		];
		$rows = $this->service->renderCbsTussenregels(4200000.0, $adjustments, -2300000.0);
		self::assertCount(10, $rows);
		// Regel 1 = BBV saldo, Regel 10 = EMU-saldo.
		self::assertSame(1, $rows[0]['rule']);
		self::assertSame(4200000.0, $rows[0]['amount']);
		self::assertSame(10, $rows[9]['rule']);
		self::assertSame(-2300000.0, $rows[9]['amount']);
		// Regel 3 (bruto investering) negative because richting saldo-verlagend → row flipped.
		self::assertSame(820000.0, $rows[2]['amount']);
		// Regel 6 (afschrijving) saldo-verhogend stays positive.
		self::assertSame(1240000.0, $rows[5]['amount']);
	}//end testRenderCbsTussenregelsTenRows()

	/**
	 * Task 14: explicit iv3 on the item wins.
	 *
	 * @return void
	 */
	public function testMapIv3ExplicitIv3Wins(): void {
		$item = ['iv3' => ['hoofdstuk' => '8', 'functie' => '810', 'category' => '3.4.1']];
		$iv3 = $this->service->mapIv3Classification(item: $item, taskFieldMap: ['4.2' => ['hoofdstuk' => '4']]);
		self::assertSame('8', $iv3['hoofdstuk']);
	}//end testMapIv3ExplicitIv3Wins()

	/**
	 * Task 14: taakveld lookup when explicit iv3 absent.
	 *
	 * @return void
	 */
	public function testMapIv3TaakveldFallback(): void {
		$item = ['taskField' => '4.2'];
		$iv3 = $this->service->mapIv3Classification(
			item: $item,
			taskFieldMap: ['4.2' => ['hoofdstuk' => '4', 'functie' => '420', 'category' => '3.5.1']]
		);
		self::assertSame('4', $iv3['hoofdstuk']);
		self::assertSame('420', $iv3['functie']);
	}//end testMapIv3TaakveldFallback()

	/**
	 * Task 14: longest-prefix account-map match wins.
	 *
	 * @return void
	 */
	public function testMapIv3AccountPrefixLongestWins(): void {
		$accountMap = [
			'4' => ['hoofdstuk' => 'X', 'functie' => '000', 'category' => '0.0.0'],
			'4801' => ['hoofdstuk' => '4', 'functie' => '420', 'category' => '3.5.1'],
		];
		$iv3 = $this->service->mapIv3Classification(item: ['accountNumber' => '4801000'], accountMap: $accountMap);
		self::assertSame('4', $iv3['hoofdstuk']);
		self::assertSame('420', $iv3['functie']);
	}//end testMapIv3AccountPrefixLongestWins()

	/**
	 * Task 14: no lookup source resolves → null.
	 *
	 * @return void
	 */
	public function testMapIv3UnresolvedReturnsNull(): void {
		self::assertNull($this->service->mapIv3Classification(item: ['accountNumber' => '999']));
	}//end testMapIv3UnresolvedReturnsNull()

	/**
	 * Task 25 / REQ-EMU-005: S.1313 counterparty resolves to intern-S1313.
	 *
	 * @return void
	 */
	public function testResolveConsolidatieEmuS1313(): void {
		$tegen = ['sector' => 'S.1313', 'name' => 'Veiligheidsregio Brabant-Zuid'];
		self::assertSame('intern-S1313', $this->service->resolveConsolidatieEmu(counterparty: $tegen));
	}//end testResolveConsolidatieEmuS1313()

	/**
	 * Task 25 / REQ-EMU-005: Wet fido exemption flips an S.1313 counterparty to extern.
	 *
	 * @return void
	 */
	public function testResolveConsolidatieEmuWetFidoExemptionWins(): void {
		$tegen = ['sector' => 'S.1313', 'wetFidoExemption' => true];
		self::assertSame('extern', $this->service->resolveConsolidatieEmu(counterparty: $tegen));
	}//end testResolveConsolidatieEmuWetFidoExemptionWins()

	/**
	 * Task 25: explicit consolidatieEMU override always wins.
	 *
	 * @return void
	 */
	public function testResolveConsolidatieEmuExplicitOverrideWins(): void {
		$tegen = ['sector' => 'S.1313', 'consolidationEMU' => 'internal-entity'];
		self::assertSame('internal-entity', $this->service->resolveConsolidatieEmu(counterparty: $tegen));
	}//end testResolveConsolidatieEmuExplicitOverrideWins()
}//end class
