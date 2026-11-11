<?php

/**
 * Golden over the T4 fields ReconciliationMatchToReportListener stamps.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\ReconciliationMatchToReportListener;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins how each event shape is read (snapshot, schema source) and which
 * update every confirmed match receives. `matchedAt` is the wall clock, so
 * it is checked for format and left out of the golden.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ReconciliationMatchStampGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/reconciliation-match-stamp-golden.json';

	/**
	 * An OR-style event carrying a snapshot and, optionally, a schema.
	 *
	 * @param mixed $object    What getObject() answers.
	 * @param mixed $schema    What getSchema() answers.
	 * @param bool  $hasSchema Whether getSchema() exists at all.
	 *
	 * @return Event
	 */
	private static function event(mixed $object, mixed $schema = null, bool $hasSchema = true): Event {
		if ($hasSchema === false) {
			return new class($object) extends Event {
				/**
				 * @param mixed $object Snapshot.
				 */
				public function __construct(private mixed $object) {
					parent::__construct();
				}

				/**
				 * @return mixed
				 */
				public function getObject(): mixed {
					return $this->object;
				}
			};
		}

		return new class($object, $schema) extends Event {
			/**
			 * @param mixed $object Snapshot.
			 * @param mixed $schema Schema.
			 */
			public function __construct(private mixed $object, private mixed $schema) {
				parent::__construct();
			}

			/**
			 * @return mixed
			 */
			public function getObject(): mixed {
				return $this->object;
			}

			/**
			 * @return mixed
			 */
			public function getSchema(): mixed {
				return $this->schema;
			}
		};
	}//end event()

	/**
	 * A jsonSerialize() wrapper, like an ObjectEntity.
	 *
	 * @param mixed $data What jsonSerialize() answers.
	 *
	 * @return object
	 */
	private static function entity(mixed $data): object {
		return new class($data) implements \JsonSerializable {
			/**
			 * @param mixed $data Data.
			 */
			public function __construct(private mixed $data) {
			}

			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * Every scenario: name => event.
	 *
	 * @return array<string,Event>
	 */
	private static function scenarios(): array {
		$slugged = new class {
			/**
			 * @return string
			 */
			public function getSlug(): string {
				return 'ReconciliationMatch';
			}
		};
		$base = ['status' => 'confirmed', 'matchedObjectId' => 'obj-9'];
		return [
			'entity-string-schema-ar' => self::event(self::entity($base + ['id' => 'm1', 'matchType' => 'ar-invoice',
				'bankStatementLineId' => 'bsl-1', 'confidence' => 'auto', 'confidenceScore' => '0.75']), 'reconciliationmatch'),
			'slug-object-journal-manual' => self::event($base + ['id' => 'm2', 'matchType' => 'journal', 'confidence' => 'manual'], $slugged),
			'self-schema-suspense' => self::event(
				$base + ['matchId' => 'm3', 'matchType' => 'suspense', '@self' => ['schema' => 'ReconciliationMatch']],
				null,
				false
			),
			'int-schema-falls-back-ap' => self::event(
				$base + ['id' => 'm4', 'matchType' => 'ap-invoice', '@self' => ['schema' => 'ReconciliationMatch']],
				42
			),
			'gl-unresolved-line' => self::event(
				$base + ['id' => 'm5', 'matchType' => 'gl-transaction', 'bankStatementLineId' => 'bsl-x'],
				'ReconciliationMatch'
			),
			'empty-matched-object' => self::event(
				['id' => 'm6', 'status' => 'confirmed', 'matchType' => 'ar-invoice', 'matchedObjectId' => ''],
				'ReconciliationMatch'
			),
			'statement-without-session' => self::event(
				$base + ['id' => 'm7', 'matchType' => 'ar-invoice', 'bankStatementLineId' => 'bsl-2'],
				'ReconciliationMatch'
			),
			'other-schema' => self::event($base + ['id' => 'n1'], 'BankStatementLine'),
			'not-confirmed' => self::event(['id' => 'n2', 'status' => 'proposed'], 'ReconciliationMatch'),
			'already-stamped' => self::event($base + ['id' => 'n3', 'matchAlgorithm' => 'exact'], 'ReconciliationMatch'),
			'no-id' => self::event($base, 'ReconciliationMatch'),
			'scalar-snapshot' => self::event('nope', 'ReconciliationMatch'),
			'entity-scalar-snapshot' => self::event(self::entity('nope'), 'ReconciliationMatch'),
			'no-schema-anywhere' => self::event($base + ['id' => 'n4'], null, false),
		];
	}//end scenarios()

	/**
	 * Run one event through a fresh listener and answer what it saved.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function saved(Event $event): array {
		$saved = [];
		$store = new InMemoryObjectServiceStub(
			[
				'BankStatementLine' => [['id' => 'bsl-1', 'statementId' => 'st-1'], ['id' => 'bsl-2', 'statementId' => 'st-2']],
				'BankStatement' => [
					['id' => 'st-1', 'bankAccountIban' => 'NL01BANK0123456789', 'periodEnd' => '2026-03-31'],
					['id' => 'st-2', 'bankAccountIban' => 'NL01BANK0123456789', 'periodEnd' => '2026-04-30'],
				],
				'BankReconciliation' => [
					['id' => 'rec-closed', 'bankAccountId' => 'NL01BANK0123456789', 'statementPeriodEnd' => '2026-03-31', 'reconciliationStatus' => 'closed'],
					['id' => 'rec-1', 'bankAccountId' => 'NL01BANK0123456789', 'statementPeriodEnd' => '2026-03-31', 'reconciliationStatus' => 'in-progress'],
				],
			],
			$saved
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		(new ReconciliationMatchToReportListener($appConfig, new NullLogger(), $store))->handle($event);
		foreach ($saved as $index => $row) {
			self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $row['object']['matchedAt']);
			unset($saved[$index]['object']['matchedAt']);
		}

		return $saved;
	}//end saved()

	/**
	 * Every scenario saves exactly what the pre-refactor listener saved.
	 *
	 * @return void
	 */
	public function testStampedFieldsMatchGolden(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $event) {
			$actual[$name] = $this->saved($event);
		}

		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		self::assertSame($expected, json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testStampedFieldsMatchGolden()
}//end class
