<?php

/**
 * Unit tests for RevenueTaskFieldLookup.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md#req-sopr-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RevenueTaskFieldLookup;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Account number to task field.
 */
final class RevenueTaskFieldLookupTest extends TestCase {

	/**
	 * Build a lookup over fixed Account rows.
	 *
	 * @param array<int,mixed> $rows The Account rows.
	 * @param string $register The configured register slug.
	 *
	 * @return RevenueTaskFieldLookup The lookup.
	 */
	private function lookup(array $rows, string $register = 'shillinq'): RevenueTaskFieldLookup {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn($rows);

		return new RevenueTaskFieldLookup($objectService, $this->appConfig($register), new NullLogger());
	}//end lookup()

	/**
	 * An app config answering the register slug.
	 *
	 * @param string $register The slug.
	 *
	 * @return IAppConfig The config.
	 */
	private function appConfig(string $register): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);

		return $appConfig;
	}//end appConfig()

	/**
	 * A blank account number is null without a read.
	 *
	 * @return void
	 */
	public function testBlankAccountNumberIsNull(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('findAll');
		$lookup = new RevenueTaskFieldLookup($objectService, $this->appConfig('shillinq'), new NullLogger());

		self::assertNull($lookup->forAccount(''));
	}//end testBlankAccountNumberIsNull()

	/**
	 * The matching account's trimmed task field is returned, skipping rows
	 * of other accounts and non-array rows.
	 *
	 * @return void
	 */
	public function testReturnsTrimmedTaskFieldOfMatchingAccount(): void {
		$lookup = $this->lookup(
			[
				'not-a-row',
				['accountNumber' => '8100', 'taskField' => '9.9'],
				['accountNumber' => '8000', 'taskField' => ''],
				['accountNumber' => '8000', 'taskField' => ' 8.3 '],
			]
		);

		self::assertSame('8.3', $lookup->forAccount('8000'));
	}//end testReturnsTrimmedTaskFieldOfMatchingAccount()

	/**
	 * Entity-shaped rows are unwrapped through getObject().
	 *
	 * @return void
	 */
	public function testUnwrapsEntityRows(): void {
		$entity = new class {

			/**
			 * The row.
			 *
			 * @return array<string,string> The row.
			 */
			public function getObject(): array {
				return ['accountNumber' => '8000', 'taskField' => '8.1'];
			}//end getObject()
		};

		self::assertSame('8.1', $this->lookup([$entity])->forAccount('8000'));
	}//end testUnwrapsEntityRows()

	/**
	 * An unknown account, or one without a task field, is null.
	 *
	 * @return void
	 */
	public function testUnknownOrEmptyIsNull(): void {
		self::assertNull($this->lookup([])->forAccount('8000'));
		self::assertNull($this->lookup([['accountNumber' => '8000']], '')->forAccount('8000'));
	}//end testUnknownOrEmptyIsNull()

	/**
	 * A failed read is swallowed and answers null.
	 *
	 * @return void
	 */
	public function testFailedReadIsNull(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willThrowException(new RuntimeException('down'));
		$lookup = new RevenueTaskFieldLookup($objectService, $this->appConfig('shillinq'), new NullLogger());

		self::assertNull($lookup->forAccount('8000'));
	}//end testFailedReadIsNull()
}//end class
