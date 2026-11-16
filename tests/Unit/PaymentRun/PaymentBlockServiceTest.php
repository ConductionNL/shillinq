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
 * F2-S2: blocking and releasing payment is open to whoever may write the record
 * under OpenRegister's access rules (a bookkeeper, REQ-BPR-003/004), within
 * the record's administration and with a reason.
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
	 * The named arguments of each save.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saveArguments = [];

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
		self::assertSame('IBAN verified by phone', $object['paymentBlockReleaseReason']);
		self::assertSame('bram', $object['paymentReleasedBy']);
	}//end testAnotherUserReleasesWithAReason()

	/**
	 * The bookkeeper who set the block may release it, as REQ-BPR-003 allows.
	 *
	 * @return void
	 */
	public function testTheBlockerMayReleaseTheirOwnBlock(): void {
		$result = $this->service(user: 'anna')->setBlock(schema: 'Payee', id: 'payee-1', blocked: false, reason: 'done');

		self::assertSame(200, $result['status']);
		self::assertSame('anna', $this->saved[0]['object']['paymentReleasedBy']);
	}//end testTheBlockerMayReleaseTheirOwnBlock()

	/**
	 * The write runs under the caller's own OpenRegister rights, so a user who may not write the record is refused there.
	 *
	 * @return void
	 */
	public function testTheSaveRunsUnderTheCallersRights(): void {
		$result = $this->service(user: 'carl')->setBlock(schema: 'APTransaction', id: 'ap-1', blocked: true, reason: 'x');

		self::assertSame(200, $result['status']);
		self::assertCount(1, $this->saveArguments);
		self::assertTrue($this->saveArguments[0]['_rbac']);
		self::assertTrue($this->saveArguments[0]['_multitenancy']);
	}//end testTheSaveRunsUnderTheCallersRights()

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
	 * Build the service for a caller in adm-1.
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
					($this->onSave)($rest);
				}

				return parent::saveObject($object, ...$rest);
			}//end saveObject()
		};
		$objects->onSave = function (array $rest) use ($scope): void {
			$this->scopeOpenOnSave[] = $scope->isOpen();
			$this->saveArguments[]   = $rest;
		};

		$administrations = $this->createMock(AdministrationContextService::class);
		$administrations->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-1');

		$iUser = $this->createMock(IUser::class);
		$iUser->method('getUID')->willReturn($user);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($iUser);
		$config = $this->createMock(IAppConfig::class);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new PaymentBlockService(
			objectService: $objects,
			administrations: $administrations,
			authorizer: new PaymentActionAuthorizer(appConfig: $config, userSession: $session, groupManager: $groups),
			scope: $scope,
		);
	}//end service()
}//end class
