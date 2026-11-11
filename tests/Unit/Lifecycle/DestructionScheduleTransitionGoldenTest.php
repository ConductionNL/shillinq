<?php

/**
 * Golden over the full DestructionScheduleGuard transition matrix.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\DestructionScheduleGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins every from/to pair against every actor/age combination, so a
 * restructure of canTransition() cannot silently open or close an arrow.
 */
class DestructionScheduleTransitionGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/destruction-schedule-transition-golden.json';

	private const FROM_TO = ['active', 'marked-for-destruction', 'destruction-completed', 'bogus', ''];

	/**
	 * Actor / record-age combinations; `now` is passed explicitly.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>}>
	 */
	private static function contexts(): array {
		$old = ['id' => 'r1', 'createdAt' => '2015-01-01T00:00:00+00:00'];
		$young = ['id' => 'r2', 'created' => '2024-06-01T00:00:00+00:00'];
		$officer = ['actorRoles' => ['compliance-officer'], 'now' => '2026-11-10T12:00:00+00:00'];
		return [
			'officer-old' => [$old, $officer],
			'officer-young' => [$young, $officer],
			'officer-undated' => [['id' => 'r3'], $officer],
			'officer-bad-date' => [['recordedAt' => 'not a date'], $officer],
			'clerk-old' => [$old, ['actorRoles' => ['clerk'], 'now' => '2026-11-10T12:00:00+00:00']],
			'system-old' => [$old, ['actorUid' => 'system', 'now' => '2026-11-10T12:00:00+00:00']],
			'runner-old' => [$old, ['actorUid' => 'shillinq-destruction-runner']],
			'nobody' => [$old, []],
		];
	}//end contexts()

	/**
	 * The whole matrix matches the golden recorded on the pre-refactor guard.
	 *
	 * @return void
	 */
	public function testTransitionMatrix(): void {
		$guard = new DestructionScheduleGuard(logger: new NullLogger());
		$actual = [];
		foreach (self::contexts() as $name => [$record, $options]) {
			foreach (self::FROM_TO as $from) {
				foreach (self::FROM_TO as $to) {
					$actual[$name][$from . ' -> ' . $to] = $guard->canTransition($from, $to, $record, $options);
				}
			}
		}

		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(count: 1);
			return;
		}

		self::assertSame(expected: json_decode((string)file_get_contents(self::GOLDEN), true), actual: $actual);
	}//end testTransitionMatrix()
}//end class
