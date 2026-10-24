<?php

/**
 * Unit tests for FinancialDocumentActionController.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003, REQ-FDO-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\FinancialDocumentActionController;
use OCA\Shillinq\Service\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\FinancialDocumentActions;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The controller hands the acts to the service and answers a refusal with its status.
 */
final class FinancialDocumentActionControllerTest extends TestCase {
	/**
	 * The controller over an actions double.
	 *
	 * @param FinancialDocumentActions $actions The service.
	 *
	 * @return FinancialDocumentActionController
	 */
	private function controller(FinancialDocumentActions $actions): FinancialDocumentActionController {
		return new FinancialDocumentActionController(appName: 'shillinq', request: $this->createMock(IRequest::class), actions: $actions);
	}//end controller()

	public function testIssueInvoicePassesTheObjectAndThePayload(): void {
		$actions = $this->createMock(FinancialDocumentActions::class);
		$actions->expects(self::once())->method('issueInvoice')
			->with('dossiq', 'case', 'c-1', ['amount' => 250.0, 'description' => 'Leges', 'currency' => 'EUR', 'debtor' => ['name' => 'J'], 'administrationId' => '', 'objectType' => 'case'])
			->willReturn(['id' => 'inv-9']);

		$response = $this->controller($actions)->issueInvoice(register: 'dossiq', schema: 'case', objectId: 'c-1', amount: 250.0, description: 'Leges', debtor: ['name' => 'J'], objectType: 'case');

		self::assertSame(201, $response->getStatus());
		self::assertSame(['invoice' => ['id' => 'inv-9']], $response->getData());
	}//end testIssueInvoicePassesTheObjectAndThePayload()

	public function testIssueInvoiceNeedsTheObject(): void {
		$actions = $this->createMock(FinancialDocumentActions::class);
		$actions->expects(self::never())->method('issueInvoice');

		self::assertSame(400, $this->controller($actions)->issueInvoice(register: 'dossiq', schema: '', objectId: 'c-1')->getStatus());
	}//end testIssueInvoiceNeedsTheObject()

	public function testARefusalAnswersWithItsStatusAndWords(): void {
		$actions = $this->createMock(FinancialDocumentActions::class);
		$actions->method('requestPayment')->willThrowException(new FinancialDocumentActionRefusedException(message: 'This needs the payment.request action.', status: 403));

		$response = $this->controller($actions)->requestPayment(id: 'i-1');

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'This needs the payment.request action.'], $response->getData());
	}//end testARefusalAnswersWithItsStatusAndWords()

	public function testRequestPaymentAnswersTheRequest(): void {
		$actions = $this->createMock(FinancialDocumentActions::class);
		$actions->method('requestPayment')->with('i-1')->willReturn(['id' => 'p-9', 'state' => 'pending']);

		$response = $this->controller($actions)->requestPayment(id: 'i-1');

		self::assertSame(201, $response->getStatus());
		self::assertSame(['request' => ['id' => 'p-9', 'state' => 'pending']], $response->getData());
	}//end testRequestPaymentAnswersTheRequest()

	public function testBothActsAreOpenToAnyUserAndRoutedAsPosts(): void {
		$routes = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');

		foreach (['issueInvoice' => '/api/financial-documents/invoices', 'requestPayment' => '/api/financial-documents/invoices/{id}/payment-request'] as $method => $url) {
			$attributes = array_map(static fn ($a): string => $a->getName(), (new ReflectionMethod(FinancialDocumentActionController::class, $method))->getAttributes());
			self::assertContains(NoAdminRequired::class, $attributes, $method);
			self::assertStringContainsString("['name' => 'financialDocumentAction#" . $method . "', 'url' => '" . $url . "', 'verb' => 'POST']", $routes, $method);
		}
	}//end testBothActsAreOpenToAnyUserAndRoutedAsPosts()
}//end class
