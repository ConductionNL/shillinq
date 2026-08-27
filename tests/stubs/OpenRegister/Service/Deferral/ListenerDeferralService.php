<?php

/**
 * Minimal ListenerDeferralService stub for unit tests.
 *
 * Mirrors the two methods shillinq calls on OpenRegister's real service
 * (`isDeferralEnabled()` and `defer()`), and records what was deferred so a
 * test can assert the promotion left the caller's write path instead of
 * running on it.
 *
 * The `defer()` signature is kept identical to upstream — including the
 * `$chunkSize` default that shillinq skips via named arguments — so a
 * signature change upstream shows up here as a test failure rather than as a
 * stub that silently accepts a call production would reject.
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
 * Stub for OCA\OpenRegister\Service\Deferral\ListenerDeferralService.
 */
class ListenerDeferralService {

	/**
	 * Default chunk size, mirroring upstream.
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_SIZE = 100;

	/**
	 * Recorded defer() calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $deferred = [];

	/**
	 * Construct the stub.
	 *
	 * @param bool $enabled What isDeferralEnabled() should report.
	 */
	public function __construct(
		private readonly bool $enabled = true,
	) {

	}//end __construct()

	/**
	 * Report whether deferral is on.
	 *
	 * @return bool
	 */
	public function isDeferralEnabled(): bool {
		return $this->enabled;

	}//end isDeferralEnabled()

	/**
	 * Record a deferred entry.
	 *
	 * @param string      $jobClass  Job to run the entry.
	 * @param array       $entry     Buffered payload.
	 * @param int         $chunkSize Flush threshold.
	 * @param string|null $dedupeKey Collapse key.
	 *
	 * @return void
	 */
	public function defer(
		string $jobClass,
		array $entry,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		?string $dedupeKey = null,
	): void {
		$this->deferred[] = [
			'jobClass'  => $jobClass,
			'entry'     => $entry,
			'chunkSize' => $chunkSize,
			'dedupeKey' => $dedupeKey,
		];

	}//end defer()
}//end class
