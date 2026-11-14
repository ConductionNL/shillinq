<?php

/**
 * Characterisation test for ENSIAValidationGuard::collegeAkkoordAllowed.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

use OCA\Shillinq\Lifecycle\ENSIAValidationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per scenario.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class ENSIACollegeAkkoordTest extends TestCase {

	public function testOutcomes(): void {
		$filterCall = [
			'register:shillinq',
			'schema:Evaluatievraag',
			['findAll' => ['filters' => ['cyclusId' => 'cy-1', 'peerReviewStatus' => 'wijziging-gevraagd'], 'limit' => 1]],
		];

		self::assertSame(['result' => true, 'logs' => [], 'calls' => []], $this->outcome([], []));
		self::assertSame(
			[
				'result' => true,
				'logs' => [],
				'calls' => [
					'register:shillinq',
					'schema:Evaluatievraag',
					['findAll' => ['filters' => ['cyclusId' => 'u-1', 'peerReviewStatus' => 'wijziging-gevraagd'], 'limit' => 1]],
				],
			],
			$this->outcome(['uuid' => 'u-1'], [])
		);
		self::assertSame(['result' => true, 'logs' => [], 'calls' => $filterCall], $this->outcome(['id' => 'cy-1'], []));
		self::assertSame(
			[
				'result' => false,
				'logs' => [['info', 'ENSIAValidationGuard: blocking college-akkoord — unresolved peer-review wijzigingen', ['cyclusId' => 'cy-1']]],
				'calls' => $filterCall,
			],
			$this->outcome(['id' => 'cy-1'], [['id' => 'q-1']])
		);
		self::assertSame(
			[
				'result' => true,
				'logs' => [
					[
						'debug',
						'ENSIAValidationGuard: unresolved-wijziging lookup failed; permissive bypass',
						[
							'cyclusId' => 'cy-1',
							'exception' => 'store down',
						],
					],
				],
				'calls' => $filterCall,
			],
			$this->outcome(['id' => 'cy-1'], '*throw*')
		);
		self::assertSame(
			[
				'result' => true,
				'logs' => [['debug', 'ENSIAValidationGuard: ObjectService lookup failed; bypassing gate', ['exception' => 'no OR']]],
				'calls' => [],
			],
			$this->outcome(['id' => 'cy-1'], '*nocontainer*')
		);
	}

	/**
	 * Run one cycle through the gate and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $cyclus The ENSIAJaarcyclus.
	 * @param mixed $rows What the store answers, '*throw*' or '*nocontainer*'.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(array $cyclus, mixed $rows): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($rows) {

			public array $calls = [];

			public function __construct(private mixed $rows) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): mixed {
				$this->calls[] = ['findAll' => $args];
				if ($this->rows === '*throw*') {
					throw new \RuntimeException('store down');
				}

				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($rows === '*nocontainer*') {
			$container->method('get')->willThrowException(new \RuntimeException('no OR'));
		} else {
			$container->method('get')->willReturn($store);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$result = (new ENSIAValidationGuard($container, $appConfig, $logger))->collegeAkkoordAllowed($cyclus);

		return ['result' => $result, 'logs' => $logger->records, 'calls' => $store->calls];
	}
}
