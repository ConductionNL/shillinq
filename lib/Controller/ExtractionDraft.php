<?php

/**
 * Extraction Draft
 *
 * The schema and id of the extraction draft a request targets.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * An extraction draft reference: its schema and id.
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 */
final class ExtractionDraft {
	/**
	 * The draft's schema.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * The draft's id.
	 *
	 * @var string
	 */
	public readonly string $id;

	/**
	 * Name a draft.
	 *
	 * @param string $schema The draft's schema.
	 * @param string $id     The draft's id.
	 */
	public function __construct(string $schema, string $id) {
		$this->schema = $schema;
		$this->id = $id;

	}//end __construct()
}//end class
