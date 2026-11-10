<?php

/**
 * Characterisation tests for InventoryGlAdjustmentPoster::post().
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InventoryGlAdjustmentPoster;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins the full header and line payloads, the transaction-number year
 * fallback, the refusal envelopes and the throwing-save envelope of post().
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class InventoryGlAdjustmentPostingTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build a poster on an object service double.
	 *
	 * @param object $objectService The duck-typed object service.
	 *
	 * @return InventoryGlAdjustmentPoster
	 */
	private function poster(object $objectService): InventoryGlAdjustmentPoster {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);

		return new InventoryGlAdjustmentPoster(
			appConfig: $appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);

	}//end poster()

	/**
	 * Post with the given posting date and amount.
	 *
	 * @param InventoryGlAdjustmentPoster $poster      The poster.
	 * @param string                      $postingDate The posting date.
	 *
	 * @return array<string,mixed> The result envelope.
	 */
	private function postWith(InventoryGlAdjustmentPoster $poster, string $postingDate): array {
		return $poster->post('adm-1', '1300', '1305', 1005, 'NRV', 'Write-down', 'SKU-9', $postingDate, '2026-Q3');

	}//end postWith()

	/**
	 * Header and both line payloads in full.
	 *
	 * @return void
	 */
	public function testPayloads(): void {
		$os = new InMemoryObjectService();
		$result = $this->postWith($this->poster($os), '2026-08-01');

		self::assertSame(['posted', 'balanced', 'transaction', 'debitCents', 'creditCents', 'message'], array_keys($result));
		self::assertSame('inventory adjustment posted', $result['message']);
		$header = $os->dump(schema: 'GLTransaction')[0];
		foreach ([
			'transactionNumber' => 'NRV-2026-SKU-9',
			'postingDate' => '2026-08-01',
			'periodId' => '2026-Q3',
			'currency' => 'EUR',
			'description' => 'Write-down',
			'sourceReference' => 'SKU-9',
			'state' => 'draft',
			'administrationId' => 'adm-1',
		] as $key => $value) {
			self::assertSame($value, $header[$key], $key);
		}

		$lines = $os->dump(schema: 'GLLine');
		foreach ([[1, '1300', 'debit'], [2, '1305', 'credit']] as $i => [$number, $account, $side]) {
			self::assertSame((string)$header['id'], (string)$lines[$i]['transactionId']);
			self::assertSame('adm-1', $lines[$i]['administrationId']);
			self::assertSame($number, $lines[$i]['lineNumber']);
			self::assertSame($account, $lines[$i]['accountNumber']);
			self::assertSame($side, $lines[$i]['side']);
			self::assertSame(10.05, $lines[$i]['amount']);
			self::assertSame('EUR', $lines[$i]['currency']);
			self::assertSame('Write-down', $lines[$i]['description']);
		}

	}//end testPayloads()

	/**
	 * A posting date without a year falls back to the current year in the
	 * transaction number.
	 *
	 * @return void
	 */
	public function testYearFallback(): void {
		$os = new InMemoryObjectService();
		$this->postWith($this->poster($os), '');

		self::assertSame('NRV-' . date('Y') . '-SKU-9', $os->dump(schema: 'GLTransaction')[0]['transactionNumber']);

	}//end testYearFallback()

	/**
	 * Missing accounts warn with the journal and accounts; a throwing save is
	 * logged and reported in the envelope.
	 *
	 * @return void
	 */
	public function testRefusalAndFailureEnvelopes(): void {
		$poster = $this->poster(new InMemoryObjectService());
		$this->logger->expects(self::once())->method('warning')->with(
			'InventoryGlAdjustmentPoster: GL accounts not configured — skipping post',
			['journalCode' => 'LAND', 'debitAccount' => '', 'creditAccount' => '1305']
		);
		self::assertSame(
			['posted' => false, 'message' => 'GL accounts not configured'],
			$poster->post('adm-1', '', '1305', 5, 'LAND', 'x', 'r', '2026-01-01', 'p')
		);

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$throwing = new class {
			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			public function saveObject(array $object): array {
				throw new RuntimeException('store down');
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$poster = $this->poster($throwing);
		$this->logger->expects(self::once())->method('error')->with(
			'InventoryGlAdjustmentPoster: failed to post inventory adjustment',
			['journalCode' => 'NRV', 'amountCents' => 1005, 'exception' => 'store down']
		);
		self::assertSame(
			['posted' => false, 'message' => 'posting raised: store down'],
			$this->postWith($poster, '2026-08-01')
		);

	}//end testRefusalAndFailureEnvelopes()
}//end class
