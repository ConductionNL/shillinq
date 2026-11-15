<?php

/**
 * Golden test for BudgetProjectionReader::loadContext.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetProjectionReader;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the projection context with and without LedgerGroups.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetProjectionContextGoldenTest extends TestCase {

	/**
	 * Every context matches the golden fixture.
	 *
	 * @return void
	 */
	public function testContextsMatchGolden(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$reader = new BudgetProjectionReader(appConfig: $appConfig, logger: new NullLogger(), objectService: new FilteredObjectServiceStub($this->data()));
		$result = [
			'withGroups' => $reader->loadContext('adm-1', true),
			'withoutGroups' => $reader->loadContext('adm-1'),
			'otherAdmin' => $reader->loadContext('adm-2', true),
		];

		$file = __DIR__.'/fixtures/budget-projection-context.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('PROJECTION_CONTEXT_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testContextsMatchGolden()

	/**
	 * The fixture.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function data(): array {
		$tx = static fn (string $id, string $date, string $state): array => [
			'id' => $id,
			'administrationId' => 'adm-1',
			'state' => $state,
			'postingDate' => $date,
		];
		return [
			'Account' => [
				['accountNumber' => '1000', 'accountType' => 'assets', 'administrationId' => 'adm-1'],
				['accountNumber' => '4000', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
				['accountNumber' => '8000', 'administrationId' => 'adm-1'],
				['accountNumber' => '', 'accountType' => 'revenue', 'administrationId' => 'adm-1'],
				['accountNumber' => '9000', 'accountType' => 'revenue', 'administrationId' => 'adm-2'],
			],
			'GLTransaction' => [
				$tx('tx-1', '2026-01-15', 'posted'),
				$tx('tx-2', '2026-03-10', 'posted'),
				$tx('tx-3', '2026-04-01', 'draft'),
			],
			'GLLine' => [
				['transactionId' => 'tx-1', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 100.0],
				['transactionId' => 'tx-2', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 50.5],
				['transactionId' => 'tx-2', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 200.0],
				['transactionId' => 'tx-3', 'accountNumber' => '1000', 'side' => 'debit', 'amount' => 9.0],
			],
			'LedgerGroup' => [
				[
					'id' => 'g-1',
					'administrationId' => 'adm-1',
					'code' => 'KOST',
					'accountRanges' => [['from' => '4000', 'to' => '4999']],
					'includedAccountNumbers' => ['1000'],
					'excludedAccountNumbers' => [],
				],
				[
					'id' => 'g-2',
					'administrationId' => 'adm-1',
					'code' => 'OMZ',
					'parentLedgerGroupId' => 'KOST',
					'accountRanges' => [],
					'includedAccountNumbers' => ['8000'],
					'excludedAccountNumbers' => [],
				],
			],
		];

	}//end data()
}//end class
