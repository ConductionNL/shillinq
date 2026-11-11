<?php

/**
 * Golden test for BcfClaimGuard::canSubmit.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BcfClaimGuard;
use OCA\Shillinq\Service\BcfClaimService;
use OCA\Shillinq\Service\BcfCompensationCalculator;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per claim.
 *
 * Regenerate with BCF_CLAIM_GUARD_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class BcfClaimGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/bcf-claim-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$ledger = [
			'GLTransaction' => [['id' => 'tx-1']],
			'GLLine' => [['transactionId' => 'tx-1', 'accountNumber' => '3610', 'side' => 'debit', 'amount' => 100]],
			'BbvAccountMapping' => [['accountNumber' => '3610', 'bcfCompensable' => true, 'compensablePercentage' => 100]],
		];
		$claim = ['id' => 'bc-1', 'administrationId' => 'adm-1', 'claimQuarter' => '2026-Q1'];
		$scenarios = [
			'closedQuarter' => ['bc-1', $claim, $ledger + ['FiscalPeriod' => [['status' => 'Gesloten']]]],
			'closedFlag' => ['bc-1', $claim, $ledger + ['FiscalPeriod' => [['isClosed' => true]]]],
			'openQuarter' => ['bc-1', $claim, $ledger + ['FiscalPeriod' => [['state' => 'open']]]],
			'emptyClaim' => ['bc-1', $claim, ['FiscalPeriod' => [['status' => 'closed']]]],
			'byLookup' => ['bc-1', ['id' => 'bc-1'], $ledger + ['BcfClaim' => [$claim], 'FiscalPeriod' => [['status' => 'locked']]]],
			'notFound' => ['', null, []],
			'noQuarter' => ['bc-1', ['claimQuarter' => ''] + $claim, $ledger],
			'storeDown' => ['bc-1', $claim, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$id, $object, $records]) {
			$actual[$name] = $this->scenario($id, $object, $records);
		}

		if (getenv('BCF_CLAIM_GUARD_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one claim and capture result, logs and store calls.
	 *
	 * @param string $id The claim id argument.
	 * @param array<string,mixed>|null $object The in-flight claim.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(string $id, array|null $object, array $records): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = [$this->schema, ($args['filters'] ?? [])];
				if (isset($this->records['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				return ($this->records[$this->schema] ?? []);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$objects = new DuckObjectServiceAdapter($store);
		$calculator = new BcfCompensationCalculator();
		$guard = new BcfClaimGuard($appConfig, new BcfClaimService($appConfig, $calculator, $objects), $calculator, $logger, $objects);

		return [
			'result' => $guard->canSubmit($id, $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
