<?php

/**
 * Tests the payment block endpoint's rules.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
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

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\PaymentBlockService;
use OCA\Shillinq\PaymentRun\PaymentBlockWriteScope;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * F2-S2: blocking and releasing payment needs the payment.block action, the
 * record's administration and a reason, and the person who set a block does
 * not release it.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
final class PaymentBlockServiceTest extends TestCase {

	/**
	 * Saved writes.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Whether the write scope was open while saving.
	 *
	 * @var list<bool>
	 */
	private array $scopeOpenOnSave = [];

	/**
	 * A blocker blocks an invoice of their administration, with reason and actor stored.
	 *
	 * @return void
	 */
	public function testABlockStoresTheReasonAndTheActor(): void {
		$result = $this->service(user: 'anna')->setBlock(schema: 'APTransaction', id: 'ap-1', blocked: true, reason: ' Wacht op creditnota ');

		self::assertSame(200, $result['status']);
		$object = $this->saved[0]['object'];
		self::assertTrue($object['paymentBlocked']);
		self::assertSame('Wacht op creditnota', $object['paymentBlockReason']);
		self::assertSame('anna', $object['paymentBlockedBy']);
		self::assertNotEmpty($object['paymentBlockedAt']);
		self::assertSame([true], $this->scopeOpenOnSave, 'The write runs inside the block scope.');
	}//end testABlockStoresTheReasonAndTheActor()

	/**
	 * Someone else releases, with a reason; the block reason stays for the record.
	 *
	 * @return void
	 */
	public function testAnotherUserReleasesWithAReason(): void {
		$result = $this->service(user: 'bram')->setBlock(schema: 'Payee', id: 'payee-1', blocked: false, reason: 'IBAN verified by phone');

		self::assertSame(200, $result['status']);
		$object = $this->saved[0]['object'];
		self::assertFalse($object['paymentBlocked']);
		self::assertSame('IBAN change under verification', $object['paymentBlockReason']);
		self::assertSame('IBAN verified by phone', $object['paymentReleaseReason']);
		self::assertSame('bram', $object['paymentReleasedBy']);
	}//end testAnotherUserReleasesWithAReason()

	/**
	 * The person who set the block cannot release it.
	 *
	 * @return void
	 */
	public function testTheBlockerCannotReleaseTheirOwnBlock(): void {
		$result = $this->service(user: 'anna')->setBlock(schema: 'Payee', id: 'payee-1', blocked: false, reason: 'done');

		self::assertSame(['status' => 403, 'error' => 'four-eyes'], $result);
		self::assertSame([], $this->saved);
	}//end testTheBlockerCannotReleaseTheirOwnBlock()

	/**
	 * A user without the payment.block action is refused.
	 *
	 * @return void
	 */
	public function testAUserWithoutThePaymentActionIsRefused(): void {
		$result = $this->service(user: 'carl')->setBlock(schema: 'APTransaction', id: 'ap-1', blocked: true, reason: 'x');

		self::assertSame(['status' => 403, 'error' => 'not-allowed'], $result);
		self::assertSame([], $this->saved);
	}//end testAUserWithoutThePaymentActionIsRefused()

	/**
	 * A record of another administration reads as not found.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsRecordIsNotFound(): void {
		$result = $this->service(user: 'anna')->setBlock(schema: 'APTransaction', id: 'ap-other', blocked: true, reason: 'x');

		self::assertSame(['status' => 404, 'error' => 'not-found'], $result);
		self::assertSame([], $this->saved);
	}//end testAnotherAdministrationsRecordIsNotFound()

	/**
	 * Both directions need a reason.
	 *
	 * @return void
	 */
	public function testAReasonIsRequiredBothWays(): void {
		$service = $this->service(user: 'bram');

		self::assertSame('reason-required', $service->setBlock(schema: 'APTransaction', id: 'ap-1', blocked: true, reason: '  ')['error']);
		self::assertSame('reason-required', $service->setBlock(schema: 'Payee', id: 'payee-1', blocked: false, reason: '')['error']);
		self::assertSame([], $this->saved);
	}//end testAReasonIsRequiredBothWays()

	/**
	 * Only invoices and suppliers can be blocked, and not twice.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndRepeatsAreRefused(): void {
		$service = $this->service(user: 'bram');

		self::assertSame(404, $service->setBlock(schema: 'ARInvoice', id: 'ap-1', blocked: true, reason: 'x')['status']);
		self::assertSame('already-blocked', $service->setBlock(schema: 'Payee', id: 'payee-1', blocked: true, reason: 'x')['error']);
		self::assertSame('not-blocked', $service->setBlock(schema: 'APTransaction', id: 'ap-1', blocked: false, reason: 'x')['error']);
	}//end testOtherSchemasAndRepeatsAreRefused()

	/**
	 * Build the service with anna and bram holding payment.block in adm-1.
	 *
	 * @param string $user The caller.
	 *
	 * @return PaymentBlockService
	 */
	private function service(string $user): PaymentBlockService {
		$scope   = new PaymentBlockWriteScope();
		$objects = new class(
			[
				'APTransaction' => [
					['id' => 'ap-1', 'administrationId' => 'adm-1', 'paymentBlocked' => false],
					['id' => 'ap-other', 'administrationId' => 'adm-2', 'paymentBlocked' => false],
				],
				'Payee' => [
					['id' => 'payee-1', 'administrationId' => 'adm-1', 'paymentBlocked' => true, 'paymentBlockReason' => 'IBAN change under verification', 'paymentBlockedBy' => 'anna'],
				],
			],
			$this->saved
		) extends InMemoryObjectServiceStub {
			/**
			 * Called before each save.
			 *
			 * @var \Closure|null
			 */
			public ?\Closure $onSave = null;

			/**
			 * Record the scope state, then save.
			 *
			 * @param array $object The payload.
			 * @param mixed ...$rest The other arguments.
			 *
			 * @return \OCA\OpenRegister\Contract\ObjectEntityInterface
			 */
			public function saveObject(array $object, ...$rest): \OCA\OpenRegister\Contract\ObjectEntityInterface {
				if ($this->onSave !== null) {
					($this->onSave)();
				}

				return parent::saveObject($object, ...$rest);
			}//end saveObject()
		};
		$objects->onSave = function () use ($scope): void {
			$this->scopeOpenOnSave[] = $scope->isOpen();
		};

		$administrations = $this->createMock(AdministrationContextService::class);
		$administrations->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-1');

		$iUser = $this->createMock(IUser::class);
		$iUser->method('getUID')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($iUser);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(json_encode([PaymentActionAuthorizer::ACTION_BLOCK => ['payables']]));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $group === 'payables' && in_array($uid, ['anna', 'bram'], true));

		return new PaymentBlockService(
			objectService: $objects,
			administrations: $administrations,
			authorizer: new PaymentActionAuthorizer(appConfig: $config, userSession: $session, groupManager: $groups),
			scope: $scope,
		);
	}//end service()
}//end class
