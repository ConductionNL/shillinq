<?php

/**
 * RGS Register Reads
 *
 * The two reads the RGS-decentraal mapping backfill makes against the
 * shillinq register: the administration's Accounts, and the RGS-decentraal
 * candidates (RgsDecentraalRekening, falling back to BbvAccountMapping when
 * that schema is not declared).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\BbvMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\BbvMigration;

/**
 * Register reads of the RGS-decentraal mapping backfill.
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 */
final class RgsRegisterReads {

	/**
	 * Hold the object service and the register slug.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $registerSlug  The shillinq register slug.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $registerSlug,
	) {
	}//end __construct()

	/**
	 * The administration's Accounts (at most 5000).
	 *
	 * @param string $administrationId Administration identifier.
	 *
	 * @return array<int, mixed> The Account rows.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function accounts(string $administrationId): array {
		return $this->objectService
			->setRegister($this->registerSlug)
			->setSchema('Account')
			->findAll(
				[
					'filters' => ['administrationId' => $administrationId],
					'limit' => 5000,
				]
			);
	}//end accounts()

	/**
	 * The RGS-decentraal candidates (at most 5000). When the
	 * RgsDecentraalRekening read fails, the BbvAccountMapping register is
	 * read instead; a failure of that read propagates.
	 *
	 * @return array<int, mixed> The candidate rows.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function candidates(): array {
		try {
			return $this->all(schema: 'RgsDecentraalRekening');
		} catch (\Throwable $e) {
			// Fall back to the BbvAccountMapping register if RgsDecentraalRekening is not yet declared.
			return $this->all(schema: 'BbvAccountMapping');
		}
	}//end candidates()

	/**
	 * Every row of a schema (at most 5000).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function all(string $schema): array {
		return $this->objectService
			->setRegister($this->registerSlug)
			->setSchema($schema)
			->findAll(['limit' => 5000]);
	}//end all()
}//end class
