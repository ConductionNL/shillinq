<?php

/**
 * Unit tests for GlLineResultStamps and the declared segment aggregations.
 *
 * The figures are the design's seed: Gemeente Voorbeeld, September 2026,
 * cost centre KP-300 Sociaal Domein. The signed amount is evaluated from the
 * expression the merged register declares, with the operator semantics of
 * OpenRegister's CalculationEvaluator (prop, lit, if, eq, -); the segment
 * figures are computed from the aggregation the merged register declares on
 * GLLine (filter, per-metric condition, sum). OpenRegister is not a composer
 * dependency of this app, so the engine itself is not loaded here.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Ledger
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-segment-results/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Ledger;

use OCA\Shillinq\Service\Ledger\GlLineResultStamps;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * The stamps, the signed amount and the segment result of KP-300.
 */
class GlLineResultStampsTest extends TestCase {

	public const ADMIN = 'gemeente-voorbeeld';

	public const TX_SEP = '0b6a1f3e-1c2d-4e5f-8a9b-000000000001';

	public const TX_DRAFT = '0b6a1f3e-1c2d-4e5f-8a9b-000000000002';

	public const TX_REVERSAL = '0b6a1f3e-1c2d-4e5f-8a9b-000000000003';

	public const TX_REREVERSAL = '0b6a1f3e-1c2d-4e5f-8a9b-000000000004';

	/**
	 * OpenRegister's calculation operators (CalculationEvaluator::OPERATORS).
	 */
	private const CALC_OPS = [
		'prop', 'lit', 'concat', 'if', 'not', 'and', 'or', '+', '-', '*', '/', '%', 'eq', 'ne', 'lt', 'lte', 'gt', 'gte',
		'now', 'diffDays', 'formatDate', 'dateDiff', 'dateAdd', 'sequence', 'max', 'min', 'coalesce', 'abs', 'round',
		'year', 'monthsElapsed', 'sha256',
	];

	/**
	 * Every write the store received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The seed records.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function records(): array {
		$accounts = [];
		foreach (['8200' => 'revenue', '4000' => 'expenses', '4100' => 'expenses', '1100' => 'assets'] as $number => $type) {
			$accounts[] = ['id' => 'acc-' . $number, 'accountNumber' => (string)$number, 'accountType' => $type, 'administrationId' => self::ADMIN];
		}

		return [
			'Account'       => $accounts,
			'GLTransaction' => [
				['id' => self::TX_SEP, 'state' => 'posted', 'administrationId' => self::ADMIN, 'periodId' => '2026-09'],
				['id' => self::TX_DRAFT, 'state' => 'draft', 'administrationId' => self::ADMIN, 'periodId' => '2026-09'],
			],
			'GLLine'        => [
				self::line('l1', self::TX_SEP, 1, '8200', 'credit', 40000.0),
				self::line('l2', self::TX_SEP, 2, '4000', 'debit', 25000.0),
				self::line('l3', self::TX_SEP, 3, '4100', 'debit', 3000.0),
				self::line('l4', self::TX_SEP, 4, '1100', 'debit', 40000.0),
				self::line('l5', self::TX_SEP, 5, '1100', 'credit', 68000.0),
				self::line('d1', self::TX_DRAFT, 1, '4000', 'debit', 5000.0),
			],
		];

	}//end records()

	/**
	 * One KP-300 line as saved, its signed amount calculated as the register declares.
	 *
	 * @param string $id            The line id.
	 * @param string $transactionId The transaction.
	 * @param int    $number        The line number.
	 * @param string $account       The account number.
	 * @param string $side          Debit or credit.
	 * @param float  $amount        The amount.
	 *
	 * @return array<string, mixed>
	 */
	public static function line(string $id, string $transactionId, int $number, string $account, string $side, float $amount): array {
		$line = [
			'id' => $id, 'transactionId' => $transactionId, 'lineNumber' => $number, 'accountNumber' => $account,
			'side' => $side, 'amount' => $amount, 'currency' => 'EUR', 'costCenterCode' => 'KP-300',
			'periodId' => '2026-09', 'administrationId' => self::ADMIN,
		];
		$line['signedAmount'] = self::evaluate(
			RegisterSchema::schema('GLLine')['x-openregister-calculations']['signedAmount']['expression'],
			$line
		);

		return $line;

	}//end line()

	/**
	 * Build the service over the seed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(self::records(), $this->saved, true);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return GlLineResultStamps
	 */
	private function stamps(): GlLineResultStamps {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new GlLineResultStamps($this->store, $settings);

	}//end stamps()

	/**
	 * The current lines of the store.
	 *
	 * @return array<string, array<string, mixed>> Lines by id.
	 */
	private function lines(): array {
		$lines = [];
		foreach ($this->store->setSchema('GLLine')->findAll() as $row) {
			$data = $row->getObject();
			$lines[(string)$data['id']] = $data;
		}

		return $lines;

	}//end lines()

	/**
	 * Mirror of CalculationEvaluator for the operators the declaration uses.
	 *
	 * @param mixed                $expr   The expression.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return mixed
	 */
	private static function evaluate(mixed $expr, array $object): mixed {
		if (is_array($expr) === false) {
			return $expr;
		}

		$op = (string)array_key_first($expr);
		$args = $expr[$op];

		return match ($op) {
			'prop' => ($object[$args] ?? null),
			'lit' => $args,
			'eq' => self::evaluate($args[0], $object) === self::evaluate($args[1], $object),
			'-' => self::evaluate($args[0], $object) - self::evaluate($args[1], $object),
			'if' => self::evaluate($args[0], $object) === true ? self::evaluate($args[1], $object) : self::evaluate($args[2], $object),
		};

	}//end evaluate()

	/**
	 * Every operator in an expression tree.
	 *
	 * @param mixed $expr The expression.
	 *
	 * @return array<int, string>
	 */
	private function operators(mixed $expr): array {
		if (is_array($expr) === false || array_is_list($expr) === true) {
			return [];
		}

		$op = (string)array_key_first($expr);
		$found = [$op];
		foreach ((array)$expr[$op] as $arg) {
			$found = array_merge($found, $this->operators($arg));
		}

		return $found;

	}//end operators()

	/**
	 * A declared GLLine aggregation over the store, as OpenRegister's runner reduces it.
	 *
	 * @param string               $name   The aggregation name.
	 * @param array<string, mixed> $narrow The caller's filter[...] narrowing.
	 *
	 * @return array<string, array<string, float>> Values per group key.
	 */
	private function aggregate(string $name, array $narrow): array {
		$spec = RegisterSchema::schema('GLLine')['x-openregister-aggregations'][$name];
		$groupKey = (string)$spec['groupBy'][0];
		$groups = [];
		foreach ($this->lines() as $line) {
			if ($this->lineMatches($line, array_merge($spec['filter'], $narrow)) === false) {
				continue;
			}

			$key = (string)($line[$groupKey] ?? '');
			foreach ($spec['metrics'] as $metric) {
				$groups[$key][$metric['as']] = ($groups[$key][$metric['as']] ?? 0.0);
				if ($this->lineMatches($line, (array)($metric['condition'] ?? [])) === true) {
					$groups[$key][$metric['as']] += (float)$line[$metric['field']];
				}
			}
		}

		return $groups;

	}//end aggregate()

	/**
	 * Whether a line meets an equality filter.
	 *
	 * @param array<string, mixed> $line   The line.
	 * @param array<string, mixed> $filter The filter.
	 *
	 * @return bool
	 */
	private function lineMatches(array $line, array $filter): bool {
		foreach ($filter as $key => $value) {
			if (($line[$key] ?? null) !== $value) {
				return false;
			}
		}

		return true;

	}//end lineMatches()

	/**
	 * REQ-RSR-001: a posted debit line of 3,000 on 4100 is minus 3,000; the expression uses engine operators only.
	 *
	 * @return void
	 */
	public function testACostLineIsNegative(): void {
		$declared = RegisterSchema::schema('GLLine')['x-openregister-calculations']['signedAmount'];

		$this->assertTrue($declared['materialise'], 'a sum can only read a stored value');
		$this->assertSame([], array_diff($this->operators($declared['expression']), self::CALC_OPS));
		$this->assertSame(-3000.0, $this->lines()['l3']['signedAmount']);
		$this->assertSame(40000.0, $this->lines()['l1']['signedAmount']);

	}//end testACostLineIsNegative()

	/**
	 * REQ-RSR-002: posting stamps every line with its account class and as counting; the lines still validate.
	 *
	 * @return void
	 */
	public function testPostingStampsEveryLineWithItsClass(): void {
		$changed = $this->stamps()->stampTransaction(self::TX_SEP);

		$lines = $this->lines();
		$this->assertSame(5, $changed);
		$this->assertSame(['pnl', true], [$lines['l1']['accountClass'], $lines['l1']['countsInResult']]);
		$this->assertSame(['pnl', true], [$lines['l3']['accountClass'], $lines['l3']['countsInResult']]);
		$this->assertSame(['balance', true], [$lines['l4']['accountClass'], $lines['l4']['countsInResult']]);
		foreach (['l1', 'l2', 'l3', 'l4', 'l5'] as $id) {
			$this->assertSame([], RegisterSchema::errors('GLLine', $lines[$id]), $id);
		}

		$this->assertSame(0, $this->stamps()->stampTransaction(self::TX_SEP), 'a second run writes nothing');

	}//end testPostingStampsEveryLineWithItsClass()

	/**
	 * REQ-RSR-002: a draft transaction's lines get no stamp.
	 *
	 * @return void
	 */
	public function testADraftIsNotStamped(): void {
		$this->assertSame(0, $this->stamps()->stampTransaction(self::TX_DRAFT));
		$this->assertFalse($this->stamps()->stampLine($this->lines()['d1']));
		$this->assertArrayNotHasKey('countsInResult', $this->lines()['d1']);

	}//end testADraftIsNotStamped()

	/**
	 * REQ-RSR-002 scenario: the bank line and the draft are left out, KP-300's result is 12,000.
	 *
	 * @return void
	 */
	public function testTheSegmentResultOfKp300IsTwelveThousand(): void {
		$this->stamps()->stampTransaction(self::TX_SEP);
		$this->stamps()->stampTransaction(self::TX_DRAFT);

		foreach (['byCostCenter', 'byCostObject', 'byProject', 'byCostCenterHierarchy', 'byAnalyticalDimension'] as $name) {
			$spec = RegisterSchema::schema('GLLine')['x-openregister-aggregations'][$name];
			$this->assertSame(['accountClass' => 'pnl', 'countsInResult' => true], array_intersect_key($spec['filter'], ['accountClass' => 1, 'countsInResult' => 1]), $name);
			$this->assertSame(['revenue', 'costs', 'result'], array_column($spec['metrics'], 'as'), $name);
		}

		$groups = $this->aggregate('byCostCenter', ['administrationId' => self::ADMIN, 'periodId' => '2026-09']);

		$this->assertSame(['revenue' => 40000.0, 'costs' => 28000.0, 'result' => 12000.0], $groups['KP-300']);

	}//end testTheSegmentResultOfKp300IsTwelveThousand()

	/**
	 * REQ-RSR-002: a reversed transaction and its reversal leave the result together, and so does a reversal of a reversal.
	 *
	 * @return void
	 */
	public function testAReversalTakesBothOutAndSoDoesAReversalOfTheReversal(): void {
		$this->stamps()->stampTransaction(self::TX_SEP);
		$this->store->saveObject(object: ['id' => self::TX_REVERSAL, 'state' => 'posted', 'administrationId' => self::ADMIN, 'reversesTransactionId' => self::TX_SEP], schema: 'GLTransaction');
		$this->store->saveObject(object: self::line('r1', self::TX_REVERSAL, 1, '4100', 'credit', 3000.0), schema: 'GLLine');
		$this->assertTrue($this->stamps()->stampLine($this->lines()['r1']));
		$this->assertTrue($this->lines()['r1']['countsInResult'], 'the reversal counts until the original is reversed');

		$this->store->saveObject(object: ['id' => self::TX_SEP, 'state' => 'reversed', 'administrationId' => self::ADMIN, 'periodId' => '2026-09'], schema: 'GLTransaction');
		$this->stamps()->stampReversal(self::TX_SEP);
		$this->assertFalse($this->lines()['l3']['countsInResult']);
		$this->assertFalse($this->lines()['r1']['countsInResult']);

		$this->store->saveObject(object: ['id' => self::TX_REVERSAL, 'state' => 'reversed', 'administrationId' => self::ADMIN, 'reversesTransactionId' => self::TX_SEP], schema: 'GLTransaction');
		$this->store->saveObject(object: ['id' => self::TX_REREVERSAL, 'state' => 'posted', 'administrationId' => self::ADMIN, 'reversesTransactionId' => self::TX_REVERSAL], schema: 'GLTransaction');
		$this->store->saveObject(object: self::line('rr1', self::TX_REREVERSAL, 1, '4100', 'debit', 3000.0), schema: 'GLLine');
		$this->stamps()->stampLine($this->lines()['rr1']);
		$this->stamps()->stampReversal(self::TX_REVERSAL);

		$lines = $this->lines();
		$this->assertSame([false, false, false], [$lines['l3']['countsInResult'], $lines['r1']['countsInResult'], $lines['rr1']['countsInResult']]);
		$groups = $this->aggregate('byCostCenter', ['administrationId' => self::ADMIN]);
		$this->assertSame([], $groups, 'nothing of the chain counts');

	}//end testAReversalTakesBothOutAndSoDoesAReversalOfTheReversal()

	/**
	 * An account type outside revenue and expenses is a balance sheet account.
	 *
	 * @return void
	 */
	public function testAccountClass(): void {
		$this->assertSame('pnl', $this->stamps()->accountClass('expenses'));
		$this->assertSame('balance', $this->stamps()->accountClass('liabilities'));
		$this->assertSame('balance', $this->stamps()->accountClass(''));

	}//end testAccountClass()
}//end class
