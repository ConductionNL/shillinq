<?php

/**
 * OnlinePaymentStatusControllerTest
 *
 * Task 3.3 of receivables-payment-links (REQ-RPL-002): the payment request
 * page asks whether a payment link can be created, and is told why not when
 * the bound payment adapter is dormant.
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
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\OnlinePaymentStatusController;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Whether online payment is set up, through the controller.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class OnlinePaymentStatusControllerTest extends TestCase {
	/**
	 * A dormant adapter answers not live, with the reason and the sentence to show.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
	 */
	public function testADormantAdapterSaysOnlinePaymentIsNotSetUp(): void {
		$response = $this->controller(dormant: true)->status();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'live'    => false,
				'reason'  => 'not-set-up',
				'message' => 'Online payment is not set up: configure a payment source in integriq.',
			],
			$response->getData()
		);
	}//end testADormantAdapterSaysOnlinePaymentIsNotSetUp()

	/**
	 * A live adapter answers live, with no reason and nothing to show.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
	 */
	public function testALiveAdapterSaysOnlinePaymentIsLive(): void {
		$response = $this->controller(dormant: false)->status();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['live' => true, 'reason' => '', 'message' => ''], $response->getData());
	}//end testALiveAdapterSaysOnlinePaymentIsLive()

	/**
	 * The controller over an adapter that is or is not dormant.
	 *
	 * @param bool $dormant Whether the bound adapter is the log-only stub.
	 *
	 * @return OnlinePaymentStatusController The controller.
	 */
	private function controller(bool $dormant): OnlinePaymentStatusController {
		$adapter = $this->createStub(MolliePaymentAdapterInterface::class);
		$adapter->method('isDormant')->willReturn($dormant);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text): string => $text);

		return new OnlinePaymentStatusController(
			'shillinq',
			$this->createStub(IRequest::class),
			$adapter,
			$l10n,
		);
	}//end controller()
}//end class
