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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\SepaAuditServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Builds the SEPA mandate audit dossier ZIP (REQ-SDD-010).
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class SepaAuditService implements SepaAuditServiceInterface {

	/**
	 * The collections CSV columns, in order.
	 *
	 * @var list<string>
	 */
	private const CSV_COLUMNS = ['endToEndId', 'amount', 'currency', 'sequenceType', 'requestedCollectionDate', 'status', 'pain002ReasonCode'];
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

		$dossier = $this->accessibleDossier(mandateId: $mandateId);
		if ($dossier === null) {
			return null;
		}

		$this->gatherRelated(dossier: $dossier);
		$zip = $this->zipOf(dossier: $dossier);
		if ($zip === null) {
			return null;
		}

		return ['data' => $zip, 'filename' => $this->dossierFilename(dossier: $dossier)];
	}//end buildMandateDossier()

	/**
	 * The dossier ZIP, logging when it cannot be assembled.
	 *
	 * @param MandateDossier $dossier The gathered records.
	 *
	 * @return string|null Raw ZIP bytes, or null on failure.
	 */
	private function zipOf(MandateDossier $dossier): ?string {
		$zip = $this->assembleZip(dossier: $dossier);
		if ($zip === null) {
			$this->logger->error('SepaAuditService: failed to assemble audit dossier ZIP', ['mandateId' => $dossier->mandateId]);
		}

		return $zip;
	}//end zipOf()

	/**
	 * Load the mandate, or null when it does not exist or sits outside the
	 * caller's administrations.
	 *
	 * @param string $mandateId The SepaMandate UUID/slug.
	 *
	 * @return MandateDossier|null
	 */
	private function accessibleDossier(string $mandateId): ?MandateDossier {
		$mandate = $this->findOne(schema: 'SepaMandate', filters: ['id' => $mandateId]);
		if ($mandate === null) {
			return null;
		}

		// Tenant guard: refuse mandates outside the CALLER's administrations.
		// canAccess() fails closed on '' — a mandate with no administrationId is
		// refused rather than exported (AdministrationContextService:220).
		if ($this->context->canAccess(administrationId: (string)($mandate['administrationId'] ?? '')) === false) {
			return null;
		}

		$dossier = new MandateDossier();
		$dossier->mandateId = $mandateId;
		$dossier->mandate = $mandate;

		return $dossier;
	}//end accessibleDossier()

	/**
	 * Load the mandate's collections and the R-transactions and
	 * pre-notifications of those collections.
	 *
	 * @param MandateDossier $dossier The dossier being built.
	 *
	 * @return void
	 */
	private function gatherRelated(MandateDossier $dossier): void {
		$dossier->collections = $this->findMany(schema: 'DirectDebitCollection', filters: ['mandateId' => $dossier->mandateId]);
		$collectionIds = $this->collectionIds(collections: $dossier->collections);
		$dossier->rTransactions = $this->findRelated(schema: 'RTransaction', ids: $collectionIds);
		$dossier->preNotifications = $this->findRelated(schema: 'PreNotification', ids: $collectionIds);
	}//end gatherRelated()

	/**
	 * The ids of the collections that carry one.
	 *
	 * @param array<int,mixed> $collections The collections (may contain non-array store rows).
	 *
	 * @return array<int,string>
	 */
	private function collectionIds(array $collections): array {
		$collectionIds = [];
		foreach ($collections as $collection) {
			if (is_array($collection) === true && isset($collection['id']) === true) {
				$collectionIds[] = (string)$collection['id'];
			}
		}

		return $collectionIds;
	}//end collectionIds()

	/**
	 * The download filename, from the mandate reference made filesystem-safe.
	 *
	 * @param MandateDossier $dossier The dossier.
	 *
	 * @return string
	 */
	private function dossierFilename(MandateDossier $dossier): string {
		$reference = (string)($dossier->mandate['mandateReference'] ?? $dossier->mandateId);
		$safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $reference) ?? 'mandate';

		return 'sepa-dossier-' . $safe . '.zip';
	}//end dossierFilename()

	/**
	 * Assemble the dossier ZIP from the gathered records.
	 *
	 * @param MandateDossier $dossier The gathered records.
	 *
	 * @return string|null Raw ZIP bytes, or null on failure.
	 */
	private function assembleZip(MandateDossier $dossier): ?string {
		$tmp = tempnam(sys_get_temp_dir(), 'sepa-dossier-');
		if ($tmp === false) {
			return null;
		}

		try {
			return $this->writeZip(path: $tmp, dossier: $dossier);
		} finally {
			if (file_exists($tmp) === true) {
				unlink($tmp);
			}
		}
	}//end assembleZip()

	/**
	 * Write the dossier ZIP to a temporary path and read its bytes back.
	 *
	 * @param string         $path    The temporary file.
	 * @param MandateDossier $dossier The gathered records.
	 *
	 * @return string|null Raw ZIP bytes, or null on failure.
	 */
	private function writeZip(string $path, MandateDossier $dossier): ?string {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			return null;
		}

		$this->addEntries(zip: $zip, dossier: $dossier);
		$zip->close();
		$bytes = file_get_contents($path);
		if ($bytes === false) {
			return null;
		}

		return $bytes;
	}//end writeZip()

	/**
	 * Add the mandate, collections, R-transactions, pre-notifications and the
	 * archived pain fragments per batch referenced by the collections.
	 *
	 * @param ZipArchive     $zip     The open archive.
	 * @param MandateDossier $dossier The gathered records.
	 *
	 * @return void
	 */
	private function addEntries(ZipArchive $zip, MandateDossier $dossier): void {
		$zip->addFromString('mandate.json', $this->encode(value: $dossier->mandate));
		$zip->addFromString('collections.csv', $this->collectionsCsv(collections: $dossier->collections));
		$zip->addFromString('collections.json', $this->encode(value: $dossier->collections));
		$zip->addFromString('r-transactions.json', $this->encode(value: $dossier->rTransactions));
		$zip->addFromString('pre-notifications.json', $this->encode(value: $dossier->preNotifications));
		$this->addPainFragments(zip: $zip, collections: $dossier->collections);
	}//end addEntries()

	/**
	 * Add the archived pain.008 fragment of every collection that carries one.
	 *
	 * @param ZipArchive       $zip         The open archive.
	 * @param array<int,mixed> $collections The collections (may contain non-array store rows).
	 *
	 * @return void
	 */
	private function addPainFragments(ZipArchive $zip, array $collections): void {
		foreach ($collections as $collection) {
			$pain008 = '';
			if (is_array($collection) === true) {
				$pain008 = (string)($collection['_pain008Xml'] ?? '');
			}

			if ($pain008 !== '') {
				$zip->addFromString('pain/' . $this->slug(value: (string)($collection['endToEndId'] ?? 'collection')) . '.pain008.xml', $pain008);
			}
		}
	}//end addPainFragments()

	/**
	 * Render the collections as a CSV with status, amount, dates and reason codes.
	 *
	 * @param array<int,mixed> $collections The collections (may contain non-array store rows).
	 *
	 * @return string The CSV text.
	 */
	private function collectionsCsv(array $collections): string {
		$rows = [implode(',', self::CSV_COLUMNS)];
		foreach ($collections as $collection) {
			if (is_array($collection) === true) {
				$rows[] = implode(',', array_map(fn (string $column): string => $this->csvCell(value: (string)($collection[$column] ?? '')), self::CSV_COLUMNS));
			}
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
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The query filters.
	 *
	 * @return array<string,mixed>|null The first matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		foreach ($this->findMany(schema: $schema, filters: $filters) as $record) {
			if (is_array($record) === true) {
				return $record;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Find all records matching filters.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters The query filters.
	 *
	 * @return array<int,mixed> The matching records.
	 */
	private function findMany(string $schema, array $filters): array {
		return array_values(
			$this->objectService->setRegister($this->resolveRegister())->setSchema($schema)->findAll(['filters' => $filters])
		);
	}//end findMany()

	/**
	 * Find records of a schema whose `collectionId` is in the given id set.
	 *
	 * @param string $schema The schema slug.
	 * @param array<int,string> $ids The collection id set.
	 *
	 * @return array<int,array<string,mixed>> The matching records.
	 */
	private function findRelated(string $schema, array $ids): array {
		$out = [];
		foreach ($ids as $id) {
			foreach ($this->findMany(schema: $schema, filters: ['collectionId' => $id]) as $record) {
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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
