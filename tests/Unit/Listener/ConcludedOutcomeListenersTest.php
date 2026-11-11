<?php

/**
 * Characterisation tests for the two concluded-outcome listeners.
 *
 * Pins SigningConcludedListener and SignoffDecisionConcludedListener branch by
 * branch — event filter, source filter, status map, subject id preference,
 * schema search order, mirror and consequence persistence, the log lines and
 * the fail-soft paths — so the shared subject lookup cannot change an outcome.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Filinq\Event;

// In-test stub of the filinq SigningConcludedEvent contract.
if (class_exists(\OCA\Filinq\Event\SigningConcludedEvent::class, false) === false) {
	class SigningConcludedEvent extends \OCP\EventDispatcher\Event {
		public function __construct(
			private readonly array $data = [],
		) {
			parent::__construct();
		}//end __construct()

		public function getSourceApp(): string {
			return $this->data['sourceApp'] ?? 'shillinq';
		}//end getSourceApp()

		public function getStatus(): string {
			return $this->data['status'] ?? 'signed';
		}//end getStatus()

		public function getSigningRequestId(): string {
			return $this->data['signingRequestId'] ?? 'sr-1';
		}//end getSigningRequestId()

		public function getSignedDocumentRef(): string {
			return $this->data['signedDocumentRef'] ?? '';
		}//end getSignedDocumentRef()

		public function getExternalReference(): string {
			return $this->data['externalReference'] ?? '';
		}//end getExternalReference()

		public function getSubjectId(): ?string {
			return $this->data['subjectId'] ?? null;
		}//end getSubjectId()

		public function getSubjectSchema(): ?string {
			return $this->data['subjectSchema'] ?? null;
		}//end getSubjectSchema()
	}//end class
}//end if

namespace OCA\Decidesk\Event;

// In-test stub of the decidesk DecisionConcludedEvent contract.
if (class_exists(\OCA\Decidesk\Event\DecisionConcludedEvent::class, false) === false) {
	class DecisionConcludedEvent extends \OCP\EventDispatcher\Event {
		public function __construct(
			private readonly string $sourceApp = '',
			private readonly string $status = 'pending',
			private readonly string $decisionId = '',
			private readonly ?string $subjectSchema = null,
			private readonly ?string $subjectId = null,
			private readonly string $externalReference = '',
			private readonly string $correlationId = '',
		) {
			parent::__construct();
		}//end __construct()

		public function getSourceApp(): string {
			return $this->sourceApp;
		}//end getSourceApp()

		public function getStatus(): string {
			return $this->status;
		}//end getStatus()

		public function getDecisionId(): string {
			return $this->decisionId;
		}//end getDecisionId()

		public function getSubjectSchema(): ?string {
			return $this->subjectSchema;
		}//end getSubjectSchema()

		public function getSubjectId(): ?string {
			return $this->subjectId;
		}//end getSubjectId()

		public function getExternalReference(): string {
			return $this->externalReference;
		}//end getExternalReference()

		public function getCorrelationId(): string {
			return $this->correlationId;
		}//end getCorrelationId()
	}//end class
}//end if

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Decidesk\Event\DecisionConcludedEvent;
use OCA\Filinq\Event\SigningConcludedEvent;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Listener\SignoffDecisionConcludedListener;
use OCA\Shillinq\Listener\SigningConcludedListener;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Branch-by-branch characterisation of both concluded-outcome listeners.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ConcludedOutcomeListenersTest extends TestCase {

	/**
	 * Every object-service call, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every updateObject() write: id + data.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $writes = [];

	/**
	 * Every log line: level, message, context.
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * Arguments the outcome service received.
	 *
	 * @var list<mixed>
	 */
	private array $serviceArgs = [];

	/**
	 * Object service over a schema => id => object store; a `throw` entry
	 * makes that schema's lookup throw, `failWrite` makes the write throw.
	 *
	 * @param array<string,array<string,mixed>> $store     The stored objects.
	 * @param bool                              $failWrite Whether updateObject throws.
	 *
	 * @return ObjectServiceInterface The object service.
	 */
	private function objectService(array $store, bool $failWrite = false): ObjectServiceInterface {
		$schema = '';
		$service = $this->createMock(ObjectServiceInterface::class);
		$service->method('setRegister')->willReturnCallback(
			function (string $register) use ($service) {
				$this->calls[] = 'register ' . $register;
				return $service;
			}
		);
		$service->method('setSchema')->willReturnCallback(
			function (string $name) use (&$schema, $service) {
				$schema = $name;
				$this->calls[] = 'schema ' . $name;
				return $service;
			}
		);
		$service->method('find')->willReturnCallback(
			function (string $id) use (&$schema, $store) {
				$this->calls[] = 'find ' . $id;
				if (($store[$schema] ?? null) === 'throw') {
					throw new RuntimeException('lookup down');
				}

				if (isset($store[$schema][$id]) === false) {
					return null;
				}

				return (new ObjectEntity())->setObject($store[$schema][$id]);
			}
		);
		$service->method('updateObject')->willReturnCallback(
			function (string $id, array $data) use ($failWrite) {
				if ($failWrite === true) {
					throw new RuntimeException('write down');
				}

				$this->writes[] = ['id' => $id] + $data;
				return new ObjectEntity();
			}
		);

		return $service;

	}//end objectService()

	/**
	 * Logger recording every line.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return $logger;

	}//end logger()

	/**
	 * Settings answering the register slug.
	 *
	 * @return SettingsService The settings mock.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('books');

		return $settings;

	}//end settings()

	/**
	 * The signing listener over a store; the outcome service runs the
	 * consequence callback on `signed` and returns $returned.
	 *
	 * @param array<string,mixed>      $store     The stored objects.
	 * @param array<string,mixed>|null $returned  What onSigningCallback returns (null: object + mirror).
	 * @param bool                     $failWrite Whether updateObject throws.
	 *
	 * @return SigningConcludedListener The listener.
	 */
	private function signing(array $store, ?array $returned = null, bool $failWrite = false): SigningConcludedListener {
		$service = $this->createMock(SigningDelegationService::class);
		$service->method('onSigningCallback')->willReturnCallback(
			function (...$args) use ($returned) {
				$this->serviceArgs = $args;
				$object = $args[0];
				if ($args[1] === 'signed') {
					$object = ($args[6])($object);
				}

				return $returned ?? $object;
			}
		);

		return new SigningConcludedListener($this->settings(), $service, $this->logger(), $this->objectService($store, $failWrite));

	}//end signing()

	/**
	 * The sign-off listener over a store.
	 *
	 * @param array<string,mixed> $store     The stored objects.
	 * @param bool                $failWrite Whether updateObject throws.
	 *
	 * @return SignoffDecisionConcludedListener The listener.
	 */
	private function signoff(array $store, bool $failWrite = false): SignoffDecisionConcludedListener {
		$service = $this->createMock(SignoffDecisionService::class);
		$service->method('onDecisionCallback')->willReturnCallback(
			function (...$args) {
				$this->serviceArgs = $args;
				$object = $args[0];
				if ($args[1] === 'approved') {
					$object = ($args[3])($object);
				}

				return $object + ['decisionRef' => 'from-service'];
			}
		);

		return new SignoffDecisionConcludedListener($this->settings(), $service, $this->logger(), $this->objectService($store, $failWrite));

	}//end signoff()

	/**
	 * Messages logged, without context.
	 *
	 * @return list<string> The level-prefixed messages.
	 */
	private function messages(): array {
		return array_map(static fn (array $log): string => $log[0] . ' ' . $log[1], $this->logs);

	}//end messages()

	/**
	 * Events that are not ours, not from shillinq, or not terminal, do nothing.
	 *
	 * @return void
	 */
	public function testIgnoredSigningEvents(): void {
		$listener = $this->signing([]);
		$listener->handle(new Event());
		$listener->handle(new SigningConcludedEvent(['sourceApp' => 'pipelinq', 'externalReference' => 'x']));
		$listener->handle(new SigningConcludedEvent(['status' => 'pending', 'externalReference' => 'x']));

		self::assertSame([], $this->calls);
		self::assertSame([], $this->logs);

	}//end testIgnoredSigningEvents()

	/**
	 * No subject id is logged and skipped; no lookup happens.
	 *
	 * @return void
	 */
	public function testSigningWithoutSubjectId(): void {
		$this->signing([])->handle(new SigningConcludedEvent(['signingRequestId' => 'sr-9', 'subjectId' => '']));

		self::assertSame([], $this->calls);
		self::assertSame(
			[['info', 'SigningConcludedListener: no subject id on concluded signing request (skipping)', ['signingRequestRef' => 'sr-9']]],
			$this->logs
		);

	}//end testSigningWithoutSubjectId()

	/**
	 * The hinted schema is searched first, then the known ones, without
	 * repeats; a throwing lookup counts as not found.
	 *
	 * @return void
	 */
	public function testSigningSearchOrderAndMissingObject(): void {
		$this->signing(['AnnualReport' => 'throw'])->handle(
			new SigningConcludedEvent(['externalReference' => 'ext-1', 'subjectId' => 'sub-1', 'subjectSchema' => 'AnnualReport'])
		);

		self::assertSame(
			[
				'register books', 'schema AnnualReport', 'find ext-1',
				'register books', 'schema ACMReport', 'find ext-1',
				'register books', 'schema ManagementLetter', 'find ext-1',
			],
			$this->calls
		);
		self::assertSame(
			[['info', 'SigningConcludedListener: no matching finance object (skipping)', ['signingRequestRef' => 'sr-1', 'subjectId' => 'ext-1']]],
			$this->logs
		);

	}//end testSigningSearchOrderAndMissingObject()

	/**
	 * A signed outcome persists the mirror plus the gate, and logs.
	 *
	 * @return void
	 */
	public function testSignedPersistsMirrorAndGate(): void {
		$this->signing(['AnnualReport' => ['sub-1' => ['id' => 'sub-1', 'title' => 'AR']]])->handle(
			new SigningConcludedEvent(['subjectId' => 'sub-1', 'signedDocumentRef' => 'doc-1'])
		);

		self::assertSame(['id' => 'sub-1', 'title' => 'AR'], $this->serviceArgs[0]);
		self::assertSame(['signed', 'sr-1', null, null, 'doc-1'], array_slice($this->serviceArgs, 1, 5));
		self::assertSame('AnnualReport', $this->serviceArgs[7]);
		self::assertCount(1, $this->writes);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $this->writes[0]['signedAt']);
		$this->writes[0]['signedAt'] = 'at';
		self::assertSame(
			['id' => 'sub-1', 'signingRequestRef' => 'sr-1', 'signingStatus' => 'signed', 'signedAt' => 'at', 'submissionGateOpen' => true],
			$this->writes[0]
		);
		self::assertSame(
			[
				'info SigningConcludedListener: accounting consequence applied',
				'info SigningConcludedListener: outcome consumed',
			],
			$this->messages()
		);
		self::assertSame(['schema' => 'AnnualReport', 'outcome' => 'signed'], $this->logs[0][2]);
		self::assertSame(
			['schema' => 'AnnualReport', 'subjectId' => 'sub-1', 'outcome' => 'signed', 'signingRequestRef' => 'sr-1'],
			$this->logs[1][2]
		);

	}//end testSignedPersistsMirrorAndGate()

	/**
	 * The mirror comes from what the service returns; signedDocumentRef only
	 * when it carries one; the consequence only when it fired.
	 *
	 * @return void
	 */
	public function testSigningMirrorFromServiceResult(): void {
		$this->signing(
			['ACMReport' => ['sub-2' => ['id' => 'sub-2']]],
			['signingRequestRef' => 'sr-kept', 'signedDocumentRef' => 'doc-kept']
		)->handle(new SigningConcludedEvent(['status' => 'cancelled', 'subjectId' => 'sub-2']));

		self::assertSame(['sub-2' => null], [$this->serviceArgs[0]['id'] => $this->serviceArgs[5]]);
		self::assertSame('expired', $this->serviceArgs[1]);
		self::assertSame(
			[['id' => 'sub-2', 'signingRequestRef' => 'sr-kept', 'signingStatus' => 'expired', 'signedDocumentRef' => 'doc-kept']],
			$this->writes
		);

	}//end testSigningMirrorFromServiceResult()

	/**
	 * A failing write is logged as a fail-soft warning.
	 *
	 * @return void
	 */
	public function testSigningWriteFailureIsFailSoft(): void {
		$this->signing(['ACMReport' => ['sub-3' => ['id' => 'sub-3']]], null, true)->handle(
			new SigningConcludedEvent(['status' => 'declined', 'subjectId' => 'sub-3'])
		);

		self::assertSame(
			[['warning', 'SigningConcludedListener: projection failed (fail-soft)', ['exception' => 'write down']]],
			$this->logs
		);

	}//end testSigningWriteFailureIsFailSoft()

	/**
	 * Sign-off: externalReference wins, hinted schema first, mirror and gate
	 * persisted, consumed log.
	 *
	 * @return void
	 */
	public function testSignoffApprovedPath(): void {
		$this->signoff(['ActuarialValuation' => ['ext-5' => ['id' => 'ext-5']]])->handle(
			new DecisionConcludedEvent('shillinq', 'approved', 'dec-5', 'ActuarialValuation', 'sub-5', 'ext-5')
		);

		self::assertSame(
			['register books', 'schema ActuarialValuation', 'find ext-5', 'register books', 'schema ActuarialValuation'],
			$this->calls
		);
		self::assertSame(['dec-5', 'ActuarialValuation'], [$this->serviceArgs[2], $this->serviceArgs[4]]);
		$this->writes[0]['signoffApprovedAt'] = 'at';
		self::assertSame(
			[['id' => 'ext-5', 'decisionRef' => 'from-service', 'decisionOutcome' => 'approved', 'signoffApprovedAt' => 'at', 'signoffGateOpen' => true]],
			$this->writes
		);
		self::assertSame(
			[
				'info SignoffDecisionConcludedListener: accounting consequence applied',
				'info SignoffDecisionConcludedListener: outcome consumed',
			],
			$this->messages()
		);
		self::assertSame(
			['schema' => 'ActuarialValuation', 'subjectId' => 'ext-5', 'outcome' => 'approved', 'decisionRef' => 'dec-5'],
			$this->logs[1][2]
		);

	}//end testSignoffApprovedPath()

	/**
	 * Sign-off skip paths and the fail-soft write.
	 *
	 * @return void
	 */
	public function testSignoffSkipsAndFailSoft(): void {
		$this->signoff([])->handle(new DecisionConcludedEvent('shillinq', 'rejected', 'dec-6'));
		$this->signoff([])->handle(new DecisionConcludedEvent('shillinq', 'rejected', 'dec-7', null, 'sub-7'));
		$this->signoff(['AnnualReport' => ['sub-8' => ['id' => 'sub-8']]], true)->handle(
			new DecisionConcludedEvent('shillinq', 'rejected', 'dec-8', null, 'sub-8')
		);

		self::assertSame(
			[
				['info', 'SignoffDecisionConcludedListener: no subject id on concluded decision (skipping)', ['decisionRef' => 'dec-6']],
				['info', 'SignoffDecisionConcludedListener: no matching finance object (skipping)', ['decisionRef' => 'dec-7', 'subjectId' => 'sub-7']],
				['warning', 'SignoffDecisionConcludedListener: projection failed (fail-soft)', ['exception' => 'write down']],
			],
			$this->logs
		);
		self::assertSame(
			['schema ACMReport', 'schema ActuarialValuation', 'schema AnnualReport'],
			array_values(array_filter(array_slice($this->calls, 0, 9), static fn (string $c): bool => str_starts_with($c, 'schema')))
		);

	}//end testSignoffSkipsAndFailSoft()
}//end class
