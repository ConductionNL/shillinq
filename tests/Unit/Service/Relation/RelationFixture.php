<?php

/**
 * The Drukkerij Van Wijk B.V. register the relation tests share
 * (reporting-relation-both-sides seed data).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Relation;

use OCA\Shillinq\Service\Relation\RelationRecords;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;

/**
 * Seed rows and a RelationRecords over them.
 */
final class RelationFixture {

	public const ADM = 'adm-van-wijk';

	/**
	 * The register: Reclamebureau Zuid linked on KvK, Transport Noord suggestable on VAT, Drukwerk Oost unlinked.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Rows per schema.
	 */
	public static function register(): array {
		$adm = self::ADM;

		return [
			'CustomerMaster' => [
				['id' => 'c-zuid', 'administrationId' => $adm, 'customerId' => 'DEB-0101', 'legalName' => 'Reclamebureau Zuid B.V.', 'kvkNumber' => '90000001', 'payeeId' => 'p-zuid', 'payeeLink' => ['matchedOn' => 'kvk', 'confirmedBy' => 'petra', 'confirmedAt' => '2026-03-01T10:00:00+00:00']],
				['id' => 'c-noord', 'administrationId' => $adm, 'customerId' => 'DEB-0102', 'legalName' => 'Transport Noord B.V.', 'email' => 'administratie@transportnoord.example', 'vatID' => 'NL 8123.45.678.B01'],
				['id' => 'c-oost', 'administrationId' => $adm, 'customerId' => 'DEB-0103', 'legalName' => 'Drukwerk Oost B.V.', 'kvkNumber' => '90000003'],
				['id' => 'c-elsewhere', 'administrationId' => 'adm-other', 'legalName' => 'Elders B.V.', 'kvkNumber' => '90000001'],
			],
			'Payee' => [
				['id' => 'p-zuid', 'administrationId' => $adm, 'name' => 'Reclamebureau Zuid B.V.', 'kvkNumber' => '90000001'],
				['id' => 'p-noord', 'administrationId' => $adm, 'name' => 'Transport Noord B.V.', 'vatNumber' => 'nl812345678b01'],
				['id' => 'p-west', 'administrationId' => $adm, 'name' => 'Papier West B.V.', 'kvkNumber' => '90000009'],
			],
			'ARInvoice' => [
				['id' => 'ar-0310', 'administrationId' => $adm, 'customerId' => 'c-zuid', 'invoiceNumber' => '2026-0310', 'invoiceDate' => '2026-03-10', 'lifecycleState' => 'paid', 'grossAmount' => 4235.0, 'invoiceType' => 'standard'],
				['id' => 'ar-0355', 'administrationId' => $adm, 'customerId' => 'DEB-0101', 'invoiceNumber' => '2026-0355', 'invoiceDate' => '2026-04-02', 'lifecycleState' => 'issued', 'grossAmount' => 1210.0, 'amountDue' => 1210.0, 'invoiceType' => 'standard'],
				['id' => 'ar-draft', 'administrationId' => $adm, 'customerId' => 'c-zuid', 'invoiceNumber' => '', 'invoiceDate' => '2026-05-01', 'lifecycleState' => 'draft', 'grossAmount' => 999.0],
				['id' => 'ar-2025', 'administrationId' => $adm, 'customerId' => 'c-zuid', 'invoiceNumber' => '2025-0900', 'invoiceDate' => '2025-12-01', 'lifecycleState' => 'paid', 'grossAmount' => 500.0],
			],
			'APTransaction' => [
				['id' => 'ap-118', 'administrationId' => $adm, 'vendorId' => 'p-zuid', 'invoiceNumber' => 'INK-2026-118', 'invoiceDate' => '2026-04-15', 'state' => 'issued', 'totalAmount' => 2662.0],
				['id' => 'ap-void', 'administrationId' => $adm, 'vendorId' => 'p-zuid', 'invoiceNumber' => 'INK-2026-119', 'invoiceDate' => '2026-04-16', 'state' => 'voided', 'totalAmount' => 100.0],
			],
			'SupplierInvoice' => [
				['id' => 'si-handed', 'administrationId' => $adm, 'supplierId' => 'p-zuid', 'invoiceNumber' => 'INK-2026-118', 'invoiceDate' => '2026-04-15', 'statusCode' => 'approved', 'totalInclVat' => 2662.0, 'apTransactionId' => 'ap-118'],
			],
		];

	}//end register()

	/**
	 * RelationRecords over an in-memory register.
	 *
	 * @param TestCase                                             $test The test, for its mocks.
	 * @param array<string, array<int, array<string, mixed>>>|null $data Rows per schema; the seed when null.
	 * @param array<int, mixed>|null                               $sink Receives every saved object.
	 *
	 * @return RelationRecords The records.
	 */
	public static function records(TestCase $test, ?array $data = null, ?array &$sink = null): RelationRecords {
		$settings = $test->getMockBuilder(SettingsService::class)->disableOriginalConstructor()->getMock();
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$sink ??= [];

		return new RelationRecords(new InMemoryObjectServiceStub($data ?? self::register(), $sink), $settings);

	}//end records()
}//end class
