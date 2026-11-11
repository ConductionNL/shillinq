<?php

/**
 * Golden test for KlantLadderOverrideApprovalGuard::isApprovedForElevatedStages.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\KlantLadderOverrideApprovalGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the decision, every read and every log line per override.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KlantLadderOverrideApprovalGuardGoldenTest extends TestCase {

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
	public function testDecisionsMatchGolden(): void {
		$result = [];
		foreach ($this->scenarios() as $name => [$register, $rows]) {
			$this->calls = [];
			$guard = new KlantLadderOverrideApprovalGuard(
				appConfig: $this->appConfig(register: $register),
				logger: $this->logger(),
				objectService: $this->objectService(rows: $rows),
			);
			$result[$name] = ['approved' => $guard->isApprovedForElevatedStages('ov-1'), 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/klantladder-override-approval.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('KLANTLADDER_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testDecisionsMatchGolden()

	/**
	 * Scenarios: [configured register, rows returned or 'throw'].
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	private function scenarios(): array {
		$approved = ['approvedBy' => 'mgr', 'approvedAt' => '2026-01-01'];
		$elevated = ['overrides' => ['stages' => [['nr' => 2], ['nr' => 4]]]];
		$json = new class(['overrides' => ['stages' => [['nr' => 5]]]]) implements \JsonSerializable {

			/**
			 * Construct.
			 *
			 * @param array $data Data.
			 */
			public function __construct(private array $data) {
			}//end __construct()

			/**
			 * Serialise.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};
		return [
			'notFound' => ['shillinq', []],
			'emptyRegister' => ['', [['overrides' => ['stages' => []]]]],
			'customRegister' => ['reg-x', [['overrides' => ['stages' => [['nr' => 3]]]]]],
			'noOverrides' => ['shillinq', [[]]],
			'stagesNotArray' => ['shillinq', [['overrides' => ['stages' => 'x']]]],
			'stageWithoutNr' => ['shillinq', [['overrides' => ['stages' => [[], ['nr' => '3']]]]]],
			'stageString' => ['shillinq', [['overrides' => ['stages' => ['abc', ['nr' => 1]]]]]],
			'elevatedApproved' => ['shillinq', [array_merge($elevated, $approved)]],
			'elevatedNoApprover' => ['shillinq', [array_merge($elevated, ['approvedAt' => '2026-01-01'])]],
			'elevatedNoDate' => ['shillinq', [array_merge($elevated, ['approvedBy' => 'mgr'])]],
			'elevatedBlankApprover' => ['shillinq', [array_merge($elevated, ['approvedBy' => '', 'approvedAt' => 'x'])]],
			'elevatedStringNr' => ['shillinq', [['overrides' => ['stages' => [['nr' => '4.9']]], 'approvedBy' => 'a', 'approvedAt' => 'b']]],
			'elevatedHigh' => ['shillinq', [['overrides' => ['stages' => [['nr' => 7]]]]]],
			'firstRowOnly' => ['shillinq', [['overrides' => ['stages' => [['nr' => 1]]]], $elevated]],
			'objectRow' => ['shillinq', [$json]],
			'storeThrows' => ['shillinq', 'throw'],
		];

	}//end scenarios()

	/**
	 * App config answering the register key.
	 *
	 * @param string $register The configured register.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $register): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (...$args) use ($register) {
				$this->calls[] = array_merge(['getValueString'], $args);
				return $register;
			}
		);
		return $config;

	}//end appConfig()

	/**
	 * A recording object service.
	 *
	 * @param mixed $rows Rows to return, or 'throw'.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(mixed $rows): ObjectServiceInterface {
		$service = $this->createMock(ObjectServiceInterface::class);
		foreach (['setRegister', 'setSchema'] as $method) {
			$service->method($method)->willReturnCallback(
				function (...$args) use ($method, $service) {
					$this->calls[] = array_merge([$method], $args);
					return $service;
				}
			);
		}

		$service->method('findAll')->willReturnCallback(
			function (...$args) use ($rows) {
				$this->calls[] = array_merge(['findAll'], $args);
				if ($rows === 'throw') {
					throw new \RuntimeException('store down');
				}

				return $rows;
			}
		);
		return $service;

	}//end objectService()

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
				$text = preg_replace('/class@anonymous\S*/', 'class@anonymous', (string) $message);
				$this->test->calls[] = ['log', $level, $text, $context];
			}//end log()
		};

	}//end logger()
}//end class
