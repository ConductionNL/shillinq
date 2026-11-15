<?php

/**
 * Golden test for IntercompanyTransactionGuard::canPost.
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

use OCA\Shillinq\Lifecycle\IntercompanyTransactionGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins result, every log record and every store call per transaction.
 *
 * Regenerate with INTERCOMPANY_POST_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class IntercompanyTransactionPostGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/intercompany-transaction-post.golden.json';

	public function testDecisionsMatchGolden(): void {
		$tx = ['postingDate' => '2026-03-01', 'fromAdministrationId' => 'adm-a', 'toAdministrationId' => 'adm-b'];
		$scenarios = [
			'bothOpen' => [$tx, ['adm-a' => [['state' => 'open']], 'adm-b' => [['state' => 'open']]]],
			'fromClosed' => [$tx, ['adm-a' => [['state' => 'closed']], 'adm-b' => [['state' => 'open']]]],
			'toClosed' => [$tx, ['adm-a' => [['state' => 'open']], 'adm-b' => [['state' => 'closed'], ['state' => 'open']]]],
			'noYears' => [$tx, []],
			'nonArrayYear' => [$tx, ['adm-a' => ['junk']]],
			'missingDate' => [['postingDate' => ''] + $tx, []],
			'yearsDown' => [$tx, '*throw*'],
		];

		$actual = [];
		foreach ($scenarios as $name => [$object, $years]) {
			$actual[$name] = $this->scenario($object, $years);
		}

		if (getenv('INTERCOMPANY_POST_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one transaction and capture result, logs and store calls.
	 *
	 * @param array<string,mixed> $object The IntercompanyTransaction.
	 * @param mixed $years FiscalYear rows per administration, or '*throw*'.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array $object, mixed $years): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($years) {

			public array $calls = [];

			public function __construct(private mixed $years) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if ($this->years === '*throw*') {
					throw new \RuntimeException('years down');
				}

				return ($this->years[$args['filters']['administrationId'] ?? ''] ?? []);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new IntercompanyTransactionGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->canPost('ict-1', $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
