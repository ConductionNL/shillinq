<?php

/**
 * ReceiptScopeTest — administration, caller and purchase order checks of receipts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\ReceiptScope;
use OCA\Shillinq\Service\RecordStoreLog;
use OCA\Shillinq\Service\RegisterBinding;
use OCA\Shillinq\Service\SchemaRecordStore;
use OCA\Shillinq\Support\RegisterSlug;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers OCA\Shillinq\Service\ReceiptScope.
 */
#[CoversClass(ReceiptScope::class)]
#[UsesClass(SchemaRecordStore::class)]
#[UsesClass(RegisterBinding::class)]
#[UsesClass(RecordStoreLog::class)]
#[UsesClass(RegisterSlug::class)]
final class ReceiptScopeTest extends TestCase {

	/**
	 * The filters of every purchase order query, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * A scope over a context and a register holding the given purchase order ids.
	 *
	 * @param bool        $canAccess Whether the administration is accessible.
	 * @param string|null $userId    The session user.
	 * @param list<string> $poIds    Purchase orders that exist in the administration.
	 *
	 * @return ReceiptScope The scope.
	 */
	private function scope(bool $canAccess=true, ?string $userId='alice', array $poIds=[]): ReceiptScope {
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturn($canAccess);
		$context->method('currentUserId')->willReturn($userId);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => $default
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[]) use ($poIds): array {
				$this->queries[] = $config['filters'];
				if (in_array($config['filters']['id'], $poIds, true) === true) {
					return [['id' => $config['filters']['id']]];
				}

				return [];
			}
		);

		$records = new SchemaRecordStore(
			binding: new RegisterBinding(objectService: $objects, appConfig: $config),
			log: new RecordStoreLog(logger: $this->createMock(LoggerInterface::class), source: 'ReceiptScopeTest')
		);

		return new ReceiptScope(administrationContext: $context, records: $records);

	}//end scope()

	/**
	 * An empty administration id is refused before access is checked.
	 *
	 * @return void
	 */
	public function testAnEmptyAdministrationIsRequired(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('administrationId is required');

		$this->scope()->assertAccess(administrationId: '');

	}//end testAnEmptyAdministrationIsRequired()

	/**
	 * An inaccessible administration reads as not found.
	 *
	 * @return void
	 */
	public function testAnInaccessibleAdministrationIsNotFound(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');

		$this->scope(canAccess: false)->assertAccess(administrationId: 'adm-1');

	}//end testAnInaccessibleAdministrationIsNotFound()

	/**
	 * An accessible administration passes.
	 *
	 * @return void
	 */
	public function testAnAccessibleAdministrationPasses(): void {
		$this->scope()->assertAccess(administrationId: 'adm-1');

		self::assertSame([], $this->queries);

	}//end testAnAccessibleAdministrationPasses()

	/**
	 * The session user is returned; without one the call is refused.
	 *
	 * @return void
	 */
	public function testTheSessionUserIsRequired(): void {
		self::assertSame('alice', $this->scope()->requireSessionUser());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Authenticated user is required');

		$this->scope(userId: null)->requireSessionUser();

	}//end testTheSessionUserIsRequired()

	/**
	 * Every purchase order is looked up inside the administration.
	 *
	 * @return void
	 */
	public function testEveryPurchaseOrderIsLookedUpInTheAdministration(): void {
		$this->scope(poIds: ['po-1', 'po-2'])->assertPurchaseOrdersAccessible(administrationId: 'adm-1', poIds: ['po-1', 'po-2']);

		self::assertSame(
			[['id' => 'po-1', 'administrationId' => 'adm-1'], ['id' => 'po-2', 'administrationId' => 'adm-1']],
			$this->queries
		);

	}//end testEveryPurchaseOrderIsLookedUpInTheAdministration()

	/**
	 * A purchase order outside the administration reads as not found.
	 *
	 * @return void
	 */
	public function testAMissingPurchaseOrderIsNotFound(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Purchase order not found');

		$this->scope(poIds: ['po-1'])->assertPurchaseOrdersAccessible(administrationId: 'adm-1', poIds: ['po-1', 'po-9']);

	}//end testAMissingPurchaseOrderIsNotFound()
}//end class
