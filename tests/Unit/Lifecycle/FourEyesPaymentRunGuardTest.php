<?php

/**
 * Unit tests for FourEyesPaymentRunGuard.
 *
 * Proves the server-side segregation-of-duties control on the
 * `PaymentRun.approve` transition (payment-run-four-eyes, REQ-PR4E-001):
 * the same user who prepared a batch is REJECTED when they try to approve it;
 * a different authorised user is ALLOWED; and every indeterminate case
 * (unknown approver, unidentifiable batch, no determinable preparer in the
 * audit trail, audit-read failure) fails CLOSED rather than passing.
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
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\FourEyesPaymentRunGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FourEyesPaymentRunGuard.
 */
class FourEyesPaymentRunGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var FourEyesPaymentRunGuard
	 */
	private FourEyesPaymentRunGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->guard = new FourEyesPaymentRunGuard(
			container: $this->container,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fake OpenRegister ObjectService returning the given audit rows.
	 *
	 * @param array<int, object|array> $logs Audit-trail rows for getLogs().
	 *
	 * @return object A stub exposing getLogs() with the ObjectService signature.
	 */
	private function objectServiceReturning(array $logs): object {
		return new class($logs) {
			/**
			 * @param array<int, object|array> $logs Audit rows.
			 */
			public function __construct(
				private array $logs,
			) {
			}

			/**
			 * Mirror of ObjectService::getLogs().
			 *
			 * @param string $uuid Object uuid.
			 * @param array<mixed> $filters Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<int, object|array>
			 */
			public function getLogs(string $uuid, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->logs;
			}
		};

	}//end objectServiceReturning()

	/**
	 * Build one audit-trail row as an entity-shaped object (getAction/getUser).
	 *
	 * @param string $action The audit action (create/update/read/delete).
	 * @param string $user The actor uid.
	 *
	 * @return object Entity-shaped audit row.
	 */
	private function log(string $action, string $user): object {
		return new class($action, $user) {
			/**
			 * @param string $action Action.
			 * @param string $user Actor uid.
			 */
			public function __construct(
				private string $action,
				private string $user,
			) {
			}

			/**
			 * @return string|null
			 */
			public function getAction(): ?string {
				return $this->action;
			}

			/**
			 * @return string|null
			 */
			public function getUser(): ?string {
				return $this->user;
			}
		};

	}//end log()

	/**
	 * A DIFFERENT authorised user may approve a batch they did not prepare.
	 *
	 * @return void
	 */
	public function testDifferentUserMayApprove(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning([$this->log('create', 'alice')])
		);

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'bob');

		self::assertTrue($result->isAllowed());
		self::assertNull($result->getMessage());

	}//end testDifferentUserMayApprove()

	/**
	 * THE control: the user who prepared the batch CANNOT self-approve it.
	 *
	 * @return void
	 */
	public function testPreparerCannotSelfApprove(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning([$this->log('create', 'alice')])
		);

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_SELF_APPROVAL, $result->getMessage());

	}//end testPreparerCannotSelfApprove()

	/**
	 * A user who MODIFIED the draft (not just created it) also cannot approve.
	 *
	 * @return void
	 */
	public function testDraftModifierCannotApprove(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning(
				[
					$this->log('create', 'alice'),
					$this->log('update', 'bob'),
				]
			)
		);

		// Bob edited the draft, so bob cannot approve it either.
		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'bob');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_SELF_APPROVAL, $result->getMessage());

	}//end testDraftModifierCannotApprove()

	/**
	 * A third party who never touched the draft may approve it.
	 *
	 * @return void
	 */
	public function testUninvolvedControllerMayApprove(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning(
				[
					$this->log('create', 'alice'),
					$this->log('update', 'bob'),
				]
			)
		);

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'carol');

		self::assertTrue($result->isAllowed());

	}//end testUninvolvedControllerMayApprove()

	/**
	 * No `create` actor in the audit trail => preparer indeterminate => BLOCK.
	 *
	 * @return void
	 */
	public function testIndeterminatePreparerIsBlocked(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning([$this->log('update', 'bob')])
		);

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'carol');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_INDETERMINATE, $result->getMessage());

	}//end testIndeterminatePreparerIsBlocked()

	/**
	 * An empty audit trail (no rows at all) fails closed.
	 *
	 * @return void
	 */
	public function testEmptyAuditTrailIsBlocked(): void {
		$this->container->method('get')->willReturn($this->objectServiceReturning([]));

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'carol');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_INDETERMINATE, $result->getMessage());

	}//end testEmptyAuditTrailIsBlocked()

	/**
	 * A `create` row whose actor is unknown (empty user) fails closed.
	 *
	 * @return void
	 */
	public function testCreateWithoutKnownActorIsBlocked(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning([$this->log('create', '')])
		);

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'carol');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_INDETERMINATE, $result->getMessage());

	}//end testCreateWithoutKnownActorIsBlocked()

	/**
	 * An unknown approver identity is blocked BEFORE any audit read.
	 *
	 * @return void
	 */
	public function testMissingApproverIsBlockedWithoutAuditRead(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', '');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_NO_APPROVER, $result->getMessage());

	}//end testMissingApproverIsBlockedWithoutAuditRead()

	/**
	 * An unidentifiable batch (no id) is blocked BEFORE any audit read.
	 *
	 * @return void
	 */
	public function testMissingObjectIdIsBlockedWithoutAuditRead(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->guard->check([], 'approve', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_NO_OBJECT, $result->getMessage());

	}//end testMissingObjectIdIsBlockedWithoutAuditRead()

	/**
	 * A thrown audit-trail read fails closed (denies), never passes.
	 *
	 * @return void
	 */
	public function testAuditReadFailureFailsClosed(): void {
		$throwing = new class {

			/**
			 * @param string $uuid Object uuid.
			 * @param array<mixed> $filters Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function getLogs(string $uuid, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				throw new \RuntimeException('audit backend unavailable');
			}
		};
		$this->container->method('get')->willReturn($throwing);

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_INDETERMINATE, $result->getMessage());

	}//end testAuditReadFailureFailsClosed()

	/**
	 * The `@self` envelope id is honoured when top-level id is absent.
	 *
	 * @return void
	 */
	public function testSelfEnvelopeIdIsResolved(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning([$this->log('create', 'alice')])
		);

		$result = $this->guard->check(['@self' => ['id' => 'pr-9']], 'approve', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_SELF_APPROVAL, $result->getMessage());

	}//end testSelfEnvelopeIdIsResolved()

	/**
	 * Array-shaped audit rows (jsonSerialize shape) are also honoured.
	 *
	 * @return void
	 */
	public function testArrayShapedLogsAreHonoured(): void {
		$this->container->method('get')->willReturn(
			$this->objectServiceReturning([['action' => 'create', 'user' => 'alice']])
		);

		$result = $this->guard->check(['id' => 'pr-1'], 'approve', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame(FourEyesPaymentRunGuard::MESSAGE_SELF_APPROVAL, $result->getMessage());

	}//end testArrayShapedLogsAreHonoured()
}//end class
