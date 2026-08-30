<?php

/**
 * Unit tests for DunningRunService.
 *
 * Tests the orchestration logic with an in-memory ObjectService stub so the
 * tests stay hermetic — no Nextcloud bootstrap, no OR runtime. Covers:
 *   - resolveLadderForKlant() with and without an active override (REQ-CCD-001)
 *   - executeStage() refuses while a pause is active (REQ-CCD-004)
 *   - executeStage() materialises lifecycleState=executed (REQ-CCD-002)
 *   - pause() sets the 60-day hard deadline (REQ-CCD-004)
 *   - resumePause() flips to resolved / hardDeadlineExpired (REQ-CCD-004)
 *   - writeOff() captures art29OBVerklaring (REQ-CCD-010)
 *   - detectAdminError() flags good customers + admin error context (REQ-CCD-011)
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md#req-ccd-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Dunning\DunningChannelSendResult;
use OCA\Shillinq\Service\Dunning\IncassoBureauAdapterInterface;
use OCA\Shillinq\Service\Dunning\PostNLAdapterInterface;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\OpenRegisterFaithfulObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * DunningRunService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DunningRunServiceTest extends TestCase {
	/**
	 * @return DunningRunService
	 */
	private function makeService(
		InMemoryObjectService $os,
		?IncassoBureauAdapterInterface $incasso = null,
		?PostNLAdapterInterface $postnl = null,
	): DunningRunService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($os, $incasso, $postnl) {
				if ($id === IncassoBureauAdapterInterface::class) {
					return ($incasso !== null) ? $incasso : new class implements IncassoBureauAdapterInterface {
						public function transfer(string $administrationId, string $invoiceId, array $dossier): DunningChannelSendResult {
							return new DunningChannelSendResult(
								channel: 'COLLECTION_AGENCY_API',
								deliveryStatus: 'DELIVERED',
								providerMessageId: 'noop',
								extras: ['dossierId' => 'test-dossier'],
							);
						}
					};
				}
				if ($id === PostNLAdapterInterface::class) {
					return ($postnl !== null) ? $postnl : new class implements PostNLAdapterInterface {
						public function sendRegisteredLetter(array $payload): DunningChannelSendResult {
							return new DunningChannelSendResult(
								channel: 'REGISTERED_POST',
								deliveryStatus: 'DELIVERED',
								providerMessageId: 'noop',
								extras: ['barcode' => '3S1234567890123', 'trackingUrl' => 'https://postnl.nl/tracktrace/3S1234567890123'],
							);
						}
					};
				}
				return $os;
			}
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'shillinq',
					'dunning.dispute_pause_hard_deadline_days' => '60',
					'dunning.admin_error_lookback_days' => '90',
				];
				return $values[$key] ?? $default;
			}
		);

		return new DunningRunService(
			container: $container,
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * REQ-CCD-001: resolveLadderForKlant returns base ladder when no override.
	 *
	 * @return void
	 */
	public function testResolveLadderFallsBackToBaseWhenNoOverride(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningLadder', rows: [
			[
				'id' => 'ladder-1',
				'stages' => [['nr' => 1, 'daysAfterExpiryDate' => 0, 'name' => 'Reminder', 'channel' => 'EMAIL']],
			],
		]);

		$service = $this->makeService(os: $os);
		$resolved = $service->resolveLadderForKlant(administrationId: 'adm-1', customerId: 'klant-1', baseLadderId: 'ladder-1');

		self::assertSame('base', $resolved['source']);
		self::assertNull($resolved['override']);
		self::assertCount(1, $resolved['stages']);

	}//end testResolveLadderFallsBackToBaseWhenNoOverride()

	/**
	 * REQ-CCD-001: active override replaces the base ladder's stages.
	 *
	 * @return void
	 */
	public function testResolveLadderUsesActiveOverride(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningLadder', rows: [
			['id' => 'ladder-1', 'stages' => [['nr' => 1, 'channel' => 'EMAIL']]],
		]);
		$os->seed(schema: 'KlantLadderOverride', rows: [
			[
				'id' => 'ovr-1',
				'customerId' => 'klant-1',
				'baseLadderId' => 'ladder-1',
				'lifecycleState' => 'active',
				'overrides' => [
					'stages' => [
						['nr' => 1, 'channel' => 'EMAIL'],
						['nr' => 2, 'channel' => 'EMAIL'],
					],
				],
			],
		]);

		$service = $this->makeService(os: $os);
		$resolved = $service->resolveLadderForKlant(administrationId: 'adm-1', customerId: 'klant-1', baseLadderId: 'ladder-1');

		self::assertSame('override', $resolved['source']);
		self::assertCount(2, $resolved['stages']);

	}//end testResolveLadderUsesActiveOverride()

	/**
	 * REQ-CCD-004: executeStage refuses while an active pause exists.
	 *
	 * @return void
	 */
	public function testExecuteStageRefusesWhilePaused(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningPauseDispute', rows: [
			[
				'id' => 'pause-1',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'lifecycleState' => 'active',
			],
		]);

		$service = $this->makeService(os: $os);
		$this->expectException(RuntimeException::class);
		$service->executeStage(administrationId: 'adm-1', params: [
			'invoiceId' => 'inv-1',
			'ladderId' => 'ladder-1',
			'stageNr' => 1,
			'templateId' => 'tpl-1',
			'channel' => 'EMAIL',
		]);

	}//end testExecuteStageRefusesWhilePaused()

	/**
	 * REQ-CCD-002: executeStage persists a DunningRun in lifecycleState=executed.
	 *
	 * @return void
	 */
	public function testExecuteStagePersistsExecutedRun(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$persisted = $service->executeStage(administrationId: 'adm-1', params: [
			'invoiceId' => 'inv-1',
			'ladderId' => 'ladder-1',
			'stageNr' => 1,
			'templateId' => 'tpl-stage1',
			'channel' => 'EMAIL',
			'recipientEmail' => 'klant@example.nl',
			'renderedSubject' => 'Reminder factuur',
			'renderedBody' => 'Vriendelijk verzoek',
			'deliveryStatus' => 'DELIVERED',
			'invoiceAmount' => 1234.56,
		]);

		self::assertSame('executed', $persisted['lifecycleState']);
		self::assertSame('EMAIL', $persisted['channel']);
		self::assertSame(1234.56, $persisted['invoiceAmount']);
		self::assertNotNull($persisted['executedOn']);

	}//end testExecuteStagePersistsExecutedRun()

	/**
	 * REQ-CCD-004: pause sets hardDeadlineEindigt at pauzeStart + 60 days.
	 *
	 * @return void
	 */
	public function testPauseSetsSixtyDayHardDeadline(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$pause = $service->pause(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			reason: 'DISPUTED',
			details: 'Klant betwist',
			pausedBy: 'user-1',
		);

		self::assertSame('active', $pause['lifecycleState']);
		$start = new \DateTimeImmutable((string)$pause['pauseStart']);
		$deadline = new \DateTimeImmutable((string)$pause['hardDeadlineEindigt']);
		self::assertSame(60, (int)$start->diff($deadline)->days);

	}//end testPauseSetsSixtyDayHardDeadline()

	/**
	 * REQ-CCD-004: resumePause flips lifecycleState (resolve / expire).
	 *
	 * @return void
	 */
	public function testResumePauseFlipsLifecycleState(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningPauseDispute', rows: [
			['id' => 'pause-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
		]);
		$service = $this->makeService(os: $os);

		$resolved = $service->resumePause(administrationId: 'adm-1', pauseId: 'pause-1', resolution: 'resolve');
		self::assertSame('resolved', $resolved['lifecycleState']);
		self::assertNotNull($resolved['pauseEnd']);

		$os2 = new OpenRegisterFaithfulObjectService();
		$os2->seed(schema: 'DunningPauseDispute', rows: [
			['id' => 'pause-2', 'administrationId' => 'adm-1', 'lifecycleState' => 'active'],
		]);
		$service2 = $this->makeService(os: $os2);
		$expired = $service2->resumePause(administrationId: 'adm-1', pauseId: 'pause-2', resolution: 'expire');
		self::assertSame('hardDeadlineExpired', $expired['lifecycleState']);

	}//end testResumePauseFlipsLifecycleState()

	/**
	 * resumePause() must refuse a pause belonging to another administration.
	 *
	 * The $administrationId parameter was accepted and then IGNORED —
	 * fetchById() resolves by id alone, so before the guard the parameter
	 * appeared exactly once in the method, its own declaration. A dead tenant
	 * parameter reads as scoped at every call site while scoping nothing, and
	 * that is precisely what enabled the resumePause IDOR.
	 *
	 * The refusal message is deliberately identical to the not-found case, so
	 * the guard cannot be used as an existence oracle.
	 *
	 * @return void
	 */
	public function testResumePauseRefusesAnotherAdministrationsPause(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningPauseDispute', rows: [
			['id' => 'pause-b', 'administrationId' => 'adm-B', 'lifecycleState' => 'active'],
		]);
		$service = $this->makeService(os: $os);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('DunningPauseDispute pause-b not found.');
		$service->resumePause(administrationId: 'adm-A', pauseId: 'pause-b', resolution: 'resolve');

	}//end testResumePauseRefusesAnotherAdministrationsPause()

	/**
	 * The paired must-PASS control: the OWNING administration still resolves.
	 *
	 * Without this, a guard that refused everything would look identical to a
	 * guard that refuses correctly.
	 *
	 * @return void
	 */
	public function testResumePauseStillResolvesForTheOwningAdministration(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningPauseDispute', rows: [
			['id' => 'pause-own', 'administrationId' => 'adm-A', 'lifecycleState' => 'active'],
		]);
		$service = $this->makeService(os: $os);

		$resolved = $service->resumePause(administrationId: 'adm-A', pauseId: 'pause-own', resolution: 'resolve');
		self::assertSame('resolved', $resolved['lifecycleState']);

	}//end testResumePauseStillResolvesForTheOwningAdministration()

	/**
	 * REQ-CCD-010: writeOff materialises the OninbaarAfschrijving record.
	 *
	 * @return void
	 */
	public function testWriteOffPersistsRecord(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$persisted = $service->writeOff(administrationId: 'adm-1', params: [
			'invoiceId' => 'inv-1',
			'principalDepreciated' => 4200.00,
			'vatAmount' => 882.00,
			'art29OBDeclaration' => 'Faillissement vonnis 2026-04-12',
			'vatTaxReturnPeriod' => '2026-Q2',
		]);

		self::assertSame('posted', $persisted['lifecycleState']);
		self::assertSame(4200.0, $persisted['principalDepreciated']);
		self::assertStringContainsString('Faillissement', (string)$persisted['art29OBDeclaration']);

	}//end testWriteOffPersistsRecord()

	/**
	 * Task-12: stageForOverdueDays picks the highest stage whose threshold has been reached.
	 *
	 * @return void
	 */
	public function testStageForOverdueDaysPicksHighestApplicable(): void {
		$service = $this->makeService(os: new OpenRegisterFaithfulObjectService());
		$stages = [
			['nr' => 1, 'daysAfterExpiryDate' => 0,  'channel' => 'EMAIL'],
			['nr' => 2, 'daysAfterExpiryDate' => 14, 'channel' => 'EMAIL'],
			['nr' => 3, 'daysAfterExpiryDate' => 30, 'channel' => 'eMAILPostRegistration'],
			['nr' => 4, 'daysAfterExpiryDate' => 60, 'channel' => 'REGISTERED_POST'],
			['nr' => 5, 'daysAfterExpiryDate' => 90, 'channel' => 'COLLECTION_AGENCY_API'],
		];

		self::assertSame(1, (int)$service->stageForOverdueDays(stages: $stages, daysInArrears: 0)['nr']);
		self::assertSame(2, (int)$service->stageForOverdueDays(stages: $stages, daysInArrears: 20)['nr']);
		self::assertSame(3, (int)$service->stageForOverdueDays(stages: $stages, daysInArrears: 45)['nr']);
		self::assertSame(5, (int)$service->stageForOverdueDays(stages: $stages, daysInArrears: 200)['nr']);
		self::assertNull($service->stageForOverdueDays(stages: $stages, daysInArrears: -1));

	}//end testStageForOverdueDaysPicksHighestApplicable()

	/**
	 * Task-12: tickInvoice emits a DunningRun for the applicable stage when the
	 * invoice has crossed the threshold and no prior run exists.
	 *
	 * @return void
	 */
	public function testTickInvoiceEmitsRunForApplicableStage(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningLadder', rows: [
			[
				'id' => 'ladder-1',
				'stages' => [
					['nr' => 1, 'daysAfterExpiryDate' => 0,  'channel' => 'EMAIL', 'templateId' => 'tpl-1'],
					['nr' => 2, 'daysAfterExpiryDate' => 14, 'channel' => 'EMAIL', 'templateId' => 'tpl-2'],
				],
			],
		]);
		$service = $this->makeService(os: $os);

		$now = new \DateTimeImmutable('2026-06-09T12:00:00Z');
		$invoice = [
			'id' => 'inv-1',
			'dueDate' => '2026-05-20',
			'grossAmount' => 8400.00,
			'customerReference' => 'klant-1',
		];

		$run = $service->tickInvoice(
			administrationId: 'adm-1',
			invoice: $invoice,
			baseLadderId: 'ladder-1',
			params: [],
			now: $now
		);

		self::assertNotNull($run);
		self::assertSame(2, (int)$run['stageNr']);
		self::assertSame('EMAIL', $run['channel']);
		self::assertSame(8400.0, (float)$run['invoiceAmount']);
		self::assertSame('executed', $run['lifecycleState']);

	}//end testTickInvoiceEmitsRunForApplicableStage()

	/**
	 * Task-12: tickInvoice is a no-op while an active DunningPauseDispute exists.
	 *
	 * @return void
	 */
	public function testTickInvoiceSkipsWhilePaused(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningLadder', rows: [
			['id' => 'ladder-1', 'stages' => [['nr' => 1, 'daysAfterExpiryDate' => 0, 'channel' => 'EMAIL']]],
		]);
		$os->seed(schema: 'DunningPauseDispute', rows: [
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'lifecycleState' => 'active',
			],
		]);
		$service = $this->makeService(os: $os);

		$result = $service->tickInvoice(
			administrationId: 'adm-1',
			invoice: ['id' => 'inv-1', 'dueDate' => '2026-05-20', 'grossAmount' => 100.0],
			baseLadderId: 'ladder-1',
			params: [],
			now: new \DateTimeImmutable('2026-06-09T12:00:00Z')
		);

		self::assertNull($result);

	}//end testTickInvoiceSkipsWhilePaused()

	/**
	 * Task-12: tickInvoice is a no-op when the same stage has already fired for the invoice.
	 *
	 * @return void
	 */
	public function testTickInvoiceIsIdempotentPerStage(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningLadder', rows: [
			['id' => 'ladder-1', 'stages' => [['nr' => 1, 'daysAfterExpiryDate' => 0, 'channel' => 'EMAIL']]],
		]);
		$os->seed(schema: 'DunningRun', rows: [
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'stageNr' => 1,
				'lifecycleState' => 'executed',
			],
		]);
		$service = $this->makeService(os: $os);

		$result = $service->tickInvoice(
			administrationId: 'adm-1',
			invoice: ['id' => 'inv-1', 'dueDate' => '2026-05-20', 'grossAmount' => 100.0],
			baseLadderId: 'ladder-1',
			params: [],
			now: new \DateTimeImmutable('2026-06-09T12:00:00Z')
		);

		self::assertNull($result);

	}//end testTickInvoiceIsIdempotentPerStage()

	/**
	 * Task-12: tickInvoice is a no-op when the invoice is still within terms.
	 *
	 * @return void
	 */
	public function testTickInvoiceSkipsWhenWithinTerms(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningLadder', rows: [
			['id' => 'ladder-1', 'stages' => [['nr' => 1, 'daysAfterExpiryDate' => 0, 'channel' => 'EMAIL']]],
		]);
		$service = $this->makeService(os: $os);

		$result = $service->tickInvoice(
			administrationId: 'adm-1',
			invoice: ['id' => 'inv-1', 'dueDate' => '2026-07-01', 'grossAmount' => 100.0],
			baseLadderId: 'ladder-1',
			params: [],
			now: new \DateTimeImmutable('2026-06-09T12:00:00Z')
		);

		self::assertNull($result);

	}//end testTickInvoiceSkipsWhenWithinTerms()

	/**
	 * REQ-CCD-010 / task-26: writeOff materialises a balanced GLTransaction
	 * (debit bad-debt + VAT-recover, credit AR control).
	 *
	 * @return void
	 */
	public function testWriteOffMaterialisesBalancedGlPosting(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$persisted = $service->writeOff(administrationId: 'adm-1', params: [
			'invoiceId' => 'inv-1',
			'principalDepreciated' => 4200.00,
			'vatAmount' => 882.00,
			'art29OBDeclaration' => 'Faillissement vonnis 2026-04-12',
			'vatTaxReturnPeriod' => '2026-Q2',
		]);

		$glRows = $os->dump(schema: 'GLTransaction');
		self::assertCount(1, $glRows, 'one GL transaction materialised');
		$journal = $glRows[0];

		// boekingId on the OninbaarAfschrijving points at the GL transaction.
		self::assertSame($journal['id'], $persisted['entryId']);
		self::assertSame('inv-1', $journal['sourceReference']);
		self::assertSame('posted', $journal['state']);
		self::assertTrue((bool)$journal['isBalanced']);

		// 3 postings: debit bad-debt 420000c + debit VAT-recover 88200c, credit AR 508200c.
		$postings = (array)$journal['postings'];
		self::assertCount(3, $postings);

		$debit = 0;
		$credit = 0;
		foreach ($postings as $line) {
			$debit += (int)$line['debitCents'];
			$credit += (int)$line['creditCents'];
		}
		self::assertSame($debit, $credit, 'GL posting must balance');
		self::assertSame(508200, $debit, 'debit total = hoofdsom + btw in cents');

	}//end testWriteOffMaterialisesBalancedGlPosting()

	/**
	 * REQ-CCD-010 / task-27: writeOff queues a `VATLine` correction line keyed
	 * to the next aangifte period with the FK back to the OninbaarAfschrijving.
	 *
	 * @return void
	 */
	public function testWriteOffQueuesArt29ObCorrectionVatLine(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$persisted = $service->writeOff(administrationId: 'adm-1', params: [
			'invoiceId' => 'inv-1',
			'principalDepreciated' => 4200.00,
			'vatAmount' => 882.00,
			'art29OBDeclaration' => 'Faillissement vonnis 2026-04-12',
			'vatTaxReturnPeriod' => '2026-Q2',
		]);

		$lines = $os->dump(schema: 'VATLine');
		self::assertCount(1, $lines);
		$line = $lines[0];
		self::assertSame('2026-Q2', $line['returnId']);
		self::assertSame('CORRECTION_ART_29_OB', $line['type']);
		self::assertSame(-882.0, (float)$line['vatAmount']);
		self::assertSame($persisted['id'], $line['sourceOninbaarRef']);
		self::assertSame('inv-1', $line['sourceInvoiceRef']);

	}//end testWriteOffQueuesArt29ObCorrectionVatLine()

	/**
	 * Task-22: when the caller supplies its own `boekingId`, the write-off
	 * reuses it instead of materialising a duplicate GL posting (idempotent
	 * for callers that already produced the journal upstream).
	 *
	 * @return void
	 */
	public function testWriteOffHonorsCallerProvidedBoekingId(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$persisted = $service->writeOff(administrationId: 'adm-1', params: [
			'invoiceId' => 'inv-1',
			'principalDepreciated' => 1000.00,
			'vatAmount' => 210.00,
			'art29OBDeclaration' => 'Schuldsanering',
			'entryId' => 'caller-gl-7',
		]);

		self::assertSame('caller-gl-7', $persisted['entryId']);
		self::assertCount(0, $os->dump(schema: 'GLTransaction'), 'caller boekingId skips re-posting');

	}//end testWriteOffHonorsCallerProvidedBoekingId()

	/**
	 * REQ-CCD-011: detectAdminError flags good customers + admin-error trigger.
	 *
	 * @return void
	 */
	public function testAdminErrorDetectorFlagsGoodCustomers(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningRun', rows: [
			[
				'id' => 'dr-1',
				'administrationId' => 'adm-1',
				'deliveryStatus' => 'DELIVERED',
				'executedOn' => (new \DateTimeImmutable('-30 days'))->format(DATE_ATOM),
			],
		]);

		$service = $this->makeService(os: $os);

		self::assertTrue($service->detectAdminError(
			administrationId: 'adm-1',
			customerId: 'klant-1',
			triggerContext: ['ibanInvalid' => true]
		));

		// No trigger context — no flag, even with prior runs.
		self::assertFalse($service->detectAdminError(
			administrationId: 'adm-1',
			customerId: 'klant-1',
			triggerContext: []
		));

	}//end testAdminErrorDetectorFlagsGoodCustomers()

	/**
	 * Task-23: detectAdminError prefers the AR `Invoice.paid` history over the
	 * legacy DunningRun heuristic once the AR core is present.
	 *
	 * @return void
	 */
	public function testAdminErrorDetectorPrefersInvoicePaidHistory(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'Invoice', rows: [
			[
				'id' => 'inv-1',
				'administrationId' => 'adm-1',
				'customerReference' => 'klant-1',
				'status' => 'paid',
				'paidOn' => (new \DateTimeImmutable('-15 days'))->format('Y-m-d'),
			],
		]);
		$service = $this->makeService(os: $os);

		self::assertTrue($service->detectAdminError(
			administrationId: 'adm-1',
			customerId: 'klant-1',
			triggerContext: ['paymentRefMissing' => true]
		));

		// No matching paid invoice + no DunningRun history → no flag.
		$os2 = new OpenRegisterFaithfulObjectService();
		$service2 = $this->makeService(os: $os2);
		self::assertFalse($service2->detectAdminError(
			administrationId: 'adm-1',
			customerId: 'klant-other',
			triggerContext: ['paymentRefMissing' => true]
		));

	}//end testAdminErrorDetectorPrefersInvoicePaidHistory()

	/**
	 * REQ-CCD-008 / task-20: transferToIncasso seals the DunningRun on DELIVERED.
	 *
	 * @return void
	 */
	public function testTransferToIncassoLocksRunOnDelivery(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningRun', rows: [
			[
				'id' => 'dr-1',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'stageNr' => 5,
				'channel' => 'COLLECTION_AGENCY_API',
				'lifecycleState' => 'executed',
				'deliveryStatus' => 'PENDING',
			],
		]);
		$service = $this->makeService(os: $os);

		$result = $service->transferToIncasso(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			dossier: ['invoiceId' => 'inv-1', 'content' => []],
			dunningRunId: 'dr-1'
		);

		self::assertSame('DELIVERED', $result->deliveryStatus);
		$sealed = $os->dump(schema: 'DunningRun');
		// The last persisted version is the sealed copy.
		$last = end($sealed);
		self::assertSame('locked', $last['lifecycleState']);
		self::assertSame('test-dossier', $last['postageStatus']['dossierId']);

	}//end testTransferToIncassoLocksRunOnDelivery()

	/**
	 * Task-20: transferToIncasso leaves the run on `executed` when the adapter fails.
	 *
	 * @return void
	 */
	public function testTransferToIncassoKeepsRunExecutedOnFailure(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningRun', rows: [
			[
				'id' => 'dr-1',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'stageNr' => 5,
				'lifecycleState' => 'executed',
			],
		]);
		$incasso = new class implements IncassoBureauAdapterInterface {
			public function transfer(string $administrationId, string $invoiceId, array $dossier): DunningChannelSendResult {
				return new DunningChannelSendResult(
					channel: 'COLLECTION_AGENCY_API',
					deliveryStatus: 'FAILED',
					errorMessage: 'connection refused',
				);
			}
		};
		$service = $this->makeService(os: $os, incasso: $incasso);

		$result = $service->transferToIncasso(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			dossier: ['invoiceId' => 'inv-1', 'content' => []],
			dunningRunId: 'dr-1'
		);

		self::assertSame('FAILED', $result->deliveryStatus);
		$rows = $os->dump(schema: 'DunningRun');
		self::assertSame('executed', end($rows)['lifecycleState']);

	}//end testTransferToIncassoKeepsRunExecutedOnFailure()

	/**
	 * REQ-CCD-008: the seal is PERSISTED BEFORE the dossier leaves.
	 *
	 * 🔑 This is the assertion the shipped code could not pass. It called the
	 * adapter first and sealed afterwards, with a lookup real OpenRegister
	 * answers with zero rows — so the dossier went to the collection agency
	 * and the run was never sealed at all. The spy reads the store from INSIDE
	 * `transfer()`, which is the only moment at which "sealed before dispatch"
	 * is distinguishable from "sealed after dispatch".
	 *
	 * @return void
	 */
	public function testTransferToIncassoSealsTheRunBeforeDispatching(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningRun', rows: [
			[
				'id' => 'dr-1',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'stageNr' => 5,
				'lifecycleState' => 'executed',
			],
		]);

		$incasso = new class ($os) implements IncassoBureauAdapterInterface {
			public array $stateAtDispatch = [];

			public function __construct(private OpenRegisterFaithfulObjectService $store) {
			}

			public function transfer(string $administrationId, string $invoiceId, array $dossier): DunningChannelSendResult {
				foreach ($this->store->dump('DunningRun') as $row) {
					$this->stateAtDispatch[] = (string)($row['lifecycleState'] ?? '');
				}

				return new DunningChannelSendResult(
					channel: 'COLLECTION_AGENCY_API',
					deliveryStatus: 'DELIVERED',
					extras: ['dossierId' => 'dossier-9'],
				);
			}
		};

		$service = $this->makeService(os: $os, incasso: $incasso);
		$service->transferToIncasso(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			dossier: ['invoiceId' => 'inv-1', 'content' => []],
			dunningRunId: 'dr-1'
		);

		self::assertSame(['locked'], $incasso->stateAtDispatch);

	}//end testTransferToIncassoSealsTheRunBeforeDispatching()

	/**
	 * REQ-CCD-008: an unresolvable run is NOT dispatched.
	 *
	 * A dossier sent against a run this app cannot find has no evidence trail
	 * and no re-dispatch guard, so the refusal has to happen before the
	 * adapter is reached — not be logged after it.
	 *
	 * @return void
	 */
	public function testTransferToIncassoRefusesToDispatchWhenTheRunIsAbsent(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$incasso = new class implements IncassoBureauAdapterInterface {
			public int $calls = 0;

			public function transfer(string $administrationId, string $invoiceId, array $dossier): DunningChannelSendResult {
				$this->calls++;
				return new DunningChannelSendResult(
					channel: 'COLLECTION_AGENCY_API',
					deliveryStatus: 'DELIVERED',
					extras: ['dossierId' => 'must-not-happen'],
				);
			}
		};

		$service = $this->makeService(os: $os, incasso: $incasso);
		$result = $service->transferToIncasso(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			dossier: ['invoiceId' => 'inv-1', 'content' => []],
			dunningRunId: 'dr-does-not-exist'
		);

		self::assertSame(0, $incasso->calls);
		self::assertSame('FAILED', $result->deliveryStatus);
		self::assertStringContainsString('not dispatched', (string)$result->errorMessage);

	}//end testTransferToIncassoRefusesToDispatchWhenTheRunIsAbsent()

	/**
	 * REQ-CCD-008: a sealed run is never handed to the agency a second time.
	 *
	 * @return void
	 */
	public function testTransferToIncassoRefusesToRedispatchASealedRun(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningRun', rows: [
			[
				'id' => 'dr-1',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'stageNr' => 5,
				'lifecycleState' => 'locked',
				'deliveryStatus' => 'DELIVERED',
				'postageStatus' => ['dossierId' => 'dossier-first'],
			],
		]);
		$incasso = new class implements IncassoBureauAdapterInterface {
			public int $calls = 0;

			public function transfer(string $administrationId, string $invoiceId, array $dossier): DunningChannelSendResult {
				$this->calls++;
				return new DunningChannelSendResult(
					channel: 'COLLECTION_AGENCY_API',
					deliveryStatus: 'DELIVERED',
					extras: ['dossierId' => 'dossier-second'],
				);
			}
		};

		$service = $this->makeService(os: $os, incasso: $incasso);
		$result = $service->transferToIncasso(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			dossier: ['invoiceId' => 'inv-1', 'content' => []],
			dunningRunId: 'dr-1'
		);

		self::assertSame(0, $incasso->calls);
		self::assertSame('dossier-first', $result->extras['dossierId']);
		self::assertCount(1, $os->dump(schema: 'DunningRun'));

	}//end testTransferToIncassoRefusesToRedispatchASealedRun()

	/**
	 * REQ-CCD-009 / task-21: sendRegisteredLetter captures barcode + tracking on the DunningRun.
	 *
	 * @return void
	 */
	public function testSendRegisteredLetterCapturesPostNLTrackingOnRun(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$os->seed(schema: 'DunningRun', rows: [
			[
				'id' => 'dr-1',
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-1',
				'stageNr' => 4,
				'channel' => 'REGISTERED_POST',
				'lifecycleState' => 'executed',
				'deliveryStatus' => 'PENDING',
			],
		]);
		$service = $this->makeService(os: $os);

		$result = $service->sendRegisteredLetter(
			administrationId: 'adm-1',
			dunningRunId: 'dr-1',
			payload: ['recipientAdres' => 'Voorbeeldstraat 1, 1234 AB Amsterdam', 'letterPdfRef' => 'docudesk:tpl-stage4-letter.pdf']
		);

		self::assertSame('DELIVERED', $result->deliveryStatus);
		$rows = $os->dump(schema: 'DunningRun');
		$last = end($rows);
		self::assertSame('3S1234567890123', $last['postageStatus']['barcode']);
		self::assertSame('https://postnl.nl/tracktrace/3S1234567890123', $last['postageStatus']['trackingUrl']);
		self::assertSame('DELIVERED', $last['deliveryStatus']);

	}//end testSendRegisteredLetterCapturesPostNLTrackingOnRun()

	/**
	 * Task-25: pause() rejects an evidenceRefs URI that does not match the
	 * `bookkeeping-document-attachment-integration` URI schemes (fail closed).
	 *
	 * @return void
	 */
	public function testPauseRejectsMalformedEvidenceUri(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$this->expectException(\InvalidArgumentException::class);
		$service->pause(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			reason: 'DISPUTED',
			details: 'Klant betwist',
			pausedBy: 'user-1',
			evidenceRefs: ['s3://my-bucket/evidence.pdf']
		);

	}//end testPauseRejectsMalformedEvidenceUri()

	/**
	 * Task-25: pause() accepts a well-formed evidenceRefs URI.
	 *
	 * @return void
	 */
	public function testPauseAcceptsWellFormedEvidenceUri(): void {
		$os = new OpenRegisterFaithfulObjectService();
		$service = $this->makeService(os: $os);

		$pause = $service->pause(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			reason: 'DISPUTED',
			details: 'Klant betwist',
			pausedBy: 'user-1',
			evidenceRefs: ['docudesk:files/dispute/email-2026-06-02-disputereactie.eml']
		);

		self::assertSame('active', $pause['lifecycleState']);
		self::assertCount(1, (array)$pause['evidenceRefs']);

	}//end testPauseAcceptsWellFormedEvidenceUri()

}//end class
