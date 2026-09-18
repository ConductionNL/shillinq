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
use OCA\Shillinq\Service\PaymentActionAuthorizer;
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
	 * @param array<int, array<string, mixed>> $stored Stored rows.
	 * @param bool $mayAdminister Whether the caller carries payment.administer.
	 *
	 * @return PaymentRequestActionController The controller.
	 */
	private function makeController(array $stored, bool $mayAdminister): PaymentRequestActionController {
		$saved = &$this->saved;
		$double = new class($stored, $saved) {
			/**
			 * @param array<int, array<string, mixed>> $stored Stored rows.
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
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return $this->stored;
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
			static function (string $app, string $key, string $default = '') use ($mayAdminister): string {
				if ($key === PaymentActionAuthorizer::CONFIG_ACTION_GROUPS) {
					return json_encode(
						[PaymentActionAuthorizer::ACTION_ADMINISTER => ($mayAdminister === true ? ['finance'] : ['treasury'])],
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

		return new PaymentRequestActionController(
			'shillinq',
			$this->createMock(IRequest::class),
			new DuckObjectServiceAdapter(inner: $double),
			new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groupManager),
			$mailer,
			$appConfig,
			$this->createMock(LoggerInterface::class),
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
		$controller = $this->makeController([$this->storedRequest()], mayAdminister: false);

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
		$controller = $this->makeController([$this->storedRequest()], mayAdminister: false);

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
		$controller = $this->makeController([$this->storedRequest()], mayAdminister: true);

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
		$controller = $this->makeController([$this->storedRequest(['debtor' => ['name' => 'J. de Vries']])], mayAdminister: true);

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
		$controller = $this->makeController([$this->storedRequest()], mayAdminister: true);

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
		$controller = $this->makeController([$this->storedRequest()], mayAdminister: true);

		$response = $controller->settle('pr-1', 'PIN-2026-0001', 'pin');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $this->saved);
		self::assertSame('captured', $this->saved[0]['state']);
		self::assertSame('PIN-2026-0001', $this->saved[0]['settlementReference']);
		self::assertSame('pin', $this->saved[0]['settlementMethod']);
		self::assertSame('handler', $this->saved[0]['settledBy']);
	}//end testPermittedHandlerSettlesByOtherMeans()

	/**
	 * A request that is already captured cannot be settled a second time: that
	 * is how the same money gets booked twice.
	 *
	 * @return void
	 */
	public function testAnAlreadyCapturedRequestIsNotSettledAgain(): void {
		$controller = $this->makeController([$this->storedRequest(['state' => 'captured'])], mayAdminister: true);

		$response = $controller->settle('pr-1', 'PIN-2026-0002', 'pin');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame([], $this->saved);
	}//end testAnAlreadyCapturedRequestIsNotSettledAgain()

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
}//end class
