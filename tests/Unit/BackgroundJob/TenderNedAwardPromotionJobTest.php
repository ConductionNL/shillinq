<?php

/**
 * Tests for TenderNedAwardPromotionJob.
 *
 * The job is the far side of the #1198 deferral: the listener buffers an
 * eligible award and this job performs the promotion later, as the restored
 * actor. Its failure modes are all silent by construction — an unresolvable
 * listener, an entry with no payload, or a throwing promotion all end in a log
 * line and a dropped Commitment — so each one is pinned here rather than left
 * to be discovered as "the award never got promoted".
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
 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-5
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
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for the deferred award-promotion job.
 */
class TenderNedAwardPromotionJobTest extends TestCase {

	/**
	 * Build the job with a container that resolves the given listener.
	 *
	 * @param object|null $listener Listener to resolve, or null to leave it unbound.
	 *
	 * @return TenderNedAwardPromotionJob
	 */
	private function job(?object $listener): TenderNedAwardPromotionJob {
		$container = new class($listener) implements ContainerInterface {

			/**
			 * @param object|null $listener Bound listener.
			 */
			public function __construct(
				private readonly ?object $listener,
			) {
			}

			/**
			 * @param string $id Service id.
			 *
			 * @return mixed
			 */
			public function get(string $id): mixed {
				if ($id === TenderNedAwardDetectedListener::class && $this->listener !== null) {
					return $this->listener;
				}

				throw new class('not bound') extends \Exception implements \Psr\Container\NotFoundExceptionInterface {
				};
			}

			/**
			 * @param string $id Service id.
			 *
			 * @return bool
			 */
			public function has(string $id): bool {
				return ($id === TenderNedAwardDetectedListener::class && $this->listener !== null);
			}
		};

		return new TenderNedAwardPromotionJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			new OrganisationService(),
			new NullLogger(),
			$container
		);

	}//end job()

	/**
	 * Wrap entries in a context.
	 *
	 * @param array<int, array<string, mixed>> $entries Buffered entries.
	 *
	 * @return DeferredListenerContext
	 */
	private function context(array $entries): DeferredListenerContext {
		return new DeferredListenerContext(
			userId: 'admin',
			orgUuid: 'org-1',
			entries: $entries
		);

	}//end context()

	/**
	 * Every buffered entry is promoted, in order.
	 *
	 * @return void
	 */
	public function testEachBufferedEntryIsPromoted(): void {
		$listener = $this->createMock(TenderNedAwardDetectedListener::class);

		$seen = [];
		$listener->method('runDeferredPromotion')
			->willReturnCallback(
				function (array $payload) use (&$seen): void {
					$seen[] = $payload['tenderId'];
				}
			);

		$this->job($listener)->runForTest(
			$this->context(
				[
					['payload' => ['tenderId' => 'TN-1']],
					['payload' => ['tenderId' => 'TN-2']],
				]
			)
		);

		$this->assertSame(['TN-1', 'TN-2'], $seen);

	}//end testEachBufferedEntryIsPromoted()

	/**
	 * An entry with no payload is skipped, and does NOT stop the batch.
	 *
	 * A malformed entry aborting the loop would silently drop every award
	 * queued behind it in the same flush.
	 *
	 * @return void
	 */
	public function testEntryWithoutPayloadIsSkippedWithoutStoppingTheBatch(): void {
		$listener = $this->createMock(TenderNedAwardDetectedListener::class);

		$seen = [];
		$listener->method('runDeferredPromotion')
			->willReturnCallback(
				function (array $payload) use (&$seen): void {
					$seen[] = $payload['tenderId'];
				}
			);

		$this->job($listener)->runForTest(
			$this->context(
				[
					['payload' => 'not-an-array'],
					[],
					['payload' => ['tenderId' => 'TN-3']],
				]
			)
		);

		$this->assertSame(['TN-3'], $seen);

	}//end testEntryWithoutPayloadIsSkippedWithoutStoppingTheBatch()

	/**
	 * A throwing promotion is fail-soft and the batch continues.
	 *
	 * The inline handler swallowed its own exceptions so a failed promotion
	 * never broke the OpenRegister write. Moving the work to cron must not
	 * turn that into an unhandled job failure.
	 *
	 * @return void
	 */
	public function testThrowingPromotionIsFailSoftAndBatchContinues(): void {
		$listener = $this->createMock(TenderNedAwardDetectedListener::class);

		$seen = [];
		$listener->method('runDeferredPromotion')
			->willReturnCallback(
				function (array $payload) use (&$seen): void {
					if ($payload['tenderId'] === 'TN-BOOM') {
						throw new RuntimeException('promotion exploded');
					}

					$seen[] = $payload['tenderId'];
				}
			);

		$this->job($listener)->runForTest(
			$this->context(
				[
					['payload' => ['tenderId' => 'TN-BOOM']],
					['payload' => ['tenderId' => 'TN-4']],
				]
			)
		);

		$this->assertSame(['TN-4'], $seen);

	}//end testThrowingPromotionIsFailSoftAndBatchContinues()

	/**
	 * An unresolvable listener drops the batch without throwing.
	 *
	 * @return void
	 */
	public function testUnresolvableListenerDropsTheBatchQuietly(): void {
		$this->job(null)->runForTest(
			$this->context([['payload' => ['tenderId' => 'TN-5']]])
		);

		$this->assertTrue(true, 'runDeferred() returned without throwing');

	}//end testUnresolvableListenerDropsTheBatchQuietly()

	/**
	 * An empty buffer is a no-op.
	 *
	 * @return void
	 */
	public function testEmptyBufferIsANoOp(): void {
		$listener = $this->createMock(TenderNedAwardDetectedListener::class);
		$listener->expects($this->never())->method('runDeferredPromotion');

		$this->job($listener)->runForTest($this->context([]));

	}//end testEmptyBufferIsANoOp()
}//end class
