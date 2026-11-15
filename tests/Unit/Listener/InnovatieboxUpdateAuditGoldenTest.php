<?php

/**
 * Golden test for the update-event audit rows of InnovatieboxAuditTrailListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\InnovatieboxAuditTrailListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\VsoLockingValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins every audit row, year-lock lookup and log line per update event.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InnovatieboxUpdateAuditGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current scenario.
	 *
	 * @var list<array<int|string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testUpdateRowsMatchGolden(): void {
		$profit = static fn (array $over): array => array_merge(
			['qualifying_asset_id' => 'asset-1', 'administrationId' => 'adm-x', 'financialYear' => 2026, 'benefit_innovation_box' => 72000],
			$over
		);
		$scenarios = [
			'profitFinalized' => ['IBProfitAttribution', $profit(['vso_locked' => false]), $profit(['vso_locked' => true]), false],
			'profitPriorLocked' => [
				'IBProfitAttribution',
				$profit(['vso_locked' => true]),
				$profit(['vso_locked' => true, 'benefit_innovation_box' => 1]),
				false,
			],
			'profitUnlocking' => ['IBProfitAttribution', $profit(['vso_locked' => true]), $profit(['vso_locked' => false]), false],
			'profitYearLocked' => ['IBProfitAttribution', $profit([]), $profit(['benefit_innovation_box' => 2]), true],
			'profitYearOpen' => ['IBProfitAttribution', $profit([]), $profit(['benefit_innovation_box' => 3]), false],
			'profitNoAdmin' => ['IBProfitAttribution', $profit([]), $profit(['administrationId' => '']), true],
			'profitNoYear' => ['IBProfitAttribution', $profit([]), $profit(['financialYear' => null]), true],
			'profitStringYear' => ['IBProfitAttribution', $profit([]), $profit(['financialYear' => '2025', 'vso_locked' => 0]), true],
			'nexusAmended' => [
				'NexusCalculation',
				['administrationId' => 'adm-x', 'nexus_fraction' => 0.5],
				['administrationId' => 'adm-x', 'nexus_fraction' => 0.7, 'financialYear' => 2026],
				false,
			],
			'otherSchema' => ['Invoice', [], ['administrationId' => 'adm-x'], true],
		];
		$result = [];
		foreach ($scenarios as $name => [$slug, $prior, $next, $yearLocked]) {
			$this->calls = [];
			$listener = new InnovatieboxAuditTrailListener(
				$this->auditTrail(),
				$this->validator(locked: $yearLocked),
				$this->resolver(slug: $slug),
				$this->logger()
			);
			$listener->handle(new ObjectUpdatedEvent($this->entity(payload: $next), $this->entity(payload: $prior)));
			$result[$name] = $this->calls;
		}

		$file = __DIR__.'/fixtures/innovatiebox-update-audit.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('INNOVATIEBOX_UPDATE_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testUpdateRowsMatchGolden()

	/**
	 * An entity with a payload.
	 *
	 * @param array $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('4102');
		$entity->setObject($payload);
		$entity->setUuid('uuid-1');
		return $entity;

	}//end entity()

	/**
	 * A recording audit trail.
	 *
	 * @return AuditTrailMapper
	 */
	private function auditTrail(): AuditTrailMapper {
		$test = $this;
		return new class($test) extends AuditTrailMapper {

			/**
			 * Construct without the parent's database wiring.
			 *
			 * @param object $test Recorder.
			 */
			public function __construct(private object $test) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param ObjectAuditEvent $event The event.
			 *
			 * @return object
			 */
			public function recordObjectEvent(ObjectAuditEvent $event): object {
				$this->test->calls[] = ['audit' => $event->action, 'context' => $event->context];
				return new \stdClass();
			}//end recordObjectEvent()
		};

	}//end auditTrail()

	/**
	 * A recording year-lock validator.
	 *
	 * @param bool $locked The answer.
	 *
	 * @return VsoLockingValidator
	 */
	private function validator(bool $locked): VsoLockingValidator {
		$test = $this;
		return new class($locked, $test) extends VsoLockingValidator {

			/**
			 * Construct without the parent's wiring.
			 *
			 * @param bool   $locked The answer.
			 * @param object $test   Recorder.
			 */
			public function __construct(private readonly bool $locked, private object $test) {
			}//end __construct()

			/**
			 * Record and answer.
			 *
			 * @param string $administrationId The administration.
			 * @param int    $financialYear    The year.
			 *
			 * @return bool
			 */
			public function isYearLocked(string $administrationId, int $financialYear): bool {
				$this->test->calls[] = ['isYearLocked' => [$administrationId, $financialYear]];
				return $this->locked;
			}//end isYearLocked()
		};

	}//end validator()

	/**
	 * A resolver answering one slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(string $slug): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		return $resolver;

	}//end resolver()

	/**
	 * A logger recording into $this->calls.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {

			/**
			 * Construct.
			 *
			 * @param object $test Recorder.
			 */
			public function __construct(private object $test) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array              $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->test->calls[] = ['log' => [$level, (string) $message, $context]];
			}//end log()
		};

	}//end logger()
}//end class
