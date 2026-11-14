<?php

/**
 * LedgerSealService unit tests (ledger-sealed-entries REQ-LSE-001..003).
 *
 * OpenRegister is a boundary: an in-memory store stands behind its object
 * service, so sealing, checking and the genesis pass run their real logic
 * over Gemeente Voorbeeld's three September postings (design, Seed Data).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Ledger;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Ledger\LedgerSealCanonicaliser;
use OCA\Shillinq\Service\Ledger\LedgerSealService;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Seal, check and genesis-seal an administration's posted transactions.
 */
#[CoversClass(LedgerSealService::class)]
#[UsesClass(LedgerSealCanonicaliser::class)]
final class LedgerSealServiceTest extends TestCase {
	/**
	 * Rows per schema, by id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Patches written, in order.
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $patches = [];

	/**
	 * Locks acquired and released, in order.
	 *
	 * @var list<string>
	 */
	private array $lockLog = [];

	private ILockingProvider $locks;

	protected function setUp(): void {
		parent::setUp();
		$this->store = ['GLTransaction' => [], 'GLLine' => []];
		$this->transaction(id: 't1', number: '2026-0901', date: '2026-09-05', lines: [['4300', 'debit', '1200.00'], ['1100', 'credit', '1200.00']]);
		$this->transaction(id: 't2', number: '2026-0902', date: '2026-09-12', lines: [['4100', 'debit', '300.00'], ['1520', 'debit', '63.00'], ['1600', 'credit', '363.00']]);
		$this->transaction(id: 't3', number: '2026-0903', date: '2026-09-20', lines: [['1600', 'debit', '363.00'], ['4100', 'credit', '300.00'], ['1520', 'credit', '63.00']], reverses: 't2');
		$this->transaction(id: 'other', number: 'X-1', date: '2026-09-01', lines: [], administration: 'adm-elders');

		$this->locks = $this->createMock(ILockingProvider::class);
		$this->locks->method('acquireLock')->willReturnCallback(function (string $path): void {
			$this->lockLog[] = 'acquire '.$path;
		});
		$this->locks->method('releaseLock')->willReturnCallback(function (string $path): void {
			$this->lockLog[] = 'release '.$path;
		});
	}//end setUp()

	/**
	 * Put a posted transaction and its lines in the store.
	 *
	 * @param string                       $id             Id.
	 * @param string                       $number         Transaction number.
	 * @param string                       $date           Posting date.
	 * @param list<array{0:string,1:string,2:string}> $lines Account, side, amount.
	 * @param string|null                  $reverses       Reversed transaction id.
	 * @param string                       $administration Administration id.
	 *
	 * @return void
	 */
	private function transaction(string $id, string $number, string $date, array $lines, ?string $reverses = null, string $administration = 'adm-voorbeeld'): void {
		$this->store['GLTransaction'][$id] = [
			'id' => $id,
			'administrationId' => $administration,
			'transactionNumber' => $number,
			'postingDate' => $date,
			'periodId' => '2026-09',
			'currency' => 'EUR',
			'description' => 'Boeking '.$number,
			'sourceReference' => null,
			'journalEntryId' => null,
			'reversesTransactionId' => $reverses,
			'state' => 'posted',
		];
		foreach ($lines as $index => [$account, $side, $amount]) {
			$this->store['GLLine'][$id.'-'.$index] = ['id' => $id.'-'.$index, 'transactionId' => $id, 'lineNumber' => ($index + 1), 'accountNumber' => $account, 'side' => $side, 'amount' => $amount, 'currency' => 'EUR'];
		}
	}//end transaction()

	/**
	 * The service over the in-memory store.
	 *
	 * @param ILockingProvider|null $locks Locks; the recording double by default.
	 *
	 * @return LedgerSealService
	 */
	private function service(?ILockingProvider $locks = null): LedgerSealService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(function (string $name) use (&$schema, $objects) {
			$schema = $name;
			return $objects;
		});
		$objects->method('findAll')->willReturnCallback(function (array $config) use (&$schema): array {
			$rows = array_values(array_filter(
				$this->store[$schema] ?? [],
				static fn (array $row): bool => array_intersect_assoc($config['filters'] ?? [], $row) === ($config['filters'] ?? [])
			));
			return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 1000));
		});
		$objects->method('patchObject')->willReturnCallback(function (string $objectId, array $data, $register = null, $schemaName = null, bool $_rbac = true, bool $_multitenancy = true) {
			self::assertSame('GLTransaction', $schemaName);
			self::assertFalse($_rbac);
			$this->patches[] = [$objectId, $data];
			$this->store['GLTransaction'][$objectId] = array_merge($this->store['GLTransaction'][$objectId], $data);
			return $this->createStub(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
		});

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new LedgerSealService($objects, $config, ($locks ?? $this->locks), new LedgerSealCanonicaliser(), $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Seal the three transactions in posting order, as three posts would.
	 *
	 * @param LedgerSealService $service The service.
	 *
	 * @return void
	 */
	private function postAll(LedgerSealService $service): void {
		foreach (['t1', 't2', 't3'] as $id) {
			$service->seal(transaction: $this->store['GLTransaction'][$id]);
		}
	}//end postAll()

	public function testEachPostChainsOntoThePreviousSealUnderTheAdministrationsLock(): void {
		$service = $this->service();
		$this->postAll(service: $service);

		$rows = $this->store['GLTransaction'];
		self::assertSame([1, 2, 3], [$rows['t1']['sealSequence'], $rows['t2']['sealSequence'], $rows['t3']['sealSequence']]);
		self::assertSame(LedgerSealCanonicaliser::GENESIS, $rows['t1']['previousSealHash']);
		self::assertSame($rows['t1']['sealHash'], $rows['t2']['previousSealHash']);
		self::assertSame($rows['t2']['sealHash'], $rows['t3']['previousSealHash']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rows['t3']['sealHash']);
		self::assertArrayNotHasKey('sealSequence', $rows['other']);
		self::assertSame(
			['acquire shillinq-gl-seal-adm-voorbeeld', 'release shillinq-gl-seal-adm-voorbeeld'],
			array_slice($this->lockLog, 0, 2)
		);
		self::assertCount(6, $this->lockLog);
	}//end testEachPostChainsOntoThePreviousSealUnderTheAdministrationsLock()

	public function testASealedTransactionKeepsItsSeal(): void {
		$service = $this->service();
		$this->postAll(service: $service);
		$this->patches = [];

		$again = $service->seal(transaction: $this->store['GLTransaction']['t2']);

		self::assertSame([], $this->patches);
		self::assertSame(2, $again['sealSequence']);
		self::assertSame($this->store['GLTransaction']['t2']['sealHash'], $again['sealHash']);
	}//end testASealedTransactionKeepsItsSeal()

	public function testAnIntactChainOfThreeIncludingAReversal(): void {
		$service = $this->service();
		$this->postAll(service: $service);
		$this->store['GLTransaction']['t2']['state'] = 'reversed';

		self::assertSame(
			['intact' => true, 'entries' => 3, 'transactionNumber' => null, 'transactionId' => null, 'reason' => null],
			$service->verify(administrationId: 'adm-voorbeeld')
		);
	}//end testAnIntactChainOfThreeIncludingAReversal()

	public function testAnAmountChangedInStorageIsNamed(): void {
		$service = $this->service();
		$this->postAll(service: $service);
		$this->store['GLLine']['t2-1']['amount'] = '6.30';

		$result = $service->verify(administrationId: 'adm-voorbeeld');

		self::assertFalse($result['intact']);
		self::assertSame('2026-0902', $result['transactionNumber']);
		self::assertSame('t2', $result['transactionId']);
		self::assertSame('content', $result['reason']);
	}//end testAnAmountChangedInStorageIsNamed()

	public function testABrokenLinkIsNamed(): void {
		$service = $this->service();
		$this->postAll(service: $service);
		$this->store['GLTransaction']['t3']['previousSealHash'] = str_repeat('f', 64);

		$result = $service->verify(administrationId: 'adm-voorbeeld');

		self::assertSame(['2026-0903', 'link'], [$result['transactionNumber'], $result['reason']]);
	}//end testABrokenLinkIsNamed()

	public function testASkippedSequenceIsNamed(): void {
		$service = $this->service();
		$this->postAll(service: $service);
		$this->store['GLTransaction']['t3']['sealSequence'] = 4;

		$result = $service->verify(administrationId: 'adm-voorbeeld');

		self::assertSame(['2026-0903', 'sequence', 3], [$result['transactionNumber'], $result['reason'], $result['entries']]);
	}//end testASkippedSequenceIsNamed()

	public function testALockThatCannotBeTakenFailsTheSealAndWritesNothing(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new LockedException('shillinq-gl-seal-adm-voorbeeld'));
		$locks->expects(self::never())->method('releaseLock');

		try {
			$this->service(locks: $locks)->seal(transaction: $this->store['GLTransaction']['t1']);
			self::fail('A seal without its lock must fail.');
		} catch (RuntimeException $e) {
			self::assertStringStartsWith('ledger_seal_locked:', $e->getMessage());
		}

		self::assertSame([], $this->patches);
	}//end testALockThatCannotBeTakenFailsTheSealAndWritesNothing()

	public function testAWriteThatFailsFailsTheSealAndReleasesTheLock(): void {
		$failing = $this->createMock(ObjectServiceInterface::class);
		$failing->method('setRegister')->willReturnSelf();
		$failing->method('setSchema')->willReturnSelf();
		$failing->method('findAll')->willReturn([]);
		$failing->method('patchObject')->willThrowException(new \Exception('database gone'));
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$service = new LedgerSealService($failing, $config, $this->locks, new LedgerSealCanonicaliser(), $this->createMock(LoggerInterface::class));

		try {
			$service->seal(transaction: $this->store['GLTransaction']['t1']);
			self::fail('A seal that cannot be written must fail.');
		} catch (RuntimeException $e) {
			self::assertStringStartsWith('ledger_seal_failed:', $e->getMessage());
		}

		self::assertSame('release shillinq-gl-seal-adm-voorbeeld', end($this->lockLog));
	}//end testAWriteThatFailsFailsTheSealAndReleasesTheLock()

	public function testATransactionWithoutAdministrationIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ledger_seal_failed: the transaction has no id or administration');

		$this->service()->seal(transaction: ['id' => 't9']);
	}//end testATransactionWithoutAdministrationIsRefused()

	public function testTheGenesisPassSealsInPostingOrderAndChecksOut(): void {
		$this->store['GLTransaction']['t1']['postingDate'] = '2026-09-25';
		$this->store['GLTransaction']['draft'] = ['id' => 'draft', 'administrationId' => 'adm-voorbeeld', 'transactionNumber' => '2026-0999', 'postingDate' => '2026-09-01', 'state' => 'draft'];
		$service = $this->service();

		self::assertSame(3, $service->sealExisting(administrationId: 'adm-voorbeeld'));

		self::assertSame(['t2', 't3', 't1'], array_column($this->patches, 0));
		self::assertArrayNotHasKey('sealSequence', $this->store['GLTransaction']['draft']);
		self::assertTrue($service->verify(administrationId: 'adm-voorbeeld')['intact']);
	}//end testTheGenesisPassSealsInPostingOrderAndChecksOut()

	public function testTheGenesisPassRefusesAnAdministrationWithSeals(): void {
		$service = $this->service();
		$service->seal(transaction: $this->store['GLTransaction']['t1']);
		$this->patches = [];

		try {
			$service->sealExisting(administrationId: 'adm-voorbeeld');
			self::fail('A sealed administration must be refused.');
		} catch (RuntimeException $e) {
			self::assertStringStartsWith('ledger_already_sealed:', $e->getMessage());
		}

		self::assertSame([], $this->patches);
	}//end testTheGenesisPassRefusesAnAdministrationWithSeals()
}//end class
