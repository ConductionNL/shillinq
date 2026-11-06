<?php

/**
 * Tests for the quick-draft invoice endpoint.
 *
 * @category Tests
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

use OCA\Shillinq\Controller\QuickDraftInvoiceController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Sales\QuickDraftInvoiceService;
use OCA\Shillinq\Service\Sales\QuickDraftRefusedException;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The administration is the caller's: a named one must be accessible, else
 * the active one is used.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class QuickDraftInvoiceControllerTest extends TestCase {
	/**
	 * Build the controller.
	 *
	 * @param QuickDraftInvoiceService $service The service.
	 * @param bool                     $member  Whether the caller may access a named administration.
	 * @param array<string,mixed>      $params  Request params.
	 * @param string|null              $active  The caller's active administration.
	 *
	 * @return QuickDraftInvoiceController
	 */
	private function controller(QuickDraftInvoiceService $service, bool $member, array $params, ?string $active='adm-1'): QuickDraftInvoiceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key) => $params[$key] ?? null);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn($member);
		$context->method('buildContext')->willReturn(['userId' => 'u', 'administrations' => [], 'activeAdministrationId' => $active]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL:' . $text);

		return new QuickDraftInvoiceController(
			request: $request,
			service: $service,
			context: $context,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A named administration the caller cannot access is refused unread.
	 *
	 * @return void
	 */
	public function testAForeignAdministrationIsForbidden(): void {
		$service = $this->createMock(QuickDraftInvoiceService::class);
		$service->expects(self::never())->method('draft');

		$response = $this->controller($service, false, ['administrationId' => 'adm-2', 'customerId' => 'c'])->create();

		self::assertSame(403, $response->getStatus());
	}//end testAForeignAdministrationIsForbidden()

	/**
	 * Without a named administration the active one is used, and only the
	 * draft's own fields reach the service.
	 *
	 * @return void
	 */
	public function testTheActiveAdministrationDraftsAndAnswers201(): void {
		$service = $this->createMock(QuickDraftInvoiceService::class);
		$service->expects(self::once())->method('draft')
			->with(
				'adm-1',
				self::callback(static fn (array $request): bool => $request['customerId'] === 'c' && array_key_exists('netAmount', $request) === false)
			)
			->willReturn(['id' => 'inv-1', 'invoiceNumber' => 'DRAFT-1']);

		$response = $this->controller($service, true, ['customerId' => 'c', 'netAmount' => 1, 'lines' => []])->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame('inv-1', $response->getData()['id']);
	}//end testTheActiveAdministrationDraftsAndAnswers201()

	/**
	 * A refusal answers 422 with the translated reason.
	 *
	 * @return void
	 */
	public function testARefusalAnswers422(): void {
		$service = $this->createMock(QuickDraftInvoiceService::class);
		$service->method('draft')->willThrowException(new QuickDraftRefusedException('This customer is not part of your administration.'));

		$response = $this->controller($service, true, ['customerId' => 'c'], null)->create();

		self::assertSame(422, $response->getStatus());
		self::assertSame('NL:This customer is not part of your administration.', $response->getData()['message']);
	}//end testARefusalAnswers422()
}//end class
