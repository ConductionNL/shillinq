<?php

/**
 * Golden test for PensionIas19Guard::canActivatePlan.
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
use OCA\Shillinq\Lifecycle\PensionIas19Guard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the activation decision, every lookup and every log line per plan.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PensionPlanActivationGoldenTest extends TestCase {

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
		$result = [];
		foreach ($this->scenarios() as $name => [$planId, $object]) {
			$this->calls = [];
			$result[$name] = ['allowed' => $this->guard()->canActivatePlan($planId, $object), 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/pension-plan-activation.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('PENSION_PLAN_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testDecisionsMatchGolden()

	/**
	 * Scenarios: [plan id, pre-loaded plan or null].
	 *
	 * @return array<string, array{0: string, 1: ?array}>
	 */
	private function scenarios(): array {
		$plan = static fn (array $over): array => array_merge(
			['planType' => 'DB', 'regulatoryFramework' => 'IAS19', 'pensionableSalaryDefinition' => 'base', 'accrualRate' => 1.75],
			$over
		);
		$cases = [
			'storedPlan' => ['plan-1', null],
			'missingPlan' => ['plan-404', null],
			'emptyId' => ['', null],
			'complete' => ['p', $plan([])],
			'unknownType' => ['p', $plan(['planType' => 'db'])],
			'noType' => ['p', ['regulatoryFramework' => 'IAS19', 'pensionableSalaryDefinition' => 'base']],
			'noFramework' => ['p', $plan(['regulatoryFramework' => ''])],
			'blankSalary' => ['p', $plan(['pensionableSalaryDefinition' => '   '])],
			'dcNoAccrual' => ['p', $plan(['planType' => 'DC', 'accrualRate' => null])],
			'throws' => ['p', $plan(['pensionableSalaryDefinition' => new \stdClass()])],
		];
		foreach (['DB', 'CDC', 'hybrid'] as $type) {
			$rates = ['zero' => 0, 'negative' => -1, 'text' => 'abc', 'numericString' => '0.5', 'leadingNumber' => '5abc', 'missing' => null];
			foreach ($rates as $label => $rate) {
				$cases[$type.'-'.$label] = ['p', $plan(['planType' => $type, 'accrualRate' => $rate])];
			}
		}

		return $cases;

	}//end scenarios()

	/**
	 * A guard over a recording store and logger.
	 *
	 * @return PensionIas19Guard
	 */
	private function guard(): PensionIas19Guard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturnCallback(
			function (array $config) {
				$this->calls[] = ['findAll', $config];
				if (($config['filters']['id'] ?? '') === 'plan-1') {
					return ['not a row', ['planType' => 'DC', 'regulatoryFramework' => 'IAS19', 'pensionableSalaryDefinition' => 'base']];
				}

				return [];
			}
		);
		return new PensionIas19Guard(appConfig: $appConfig, logger: $this->logger(), objectService: $service);

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
