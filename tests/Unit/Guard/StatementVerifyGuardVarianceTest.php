<?php

/**
 * Unit tests for StatementVerifyGuard bank-balance tie-out flagging.
 *
 * Evidence test for payment-control-guards audit item 2 (bank-balance tie-out).
 * The statement-closing-balance vs GL-bank-account tie-out is ALREADY OWNED by
 * bookkeeping-reconciliation-reports' StatementVerifyGuard::verifyStatementBalance()
 * (REQ-REC-002) — this change does NOT re-implement it. These tests pin the
 * BAD PATH the audit cares about: when the statement closing balance does NOT
 * tie to the expected GL balance, a non-zero variance is FLAGGED (persisted onto
 * the BankReconciliation record), while a balance that ties flags nothing.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Guard\StatementVerifyGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that StatementVerifyGuard flags a bank statement that does not tie out.
 */
class StatementVerifyGuardVarianceTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Recording ObjectService mock injected into the guard (ADR-083 rule 1).
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Every updateObject() payload the guard persisted, keyed by object id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $updates = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		// A fluent ObjectService: no GL lines (net activity 0), recording every
		// updateObject() so the test can read the persisted variance back.
		$this->updates = [];
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('updateObject')->willReturnCallback(
			function (string $objectId, array $data): ObjectEntityInterface {
				$this->updates[$objectId] = $data;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end setUp()

	/**
	 * Build the guard under test.
	 *
	 * @return StatementVerifyGuard
	 */
	private function guard(): StatementVerifyGuard {
		return new StatementVerifyGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->objectService,
		);
	}//end guard()

	/**
	 * A statement whose closing balance does NOT tie to GL flags a non-zero variance.
	 *
	 * Opening 5000.00 + GL net activity 0.00 = expected 5000.00; the statement
	 * closes at 5100.00, so the tie-out fails by 100.00 and that variance is
	 * persisted (flagged) onto the reconciliation.
	 *
	 * @return void
	 */
	public function testNonTyingBalanceIsFlagged(): void {
		$result = $this->guard()->verifyStatementBalance(
			[
				'id' => 'rec-1',
				'bankAccountId' => '1000',
				'openingBalance' => 5000.00,
				'closingBalance' => 5100.00,
				'statementPeriodStart' => '2026-01-01',
				'statementPeriodEnd' => '2026-01-31',
			]
		);

		// REQ-REC-002: variance surfaces a warning but does not block.
		self::assertTrue(condition: $result, message: 'A variance surfaces a warning but does not block the transition');
		self::assertArrayHasKey(key: 'rec-1', array: $this->updates);
		self::assertEqualsWithDelta(
			expected: 100.00,
			actual: $this->updates['rec-1']['variance'],
			delta: 0.001,
			message: 'The non-tying balance must flag a 100.00 variance'
		);
		self::assertNotSame(expected: 0, actual: $this->updates['rec-1']['variance'], message: 'A non-tying balance must not flag a zero variance');
		self::assertEqualsWithDelta(expected: 5000.00, actual: $this->updates['rec-1']['expectedGLBalance'], delta: 0.001);

	}//end testNonTyingBalanceIsFlagged()

	/**
	 * A statement that ties out flags a zero variance.
	 *
	 * @return void
	 */
	public function testTyingBalanceFlagsZeroVariance(): void {
		$result = $this->guard()->verifyStatementBalance(
			[
				'id' => 'rec-2',
				'bankAccountId' => '1000',
				'openingBalance' => 5000.00,
				'closingBalance' => 5000.00,
				'statementPeriodStart' => '2026-01-01',
				'statementPeriodEnd' => '2026-01-31',
			]
		);

		self::assertTrue(condition: $result);
		self::assertEqualsWithDelta(
			expected: 0.00,
			actual: $this->updates['rec-2']['variance'],
			delta: 0.001,
			message: 'A tying balance must flag a zero variance'
		);

	}//end testTyingBalanceFlagsZeroVariance()
}//end class
