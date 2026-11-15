<?php

/**
 * Golden test for the innovatiebox create-event audit rows.
 *
 * Pins, per created schema, every audit row written on the subject's trail:
 * the action and the exact context — key order included, since the trail is
 * hash-chained — with nulls dropped, the ForfaitairCap twin row (by flag and
 * by computed cap), the CarryForwardLoss year fallback, and the refusal of a
 * row without an administration.
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

use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\InnovatieboxAuditTrailListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\VsoLockingValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden of the create-event audit rows.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InnovatieboxCreatedAuditGoldenTest extends TestCase {

	/**
	 * Path of the recorded golden.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/fixtures/innovatiebox-created-audit-golden.json';

	/**
	 * Created objects per case.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: ?string}> Cases.
	 */
	public static function created(): array {
		return [
			'nexus' => ['NexusCalculation', ['administrationId' => 'adm-1', 'financialYear' => '2026', 'qualifying_asset_id' => 'qa-1', 'own_rd_cost' => 1, 'uplift_factor' => 1.3, 'nexus_fraction_applied' => 0.9, 'other' => 'x'], 'uuid-n'],
			'nexus without asset or year' => ['register/NexusCalculation', ['administrationId' => 'adm-1', 'financialYear' => 'n/a', 'qualifying_asset_id' => ''], null],
			'profit' => ['IBProfitAttribution', ['administrationId' => 'adm-1', 'financialYear' => 2026, 'method' => 'nexus', 'qualifying_profit_for_nexus' => 1000, 'effective_rate' => 0.09], 'uuid-p'],
			'profit cap by flag' => ['IBProfitAttribution', ['administrationId' => 'adm-1', 'financialYear' => 2026, 'flat_rate_cap_applied' => true, 'qualifying_profit_for_nexus' => '30000', 'qualifying_profit_after_nexus' => 25000], 'uuid-c'],
			'profit cap by amount' => ['IBProfitAttribution', ['administrationId' => 'adm-1', 'method' => 'flat_rate_25pct', 'qualifying_profit_for_nexus' => 26000.5], 'uuid-d'],
			'profit flat under cap' => ['IBProfitAttribution', ['administrationId' => 'adm-1', 'method' => 'flat_rate_25pct', 'qualifying_profit_for_nexus' => 25000], 'uuid-e'],
			'loss by origin year' => ['CarryForwardLoss', ['administrationId' => 'adm-1', 'origin_boekjaar' => 2023, 'financialYear' => 2026, 'oorspronkelijk_bedrag' => 500, 'status' => 'open'], 'uuid-l'],
			'loss by financial year' => ['CarryForwardLoss', ['administrationId' => 'adm-1', 'financialYear' => 2026, 'balance_after' => 0], 'uuid-m'],
			'no administration' => ['NexusCalculation', ['financialYear' => 2026], 'uuid-x'],
			'other schema' => ['Invoice', ['administrationId' => 'adm-1'], 'uuid-i'],
		];

	}//end created()

	/**
	 * The rows written for each created object equal the golden.
	 *
	 * @param string               $slug    The resolved schema slug.
	 * @param array<string, mixed> $payload The object payload.
	 * @param string|null          $uuid    The object uuid.
	 *
	 * @return void
	 */
	#[DataProvider('created')]
	public function testCreatedRows(string $slug, array $payload, ?string $uuid): void {
		$trail = new class extends AuditTrailMapper {
			/**
			 * @var list<array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * @param ObjectAuditEvent $event The audit event.
			 *
			 * @return object
			 */
			public function recordObjectEvent(ObjectAuditEvent $event): object {
				$this->rows[] = ['action' => $event->action, 'objectUuid' => $event->object->getUuid(), 'context' => $event->context];
				return new \stdClass();
			}
		};
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$listener = new InnovatieboxAuditTrailListener($trail, $this->createMock(VsoLockingValidator::class), $resolver, $logger);

		$entity = new ObjectEntity();
		$entity->setSchema('4101');
		$entity->setObject($payload);
		$entity->setUuid($uuid);
		$listener->handle(new ObjectCreatedEvent($entity));

		$actual = ['rows' => $trail->rows, 'warnings' => $warnings];
		$key = $slug . ' ' . json_encode($payload);
		$golden = json_decode((string)(file_exists(self::GOLDEN) === true ? file_get_contents(self::GOLDEN) : '{}'), true);
		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			$golden[$key] = $actual;
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		self::assertSame($golden[$key], json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true));

	}//end testCreatedRows()
}//end class
