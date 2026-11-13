<?php

/**
 * Unit tests for SegregatedLedgerGuard reading the ledger itself.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\SegregatedLedgerGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When the close transition hands over only an id, the guard reads the
 * segregated ledger itself; variances are compared in whole cents.
 *
 * @covers \OCA\Shillinq\Lifecycle\SegregatedLedgerGuard
 */
final class SegregatedLedgerGuardLookupTest extends TestCase {

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
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard, over a configured register.
	 *
	 * @param string $register The configured register slug.
	 *
	 * @return SegregatedLedgerGuard The guard.
	 */
	private function guard(string $register = 'books'): SegregatedLedgerGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new SegregatedLedgerGuard($config, $this->logger, $this->objects);
	}//end guard()

	/**
	 * Expect one read of this schema in this register, answering these records.
	 *
	 * @param string               $register The register expected.
	 * @param string               $schema   The schema expected.
	 * @param array<string, mixed> $query    The query expected.
	 * @param array<int, mixed>    $records  The records to answer.
	 *
	 * @return void
	 */
	private function expectRead(string $register, string $schema, array $query, array $records): void {
		$this->objects->expects($this->once())->method('setRegister')->with($register)->willReturnSelf();
		$this->objects->expects($this->once())->method('setSchema')->with($schema)->willReturnSelf();
		$this->objects->expects($this->once())->method('findAll')->with($query)->willReturn($records);
	}//end expectRead()

	/**
	 * Expect no read at all.
	 *
	 * @return void
	 */
	private function expectNoRead(): void {
		$this->objects->expects($this->never())->method('findAll');
	}//end expectNoRead()

	/**
	 * Expect a read that throws, and one fail-closed log line with this message.
	 *
	 * @param string               $message The logged message.
	 * @param array<string, mixed> $context The logged context.
	 *
	 * @return void
	 */
	private function expectFailedRead(string $message, array $context): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with($message, $context);
	}//end expectFailedRead()

	/**
	 * Given only an id, the guard reads the first array record in the configured register.
	 *
	 * @return void
	 */
	public function testAnIdOnlyTransitionReadsTheRecordFromTheConfiguredRegister(): void {
		$this->expectRead('books', 'SegregatedLedger', ['filters' => ['id' => 'x-1'], 'limit' => 1], ['not a record', ['reconciliationVariance' => 0.004]]);

		$this->assertTrue($this->guard()->canClose('x-1'));
	}//end testAnIdOnlyTransitionReadsTheRecordFromTheConfiguredRegister()

	/**
	 * A read record that fails the check is refused like a passed one.
	 *
	 * @return void
	 */
	public function testTheReadRecordIsJudgedLikeAPassedOne(): void {
		$this->expectRead('shillinq', 'SegregatedLedger', ['filters' => ['id' => 'x-1'], 'limit' => 1], [['reconciliationVariance' => 0.01]]);

		$this->assertFalse($this->guard('')->canClose('x-1'), 'a blank register falls back to the app id');
	}//end testTheReadRecordIsJudgedLikeAPassedOne()

	/**
	 * A record that is not found, or found in no array shape, is refused.
	 *
	 * @return void
	 */
	public function testANotFoundRecordIsRefused(): void {
		$this->expectRead('books', 'SegregatedLedger', ['filters' => ['id' => 'x-1'], 'limit' => 1], ['broken', 42]);

		$this->assertFalse($this->guard()->canClose('x-1'));
	}//end testANotFoundRecordIsRefused()

	/**
	 * A blank id and no record is refused without reading.
	 *
	 * @return void
	 */
	public function testABlankIdWithoutARecordIsRefusedWithoutARead(): void {
		$this->expectNoRead();

		$this->assertFalse($this->guard()->canClose(''));
	}//end testABlankIdWithoutARecordIsRefusedWithoutARead()

	/**
	 * A record passed in by the transition is judged without reading.
	 *
	 * @return void
	 */
	public function testAPassedRecordIsUsedWithoutARead(): void {
		$this->expectNoRead();

		$this->assertTrue($this->guard()->canClose('', ['reconciliationVariance' => 0.004]));
	}//end testAPassedRecordIsUsedWithoutARead()

	/**
	 * A read that throws is logged and refuses the transition.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndRefused(): void {
		$this->expectFailedRead(
			'SegregatedLedgerGuard: close check failed — denying close transition (fail-closed)',
			['segregatedLedgerId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canClose('x-1'));
	}//end testAFailingReadIsLoggedAndRefused()

	/**
	 * A stored variance below half a cent closes; a cent or more does not.
	 *
	 * @return void
	 */
	public function testAVarianceIsComparedInWholeCents(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertTrue($guard->canClose('', ['reconciliationVariance' => '-0.004']));
		$this->assertFalse($guard->canClose('', ['reconciliationVariance' => -0.005]));
		$this->assertFalse($guard->canClose('', ['reconciliationVariance' => 0.01, 'regularGlBalanceEur' => 5, 'euAdministrationBalanceEur' => 5]), 'a stored variance wins over the balances');
	}//end testAVarianceIsComparedInWholeCents()

	/**
	 * Without a stored variance, the two balances must agree to the cent.
	 *
	 * @return void
	 */
	public function testWithoutAStoredVarianceTheBalancesMustMatchToTheCent(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertTrue($guard->canClose('', ['reconciliationVariance' => null, 'regularGlBalanceEur' => '100.001', 'euAdministrationBalanceEur' => 100.0]));
		$this->assertFalse($guard->canClose('', ['regularGlBalanceEur' => 100.0, 'euAdministrationBalanceEur' => 100.01]));
		$this->assertTrue($guard->canClose('', []), 'two missing balances are both zero');
	}//end testWithoutAStoredVarianceTheBalancesMustMatchToTheCent()
}//end class
