<?php

/**
 * Evidence-attachment retention enforcer.
 *
 * REQ-CCD-002 / REQ-CCD-004 / REQ-CCD-010 + task-25. Every evidenceRef on a
 * `DunningRun`, `DunningPauseDispute`, and `OninbaarAfschrijving` must be
 * archivable per the `bookkeeping-document-attachment-integration` contract
 * (docudesk / openregister / postnl URIs) for the 7-year retention window
 * required by art. 6:96 BW + Wki + Wsnp.
 *
 * This enforcer is the shillinq-side gatekeeper:
 *   - validateEvidenceRefs(): rejects malformed URIs (`assert*` style — fails
 *     closed so a wrong shape never silently logs as compliant).
 *   - retentionPolicy(): returns the canonical retention envelope for the
 *     UI / repair-step to surface (years, deletionDate, sourceUri).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-25
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Enforces the per-evidence-ref URI contract + the 7-year retention window.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-25
 */
final class EvidenceRetentionEnforcer {
	/**
	 * Mandatory retention window for credit-control evidence (art. 6:96 BW + Wki/Wsnp).
	 */
	public const RETENTION_YEARS = 7;

	/**
	 * Allowed scheme prefixes for an evidenceRef URI, per the shared
	 * `bookkeeping-document-attachment-integration` contract.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_SCHEMES = [
		'docudesk:',
		'openregister:',
		'postnl:',
		'dunning-run:',
	];

	/**
	 * Validate a single evidence URI; throws on malformed input (fail-closed).
	 *
	 * @param string $uri Candidate URI.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URI does not match an allowed scheme.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-25
	 */
	public function assertEvidenceUri(string $uri): void {
		$trimmed = trim($uri);
		if ($trimmed === '') {
			throw new InvalidArgumentException('Evidence URI must not be empty.');
		}

		foreach (self::ALLOWED_SCHEMES as $scheme) {
			if (str_starts_with($trimmed, $scheme) === true) {
				$remainder = substr($trimmed, strlen($scheme));
				if ($remainder === '') {
					throw new InvalidArgumentException(
						sprintf('Evidence URI %s missing locator after scheme.', $trimmed)
					);
				}

				return;
			}
		}

		throw new InvalidArgumentException(
			sprintf(
				'Evidence URI %s does not match any allowed scheme (%s).',
				$trimmed,
				implode(', ', self::ALLOWED_SCHEMES)
			)
		);

	}//end assertEvidenceUri()

	/**
	 * Validate an entire evidenceRefs array; collects all violations.
	 *
	 * @param array<int,mixed> $uris Candidate URIs.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When any URI is malformed (message lists all).
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-25
	 */
	public function validateEvidenceRefs(array $uris): void {
		$errors = [];
		foreach ($uris as $idx => $uri) {
			if (is_string($uri) === false) {
				$errors[] = sprintf('evidenceRefs[%d] is not a string.', (int)$idx);
				continue;
			}

			try {
				$this->assertEvidenceUri(uri: $uri);
			} catch (InvalidArgumentException $e) {
				$errors[] = sprintf('evidenceRefs[%d]: %s', (int)$idx, $e->getMessage());
			}
		}

		if ($errors !== []) {
			throw new InvalidArgumentException(implode(' | ', $errors));
		}

	}//end validateEvidenceRefs()

	/**
	 * Return the canonical retention policy envelope for a given evidence URI.
	 *
	 * @param string $uri The evidence URI (already validated).
	 * @param DateTimeImmutable|null $issuedAt The date the evidence was archived (defaults to now).
	 *
	 * @return array{retentionYears:int,deletionDate:string,sourceUri:string}
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-25
	 */
	public function retentionPolicy(string $uri, ?DateTimeImmutable $issuedAt = null): array {
		$this->assertEvidenceUri(uri: $uri);
		$issuedAt = ($issuedAt ?? new DateTimeImmutable());
		$deletion = $issuedAt->modify('+' . self::RETENTION_YEARS . ' years');

		return [
			'retentionYears' => self::RETENTION_YEARS,
			'deletionDate' => $deletion->format('Y-m-d'),
			'sourceUri' => $uri,
		];

	}//end retentionPolicy()
}//end class
