<?php

/**
 * Unit tests for CashflowExportService — the wiring that makes REQ-CF-016's
 * "Export PDF" affordance produce an actual document (#865).
 *
 * ## What these tests are pointed at
 *
 * `CashflowPdfRenderer` was written and had ZERO callers. The gap this closes
 * is not "does the renderer format a table" (its own test covers that) but
 * "does anything gather a horizon out of OpenRegister and hand it to the
 * renderer, scoped to the caller's administration".
 *
 * Every assertion here reads the BYTES the service produced. There is no
 * `expects($this->once())->method('findAll')` anywhere in this file, and that
 * is deliberate: a PHPUnit mock cannot observe a named argument — it resolves
 * the call against its OWN signature and then invokes the return callback
 * POSITIONALLY — so an argument expectation over this app's named-argument
 * call style pins the double's defaults, not the code. The doubles are the
 * repo's hand-written `InMemoryObjectServiceStub`, which really does filter,
 * and the tenant-isolation test proves scoping by seeding a SECOND
 * administration and asserting its data is absent from the output.
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
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\CashflowExportService;
use OCA\Shillinq\Service\CashflowPdfRenderer;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Export-wiring tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CashflowExportServiceTest extends TestCase {

	/**
	 * Administration ids the faked membership guard answers with.
	 *
	 * @var array<int,string>
	 */
	private array $accessible = ['adm-001'];

	/**
	 * Build the subject over a seeded in-memory OpenRegister.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Schema slug => rows.
	 *
	 * @return CashflowExportService
	 */
	private function subject(array $rows): CashflowExportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')
			->willReturnCallback(fn (): array => $this->accessible);

		return new CashflowExportService(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: new InMemoryObjectServiceStub($rows),
			administrationContext: $context,
			renderer: new CashflowPdfRenderer(),
		);
	}//end subject()

	/**
	 * A two-week horizon for `adm-001`, plus one AR projection and one
	 * recurring cost.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function seed(): array {
		return [
			'CashflowForecastHorizon' => [
				[
					'horizonId' => 'hz-2026-w22',
					'administrationId' => 'adm-001',
					'horizonStart' => '2026-05-25',
					'horizonEnd' => '2026-08-23',
					'rolledOn' => '2026-05-25T02:00:00Z',
					'modelVersion' => 'v4.1-klantspecifiek-betaalgedrag',
					'lifecycleState' => 'active',
				],
			],
			'CashflowWeek' => [
				[
					'weekId' => 'wk-23',
					'horizonId' => 'hz-2026-w22',
					'administrationId' => 'adm-001',
					'weekNumber' => 23,
					'inflows_total' => 0.0,
					'outflows_total' => 9000.0,
					'netMovement' => -9000.0,
					'closingBalance' => 11120.0,
					'bufferStatus' => 'PRE_ALERT',
				],
				[
					'weekId' => 'wk-22',
					'horizonId' => 'hz-2026-w22',
					'administrationId' => 'adm-001',
					'weekNumber' => 22,
					'inflows_total' => 12500.0,
					'outflows_total' => 7200.0,
					'netMovement' => 5300.0,
					'closingBalance' => 20120.0,
					'bufferStatus' => 'ABOVE_BUFFER',
				],
			],
			'CashflowARProjection' => [
				[
					'projId' => 'ar-1',
					'horizonId' => 'hz-2026-w22',
					'administrationId' => 'adm-001',
					'customerId' => 'klant-municipality-amsterdam',
					'outstandingAmount' => 48000.0,
					'payment_history_average_deviation' => 48,
					'reliabilityScore' => 0.95,
				],
			],
			'CashflowRecurring' => [
				[
					'recurId' => 'rec-1',
					'administrationId' => 'adm-001',
					'label' => 'Kantoorhuur',
					'frequency' => 'maandelijks',
					'standardAmount' => 1450.0,
					'indexationRule' => 'CPI',
				],
			],
		];
	}//end seed()

	/**
	 * The happy path produces a PDF envelope carrying the horizon's weeks.
	 *
	 * @return void
	 */
	public function testBuildsAPdfForTheCurrentHorizon(): void {
		$export = $this->subject($this->seed())->buildHorizonExport();

		self::assertNotNull($export);
		self::assertSame('application/pdf', $export['mimeType']);
		self::assertStringStartsWith('%PDF-1.7', $export['payload']);
		self::assertStringEndsWith('%%EOF', $export['payload']);
		self::assertStringContainsString('hz-2026-w22', $export['filename']);
		self::assertStringContainsString('ABOVE_BUFFER', $export['payload']);
		self::assertStringContainsString('PRE_ALERT', $export['payload']);

	}//end testBuildsAPdfForTheCurrentHorizon()

	/**
	 * Weeks reach the renderer in weeknummer order regardless of the order
	 * OpenRegister returned them in — the seed above deliberately lists week
	 * 23 before week 22.
	 *
	 * @return void
	 */
	public function testWeeksAreOrderedByWeekNumber(): void {
		$payload = $this->subject($this->seed())->buildHorizonExport()['payload'];

		self::assertLessThan(
			strpos($payload, 'PRE_ALERT'),
			strpos($payload, 'ABOVE_BUFFER'),
			'week 23 was rendered before week 22'
		);

	}//end testWeeksAreOrderedByWeekNumber()

	/**
	 * Tenant isolation (REQ-MA-001): a horizon belonging to an administration
	 * the caller has no membership for is not exported, and its weeks never
	 * reach the document.
	 *
	 * @return void
	 */
	public function testDoesNotExportAnotherAdministrationsHorizon(): void {
		$rows = $this->seed();
		$rows['CashflowForecastHorizon'][] = [
			'horizonId' => 'hz-other-tenant',
			'administrationId' => 'adm-999',
			'rolledOn' => '2099-01-01T00:00:00Z',
			'lifecycleState' => 'active',
		];
		$rows['CashflowWeek'][] = [
			'weekId' => 'wk-other',
			'horizonId' => 'hz-other-tenant',
			'administrationId' => 'adm-999',
			'weekNumber' => 1,
			'closingBalance' => 999999.0,
			'bufferStatus' => 'OTHER_TENANT_MARKER',
		];

		$export = $this->subject($rows)->buildHorizonExport();

		self::assertNotNull($export);
		self::assertStringNotContainsString('hz-other-tenant', $export['payload']);
		self::assertStringNotContainsString('OTHER_TENANT_MARKER', $export['payload']);
		self::assertStringContainsString('hz-2026-w22', $export['filename']);

	}//end testDoesNotExportAnotherAdministrationsHorizon()

	/**
	 * The most recently rolled horizon wins when the administration has more
	 * than one.
	 *
	 * @return void
	 */
	public function testPicksTheMostRecentlyRolledHorizon(): void {
		$rows = $this->seed();
		$rows['CashflowForecastHorizon'][] = [
			'horizonId' => 'hz-2026-w30',
			'administrationId' => 'adm-001',
			'rolledOn' => '2026-07-20T02:00:00Z',
			'lifecycleState' => 'active',
		];

		$export = $this->subject($rows)->buildHorizonExport();

		self::assertNotNull($export);
		self::assertStringContainsString('hz-2026-w30', $export['filename']);

	}//end testPicksTheMostRecentlyRolledHorizon()

	/**
	 * The AR projection's schema property `payment_history_average_deviation`
	 * reaches the renderer's `gemiddeldeAfwijking` slot.
	 *
	 * ⚠️ This asymmetry is a real drift, not a preference: `CashflowRecurring`
	 * / `CashflowARProjection` declare English property names, while the
	 * renderer (written earlier, against the design document) reads the Dutch
	 * `gemiddeldeAfwijking`. Nothing had ever called the renderer, so nothing
	 * had ever noticed. The adaptation is done in the SERVICE — renaming the
	 * renderer's read key is a change to a published input contract, and
	 * renaming the schema property is a data migration.
	 *
	 * @return void
	 */
	public function testMapsArProjectionDeviationIntoTheRenderersCustomerSection(): void {
		$payload = $this->subject($this->seed())->buildHorizonExport()['payload'];

		// The section heading reaches the page as `TOP CUSTOMERS
		// \(BETALINGSGEDRAG\)` — a PDF literal string escapes its parentheses,
		// so asserting the raw heading would fail on a document that carries
		// it perfectly well.
		self::assertStringContainsString('TOP CUSTOMERS \\(BETALINGSGEDRAG\\)', $payload);
		self::assertStringContainsString('klant-municipality-amsterdam', $payload);
		self::assertStringContainsString('avg offset 48', $payload);

	}//end testMapsArProjectionDeviationIntoTheRenderersCustomerSection()

	/**
	 * Recurring costs reach the assumptions section (REQ-CF-016 §3).
	 *
	 * @return void
	 */
	public function testIncludesTheRecurringCostBreakdown(): void {
		$payload = $this->subject($this->seed())->buildHorizonExport()['payload'];

		self::assertStringContainsString('RECURRING COSTS', $payload);
		self::assertStringContainsString('Kantoorhuur', $payload);

	}//end testIncludesTheRecurringCostBreakdown()

	/**
	 * An anonymous caller, or one with no membership anywhere, gets nothing —
	 * not an empty PDF, which would look like a successful export of an empty
	 * business.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenTheCallerHasNoAdministration(): void {
		$this->accessible = [];

		self::assertNull($this->subject($this->seed())->buildHorizonExport());

	}//end testReturnsNullWhenTheCallerHasNoAdministration()

	/**
	 * A member of an administration that has no forecast yet gets nothing,
	 * so the caller can say so rather than hand over a blank document.
	 *
	 * @return void
	 */
	public function testReturnsNullWhenTheAdministrationHasNoHorizon(): void {
		$rows = $this->seed();
		$rows['CashflowForecastHorizon'] = [];

		self::assertNull($this->subject($rows)->buildHorizonExport());

	}//end testReturnsNullWhenTheAdministrationHasNoHorizon()

	/**
	 * A store that throws is logged and answered as "no horizon" — the export
	 * must not surface an OpenRegister exception to the browser.
	 *
	 * ⚠️ The double here is a `createMock` on purpose and it observes NOTHING:
	 * it is configured only to throw. That is the one use of a mock this file's
	 * header does not warn against — no argument is inspected, so the
	 * named-argument blindness cannot produce a false pass.
	 *
	 * @return void
	 */
	public function testAStoreFailureIsSwallowedIntoNoHorizon(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willThrowException(new RuntimeException('connection refused'));

		self::assertNull($this->subjectWith(objectService: $objectService)->buildHorizonExport());

	}//end testAStoreFailureIsSwallowedIntoNoHorizon()

	/**
	 * OpenRegister answers some call shapes with ObjectEntity instances rather
	 * than plain arrays, and the export must read those too.
	 *
	 * Without this the service would silently produce a horizon-less export the
	 * moment the store's return shape changed — an empty PDF, not an error.
	 *
	 * @return void
	 */
	public function testReadsEntityShapedRowsAsWellAsArrays(): void {
		$horizon = new ObjectEntityStub(
			[
				'horizonId' => 'hz-entity-shaped',
				'administrationId' => 'adm-001',
				'rolledOn' => '2026-05-25T02:00:00Z',
			],
			'shillinq',
			'CashflowForecastHorizon'
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([$horizon]);

		$export = $this->subjectWith(objectService: $objectService)->buildHorizonExport();

		self::assertNotNull($export);
		self::assertStringContainsString('hz-entity-shaped', $export['filename']);

	}//end testReadsEntityShapedRowsAsWellAsArrays()

	/**
	 * An app config that answers an empty register slug falls back to
	 * `shillinq` rather than querying register `''`, which OpenRegister would
	 * answer with nothing at all.
	 *
	 * @return void
	 */
	public function testAnEmptyConfiguredRegisterFallsBackToShillinq(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')->willReturn(['adm-001']);

		$registers = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string|int $register) use (&$registers, $objectService): ObjectServiceInterface {
				$registers[] = (string)$register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([]);

		$service = new CashflowExportService(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $objectService,
			administrationContext: $context,
			renderer: new CashflowPdfRenderer(),
		);

		self::assertNull($service->buildHorizonExport());
		self::assertSame(['shillinq'], array_unique($registers));

	}//end testAnEmptyConfiguredRegisterFallsBackToShillinq()

	/**
	 * Build the subject over a caller-supplied object service.
	 *
	 * @param ObjectServiceInterface $objectService The store double.
	 *
	 * @return CashflowExportService
	 */
	private function subjectWith(ObjectServiceInterface $objectService): CashflowExportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$context = $this->createMock(AdministrationContextService::class);
		$context->method('accessibleAdministrationIds')
			->willReturnCallback(fn (): array => $this->accessible);

		return new CashflowExportService(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $objectService,
			administrationContext: $context,
			renderer: new CashflowPdfRenderer(),
		);
	}//end subjectWith()

}//end class
