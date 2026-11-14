<?php

/**
 * Unit tests for CommitmentYearEndController's carry-over, last-invoice and
 * failure branches.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use DomainException;
use OCA\Shillinq\Controller\CommitmentYearEndController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Commitment\CommitmentCarryOverService;
use OCA\Shillinq\Service\Commitment\CommitmentInvoicing;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Executes a carry-over as the signed-in user, guards the last-invoice
 * endpoints with access and posting rights, and maps a domain refusal to 422
 * and any other failure to a logged 500.
 *
 * @covers \OCA\Shillinq\Controller\CommitmentYearEndController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CommitmentYearEndControllerGapsTest extends TestCase {

	/**
	 * Carry-over service double.
	 *
	 * @var CommitmentCarryOverService&MockObject
	 */
	private CommitmentCarryOverService&MockObject $carryOver;

	/**
	 * Invoicing service double.
	 *
	 * @var CommitmentInvoicing&MockObject
	 */
	private CommitmentInvoicing&MockObject $invoicing;

	/**
	 * Administration context double.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Request parameters served by the request double.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = ['administrationId' => 'a1', 'fromYear' => '2026'];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->carryOver = $this->createMock(CommitmentCarryOverService::class);
		$this->invoicing = $this->createMock(CommitmentInvoicing::class);
		$this->context   = $this->createMock(AdministrationContextService::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller, signed in or not.
	 *
	 * @param bool $loggedIn Whether a user is signed in.
	 *
	 * @return CommitmentYearEndController
	 */
	private function controller(bool $loggedIn=true): CommitmentYearEndController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default=null): mixed => ($this->params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		return new CommitmentYearEndController(
			request: $request,
			carryOver: $this->carryOver,
			invoicing: $this->invoicing,
			context: $this->context,
			session: $session,
			l10n: $l10n,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Let the caller into administration a1 with the given role.
	 *
	 * @param string $role The caller's role.
	 *
	 * @return void
	 */
	private function member(string $role): void {
		$this->context->method('canAccess')->with('a1')->willReturn(true);
		$this->context->method('buildContext')->willReturn(
			['administrations' => [['administrationId' => 'a1', 'role' => $role]]]
		);
	}//end member()

	/**
	 * A controller carries over as themselves, with the requested year.
	 *
	 * @return void
	 */
	public function testCarryOverExecutesAsTheSignedInUser(): void {
		$this->member(role: 'controller');
		$this->context->method('currentUserId')->willReturn('carol');
		$this->carryOver->expects(self::once())
			->method('execute')
			->with('a1', 2026, 'carol')
			->willReturn(['carried' => 3]);

		$response = $this->controller()->carryOver();

		self::assertSame(200, $response->getStatus());
		self::assertSame(['carried' => 3], $response->getData());
	}//end testCarryOverExecutesAsTheSignedInUser()

	/**
	 * A bookkeeper may not carry over; nothing is executed.
	 *
	 * @return void
	 */
	public function testCarryOverRefusesANonController(): void {
		$this->member(role: 'boekhouder');
		$this->carryOver->expects(self::never())->method('execute');

		$response = $this->controller()->carryOver();

		self::assertSame(403, $response->getStatus());
		self::assertSame(['message' => 'T:Only a controller can carry commitments over.'], $response->getData());
	}//end testCarryOverRefusesANonController()

	/**
	 * A domain refusal from the carry-over is answered 422 with its message.
	 *
	 * @return void
	 */
	public function testCarryOverDomainRefusalAnswers422(): void {
		$this->member(role: 'eigenaar');
		$this->carryOver->method('execute')->willThrowException(new DomainException('Year already closed.'));
		$this->logger->expects(self::never())->method('error');

		$response = $this->controller()->carryOver();

		self::assertSame(422, $response->getStatus());
		self::assertSame(['message' => 'T:Year already closed.'], $response->getData());
	}//end testCarryOverDomainRefusalAnswers422()

	/**
	 * Any other failure is logged and answered 500 without its cause.
	 *
	 * @return void
	 */
	public function testUnexpectedFailureIsLoggedAndAnswers500(): void {
		$this->member(role: 'controller');
		$this->carryOver->method('preview')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())
			->method('error')
			->with('CommitmentYearEndController: request failed', ['exception' => 'db down']);

		$response = $this->controller()->previewCarryOver();

		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'T:The commitments could not be updated.'], $response->getData());
	}//end testUnexpectedFailureIsLoggedAndAnswers500()

	/**
	 * A poster may preview the last invoice of administration a1.
	 *
	 * @return void
	 */
	public function testPreviewLastInvoiceForAPoster(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->context->expects(self::once())->method('canPostJournalEntry')->with('a1')->willReturn(true);
		$this->invoicing->expects(self::once())
			->method('lastInvoice')
			->with('a1', 'inv-9')
			->willReturn(['releases' => 120.0]);

		$response = $this->controller()->previewLastInvoice(id: 'inv-9');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['releases' => 120.0], $response->getData());
	}//end testPreviewLastInvoiceForAPoster()

	/**
	 * A poster may mark the last invoice of administration a1.
	 *
	 * @return void
	 */
	public function testMarkLastInvoiceForAPoster(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->context->method('canPostJournalEntry')->willReturn(true);
		$this->invoicing->expects(self::once())
			->method('markLast')
			->with('a1', 'inv-9')
			->willReturn(['closed' => true]);

		$response = $this->controller()->markLastInvoice(id: 'inv-9');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['closed' => true], $response->getData());
	}//end testMarkLastInvoiceForAPoster()

	/**
	 * A member without posting rights may not mark the last invoice.
	 *
	 * @return void
	 */
	public function testMarkLastInvoiceRefusesANonPoster(): void {
		$this->context->method('canAccess')->willReturn(true);
		$this->context->method('canPostJournalEntry')->willReturn(false);
		$this->invoicing->expects(self::never())->method('markLast');

		$response = $this->controller()->markLastInvoice(id: 'inv-9');

		self::assertSame(403, $response->getStatus());
		self::assertSame(['message' => 'T:You may not change invoices in this administration.'], $response->getData());
	}//end testMarkLastInvoiceRefusesANonPoster()

	/**
	 * An anonymous caller is answered 401 on the last-invoice preview.
	 *
	 * @return void
	 */
	public function testPreviewLastInvoiceRefusesAnAnonymousCaller(): void {
		$this->context->expects(self::never())->method('canAccess');
		$this->invoicing->expects(self::never())->method('lastInvoice');

		$response = $this->controller(loggedIn: false)->previewLastInvoice(id: 'inv-9');

		self::assertSame(401, $response->getStatus());
		self::assertSame(['message' => 'T:Not logged in'], $response->getData());
	}//end testPreviewLastInvoiceRefusesAnAnonymousCaller()

	/**
	 * An administration the caller cannot access is masked as 404.
	 *
	 * @return void
	 */
	public function testInaccessibleAdministrationIsMaskedAs404(): void {
		$this->context->expects(self::once())->method('canAccess')->with('a1')->willReturn(false);
		$this->context->expects(self::never())->method('canPostJournalEntry');

		$response = $this->controller()->markLastInvoice(id: 'inv-9');

		self::assertSame(404, $response->getStatus());
		self::assertSame(['message' => 'T:Administration not found'], $response->getData());
	}//end testInaccessibleAdministrationIsMaskedAs404()

	/**
	 * A blank administration id is refused as 404 without asking for access.
	 *
	 * @return void
	 */
	public function testBlankAdministrationIdIsRefusedAs404(): void {
		$this->params = ['administrationId' => '   '];
		$this->context->expects(self::never())->method('canAccess');

		$response = $this->controller()->carryOver();

		self::assertSame(404, $response->getStatus());
		self::assertSame(['message' => 'T:Administration not found'], $response->getData());
	}//end testBlankAdministrationIdIsRefusedAs404()
}//end class
