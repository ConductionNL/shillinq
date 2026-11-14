<?php

/**
 * Unit tests for AuditorStatementGuard's statement lookup.
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
use OCA\Shillinq\Lifecycle\AuditorStatementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When the transition hands over only an id, the guard reads the statement
 * itself, in the configured register: a missing statement, an unreadable
 * one and a read that throws all deny the approval.
 *
 * @covers \OCA\Shillinq\Lifecycle\AuditorStatementGuard
 */
final class AuditorStatementGuardLookupTest extends TestCase {

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
		$this->objects->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard, over a configured register.
	 *
	 * @param string $register The configured register slug.
	 *
	 * @return AuditorStatementGuard The guard.
	 */
	private function guard(string $register = 'books'): AuditorStatementGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);

		return new AuditorStatementGuard($config, $this->logger, $this->objects);
	}//end guard()

	/**
	 * Expect one statement read in this register, answering these records.
	 *
	 * @param string            $register The register expected.
	 * @param array<int, mixed> $records  The records to answer.
	 *
	 * @return void
	 */
	private function expectRead(string $register, array $records): void {
		$this->objects->expects($this->once())->method('setRegister')->with($register)->willReturnSelf();
		$this->objects->expects($this->once())
			->method('findAll')
			->with(['filters' => ['id' => 'st-1']])
			->willReturn($records);
	}//end expectRead()

	/**
	 * A statement read as an array with every finding resolved may be approved.
	 *
	 * @return void
	 */
	public function testAReadStatementWithResolvedFindingsMayBeApproved(): void {
		$this->expectRead('books', [['findings' => [['resolution' => 'resolved']]]]);

		$this->assertTrue($this->guard()->canApprove('st-1'));
	}//end testAReadStatementWithResolvedFindingsMayBeApproved()

	/**
	 * A statement read as an entity is unwrapped; an open finding blocks.
	 *
	 * @return void
	 */
	public function testAnEntityRecordIsUnwrapped(): void {
		$entity = new class {

			/**
			 * @return array<string, mixed> The statement.
			 */
			public function getObject(): array {
				return ['findings' => [['resolution' => 'resolved'], ['resolution' => 'open']]];
			}
		};
		$this->expectRead('shillinq', ['not a record', $entity]);

		$this->assertFalse($this->guard('')->canApprove('st-1'), 'a blank register falls back to the app id');
	}//end testAnEntityRecordIsUnwrapped()

	/**
	 * A statement that is not found, or found in no readable shape, is not approved.
	 *
	 * @return void
	 */
	public function testAnUnreadableStatementIsNotApproved(): void {
		$entity = new class {

			/**
			 * @return string Not an array.
			 */
			public function getObject(): string {
				return 'broken';
			}
		};
		$this->expectRead('books', [$entity]);

		$this->assertFalse($this->guard()->canApprove('st-1'));
	}//end testAnUnreadableStatementIsNotApproved()

	/**
	 * A blank id is never looked up.
	 *
	 * @return void
	 */
	public function testABlankIdIsNotLookedUp(): void {
		$this->objects->expects($this->never())->method('findAll');

		$this->assertFalse($this->guard()->canApprove(''));
	}//end testABlankIdIsNotLookedUp()

	/**
	 * A read that throws denies, and logs why.
	 *
	 * @return void
	 */
	public function testAFailedReadFailsClosed(): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('register offline'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'AuditorStatementGuard: approve check failed — denying approve transition (fail-closed)',
				['statementId' => 'st-1', 'exception' => 'register offline']
			);

		$this->assertFalse($this->guard()->canApprove('st-1'));
	}//end testAFailedReadFailsClosed()
}//end class
