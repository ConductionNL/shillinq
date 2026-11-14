<?php

/**
 * Pins WbsoAccountService::assertHierarchy(): every refusal with its message,
 * the depth limit at its boundary, and the chains it accepts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\WbsoAccountService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * assertHierarchy() before it is split into named checks.
 *
 * @covers \OCA\Shillinq\Service\WbsoAccountService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoAccountHierarchyTest extends TestCase {

	/**
	 * Build the service over $accounts.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account rows.
	 *
	 * @return WbsoAccountService
	 */
	private function service(array $accounts): WbsoAccountService {
		$store = new class($accounts) {
			/**
			 * @param array<int,array<string,mixed>> $rows Rows.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * @param string $r Register.
			 *
			 * @return static
			 */
			public function setRegister(string $r): static {
				return $this;
			}

			/**
			 * @param string $s Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $s): static {
				return $this;
			}

			/**
			 * @param array<string,mixed> $p Query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $p = []): array {
				$filters = ($p['filters'] ?? []);
				return array_values(
					array_filter(
						$this->rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $k => $v) {
								if (($row[$k] ?? null) !== $v) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new WbsoAccountService(appConfig: $appConfig, objectService: new DuckObjectServiceAdapter($store));

	}//end service()

	/**
	 * A chain 1 <- 2 <- 3 <- 4 (4 deepest), all active, plus odd rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function chart(): array {
		return [
			['accountNumber' => '1', 'parentAccountNumber' => '', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => '2', 'parentAccountNumber' => '1', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => '3', 'parentAccountNumber' => '2', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => '4', 'parentAccountNumber' => '3', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => '9', 'parentAccountNumber' => 'gone', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => 'x', 'status' => 'archived', 'administrationId' => 'adm-1'],
			['accountNumber' => '', 'parentAccountNumber' => '1', 'status' => 'active', 'administrationId' => 'adm-1'],
			['parentAccountNumber' => '1', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => 'other', 'status' => 'active', 'administrationId' => 'adm-2'],
			['accountNumber' => 'a', 'parentAccountNumber' => 'b', 'status' => 'active', 'administrationId' => 'adm-1'],
			['accountNumber' => 'b', 'parentAccountNumber' => 'a', 'status' => 'active', 'administrationId' => 'adm-1'],
		];

	}//end chart()

	/**
	 * Requests and the refusal message, or null when accepted.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string|null}>
	 */
	public static function requests(): array {
		return [
			'no parent'                  => ['new', '', null],
			'own parent'                 => ['7', '7', 'parentAccountNumber must not equal the account itself'],
			'unknown parent'             => ['7', '8', 'parentAccountNumber does not exist in this administration'],
			'other administration'       => ['7', 'other', 'parentAccountNumber does not exist in this administration'],
			'inactive parent'            => ['7', 'x', 'parentAccountNumber refers to an account that is not active'],
			'top-level parent'           => ['7', '1', null],
			'depth 5 allowed'            => ['7', '4', null],
			'depth 5 with chain edit'    => ['5', '4', null],
			'parent chain to missing'    => ['7', '9', null],
			'moving an ancestor below'   => ['2', '4', 'Circular parent reference detected'],
			'cycle above'                => ['7', 'a', 'Circular parent reference detected'],
		];

	}//end requests()

	/**
	 * Each request is accepted or refused with its message.
	 *
	 * @param string      $account The account.
	 * @param string      $parent  The requested parent.
	 * @param string|null $message The refusal, null when accepted.
	 *
	 * @return void
	 */
	#[DataProvider('requests')]
	public function testRequest(string $account, string $parent, ?string $message): void {
		$service = $this->service(self::chart());
		try {
			$service->assertHierarchy(administrationId: 'adm-1', accountNumber: $account, parent: $parent);
			self::assertNull($message);
		} catch (InvalidArgumentException $e) {
			self::assertSame($message, $e->getMessage());
		}

	}//end testRequest()

	/**
	 * One level deeper than the limit is refused with the limit in the message.
	 *
	 * @return void
	 */
	public function testDepthBeyondTheLimitIsRefused(): void {
		$chart = self::chart();
		$chart[] = ['accountNumber' => '5', 'parentAccountNumber' => '4', 'status' => 'active', 'administrationId' => 'adm-1'];
		$service = $this->service($chart);

		$this->expectExceptionMessage('Hierarchy depth must not exceed 5 levels');
		$service->assertHierarchy(administrationId: 'adm-1', accountNumber: '6', parent: '5');

	}//end testDepthBeyondTheLimitIsRefused()
}//end class
