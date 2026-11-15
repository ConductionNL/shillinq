<?php

/**
 * Golden test for PeriodCloseGuard::mandatoryChecklistResolved.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
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

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\PeriodCloseGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins the decision, every lookup and every log line per period.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PeriodChecklistGoldenTest extends TestCase {

	/**
	 * Recorded lookups and log lines of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testDecisionsMatchGolden(): void {
		$item = static fn (string $category, mixed $resolved): array => ['category' => $category, 'resolved' => $resolved];
		$scenarios = [
			'storedResolved' => 'fp-1',
			'storedMissing' => 'fp-404',
			'storeThrows' => 'fp-boom',
			'noItems' => [],
			'itemsNotArray' => ['taskChecklistItems' => 'x'],
			'allResolved' => ['taskChecklistItems' => [$item('ap', true), $item('ar', true), $item('gl', false)]],
			'apOpen' => ['taskChecklistItems' => [$item('ar', true), $item('ap', false)]],
			'arTruthy' => ['taskChecklistItems' => [$item('ar', 1)]],
			'arMissingFlag' => ['taskChecklistItems' => [['category' => 'ar']]],
			'upperCase' => ['taskChecklistItems' => [$item('AP', false)]],
			'nonArrayItem' => ['taskChecklistItems' => ['ap', null, $item('ap', true)]],
			'noCategory' => ['taskChecklistItems' => [['resolved' => false]]],
			'objectCategory' => ['taskChecklistItems' => [['category' => new \stdClass(), 'resolved' => true]]],
		];
		$result = [];
		foreach ($scenarios as $name => $period) {
			$this->calls = [];
			$result[$name] = ['resolved' => $this->guard()->mandatoryChecklistResolved($period), 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/period-checklist.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('PERIOD_CHECKLIST_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testDecisionsMatchGolden()

	/**
	 * A guard over a recording store and logger.
	 *
	 * @return PeriodCloseGuard
	 */
	private function guard(): PeriodCloseGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturnCallback(
			function (array $config) {
				$this->calls[] = ['findAll', $config];
				$id = ($config['filters']['id'] ?? '');
				if ($id === 'fp-boom') {
					throw new \RuntimeException('store down');
				}

				if ($id === 'fp-1') {
					return [['id' => 'fp-1', 'taskChecklistItems' => [['category' => 'ap', 'resolved' => true]]]];
				}

				return [];
			}
		);
		return new PeriodCloseGuard(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $appConfig,
			logger: $this->logger(),
			objectService: $service,
		);

	}//end guard()

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
