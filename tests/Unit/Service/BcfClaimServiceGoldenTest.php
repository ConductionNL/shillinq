<?php

/**
 * Golden test for BcfClaimService::computeClaim scoping and sums.
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
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BcfClaimService;
use OCA\Shillinq\Service\BcfCompensationCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins the claim, every store call and the register reads per scenario.
 *
 * Regenerate with BCF_CLAIM_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class BcfClaimServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/bcf-claim-service.golden.json';

	public function testClaimsMatchGolden(): void {
		$lines = [
			['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => '100.005'],
			['transactionId' => 'tx-2', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 50],
			['transactionId' => 'tx-1', 'accountNumber' => '4100', 'side' => 'debit', 'amount' => 40],
			['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'credit', 'amount' => 999],
			['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 70, 'eliminationFlag' => true],
			['transactionId' => 'tx-OTHER', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 5000],
			['transactionId' => 'tx-1', 'accountNumber' => '', 'side' => 'debit', 'amount' => 8],
			['accountNumber' => '3610', 'side' => 'debit', 'amount' => 3],
		];
		$mappings = [
			['accountNumber' => '3610', 'bcfCompensable' => true, 'compensablePercentage' => 100],
			['accountNumber' => '4100', 'bcfCompensable' => true, 'compensablePercentage' => 50],
			['accountNumber' => '', 'bcfCompensable' => true, 'compensablePercentage' => 100],
		];
		$scenarios = [
			'inScope' => [
				'GLTransaction' => [['id' => 'tx-1'], ['@self' => ['id' => 'tx-2']], ['periodId' => 'no-id']],
				'GLLine' => $lines,
				'BbvAccountMapping' => $mappings,
			],
			'noTransactions' => ['GLLine' => $lines, 'BbvAccountMapping' => $mappings],
			'noMappings' => ['GLTransaction' => [['id' => 'tx-1']], 'GLLine' => $lines],
		];

		$actual = [];
		foreach ($scenarios as $name => $records) {
			$actual[$name] = $this->scenario($records);
		}

		if (getenv('BCF_CLAIM_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(json_encode($golden, JSON_PRESERVE_ZERO_FRACTION), json_encode($actual, JSON_PRESERVE_ZERO_FRACTION));
	}

	/**
	 * Compute one claim and capture it with the store calls and register reads.
	 *
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $records): array {
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				return ($this->records[$this->schema] ?? []);
			}
		};
		$reads = 0;
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function () use (&$reads): string {
				$reads++;
				return '';
			}
		);
		$service = new BcfClaimService($appConfig, new BcfCompensationCalculator(), new DuckObjectServiceAdapter($store));

		return [
			'claim' => $service->computeClaim('adm-1', '2026-Q1'),
			'calls' => $store->calls,
			'registerReads' => $reads,
		];
	}
}
