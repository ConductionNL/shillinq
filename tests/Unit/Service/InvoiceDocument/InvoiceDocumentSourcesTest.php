<?php

/**
 * Unit tests for the records an invoice document is made from (REQ-SID-001, REQ-SID-005).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\InvoiceDocument;

use OCA\Shillinq\Service\InvoiceDocument\InvoiceDocumentSources;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Lookups over an in-memory register, scoped to the invoice's administration.
 */
#[CoversClass(InvoiceDocumentSources::class)]
#[UsesClass(ObjectIdentifier::class)]
final class InvoiceDocumentSourcesTest extends TestCase {
	/**
	 * The sources over a register with two administrations.
	 *
	 * @param IRootFolder|null $root The root folder.
	 *
	 * @return InvoiceDocumentSources
	 */
	private function sources(?IRootFolder $root = null): InvoiceDocumentSources {
		$store = new InMemoryObjectServiceStub(
			[
				'Administration' => [['id' => 'adm-1', 'name' => 'Van Dijk', 'defaultLanguage' => 'nl']],
				'InvoiceLayout' => [
					['id' => 'l-2', 'administrationId' => 'adm-2', 'variant' => 'compact'],
					['id' => 'l-1', 'administrationId' => 'adm-1', 'variant' => 'modern'],
				],
				'CustomerMaster' => [
					['id' => 'c-1', 'administrationId' => 'adm-1', 'language' => 'en'],
					['id' => 'c-2', 'administrationId' => 'adm-2', 'language' => 'de'],
				],
				'PaymentRequest' => [
					['invoiceReference' => '2026-0042', 'administrationId' => 'adm-2', 'state' => 'pending', 'paymentLink' => 'https://other'],
					['invoiceReference' => '2026-0042', 'administrationId' => 'adm-1', 'state' => 'expired', 'paymentLink' => 'https://old'],
					['invoiceReference' => '2026-0042', 'administrationId' => 'adm-1', 'state' => 'pending', 'paymentLink' => 'https://pay/new'],
				],
			]
		);

		return new InvoiceDocumentSources($store, $root ?? $this->createMock(IRootFolder::class));

	}//end sources()

	/**
	 * The layout is the administration's own, never another's.
	 *
	 * @return void
	 */
	public function testTheLayoutIsTheAdministrationsOwn(): void {
		self::assertSame('modern', $this->sources()->layout('adm-1')['variant']);
		self::assertSame([], $this->sources()->layout('adm-3'));
		self::assertSame([], $this->sources()->layout(''));

	}//end testTheLayoutIsTheAdministrationsOwn()

	/**
	 * Another administration's customer is a miss.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsCustomerIsAMiss(): void {
		self::assertSame('en', $this->sources()->customer('c-1', 'adm-1')['language']);
		self::assertSame([], $this->sources()->customer('c-2', 'adm-1'));
		self::assertSame([], $this->sources()->customer('', 'adm-1'));
		self::assertSame('Van Dijk', $this->sources()->administration('adm-1')['name']);

	}//end testAnotherAdministrationsCustomerIsAMiss()

	/**
	 * The pay link is the open request's of this administration.
	 *
	 * @return void
	 */
	public function testThePayLinkIsTheOpenRequestOfThisAdministration(): void {
		self::assertSame('https://pay/new', $this->sources()->payLink('2026-0042', 'adm-1'));
		self::assertNull($this->sources()->payLink('2026-0099', 'adm-1'));
		self::assertNull($this->sources()->payLink('', 'adm-1'));

	}//end testThePayLinkIsTheOpenRequestOfThisAdministration()

	/**
	 * The logo is embedded as a data URI.
	 *
	 * @return void
	 */
	public function testTheLogoIsEmbeddedAsData(): void {
		$file = $this->createMock(File::class);
		$file->method('getMimeType')->willReturn('image/png');
		$file->method('getSize')->willReturn(3);
		$file->method('getContent')->willReturn('png');
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getById')->with(48213)->willReturn([$file]);

		self::assertSame('data:image/png;base64,'.base64_encode('png'), $this->sources($root)->logoDataUri('48213'));

	}//end testTheLogoIsEmbeddedAsData()

	/**
	 * No logo for a bad id, a folder, an unsafe type or an oversized file.
	 *
	 * @return void
	 */
	public function testNoLogoForWhatCannotBeEmbedded(): void {
		$html = $this->createMock(File::class);
		$html->method('getMimeType')->willReturn('text/html');
		$big = $this->createMock(File::class);
		$big->method('getMimeType')->willReturn('image/png');
		$big->method('getSize')->willReturn(2000000);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getById')->willReturnMap([[1, [$html]], [2, [$big]], [3, [$this->createMock(Folder::class)]], [4, []]]);

		$sources = $this->sources($root);
		foreach (['', 'abc', '1', '2', '3', '4'] as $id) {
			self::assertNull($sources->logoDataUri($id), 'file id '.$id);
		}

	}//end testNoLogoForWhatCannotBeEmbedded()
}//end class
