<?php

/**
 * Unit tests for SupplierInvoiceWarningListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\SupplierInvoiceWarningListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Purchasing\SupplierInvoiceChecksTest;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-PSII-003: a typed invoice gets the same duplicate warning an import does.
 */
class SupplierInvoiceWarningListenerTest extends TestCase {

	/**
	 * Everything the store wrote.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The listener over the checks fixture plus one typed invoice.
	 *
	 * @param array<string, mixed> $typed The typed invoice as stored.
	 *
	 * @return SupplierInvoiceWarningListener
	 */
	private function listener(array $typed): SupplierInvoiceWarningListener {
		$records = SupplierInvoiceChecksTest::records();
		$records['SupplierInvoice'][] = $typed;
		$this->saved = [];
		$store = new InMemoryObjectServiceStub($records, $this->saved);
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$resolver = $this->createStub(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn('SupplierInvoice');

		return new SupplierInvoiceWarningListener(
			new SupplierInvoiceChecks($store, $settings),
			$resolver,
			$store,
			$settings,
			$this->createStub(LoggerInterface::class)
		);

	}//end listener()

	/**
	 * The entity OpenRegister hands the event: a numeric schema id, the body as object.
	 *
	 * @param array<string, mixed> $invoice The body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $invoice): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($invoice['id']);
		$entity->setSchema('412');
		$entity->setObject($invoice);

		return $entity;

	}//end entity()

	/**
	 * A typed invoice 2026-0455 for the same supplier.
	 *
	 * @return array<string, mixed>
	 */
	private function typed(): array {
		return [
			'id' => 'si-typed', 'invoiceNumber' => '2026-0455', 'supplierId' => SupplierInvoiceChecksTest::DRUKKERIJ,
			'administrationId' => SupplierInvoiceChecksTest::ADMIN, 'statusCode' => 'received',
		];

	}//end typed()

	/**
	 * Saving a typed repeat of an imported number writes the warning naming the earlier one.
	 *
	 * @return void
	 */
	public function testATypedRepeatGetsTheDuplicateWarning(): void {
		$this->listener($this->typed())->handle(new ObjectCreatedEvent($this->entity($this->typed())));

		$this->assertCount(1, $this->saved);
		$this->assertSame('si-0455', $this->saved[0]['object']['duplicateOfId']);
		$this->assertSame('', $this->saved[0]['object']['ibanMismatch']);

	}//end testATypedRepeatGetsTheDuplicateWarning()

	/**
	 * The update its own patch causes finds nothing to change: no loop.
	 *
	 * @return void
	 */
	public function testItsOwnPatchDoesNotLoop(): void {
		$typed = $this->typed() + ['duplicateOfId' => 'si-0455', 'ibanMismatch' => ''];

		$this->listener($typed)->handle(new ObjectUpdatedEvent($this->entity($typed), $this->entity($this->typed())));

		$this->assertSame([], $this->saved);

	}//end testItsOwnPatchDoesNotLoop()

	/**
	 * The app registers the listener for SupplierInvoice writes (the wiring, from the caller).
	 *
	 * @return void
	 */
	public function testTheAppRegistersTheListener(): void {
		$application = (string)file_get_contents(__DIR__ . '/../../../lib/AppInfo/Application.php');

		$this->assertMatchesRegularExpression(
			'/registerFilteredObjectWriteListener\(\s*dispatcher: \$dispatcher,\s*listener: SupplierInvoiceWarningListener::class,\s*schemas: \[\'SupplierInvoice\'\]/',
			$application
		);

	}//end testTheAppRegistersTheListener()
}//end class
