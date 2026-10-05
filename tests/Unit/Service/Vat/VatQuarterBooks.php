<?php

/**
 * The Korenbloem quarter as test books: documents posted through the real
 * posting mapper, plus hand-made journal entries, for the VAT return tests.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Vat;

use OCA\Shillinq\Lifecycle\Action\MaterialiseGlTransactionAction;
use OCA\Shillinq\Tests\Unit\Lifecycle\Action\InMemoryObjectStore;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Builds the books of a Q3 2026 VAT return. Used by a TestCase.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
trait VatQuarterBooks {

	/**
	 * An app config that answers every key with its default.
	 *
	 * @return IAppConfig
	 */
	private function defaultsConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		return $appConfig;
	}//end defaultsConfig()

	/**
	 * A sale of EUR 10,000 at the low tariff with EUR 900 VAT.
	 *
	 * @param string $id     The invoice id.
	 * @param array  $change Fields to change.
	 *
	 * @return array{0: array<string,mixed>, 1: string}
	 */
	private function sale(string $id='ar-kb-1', array $change=[]): array {
		return [
			array_merge(
				[
					'id' => $id, 'invoiceNumber' => '2026-0301', 'invoiceDate' => '2026-08-14',
					'administrationId' => 'adm-kb', 'currency' => 'EUR', 'grossAmount' => 10900.0, 'netAmount' => 10000.0, 'vatAmount' => 900.0,
					'invoiceLines' => [['itemName' => 'Brood en banket', 'netAmount' => 10000.0, 'vatCategory' => 'S', 'vatRate' => 9]],
					'lifecycleState' => 'issued',
				],
				$change
			),
			'ARInvoice',
		];
	}//end sale()

	/**
	 * Purchases of EUR 4,000 at the high tariff and EUR 2,000 at the low tariff with EUR 1,020 input VAT.
	 *
	 * @return array{0: array<string,mixed>, 1: string}
	 */
	private function purchase(): array {
		return [
			[
				'id' => 'ap-kb-1', 'invoiceNumber' => 'INK-301', 'invoiceDate' => '2026-08-02', 'administrationId' => 'adm-kb',
				'totalAmount' => 7020.0, 'taxAmount' => 1020.0,
				'lines' => [
					['accountNumber' => '7000', 'amount' => 4000.0, 'description' => 'Oven', 'taxCode' => 'high'],
					['accountNumber' => '7010', 'amount' => 2000.0, 'description' => 'Meel', 'taxCode' => 'low'],
				],
				'state' => 'posted',
			],
			'APInvoice',
		];
	}//end purchase()

	/**
	 * Post documents through the real mapper.
	 *
	 * @param list<array{0: array<string,mixed>, 1: string}> $documents Documents and their schemas.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function posted(array $documents): array {
		$posting = new InMemoryObjectStore();
		$tariffs = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/seeds/btw-tariffs-2026.json'), true)['tariffs'];
		$posting->rows['VatTariff'] = $tariffs;

		$action = new MaterialiseGlTransactionAction($posting->mock($this), $this->defaultsConfig(), $this->createMock(LoggerInterface::class));
		foreach ($documents as [$document, $schema]) {
			$action->execute($document, [], ['sourceSchema' => $schema], MaterialiseGlTransactionAction::class);
		}

		return [
			'GLTransaction' => $posting->savedOf('GLTransaction'),
			'GLLine' => $posting->savedOf('GLLine'),
			'VatTariff' => $tariffs,
		];
	}//end posted()

	/**
	 * A posted journal entry in Q3 with the given lines.
	 *
	 * @param array<string,list<array<string,mixed>>> $rows   Rows per schema, appended to.
	 * @param string                                  $number The transaction number.
	 * @param list<array<string,mixed>>               $lines  The lines.
	 * @param string                                  $state  The transaction state.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function withEntry(array $rows, string $number, array $lines, string $state='posted'): array {
		$id = 'gl-' . $number;
		$rows['GLTransaction'][] = [
			'id' => $id, 'transactionNumber' => $number, 'postingDate' => '2026-09-01', 'periodId' => '2026-09',
			'currency' => 'EUR', 'description' => 'Memoriaal', 'state' => $state, 'administrationId' => 'adm-kb',
		];
		foreach ($lines as $n => $line) {
			$rows['GLLine'][] = array_merge(
				['id' => $id . '-' . $n, 'transactionId' => $id, 'lineNumber' => ($n + 1), 'currency' => 'EUR', 'administrationId' => 'adm-kb'],
				$line
			);
		}

		return $rows;
	}//end withEntry()
}//end trait
