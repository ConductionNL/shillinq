<?php

/**
 * SEPA Audit Service
 *
 * Assembles the per-mandate audit dossier (REQ-SDD-010): the mandate, its
 * collections, R-transactions, pre-notifications, and archived pain.008 /
 * pain.002 XML, zipped for the 7-year bewaarplicht. The mandate is scoped to the
 * CALLER's administration memberships so a caller cannot export another tenant's
 * mandate (IDOR-safe per ADR-005 / REQ-MA-001).
 *
 * ⚠️ This paragraph used to say "scoped to the administration configured for the
 * app". That is a different thing and it is not access control: the config key
 * `administration_id` is instance-wide, has no relation to the calling user, and
 * defaults to '' — at which value the old guard was skipped entirely.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use ZipArchive;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Builds the SEPA mandate audit dossier ZIP (REQ-SDD-010).
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class SepaAuditService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $context RBAC guard — the caller's administration memberships.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build the audit dossier for one mandate, scoped to the CALLER's administrations.
	 *
	 * Returns null when the mandate does not exist or belongs to an
	 * administration the caller has no membership for (treated as not-found to
	 * avoid leaking existence; IDOR-safe per ADR-005 / REQ-MA-001).
	 *
	 * ⚠️ This used to read "outside the CONFIGURED administration", and that is
	 * exactly what it did: `resolveAdministration()` returned
	 * `appConfig->getValueString(APP_ID, 'administration_id', '')` — an
	 * instance-wide constant with no relation to the calling user — and the
	 * `$administration !== ''` short-circuit made the guard a complete no-op
	 * whenever that key was unset, which is its default.
	 *
	 * @param string $mandateId The SepaMandate UUID/slug.
	 *
	 * @return array{data:string,filename:string}|null The ZIP payload + filename, or null.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function buildMandateDossier(string $mandateId): ?array {
		if ($mandateId === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$mandate = $this->findOne(
			objectService: $this->objectService,
			register: $register,
			schema: 'SepaMandate',
			filters: ['id' => $mandateId]
		);

		if ($mandate === null) {
			return null;
		}

		// Tenant guard: refuse mandates outside the CALLER's administrations.
		// canAccess() fails closed on '' — a mandate with no administrationId is
		// refused rather than exported (AdministrationContextService:220).
		if ($this->context->canAccess(administrationId: (string)($mandate['administrationId'] ?? '')) === false) {
			return null;
		}

		$collections = $this->findMany(
			objectService: $this->objectService,
			register: $register,
			schema: 'DirectDebitCollection',
			filters: ['mandateId' => $mandateId]
		);

		$collectionIds = [];
		foreach ($collections as $collection) {
			if (is_array($collection) === true && isset($collection['id']) === true) {
				$collectionIds[] = (string)$collection['id'];
			}
		}

		$rTransactions = $this->findRelated(
			objectService: $this->objectService,
			register: $register,
			schema: 'RTransaction',
			field: 'collectionId',
			ids: $collectionIds
		);

		$preNotifications = $this->findRelated(
			objectService: $this->objectService,
			register: $register,
			schema: 'PreNotification',
			field: 'collectionId',
			ids: $collectionIds
		);

		$zip = $this->assembleZip(
			mandate: $mandate,
			collections: $collections,
			rTransactions: $rTransactions,
			preNotifications: $preNotifications
		);

		if ($zip === null) {
			$this->logger->error(
				'SepaAuditService: failed to assemble audit dossier ZIP',
				['mandateId' => $mandateId]
			);
			return null;
		}

		$reference = (string)($mandate['mandateReference'] ?? $mandateId);
		$safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $reference) ?? 'mandate';

		return [
			'data' => $zip,
			'filename' => 'sepa-dossier-' . $safe . '.zip',
		];
	}//end buildMandateDossier()

	/**
	 * Assemble the dossier ZIP from the gathered records.
	 *
	 * @param array<string,mixed> $mandate The mandate.
	 * @param array<int,mixed> $collections Its collections (may contain non-array store rows).
	 * @param array<int,mixed> $rTransactions Related R-transactions.
	 * @param array<int,mixed> $preNotifications Related pre-notifications.
	 *
	 * @return string|null Raw ZIP bytes, or null on failure.
	 */
	private function assembleZip(
		array $mandate,
		array $collections,
		array $rTransactions,
		array $preNotifications,
	): ?string {
		$tmp = tempnam(sys_get_temp_dir(), 'sepa-dossier-');
		if ($tmp === false) {
			return null;
		}

		try {
			$zip = new ZipArchive();
			if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
				return null;
			}

			$zip->addFromString('mandate.json', $this->encode(value: $mandate));
			$zip->addFromString('collections.csv', $this->collectionsCsv(collections: $collections));
			$zip->addFromString('collections.json', $this->encode(value: $collections));
			$zip->addFromString('r-transactions.json', $this->encode(value: $rTransactions));
			$zip->addFromString('pre-notifications.json', $this->encode(value: $preNotifications));

			// Archived pain fragments per batch referenced by the collections.
			foreach ($collections as $collection) {
				if (is_array($collection) === false) {
					continue;
				}

				$pain008 = (string)($collection['_pain008Xml'] ?? '');
				if ($pain008 !== '') {
					$end = (string)($collection['endToEndId'] ?? 'collection');
					$zip->addFromString('pain/' . $this->slug(value: $end) . '.pain008.xml', $pain008);
				}
			}

			$zip->close();

			$bytes = file_get_contents($tmp);
			if ($bytes === false) {
				return null;
			}

			return $bytes;
		} finally {
			if (file_exists($tmp) === true) {
				unlink($tmp);
			}
		}//end try
	}//end assembleZip()

	/**
	 * Render the collections as a CSV with status, amount, dates and reason codes.
	 *
	 * @param array<int,mixed> $collections The collections (may contain non-array store rows).
	 *
	 * @return string The CSV text.
	 */
	private function collectionsCsv(array $collections): string {
		$rows = [];
		$rows[] = 'endToEndId,amount,currency,sequenceType,requestedCollectionDate,status,pain002ReasonCode';
		foreach ($collections as $collection) {
			if (is_array($collection) === false) {
				continue;
			}

			$rows[] = implode(
				',',
				[
					$this->csvCell(value: (string)($collection['endToEndId'] ?? '')),
					$this->csvCell(value: (string)($collection['amount'] ?? '')),
					$this->csvCell(value: (string)($collection['currency'] ?? '')),
					$this->csvCell(value: (string)($collection['sequenceType'] ?? '')),
					$this->csvCell(value: (string)($collection['requestedCollectionDate'] ?? '')),
					$this->csvCell(value: (string)($collection['status'] ?? '')),
					$this->csvCell(value: (string)($collection['pain002ReasonCode'] ?? '')),
				]
			);
		}

		return implode("\n", $rows) . "\n";
	}//end collectionsCsv()

	/**
	 * Escape a CSV cell (quote when it contains a comma, quote or newline).
	 *
	 * @param string $value The raw cell value.
	 *
	 * @return string The escaped cell.
	 */
	private function csvCell(string $value): string {
		if (preg_match('/[",\n]/', $value) === 1) {
			return '"' . str_replace('"', '""', $value) . '"';
		}

		return $value;
	}//end csvCell()

	/**
	 * JSON-encode a value for inclusion in the dossier.
	 *
	 * @param mixed $value The value to encode.
	 *
	 * @return string Pretty-printed JSON.
	 */
	private function encode(mixed $value): string {
		$json = json_encode($value, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($json === false) {
			return '{}';
		}

		return $json;
	}//end encode()

	/**
	 * Filesystem-safe slug for a free-form identifier.
	 *
	 * @param string $value The raw identifier.
	 *
	 * @return string The slug.
	 */
	private function slug(string $value): string {
		return preg_replace('/[^A-Za-z0-9_-]/', '_', $value) ?? 'item';
	}//end slug()

	/**
	 * Find a single record matching filters, or null.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The query filters.
	 *
	 * @return array<string,mixed>|null The first matching record, or null.
	 */
	private function findOne(object $objectService, string $register, string $schema, array $filters): ?array {
		$records = $this->findMany(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: $filters
		);

		foreach ($records as $record) {
			if (is_array($record) === true) {
				return $record;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Find all records matching filters.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The query filters.
	 *
	 * @return array<int,mixed> The matching records.
	 */
	private function findMany(object $objectService, string $register, string $schema, array $filters): array {
		$result = $objectService
			->setRegister($register)
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		if (is_array($result) === true) {
			return array_values($result);
		}

		return [];
	}//end findMany()

	/**
	 * Find records of a schema whose `field` is in the given id set.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $field The FK field name.
	 * @param array<int,string> $ids The id set.
	 *
	 * @return array<int,array<string,mixed>> The matching records.
	 */
	private function findRelated(object $objectService, string $register, string $schema, string $field, array $ids): array {
		if ($ids === []) {
			return [];
		}

		$out = [];
		foreach ($ids as $id) {
			foreach ($this->findMany(objectService: $objectService, register: $register, schema: $schema, filters: [$field => $id]) as $record) {
				if (is_array($record) === true) {
					$out[] = $record;
				}
			}
		}

		return $out;
	}//end findRelated()

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
