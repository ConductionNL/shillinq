<?php

/**
 * Golden test for VatNumberCheckController::check.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\Shillinq\Controller\VatNumberCheckController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Tax\VatNumberCheck;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the check, allowed and refused, and every lookup behind it in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class VatNumberCheckControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Check one record.
	 *
	 * @param string              $type  The record type.
	 * @param array<string,mixed> $setup user, record (array|null|'throw'), member, refuse (bool).
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function check(string $type, array $setup=[]): array {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturnCallback(
			function () use ($setup): ?string {
				$this->calls[] = ['currentUserId'];
				if (array_key_exists('user', $setup) === true) {
					return $setup['user'];
				}

				return 'u1';
			}
		);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$record = ['id' => 'r1', 'administrationId' => 'a1'];
		if (array_key_exists('record', $setup) === true) {
			$record = $setup['record'];
		}

		$records = $this->createMock(AssetRecords::class);
		$records->method('find')->willReturnCallback(
			function (string $schema, string $id) use ($record): ?array {
				$this->calls[] = ['find', $schema, $id];
				if ($record === 'throw') {
					throw new RuntimeException('store down');
				}

				return $record;
			}
		);
		$refuse = ($setup['refuse'] ?? false);
		$vat    = $this->createMock(VatNumberCheck::class);
		$vat->method('checkRecord')->willReturnCallback(
			function (string $type, array $given) use ($refuse): array {
				$this->calls[] = ['checkRecord', $type, $given['id']];
				if ($refuse === true) {
					throw new DomainException('No VAT number on file.');
				}

				return ['valid' => true];
			}
		);

		$response = (new VatNumberCheckController($this->createMock(IRequest::class), $context, $records, $vat))->check($type, 'r1');
		return [$response->getStatus(), $response->getData()];
	}//end check()

	/**
	 * No session is a 401; an unknown type, record, unreadable or foreign record a 404.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$notFound = [404, ['error' => 'Not found']];
		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: $this->check(type: 'customer', setup: ['user' => null]));
		self::assertSame(expected: $notFound, actual: $this->check(type: 'employee'));
		self::assertSame(expected: $notFound, actual: $this->check(type: 'customer', setup: ['record' => null]));
		self::assertSame(expected: $notFound, actual: $this->check(type: 'supplier', setup: ['record' => 'throw']));
		self::assertSame(expected: $notFound, actual: $this->check(type: 'customer', setup: ['member' => false]));
		self::assertSame(expected: $notFound, actual: $this->check(type: 'customer', setup: ['record' => ['id' => 'r1'], 'member' => false]));
		$expected = [
			['currentUserId'],
			['currentUserId'],
			['currentUserId'],
			['find', 'CustomerMaster', 'r1'],
			['currentUserId'],
			['find', 'Payee', 'r1'],
			['currentUserId'],
			['find', 'CustomerMaster', 'r1'],
			['canAccess', 'a1'],
			['currentUserId'],
			['find', 'CustomerMaster', 'r1'],
			['canAccess', ''],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testRefusals()

	/**
	 * A readable record of the caller's administration is checked; a refusal is a 422.
	 *
	 * @return void
	 */
	public function testChecks(): void {
		self::assertSame(expected: [200, ['valid' => true]], actual: $this->check(type: 'supplier'));
		self::assertSame(expected: [422, ['error' => 'No VAT number on file.']], actual: $this->check(type: 'customer', setup: ['refuse' => true]));
		$expected = [
			['currentUserId'],
			['find', 'Payee', 'r1'],
			['canAccess', 'a1'],
			['checkRecord', 'supplier', 'r1'],
			['currentUserId'],
			['find', 'CustomerMaster', 'r1'],
			['canAccess', 'a1'],
			['checkRecord', 'customer', 'r1'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testChecks()
}//end class
