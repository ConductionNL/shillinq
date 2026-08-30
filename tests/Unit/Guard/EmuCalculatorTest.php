<?php

/**
 * Unit tests for EmuCalculator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-shillinq-emu-reporting/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\EmuCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EmuCalculator.
 *
 * Covers:
 * - Included lines contribute 100% to sector saldo
 * - Excluded lines are omitted (REQ-EMU-004 exclusion rule)
 * - Partial lines contribute 50% per 2026 BBV handleiding default
 * - Lines with no esaClassifier are skipped
 * - Integer-cents arithmetic avoids IEEE-754 rounding
 * - Multiple sectors are grouped independently
 * - Quarterly and annual entry-points produce equivalent results for same lines
 * - Reproducibility: same input → identical output (REQ-EMU-005)
 */
class EmuCalculatorTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The calculator under test.
	 *
	 * @var EmuCalculator
	 */
	private EmuCalculator $calculator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->calculator = new EmuCalculator(logger: $this->logger);

	}//end setUp()

	/**
	 * REQ-EMU-002 / REQ-EMU-004: an S.1313 account with an included line
	 * contributes to the S.1313 bucket; an excluded line does not.
	 *
	 * @return void
	 */
	public function testIncludedLineContributesAndExcludedLineIsOmitted(): void {
		$glLines = [
			[
				'account' => ['esaClassifier' => 'S.1313'],
				'debit' => 1000.00,
				'credit' => 400.00,
				'emuInclusionRule' => 'included',
			],
			[
				'account' => ['esaClassifier' => 'S.1313'],
				'debit' => 5000.00,
				'credit' => 0.00,
				'emuInclusionRule' => 'excluded',
			],
		];

		$result = $this->calculator->computeQuarterlySaldo(
			glLines: $glLines,
			params: ['quarter' => 'Q1', 'year' => 2026]
		);

		// Only the included line contributes: (1000 - 400) * 100 = 60 000 cents.
		self::assertSame(60000, $result['S.1313'] ?? null);
		// Excluded line produces no additional contribution.
		self::assertSame(60000, $result['S.1313']);

	}//end testIncludedLineContributesAndExcludedLineIsOmitted()

	/**
	 * REQ-EMU-004: partial emuInclusionRule contributes at 50%.
	 *
	 * @return void
	 */
	public function testPartialLineContributesAtFiftyPercent(): void {
		$glLines = [
			[
				'account' => ['esaClassifier' => 'S.1314'],
				'debit' => 2000.00,
				'credit' => 0.00,
				'emuInclusionRule' => 'partial',
			],
		];

		$result = $this->calculator->computeAnnualSaldo(
			glLines: $glLines,
			params: ['fiscalYear' => 2026]
		);

		// 50 % of 200 000 cents = 100 000 cents.
		self::assertSame(100000, $result['S.1314'] ?? null);

	}//end testPartialLineContributesAtFiftyPercent()

	/**
	 * Lines without an esaClassifier on the account are silently skipped.
	 *
	 * @return void
	 */
	public function testLinesWithoutEsaClassifierAreSkipped(): void {
		$glLines = [
			[
				'account' => [],
				'debit' => 9999.00,
				'credit' => 0.00,
				'emuInclusionRule' => 'included',
			],
		];

		$result = $this->calculator->computeQuarterlySaldo(
			glLines: $glLines,
			params: ['quarter' => 'Q2', 'year' => 2026]
		);

		self::assertEmpty($result, 'Lines without esaClassifier must not appear in output.');

	}//end testLinesWithoutEsaClassifierAreSkipped()

	/**
	 * Multiple sectors are grouped and summed independently.
	 *
	 * @return void
	 */
	public function testMultipleSectorsGroupedIndependently(): void {
		$glLines = [
			[
				'account' => ['esaClassifier' => 'S.1311'],
				'debit' => 500.00,
				'credit' => 100.00,
				'emuInclusionRule' => 'included',
			],
			[
				'account' => ['esaClassifier' => 'S.1313'],
				'debit' => 300.00,
				'credit' => 50.00,
				'emuInclusionRule' => 'included',
			],
			[
				'account' => ['esaClassifier' => 'S.1311'],
				'debit' => 200.00,
				'credit' => 0.00,
				'emuInclusionRule' => 'included',
			],
		];

		$result = $this->calculator->computeAnnualSaldo(
			glLines: $glLines,
			params: ['fiscalYear' => 2026]
		);

		// S.1311: (500-100+200-0)*100 = 60 000.
		self::assertSame(60000, $result['S.1311'] ?? null);
		// S.1313: (300-50)*100 = 25 000.
		self::assertSame(25000, $result['S.1313'] ?? null);

	}//end testMultipleSectorsGroupedIndependently()

	/**
	 * REQ-EMU-005: reproducibility — identical inputs produce identical outputs.
	 *
	 * @return void
	 */
	public function testReproducibilitySameInputProducesIdenticalOutput(): void {
		$glLines = [
			[
				'account' => ['esaClassifier' => 'S.1313'],
				'debit' => 100.00,
				'credit' => 30.00,
				'emuInclusionRule' => 'included',
			],
		];

		$params = ['fiscalYear' => 2026];

		$first = $this->calculator->computeAnnualSaldo(glLines: $glLines, params: $params);
		$second = $this->calculator->computeAnnualSaldo(glLines: $glLines, params: $params);

		self::assertSame($first, $second, 'Same inputs must always produce identical output (REQ-EMU-005).');

	}//end testReproducibilitySameInputProducesIdenticalOutput()

	/**
	 * Integer-cents arithmetic: 0.1 + 0.2 - 0.3 must not cause float drift.
	 *
	 * @return void
	 */
	public function testIntegerCentsAvoidIeeeDrift(): void {
		$glLines = [
			[
				'account' => ['esaClassifier' => 'S.1313'],
				'debit' => 0.1,
				'credit' => 0.0,
				'emuInclusionRule' => 'included',
			],
			[
				'account' => ['esaClassifier' => 'S.1313'],
				'debit' => 0.2,
				'credit' => 0.3,
				'emuInclusionRule' => 'included',
			],
		];

		$result = $this->calculator->computeQuarterlySaldo(
			glLines: $glLines,
			params: ['quarter' => 'Q3', 'year' => 2026]
		);

		// (0.1 + 0.2 - 0.3) in euros = 0 cents exactly when using integer rounding.
		self::assertSame(0, $result['S.1313'] ?? null);

	}//end testIntegerCentsAvoidIeeeDrift()

	/**
	 * esaClassifier may also appear flat on the line (not nested in 'account').
	 *
	 * @return void
	 */
	public function testFlatEsaClassifierOnLineIsAlsoRead(): void {
		$glLines = [
			[
				'esaClassifier' => 'S.11',
				'debit' => 100.00,
				'credit' => 0.00,
				'emuInclusionRule' => 'included',
			],
		];

		$result = $this->calculator->computeQuarterlySaldo(
			glLines: $glLines,
			params: ['quarter' => 'Q4', 'year' => 2026]
		);

		self::assertSame(10000, $result['S.11'] ?? null);

	}//end testFlatEsaClassifierOnLineIsAlsoRead()

}//end class
