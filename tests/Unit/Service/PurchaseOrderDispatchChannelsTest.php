<?php

/**
 * PurchaseOrderService::dispatchChannels() says which channel can take an order, and why not.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrder\PeppolTransmissionAdapterInterface;
use OCA\Shillinq\Service\PurchaseOrder\PurchaseOrderMailerInterface;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each channel is available only when something real is bound behind it.
 */
final class PurchaseOrderDispatchChannelsTest extends TestCase {

	/**
	 * The service with this Peppol adapter (null: the logging stand-in) and a mailer that is or is not available.
	 *
	 * @param PeppolTransmissionAdapterInterface|null $peppol     The adapter, or null for the default.
	 * @param bool                                    $mailWorks  Whether the mailer has a channel.
	 *
	 * @return PurchaseOrderService
	 */
	private function service(?PeppolTransmissionAdapterInterface $peppol, bool $mailWorks): PurchaseOrderService {
		$mailer = $this->createMock(PurchaseOrderMailerInterface::class);
		$mailer->method('isAvailable')->willReturn($mailWorks);
		$mailer->expects($this->never())->method('sendPurchaseOrderEmail');

		return new PurchaseOrderService(
			appConfig: $this->createMock(IAppConfig::class),
			administrationContext: $this->createMock(AdministrationContextService::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter(new \stdClass()),
			peppolAdapter: $peppol,
			purchaseOrderMailer: $mailer,
		);
	}//end service()

	/**
	 * Without integriq both channels are unavailable, each with its reason.
	 *
	 * @return void
	 */
	public function testNothingBoundMeansNeitherChannel(): void {
		$this->assertSame(
			[
				'peppol' => ['available' => false, 'reason' => 'Peppol sending is not configured'],
				'email' => ['available' => false, 'reason' => 'Email sending is not configured'],
			],
			$this->service(peppol: null, mailWorks: false)->dispatchChannels()
		);
	}//end testNothingBoundMeansNeitherChannel()

	/**
	 * A real Peppol adapter and a working mail channel make both available, without a reason.
	 *
	 * @return void
	 */
	public function testBoundChannelsAreAvailable(): void {
		$this->assertSame(
			[
				'peppol' => ['available' => true, 'reason' => null],
				'email' => ['available' => true, 'reason' => null],
			],
			$this->service(peppol: $this->createMock(PeppolTransmissionAdapterInterface::class), mailWorks: true)->dispatchChannels()
		);
	}//end testBoundChannelsAreAvailable()

	/**
	 * Peppol without mail: email is refused with its reason, Peppol stays offered.
	 *
	 * @return void
	 */
	public function testEachChannelIsJudgedOnItsOwn(): void {
		$channels = $this->service(peppol: $this->createMock(PeppolTransmissionAdapterInterface::class), mailWorks: false)->dispatchChannels();

		$this->assertTrue($channels['peppol']['available']);
		$this->assertFalse($channels['email']['available']);
		$this->assertSame('Email sending is not configured', $channels['email']['reason']);
	}//end testEachChannelIsJudgedOnItsOwn()
}//end class
