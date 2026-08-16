<?php

/**
 * Supporting Document Guard
 *
 * ADR-031 exception-path lifecycle guard for the SupportingDocument register
 * (bookkeeping-single-audit-eu-fondsen, T3 regulatory + compliance):
 *
 *  - canCertify(): a bewijsstuk may only be gewaarmerkt once it carries a
 *                  well-formed SHA-256 hash (64 hex chars). The hash is the
 *                  integrity anchor relied upon at 5+ year audit-reconstructie
 *                  (REQ-EUF-004, REQ-EUF-009).
 *
 * ADR-031 exception reason: the hash-format precondition is a value-shape
 * validation the declarative `requires:` clause cannot yet express. When the
 * engine gains pattern conditions, replace this with a declarative condition
 * and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for the SupportingDocument certify transition.
 *
 * Referenced from the SupportingDocument schema (register.d fragment)
 * x-openregister-lifecycle transitions.certify.requires as
 * OCA\Shillinq\Lifecycle\SupportingDocumentGuard::canCertify.
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */
class SupportingDocumentGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the bewijsstuk carries a well-formed SHA-256 hash.
	 *
	 * REQ-EUF-004 / REQ-EUF-009: certification requires the integrity anchor
	 * (64 lowercase/uppercase hex characters) to be present.
	 *
	 * Fail-closed: returns false on any exception or malformed input
	 * (REQ-EUF-004 / CWE-863).
	 *
	 * @param string $supportingDocumentId The SupportingDocument.id (call-signature parity).
	 * @param array<string,mixed>|null $object The SupportingDocument object being transitioned.
	 *
	 * @return bool True when the document may be certified.
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function canCertify(string $supportingDocumentId, ?array $object = null): bool {
		try {
			$document = $this->resolveDocument(supportingDocumentId: $supportingDocumentId, object: $object);
			if ($document === null) {
				return false;
			}

			$hash = trim((string)($document['sha256Hash'] ?? ''));
			return preg_match('/^[0-9a-fA-F]{64}$/', $hash) === 1;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SupportingDocumentGuard: certify check failed — denying certify transition (fail-closed)',
				['supportingDocumentId' => $supportingDocumentId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canCertify()

	/**
	 * Resolve the SupportingDocument object, preferring the supplied object and
	 * falling back to an ObjectService lookup by id.
	 *
	 * @param string $supportingDocumentId The SupportingDocument.id.
	 * @param array<string,mixed>|null $object The in-flight object, if provided.
	 *
	 * @return array<string,mixed>|null The document, or null when unresolved.
	 */
	private function resolveDocument(string $supportingDocumentId, ?array $object): ?array {
		if ($object !== null) {
			return $object;
		}

		if ($supportingDocumentId === '') {
			return null;
		}

		$documents = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('SupportingDocument')
			->findAll(['filters' => ['id' => $supportingDocumentId], 'limit' => 1]);

		foreach ($documents as $document) {
			if (is_array($document) === true) {
				return $document;
			}
		}

		return null;
	}//end resolveDocument()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
