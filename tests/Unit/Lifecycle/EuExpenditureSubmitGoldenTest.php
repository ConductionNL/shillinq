<?php

/**
 * Golden test for EuExpenditureGuard::canSubmit.
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

use OCA\Shillinq\Lifecycle\EuExpenditureGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * Regenerate with EU_EXPENDITURE_SUBMIT_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class EuExpenditureSubmitGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/eu-expenditure-submit.golden.json';

	public function testDecisionsMatchGolden(): void {
		$exp = ['id' => 'ex-1', 'costCategory' => 'kapitaal', 'euProjectId' => 'pj-1'];
		$docs = [['documentType' => 'factuur'], ['documentType' => 'betaalbewijs'], 'junk', ['note' => 'x']];
		$scenarios = [
			'notFound' => ['', null, []],
			'noCategory' => ['ex-1', ['costCategory' => ''] + $exp, []],
			'byLookup' => ['ex-1', null, ['EuExpenditure' => [$exp], 'SupportingDocument' => $docs]],
			'defaultsMet' => ['ex-1', $exp, ['SupportingDocument' => $docs]],
			'defaultsMissing' => ['ex-1', $exp, ['SupportingDocument' => [['documentType' => 'factuur']]]],
			'idFromArgument' => ['ex-9', array_diff_key($exp, ['id' => 1]), ['SupportingDocument' => $docs]],
			'ruleOverride' => [
				'ex-1',
				$exp,
				[
					'EuProject' => [['fonds' => 'efro']],
					'EligibilityRule' => [['evidenceRequired' => ['kapitaal' => ['k' => 'factuur']]]],
					'SupportingDocument' => [['documentType' => 'factuur']],
				],
			],
			'ruleWithoutCategory' => [
				'ex-1',
				$exp,
				[
					'EuProject' => [['fonds' => 'efro']],
					'EligibilityRule' => [['evidenceRequired' => ['personeel' => ['contract']]]],
					'SupportingDocument' => [['documentType' => 'factuur']],
				],
			],
			'indirectNothingNeeded' => ['ex-1', ['costCategory' => 'indirecte_kosten'] + $exp, []],
			'unknownCategory' => ['ex-1', ['costCategory' => 'overig'] + $exp, []],
			'procurementMissing' => ['ex-1', ['procurementRequired' => true] + $exp, ['SupportingDocument' => $docs]],
			'procurementPresent' => [
				'ex-1',
				['procurementRequired' => true] + $exp,
				['SupportingDocument' => array_merge($docs, [['documentType' => 'aanbestedingsdossier']])],
			],
			'storeDown' => ['ex-1', $exp, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$id, $object, $records]) {
			$actual[$name] = $this->scenario($id, $object, $records);
		}

		if (getenv('EU_EXPENDITURE_SUBMIT_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param string $id The EuExpenditure id argument.
	 * @param array<string,mixed>|null $object The in-flight expenditure.
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
				if (isset($this->records['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				return ($this->records[$this->schema] ?? []);
			}
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new EuExpenditureGuard($appConfig, $logger, new DuckObjectServiceAdapter($store));

		return [
			'result' => $guard->canSubmit($id, $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
