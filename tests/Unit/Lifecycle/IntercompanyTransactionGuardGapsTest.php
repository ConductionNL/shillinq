<?php

/**
 * Gap-filling unit tests for IntercompanyTransactionGuard.
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
 * @spec openspec/changes/bookkeeping-treasury-ihb/specs/bookkeeping-treasury-ihb/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the id-only IntercompanyTransaction lookup (found, not found, failing,
 * empty id) and the loan-activation branches that skip or raise the OECD
 * arm's-length warning in IntercompanyTransactionGuard.
 *
 * @covers \OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard
 */
final class IntercompanyTransactionGuardGapsTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The DI container double handing out the object service.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Every findAll() call as [schema, params].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $calls = [];

	/**
	 * Registers passed to setRegister().
	 *
	 * @var array<int,string>
	 */
	private array $registers = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($this->objects);
	}//end setUp()

	/**
	 * Build the guard over a configured register slug.
	 *
	 * @param string $register The register slug the app config answers.
	 *
	 * @return IntercompanyTransactionGuard The guard.
	 */
	private function guard(string $register = 'books'): IntercompanyTransactionGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new IntercompanyTransactionGuard(container: $this->container, appConfig: $config, logger: $this->logger);
	}//end guard()

	/**
	 * Seed the object service with rows per schema and record every read.
	 *
	 * @param array<string,array<int,mixed>|\Throwable> $bySchema Rows (or a throwable) per schema.
	 *
	 * @return void
	 */
	private function seed(array $bySchema): void {
		$schema = '';
		$this->objects->method('setRegister')->willReturnCallback(
			function (string|int $register): ObjectServiceInterface {
				$this->registers[] = (string)$register;
				return $this->objects;
			}
		);
		$this->objects->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema): ObjectServiceInterface {
				$schema = (string)$slug;
				return $this->objects;
			}
		);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = []) use (&$schema, $bySchema): array {
				$this->calls[] = [$schema, $config];
				$rows = ($bySchema[$schema] ?? []);
				if ($rows instanceof \Throwable) {
					throw $rows;
				}

				return $rows;
			}
		);
	}//end seed()

	/**
	 * No object and no id: post is refused without resolving the store.
	 *
	 * @return void
	 */
	public function testPostWithoutObjectOrIdIsRefusedWithoutLookup(): void {
		$this->container->expects($this->never())->method('get');

		self::assertFalse($this->guard()->canPost(transactionId: ''));
	}//end testPostWithoutObjectOrIdIsRefusedWithoutLookup()

	/**
	 * An id-only post reads the transaction (non-array rows skipped), then the
	 * covering FiscalYear of both administrations; open years permit the post.
	 *
	 * @return void
	 */
	public function testIdOnlyPostReadsTransactionThenBothFiscalYears(): void {
		$this->seed(
			[
				'IntercompanyTransaction' => [
					'junk',
					[
						'id'                   => 'ict-1',
						'postingDate'          => '2026-03-15',
						'fromAdministrationId' => 'adm-a',
						'toAdministrationId'   => 'adm-b',
					],
				],
				'FiscalYear'              => [['state' => 'open']],
			]
		);

		self::assertTrue($this->guard(register: '')->canPost(transactionId: 'ict-1'));
		$window = ['startDate' => ['lte' => '2026-03-15'], 'endDate' => ['gte' => '2026-03-15']];
		self::assertSame(
			[
				['IntercompanyTransaction', ['filters' => ['id' => 'ict-1']]],
				['FiscalYear', ['filters' => ['administrationId' => 'adm-a'] + $window]],
				['FiscalYear', ['filters' => ['administrationId' => 'adm-b'] + $window]],
			],
			$this->calls
		);
		self::assertSame(['shillinq', 'shillinq', 'shillinq'], $this->registers);
	}//end testIdOnlyPostReadsTransactionThenBothFiscalYears()

	/**
	 * A lookup answering no array row resolves to nothing: post refused, and no
	 * FiscalYear is consulted.
	 *
	 * @return void
	 */
	public function testIdOnlyPostWithoutArrayRowIsRefused(): void {
		$this->seed(['IntercompanyTransaction' => ['junk', 3]]);

		self::assertFalse($this->guard()->canPost(transactionId: 'ict-2'));
		self::assertSame([['IntercompanyTransaction', ['filters' => ['id' => 'ict-2']]]], $this->calls);
		self::assertSame(['books'], $this->registers);
	}//end testIdOnlyPostWithoutArrayRowIsRefused()

	/**
	 * A failing transaction lookup fails closed and logs the cause.
	 *
	 * @return void
	 */
	public function testTransactionLookupFailureFailsClosedAndLogs(): void {
		$this->seed(['IntercompanyTransaction' => new RuntimeException('store down')]);

		$this->logger->expects($this->once())->method('error')->with(
			'IntercompanyTransactionGuard: transaction lookup failed — denying post (fail-closed)',
			['transactionId' => 'ict-3', 'exception' => 'store down']
		);

		self::assertFalse($this->guard()->canPost(transactionId: 'ict-3'));
	}//end testTransactionLookupFailureFailsClosedAndLogs()

	/**
	 * Without a loan object there is nothing to check: activation permitted
	 * silently.
	 *
	 * @return void
	 */
	public function testLoanWithoutObjectIsPermittedSilently(): void {
		$this->logger->expects($this->never())->method('warning');

		self::assertTrue($this->guard()->canActivateLoan(loanId: 'loan-0'));
	}//end testLoanWithoutObjectIsPermittedSilently()

	/**
	 * A variable-rate loan is permitted without an arm's-length warning.
	 *
	 * @return void
	 */
	public function testVariableRateLoanSkipsArmsLengthCheck(): void {
		$this->logger->expects($this->never())->method('warning');

		self::assertTrue(
			$this->guard()->canActivateLoan(loanId: 'loan-1', object: ['rateType' => 'variable', 'fixedRate' => 0.5])
		);
	}//end testVariableRateLoanSkipsArmsLengthCheck()

	/**
	 * A fixed loan without a numeric rate is permitted without a warning.
	 *
	 * @return void
	 */
	public function testFixedLoanWithoutNumericRateSkipsArmsLengthCheck(): void {
		$this->logger->expects($this->never())->method('warning');

		self::assertTrue(
			$this->guard()->canActivateLoan(loanId: 'loan-2', object: ['rateType' => 'fixed', 'fixedRate' => 'n/a'])
		);
	}//end testFixedLoanWithoutNumericRateSkipsArmsLengthCheck()

	/**
	 * A fixed rate above EURIBOR-3M + 3% (7.5%) is permitted but logs the
	 * arm's-length warning with the loan id and the rate.
	 *
	 * @return void
	 */
	public function testFixedRateAboveSpreadLogsArmsLengthWarning(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'IntercompanyTransactionGuard: loan rate exceeds EURIBOR-3M + 3% — review OECD arm\'s-length documentation',
			['loanId' => 'loan-3', 'fixedRate' => 0.08]
		);

		self::assertTrue($this->guard()->canActivateLoan(loanId: 'loan-3', object: ['fixedRate' => '0.08']));
	}//end testFixedRateAboveSpreadLogsArmsLengthWarning()

	/**
	 * A fixed rate within the spread is permitted silently.
	 *
	 * @return void
	 */
	public function testFixedRateWithinSpreadLogsNothing(): void {
		$this->logger->expects($this->never())->method('warning');

		self::assertTrue(
			$this->guard()->canActivateLoan(loanId: 'loan-4', object: ['rateType' => 'fixed', 'fixedRate' => 0.07])
		);
	}//end testFixedRateWithinSpreadLogsNothing()
}//end class
