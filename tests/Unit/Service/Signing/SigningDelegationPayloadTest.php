<?php

/**
 * Characterisation tests for SigningDelegationService.
 *
 * Pins the signing-request event payload (provenance, label and document
 * fallbacks), the refusal when docudesk answers without a request id, the log
 * lines, and the callback's optional fields and Activity emission.
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

use OCA\Shillinq\Service\ApprovalActivityEmitter;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\Signing\SigningDelegationService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Characterisation tests for SigningDelegationService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SigningDelegationPayloadTest extends TestCase {

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
	 * Activity emitter mock.
	 *
	 * @var ApprovalActivityEmitter&MockObject
	 */
	private ApprovalActivityEmitter&MockObject $emitter;

	/**
	 * Service under test.
	 */
	private SigningDelegationService $svc;

	/**
	 * The event the dispatcher received.
	 */
	private ?object $dispatched = null;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq-register');
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->emitter    = $this->createMock(ApprovalActivityEmitter::class);
		$this->svc        = new SigningDelegationService(
			registerLocation: $settings,
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
			activityEmitter: $this->emitter,
		);
	}

	/**
	 * Make the dispatcher record the event and answer with the given id.
	 *
	 * @param string|null $requestId Id to mark the event handled with; null leaves it unhandled.
	 */
	private function answerWith(?string $requestId): void {
		$this->dispatcher->expects(self::once())->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($requestId): void {
				$this->dispatched = $event;
				if ($requestId !== null) {
					$event->markHandled($requestId);
				}
			}
		);
	}

	/**
	 * The event carries the provenance, label, stored document reference and hints.
	 */
	public function testEventCarriesProvenanceAndFallsBackToStoredDocumentReference(): void {
		$this->answerWith('req-1');
		$this->logger->expects(self::once())->method('info')->with(
			'SigningDelegationService: signingRequest raised via docudesk event',
			['signingRequestRef' => 'req-1', 'documentClass' => 'acm-report']
		);

		$result = $this->svc->requestSignature(
			['id' => 'acm-1', 'name' => 'ACM one', 'documentReference' => '/f/acm.pdf', 'pdfRef' => '/f/other.pdf'],
			'ACMReport',
			'acm-report',
			'',
			[['uid' => 'alice']],
			'qualified',
			'parallel'
		);

		self::assertSame(['id' => 'acm-1', 'name' => 'ACM one', 'documentReference' => '/f/acm.pdf', 'pdfRef' => '/f/other.pdf', 'signingRequestRef' => 'req-1', 'signingStatus' => 'requested'], $result);
		$event = $this->dispatched;
		self::assertSame('shillinq', $event->provenance->sourceApp);
		self::assertSame('shillinq-register', $event->provenance->subjectRegister);
		self::assertSame('ACMReport', $event->provenance->subjectSchema);
		self::assertSame('acm-1', $event->provenance->subjectId);
		self::assertSame('acm-1', $event->provenance->externalReference);
		self::assertSame('', $event->provenance->correlationId);
		self::assertSame('ACM one', $event->subjectLabel);
		self::assertSame('/f/acm.pdf', $event->documentReference);
		self::assertSame([['uid' => 'alice']], $event->signers);
		self::assertSame('qualified', $event->signatureLevel);
		self::assertSame('parallel', $event->signingMode);
	}

	/**
	 * An explicit document reference wins; id and label fall back to _id, title, pdfRef.
	 */
	public function testExplicitDocumentReferenceAndIdFallbacks(): void {
		$this->answerWith('req-2');
		$this->svc->requestSignature(['_id' => 'x-2', 'title' => 'Title two', 'pdfRef' => '/p.pdf'], 'Doc', 'doc', '/explicit.pdf');

		self::assertSame('x-2', $this->dispatched->provenance->subjectId);
		self::assertSame('Title two', $this->dispatched->subjectLabel);
		self::assertSame('/explicit.pdf', $this->dispatched->documentReference);
		self::assertSame('advanced', $this->dispatched->signatureLevel);
		self::assertSame('sequential', $this->dispatched->signingMode);
	}

	/**
	 * With no name or title the label is the id; pdfRef is the last document fallback.
	 */
	public function testLabelFallsBackToIdAndDocumentToPdfRef(): void {
		$this->answerWith('req-3');
		$this->svc->requestSignature(['id' => 'z-3', 'pdfRef' => '/p3.pdf']);

		self::assertSame('z-3', $this->dispatched->subjectLabel);
		self::assertSame('/p3.pdf', $this->dispatched->documentReference);
		self::assertSame('document', $this->dispatched->provenance->subjectSchema);
	}

	/**
	 * Handled with an empty id is refused, with the warning context.
	 */
	public function testHandledWithEmptyIdIsRefused(): void {
		$this->answerWith('');
		$this->logger->expects(self::once())->method('warning')->with(
			'SigningDelegationService: docudesk did not handle the signing request (fail closed)',
			['subjectSchema' => 'ACMReport', 'subjectId' => 'acm-9', 'documentClass' => 'acm-report']
		);
		$this->logger->expects(self::never())->method('info');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('docudesk did not handle the document signing request.');
		$this->svc->requestSignature(['id' => 'acm-9'], 'ACMReport', 'acm-report');
	}

	/**
	 * An unhandled event is refused as well.
	 */
	public function testUnhandledEventIsRefused(): void {
		$this->answerWith(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('docudesk did not handle the document signing request.');
		$this->svc->requestSignature(['id' => 'acm-8'], 'ACMReport', 'acm-report');
	}

	/**
	 * Null optional fields are not written; the callback log carries outcome and ref.
	 */
	public function testCallbackLeavesNullFieldsUnwrittenAndLogs(): void {
		$this->logger->expects(self::once())->method('info')->with(
			'SigningDelegationService: callback consumed',
			['outcome' => 'expired', 'signingRequestRef' => 'ref-1']
		);
		$this->emitter->expects(self::never())->method('emitDocumentSigned');

		$result = $this->svc->onSigningCallback(['id' => 'o-1', 'signingStatus' => 'requested'], 'expired', 'ref-1', subjectSchema: 'ACMReport');

		self::assertSame(['id' => 'o-1', 'signingStatus' => 'expired', 'signingRequestRef' => 'ref-1'], $result);
	}

	/**
	 * A signed callback with a schema emits document_signed after the consequence.
	 */
	public function testSignedCallbackEmitsDocumentSignedAfterConsequence(): void {
		$order = [];
		$this->emitter->expects(self::once())->method('emitDocumentSigned')
			->with('ACMReport', 'o-2', '', 'ACMReport o-2')
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'emit';
			});

		$this->svc->onSigningCallback(
			['id' => 'o-2'],
			'signed',
			'ref-2',
			null,
			'qualified',
			null,
			function (array $object) use (&$order): void {
				$order[] = 'consequence:' . $object['signingStatus'] . ':' . $object['signingLevel'];
			},
			'ACMReport'
		);

		self::assertSame(['consequence:signed:qualified', 'emit'], $order);
	}

	/**
	 * A signed callback without a schema does not emit.
	 */
	public function testSignedCallbackWithoutSchemaDoesNotEmit(): void {
		$this->emitter->expects(self::never())->method('emitDocumentSigned');

		$result = $this->svc->onSigningCallback(['id' => 'o-3'], 'signed', 'ref-3');

		self::assertSame('signed', $result['signingStatus']);
	}

	/**
	 * The idempotent no-op logs the already-terminal message and emits nothing.
	 */
	public function testIdempotentCallbackLogsAndEmitsNothing(): void {
		$this->logger->expects(self::once())->method('info')->with('SigningDelegationService: idempotent callback — already signed, no-op.');
		$this->emitter->expects(self::never())->method('emitDocumentSigned');

		$object = ['id' => 'o-4', 'signingStatus' => 'signed'];
		self::assertSame($object, $this->svc->onSigningCallback($object, 'signed', 'ref-4', subjectSchema: 'ACMReport'));
	}
}
