<?php

/**
 * Gap tests for PeriodCloseGuard: transaction lookup by id, unresolvable
 * periods, fail-closed catches, unreadable rows and the register fallback.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-14
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\PeriodCloseGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the branches the main PeriodCloseGuard suite leaves open
 * (REQ-PC-002, REQ-PC-003, REQ-PC-006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(PeriodCloseGuard::class)]
final class PeriodCloseGuardGapsTest extends TestCase {

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $log = [];

	/**
	 * The recording duck behind the adapter.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The container mock (never expected to be asked in these paths unless stated).
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * Build the guard over per-schema rows.
	 *
	 * @param array<string,mixed> $rows     Rows per schema; a Throwable is thrown by findAll.
	 * @param string              $register What IAppConfig answers for the register.
	 *
	 * @return PeriodCloseGuard
	 */
	private function guard(array $rows, string $register = 'shillinq'): PeriodCloseGuard {
		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class($rows) {
			/** @var list<array{0:string,1:string,2:array<string,mixed>}> register, schema and query per findAll */
			public array $queries = [];

			private string $register = '';

			private string $schema = '';

			public function __construct(private readonly array $rows) {
			}

			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $params = []): array {
				$this->queries[] = [$this->register, $this->schema, $params];
				$answer = ($this->rows[$this->schema] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						$answer,
						static function (mixed $row) use ($filters): bool {
							if (is_array($row) === false) {
								return true;
							}

							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->expects(self::never())->method('get');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->log[] = [$level, $message, $context];
				}
			);
		}

		return new PeriodCloseGuard(
			container: $this->container,
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end guard()

	/**
	 * A transaction given by id is looked up and gated by its period.
	 *
	 * @return void
	 */
	public function testATransactionIdIsResolvedBeforeGating(): void {
		$guard = $this->guard(
			[
				'GLTransaction' => [
					['id' => 'tx-closed', 'periodId' => '2026-01', 'administrationId' => 'adm-1'],
					['id' => 'tx-open', 'periodId' => '2026-02', 'administrationId' => 'adm-1'],
				],
				'FiscalPeriod' => [
					['periodId' => '2026-01', 'administrationId' => 'adm-1', 'state' => 'closed'],
					['periodId' => '2026-02', 'administrationId' => 'adm-1', 'state' => 'open'],
				],
			]
		);

		self::assertFalse($guard->periodOpen('tx-closed'));
		self::assertTrue($guard->periodOpen('tx-open'));
		self::assertSame(
			[
				['shillinq', 'GLTransaction', ['filters' => ['id' => 'tx-closed'], 'limit' => 1]],
				['shillinq', 'FiscalPeriod', ['filters' => ['periodId' => '2026-01', 'administrationId' => 'adm-1'], 'limit' => 1]],
				['shillinq', 'GLTransaction', ['filters' => ['id' => 'tx-open'], 'limit' => 1]],
				['shillinq', 'FiscalPeriod', ['filters' => ['periodId' => '2026-02', 'administrationId' => 'adm-1'], 'limit' => 1]],
			],
			$this->store->queries
		);
		self::assertSame([['info', 'PeriodCloseGuard: posting rejected — period is frozen', ['periodId' => '2026-01', 'state' => 'closed']]], $this->log);

	}//end testATransactionIdIsResolvedBeforeGating()

	/**
	 * An unknown transaction id has no period scope, so posting is allowed without a period lookup.
	 *
	 * @return void
	 */
	public function testAnUnknownTransactionIdIsUngated(): void {
		$guard = $this->guard(['FiscalPeriod' => [['periodId' => '2026-01', 'state' => 'closed']]]);

		self::assertTrue($guard->periodOpen('tx-404'));
		self::assertSame([['shillinq', 'GLTransaction', ['filters' => ['id' => 'tx-404'], 'limit' => 1]]], $this->store->queries);

	}//end testAnUnknownTransactionIdIsUngated()

	/**
	 * A transaction row that is neither an array nor serialises to one reads as empty.
	 *
	 * @return void
	 */
	public function testAnUnreadableTransactionRowReadsAsEmpty(): void {
		$opaque = new class implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				return 'opaque';
			}
		};
		$guard = $this->guard(['GLTransaction' => [$opaque], 'FiscalPeriod' => [['periodId' => '2026-01', 'state' => 'closed']]]);

		self::assertTrue($guard->periodOpen('tx-1'));
		self::assertCount(1, $this->store->queries);

	}//end testAnUnreadableTransactionRowReadsAsEmpty()

	/**
	 * A failing period lookup denies the posting (fail-closed).
	 *
	 * @return void
	 */
	public function testAFailingPeriodLookupDeniesPosting(): void {
		$guard = $this->guard(['FiscalPeriod' => new RuntimeException('periods offline')]);

		self::assertFalse($guard->periodOpen(['periodId' => '2026-01']));
		self::assertSame(
			[['error', 'PeriodCloseGuard: periodOpen check failed — denying post (fail-closed)', ['exception' => 'periods offline']]],
			$this->log
		);

	}//end testAFailingPeriodLookupDeniesPosting()

	/**
	 * A failing lookup denies beginClose (fail-closed); an unknown period id is denied quietly.
	 *
	 * @return void
	 */
	public function testTrialBalanceFailsClosed(): void {
		self::assertFalse($this->guard([])->trialBalanceVerifies('fp-404'));
		self::assertSame([], $this->log);

		self::assertFalse($this->guard(['FiscalPeriod' => new RuntimeException('periods offline')])->trialBalanceVerifies('fp-1'));
		self::assertSame(
			[['error', 'PeriodCloseGuard: trialBalanceVerifies check failed — denying beginClose (fail-closed)', ['exception' => 'periods offline']]],
			$this->log
		);

	}//end testTrialBalanceFailsClosed()

	/**
	 * An unknown period id is denied close without asking the suspense service.
	 *
	 * @return void
	 */
	public function testSuspenseCheckDeniesAnUnknownPeriod(): void {
		$guard = $this->guard([]);

		self::assertFalse($guard->suspenseAccountDrained('fp-404'));
		self::assertSame([['shillinq', 'FiscalPeriod', ['filters' => ['id' => 'fp-404'], 'limit' => 1]]], $this->store->queries);
		self::assertSame([], $this->log);

	}//end testSuspenseCheckDeniesAnUnknownPeriod()

	/**
	 * Reopen needs a resolvable period with a close reason; a failing lookup denies.
	 *
	 * @return void
	 */
	public function testCloseReasonOnAResolvedPeriod(): void {
		$guard = $this->guard(
			[
				'FiscalPeriod' => [
					['id' => 'fp-1', 'closeReason' => 'Late supplier invoice'],
					['id' => 'fp-2', 'closeReason' => '   '],
				],
			]
		);

		self::assertTrue($guard->closeReasonSupplied('fp-1'));
		self::assertFalse($guard->closeReasonSupplied('fp-2'));
		self::assertFalse($guard->closeReasonSupplied('fp-404'));
		self::assertSame([], $this->log);

		self::assertFalse($this->guard(['FiscalPeriod' => new RuntimeException('periods offline')])->closeReasonSupplied('fp-1'));
		self::assertSame(
			[['error', 'PeriodCloseGuard: closeReasonSupplied check failed — denying reopen (fail-closed)', ['exception' => 'periods offline']]],
			$this->log
		);

	}//end testCloseReasonOnAResolvedPeriod()

	/**
	 * An empty configured register falls back to 'shillinq'; a set one is used.
	 *
	 * @return void
	 */
	public function testTheRegisterFallsBackToShillinq(): void {
		$this->guard([], register: '')->closeReasonSupplied('fp-1');
		self::assertSame('shillinq', $this->store->queries[0][0]);

		$this->guard([], register: 'ledger')->closeReasonSupplied('fp-1');
		self::assertSame('ledger', $this->store->queries[0][0]);

	}//end testTheRegisterFallsBackToShillinq()
}//end class
