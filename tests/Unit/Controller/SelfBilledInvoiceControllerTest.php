<?php

/**
 * Unit tests for SelfBilledInvoiceController (REQ-SEIX-007).
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
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-3.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\SelfBilledInvoiceController;
use OCA\Shillinq\Service\EInvoice\SelfBilledInvoiceReview;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Covers the reject endpoint: anonymous 401, malformed scope 400, the review's
 * refusals mapped to 404 and 400, an unexpected failure 500 without its
 * message, and a rejection 200 with the invoice.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SelfBilledInvoiceControllerTest extends TestCase {
	/**
	 * The controller over a request with these parameters.
	 *
	 * @param array<string,string>    $params The request parameters.
	 * @param SelfBilledInvoiceReview $review The review.
	 * @param bool                    $loggedIn Whether a user is logged in.
	 *
	 * @return SelfBilledInvoiceController
	 */
	private function controller(array $params, SelfBilledInvoiceReview $review, bool $loggedIn = true): SelfBilledInvoiceController {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createStub(IUser::class) : null);

		return new SelfBilledInvoiceController(request: $request, review: $review, userSession: $session, logger: new NullLogger());
	}//end controller()

	/**
	 * A rejection answers 200 with the invoice, passing the scope and reason through.
	 *
	 * @return void
	 */
	public function testARejectionAnswersTheInvoice(): void {
		$review = $this->createMock(SelfBilledInvoiceReview::class);
		$review->expects(self::once())->method('reject')
			->with('adm-jansen', 'SB-2026-00042', 'Hoeveelheid klopt niet')
			->willReturn(['invoiceNumber' => 'SB-2026-00042', 'selfBilling' => ['rejectionReason' => 'Hoeveelheid klopt niet']]);

		$response = $this->controller(params: ['administrationId' => 'adm-jansen', 'reason' => 'Hoeveelheid klopt niet'], review: $review)
			->reject(invoiceNumber: 'SB-2026-00042');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Hoeveelheid klopt niet', $response->getData()['selfBilling']['rejectionReason']);
	}//end testARejectionAnswersTheInvoice()

	/**
	 * Anonymous and malformed calls never reach the review.
	 *
	 * @return void
	 */
	public function testAnonymousAndMalformedCallsAreRefused(): void {
		$review = $this->createMock(SelfBilledInvoiceReview::class);
		$review->expects(self::never())->method('reject');

		$anonymous = $this->controller(params: ['administrationId' => 'adm-jansen'], review: $review, loggedIn: false)->reject(invoiceNumber: 'SB-1');
		$noScope   = $this->controller(params: [], review: $review)->reject(invoiceNumber: 'SB-1');
		$badNumber = $this->controller(params: ['administrationId' => 'adm-jansen'], review: $review)->reject(invoiceNumber: 'SB 1/../x');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $noScope->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $badNumber->getStatus());
	}//end testAnonymousAndMalformedCallsAreRefused()

	/**
	 * The review's refusals and failures, as status codes.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailuresMapToStatusCodes(): void {
		$cases = [
			[new RuntimeException('ARInvoice not found'), Http::STATUS_NOT_FOUND, 'ARInvoice not found'],
			[new RuntimeException('Only a draft self-billed invoice can be rejected'), Http::STATUS_BAD_REQUEST, 'Only a draft self-billed invoice can be rejected'],
			[new \LogicException('database is down'), Http::STATUS_INTERNAL_SERVER_ERROR, 'Could not reject the invoice'],
		];

		foreach ($cases as [$thrown, $status, $error]) {
			$review = $this->createStub(SelfBilledInvoiceReview::class);
			$review->method('reject')->willThrowException($thrown);

			$response = $this->controller(params: ['administrationId' => 'adm-jansen', 'reason' => 'x'], review: $review)->reject(invoiceNumber: 'SB-2026-00042');

			self::assertSame($status, $response->getStatus(), $error);
			self::assertSame(['error' => $error], $response->getData());
		}
	}//end testRefusalsAndFailuresMapToStatusCodes()
}//end class
