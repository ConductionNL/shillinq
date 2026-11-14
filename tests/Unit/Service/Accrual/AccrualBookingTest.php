<?php

/**
 * Unit tests for AccrualBooking and its use by the nightly soft close.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Accrual
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-a-computed-accrual-is-booked-and-reversed-req-lpe-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Accrual;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\Accrual\AccrualBooking;
use OCA\Shillinq\Service\Deferral\DeferralService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\SoftCloseExecutor;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Shillinq\Service\Accrual\AccrualBooking
 * @covers \OCA\Shillinq\Service\SoftCloseExecutor
 * @uses   \OCA\Shillinq\Service\SoftCloseRun
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 * @uses   \OCA\Shillinq\Service\Deferral\DeferralService
 * @uses   \OCA\Shillinq\Service\Deferral\DeferralPlan
 */
final class AccrualBookingTest extends TestCase {

	/**
	 * Stored rows per schema, keyed by id.
	 *
	 * @var array<string, array<string, array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * The schema the store is scoped to.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Ids handed out.
	 *
	 * @var int
	 */
	private int $next = 0;

	/**
	 * The journal entries postDirect ran on.
	 *
	 * @var list<string>
	 */
	private array $posted = [];

	/**
	 * The Energie rule of design.md.
	 *
	 * @var array<string,mixed>
	 */
	private const ENERGIE = [
		'id' => 'rule-energie',
		'ruleName' => 'Energie',
		'targetGLAccount' => '4010',
		'contraGLAccount' => '1920',
		'calculationMethod' => 'fixed-amount',
		'calculationParameters' => ['amountCents' => 35000],
		'reversalPattern' => 'first-of-next-month',
		'frequency' => 'monthly',
		'administrationId' => 'adm-consultancy-nl',
		'lifecycleState' => 'active',
		'ruleVersion' => 1,
	];

	/**
	 * Seed the rule.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows   = ['AutoAccrualRule' => ['rule-energie' => self::ENERGIE]];
		$this->posted = [];
		$this->next   = 0;

	}//end setUp()

	/**
	 * An in-memory object service.
	 *
	 * @return ObjectServiceInterface
	 */
	private function store(): ObjectServiceInterface {
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnSelf();
		$store->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($store) {
				$this->schema = (string)$schema;
				return $store;
			}
		);
		$store->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = (array)($config['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($row[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null): ObjectEntityInterface {
				$slug         = (string)($schema ?? $this->schema);
				$object['id'] = ($object['id'] ?? (strtolower($slug) . '-' . (++$this->next)));
				$this->rows[$slug][$object['id']] = $object;
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);
		$store->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, $register = null, $schema = null): ObjectEntityInterface {
				$this->rows[(string)$schema][$objectId] = array_merge($this->rows[(string)$schema][$objectId], $data);
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		return $store;

	}//end store()

	/**
	 * The transition runner, recording postDirect.
	 *
	 * @return ObjectTransitionRunnerInterface
	 */
	private function transitions(): ObjectTransitionRunnerInterface {
		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->method('run')->willReturnCallback(
			function (string $objectId, string $action): void {
				$this->assertSame('postDirect', $action);
				$this->posted[] = $objectId;
				$this->rows['JournalEntry'][$objectId]['state'] = 'posted';
			}
		);

		return $transitions;

	}//end transitions()

	/**
	 * The nightly executor with booking and deferrals wired.
	 *
	 * @return SoftCloseExecutor
	 */
	private function executor(): SoftCloseExecutor {
		$store    = $this->store();
		$location = $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']);
		$config   = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(false);

		return new SoftCloseExecutor(
			$container,
			$config,
			new NullLogger(),
			$store,
			new AccrualBooking($store, $this->transitions(), $location),
			new DeferralService($store, $this->transitions(), $location)
		);

	}//end executor()

	/**
	 * The posted journal entries, in order.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function journals(): array {
		return array_values($this->rows['JournalEntry'] ?? []);

	}//end journals()

	/**
	 * Debit account, credit account and amount of an entry.
	 *
	 * @param array<string,mixed> $journal The entry.
	 *
	 * @return array{0: string, 1: string, 2: float}
	 */
	private static function movement(array $journal): array {
		$lines = array_column($journal['lines'], null, 'side');
		return [$lines['debit']['accountNumber'], $lines['credit']['accountNumber'], $lines['debit']['amount']];

	}//end movement()

	public function testTheEnergyAccrualReachesTheLedgerOncePerPeriodAndOctobersFirstRunReversesIt(): void {
		$executor = $this->executor();

		$first = $executor->execute('adm-consultancy-nl', '2026-09', new DateTimeImmutable('2026-09-30T02:00:00+00:00'));
		$executor->execute('adm-consultancy-nl', '2026-09', new DateTimeImmutable('2026-09-30T03:00:00+00:00'));

		$this->assertSame('completed', $first['status']);
		$this->assertSame(1, $first['accrualPostings']);
		$journals = $this->journals();
		$this->assertCount(1, $journals, 'one entry per rule and period across two runs');
		$this->assertSame(['4010', '1920', 350.0], self::movement($journals[0]));
		$this->assertSame('2026-09-30', $journals[0]['entryDate']);
		$this->assertSame('je-2026-09-30-energie', $journals[0]['journalNumber']);
		$this->assertSame('adm-consultancy-nl', $journals[0]['administrationId']);
		$this->assertSame('posted', $journals[0]['state']);
		$this->assertSame([$journals[0]['id']], $this->posted);

		$postings = array_values($this->rows['AutoAccrualPosting']);
		$this->assertCount(1, $postings);
		$this->assertSame(['rule-energie', 1, '2026-09', 35000, 'je-2026-09-30-energie', 'posted', null], [$postings[0]['ruleId'], $postings[0]['ruleVersion'], $postings[0]['periodId'], $postings[0]['amountCents'], $postings[0]['journalEntryId'], $postings[0]['reversalState'], $postings[0]['reversalId']]);

		$october = $executor->execute('adm-consultancy-nl', '2026-10', new DateTimeImmutable('2026-10-01T02:00:00+00:00'));
		$this->assertSame(2, $october['accrualPostings'], "September's reversal and October's accrual");

		$journals = $this->journals();
		$this->assertCount(3, $journals);
		$this->assertSame(['1920', '4010', 350.0], self::movement($journals[1]), 'the mirror entry');
		$this->assertSame('2026-10-01', $journals[1]['entryDate']);
		$this->assertSame('je-2026-09-30-energie-rev', $journals[1]['journalNumber']);
		$this->assertSame(['4010', '1920', 350.0], self::movement($journals[2]), "October's accrual");

		$september = $this->rows['AutoAccrualPosting'][$postings[0]['id']];
		$this->assertSame('reversed', $september['reversalState']);
		$this->assertSame('je-2026-09-30-energie-rev', $september['reversalId']);

		$executor->execute('adm-consultancy-nl', '2026-10', new DateTimeImmutable('2026-10-02T02:00:00+00:00'));
		$this->assertCount(3, $this->journals(), 'nothing is reversed or booked twice');

	}//end testTheEnergyAccrualReachesTheLedgerOncePerPeriodAndOctobersFirstRunReversesIt()

	public function testANewRuleVersionBooksAgainAndARuleWithoutNextPeriodReversalIsNotReversed(): void {
		$this->rows['AutoAccrualRule']['rule-energie']['reversalPattern'] = 'on-receipt-of-invoice';
		$executor = $this->executor();
		$executor->execute('adm-consultancy-nl', '2026-09', new DateTimeImmutable('2026-09-10T02:00:00+00:00'));

		$this->rows['AutoAccrualRule']['rule-energie']['ruleVersion'] = 2;
		$executor->execute('adm-consultancy-nl', '2026-09', new DateTimeImmutable('2026-09-11T02:00:00+00:00'));
		$this->assertCount(2, $this->journals());

		$executor->execute('adm-consultancy-nl', '2026-10', new DateTimeImmutable('2026-10-01T02:00:00+00:00'));
		$this->assertCount(3, $this->journals(), 'only October is booked');
		foreach ($this->rows['AutoAccrualPosting'] as $posting) {
			$this->assertSame('posted', $posting['reversalState']);
		}

	}//end testANewRuleVersionBooksAgainAndARuleWithoutNextPeriodReversalIsNotReversed()

	public function testAPastPeriodsAccrualIsDatedOnItsLastDay(): void {
		$booking = new AccrualBooking($this->store(), $this->transitions(), $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']));

		$this->assertTrue($booking->book(self::ENERGIE, '2026-08', 35000, new DateTimeImmutable('2026-09-02'), 'je-x'));
		$this->assertFalse($booking->book(self::ENERGIE, '2026-08', 35000, new DateTimeImmutable('2026-09-03'), 'je-y'));
		$this->assertFalse($booking->book(self::ENERGIE, '2026-09', 0, new DateTimeImmutable('2026-09-03'), 'je-z'));

		$this->assertSame(['2026-08-31'], array_column($this->journals(), 'entryDate'));

	}//end testAPastPeriodsAccrualIsDatedOnItsLastDay()

	public function testTheNightlyRunReleasesEndedDeferralsAndCountsThem(): void {
		$this->rows['AutoAccrualRule'] = [];
		$executor = $this->executor();
		$this->rows['DeferralSchedule']['s-1'] = [
			'id' => 's-1',
			'administrationId' => 'adm-consultancy-nl',
			'sourceReference' => 'ap-invoice:INK-1#line-1',
			'kind' => 'prepaid-cost',
			'resultAccount' => '4300',
			'balanceAccount' => '1900',
			'totalCents' => 120000,
			'state' => 'active',
			'releases' => [
				['periodId' => '2026-01', 'periodEnd' => '2026-01-31', 'amountCents' => 10000, 'journalEntryId' => null],
				['periodId' => '2026-02', 'periodEnd' => '2026-02-28', 'amountCents' => 10000, 'journalEntryId' => null],
			],
		];

		$report = $executor->execute('adm-consultancy-nl', '2026-02', new DateTimeImmutable('2026-02-01T02:00:00+00:00'));

		$this->assertSame(1, $report['deferralReleases']);
		$this->assertSame(1, $report['postingCount']);
		$this->assertSame(['4300', '1900', 100.0], self::movement($this->journals()[0]));
		$this->assertSame(0, $executor->execute('adm-consultancy-nl', '2026-02', new DateTimeImmutable('2026-02-01T03:00:00+00:00'))['deferralReleases']);

	}//end testTheNightlyRunReleasesEndedDeferralsAndCountsThem()
}//end class
