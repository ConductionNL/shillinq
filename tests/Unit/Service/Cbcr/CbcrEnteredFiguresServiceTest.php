<?php

/**
 * Unit tests for CbcrEnteredFiguresService: entered and imported CbC figures
 * of a group entity whose books are not kept in Shillinq.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Service\Cbcr\CbcrEnteredFiguresService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The service over an in-memory register.
 */
#[CoversClass(CbcrEnteredFiguresService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CbcrEnteredFiguresServiceTest extends TestCase {

	/**
	 * The German subsidiary, whose books are kept elsewhere.
	 *
	 * @var array<string,mixed>
	 */
	private const GMBH = ['id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'ultimateParentEntity' => 'ge-holding'];

	/**
	 * The nine figures of the GmbH for 2026.
	 *
	 * @var array<string,mixed>
	 */
	private const FIGURES = [
		'unrelatedPartyRevenue' => 120000000,
		'relatedPartyRevenue' => '40000000',
		'profitBeforeTax' => '-250000.50',
		'incomeTaxPaidCash' => 0,
		'incomeTaxAccrued' => '3000000',
		'statedCapital' => '25000',
		'accumulatedEarnings' => '14000000',
		'numberOfEmployees' => '210',
		'tangibleAssetsOtherThanCash' => '31000000',
	];

	/**
	 * The in-memory register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The service over the given figure rows.
	 *
	 * @param array<int,array<string,mixed>> $figures The CbcrEntityFigures rows.
	 *
	 * @return CbcrEnteredFiguresService
	 */
	private function service(array $figures = []): CbcrEnteredFiguresService {
		$this->store = new InMemoryObjectServiceStub(['CbcrEntityFigures' => $figures]);
		$appConfig   = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new CbcrEnteredFiguresService($this->store, $appConfig, $l10n);
	}//end service()

	/**
	 * The stored figure rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		return array_map(
			static fn ($row): array => (array)ObjectIdentifier::recordWithId(candidate: $row),
			$this->store->setSchema('CbcrEntityFigures')->findAll()
		);
	}//end rows()

	/**
	 * Entered figures are stored as numbers, the employees as a whole number, with the source and time.
	 *
	 * @return void
	 */
	public function testEnterStoresTheFiguresAsNumbers(): void {
		$stored = $this->service()->enter(
			self::GMBH,
			2026,
			self::FIGURES + ['currency' => ' chf ', 'eligiblePayroll' => '8100000'],
			'entered',
			new DateTimeImmutable('2027-03-01T10:00:00+00:00')
		);

		self::assertSame('ge-gmbh', $stored['entity']);
		self::assertSame(2026, $stored['fiscalYear']);
		self::assertSame('entered', $stored['source']);
		self::assertSame('CHF', $stored['currency']);
		self::assertSame(120000000.0, $stored['unrelatedPartyRevenue']);
		self::assertSame(-250000.5, $stored['profitBeforeTax']);
		self::assertSame(0.0, $stored['incomeTaxPaidCash']);
		self::assertSame(210, $stored['numberOfEmployees']);
		self::assertSame(8100000.0, $stored['eligiblePayroll']);
		self::assertArrayNotHasKey('deferredTaxExpense', $stored);
		self::assertSame('entered', $stored['employeeMethod']);
		self::assertSame([], $stored['unmappedAccounts']);
		self::assertSame('2027-03-01T10:00:00+00:00', $stored['computedAt']);
		self::assertCount(1, $this->rows());
	}//end testEnterStoresTheFiguresAsNumbers()

	/**
	 * The figures of a year replace the ones stored for it; another year is a record of its own.
	 *
	 * @return void
	 */
	public function testEnterReplacesTheYearsFiguresInPlace(): void {
		$service = $this->service(
			[
				['id' => 'fig-2026', 'entity' => 'ge-gmbh', 'fiscalYear' => 2026, 'source' => 'entered', 'profitBeforeTax' => 1.0],
				['id' => 'fig-2025', 'entity' => 'ge-gmbh', 'fiscalYear' => 2025, 'source' => 'entered', 'profitBeforeTax' => 2.0],
			]
		);

		$stored = $service->enter(self::GMBH, 2026, self::FIGURES, 'imported');

		self::assertSame('fig-2026', $stored['id']);
		self::assertSame('imported', $stored['source']);
		self::assertSame('EUR', $stored['currency']);
		$byId = array_column($this->rows(), null, 'id');
		self::assertCount(2, $byId);
		self::assertSame(-250000.5, $byId['fig-2026']['profitBeforeTax']);
		self::assertSame(2.0, $byId['fig-2025']['profitBeforeTax']);
	}//end testEnterReplacesTheYearsFiguresInPlace()

	/**
	 * Each refusal names what is wrong and stores nothing.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>,2:string,3:string}>
	 */
	public static function refusals(): array {
		$notWhole = 'The number of employees is a whole number of at least zero.';
		$missing  = self::FIGURES;
		unset($missing['statedCapital']);

		return [
			'books kept here' => [
				self::GMBH + ['administrationId' => 'adm-de'],
				self::FIGURES,
				'entered',
				'Voorbeeld GmbH keeps its books in Shillinq; read its figures from the books instead.',
			],
			'a figure missing' => [self::GMBH, $missing, 'entered', 'Give the figure statedCapital.'],
			'a figure blank' => [self::GMBH, ['profitBeforeTax' => '  '] + self::FIGURES, 'entered', 'Give the figure profitBeforeTax.'],
			'a figure not a number' => [
				self::GMBH,
				['incomeTaxAccrued' => '3.000.000'] + self::FIGURES,
				'entered',
				'The figure incomeTaxAccrued is not a number.',
			],
			'an optional figure not a number' => [
				self::GMBH,
				self::FIGURES + ['deferredTaxExpense' => 'n/a'],
				'entered',
				'The figure deferredTaxExpense is not a number.',
			],
			'employees not whole' => [self::GMBH, ['numberOfEmployees' => '12.5'] + self::FIGURES, 'entered', $notWhole],
			'employees negative' => [self::GMBH, ['numberOfEmployees' => -1] + self::FIGURES, 'entered', $notWhole],
			'currency malformed' => [self::GMBH, self::FIGURES + ['currency' => 'EURO'], 'entered', 'The currency is a three-letter code, for example EUR.'],
			'source from the books' => [self::GMBH, self::FIGURES, 'books', 'These figures cannot be stored.'],
			'no entity id' => [['entityName' => 'Nameless'], self::FIGURES, 'entered', 'These figures cannot be stored.'],
		];
	}//end refusals()

	/**
	 * A refusal stores nothing.
	 *
	 * @param array<string,mixed> $entity  The entity.
	 * @param array<string,mixed> $input   The input.
	 * @param string              $source  The source.
	 * @param string              $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testEnterRefusesAndStoresNothing(array $entity, array $input, string $source, string $message): void {
		$service = $this->service();

		try {
			$service->enter($entity, 2026, $input, $source);
			self::fail('Expected a refusal: ' . $message);
		} catch (InvalidArgumentException $e) {
			self::assertSame($message, $e->getMessage());
		}

		self::assertSame([], $this->rows());
	}//end testEnterRefusesAndStoresNothing()

	/**
	 * A pasted CSV reads one figure per line, by any separator and in any case, skipping its header.
	 *
	 * @return void
	 */
	public function testParseReadsOneFigurePerLine(): void {
		$text = "Figure;Amount\r\n\"unrelatedPartyRevenue\";\"120000000\"\nRELATEDPARTYREVENUE,40000000\n\n"
			. "numberOfEmployees\t210\ncurrency;chf\nprofitBeforeTax";

		self::assertSame(
			[
				'unrelatedPartyRevenue' => '120000000',
				'relatedPartyRevenue' => '40000000',
				'numberOfEmployees' => '210',
				'currency' => 'chf',
				'profitBeforeTax' => '',
			],
			$this->service()->parse($text)
		);
	}//end testParseReadsOneFigurePerLine()

	/**
	 * An unknown name or a figure given twice is refused with its line.
	 *
	 * @return void
	 */
	public function testParseRefusesAnUnknownOrRepeatedFigure(): void {
		$service = $this->service();

		try {
			$service->parse("unrelatedPartyRevenue;1\nturnover;2");
			self::fail('An unknown figure was read.');
		} catch (InvalidArgumentException $e) {
			self::assertSame('Line 2 names no known figure: turnover.', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The figure statedCapital is given twice.');
		$service->parse("statedCapital;1\nstatedcapital;2");
	}//end testParseRefusesAnUnknownOrRepeatedFigure()

	/**
	 * A parsed file enters like the form does.
	 *
	 * @return void
	 */
	public function testAParsedFileIsStoredAsImported(): void {
		$lines = [];
		foreach (self::FIGURES as $field => $value) {
			$lines[] = $field . ',' . $value;
		}

		$service = $this->service();
		$stored  = $service->enter(self::GMBH, 2026, $service->parse(implode("\n", $lines)), 'imported');

		self::assertSame('imported', $stored['source']);
		self::assertSame(31000000.0, $stored['tangibleAssetsOtherThanCash']);
		self::assertSame(210, $stored['numberOfEmployees']);
	}//end testAParsedFileIsStoredAsImported()
}//end class
