<?php

/**
 * Golden test for the source resolution of AansluitingService::compute.
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

use OCA\Shillinq\Lifecycle\AansluitingResolutionGuard;
use OCA\Shillinq\Service\AansluitingCalculator;
use OCA\Shillinq\Service\AansluitingService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins compute()'s result (or exception) and every store read for VAT-ledger
 * and subledger/GL-control reconciliations.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AansluitingSourcesGoldenTest extends TestCase {

	/**
	 * Recorded reads of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $reads = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testComputeMatchesGolden(): void {
		$result = [];
		foreach ($this->scenarios() as $name => [$definition, $extra]) {
			$this->reads = [];
			$service = $this->service(seed: array_merge($this->seed(), ['Aansluiting' => [$definition + ['id' => 'aansl', 'toleranceCents' => 100]]], $extra));
			try {
				$out = $this->mask(value: $service->compute('aansl', '2026-Q2'));
			} catch (\Throwable $e) {
				$out = ['exception' => [get_class($e), $e->getMessage()]];
			}

			$result[$name] = ['result' => $out, 'reads' => $this->reads];
		}

		$file = __DIR__.'/fixtures/aansluiting-sources.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('AANSLUITING_SOURCES_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testComputeMatchesGolden()

	/**
	 * Scenarios: [definition, extra seed].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private function scenarios(): array {
		$vat = ['reconciliationType' => 'vat-ledger-return', 'expectedRelationship' => 'equal', 'administrationId' => 'adm-1'];
		$sub = static fn (string $type, string $account, string $relationship): array => [
			'reconciliationType' => 'subledger-gl-control',
			'controlAccountNumber' => $account,
			'subLedgerType' => $type,
			'expectedRelationship' => $relationship,
			'administrationId' => 'adm-1',
		];
		return [
			'vatDrift' => [$vat, []],
			'vatWithCorrection' => [$vat, ['VatCorrection' => [['id' => 'vc-1', 'originalVatReturnId' => 'vatret-1', 'administrationId' => 'adm-1']]]],
			'vatNoReturn' => [array_merge($vat, ['administrationId' => 'adm-9']), []],
			'arControl' => [$sub('ar', '1300', 'equal'), []],
			'apControl' => [$sub('ap', '1600', 'equal-with-sign-flip'), []],
			'unknownSubledger' => [$sub('xx', '1300', 'equal'), []],
			'noControlAccount' => [$sub('ar', '', 'equal'), []],
			'noTransactionsForAdmin' => [array_merge($sub('ar', '1300', 'equal'), ['administrationId' => 'adm-empty']), []],
		];

	}//end scenarios()

	/**
	 * The shared seed.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function seed(): array {
		$glLine = static fn (string $tx, string $account, string $side, float $amount, array $extra=[]): array => array_merge(
			['transactionId' => $tx, 'accountNumber' => $account, 'side' => $side, 'amount' => $amount, 'administrationId' => 'adm-1'],
			$extra
		);
		return [
			'AansluitingResult' => [],
			'GLTransaction' => [
				['id' => 'gltx-1', 'administrationId' => 'adm-1', 'postingDate' => '2026-06-15', 'state' => 'posted'],
				['@self' => ['id' => 'gltx-2'], 'administrationId' => 'adm-1', 'postingDate' => '2026-05-01', 'state' => 'posted'],
				['administrationId' => 'adm-1'],
			],
			'GLLine' => [
				$glLine('gltx-1', '8000', 'credit', 20000.0, ['vatTariffCode' => 'high', 'vatReturnBox' => '1a', 'vatAmountKind' => 'base']),
				$glLine('gltx-1', '2110', 'credit', 4200.0, ['vatTariffCode' => 'high', 'vatReturnBox' => '1a', 'vatAmountKind' => 'vat']),
				$glLine('gltx-1', '1300', 'debit', 18500.0),
				$glLine('gltx-2', '1300', 'credit', 500.25),
				$glLine('gltx-2', '1300', 'debit', 999.0, ['eliminationFlag' => true]),
				$glLine('gltx-foreign', '1300', 'debit', 77.0),
				$glLine('gltx-1', '1600', 'credit', 9200.0),
				$glLine('gltx-2', '1600', 'debit', 0.105),
			],
			'BtwAangifte' => [
				[
					'id' => 'vatret-1',
					'administrationId' => 'adm-1',
					'statusCode' => 'submitted',
					'period' => 'quarter',
					'periodYear' => 2026,
					'periodNumber' => 2,
					'startDate' => '2026-04-01',
					'endDate' => '2026-06-30',
				],
			],
			'VATDeclaration' => [
				['id' => 'vd-1', 'returnId' => 'vatret-1', 'returnBox' => '1a', 'type' => 'collected', 'taxRate' => 21.0, 'totalVATAmount' => 4450.0],
				['id' => 'vd-2', 'returnId' => 'vatret-1', 'returnBox' => '5b', 'type' => 'deductible', 'taxRate' => 21.0, 'totalVATAmount' => 120.0],
			],
			'ARInvoice' => [
				['id' => 'inv-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'issued', 'grossAmount' => 10000.0],
				['id' => 'inv-2', 'administrationId' => 'adm-1', 'lifecycleState' => 'overdue', 'grossAmount' => 8000.0],
				['id' => 'inv-3', 'administrationId' => 'adm-1', 'lifecycleState' => 'paid', 'grossAmount' => 5000.0],
				['administrationId' => 'adm-1', 'lifecycleState' => 'issued', 'grossAmount' => 1.0],
				['id' => 'inv-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'issued', 'grossAmount' => 250.5],
			],
			'APTransaction' => [
				['id' => 'ap-1', 'administrationId' => 'adm-1', 'state' => 'received', 'totalAmount' => 9350.0],
				['id' => 'ap-2', 'administrationId' => 'adm-1', 'state' => 'paid', 'totalAmount' => 1.0],
			],
		];

	}//end seed()

	/**
	 * Mask wall-clock values.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return mixed
	 */
	private function mask(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $item) {
			$isClock = (is_string($key) === true && str_ends_with($key, 'At') === true && is_string($item) === true && $item !== '');
			$value[$key] = $this->mask(value: $item);
			if ($isClock === true) {
				$value[$key] = '<now>';
			}
		}

		return $value;

	}//end mask()

	/**
	 * A service over a recording in-memory store.
	 *
	 * @param array $seed Schema => rows.
	 *
	 * @return AansluitingService
	 */
	private function service(array $seed): AansluitingService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$store = new DuckObjectServiceAdapter($this->store(seed: $seed));
		return new AansluitingService(
			appConfig: $appConfig,
			logger: new NullLogger(),
			calculator: new AansluitingCalculator(),
			vatReturnService: new VATReturnService(appConfig: $appConfig, logger: new NullLogger(), objectService: $store),
			resolutionGuard: new AansluitingResolutionGuard(logger: new NullLogger()),
			objectService: $store,
		);

	}//end service()

	/**
	 * The recording store.
	 *
	 * @param array $seed Schema => rows.
	 *
	 * @return object
	 */
	private function store(array $seed): object {
		$test = $this;
		return new class($seed, $test) {

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param array  $data Rows.
			 * @param object $test Recorder.
			 */
			public function __construct(private array $data, private object $test) {
			}//end __construct()

			/**
			 * Set register.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set schema.
			 *
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find rows matching every scalar filter.
			 *
			 * @param array $params Params.
			 *
			 * @return array
			 */
			public function findAll(array $params=[]): array {
				$this->test->reads[] = [$this->schema, $params];
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						($this->data[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (is_array($value) === false && ($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Find one row by id.
			 *
			 * @param string $id Id.
			 *
			 * @return array|null
			 */
			public function find(string $id): ?array {
				foreach (($this->data[$this->schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Save, assigning an id.
			 *
			 * @param array $data Body.
			 *
			 * @return array
			 */
			public function saveObject(array $data): array {
				if (($data['id'] ?? '') === '') {
					$data['id'] = $this->schema.'-new';
				}

				$this->data[$this->schema][] = $data;
				return $data;
			}//end saveObject()
		};

	}//end store()
}//end class
