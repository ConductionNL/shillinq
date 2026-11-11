<?php

/**
 * Stored Secret
 *
 * One app-wide secret in Nextcloud's secrets store (ICredentialsManager,
 * encrypted at rest, ADR-005): read back as a string, written trimmed, and
 * deleted when written blank. The decidesk webhook token and the pipelinq API
 * token are both kept this way. The value is never logged.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\Security\ICredentialsManager;

/**
 * Reads and writes one app-wide secret (stored for no particular user).
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 */
final class StoredSecret {
	/**
	 * Constructor.
	 *
	 * @param ICredentialsManager $credentialsManager The secrets store.
	 * @param string              $identifier         The secret's identifier in the store.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ICredentialsManager $credentialsManager,
		private readonly string $identifier,
	) {

	}//end __construct()

	/**
	 * The stored secret, or an empty string when none (or no string) is stored.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function read(): string {
		$secret = $this->credentialsManager->retrieve(
			userId: '',
			identifier: $this->identifier
		);
		if (is_string($secret) === false || $secret === '') {
			return '';
		}

		return $secret;
	}//end read()

	/**
	 * Store the trimmed value; a blank value deletes the secret.
	 *
	 * @param string $value The secret, or '' to clear.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function write(string $value): void {
		$trimmed = trim($value);
		if ($trimmed === '') {
			$this->credentialsManager->delete(
				userId: '',
				identifier: $this->identifier
			);
			return;
		}

		$this->credentialsManager->store(
			userId: '',
			identifier: $this->identifier,
			credentials: $trimmed
		);

	}//end write()
}//end class
