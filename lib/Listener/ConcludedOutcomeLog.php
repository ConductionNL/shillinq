<?php

/**
 * Concluded Outcome Log
 *
 * The log lines of a concluded-outcome listener: the listener's name prefixes
 * every message and the cross-app reference is logged under the listener's own
 * reference key.
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

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Log lines shared by the concluded-outcome listeners.
 *
 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
 */
final class ConcludedOutcomeLog {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger   Logger.
	 * @param string          $listener The listener name that prefixes every message.
	 * @param string          $refKey   The context key the cross-app reference is logged under.
	 * @param string          $idKey    The payload key that holds the cross-app reference.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly string $listener,
		private readonly string $refKey,
		private readonly string $idKey,
	) {
	}//end __construct()

	/**
	 * Log a projection that failed; the listener swallows the error.
	 *
	 * @param Throwable $error The error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function projectionFailed(Throwable $error): void {
		$this->logger->warning(
			$this->listener.': projection failed (fail-soft)',
			['exception' => $error->getMessage()]
		);

	}//end projectionFailed()

	/**
	 * Log a concluded request that carries no subject id.
	 *
	 * @param array<string,string> $payload The normalised payload.
	 * @param string               $request What the concluded request is called in the message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function noSubjectId(array $payload, string $request): void {
		$this->logger->info(
			$this->listener.': no subject id on concluded '.$request.' (skipping)',
			[$this->refKey => $payload[$this->idKey]]
		);

	}//end noSubjectId()

	/**
	 * Log a subject id that matches no finance object.
	 *
	 * @param array<string,string> $payload   The normalised payload.
	 * @param string               $subjectId The resolved subject id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function noMatch(array $payload, string $subjectId): void {
		$this->logger->info(
			$this->listener.': no matching finance object (skipping)',
			[$this->refKey => $payload[$this->idKey], 'subjectId' => $subjectId]
		);

	}//end noMatch()

	/**
	 * Log a consumed outcome.
	 *
	 * @param FoundSubject         $found      The finance object.
	 * @param array<string,string> $conclusion The payload plus the mapped outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function consumed(FoundSubject $found, array $conclusion): void {
		$this->logger->info(
			$this->listener.': outcome consumed',
			[
				'schema' => $found->ref->schema,
				'subjectId' => $found->ref->id,
				'outcome' => $conclusion['outcome'],
				$this->refKey => $conclusion[$this->idKey],
			]
		);

	}//end consumed()

	/**
	 * Log an applied accounting consequence.
	 *
	 * @param string $schema  The finance schema.
	 * @param string $outcome The completing outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-signing-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function consequenceApplied(string $schema, string $outcome): void {
		$this->logger->info(
			$this->listener.': accounting consequence applied',
			[
				'schema' => $schema,
				'outcome' => $outcome,
			]
		);

	}//end consequenceApplied()
}//end class
