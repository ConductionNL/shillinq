<?php

/**
 * WBSO administration scope
 *
 * Resolves the administration a WBSO v1 API request works in, and refuses one
 * the caller holds no membership for.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IRequest;

/**
 * The administration a WBSO API request works in.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 */
final class WbsoAdministrationScope {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request naming the administration.
	 * @param AdministrationContextService $administrationContext The caller's memberships.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly AdministrationContextService $administrationContext,
	) {
	}//end __construct()

	/**
	 * The administration the request works in.
	 *
	 * The request's `administration_id`, when the caller holds a membership for
	 * it; without one, the caller's first administration. Null when it is
	 * malformed, foreign, or the caller has none.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	public function resolve(): ?string {
		$value = trim((string)$this->request->getParam('administration_id', ''));
		if ($value === '') {
			return ($this->administrationContext->accessibleAdministrationIds()[0] ?? null);
		}

		if (preg_match('/^[A-Za-z0-9_.\\-]{1,64}$/', $value) !== 1) {
			return null;
		}

		if ($this->administrationContext->canAccess(administrationId: $value) === false) {
			return null;
		}

		return $value;

	}//end resolve()

	/**
	 * Whether the caller may book journal entries in the administration.
	 *
	 * @param string $administrationId The resolved administration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
	 */
	public function canPost(string $administrationId): bool {
		return $this->administrationContext->canPostJournalEntry(administrationId: $administrationId);

	}//end canPost()
}//end class
