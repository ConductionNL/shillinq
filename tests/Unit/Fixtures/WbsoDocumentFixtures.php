<?php

/**
 * WBSO Document fixtures.
 *
 * Three canonical bookkeeping documents (one in each lifecycle state) used
 * by the unit tests covering REQ-WBSO-003 / REQ-WBSO-007 / REQ-WBSO-009.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-39
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Fixtures;

/**
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-39
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
final class WbsoDocumentFixtures {

	public const SAMPLE_ADMINISTRATION = 'adm-consultancy-nl';

	/**
	 * Three documents covering draft / filed / archived.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function documents(): array {
		return [
			[
				'id' => 'doc-fixture-inv-1',
				'documentType' => 'invoice',
				'documentNumber' => 'DOC-INV-2026-001',
				'documentDate' => '2026-01-15',
				'status' => 'filed',
				'fileReference' => 'docudesk://invoices/inv-2026-001.pdf',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'createdBy' => 'system',
				'filedAt' => '2026-01-15T10:00:00+00:00',
			],
			[
				'id' => 'doc-fixture-rec-1',
				'documentType' => 'receipt',
				'documentNumber' => 'DOC-REC-2026-001',
				'documentDate' => '2026-01-20',
				'status' => 'draft',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'createdBy' => 'system',
			],
			[
				'id' => 'doc-fixture-tax-1',
				'documentType' => 'tax-form',
				'documentNumber' => 'FORM-IB-2019',
				'documentDate' => '2019-04-30',
				'status' => 'archived',
				'fileReference' => 'docudesk://tax/form-ib-2019.pdf',
				'administrationId' => self::SAMPLE_ADMINISTRATION,
				'createdBy' => 'system',
				'filedAt' => '2019-05-01T09:00:00+00:00',
				'archivedAt' => '2026-05-01T09:00:00+00:00',
				'archivalReason' => 'Automatic 7-year archival (fixture).',
			],
		];

	}//end documents()
}//end class
