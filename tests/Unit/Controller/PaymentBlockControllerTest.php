<?php

/**
 * Unit tests for PaymentBlockController.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\Shillinq\Controller\PaymentBlockController;
use OCA\Shillinq\Listener\PaymentBlockReleaseListener;
use OCA\Shillinq\PaymentRun\PaymentBlockService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests how the block endpoint answers the service's outcomes.
 *
 * @covers \OCA\Shillinq\Controller\PaymentBlockController
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PaymentBlockControllerTest extends TestCase {

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock PaymentBlockService.
	 *
	 * @var PaymentBlockService&MockObject
	 */
	private PaymentBlockService&MockObject $blocks;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The controller under test.
	 *
	 * @var PaymentBlockController
	 */
	private PaymentBlockController $controller;

	/**
	 * Build the controller with a release request and an echoing translator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnMap([['blocked', true, 'false']]);
		$this->blocks = $this->createMock(PaymentBlockService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l10n         = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->controller = new PaymentBlockController($this->request, $this->blocks, $l10n, $this->logger);
	}//end setUp()

	/**
	 * A release vetoed by the release role rule answers 403 with that rule's sentence, not a save failure.
	 *
	 * @return void
	 */
	public function testAReleaseRefusedByTheRoleRuleIsAForbiddenRefusal(): void {
		$this->blocks->expects($this->once())->method('setBlock')
			->with('APTransaction', 'inv-1', false, 'Creditnota ontvangen')
			->willThrowException(new HookStoppedException(
				PaymentBlockReleaseListener::MESSAGE_ROLE,
				['message' => PaymentBlockReleaseListener::MESSAGE_ROLE, 'field' => 'paymentBlocked']
			));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller->update('APTransaction', 'inv-1', 'Creditnota ontvangen');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['error' => 'refused', 'message' => 'Only a controller or a treasurer can release a payment block.'],
			$response->getData()
		);
	}//end testAReleaseRefusedByTheRoleRuleIsAForbiddenRefusal()

	/**
	 * Any other save-path refusal is also a 403 refusal, with a general sentence.
	 *
	 * @return void
	 */
	public function testAnotherSavePathRefusalIsAForbiddenRefusal(): void {
		$this->blocks->method('setBlock')->willThrowException(new HookStoppedException('Some other rule'));

		$response = $this->controller->update('Payee', 'payee-1', 'Reden');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['error' => 'refused', 'message' => 'This change to the payment block is not allowed.'],
			$response->getData()
		);
	}//end testAnotherSavePathRefusalIsAForbiddenRefusal()

	/**
	 * An unexpected failure is logged and answers 500 without its details.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsASaveFailure(): void {
		$this->blocks->method('setBlock')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->update('Payee', 'payee-1', 'Reden');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(
			['error' => 'save-failed', 'message' => 'The payment block could not be saved.'],
			$response->getData()
		);
	}//end testAnUnexpectedFailureIsASaveFailure()

	/**
	 * A service refusal keeps its status and gets its sentence.
	 *
	 * @return void
	 */
	public function testAServiceRefusalKeepsItsStatus(): void {
		$this->blocks->method('setBlock')->willReturn(['status' => 409, 'error' => 'not-blocked']);

		$response = $this->controller->update('Payee', 'payee-1', 'Reden');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'not-blocked', 'message' => 'Payment is not blocked.'], $response->getData());
	}//end testAServiceRefusalKeepsItsStatus()
}//end class
