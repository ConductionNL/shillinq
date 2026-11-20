<?php

/**
 * Unit tests for the ledger pivot's account groups, customer names and line limit.
 *
 * An account without a parent is grouped by its account type; an account
 * the chart does not know has no group. A customer without a legal name is
 * named by its trade name. A ledger longer than the line limit is cut there
 * and the pivot says it was truncated.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Pivot
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

namespace OCA\Shillinq\Tests\Unit\Service\Pivot;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Pivot\LedgerPivotRun;
use OCA\Shillinq\Service\Pivot\LedgerPivotService;
use OCA\Shillinq\Service\Pivot\PivotLabels;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Groups, names and the line limit of the pivot.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(LedgerPivotService::class)]
#[UsesClass(LedgerPivotRun::class)]
#[UsesClass(PivotLabels::class)]
final class LedgerPivotGroupsAndLimitsTest extends TestCase {

	private const ADM = 'adm-1';

	/**
	 * The register location.
	 *
	 * @return RegisterLocation
	 */
	private function location(): RegisterLocation {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		return $location;
	}//end location()

	/**
	 * A counted result line.
	 *
	 * @param string               $tx      The transaction.
	 * @param string               $account The account.
	 * @param float                $signed  The signed amount.
	 * @param array<string, mixed> $extra   Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private function line(string $tx, string $account, float $signed, array $extra = []): array {
		return array_merge(
			['administrationId' => self::ADM, 'transactionId' => $tx, 'accountNumber' => $account, 'signedAmount' => $signed, 'accountClass' => 'pnl', 'countsInResult' => true],
			$extra
		);
	}//end line()

	/**
	 * An account without a parent groups under its type; an unknown account under no group.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutAParentGroupsByItsType(): void {
		$data = [
			'GLTransaction' => [['id' => 'tx-1', 'administrationId' => self::ADM, 'state' => 'posted', 'postingDate' => '2026-02-10']],
			'GLLine' => [
				$this->line('tx-1', '8000', 100.0),
				$this->line('tx-1', '8100', 50.0),
				$this->line('tx-1', '4000', -30.0),
				$this->line('tx-1', '9999', -5.0),
			],
			'Account' => [
				['administrationId' => self::ADM, 'accountNumber' => '80', 'name' => 'Omzet'],
				['administrationId' => self::ADM, 'accountNumber' => '8000', 'name' => 'Omzet advies', 'parentAccountNumber' => '80'],
				['administrationId' => self::ADM, 'accountNumber' => '8100', 'name' => 'Overige omzet', 'accountType' => 'revenue'],
				['administrationId' => self::ADM, 'accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'expenses'],
			],
		];

		$pivot = (new LedgerPivotService(new InMemoryObjectServiceStub($data), $this->location()))
			->pivot(self::ADM, 'accountGroup', 'period', '2026-01-01', '2026-12-31');

		$labels = array_column($pivot['rows'], 'label', 'key');
		self::assertSame('Omzet', $labels['80']);
		self::assertSame('revenue', $labels['type:revenue']);
		self::assertSame('expenses', $labels['type:expenses']);
		self::assertArrayHasKey('', $labels);
		self::assertSame(['2026-02' => 100.0], $pivot['cells']['80']);
		self::assertSame(['2026-02' => 50.0], $pivot['cells']['type:revenue']);
		self::assertSame(['2026-02' => -30.0], $pivot['cells']['type:expenses']);
		self::assertSame(['2026-02' => -5.0], $pivot['cells']['']);
		self::assertSame(115.0, $pivot['total']);
	}//end testAnAccountWithoutAParentGroupsByItsType()

	/**
	 * A customer without a legal name is shown by its trade name.
	 *
	 * @return void
	 */
	public function testACustomerWithoutALegalNameShowsItsTradeName(): void {
		$data = [
			'GLTransaction' => [['id' => 'tx-1', 'administrationId' => self::ADM, 'state' => 'posted', 'postingDate' => '2026-02-10']],
			'GLLine' => [
				$this->line('tx-1', '1300', 0.0, ['accountClass' => 'balance', 'subLedgerType' => 'ar', 'subLedgerRef' => 'cust-1']),
				$this->line('tx-1', '8000', 75.0),
			],
			'CustomerMaster' => [['id' => 'cust-1', 'administrationId' => self::ADM, 'tradeName' => 'De Korenbloem']],
		];

		$pivot = (new LedgerPivotService(new InMemoryObjectServiceStub($data), $this->location()))
			->pivot(self::ADM, 'customer', 'period', '2026-01-01', '2026-12-31');

		self::assertSame([['key' => 'cust-1', 'label' => 'De Korenbloem']], $pivot['rows']);
		self::assertSame(75.0, $pivot['rowTotals']['cust-1']);
	}//end testACustomerWithoutALegalNameShowsItsTradeName()

	/**
	 * A ledger past the line limit is cut there and reported truncated.
	 *
	 * @return void
	 */
	public function testALedgerPastTheLineLimitIsTruncated(): void {
		$line = new class($this->line('tx-1', '8000', 1.0)) implements JsonSerializable {
			/**
			 * @param array<string, mixed> $payload The payload.
			 */
			public function __construct(
				private readonly array $payload,
			) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};

		$schema = '';
		$pages = 0;
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string $name) use (&$schema, $objects) {
				$schema = $name;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$schema, &$pages, $line): array {
				if ($schema === 'GLTransaction') {
					return [['id' => 'tx-1', 'state' => 'posted', 'postingDate' => '2026-02-10']];
				}

				if ($schema !== 'GLLine') {
					return [];
				}

				$pages++;
				// Every page full: the ledger never ends by itself.
				return array_fill(0, $config['limit'], $line);
			}
		);

		$pivot = (new LedgerPivotService($objects, $this->location()))->pivot(self::ADM, 'account', 'period', '2026-01-01', '2026-12-31');

		self::assertTrue($pivot['truncated']);
		self::assertSame(LedgerPivotService::MAX_LINES / 500, $pages);
		self::assertSame((float)LedgerPivotService::MAX_LINES, $pivot['total']);
	}//end testALedgerPastTheLineLimitIsTruncated()
}//end class
