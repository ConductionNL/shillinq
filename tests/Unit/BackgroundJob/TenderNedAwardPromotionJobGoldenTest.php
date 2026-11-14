<?php

/**
 * Golden test for TenderNedAwardPromotionJob's deferred run.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
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

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Shillinq\BackgroundJob\TenderNedAwardPromotionJob;
use OCA\Shillinq\Listener\TenderNedAwardDetectedListener;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins which entries are promoted, skipped or fail soft, and every warning in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class TenderNedAwardPromotionJobGoldenTest extends TestCase {

	/**
	 * Recorded calls and log lines in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Run the deferred batch.
	 *
	 * @param array<int,array<string,mixed>> $entries The buffered entries.
	 *
	 * @return void
	 */
	private function runBatch(array $entries): void {
		$listener = $this->createMock(TenderNedAwardDetectedListener::class);
		$listener->method('runDeferredPromotion')->willReturnCallback(
			function (array $entry): void {
				$this->calls[] = ['promote', $entry['uuid']];
				if (($entry['fail'] ?? false) === true) {
					throw new RuntimeException('dossier gone');
				}
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($listener);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['warning', $message, $context];
			}
		);
		$job     = new TenderNedAwardPromotionJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$logger,
			$container
		);
		$context = new DeferredListenerContext(userId: 'admin', orgUuid: 'org-1', entries: $entries);
		(new ReflectionMethod($job, 'runDeferred'))->invoke($job, $context);
	}//end runBatch()

	/**
	 * An entry without a uuid is skipped with a warning naming its keys; a failed promotion warns and the batch goes on.
	 *
	 * @return void
	 */
	public function testBatch(): void {
		$this->runBatch(
			entries: [
				['uuid' => 'u1', 'payload' => []],
				['schema' => 'S', 'uuid' => ''],
				['uuid' => 7, 'fail' => true],
				['payload' => ['tenderId' => 't']],
				['uuid' => 'u3'],
			]
		);
		$skip     = 'TenderNedAwardPromotionJob: buffered entry carries no uuid — skipped';
		$expected = [
			['promote', 'u1'],
			['warning', $skip, ['keys' => ['schema', 'uuid']]],
			['promote', 7],
			['warning', 'TenderNedAwardPromotionJob: promotion failed — fail-soft', ['uuid' => '7', 'exception' => 'dossier gone']],
			['warning', $skip, ['keys' => ['payload']]],
			['promote', 'u3'],
		];
		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testBatch()
}//end class
