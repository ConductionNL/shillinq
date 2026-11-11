<?php

/**
 * Golden test for the full Activity call sequence of ApprovalActivityEmitter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins every event setter call, its order, and the failure log per emitter.
 */
final class ApprovalActivityEmitterGoldenTest extends TestCase {

	/**
	 * Recorded calls of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Every scenario's recorded calls match the golden fixture.
	 *
	 * @return void
	 */
	public function testCallSequencesMatchGolden(): void {
		$result = [];
		foreach ($this->scenarios() as $name => [$sessionUid, $failPublish, $emit]) {
			$this->calls = [];
			$emitter = $this->emitter(sessionUid: $sessionUid, failPublish: $failPublish);
			$emit($emitter);
			$result[$name] = $this->calls;
		}

		$file = __DIR__.'/fixtures/approval-activity-emitter.golden.json';
		$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (getenv('APPROVAL_ACTIVITY_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(expected: file_get_contents($file), actual: $json."\n");

	}//end testCallSequencesMatchGolden()

	/**
	 * The scenarios: [session uid or null, publish throws, emit closure].
	 *
	 * @return array<string, array{0: ?string, 1: bool, 2: callable}>
	 */
	private function scenarios(): array {
		return [
			'requestedHint' => ['sess', false, fn ($e) => $e->emitApprovalRequested('ApprovalRequest', 'id-1', 'alice', 'Invoice #1')],
			'requestedSession' => ['sess', false, fn ($e) => $e->emitApprovalRequested('ApprovalRequest', 'id-2')],
			'requestedSystem' => [null, false, fn ($e) => $e->emitApprovalRequested('ApprovalRequest', 'id-3')],
			'approved' => ['sess', false, fn ($e) => $e->emitApprovalApproved('ApprovalTask', 'id-4', 'bob', 'PO 9', 'ok')],
			'approvedBare' => ['sess', false, fn ($e) => $e->emitApprovalApproved('ApprovalTask', 'id-5', 'bob')],
			'rejected' => ['sess', false, fn ($e) => $e->emitApprovalRejected('ApprovalTask', 'id-6', 'carol', 'X', 'too high')],
			'signed' => ['sess', false, fn ($e) => $e->emitDocumentSigned('Contract', 'id-7', 'dave', 'Lease')],
			'decision' => ['sess', false, fn ($e) => $e->emitDecisionMade('Decision', 'id-8', 'erin', 'approved')],
			'publishFails' => ['sess', true, fn ($e) => $e->emitDocumentSigned('Contract', 'id-9', '', 'Doc')],
		];

	}//end scenarios()

	/**
	 * Build an emitter whose collaborators record into $this->calls.
	 *
	 * @param string|null $sessionUid  The session user, or null for anonymous.
	 * @param bool        $failPublish Whether publish() throws.
	 *
	 * @return ApprovalActivityEmitter
	 */
	private function emitter(?string $sessionUid, bool $failPublish): ApprovalActivityEmitter {
		$event = $this->createMock(originalClassName: IEvent::class);
		$setters = ['setApp', 'setType', 'setAuthor', 'setAffectedUser', 'setTimestamp', 'setSubject', 'setObject', 'setMessage'];
		foreach ($setters as $setter) {
			$event->method($setter)->willReturnCallback(
				function (...$args) use ($setter, $event) {
					if ($setter === 'setTimestamp') {
						$args = ['<time>'];
					}

					$this->calls[] = array_merge([$setter], $args);
					return $event;
				}
			);
		}

		$manager = $this->createMock(originalClassName: IActivityManager::class);
		$manager->method('generateEvent')->willReturnCallback(
			function () use ($event) {
				$this->calls[] = ['generateEvent'];
				return $event;
			}
		);
		$manager->method('publish')->willReturnCallback(
			function () use ($failPublish) {
				$this->calls[] = ['publish'];
				if ($failPublish === true) {
					throw new \RuntimeException('offline');
				}
			}
		);

		return new ApprovalActivityEmitter(
			activityManager: $manager,
			userSession: $this->session(uid: $sessionUid),
			logger: $this->logger()
		);

	}//end emitter()

	/**
	 * A session whose user has the given uid, or no user.
	 *
	 * @param string|null $uid The uid, or null.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturnCallback(
			function () use ($user) {
				$this->calls[] = ['getUser'];
				return $user;
			}
		);
		return $session;

	}//end session()

	/**
	 * A logger recording into $this->calls.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$calls = &$this->calls;
		return new class($calls) extends AbstractLogger {

			/**
			 * Construct.
			 *
			 * @param array $calls Recording sink.
			 */
			public function __construct(private array &$calls) {
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
				$this->calls[] = ['log', $level, (string) $message, $context];
			}//end log()
		};

	}//end logger()
}//end class
