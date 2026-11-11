<?php

/**
 * Golden test for PaymentRunExportService's generator discovery.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;

/**
 * Pins which generator classes are resolved, in which order, the formats
 * they are keyed under, the failure log, and the memoisation.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PaymentRunGeneratorDiscoveryGoldenTest extends TestCase {

	/**
	 * Recorded container lookups and log lines.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testDiscoveryMatchesGolden(): void {
		$result = [];
		foreach (['allResolve' => '', 'sepaFails' => 'SepaPain001Generator'] as $name => $failing) {
			$this->calls = [];
			$service = $this->service(failing: $failing);
			$method = new ReflectionMethod(PaymentRunExportService::class, 'generators');
			$first = $method->invoke($service);
			$second = $method->invoke($service);
			$result[$name] = [
				'generators' => array_map(static fn (object $generator): string => get_class($generator), $first),
				'memoised' => ($first === $second),
				'calls' => $this->calls,
			];
		}

		$file = __DIR__.'/fixtures/payment-run-generator-discovery.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('GENERATOR_DISCOVERY_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testDiscoveryMatchesGolden()

	/**
	 * A service whose container builds real generators, failing for one.
	 *
	 * @param string $failing Short class name to fail for, or ''.
	 *
	 * @return PaymentRunExportService
	 */
	private function service(string $failing): PaymentRunExportService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $class) use ($failing) {
				$this->calls[] = ['get', $class];
				if ($failing !== '' && str_ends_with($class, '\\'.$failing) === true) {
					throw new \RuntimeException('cannot build '.$failing);
				}

				return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
			}
		);
		return new PaymentRunExportService(
			container: $container,
			rootFolder: $this->createMock(IRootFolder::class),
			tagManager: $this->createMock(ISystemTagManager::class),
			tagMapper: $this->createMock(ISystemTagObjectMapper::class),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->logger(),
			blockChecker: (new \ReflectionClass(PaymentBlockChecker::class))->newInstanceWithoutConstructor(),
		);

	}//end service()

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
