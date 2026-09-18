<?php

/**
 * Unit tests for PaymentRequestActionController.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\PaymentRequestActionController;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\PaymentSettlementService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the two panel actions and, above all, who is refused (REQ-SOPR-004).
 */
final class PaymentRequestActionControllerTest extends TestCase {
	/**
	 * Requests written by the object-service double.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Messages handed to the mailer.
	 *
	 * @var array<int, IMessage>
	 */
	private array $sent = [];

	/**
	 * One stored pending request on a case.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function storedRequest(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pr-1',
				'subjectKind' => 'object',
				'subject' => ['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-7'],
				'requestType' => 'leges',
				'amount' => 45.0,
				'currency' => 'EUR',
				'state' => 'pending',
				'description' => 'Leges omgevingsvergunning',
				'paymentLink' => 'https://pay.example/pr-1',
				'debtor' => ['name' => 'J. de Vries', 'email' => 'j.devries@example.nl'],
			],
			$overrides
		);
	}//end storedRequest()

	/**
	 * Build the controller under test.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $stored Rows per schema slug.
	 * @param bool $mayAdminister Whether the caller carries payment.administer.
	 * @param bool $mayRequest Whether the caller carries payment.request.
	 *
	 * @return PaymentRequestActionController The controller.
	 */
	private function makeController(
		array $stored,
		bool $mayAdminister,
		bool $mayRequest = true,
	): PaymentRequestActionController {
		$saved = &$this->saved;
		$double = new class($stored, $saved) {
			/**
			 * The schema the fluent chain last selected.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $stored Rows per schema slug.
			 * @param array<int, array<string, mixed>> $saved Sink.
			 */
			public function __construct(
				private array $stored,
				private array &$saved,
			) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->stored[$this->schema] ?? []);
			}

			/**
			 * @param array<string, mixed> $object Object to persist.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved[] = $object;
				return $object;
			}
		};

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($mayAdminister, $mayRequest): string {
				if ($key === PaymentActionAuthorizer::CONFIG_ACTION_GROUPS) {
					return json_encode(
						[
							PaymentActionAuthorizer::ACTION_ADMINISTER => ($mayAdminister === true ? ['finance'] : ['treasury']),
							PaymentActionAuthorizer::ACTION_REQUEST => ($mayRequest === true ? ['finance'] : ['treasury']),
						],
						JSON_THROW_ON_ERROR
					);
				}

				return ($key === 'register' ? 'shillinq' : $default);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($group === 'finance')
		);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(fn (): IMessage => $this->createMock(IMessage::class));
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message): array {
				$this->sent[] = $message;
				return [];
			}
		);

		$objectService = new DuckObjectServiceAdapter(inner: $double);
		$authorizer = new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groupManager);
		$logger = $this->createMock(LoggerInterface::class);
		$feeSchedules = new FeeScheduleService(objectService: $objectService, appConfig: $appConfig, logger: $logger);

		return new PaymentRequestActionController(
			'shillinq',
			$this->createMock(IRequest::class),
			$objectService,
			$authorizer,
			$mailer,
			$appConfig,
			$logger,
			$feeSchedules,
			new PaymentRequestLeafProvider(
				objectService: $objectService,
				validator: new ObjectPaymentRequestValidator(),
				appConfig: $appConfig,
				authorizer: $authorizer,
				feeSchedules: $feeSchedules,
				settlements: new PaymentSettlementService(),
			),
			new PaymentSettlementService(),
		);
	}//end makeController()

	/**
	 * A signed-in handler who does NOT carry payment.administer is refused, and
	 * nothing is mailed or written. This is the least privileged principal that
	 * should be refused: a real user with a real session, not an anonymous one
	 * (REQ-SOPR-004).
	 *
	 * @return void
	 */
	public function testHandlerWithoutTheActionCannotSendTheLink(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest()]], mayAdminister: false);

		$response = $controller->send('pr-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([], $this->sent);
		self::assertSame([], $this->saved);
	}//end testHandlerWithoutTheActionCannotSendTheLink()

	/**
	 * The same refusal on the settlement, which is the one that moves money.
	 *
	 * @return void
	 */
	public function testHandlerWithoutTheActionCannotSettle(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest()]], mayAdminister: false);

		$response = $controller->settle('pr-1', 'PIN-2026-0001', 'pin');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testHandlerWithoutTheActionCannotSettle()

	/**
	 * A permitted handler mails the link and the request records when it went
	 * out, so a second click is a deliberate resend and not a guess.
	 *
	 * @return void
	 */
	public function testPermittedHandlerSendsTheLinkAndRecordsIt(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest()]], mayAdminister: true);

		$response = $controller->send('pr-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $this->sent);
		self::assertCount(1, $this->saved);
		self::assertArrayHasKey('linkSentAt', $this->saved[0]);
	}//end testPermittedHandlerSendsTheLinkAndRecordsIt()

	/**
	 * A request with no debtor email is refused rather than mailed nowhere.
	 *
	 * @return void
	 */
	public function testARequestWithoutADebtorEmailIsNotSent(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest(['debtor' => ['name' => 'J. de Vries']])]], mayAdminister: true);

		$response = $controller->send('pr-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $this->sent);
	}//end testARequestWithoutADebtorEmailIsNotSent()

	/**
	 * A settlement by other means needs the reference a later bank match will
	 * look for; without one, nothing is written.
	 *
	 * @return void
	 */
	public function testSettlementNeedsAReference(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest()]], mayAdminister: true);

		$response = $controller->settle('pr-1', '   ', 'pin');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testSettlementNeedsAReference()

	/**
	 * A permitted handler settles a pending request, and the record says who did
	 * it and on what evidence.
	 *
	 * @return void
	 */
	public function testPermittedHandlerSettlesByOtherMeans(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest()]], mayAdminister: true);

		$response = $controller->settle('pr-1', 'PIN-2026-0001', 'pin');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $this->saved);

		// The provider's own state is UNTOUCHED. Overwriting it is how a later
		// capture becomes invisible and the refund never happens (REQ-FPCR-003).
		self::assertSame('pending', $this->saved[0]['state']);

		$settlement = $this->saved[0]['settlements'][0];
		self::assertSame('PIN-2026-0001', $settlement['reference']);
		self::assertSame('pin', $settlement['method']);
		self::assertSame('handler', $settlement['actor']);
		self::assertSame(45.0, $settlement['amount']);
		self::assertSame('paid', $response->getData()['report']['state']);
	}//end testPermittedHandlerSettlesByOtherMeans()

	/**
	 * A provider capture landing AFTER a counter payment is not lost: both are
	 * readable and the request reports the overpayment (REQ-FPCR-003).
	 *
	 * @return void
	 */
	public function testACaptureAfterAManualSettlementReportsAnOverpayment(): void {
		$alreadySettled = $this->storedRequest(
			[
				'state' => 'captured',
				'settlements' => [
					[
						'method' => 'pin',
						'amount' => 45.0,
						'reference' => 'PIN-2026-0001',
						'actor' => 'handler',
						'settledAt' => '2026-09-18T10:00:00Z',
						'reason' => '',
					],
				],
			]
		);
		$controller = $this->makeController(['PaymentRequest' => [$alreadySettled]], mayAdminister: true);

		$response = $controller->settle('pr-1', 'PIN-2026-0002', 'pin', 0.0, '');

		$report = $response->getData()['report'];
		self::assertSame('overpaid', $report['state']);
		self::assertSame(45.0 + 45.0 + 45.0 - 45.0, $report['over']);
		self::assertCount(2, $this->saved[0]['settlements']);
	}//end testACaptureAfterAManualSettlementReportsAnOverpayment()

	/**
	 * A waiver carries no bank evidence, so it needs the reason it was granted
	 * instead of a reference.
	 *
	 * @return void
	 */
	public function testAWaivedFeeNeedsItsReason(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest()]], mayAdminister: true);

		$response = $controller->settle('pr-1', '', 'waived', 0.0, '');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testAWaivedFeeNeedsItsReason()

	/**
	 * A request that is already captured cannot be settled a second time: that
	 * is how the same money gets booked twice.
	 *
	 * @return void
	 */
	public function testACapturedRequestSettledAgainReportsTheOverpayment(): void {
		$controller = $this->makeController(['PaymentRequest' => [$this->storedRequest(['state' => 'captured'])]], mayAdminister: true);

		$response = $controller->settle('pr-1', 'PIN-2026-0002', 'pin');

		// Not a conflict. Refusing here would leave a counter payment nobody
		// recorded; recording it is what makes the refund findable.
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('overpaid', $response->getData()['report']['state']);
	}//end testACapturedRequestSettledAgainReportsTheOverpayment()

	/**
	 * An unknown id is a 404, not a silent success.
	 *
	 * @return void
	 */
	public function testAnUnknownRequestIsNotFound(): void {
		$controller = $this->makeController([], mayAdminister: true);

		$response = $controller->settle('pr-nope', 'PIN-2026-0003', 'pin');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnknownRequestIsNotFound()
	/**
	 * A published fee for the case's type.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The schedule row.
	 */
	private function feeSchedule(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'fs-1',
				'targetApp' => 'dossiq',
				'register' => 'dossiq',
				'schema' => 'Zaak',
				'typeProperty' => 'caseType',
				'typeValue' => 'bouwvergunning',
				'amounts' => [['intakeChannel' => '', 'amount' => 245.0, 'currency' => 'EUR']],
				'currency' => 'EUR',
				'payAtIntake' => 'required',
				'legalBasis' => [
					'regulation' => 'Legesverordening 2026',
					'article' => '2.3.1',
					'effectiveDate' => '2026-01-01',
				],
				'validFrom' => '2020-01-01',
				'validTo' => '',
				'intakeChannel' => '',
			],
			$overrides
		);
	}//end feeSchedule()

	/**
	 * A clerk raises the published leges in one action, and the amount comes from
	 * the schedule rather than from the request body (REQ-SOPR-008).
	 *
	 * @return void
	 */
	public function testAClerkRaisesThePublishedLeges(): void {
		$controller = $this->makeController(
			[
				'Zaak' => [['id' => 'zaak-7', 'caseType' => 'bouwvergunning']],
				'FeeSchedule' => [$this->feeSchedule()],
			],
			mayAdminister: true,
		);

		$response = $controller->raiseLeges('dossiq', 'Zaak', 'zaak-7');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		self::assertSame(245.0, $data['request']['amount']);
		self::assertSame('leges', $data['request']['requestType']);
		self::assertStringContainsString('Legesverordening 2026', (string)$data['request']['description']);
	}//end testAClerkRaisesThePublishedLeges()

	/**
	 * A type with no published fee answers 404 and raises nothing, rather than
	 * inventing a zero-amount request the citizen can never pay.
	 *
	 * @return void
	 */
	public function testATypeWithoutAPublishedFeeRaisesNothing(): void {
		$controller = $this->makeController(
			[
				'Zaak' => [['id' => 'zaak-8', 'caseType' => 'melding']],
				'FeeSchedule' => [$this->feeSchedule()],
			],
			mayAdminister: true,
		);

		$response = $controller->raiseLeges('dossiq', 'Zaak', 'zaak-8');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testATypeWithoutAPublishedFeeRaisesNothing()

	/**
	 * A clerk without the `payment.request` action is refused. This is the least
	 * privileged principal that should be refused here: someone who may see the
	 * case and may administer payments generally, but was not granted the right
	 * to raise a demand for money.
	 *
	 * @return void
	 */
	public function testAClerkWithoutTheRequestActionIsRefused(): void {
		$controller = $this->makeController(
			[
				'Zaak' => [['id' => 'zaak-7', 'caseType' => 'bouwvergunning']],
				'FeeSchedule' => [$this->feeSchedule()],
			],
			mayAdminister: true,
			mayRequest: false,
		);

		$response = $controller->raiseLeges('dossiq', 'Zaak', 'zaak-7');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testAClerkWithoutTheRequestActionIsRefused()

	/**
	 * A call that does not name its object is refused before anything is read.
	 *
	 * @return void
	 */
	public function testRaisingLegesNeedsAnObject(): void {
		$controller = $this->makeController([], mayAdminister: true);

		$response = $controller->raiseLeges('dossiq', 'Zaak', '');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRaisingLegesNeedsAnObject()

	/**
	 * Settling without naming an amount means "the whole of what this request
	 * asks for". When the request's own amount cannot be read there is no whole
	 * to settle, so the call is refused and says why. The old cast made it a
	 * counter payment of 0.00 with a named clerk on it, which is a record of
	 * money arriving for nothing (REQ-FPCR-003).
	 *
	 * @return void
	 */
	public function testSettlingAnUnreadableAmountIsRefusedRatherThanRecordedAsZero(): void {
		$unreadable = $this->storedRequest(['amount' => null]);
		$controller = $this->makeController(['PaymentRequest' => [$unreadable]], mayAdminister: true);

		$response = $controller->settle('pr-1', 'PIN-2026-0009', 'pin', 0.0, '');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertCount(0, $this->saved);

		// The sentence has to name the fallback, because that is what the clerk
		// has to do differently. "A settlement needs the amount that arrived",
		// which is what the builder says about the clerk's OWN input, sends
		// them looking at a field they left empty on purpose.
		self::assertStringContainsString('fall back', $response->getData()['error']);
	}//end testSettlingAnUnreadableAmountIsRefusedRatherThanRecordedAsZero()

	/**
	 * The refusal above is about the FALLBACK, not about settling at all. A
	 * clerk who names the amount that actually arrived may still record it
	 * against a request whose own amount is unreadable, and what the request
	 * then reports is that the sum cannot be done, never that it is settled.
	 *
	 * @return void
	 */
	public function testANamedAmountStillSettlesAgainstAnUnreadableRequest(): void {
		$unreadable = $this->storedRequest(['amount' => null]);
		$controller = $this->makeController(['PaymentRequest' => [$unreadable]], mayAdminister: true);

		$response = $controller->settle('pr-1', 'PIN-2026-0010', 'pin', 45.0, '');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(45.0, $this->saved[0]['settlements'][0]['amount']);

		$report = $response->getData()['report'];
		self::assertSame('indeterminate', $report['state']);
		self::assertNull($report['due']);
	}//end testANamedAmountStillSettlesAgainstAnUnreadableRequest()
}//end class
