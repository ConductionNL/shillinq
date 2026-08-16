<?php

/**
 * Unit tests for the InnovatieboxAuditTrailListener.
 *
 * Verifies the listener's contract for the three innovatiebox subject
 * schemas (NexusCalculation, IBProfitAttribution, CarryForwardLoss):
 *
 *   - *.created events emit the corresponding InnovatieboxAuditEvent.
 *   - IBProfitAttribution update with vso_locked false -> true emits
 *     IBProfitAttribution.finalized.
 *   - IBProfitAttribution update with prior vso_locked = true emits
 *     IBProfitAttribution.amendment_attempt_blocked with reason vso_locked.
 *   - CarryForwardLoss update with a grown verrekend_boekjaar array emits
 *     CarryForwardLoss.offset_applied with the new entries.
 *   - IBProfitAttribution.created with a forfaitair election that hit the
 *     EUR 25k cap emits ForfaitairCap.applied as a twin event.
 *   - Non-innovatiebox schemas are skipped.
 *   - Listener handler NEVER raises.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\InnovatieboxAuditTrailListener;
use OCA\Shillinq\Service\InnovatieboxAuditEventLogger;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\VsoLockingValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Verifies the listener orchestrates the per-event audit append (REQ-IBA-008).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InnovatieboxAuditTrailListenerTest extends TestCase {
	/**
	 * Build a recording logger.
	 *
	 * @return AbstractLogger
	 */
	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $records = [];

			/**
			 * @param mixed $level Level.
			 * @param string|\Stringable $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			}//end log()
		};

	}//end recordingLogger()

	/**
	 * Build a fake InnovatieboxAuditEventLogger that captures every record() call.
	 *
	 * @return InnovatieboxAuditEventLogger&object{calls: array<int,array<string,mixed>>}
	 */
	private function fakeLogger(): InnovatieboxAuditEventLogger {
		return new class extends InnovatieboxAuditEventLogger {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			public function __construct() {
				// Skip parent constructor — we never touch the OR write path.

			}//end __construct()

			/**
			 * Capture the record call without touching OR.
			 *
			 * @param array<string,mixed> $options Event payload.
			 *
			 * @return bool
			 */
			public function record(array $options): bool {
				$this->calls[] = $options;
				return true;
			}//end record()
		};

	}//end fakeLogger()

	/**
	 * Build a fake VsoLockingValidator that returns a constant lock state.
	 *
	 * @param bool $locked Lock state.
	 *
	 * @return VsoLockingValidator
	 */
	private function fakeVsoValidator(bool $locked): VsoLockingValidator {
		return new class($locked) extends VsoLockingValidator {
			public function __construct(
				private readonly bool $constantLock,
			) {
				// Skip parent constructor — we don't touch the OR write path.

			}//end __construct()

			public function isYearLocked(string $administrationId, int $financialYear): bool {
				return $this->constantLock;
			}//end isYearLocked()
		};

	}//end fakeVsoValidator()

	/**
	 * Build an ObjectEntity stub carrying a numeric schema **id**, exactly as
	 * OpenRegister stamps it (`setSchema((string) $schema->getId())`).
	 *
	 * A hand-built entity carrying the slug is a shape production never
	 * produces; the slug arrives through {@see ListenerSchemaResolver}.
	 *
	 * @param string $schemaId Numeric schema id as OR stamps it.
	 * @param array<string,mixed> $payload Object payload.
	 * @param string|null $uuid Optional uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schemaId, array $payload, ?string $uuid = 'uuid-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schemaId);
		$entity->setObject($payload);
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	/**
	 * Build a ListenerSchemaResolver stub that reports a given schema slug.
	 *
	 * @param string $slug Slug the resolver resolves the entity's id to.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(string $slug): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		return $resolver;
	}//end resolver()

	/**
	 * A NexusCalculation create emits NexusCalculation.calculated with the
	 * R&D breakdown sliced into the details blob.
	 *
	 * @return void
	 */
	public function testNexusCreateEmitsCalculatedEvent(): void {
		$logger = $this->fakeLogger();
		$vso = $this->fakeVsoValidator(false);
		$listener = new InnovatieboxAuditTrailListener(
			$logger,
			$vso,
			$this->resolver('NexusCalculation'),
			$this->recordingLogger()
		);

		$entity = $this->entity('4101', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'financialYear' => 2026,
			'own_rd_cost' => 480000,
			'rd_cost_outsourced_third_parties' => 120000,
			'rd_cost_outsourced_affiliated' => 80000,
			'uplift_factor' => 1.3,
			'nexusbreuk_ongecapt' => 1.10,
			'nexus_fraction_applied' => 1.0,
		]);
		$event = new ObjectCreatedEvent($entity);

		$listener->handle($event);

		$this->assertCount(1, $logger->calls);
		$this->assertSame(
			InnovatieboxAuditEventLogger::EVENT_NEXUS_CALCULATED,
			$logger->calls[0]['event_type']
		);
		$this->assertSame('asset-1', $logger->calls[0]['qualifying_asset_id']);
		$this->assertSame(2026, $logger->calls[0]['financialYear']);
		$this->assertSame(1.0, $logger->calls[0]['details']['nexus_fraction_applied']);

	}//end testNexusCreateEmitsCalculatedEvent()

	/**
	 * IBProfitAttribution create emits .created; if the methode is
	 * forfaitair_25pct and pre-cap qualifying profit > 25k a twin
	 * ForfaitairCap.applied event is recorded.
	 *
	 * @return void
	 */
	public function testForfaitairCreateEmitsCapAppliedTwinEvent(): void {
		$logger = $this->fakeLogger();
		$listener = new InnovatieboxAuditTrailListener(
			$logger,
			$this->fakeVsoValidator(false),
			$this->resolver('IBProfitAttribution'),
			$this->recordingLogger()
		);

		$entity = $this->entity('4102', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'financialYear' => 2026,
			'method' => 'flat_rate_25pct',
			'qualifying_profit_for_nexus' => 125000,
			'qualifying_profit_after_nexus' => 25000,
			'vso_locked' => false,
		]);
		$event = new ObjectCreatedEvent($entity);

		$listener->handle($event);

		$types = array_column($logger->calls, 'event_type');
		$this->assertContains(InnovatieboxAuditEventLogger::EVENT_PROFIT_CREATED, $types);
		$this->assertContains(InnovatieboxAuditEventLogger::EVENT_FORFAITAIR_CAP_APPLIED, $types);

	}//end testForfaitairCreateEmitsCapAppliedTwinEvent()

	/**
	 * IBProfitAttribution update vso_locked false -> true emits the
	 * .finalized event with reason vso_signed.
	 *
	 * @return void
	 */
	public function testProfitFinalizedFiresWhenVsoFlagFlips(): void {
		$logger = $this->fakeLogger();
		$listener = new InnovatieboxAuditTrailListener(
			$logger,
			$this->fakeVsoValidator(false),
			$this->resolver('IBProfitAttribution'),
			$this->recordingLogger()
		);

		$prior = $this->entity('4102', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'financialYear' => 2026,
			'vso_locked' => false,
		]);
		$next = $this->entity('4102', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'financialYear' => 2026,
			'vso_locked' => true,
		]);
		$event = new ObjectUpdatedEvent($next, $prior);

		$listener->handle($event);

		$this->assertCount(1, $logger->calls);
		$this->assertSame(
			InnovatieboxAuditEventLogger::EVENT_PROFIT_FINALIZED,
			$logger->calls[0]['event_type']
		);
		$this->assertSame('vso_signed', $logger->calls[0]['reason']);

	}//end testProfitFinalizedFiresWhenVsoFlagFlips()

	/**
	 * IBProfitAttribution update when the prior state is vso_locked = true
	 * is recorded as an amendment_attempt_blocked event with reason vso_locked.
	 *
	 * @return void
	 */
	public function testProfitAmendmentBlockedWhenPriorWasLocked(): void {
		$logger = $this->fakeLogger();
		$listener = new InnovatieboxAuditTrailListener(
			$logger,
			$this->fakeVsoValidator(true),
			$this->resolver('IBProfitAttribution'),
			$this->recordingLogger()
		);

		$prior = $this->entity('4102', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'financialYear' => 2026,
			'vso_locked' => true,
			'benefit_innovation_box' => 72000,
		]);
		$next = $this->entity('4102', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'financialYear' => 2026,
			'vso_locked' => true,
			'benefit_innovation_box' => 80000,
		]);
		$event = new ObjectUpdatedEvent($next, $prior);

		$listener->handle($event);

		$this->assertCount(1, $logger->calls);
		$this->assertSame(
			InnovatieboxAuditEventLogger::EVENT_PROFIT_AMENDMENT_BLOCKED,
			$logger->calls[0]['event_type']
		);
		$this->assertSame('vso_locked', $logger->calls[0]['reason']);
		$this->assertContains('benefit_innovation_box', $logger->calls[0]['details']['changed_keys']);

	}//end testProfitAmendmentBlockedWhenPriorWasLocked()

	/**
	 * CarryForwardLoss update with a grown verrekend_boekjaar emits the
	 * offset_applied event with the new entries in details.
	 *
	 * @return void
	 */
	public function testLossOffsetAppliedFiresOnVerrekendGrowth(): void {
		$logger = $this->fakeLogger();
		$listener = new InnovatieboxAuditTrailListener(
			$logger,
			$this->fakeVsoValidator(false),
			$this->resolver('CarryForwardLoss'),
			$this->recordingLogger()
		);

		$prior = $this->entity('4103', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'origin_boekjaar' => 2024,
			'settled_financial_year' => [],
			'balance_after' => 215000,
			'status' => 'open',
		]);
		$next = $this->entity('4103', [
			'qualifying_asset_id' => 'asset-1',
			'administrationId' => 'adm-x',
			'origin_boekjaar' => 2024,
			'settled_financial_year' => [['year' => 2026, 'amount' => 215000, 'balance_after' => 0]],
			'balance_after' => 0,
			'status' => 'consumed',
		]);
		$event = new ObjectUpdatedEvent($next, $prior);

		$listener->handle($event);

		$this->assertCount(1, $logger->calls);
		$this->assertSame(
			InnovatieboxAuditEventLogger::EVENT_LOSS_OFFSET_APPLIED,
			$logger->calls[0]['event_type']
		);
		$this->assertSame(
			215000,
			$logger->calls[0]['details']['new_entries'][0]['amount']
		);

	}//end testLossOffsetAppliedFiresOnVerrekendGrowth()

	/**
	 * Non-innovatiebox schemas (e.g. GLLine) are silently skipped.
	 *
	 * @return void
	 */
	public function testNonInnovatieboxSchemaIsIgnored(): void {
		$logger = $this->fakeLogger();
		$listener = new InnovatieboxAuditTrailListener(
			$logger,
			$this->fakeVsoValidator(false),
			$this->resolver('GLLine'),
			$this->recordingLogger()
		);

		$entity = $this->entity('4207', ['administrationId' => 'adm-x']);
		$event = new ObjectCreatedEvent($entity);

		$listener->handle($event);

		$this->assertSame([], $logger->calls);

	}//end testNonInnovatieboxSchemaIsIgnored()
}//end class
