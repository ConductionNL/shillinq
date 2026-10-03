<?php

/**
 * FeeScheduleValidationListenerTest — the write path actually runs the rules.
 *
 * These cases assert the WIRING from the caller's side, which is the thing
 * that was missing: FeeScheduleService::assertNoOverlap() had nine passing
 * tests and no call site, so it enforced nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\FeeScheduleValidationListener;
use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Covers OCA\Shillinq\Listener\FeeScheduleValidationListener.
 */
final class FeeScheduleValidationListenerTest extends TestCase {

	/**
	 * A schedule overlapping a published one is refused, and the refusal names
	 * the schedule it collides with.
	 *
	 * @return void
	 */
	public function testAnOverlappingScheduleIsRefusedOnCreate(): void {
		$event = $this->creatingEvent($this->schedule(['id' => 'fs-2', 'validFrom' => '2026-06-01']));

		$this->listener(matches: true, stored: [$this->schedule()])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('overlaps', (string)($event->getErrors()['message'] ?? ''));
	}//end testAnOverlappingScheduleIsRefusedOnCreate()

	/**
	 * The same rule holds on update, which is the write a client reaches for
	 * when a create is refused.
	 *
	 * @return void
	 */
	public function testAnOverlappingScheduleIsRefusedOnUpdate(): void {
		$event = $this->updatingEvent($this->schedule(['id' => 'fs-2', 'validFrom' => '2026-06-01']));

		$this->listener(matches: true, stored: [$this->schedule()])->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnOverlappingScheduleIsRefusedOnUpdate()

	/**
	 * A schedule naming no council decision is refused too: the listener runs
	 * the whole rule, not only the overlap half.
	 *
	 * @return void
	 */
	public function testAScheduleWithoutALegalBasisIsRefused(): void {
		$event = $this->creatingEvent($this->schedule(['legalBasis' => []]));

		$this->listener(matches: true, stored: [])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('legalBasis', (string)($event->getErrors()['message'] ?? ''));
	}//end testAScheduleWithoutALegalBasisIsRefused()

	/**
	 * A schedule that breaks no rule is written.
	 *
	 * @return void
	 */
	public function testAValidScheduleIsLetThrough(): void {
		$event = $this->creatingEvent($this->schedule(['id' => 'fs-2', 'validFrom' => '2027-01-01', 'validTo' => '2027-12-31']));

		$this->listener(matches: true, stored: [$this->schedule()])->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAValidScheduleIsLetThrough()

	/**
	 * Updating the SAME schedule is not an overlap with itself.
	 *
	 * @return void
	 */
	public function testAScheduleDoesNotOverlapItself(): void {
		$event = $this->updatingEvent($this->schedule());

		$this->listener(matches: true, stored: [$this->schedule()])->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAScheduleDoesNotOverlapItself()

	/**
	 * Another schema's write is untouched, even one that would break every
	 * fee-schedule rule.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreUntouched(): void {
		$event = $this->creatingEvent(['anything' => 'at all']);

		$this->listener(matches: false, stored: [$this->schedule()])->handle($event);

		self::assertFalse($event->isPropagationStopped(), 'The listener must be scoped to FeeSchedule.');
	}//end testOtherSchemasAreUntouched()

	/**
	 * Build the listener over a FeeScheduleService reading the given rows.
	 *
	 * @param bool $matches Whether the entity is a FeeSchedule.
	 * @param array<int, array<string, mixed>> $stored The schedules already published.
	 *
	 * @return FeeScheduleValidationListener The listener.
	 */
	private function listener(bool $matches, array $stored): FeeScheduleValidationListener {
		$double = new class(['FeeSchedule' => $stored]) {
			/**
			 * The schema the fluent chain last selected.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->rows[$this->schema] ?? []);
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturn($matches);

		return new FeeScheduleValidationListener(
			feeSchedules: new FeeScheduleService(
				objectService: new DuckObjectServiceAdapter(inner: $double),
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			),
			schemaResolver: $resolver,
			logger: new NullLogger(),
		);
	}//end listener()

	/**
	 * One published schedule.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function schedule(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'fs-1',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'legalBasis' => [
					'regulation' => 'Legesverordening 2026',
					'article' => '2.3.1',
					'effectiveDate' => '2026-01-01',
				],
				'amount' => 245.0,
				'currency' => 'EUR',
				'payAtIntake' => 'required',
				'validFrom' => '2026-01-01',
				'validTo' => '2026-12-31',
				'intakeChannel' => '',
			],
			$overrides
		);
	}//end schedule()

	/**
	 * An ObjectCreatingEvent carrying the given payload.
	 *
	 * @param array<string, mixed> $data The object payload being written.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function creatingEvent(array $data): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('FeeSchedule');
		$entity->setRegister('shillinq');

		return new ObjectCreatingEvent($entity);
	}//end creatingEvent()

	/**
	 * An ObjectUpdatingEvent carrying the given payload.
	 *
	 * @param array<string, mixed> $data The object payload being written.
	 *
	 * @return ObjectUpdatingEvent The event.
	 */
	private function updatingEvent(array $data): ObjectUpdatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('FeeSchedule');
		$entity->setRegister('shillinq');

		return new ObjectUpdatingEvent($entity);
	}//end updatingEvent()
}//end class
