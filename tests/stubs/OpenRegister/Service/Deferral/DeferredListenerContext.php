<?php

/**
 * Minimal DeferredListenerContext stub for unit tests and static analysis.
 *
 * Mirrors the upstream constructor and accessors so a deferred job can be
 * exercised without the openregister app present.
 *
 * NOTE: upstream declares this class `final`. The stub does too — a stub that
 * relaxed it would let a test subclass something production cannot.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

/**
 * Stub for OCA\OpenRegister\Service\Deferral\DeferredListenerContext.
 */
final class DeferredListenerContext {

	/**
	 * Construct the context.
	 *
	 * @param string|null                      $userId  Acting user.
	 * @param string|null                      $orgUuid Acting organisation.
	 * @param array<int, array<string, mixed>> $entries Buffered entries.
	 */
	public function __construct(
		private readonly ?string $userId,
		private readonly ?string $orgUuid,
		private readonly array $entries,
	) {

	}//end __construct()

	/**
	 * Return the acting user id.
	 *
	 * @return string|null
	 */
	public function getUserId(): ?string {
		return $this->userId;

	}//end getUserId()

	/**
	 * Return the acting organisation uuid.
	 *
	 * @return string|null
	 */
	public function getOrganisationUuid(): ?string {
		return $this->orgUuid;

	}//end getOrganisationUuid()

	/**
	 * Return the buffered entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getEntries(): array {
		return $this->entries;

	}//end getEntries()

	/**
	 * Serialise back to job arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function toJobArguments(): array {
		return [
			'userId'  => $this->userId,
			'orgUuid' => $this->orgUuid,
			'entries' => $this->entries,
		];

	}//end toJobArguments()
}//end class
