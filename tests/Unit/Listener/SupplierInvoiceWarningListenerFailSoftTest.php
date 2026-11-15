<?php

/**
 * SupplierInvoiceWarningListener's guards and its fail-soft patch.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\Shillinq\Listener\SupplierInvoiceWarningListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\RegisterLocation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A deleted invoice and another schema are ignored, changed warnings are
 * patched onto the invoice by its uuid, and a failing check is logged as a
 * warning and swallowed.
 *
 * @covers \OCA\Shillinq\Listener\SupplierInvoiceWarningListener
 */
final class SupplierInvoiceWarningListenerFailSoftTest extends TestCase {
	/**
	 * The invoice checks.
	 *
	 * @var SupplierInvoiceChecks&MockObject
	 */
	private SupplierInvoiceChecks&MockObject $checks;

	/**
	 * The schema resolver.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $resolver;

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var SupplierInvoiceWarningListener
	 */
	private SupplierInvoiceWarningListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = $this->createMock(originalClassName: SupplierInvoiceChecks::class);
		$this->resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$this->objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$settings = $this->createMock(originalClassName: RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$this->listener = new SupplierInvoiceWarningListener(
			checks: $this->checks,
			schemaResolver: $this->resolver,
			objectService: $this->objects,
			settings: $settings,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A supplier invoice entity without an id in its data.
	 *
	 * @return ObjectEntity The invoice.
	 */
	private function invoice(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('inv-1');
		$entity->setSchema('3001');
		$entity->setObject(['invoiceNumber' => 'F-1']);
		return $entity;
	}//end invoice()

	/**
	 * A deleted invoice is not checked.
	 *
	 * @return void
	 */
	public function testADeletedInvoiceIsIgnored(): void {
		$this->resolver->expects($this->never())->method('schemaSlug');
		$this->checks->expects($this->never())->method('warnings');

		$this->listener->handle(event: new ObjectDeletedEvent($this->invoice()));
	}//end testADeletedInvoiceIsIgnored()

	/**
	 * An object in another schema is not checked.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$this->resolver->method('schemaSlug')->willReturn('SalesInvoice');
		$this->checks->expects($this->never())->method('warnings');

		$this->listener->handle(event: new ObjectCreatedEvent($this->invoice()));
	}//end testAnotherSchemaIsIgnored()

	/**
	 * Changed warnings are patched onto the invoice by its uuid.
	 *
	 * @return void
	 */
	public function testChangedWarningsArePatchedByUuid(): void {
		$this->resolver->method('schemaSlug')->willReturn('SupplierInvoice');
		$warnings = ['duplicateOfId' => 'inv-0', 'ibanMismatch' => ''];
		$this->checks->expects($this->once())->method('warnings')
			->with($this->callback(static fn (array $invoice): bool => $invoice['id'] === 'inv-1'))
			->willReturn($warnings);
		$this->objects->expects($this->once())->method('patchObject')
			->with('inv-1', $warnings, 'shillinq', 'SupplierInvoice');

		$this->listener->handle(event: new ObjectCreatedEvent($this->invoice()));
	}//end testChangedWarningsArePatchedByUuid()

	/**
	 * A failing check is logged as a warning and nothing is patched.
	 *
	 * @return void
	 */
	public function testAFailingCheckIsLoggedAndSwallowed(): void {
		$this->resolver->method('schemaSlug')->willReturn('SupplierInvoice');
		$this->checks->method('warnings')->willThrowException(new \RuntimeException('store down'));
		$this->objects->expects($this->never())->method('patchObject');
		$this->logger->expects($this->once())->method('warning')
			->with('SupplierInvoiceWarningListener: warnings not updated', ['exception' => 'store down']);

		$this->listener->handle(event: new ObjectCreatedEvent($this->invoice()));
	}//end testAFailingCheckIsLoggedAndSwallowed()
}//end class
