<?php

/**
 * Found Subject
 *
 * A finance object a concluded outcome points at, as found in the register:
 * where it lives and its payload.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

/**
 * A located finance object and its payload.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class FoundSubject {

	/**
	 * Hold the reference and the payload.
	 *
	 * @param SubjectRef          $ref    Where the object lives.
	 * @param array<string,mixed> $object The object payload.
	 */
	public function __construct(
		public readonly SubjectRef $ref,
		public readonly array $object,
	) {
	}//end __construct()
}//end class
