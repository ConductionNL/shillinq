<?php

/**
 * SearchOptions stub mirroring OCA\OpenRegister\Kernel\Contracts\SearchOptions (openregister #9103).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Stub for OCA\OpenRegister\Kernel\Contracts\SearchOptions used by shillinq tests.
 */
final class SearchOptions {
	/**
	 * Constructor.
	 *
	 * @param ObjectSelection $selection         Ids and uses restriction.
	 * @param array|null      $views             Views to search through.
	 * @param bool            $viewScopeRequired Whether a view scope is required.
	 */
	public function __construct(
		public readonly ObjectSelection $selection=new ObjectSelection(),
		public readonly ?array $views=null,
		public readonly bool $viewScopeRequired=false,
	) {
	}//end __construct()
}//end class
