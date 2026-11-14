<?php

/**
 * Unit tests for PeriodChecklistService.
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
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md#requirement-a-person-resolves-reopens-and-adds-checklist-items-req-lpe-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PeriodCloseServiceInterface;
use OCA\Shillinq\Kernel\Exception\PeriodCloseException;
use OCA\Shillinq\Service\PeriodChecklistService;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\PeriodChecklistService
 * @uses   \OCA\Shillinq\Kernel\Exception\PeriodCloseException
 */
final class PeriodChecklistServiceTest extends TestCase {

	/**
	 * The period the reader returns.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $period;

	/**
	 * The objects passed to saveObject, with register and schema.
	 *
	 * @var list<array{0: array<string,mixed>, 1: mixed, 2: mixed}>
	 */
	private array $saved = [];

	/**
	 * Whether the user is in the period-closer group.
	 *
	 * @var bool
	 */
	private bool $closer = true;

	/**
	 * The period reader.
	 *
	 * @var PeriodCloseServiceInterface&MockObject
	 */
	private PeriodCloseServiceInterface&MockObject $periods;

	/**
	 * A closing period with one open bank item and one resolved AP item.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->period = [
			'id' => 'uuid-sep',
			'periodId' => '2026-09',
			'administrationId' => 'ADM-001',
			'state' => 'closing',
			'taskChecklistItems' => [
				['id' => 'bank', 'category' => 'bank', 'description' => 'Bankmutaties volledig verwerkt', 'resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null],
				['id' => 'ap', 'category' => 'ap', 'description' => 'Inkoopfacturen geboekt', 'resolved' => true, 'resolvedAt' => '2026-10-01T09:00:00+00:00', 'resolvedBy' => 'bob'],
			],
		];
		$this->saved  = [];
		$this->closer = true;
		$this->periods = $this->createMock(PeriodCloseServiceInterface::class);
		$this->periods->method('getPeriodForClose')->willReturnCallback(fn (): ?array => $this->period);

	}//end setUp()

	/**
	 * The service over the stubs.
	 *
	 * @return PeriodChecklistService
	 */
	private function service(): PeriodChecklistService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) {
				$this->saved[] = [$object, $register, $schema];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $group): bool => $this->closer && $uid === 'alice' && $group === 'period-closer'
		);

		return new PeriodChecklistService(
			$this->periods,
			$objects,
			$this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			$groups
		);

	}//end service()

	/**
	 * Run a call and return the refusal's status.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (PeriodCloseException $e) {
			return $e->getStatus();
		}

		$this->fail('Expected a PeriodCloseException');

	}//end refusal()

	public function testResolvingRecordsTheUserAndTheTimeAndStoresThePeriod(): void {
		$before = time();
		$result = $this->service()->resolveItem('2026-09', 'ADM-001', 'bank', 'alice');

		$bank = $result['taskChecklistItems'][0];
		$this->assertTrue($bank['resolved']);
		$this->assertSame('alice', $bank['resolvedBy']);
		$this->assertGreaterThanOrEqual($before, strtotime($bank['resolvedAt']));
		$this->assertSame('bank', $bank['id']);
		$this->assertSame('Bankmutaties volledig verwerkt', $bank['description']);
		$this->assertSame($this->period['taskChecklistItems'][1], $result['taskChecklistItems'][1], 'the other item is untouched');

		$this->assertCount(1, $this->saved);
		[$object, $register, $schema] = $this->saved[0];
		$this->assertSame($result, $object);
		$this->assertSame('shillinq', $register);
		$this->assertSame('FiscalPeriod', $schema);
		$this->assertSame('uuid-sep', $object['id'], 'the stored record is the existing period');

	}//end testResolvingRecordsTheUserAndTheTimeAndStoresThePeriod()

	public function testReopeningClearsWhoAndWhen(): void {
		$result = $this->service()->reopenItem('2026-09', 'ADM-001', 'ap', 'alice');

		$this->assertSame(
			['id' => 'ap', 'category' => 'ap', 'description' => 'Inkoopfacturen geboekt', 'resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null],
			$result['taskChecklistItems'][1]
		);

	}//end testReopeningClearsWhoAndWhen()

	public function testAnAddedItemIsOpenWithAFreshId(): void {
		$this->period['taskChecklistItems'][] = ['id' => 'item-3', 'category' => 'other', 'description' => 'Oud', 'resolved' => false];

		$result = $this->service()->addItem('2026-09', 'ADM-001', 'other', '  loonjournaal geboekt ', 'alice');

		$this->assertCount(4, $result['taskChecklistItems']);
		$this->assertSame(
			['id' => 'item-4', 'category' => 'other', 'description' => 'loonjournaal geboekt', 'resolved' => false, 'resolvedAt' => null, 'resolvedBy' => null],
			$result['taskChecklistItems'][3]
		);

	}//end testAnAddedItemIsOpenWithAFreshId()

	public function testAnAddedIdSkipsOneAlreadyTaken(): void {
		$this->period['taskChecklistItems'] = [['id' => 'item-1', 'category' => 'bank', 'description' => 'x']];
		$this->period['taskChecklistItems'][] = ['id' => 'item-2', 'category' => 'bank', 'description' => 'y'];
		$this->period['taskChecklistItems'][0]['id'] = 'item-3';

		$result = $this->service()->addItem('2026-09', 'ADM-001', 'vat', 'BTW', 'alice');

		$this->assertSame(['item-3', 'item-2', 'item-4'], array_column($result['taskChecklistItems'], 'id'));

	}//end testAnAddedIdSkipsOneAlreadyTaken()

	public function testAPeriodWithoutAListGetsOne(): void {
		unset($this->period['taskChecklistItems']);

		$result = $this->service()->addItem('2026-09', 'ADM-001', 'bank', 'Bank', 'alice');

		$this->assertSame(['item-1'], array_column($result['taskChecklistItems'], 'id'));

	}//end testAPeriodWithoutAListGetsOne()

	public function testAnInvalidItemIsRefusedWithoutStoring(): void {
		foreach ([['bank', '   '], ['bank', str_repeat('x', 501)], ['payroll', 'Loon'], ['', 'Loon']] as [$category, $description]) {
			$this->assertSame(
				PeriodCloseServiceInterface::ERR_VALIDATION,
				$this->refusal(fn () => $this->service()->addItem('2026-09', 'ADM-001', $category, $description, 'alice'))
			);
		}

		$this->assertSame([], $this->saved);

	}//end testAnInvalidItemIsRefusedWithoutStoring()

	public function testItemsOfAClosedOrLockedPeriodCannotChange(): void {
		foreach (['closed', 'audit-locked'] as $state) {
			$this->period['state'] = $state;
			$this->assertSame('invalid-state', $this->refusal(fn () => $this->service()->resolveItem('2026-09', 'ADM-001', 'bank', 'alice')));
			$this->assertSame('invalid-state', $this->refusal(fn () => $this->service()->reopenItem('2026-09', 'ADM-001', 'ap', 'alice')));
			$this->assertSame('invalid-state', $this->refusal(fn () => $this->service()->addItem('2026-09', 'ADM-001', 'bank', 'Bank', 'alice')));
		}

		$this->assertSame([], $this->saved);

	}//end testItemsOfAClosedOrLockedPeriodCannotChange()

	public function testAnOpenPeriodsItemsCanChange(): void {
		$this->period['state'] = 'open';

		$this->assertTrue($this->service()->resolveItem('2026-09', 'ADM-001', 'bank', 'alice')['taskChecklistItems'][0]['resolved']);

	}//end testAnOpenPeriodsItemsCanChange()

	public function testAUserWithoutThePeriodCloserRoleIsRefusedBeforeThePeriodIsRead(): void {
		$this->closer = false;
		$this->periods->expects($this->never())->method('getPeriodForClose');

		$this->assertSame(PeriodCloseServiceInterface::ERR_FORBIDDEN, $this->refusal(fn () => $this->service()->resolveItem('2026-09', 'ADM-001', 'bank', 'alice')));
		$this->assertSame(PeriodCloseServiceInterface::ERR_FORBIDDEN, $this->refusal(fn () => $this->service()->addItem('2026-09', 'ADM-001', 'bank', 'Bank', 'alice')));
		$this->assertSame(PeriodCloseServiceInterface::ERR_FORBIDDEN, $this->refusal(fn () => $this->service()->reopenItem('2026-09', 'ADM-001', 'ap', '')));
		$this->assertSame([], $this->saved);

	}//end testAUserWithoutThePeriodCloserRoleIsRefusedBeforeThePeriodIsRead()

	public function testAnUnknownPeriodOrItemIsNotFound(): void {
		$this->assertSame(PeriodCloseServiceInterface::ERR_NOT_FOUND, $this->refusal(fn () => $this->service()->resolveItem('2026-09', 'ADM-001', 'nope', 'alice')));

		$this->period = null;
		$this->assertSame(PeriodCloseServiceInterface::ERR_NOT_FOUND, $this->refusal(fn () => $this->service()->resolveItem('2026-09', 'ADM-001', 'bank', 'alice')));
		$this->assertSame([], $this->saved);

	}//end testAnUnknownPeriodOrItemIsNotFound()
}//end class
