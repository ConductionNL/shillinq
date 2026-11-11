<?php

/**
 * Golden test for TransactionalGuard::lockResourceRow.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Booking
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins every query-builder, connection and log call of the row lock.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TransactionalGuardLockGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testLockMatchesGolden(): void {
		$result = [];
		$scenarios = [
			'noDb' => ['res-0', 'nodb'],
			'outsideTransaction' => ['res-1', 'notx'],
			'builderThrows' => ['res-"2"', 'builder'],
		];
		foreach ($scenarios as $name => [$resourceId, $mode]) {
			$this->calls = [];
			$guard = new TransactionalGuard(db: $this->db(mode: $mode), logger: $this->logger());
			try {
				$result[$name] = ['locked' => $guard->lockResourceRow($resourceId), 'calls' => $this->calls];
			} catch (\LogicException $e) {
				$result[$name] = ['exception' => [get_class($e), $e->getMessage()], 'calls' => $this->calls];
			}
		}

		$file = __DIR__.'/fixtures/transactional-guard-lock.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('ROW_LOCK_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testLockMatchesGolden()

	/**
	 * A recording connection, or null for 'nodb'.
	 *
	 * The query-building path itself cannot be exercised here: mocking
	 * IQueryBuilder needs Doctrine\DBAL, which this test stack does not ship.
	 *
	 * @param string $mode 'nodb', 'notx' or 'builder'.
	 *
	 * @return IDBConnection|null
	 */
	private function db(string $mode): ?IDBConnection {
		if ($mode === 'nodb') {
			return null;
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn () => $this->record(call: ['inTransaction'], value: $mode !== 'notx'));
		$db->method('getQueryBuilder')->willReturnCallback(
			function () {
				$this->calls[] = ['getQueryBuilder'];
				throw new \RuntimeException('no builder');
			}
		);
		$db->method('executeQuery')->willReturnCallback(fn () => $this->record(call: ['executeQuery'], value: null));
		return $db;

	}//end db()

	/**
	 * Record a call and return a value.
	 *
	 * @param array $call  The call.
	 * @param mixed $value The value to return.
	 *
	 * @return mixed
	 */
	private function record(array $call, mixed $value): mixed {
		$this->calls[] = $call;
		return $value;

	}//end record()

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
				$this->test->calls[] = ['log', $level, (string) $message, $context];
			}//end log()
		};

	}//end logger()
}//end class
