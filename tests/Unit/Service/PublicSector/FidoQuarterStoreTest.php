<?php

/**
 * Unit tests for how FidoQuarter reads and writes the store: credit lines on
 * the bank account, transactions without a posting date, entity rows, a
 * limit record without an id and an empty register setting.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
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

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;


use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\PublicSector\FidoQuarter;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../InMemoryObjectService.php';

/**
 * Tests for FidoQuarter's reads and writes.
 */
#[CoversClass(FidoQuarter::class)]
final class FidoQuarterStoreTest extends TestCase {

	/**
	 * A credit on the bank account lowers the balance, and a posted
	 * transaction without a posting date is left out of every month end.
	 *
	 * @return void
	 */
	public function testCreditLinesLowerTheBalanceAndUndatedPostingsAreSkipped(): void {
		$os = new InMemoryObjectService();
		$os->setSchema('KasgeldLimiet')->saveObject(
			['id' => 'kl-2026', 'auditYear' => 2026, 'organisationId' => 'gem-1', 'baseBudget' => 100000000.0, 'percentage' => 8.5]
		);
		$os->setSchema('Account')->saveObject(['administrationId' => 'gem-1', 'accountNumber' => '1100', 'accountType' => 'bank']);
		$os->setSchema('Lening')->saveObject(
			['organisationId' => 'gem-1', 'type' => 'kasgeld', 'principal' => 6000000.0, 'issueDate' => '2026-06-01', 'maturityDate' => '2026-12-01', 'status' => 'recorded']
		);
		$os->setSchema('GLTransaction')->saveObject(
			['id' => 'tx-1', 'administrationId' => 'gem-1', 'state' => 'posted', 'postingDate' => '2026-07-15']
		);
		$os->setSchema('GLLine')->saveObject(['transactionId' => 'tx-1', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 1000000.0]);
		$os->setSchema('GLLine')->saveObject(['transactionId' => 'tx-1', 'accountNumber' => '1100', 'side' => 'credit', 'amount' => 400000.0]);
		$os->setSchema('GLTransaction')->saveObject(
			['id' => 'tx-undated', 'administrationId' => 'gem-1', 'state' => 'posted', 'postingDate' => '']
		);
		$os->setSchema('GLLine')->saveObject(['transactionId' => 'tx-undated', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 5000000.0]);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$service = new FidoQuarter(appConfig: $appConfig, objectService: new DuckObjectServiceAdapter($os));

		$result = $service->compute(organisationId: 'gem-1', year: '2026', quarter: 3);

		// 6.0M short-term debt less 0.6M on the bank at every month end.
		self::assertSame(5400000.0, $result['cashStatus']['exposure']);
		self::assertSame(3100000.0, $result['cashStatus']['headroom']);
		self::assertSame('binnen-norm', $result['cashStatus']['status']);

	}//end testCreditLinesLowerTheBalanceAndUndatedPostingsAreSkipped()

	/**
	 * Rows that come back as entities are read through jsonSerialize, a
	 * record without an id is saved as a new object, and an empty register
	 * setting falls back to the app's own register.
	 *
	 * @return void
	 */
	public function testEntityRowsAreReadAndARecordWithoutIdIsSavedUnderTheDefaultRegister(): void {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$limit = new class implements \JsonSerializable {
			/**
			 * The limit record without an id.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['auditYear' => 2026, 'organisationId' => 'gem-1', 'baseBudget' => 1000000.0, 'percentage' => 10.0];
			}
		};

		$schema = '';
		$os = $this->createMock(ObjectServiceInterface::class);
		$os->expects($this->atLeastOnce())->method('setRegister')->with('shillinq')->willReturnSelf();
		$os->method('setSchema')->willReturnCallback(
			function (string|int $slug) use (&$schema, $os) {
				$schema = (string)$slug;
				return $os;
			}
		);
		$os->method('findAll')->willReturnCallback(
			function () use (&$schema, $limit): array {
				return ($schema === 'KasgeldLimiet') ? [$limit] : [];
			}
		);

		$entity = $this->createStub(ObjectEntityInterface::class);
		$saved = [];
		$os->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null) use (&$saved, $entity) {
				$saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $entity;
			}
		);

		$result = (new FidoQuarter(appConfig: $appConfig, objectService: $os))->compute(organisationId: 'gem-1', year: '2026', quarter: 1);

		self::assertSame(100000.0, $result['cashStatus']['ceiling']);
		self::assertSame(100000.0, $result['cashStatus']['headroom']);
		self::assertCount(1, $saved);
		self::assertSame('KasgeldLimiet', $saved[0]['schema']);
		self::assertSame('shillinq', $saved[0]['register']);
		self::assertNull($saved[0]['uuid']);
		self::assertSame(100000.0, $saved[0]['object']['calculatedCeiling']);
		self::assertSame('binnen-norm', $saved[0]['object']['status']);

	}//end testEntityRowsAreReadAndARecordWithoutIdIsSavedUnderTheDefaultRegister()
}//end class
