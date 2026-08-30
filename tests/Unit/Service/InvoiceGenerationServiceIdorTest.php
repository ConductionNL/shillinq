<?php

/**
 * Second-order IDOR guard on InvoiceGenerationService::draftInvoice().
 *
 * REQ-001 (security-endpoint-guards): draftInvoice() resolves every id the
 * client supplies on the request (timeEntryIds/expenseIds/meterReadingIds/
 * milestoneId, and the UsageRatePlan a MeterReading points at) through
 * OpenRegister's ObjectService by id alone. Before the fix, none of those
 * lookups checked that the resolved record's own administrationId matched
 * the caller's server-resolved administration — a member of administration A
 * could reference administration B's UrenRegistratie/ExpenseClaimEntry/
 * MeterReading/Milestone ids and have B's data (hours, expense amounts,
 * metered usage, milestone name/budget) folded straight into A's invoice.
 *
 * These tests seed TWO administrations (adm-attacker / adm-victim) and drive
 * draftInvoice() as adm-attacker with a request that references both an
 * adm-attacker-owned id and an adm-victim-owned id for each source type,
 * proving both directions in one assertion block: the caller's own record is
 * still resolved and billed (the fix does not regress the legitimate path),
 * and the victim's record is silently excluded — never resolved, never
 * billed, never leaked into a description or amount — exactly like an
 * unknown/non-existent id, which is this file's pre-existing convention for
 * an unresolvable reference.
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
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
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
 * Proves draftInvoice()'s referenced-record lookups are administration-scoped.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoiceGenerationServiceIdorTest extends TestCase {

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
		);
	}//end setUp()

	/**
	 * REQ-001: a cross-tenant timeEntryId is silently excluded from the
	 * drafted invoice — only the attacker's own 5 billable hours land, not
	 * the victim's 8 (13 combined would prove the exclusion failed).
	 *
	 * @return void
	 */
	public function testCrossTenantTimeEntryIdIsExcludedFromDraft(): void {
		$request = new InvoiceGenerationRequest(
			administrationId: self::ADMIN_ATTACKER,
			billingModel: 't_and_m',
			customerId: 'cust-1',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			timeEntryIds: ['ure-attacker-1', 'ure-victim-1'],
			rateCardId: 'rc-none',
		);

		$invoice = $this->service->draftInvoice($request);

		// Only the attacker's own 5 hours @ €100/hr (RateCardResolver's
		// no-match fallback) = €500.00 net. If the victim's 8 hours had
		// leaked in, this would be €1300.00 (13 hours combined).
		self::assertSame(500.00, $invoice['netAmount']);

		$lines = $this->objectService->saved['BillableInvoiceLine'] ?? [];
		self::assertCount(1, $lines);
		self::assertSame(5.0, $lines[0]['billableUnits']);
	}//end testCrossTenantTimeEntryIdIsExcludedFromDraft()

	/**
	 * REQ-001: a cross-tenant expenseId is silently excluded — only the
	 * attacker's own €50 expense line is persisted, never the victim's €999
	 * confidential expense (which would also leak its description).
	 *
	 * @return void
	 */
	public function testCrossTenantExpenseIdIsExcludedFromDraft(): void {
		$request = new InvoiceGenerationRequest(
			administrationId: self::ADMIN_ATTACKER,
			billingModel: 'fixed_fee',
			customerId: 'cust-1',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			expenseIds: ['exp-attacker-1', 'exp-victim-1'],
			fixedFeeCents: 100000,
		);

		$invoice = $this->service->draftInvoice($request);

		// €1000.00 fixed fee + €50.00 attacker expense = €1050.00. The
		// victim's €999.00 expense must not be added (would be €2049.00).
		self::assertSame(1050.00, $invoice['netAmount']);

		$lines = $this->objectService->saved['BillableInvoiceLine'] ?? [];
		$expenseLines = array_values(array_filter($lines, static fn (array $l): bool => $l['sourceType'] === 'expense'));
		self::assertCount(1, $expenseLines);
		self::assertSame('exp-attacker-1', $expenseLines[0]['sourceId']);
		self::assertSame('Attacker travel', $expenseLines[0]['description']);
	}//end testCrossTenantExpenseIdIsExcludedFromDraft()

	/**
	 * REQ-001: a cross-tenant meterReadingId is silently excluded — only the
	 * attacker's own metered reading rates onto the invoice.
	 *
	 * @return void
	 */
	public function testCrossTenantMeterReadingIdIsExcludedFromDraft(): void {
		$request = new InvoiceGenerationRequest(
			administrationId: self::ADMIN_ATTACKER,
			billingModel: 'usage',
			customerId: 'cust-1',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			meterReadingIds: ['mr-attacker-1', 'mr-victim-1'],
			usageRatePlanId: 'urp-attacker',
		);

		$invoice = $this->service->draftInvoice($request);

		$lines = $this->objectService->saved['BillableInvoiceLine'] ?? [];
		self::assertCount(1, $lines);
		self::assertSame('mr-attacker-1', $lines[0]['sourceId']);
		// 100 calls @ €0.05/call (attacker plan) = €5.00; the victim's
		// reading (which would rate at the victim's €9.99/call plan) is
		// never resolved at all.
		self::assertSame(5.00, $invoice['netAmount']);
	}//end testCrossTenantMeterReadingIdIsExcludedFromDraft()

	/**
	 * REQ-001: a MeterReading that is the attacker's own record but whose
	 * ratePlanId points at another tenant's UsageRatePlan must not rate
	 * against that foreign plan — the reading is skipped (never billed at a
	 * cross-tenant price), the same as any other unresolvable plan id.
	 *
	 * @return void
	 */
	public function testCrossTenantRatePlanReferenceIsExcludedFromDraft(): void {
		$request = new InvoiceGenerationRequest(
			administrationId: self::ADMIN_ATTACKER,
			billingModel: 'usage',
			customerId: 'cust-1',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			meterReadingIds: ['mr-attacker-2'],
		);

		$invoice = $this->service->draftInvoice($request);

		// mr-attacker-2 references urp-victim; the plan lookup is scoped to
		// adm-attacker so it never resolves, so the reading is skipped and
		// nothing is billed — never the victim's €999.00/call rate.
		self::assertSame(0.00, $invoice['netAmount']);
		self::assertCount(0, $this->objectService->saved['BillableInvoiceLine'] ?? []);
	}//end testCrossTenantRatePlanReferenceIsExcludedFromDraft()

	/**
	 * REQ-001: a cross-tenant milestoneId falls back to the same generic
	 * stub as an unknown id — never the victim's real milestone name or
	 * budget.
	 *
	 * @return void
	 */
	public function testCrossTenantMilestoneIdFallsBackToStub(): void {
		$request = new InvoiceGenerationRequest(
			administrationId: self::ADMIN_ATTACKER,
			billingModel: 'milestone',
			customerId: 'cust-1',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			milestoneId: 'ms-victim-1',
		);

		$invoice = $this->service->draftInvoice($request);

		$lines = $this->objectService->saved['BillableInvoiceLine'] ?? [];
		self::assertCount(1, $lines);
		// Generic stub, not the victim's real name/budget.
		self::assertSame('Milestone', $lines[0]['description']);
		self::assertSame(0.00, $lines[0]['costAmount']);
		self::assertSame(0.00, $invoice['netAmount']);
	}//end testCrossTenantMilestoneIdFallsBackToStub()

	/**
	 * Own-tenant control: the attacker's own milestoneId resolves normally —
	 * proves the fix does not regress the legitimate same-tenant path.
	 *
	 * @return void
	 */
	public function testOwnTenantMilestoneIdDraftsCorrectly(): void {
		$request = new InvoiceGenerationRequest(
			administrationId: self::ADMIN_ATTACKER,
			billingModel: 'milestone',
			customerId: 'cust-1',
			fromDate: '2026-03-01',
			toDate: '2026-03-31',
			milestoneId: 'ms-attacker-1',
		);

		$invoice = $this->service->draftInvoice($request);

		$lines = $this->objectService->saved['BillableInvoiceLine'] ?? [];
		self::assertCount(1, $lines);
		self::assertSame('Attacker milestone', $lines[0]['description']);
		self::assertSame(500.00, $lines[0]['costAmount']);
		self::assertSame(500.00, $invoice['netAmount']);
	}//end testOwnTenantMilestoneIdDraftsCorrectly()
}//end class
