<?php

/**
 * Golden test for the envelope BudgetGridController builds from a grid.
 *
 * A grid whose root ledger groups cover every term of the computed-row
 * formulas, with a group keyed by slug, a group without code, nested groups,
 * unresolved members and accounts keyed by @self id; the whole envelope is
 * compared with the one the controller produced before its split.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\BudgetGridController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\BudgetGridCalculator;
use OCA\Shillinq\Service\BudgetGridReader;
use OCA\Shillinq\Service\BudgetVsActualsCalculator;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the full budget grid envelope.
 */
final class BudgetGridEnvelopeGoldenTest extends TestCase {

	/**
	 * The ledger groups: slug, id, account number, account type, budget, actual.
	 *
	 * @var array<int,array{0:string,1:string,2:string,3:string,4:int,5:int}>
	 */
	private const GROUPS = [
		['omzet', 'lg-omzet', '8000', 'revenue', 5000000, 6000000],
		['kostprijs-van-de-omzet', 'lg-kostprijs', '7000', 'expenses', 1500000, 2000000],
		['personeel', 'lg-personeel', '4000', 'expenses', 800000, 900000],
		['huisvesting', '', '4100', 'expenses', 200000, 150000],
		['afschrijvingen-op-vaste-activa', 'lg-afschr', '4200', 'expenses', 100000, 100000],
		['exploitatie-en-machinekosten', 'lg-expl', '4300', 'expenses', 50000, 70000],
		['verkoopkosten', 'lg-verkoop', '4400', 'expenses', 30000, 10000],
		['algemene-kosten', 'lg-alg', '4500', 'expenses', 20000, 25000],
		['rentebaten', 'lg-rb', '8500', 'revenue', 1000, 2000],
		['rentelasten', 'lg-rl', '4600', 'expenses', 5000, 4000],
		['vennootschapsbelasting', 'lg-vpb', '9000', 'expenses', 300000, 350000],
	];

	/**
	 * The grid the reader returns.
	 *
	 * @return array<string,mixed>
	 */
	private static function grid(): array {
		$entries = [];
		$ledgerGroupEntries = [];
		$keyToIndex = [];
		$actuals = [];
		$budgetLines = [];
		$types = [];
		$accounts = [];
		foreach (self::GROUPS as $index => [$slug, $id, $number, $type, $budget, $actual]) {
			$entries[$index] = ['id' => $id, 'slug' => $slug, 'code' => $slug, 'name' => ucfirst($slug)];
			$key = ($id !== '' ? $id : $slug);
			$ledgerGroupEntries[$index] = ['id' => $id, 'slug' => $slug, 'memberAccountNumbers' => [$number, '0000']];
			$keyToIndex[$key] = $index;
			$actuals[$number] = ['2027-01' => $actual, '2027-03' => 7];
			$budgetLines[] = ['ledgerGroupId' => $key, 'source' => 'manual', 'month01Amount' => $budget, 'month03Amount' => 11];
			$types[$number] = $type;
			$accounts[$number] = ['@self' => ['id' => 'self-' . $number], 'id' => 'acc-' . $number, 'name' => 'Account ' . $number];
		}

		$entries[11] = ['id' => 'lg-child', 'slug' => 'child', 'code' => '', 'name' => 'Child without code'];
		$ledgerGroupEntries[11] = ['id' => 'lg-child', 'slug' => 'child', 'memberAccountNumbers' => ['4001']];
		$keyToIndex['lg-child'] = 11;
		$actuals['4001'] = ['2027-01' => 3];
		$types['4001'] = 'expenses';
		$accounts['4001'] = ['id' => 'acc-4001'];
		$entries[12] = ['id' => 'lg-nocode', 'slug' => 'nocode', 'code' => '', 'name' => 'No code'];
		$ledgerGroupEntries[12] = ['id' => 'lg-nocode', 'slug' => 'nocode', 'memberAccountNumbers' => []];
		$keyToIndex['lg-nocode'] = 12;

		$column = static fn (string $key, bool $isPast, array $months): array => [
			'key' => $key,
			'label' => 'col ' . $key,
			'granularity' => 'month',
			'isPast' => $isPast,
			'monthKeys' => $months,
			'fiscalYears' => [2027],
		];

		return [
			'rowTree' => [
				'entries' => $entries,
				'childrenByIndex' => [2 => [11]],
				'rootIndexes' => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12],
			],
			'columns' => [$column('2027-01', true, ['2027-01']), $column('2027-02', false, ['2027-02']), $column('Q1', true, ['2027-01', '2027-03'])],
			'bvaContext' => [
				'actualsByAccountMonth' => $actuals,
				'ledgerGroupEntries' => $ledgerGroupEntries,
				'ledgerGroupKeyToIndex' => $keyToIndex,
				'ledgerGroupChildrenByIndex' => [2 => [11]],
			],
			'budgetLinesByFiscalYear' => [2027 => $budgetLines],
			'accountTypeByNumber' => $types,
			'accountByNumber' => $accounts,
		];

	}//end grid()

	/**
	 * The envelope of the grid.
	 *
	 * @return array<string,mixed>
	 */
	private function envelope(): array {
		$params = ['administrationId' => 'adm-1', 'granularity' => 'month', 'startPeriod' => '2027-01', 'endPeriod' => '2027-03'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$reader = $this->createMock(BudgetGridReader::class);
		$reader->method('loadGrid')->willReturn(self::grid());
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$controller = new BudgetGridController($request, $l10n, new NullLogger(), $reader, new BudgetGridCalculator(new BudgetVsActualsCalculator()), $context, $session);

		return $controller->index()->getData();

	}//end envelope()

	/**
	 * The envelope equals the golden one.
	 *
	 * @return void
	 */
	public function testEnvelopeMatchesTheGolden(): void {
		$golden = __DIR__ . '/Fixtures/budget-grid-envelope.golden.json';
		$actual = json_encode($this->envelope(), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n";
		if (getenv('WRITE_GOLDEN') === '1') {
			file_put_contents($golden, $actual);
		}

		// Decoded and compared with a delta, so the percentage row does not
		// depend on the float serialisation of the PHP build.
		self::assertEqualsWithDelta(
			json_decode((string)file_get_contents($golden), true),
			json_decode($actual, true),
			0.000000001
		);

	}//end testEnvelopeMatchesTheGolden()
}//end class
