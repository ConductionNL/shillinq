<?php

/**
 * Unit tests for WbsoTransactionService.
 *
 * Covers REQ-WBSO-002 (schema validation), REQ-WBSO-008 (post/reverse state
 * machine), and REQ-WBSO-005 (audit-trail-immutable preconditions).
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-32
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\WbsoTransactionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-32
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoTransactionServiceTest extends TestCase {

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * App-config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * User-session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build a service over an in-memory OR stub.
	 *
	 * @param array<int,array<string,mixed>> $rows Initial transactions.
	 *
	 * @return WbsoTransactionService
	 */
	private function buildService(array $rows): WbsoTransactionService {
		$stub = new class($rows) {

			/**
			 * Backing rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $rows;

			/**
			 * Last saved.
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $lastSaved = null;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $rows Rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}

			/**
			 * Fluent.
			 *
			 * @param string $r Register.
			 *
			 * @return static
			 */
			public function setRegister(string $r): static {
				return $this;
			}

			/**
			 * Fluent.
			 *
			 * @param string $s Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $s): static {
				return $this;
			}

			/**
			 * findAll.
			 *
			 * @param array<string,mixed> $p Params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $p = []): array {
				$filters = ($p['filters'] ?? []);
				if ($filters === []) {
					return $this->rows;
				}

				return array_values(array_filter(
					$this->rows,
					static function (array $row) use ($filters): bool {
						foreach ($filters as $k => $v) {
							if (($row[$k] ?? null) !== $v) {
								return false;
							}
						}
						return true;
					}
				));
			}

			/**
			 * Save passthrough.
			 *
			 * @param array<string,mixed> $object Object.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->lastSaved = $object;
				// Update existing or insert.
				if (isset($object['id']) === true) {
					foreach ($this->rows as $idx => $row) {
						if (($row['id'] ?? null) === $object['id']) {
							$this->rows[$idx] = $object;
							return $object;
						}
					}
				}

				$this->rows[] = $object;

				return $object;
			}
		};

		$this->container->method('get')->willReturn($stub);

		return new WbsoTransactionService(
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Creating a transaction yields draft state.
	 *
	 * @return void
	 */
	public function testCreateTransactionIsDraft(): void {
		$service = $this->buildService([]);
		$row = $service->createTransaction(
			administrationId: 'adm-1',
			payload: [
				'transactionNumber' => 'INV-1',
				'transactionType' => 'invoice',
				'transactionDate' => '2026-01-15',
				'amount' => 1500.00,
				'description' => 'Test',
			]
		);

		self::assertSame('draft', $row['status']);
		self::assertSame('alice', $row['createdBy']);

	}//end testCreateTransactionIsDraft()

	/**
	 * Negative amount rejected.
	 *
	 * @return void
	 */
	public function testCreateRejectsNegativeAmount(): void {
		$service = $this->buildService([]);

		$this->expectException(InvalidArgumentException::class);
		$service->createTransaction(
			administrationId: 'adm-1',
			payload: [
				'transactionNumber' => 'INV-1',
				'transactionType' => 'invoice',
				'transactionDate' => '2026-01-15',
				'amount' => -1.0,
				'description' => 'Bad',
			]
		);

	}//end testCreateRejectsNegativeAmount()

	/**
	 * Unknown transactionType rejected.
	 *
	 * @return void
	 */
	public function testCreateRejectsUnknownType(): void {
		$service = $this->buildService([]);

		$this->expectException(InvalidArgumentException::class);
		$service->createTransaction(
			administrationId: 'adm-1',
			payload: [
				'transactionNumber' => 'BAD-1',
				'transactionType' => 'unknown-type',
				'transactionDate' => '2026-01-15',
				'amount' => 1.00,
				'description' => 'Bad',
			]
		);

	}//end testCreateRejectsUnknownType()

	/**
	 * post() transitions draft → posted.
	 *
	 * @return void
	 */
	public function testPostTransition(): void {
		$service = $this->buildService([
			[
				'id' => 'tx-1',
				'transactionNumber' => 'INV-1',
				'transactionType' => 'invoice',
				'transactionDate' => '2026-01-15',
				'amount' => 100.00,
				'description' => 'Test',
				'status' => 'draft',
				'administrationId' => 'adm-1',
			],
		]);

		$row = $service->postTransaction(administrationId: 'adm-1', transactionId: 'tx-1');
		self::assertSame('posted', $row['status']);

	}//end testPostTransition()

	/**
	 * Posting a non-draft transaction is a conflict.
	 *
	 * @return void
	 */
	public function testPostRejectsNonDraft(): void {
		$service = $this->buildService([
			[
				'id' => 'tx-1',
				'transactionNumber' => 'INV-1',
				'status' => 'posted',
				'administrationId' => 'adm-1',
			],
		]);

		$this->expectException(RuntimeException::class);
		$service->postTransaction(administrationId: 'adm-1', transactionId: 'tx-1');

	}//end testPostRejectsNonDraft()

	/**
	 * Reversal creates a new linked record (REQ-WBSO-008).
	 *
	 * @return void
	 */
	public function testReversalCreatesLinkedRecord(): void {
		$service = $this->buildService([
			[
				'id' => 'tx-1',
				'transactionNumber' => 'INV-1',
				'transactionType' => 'invoice',
				'transactionDate' => '2026-01-15',
				'amount' => 1500.00,
				'description' => 'Sample',
				'status' => 'posted',
				'administrationId' => 'adm-1',
			],
		]);

		$rev = $service->reverseTransaction(
			administrationId: 'adm-1',
			transactionId: 'tx-1',
			reason: 'Disputed scope',
		);

		self::assertSame('reversed', $rev['status']);
		self::assertSame('tx-1', $rev['reversalOfTransactionId']);
		self::assertSame('Disputed scope', $rev['reversalReason']);
		self::assertStringStartsWith('Reversal of ', $rev['description']);

	}//end testReversalCreatesLinkedRecord()

	/**
	 * Reversal of a non-posted transaction is a conflict.
	 *
	 * @return void
	 */
	public function testReversalRejectsNonPosted(): void {
		$service = $this->buildService([
			[
				'id' => 'tx-2',
				'status' => 'draft',
				'transactionNumber' => 'INV-2',
				'administrationId' => 'adm-1',
			],
		]);

		$this->expectException(RuntimeException::class);
		$service->reverseTransaction(
			administrationId: 'adm-1',
			transactionId: 'tx-2',
			reason: 'oops',
		);

	}//end testReversalRejectsNonPosted()

	/**
	 * Empty reason on reversal is invalid.
	 *
	 * @return void
	 */
	public function testReversalRequiresReason(): void {
		$service = $this->buildService([
			[
				'id' => 'tx-3',
				'status' => 'posted',
				'transactionNumber' => 'INV-3',
				'amount' => 10.00,
				'description' => 'Sample',
				'administrationId' => 'adm-1',
			],
		]);

		$this->expectException(InvalidArgumentException::class);
		$service->reverseTransaction(
			administrationId: 'adm-1',
			transactionId: 'tx-3',
			reason: '   ',
		);

	}//end testReversalRequiresReason()

	/**
	 * listTransactions honours status + type filters.
	 *
	 * @return void
	 */
	public function testListAppliesFilters(): void {
		$service = $this->buildService([
			['id' => 'a', 'status' => 'draft', 'transactionType' => 'invoice', 'transactionDate' => '2026-01-01', 'administrationId' => 'adm-1'],
			['id' => 'b', 'status' => 'posted', 'transactionType' => 'invoice', 'transactionDate' => '2026-02-01', 'administrationId' => 'adm-1'],
			['id' => 'c', 'status' => 'posted', 'transactionType' => 'receipt', 'transactionDate' => '2026-02-15', 'administrationId' => 'adm-1'],
		]);

		$result = $service->listTransactions(
			administrationId: 'adm-1',
			filters: ['status' => 'posted', 'type' => 'invoice', 'dateFrom' => '2026-01-15', 'dateTo' => '2026-03-01'],
		);

		self::assertCount(1, $result);
		self::assertSame('b', $result[0]['id']);

	}//end testListAppliesFilters()
}//end class
