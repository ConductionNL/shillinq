<?php

/**
 * ReconciliationMatchToReportListener when OpenRegister fails underneath it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Listener\ReconciliationMatchToReportListener;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * A failing session lookup still stamps the match; a failing stamp is logged, never thrown.
 */
final class ReconciliationMatchToReportListenerTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * Log records: [level, message].
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $records = [];

	/**
	 * The listener over a scoped object service whose reads throw.
	 *
	 * @return ReconciliationMatchToReportListener
	 */
	private function listener(): ReconciliationMatchToReportListener {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('find')->willThrowException(new \RuntimeException('database gone'));

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$records = &$this->records;
		$logger = new class($records) extends AbstractLogger {
			/**
			 * @param array<int,array{0:string,1:string}> $records The shared records.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * Record a log call.
			 *
			 * @param mixed $level Level.
			 * @param \Stringable|string $message Message.
			 * @param array<string,mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message];
			}
		};

		return new ReconciliationMatchToReportListener($appConfig, $logger, $this->objects);
	}//end listener()

	/**
	 * A confirmed match event, as OpenRegister dispatches it.
	 *
	 * @return Event
	 */
	private static function confirmedMatch(): Event {
		return new class extends Event {
			/**
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['id' => 'm1', 'status' => 'confirmed', 'matchType' => 'ar-invoice', 'matchedObjectId' => 'obj-9', 'bankStatementLineId' => 'bsl-1'];
			}

			/**
			 * @return string
			 */
			public function getSchema(): string {
				return 'ReconciliationMatch';
			}
		};
	}//end confirmedMatch()

	/**
	 * When the bank line cannot be read the match is still stamped, without a reconId.
	 *
	 * @return void
	 */
	public function testAFailedSessionLookupStillStampsTheMatch(): void {
		$listener = $this->listener();
		$this->objects->expects($this->once())->method('updateObject')
			->with('m1', $this->callback(static fn (array $updates): bool => ($updates['reconId'] ?? null) === null || ($updates['reconId'] ?? '') === ''))
			->willReturn($this->createStub(\OCA\OpenRegister\Contract\ObjectEntityInterface::class));

		$listener->handle(self::confirmedMatch());

		self::assertContains(['info', 'ReconciliationMatchToReportListener: reconId lookup failed (continuing without)'], $this->records);
		self::assertContains(['info', 'ReconciliationMatchToReportListener: T4 fields stamped'], $this->records);
	}//end testAFailedSessionLookupStillStampsTheMatch()

	/**
	 * A failing stamp is logged as a fail-soft warning and not thrown.
	 *
	 * @return void
	 */
	public function testAFailedStampIsLoggedNotThrown(): void {
		$listener = $this->listener();
		$this->objects->method('updateObject')->willThrowException(new \RuntimeException('locked'));

		$listener->handle(self::confirmedMatch());

		self::assertContains(['warning', 'ReconciliationMatchToReportListener: stamping failed (fail-soft)'], $this->records);
		self::assertNotContains(['info', 'ReconciliationMatchToReportListener: T4 fields stamped'], $this->records);
	}//end testAFailedStampIsLoggedNotThrown()
}//end class
