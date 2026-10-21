<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Lifecycle\PurchaseOrderApprovalGuard;
use OCA\Shillinq\Lifecycle\SupplierInvoiceExceptionApprovalGuard;
use OCA\Shillinq\Lifecycle\SupplierInvoicePayGuard;
use OCA\Shillinq\Listener\PurchaseOrderDecisionLockListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ServerWriteAuthority;
use OCA\Shillinq\Service\SettingsService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Purchase order and supplier invoice approvals only happen through their services.
 */
final class PurchasingGuardsTest extends TestCase {

	private const PO = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

	private const SIGNED = ['decision' => 'approved', 'decidedAt' => '2026-10-21T10:00:00Z', 'userId' => 'ann'];

	public function testPatchedPurchaseOrderApprovalIsRefused(): void {
		$guard = new PurchaseOrderApprovalGuard(new ServerWriteAuthority());
		$result = $guard->check(['id' => self::PO, 'approvalChain' => [self::SIGNED]], 'approve', 'mallory');
		self::assertFalse($result->isAllowed());
		self::assertSame(PurchaseOrderApprovalGuard::MESSAGE_NOT_SIGNED, $result->getMessage());
	}

	public function testApprovalServiceSaveWithFullChainPasses(): void {
		$authority = new ServerWriteAuthority();
		$guard = new PurchaseOrderApprovalGuard($authority);
		$result = $authority->during(
			PurchaseOrderApprovalGuard::PURPOSE,
			self::PO,
			fn () => $guard->check(['id' => self::PO, 'approvalChain' => [self::SIGNED, self::SIGNED]], 'approve', 'ann')
		);
		self::assertTrue($result->isAllowed());
	}

	public function testIncompleteOrEmptyChainIsRefusedEvenWithTheGrant(): void {
		$authority = new ServerWriteAuthority();
		$guard = new PurchaseOrderApprovalGuard($authority);
		foreach ([[], [self::SIGNED, ['decision' => 'pending']], [['decision' => 'approved']]] as $chain) {
			$result = $authority->during(
				PurchaseOrderApprovalGuard::PURPOSE,
				self::PO,
				fn () => $guard->check(['id' => self::PO, 'approvalChain' => $chain], 'approve', 'ann')
			);
			self::assertSame(PurchaseOrderApprovalGuard::MESSAGE_CHAIN_INCOMPLETE, $result->getMessage());
		}
	}

	public function testInvoiceLeavesExceptionOnlyUnderTheAcceptanceGrant(): void {
		$authority = new ServerWriteAuthority();
		$guard = new SupplierInvoiceExceptionApprovalGuard($authority);
		self::assertFalse($guard->check(['id' => 'inv-1'], 'approveException', 'mallory')->isAllowed());
		self::assertTrue($authority->during(
			SupplierInvoiceExceptionApprovalGuard::PURPOSE,
			'inv-1',
			fn () => $guard->check(['id' => 'inv-1'], 'approveException', 'ann')
		)->isAllowed());
		self::assertFalse($authority->during(
			SupplierInvoiceExceptionApprovalGuard::PURPOSE,
			'inv-2',
			fn () => $guard->check(['id' => 'inv-1'], 'approveException', 'ann')
		)->isAllowed());
	}

	/**
	 * @param array<int, array<string, mixed>> $apRows
	 */
	private function payGuard(array $apRows): SupplierInvoicePayGuard {
		$objectService = new class($apRows) {
			public function __construct(private array $rows) {
			}
			public function setRegister($r): self {
				return $this;
			}
			public function setSchema($s): self {
				return $this;
			}
			public function findAll(array $config = [], bool $rbac = true, bool $multi = true): array {
				$id = ($config['filters']['id'] ?? '');
				return array_values(array_filter($this->rows, fn (array $r): bool => $r['id'] === $id));
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return new SupplierInvoicePayGuard($container, $settings, $this->createMock(LoggerInterface::class));
	}

	public function testInvoiceIsPaidOnlyWhenItsApTransactionIsPaid(): void {
		$guard = $this->payGuard([['id' => 'ap-paid', 'state' => 'paid'], ['id' => 'ap-open', 'state' => 'issued']]);
		self::assertTrue($guard->check(['apTransactionId' => 'ap-paid'], 'pay', 'ann')->isAllowed());
		self::assertFalse($guard->check(['apTransactionId' => 'ap-open'], 'pay', 'ann')->isAllowed());
		self::assertFalse($guard->check(['apTransactionId' => 'ap-missing'], 'pay', 'ann')->isAllowed());
		self::assertFalse($guard->check([], 'pay', 'ann')->isAllowed());
	}

	private function chainListener(bool $withUser = true): PurchaseOrderDecisionLockListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($withUser === true ? $this->createMock(IUser::class) : null);
		return new PurchaseOrderDecisionLockListener($resolver, new ServerWriteAuthority(), $session);
	}

	private function po(array $chain): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::PO);
		$entity->setObject(['approvalChain' => $chain]);
		return $entity;
	}

	public function testCreatingAnOrderWithADecidedEntryIsRefused(): void {
		$event = new ObjectCreatingEvent($this->po([self::SIGNED]));
		$this->chainListener()->handle($event);
		self::assertSame(PurchaseOrderDecisionLockListener::MESSAGE, $event->getErrors()['message'] ?? null);

		$event = new ObjectCreatingEvent($this->po([['decision' => 'pending', 'userId' => '']]));
		$this->chainListener()->handle($event);
		self::assertSame([], $event->getErrors());
	}

	public function testChangingTheChainOutsideTheServiceIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->po([self::SIGNED]), $this->po([['decision' => 'pending']]));
		$this->chainListener()->handle($event);
		self::assertNotSame([], $event->getErrors());

		$event = new ObjectUpdatingEvent($this->po([self::SIGNED]), $this->po([['decision' => 'pending']]));
		(new ServerWriteAuthority())->during(PurchaseOrderApprovalGuard::PURPOSE, self::PO, fn () => $this->chainListener()->handle($event));
		self::assertSame([], $event->getErrors());

		$event = new ObjectUpdatingEvent($this->po([['decision' => 'pending']]), $this->po([['decision' => 'pending']]));
		$this->chainListener()->handle($event);
		self::assertSame([], $event->getErrors());
	}
}
