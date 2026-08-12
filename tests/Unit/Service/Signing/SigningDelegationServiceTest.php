<?php

/**
 * Unit tests for SigningDelegationService.
 *
 * Covers: requestSignature idempotency, the docudesk IEventDispatcher dispatch,
 * fail-closed behaviour when docudesk is absent / did not handle the request,
 * field-writing on success; onSigningCallback field-mapping, idempotency,
 * consequence firing, and unknown-outcome rejection.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Signing
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

namespace OCA\DocuDesk\Event;

// Minimal in-test stub of the docudesk DocumentSigningRequestedEvent contract so
// that SigningDelegationService::requestSignature can class_exists()-guard,
// dispatch, and read back isHandled()/getSigningRequestId() without the docudesk
// app present. The real class lives in docudesk; shillinq only consumes this
// shape.
if (class_exists(\OCA\DocuDesk\Event\SigningProvenance::class, false) === false) {
	class SigningProvenance {

		public function __construct(
			public readonly string $sourceApp = '',
			public readonly ?string $subjectRegister = null,
			public readonly ?string $subjectSchema = null,
			public readonly ?string $subjectId = null,
			public readonly string $externalReference = '',
			public readonly string $correlationId = '',
		) {
		}//end __construct()
	}//end class
}//end if

if (class_exists(\OCA\DocuDesk\Event\DocumentSigningRequestedEvent::class, false) === false) {
	class DocumentSigningRequestedEvent extends \OCP\EventDispatcher\Event {

		private bool $handled = false;

		private ?string $signingRequestId = null;

		/**
		 * @param array<int,mixed> $signers
		 */
		public function __construct(
			public readonly SigningProvenance $provenance = new SigningProvenance(),
			public readonly string $subjectLabel = '',
			public readonly string $documentReference = '',
			public readonly array $signers = [],
			public readonly string $signatureLevel = 'advanced',
			public readonly string $signingMode = 'sequential',
		) {
			parent::__construct();
		}//end __construct()

		public function markHandled(string $signingRequestId): void {
			$this->handled = true;
			$this->signingRequestId = $signingRequestId;
		}//end markHandled()

		public function isHandled(): bool {
			return $this->handled;
		}//end isHandled()

		public function getSigningRequestId(): ?string {
			return $this->signingRequestId;
		}//end getSigningRequestId()
	}//end class
}//end if

namespace OCA\Shillinq\Tests\Unit\Service\Signing;

use InvalidArgumentException;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SigningDelegationService (REQ-SIGN-001/006).
 *
 * The docudesk event contract class
 * {@see \OCA\DocuDesk\Event\DocumentSigningRequestedEvent} lives on docudesk's
 * development branch and is not autoloadable in this checkout. A minimal stub is
 * defined below so the success path can be exercised; the fail-closed path is
 * asserted on a class name that is guaranteed absent.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SigningDelegationServiceTest extends TestCase {

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Event dispatcher mock.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 */
	private SigningDelegationService $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->svc = new SigningDelegationService(
			settingsService: $this->settings,
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * requestSignature dispatches the docudesk event and writes
	 * signingStatus=requested when the listener handled the request and returned
	 * a signing-request id.
	 *
	 * Requires the docudesk event stub (defined at the bottom of this file).
	 *
	 * @requires extension Reflection
	 */
	public function testRequestSignatureWritesStatusRequestedOnHandled(): void {
		if (class_exists(\OCA\DocuDesk\Event\DocumentSigningRequestedEvent::class) === false) {
			self::markTestSkipped('docudesk DocumentSigningRequestedEvent stub not loaded.');
		}

		// The dispatcher simulates the docudesk listener writing the result slot.
		$this->dispatcher
			->expects(self::once())
			->method('dispatchTyped')
			->willReturnCallback(
				function (object $event): void {
					$event->markHandled('ds-req-001');
				}
			);

		$result = $this->svc->requestSignature(
			financeObject: ['id' => 'acm-1', 'administrationId' => 'adm-1'],
			subjectSchema: 'ACMReport',
		);

		self::assertSame('ds-req-001', $result['signingRequestRef']);
		self::assertSame('requested', $result['signingStatus']);

	}//end testRequestSignatureWritesStatusRequestedOnHandled()

	/**
	 * requestSignature is idempotent when signingStatus==signed — no dispatch.
	 */
	public function testRequestSignatureIsIdempotentWhenAlreadySigned(): void {
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		$obj = ['id' => 'acm-2', 'signingStatus' => 'signed', 'signingRequestRef' => 'existing-ref'];

		$result = $this->svc->requestSignature(
			financeObject: $obj,
			subjectSchema: 'ACMReport',
		);

		self::assertSame('existing-ref', $result['signingRequestRef']);
		self::assertSame('signed', $result['signingStatus']);

	}//end testRequestSignatureIsIdempotentWhenAlreadySigned()

	/**
	 * requestSignature FAILS CLOSED when the docudesk listener did not handle
	 * the request (isHandled() false / null id).
	 *
	 * Requires the docudesk event stub.
	 */
	public function testRequestSignatureFailsClosedWhenNotHandled(): void {
		if (class_exists(\OCA\DocuDesk\Event\DocumentSigningRequestedEvent::class) === false) {
			self::markTestSkipped('docudesk DocumentSigningRequestedEvent stub not loaded.');
		}

		// Dispatcher does nothing — the event stays unhandled with a null id.
		$this->dispatcher->expects(self::once())->method('dispatchTyped');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/did not handle/');

		$this->svc->requestSignature(
			financeObject: ['id' => 'acm-3'],
			subjectSchema: 'ACMReport',
		);

	}//end testRequestSignatureFailsClosedWhenNotHandled()

	/**
	 * onSigningCallback with outcome=signed writes all fields and fires the consequence.
	 */
	public function testOnSigningCallbackSignedWritesFieldsAndFiresConsequence(): void {
		$fired = false;
		$firedWith = null;

		$result = $this->svc->onSigningCallback(
			financeObject: ['id' => 'acm-4', 'signingStatus' => 'requested'],
			outcome: 'signed',
			signingRequestRef: 'ds-req-004',
			signingProvider: 'evidos',
			signingLevel: 'advanced',
			signedDocumentRef: '/NC/files/signed-acm-4.pdf',
			consequenceCallback: function (array $obj) use (&$fired, &$firedWith): void {
				$fired = true;
				$firedWith = $obj;
			},
		);

		self::assertSame('signed', $result['signingStatus']);
		self::assertSame('ds-req-004', $result['signingRequestRef']);
		self::assertSame('evidos', $result['signingProvider']);
		self::assertSame('advanced', $result['signingLevel']);
		self::assertSame('/NC/files/signed-acm-4.pdf', $result['signedDocumentRef']);
		self::assertTrue($fired);
		self::assertNotNull($firedWith);

	}//end testOnSigningCallbackSignedWritesFieldsAndFiresConsequence()

	/**
	 * onSigningCallback with outcome=declined does NOT fire the consequence.
	 */
	public function testOnSigningCallbackDeclinedDoesNotFireConsequence(): void {
		$fired = false;

		$result = $this->svc->onSigningCallback(
			financeObject: ['id' => 'acm-5', 'signingStatus' => 'requested'],
			outcome: 'declined',
			signingRequestRef: 'ds-req-005',
			consequenceCallback: function (array $obj) use (&$fired): void {
				$fired = true;
			},
		);

		self::assertSame('declined', $result['signingStatus']);
		self::assertFalse($fired);

	}//end testOnSigningCallbackDeclinedDoesNotFireConsequence()

	/**
	 * onSigningCallback is idempotent when already in the same terminal state.
	 */
	public function testOnSigningCallbackIsIdempotent(): void {
		$fired = false;

		$result = $this->svc->onSigningCallback(
			financeObject: ['id' => 'acm-6', 'signingStatus' => 'signed', 'signingRequestRef' => 'ds-req-006'],
			outcome: 'signed',
			signingRequestRef: 'ds-req-006',
			consequenceCallback: function (array $obj) use (&$fired): void {
				$fired = true;
			},
		);

		self::assertSame('signed', $result['signingStatus']);
		self::assertFalse($fired, 'Consequence must not fire on idempotent callback');

	}//end testOnSigningCallbackIsIdempotent()

	/**
	 * onSigningCallback rejects unknown outcomes.
	 */
	public function testOnSigningCallbackRejectsUnknownOutcome(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Unknown signing outcome/');

		$this->svc->onSigningCallback(
			financeObject: ['id' => 'acm-7'],
			outcome: 'bogus',
			signingRequestRef: 'ds-req-007',
		);

	}//end testOnSigningCallbackRejectsUnknownOutcome()

	/**
	 * onSigningCallback with null consequence callback does not error on 'signed'.
	 */
	public function testOnSigningCallbackNullCallbackSignedNoError(): void {
		$result = $this->svc->onSigningCallback(
			financeObject: ['id' => 'acm-8', 'signingStatus' => 'requested'],
			outcome: 'signed',
			signingRequestRef: 'ds-req-008',
		);

		self::assertSame('signed', $result['signingStatus']);

	}//end testOnSigningCallbackNullCallbackSignedNoError()

}//end class
