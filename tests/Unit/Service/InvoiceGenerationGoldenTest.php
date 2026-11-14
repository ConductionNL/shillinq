<?php

/**
 * Golden tests for InvoiceGenerationService::draftInvoice() across every
 * billing model, and postInvoice().
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\BillingModelEngine;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RetainerResolver;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Service\VATCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Pins every saved record of a draft per billing model, and of a post.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoiceGenerationGoldenTest extends TestCase {

	private const ADMIN_ATTACKER = 'adm-attacker';

	private const ADMIN_VICTIM = 'adm-victim';

	/**
	 * In-memory fake ObjectService supporting the fluent find/findAll/saveObject
	 * shape InvoiceGenerationService consumes (same shape as
	 * MeteredInvoiceGenerationTest's double).
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Subject under test.
	 *
	 * @var InvoiceGenerationService
	 */
	private InvoiceGenerationService $service;

	/**
	 * Seed both administrations' fixtures and wire the service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = new class {

			/**
			 * Records keyed by schema; also the store find/findAll read from.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $store = [];

			/**
			 * Save log keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter.
			 *
			 * @var integer
			 */
			private int $seq = 0;

			/**
			 * Fluent register selector.
			 *
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema selector.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find one record by id under the current schema (id-only — used by
			 * fetchInvoice()/generateInvoiceNumber(), never by the scoped loaders
			 * under test here).
			 *
			 * @param string $id Record id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				foreach (($this->store[$this->schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Return rows for the current schema, applying equality filters —
			 * the compound id+administrationId filter InvoiceGenerationService's
			 * findScoped() issues is honoured here exactly like the real
			 * OpenRegister ObjectService::findAll() (AND across every filter key).
			 *
			 * @param array<string,mixed> $options Query options.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $options): array {
				$rows = $this->store[$this->schema] ?? [];
				$filters = $options['filters'] ?? [];
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Persist a record (assigning an id when absent) under the current schema.
			 *
			 * @param array<string,mixed> $data Record payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				if (isset($data['id']) === false || (string)$data['id'] === '') {
					$this->seq++;
					$data['id'] = sprintf('%s-%d', strtolower($this->schema), $this->seq);
				}

				$this->store[$this->schema][] = $data;
				$this->saved[$this->schema][] = $data;
				return $data;
			}//end saveObject()
		};

		$this->objectService->store = [
			'UrenRegistratie' => [
				[
					'id' => 'ure-attacker-1',
					'administrationId' => self::ADMIN_ATTACKER,
					'resourceType' => 'consultant',
					'hours' => 5.0,
					'date' => '2026-03-10',
					'hourlyRateOverride' => 100,
				],
				[
					'id' => 'ure-victim-1',
					'administrationId' => self::ADMIN_VICTIM,
					'resourceType' => 'consultant',
					'hours' => 8.0,
					'date' => '2026-03-10',
					'hourlyRateOverride' => 100,
				],
			],
			'ExpenseClaimEntry' => [
				[
					'id' => 'exp-attacker-1',
					'administrationId' => self::ADMIN_ATTACKER,
					'description' => 'Attacker travel',
					'amount' => 50.00,
				],
				[
					'id' => 'exp-victim-1',
					'administrationId' => self::ADMIN_VICTIM,
					'description' => 'Victim confidential expense',
					'amount' => 999.00,
				],
			],
			'MeterReading' => [
				[
					'id' => 'mr-attacker-1',
					'administrationId' => self::ADMIN_ATTACKER,
					'meterId' => 'meter-attacker',
					'resourceType' => 'api_calls',
					'quantity' => 100,
					'unit' => 'calls',
					'ratePlanId' => 'urp-attacker',
					'periodStart' => '2026-03-01',
					'periodEnd' => '2026-03-31',
					'status' => 'rated',
				],
				[
					'id' => 'mr-victim-1',
					'administrationId' => self::ADMIN_VICTIM,
					'meterId' => 'meter-victim',
					'resourceType' => 'api_calls',
					'quantity' => 100,
					'unit' => 'calls',
					'ratePlanId' => 'urp-victim',
					'periodStart' => '2026-03-01',
					'periodEnd' => '2026-03-31',
					'status' => 'rated',
				],
				[
					// Attacker's OWN reading, but with a ratePlanId that resolves to
					// the VICTIM's UsageRatePlan — proves the plan lookup is scoped
					// too, not just the reading lookup.
					'id' => 'mr-attacker-2',
					'administrationId' => self::ADMIN_ATTACKER,
					'meterId' => 'meter-attacker-2',
					'resourceType' => 'api_calls',
					'quantity' => 100,
					'unit' => 'calls',
					'ratePlanId' => 'urp-victim',
					'periodStart' => '2026-03-01',
					'periodEnd' => '2026-03-31',
					'status' => 'rated',
				],
			],
			'UsageRatePlan' => [
				[
					'id' => 'urp-attacker',
					'administrationId' => self::ADMIN_ATTACKER,
					'name' => 'Attacker plan',
					'resourceType' => 'api_calls',
					'unit' => 'calls',
					'ratingMethod' => 'flat',
					'unitPriceCents' => 5,
					'vatRate' => 21,
				],
				[
					'id' => 'urp-victim',
					'administrationId' => self::ADMIN_VICTIM,
					'name' => 'Victim confidential plan',
					'resourceType' => 'api_calls',
					'unit' => 'calls',
					'ratingMethod' => 'flat',
					'unitPriceCents' => 999,
					'vatRate' => 21,
				],
			],
			'Milestone' => [
				[
					'id' => 'ms-attacker-1',
					'administrationId' => self::ADMIN_ATTACKER,
					'name' => 'Attacker milestone',
					'completedAt' => '2026-03-01',
					'budgetAmount' => 500.00,
				],
				[
					'id' => 'ms-victim-1',
					'administrationId' => self::ADMIN_VICTIM,
					'name' => 'Victim confidential milestone',
					'completedAt' => '2026-03-01',
					'budgetAmount' => 99999.00,
				],
			],
			'BillableInvoice' => [],
			'RetainerSchedule' => [
				[
					'id' => 'rs-1',
					'scheduleId' => 'ret-1',
					'administrationId' => self::ADMIN_ATTACKER,
					'monthlyAmount' => 2000.00,
					'includedHours' => 10,
					'overageRate' => 120,
					'validFrom' => '2026-01-01',
					'status' => 'active',
				],
			],
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $default
		);

		$logger = new NullLogger();

		$this->service = new InvoiceGenerationService(
			$appConfig,
			$logger,
			new RateCardResolver($container, $appConfig, $logger),
			new RetainerResolver($container, $appConfig, $logger),
			new BillingModelEngine(),
			new InvoiceDeduplicationService($container, $appConfig, $logger),
			new VATCalculationService(),
			new UsageRatingCalculator(),
			objectService: new DuckObjectServiceAdapter($this->objectService),
			billedReadings: $this->createMock(BilledReadings::class),
		);
	}//end setUp()

	/**
	 * The scenarios: one request per billing model and description branch.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		$base = ['administrationId' => self::ADMIN_ATTACKER, 'customerId' => 'cust-1', 'fromDate' => '2026-03-01', 'toDate' => '2026-03-31'];
		return [
			't_and_m' => [$base + ['billingModel' => 't_and_m', 'timeEntryIds' => ['ure-attacker-1'], 'expenseIds' => ['exp-attacker-1'], 'rateCardId' => 'rc-none', 'projectId' => 'p-1']],
			'fixed_fee notes' => [$base + ['billingModel' => 'fixed_fee', 'fixedFeeCents' => 150000, 'notes' => 'Website build', 'timeEntryIds' => ['ure-attacker-1']]],
			'fixed_fee plain' => [$base + ['billingModel' => 'fixed_fee', 'fixedFeeCents' => 99999, 'expenseIds' => ['exp-attacker-1']]],
			'milestone' => [$base + ['billingModel' => 'milestone', 'milestoneId' => 'ms-attacker-1', 'expenseIds' => ['exp-attacker-1']]],
			'retainer' => [$base + ['billingModel' => 'retainer', 'retainerScheduleId' => 'ret-1', 'timeEntryIds' => ['ure-attacker-1']]],
			'mixed notes' => [$base + ['billingModel' => 'mixed', 'retainerScheduleId' => 'ret-1', 'fixedFeeCents' => 25000, 'notes' => 'Onboarding']],
			'mixed plain' => [$base + ['billingModel' => 'mixed', 'retainerScheduleId' => 'ret-1', 'fixedFeeCents' => 25000, 'timeEntryIds' => ['ure-attacker-1']]],
			'usage' => [$base + ['billingModel' => 'usage', 'meterReadingIds' => ['mr-attacker-1'], 'usageRatePlanId' => 'urp-attacker']],
		];
	}//end scenarios()

	/**
	 * Every record a draft saves, and the invoice it answers, match the
	 * snapshot taken before the draft path was split.
	 *
	 * @param array<string, mixed> $fields The request fields.
	 *
	 * @return void
	 *
	 * @dataProvider scenarios
	 */
	public function testDraftMatchesTheSnapshot(array $fields): void {
		$request = new InvoiceGenerationRequest(...$fields);
		$invoice = $this->service->draftInvoice($request);

		$actual = ['returned' => $invoice, 'saved' => $this->objectService->saved];
		self::assertSame($this->snapshot()[$this->dataName()], json_decode((string)json_encode($actual), true));
	}//end testDraftMatchesTheSnapshot()

	/**
	 * A post saves the obligation, the journal and the posted invoice, in
	 * that order, and matches the snapshot.
	 *
	 * @return void
	 */
	public function testPostMatchesTheSnapshot(): void {
		$scenario = self::scenarios()['t_and_m'][0];
		$draft    = $this->service->draftInvoice(new InvoiceGenerationRequest(...$scenario));
		$this->objectService->saved = [];

		$posted = $this->service->postInvoice($draft);

		$actual = ['returned' => $posted, 'saved' => $this->objectService->saved];
		self::assertSame($this->snapshot()['post'], json_decode((string)json_encode($actual), true));
	}//end testPostMatchesTheSnapshot()

	/**
	 * A posted, a cancelled and an invalid invoice are refused before any
	 * save, each with its message.
	 *
	 * @return void
	 */
	public function testPostRefusals(): void {
		$messages = [];
		foreach ([
			['status' => 'posted'],
			['status' => 'cancelled'],
			['status' => 'draft', 'administrationId' => self::ADMIN_ATTACKER, 'billingModel' => 't_and_m'],
			['status' => 'draft', 'administrationId' => self::ADMIN_ATTACKER, 'billingModel' => 'mixed', 'rateCardId' => 'x'],
		] as $invoice) {
			try {
				$this->service->postInvoice($invoice);
				$messages[] = 'accepted';
			} catch (\RuntimeException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(
			[
				'Invoice is already posted.',
				'Cancelled invoices cannot be posted.',
				'Invoice failed validation: rateCardId is required for t_and_m model.',
				'Invoice failed validation: retainerScheduleId is required for mixed model.',
			],
			$messages
		);
		self::assertSame([], $this->objectService->saved);
	}//end testPostRefusals()

	/**
	 * The snapshot.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/fixtures/invoice-generation-golden.json'), true);
	}//end snapshot()
}//end class
