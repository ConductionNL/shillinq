<?php

/**
 * Unit tests for DeferralService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Deferral
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-the-nightly-close-releases-each-ended-period-req-lpe-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Deferral;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Kernel\Exception\DeferralRefusedException;
use OCA\Shillinq\Service\Deferral\DeferralService;
use OCA\Shillinq\Service\RegisterLocation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\Deferral\DeferralService
 * @uses   \OCA\Shillinq\Service\Deferral\DeferralPlan
 * @uses   \OCA\Shillinq\Kernel\Exception\DeferralRefusedException
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class DeferralServiceTest extends TestCase {

	/**
	 * Stored rows per schema, keyed by id.
	 *
	 * @var array<string, array<string, array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * The transitions run, as [objectId, action].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $ran = [];

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
	 * The licence request of the spec's scenario.
	 *
	 * @var array<string,mixed>
	 */
	private const LICENCE = [
		'administrationId' => 'adm-consultancy-nl',
		'sourceReference' => 'ap-invoice:INK-2026-0007#line-1',
		'description' => 'Jaarlicentie software',
		'kind' => 'prepaid-cost',
		'resultAccount' => '4300',
		'balanceAccount' => '1900',
		'totalCents' => 120000,
		'periodFrom' => '2026-01-01',
		'periodTo' => '2026-12-31',
	];

	/**
	 * The service over an in-memory store.
	 *
	 * @return DeferralService
	 */
	private function service(): DeferralService {
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
			function (array $object): ObjectEntityInterface {
				$object['id'] = strtolower($this->schema) . '-' . (++$this->next);
				$this->rows[$this->schema][$object['id']] = $object;
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);
		$store->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data, $register = null, $schema = null): ObjectEntityInterface {
				$this->assertSame('shillinq', $register);
				$this->assertArrayHasKey($objectId, $this->rows[(string)$schema]);
				$this->rows[(string)$schema][$objectId] = array_merge($this->rows[(string)$schema][$objectId], $data);
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$transitions = $this->createMock(ObjectTransitionRunnerInterface::class);
		$transitions->method('run')->willReturnCallback(
			function (string $objectId, string $action): void {
				$this->ran[] = [$objectId, $action];
				$this->rows['JournalEntry'][$objectId]['state'] = 'posted';
			}
		);

		return new DeferralService($store, $transitions, $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']));

	}//end service()

	/**
	 * The stored journal entries.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function journals(): array {
		return array_values($this->rows['JournalEntry'] ?? []);

	}//end journals()

	/**
	 * The debit and credit account and amount of an entry.
	 *
	 * @param array<string,mixed> $journal The entry.
	 *
	 * @return array{0: string, 1: string, 2: float}
	 */
	private static function movement(array $journal): array {
		$lines = array_column($journal['lines'], null, 'side');
		return [$lines['debit']['accountNumber'], $lines['credit']['accountNumber'], $lines['debit']['amount']];

	}//end movement()

	/**
	 * The refusal a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return DeferralRefusedException
	 */
	private function refusal(callable $call): DeferralRefusedException {
		try {
			$call();
		} catch (DeferralRefusedException $e) {
			return $e;
		}

		$this->fail('Expected a DeferralRefusedException');

	}//end refusal()

	public function testSpreadingTheLicenceStoresTwelveReleasesAndPostsTheOpeningEntry(): void {
		$schedule = $this->service()->spread(self::LICENCE);

		$this->assertSame('deferralschedule-1', $schedule['id']);
		$this->assertSame(array_fill(0, 12, 10000), array_column($schedule['releases'], 'amountCents'));
		$this->assertSame('active', $schedule['state']);

		$journals = $this->journals();
		$this->assertCount(1, $journals);
		$this->assertSame(['1900', '4300', 1200.0], self::movement($journals[0]), 'EUR 1,200 moved from 4300 to 1900');
		$this->assertSame('2026-01-01', $journals[0]['entryDate']);
		$this->assertSame('adm-consultancy-nl', $journals[0]['administrationId']);
		$this->assertSame(['manual', 'not-required', 'posted'], [$journals[0]['journalType'], $journals[0]['approvalState'], $journals[0]['state']]);
		$this->assertSame('Jaarlicentie software (spread)', $journals[0]['description']);
		$this->assertSame([[$journals[0]['id'], 'postDirect']], $this->ran);

		$stored = $this->rows['DeferralSchedule']['deferralschedule-1'];
		$this->assertSame($journals[0]['id'], $stored['openingJournalEntryId']);
		$this->assertSame($journals[0]['id'], $schedule['openingJournalEntryId']);
		$this->assertCount(12, $stored['releases']);

	}//end testSpreadingTheLicenceStoresTwelveReleasesAndPostsTheOpeningEntry()

	public function testDeferredRevenueMovesTheOtherWay(): void {
		$schedule = $this->service()->spread(['kind' => 'deferred-revenue', 'resultAccount' => '8000', 'balanceAccount' => '1600', 'totalCents' => '60000'] + self::LICENCE);

		$this->assertSame(['8000', '1600', 600.0], self::movement($this->journals()[0]));
		$this->assertSame(5000, $schedule['releases'][0]['amountCents']);

	}//end testDeferredRevenueMovesTheOtherWay()

	public function testPreviewWritesNothing(): void {
		$preview = $this->service()->preview(self::LICENCE);

		$this->assertCount(12, $preview['releases']);
		$this->assertNull($preview['openingJournalEntryId']);
		$this->assertSame([], $this->rows);
		$this->assertSame([], $this->ran);

	}//end testPreviewWritesNothing()

	public function testALineAlreadySpreadIsAConflictUnlessItsScheduleWasCancelled(): void {
		$service = $this->service();
		$service->spread(self::LICENCE);

		$this->assertSame(DeferralRefusedException::CONFLICT, $this->refusal(fn () => $service->spread(self::LICENCE))->getStatus());
		$this->assertCount(1, $this->journals());

		$this->rows['DeferralSchedule']['deferralschedule-1']['state'] = 'cancelled';
		$this->assertSame('active', $service->spread(self::LICENCE)['state']);

	}//end testALineAlreadySpreadIsAConflictUnlessItsScheduleWasCancelled()

	public function testUnusableRequestsAreRefusedWithoutWriting(): void {
		$cases = [
			['kind' => 'accrual'],
			['balanceAccount' => '4300'],
			['totalCents' => 0],
			['totalCents' => '12.50'],
			['totalCents' => 1.5],
			['periodTo' => '2026-01-31'],
			['periodTo' => '2025-12-31'],
			['periodFrom' => 'januari'],
			['sourceReference' => '  '],
			['administrationId' => ['x']],
			['description' => str_repeat('x', 256)],
		];
		foreach ($cases as $case) {
			$e = $this->refusal(fn () => $this->service()->spread($case + self::LICENCE));
			$this->assertSame(DeferralRefusedException::INVALID, $e->getStatus(), json_encode($case));
		}

		$this->assertSame([], $this->rows);

	}//end testUnusableRequestsAreRefusedWithoutWriting()

	public function testJanuarysShareIsReleasedAfterJanuaryEndsAndASecondRunWritesNothing(): void {
		$service = $this->service();
		$service->spread(self::LICENCE);
		$this->ran = [];

		$this->assertSame(0, $service->releaseEnded('adm-consultancy-nl', new DateTimeImmutable('2026-01-31 23:00')), 'January has not ended yet');
		$this->assertSame(1, $service->releaseEnded('adm-consultancy-nl', new DateTimeImmutable('2026-02-01 02:00')));
		$this->assertSame(0, $service->releaseEnded('adm-consultancy-nl', new DateTimeImmutable('2026-02-01 03:00')), 'a rerun the same night');

		$journals = $this->journals();
		$this->assertCount(2, $journals);
		$this->assertSame(['4300', '1900', 100.0], self::movement($journals[1]), 'EUR 100 moved from 1900 to 4300');
		$this->assertSame('2026-01-31', $journals[1]['entryDate'], 'in period 2026-01');
		$this->assertSame([[$journals[1]['id'], 'postDirect']], $this->ran);

		$stored = $this->rows['DeferralSchedule']['deferralschedule-1'];
		$this->assertSame($journals[1]['id'], $stored['releases'][0]['journalEntryId']);
		$this->assertSame('2026-02-01T02:00:00+00:00', (new DateTimeImmutable($stored['releases'][0]['releasedAt']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
		$this->assertNull($stored['releases'][1]['journalEntryId']);
		$this->assertSame('active', $stored['state']);

	}//end testJanuarysShareIsReleasedAfterJanuaryEndsAndASecondRunWritesNothing()

	public function testTheLastReleaseCompletesTheScheduleAndTheReleasesAddUpToTheTotal(): void {
		$service = $this->service();
		$service->spread(['totalCents' => 100, 'periodTo' => '2026-03-31'] + self::LICENCE);

		$this->assertSame(3, $service->releaseEnded('', new DateTimeImmutable('2026-06-01')), 'missed periods are caught up');
		$stored = $this->rows['DeferralSchedule']['deferralschedule-1'];
		$this->assertSame('completed', $stored['state']);
		$this->assertSame([33, 33, 34], array_column($stored['releases'], 'amountCents'));

		$released = array_sum(array_map(static fn (array $j): float => self::movement($j)[2], array_slice($this->journals(), 1)));
		$this->assertSame(1.0, round($released, 2));

		$this->assertSame(0, $service->releaseEnded('', new DateTimeImmutable('2026-07-01')), 'a completed schedule is left alone');

	}//end testTheLastReleaseCompletesTheScheduleAndTheReleasesAddUpToTheTotal()

	public function testOnlyActiveSchedulesOfTheAdministrationAreReleased(): void {
		$service = $this->service();
		$service->spread(self::LICENCE);
		$service->spread(['sourceReference' => 'ap-invoice:X#1', 'administrationId' => 'adm-other'] + self::LICENCE);
		$service->spread(['sourceReference' => 'ap-invoice:Y#1'] + self::LICENCE);
		$this->rows['DeferralSchedule']['deferralschedule-5']['state'] = 'cancelled';

		$this->assertSame(1, $service->releaseEnded('adm-consultancy-nl', new DateTimeImmutable('2026-02-15')));
		$this->assertNull($this->rows['DeferralSchedule']['deferralschedule-3']['releases'][0]['journalEntryId']);
		$this->assertNull($this->rows['DeferralSchedule']['deferralschedule-5']['releases'][0]['journalEntryId']);

	}//end testOnlyActiveSchedulesOfTheAdministrationAreReleased()
}//end class
