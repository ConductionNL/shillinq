<?php

/**
 * Registration test for integriq's CloudEvent consumers.
 *
 * integriq publishes its CloudEvents as OpenRegister objects, so the only
 * event shillinq can register for is OpenRegister's `ObjectCreatedEvent`. A
 * listener registered on a CloudEvent NAME instead is never dispatched, and
 * nothing fails: that is how the payment status went unheard (issue #1681).
 * `Application::register()` cannot run without a Nextcloud server, so this
 * reads the composition root as text, as RegisterLifecycleGuardsResolveTest
 * already does for guard tags.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * integriq CloudEvent consumers are registered on ObjectCreatedEvent.
 */
final class IntegriqCloudEventRegistrationTest extends TestCase {
	/**
	 * The composition root, read as text.
	 *
	 * @var string
	 */
	private string $source = '';

	/**
	 * Read Application.php.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->source = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');
	}//end setUp()

	/**
	 * The payment status listener hears the object integriq saves.
	 *
	 * @return void
	 */
	public function testThePaymentStatusListenerIsRegisteredOnObjectCreatedEvent(): void {
		self::assertStringContainsString('use OCA\OpenRegister\Event\ObjectCreatedEvent;', $this->source);
		self::assertStringContainsString('use OCA\Shillinq\Listener\IntegriqCloudEventListener;', $this->source);
		self::assertMatchesRegularExpression(
			'/registerEventListener\(\s*event:\s*ObjectCreatedEvent::class,\s*listener:\s*IntegriqCloudEventListener::class\s*\)/',
			$this->source,
			'IntegriqCloudEventListener must be registered on ObjectCreatedEvent, the only event integriq\'s CloudEvents raise.'
		);
	}//end testThePaymentStatusListenerIsRegisteredOnObjectCreatedEvent()

	/**
	 * The Peppol delivery status listener hears the object integriq saves, and
	 * is no longer registered on the CloudEvent name nothing dispatches (issue #1111).
	 *
	 * @return void
	 */
	public function testThePeppolDeliveryStatusListenerIsRegisteredOnObjectCreatedEvent(): void {
		self::assertMatchesRegularExpression(
			'/registerEventListener\(\s*event:\s*ObjectCreatedEvent::class,\s*listener:\s*PeppolDeliveryStatusListener::class\s*\)/',
			$this->source
		);
		self::assertDoesNotMatchRegularExpression(
			'/registerEventListener\(\s*event:\s*(PeppolDeliveryStatusListener::\w+|\'nl\.conduction\.peppol\.delivery\.status\')/',
			$this->source,
			'A registration on the CloudEvent name never fires: integriq dispatches no event by that name.'
		);
	}//end testThePeppolDeliveryStatusListenerIsRegisteredOnObjectCreatedEvent()

	/**
	 * The rejection notice the listener raises has a notifier that renders it.
	 *
	 * @return void
	 */
	public function testTheEInvoiceNotifierIsRegistered(): void {
		self::assertStringContainsString('use OCA\Shillinq\Notification\EInvoiceNotifier;', $this->source);
		self::assertMatchesRegularExpression('/registerNotifierService\(\s*EInvoiceNotifier::class\s*\)/', $this->source);
	}//end testTheEInvoiceNotifierIsRegistered()
}//end class
