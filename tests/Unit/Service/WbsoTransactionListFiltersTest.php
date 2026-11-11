<?php

/**
 * Pins WbsoTransactionService::listTransactions() filtering: each filter
 * alone, empty and falsy filter values, inclusive date bounds and rows
 * without a date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\WbsoTransactionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * listTransactions() filters, before they are split into named checks.
 *
 * @covers \OCA\Shillinq\Service\WbsoTransactionService
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoTransactionListFiltersTest extends TestCase {

	private const ROWS = [
		['id' => 'a', 'status' => 'draft', 'transactionType' => 'invoice', 'transactionDate' => '2026-01-01', 'administrationId' => 'adm-1'],
		['id' => 'b', 'status' => 'posted', 'transactionType' => 'invoice', 'transactionDate' => '2026-02-01', 'administrationId' => 'adm-1'],
		['id' => 'c', 'status' => 'posted', 'transactionType' => 'receipt', 'transactionDate' => '2026-02-15', 'administrationId' => 'adm-1'],
		['id' => 'd', 'status' => '0', 'transactionType' => '', 'administrationId' => 'adm-1'],
		['id' => 'e', 'status' => 'posted', 'transactionType' => 'invoice', 'transactionDate' => '2026-03-01', 'administrationId' => 'adm-2'],
	];

	/**
	 * Filters and the ids they keep.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: array<int,string>}>
	 */
	public static function filters(): array {
		return [
			'none'                    => [[], ['a', 'b', 'c', 'd']],
			'empty values'            => [['status' => '', 'type' => '', 'dateFrom' => '', 'dateTo' => ''], ['a', 'b', 'c', 'd']],
			'null values'             => [['status' => null, 'dateTo' => null], ['a', 'b', 'c', 'd']],
			'status'                  => [['status' => 'posted'], ['b', 'c']],
			'status zero'             => [['status' => 0], ['d']],
			'status false'            => [['status' => false], []],
			'type'                    => [['type' => 'receipt'], ['c']],
			'from is inclusive'       => [['dateFrom' => '2026-02-01'], ['b', 'c']],
			'to is inclusive'         => [['dateTo' => '2026-02-01'], ['a', 'b', 'd']],
			'window'                  => [['dateFrom' => '2026-01-02', 'dateTo' => '2026-02-14'], ['b']],
			'from drops undated rows' => [['dateFrom' => '0'], ['a', 'b', 'c']],
		];

	}//end filters()

	/**
	 * Each filter set keeps the pinned rows, in order.
	 *
	 * @param array<string,mixed> $filters  The filters.
	 * @param array<int,string>   $expected The ids kept.
	 *
	 * @return void
	 */
	#[DataProvider('filters')]
	public function testFilters(array $filters, array $expected): void {
		$store = new class(self::ROWS) {
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
						static fn (array $row): bool => ($row['administrationId'] ?? null) === ($filters['administrationId'] ?? null)
					)
				);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$service = new WbsoTransactionService(
			appConfig: $appConfig,
			userSession: $this->createMock(IUserSession::class),
			objectService: new DuckObjectServiceAdapter($store),
		);

		$ids = array_column($service->listTransactions(administrationId: 'adm-1', filters: $filters), 'id');

		self::assertSame($expected, $ids);

	}//end testFilters()
}//end class
