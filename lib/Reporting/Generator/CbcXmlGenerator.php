<?php

/**
 * Shillinq country-by-country XML report generator
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Service\Cbcr\CbcXmlDocument;
use OCA\Shillinq\Service\Cbcr\CbcXsdSet;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use RuntimeException;

/**
 * Answers the `cbc-report` report: the country-by-country file of one CbcrReturn.
 *
 * The context names the return (`returnId`). The file is refused while a
 * blocking check fails, naming it (REQ-CBC-012). Otherwise it is rendered,
 * then validated against the bundled OECD set before anything is stored
 * (REQ-CBC-008, design D3): a file that fails validation is not returned, and
 * its errors go to the return's `lastValidation`. A valid file writes
 * `lastValidation` with the records' fingerprint, which the submit guard
 * compares, and a `DocRefId` per record in `docRefIds`.
 *
 * A return that corrects another (`correctsReturn`) is a CBC402 message: a
 * jurisdiction whose figures or entities changed since the corrected file
 * is sent as OECD2 with `CorrDocRefId`, an unchanged one is left out, and the
 * reporting entity is resent as OECD0 when unchanged (REQ-CBC-013).
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */
final class CbcXmlGenerator implements ReportGeneratorInterface {

	/**
	 * Record keys left out of a record fingerprint: they change without the record's content changing.
	 *
	 * @var array<int,string>
	 */
	private const VOLATILE = ['@self', 'updated', 'created', 'state', 'figures', 'fixedAssetBookValue'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objects   OpenRegister's object service.
	 * @param IAppConfig             $appConfig Names the register.
	 * @param CbcrCheckRunner        $checks    The checks and the records they read.
	 * @param CbcXmlDocument         $document  Renders the file.
	 * @param CbcXsdSet              $xsd       The bundled schema set.
	 * @param IL10N                  $l10n      Translated refusals.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objects,
		private readonly IAppConfig $appConfig,
		private readonly CbcrCheckRunner $checks,
		private readonly CbcXmlDocument $document,
		private readonly CbcXsdSet $xsd,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public static function reportType(): string {
		return 'cbc-report';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public static function supportedFormats(): array {
		return ['xml'];
	}//end supportedFormats()

	/**
	 * Render, validate and return the return's CbC file.
	 *
	 * @param array<string, mixed> $context `{ returnId, period, administrationId }`.
	 * @param string               $format  Must be 'xml'.
	 *
	 * @return GeneratedFile
	 *
	 * @throws RuntimeException When the return is not named or found, a blocking check fails or the file is not valid.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public function generate(array $context, string $format): GeneratedFile {
		if ($format !== 'xml') {
			throw new RuntimeException($this->l10n->t('The country-by-country file is XML only.'));
		}

		$return = $this->record(schema: 'CbcrReturn', id: trim((string)($context['returnId'] ?? '')));
		if ($return === null) {
			throw new RuntimeException($this->l10n->t('Generate the country-by-country file from its report page.'));
		}

		$this->refuseOnBlockingChecks(return: $return);

		$records = $this->checks->cbcRecords(return: $return);
		if ($records['reportingEntity'] === null) {
			throw new RuntimeException($this->l10n->t('The report names no reporting entity.'));
		}

		$file = $this->fileParts(return: $return, records: $records);
		$xml  = $this->document->render(file: $file);

		$validation = $this->xsd->validate(xml: $xml);
		$checked    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
		if ($validation['valid'] === false) {
			$this->update(return: $return, fields: ['lastValidation' => $validation + ['validatedAt' => $checked]]);
			throw new RuntimeException($this->invalidMessage(errors: $validation['errors']));
		}

		$this->update(
			return: $return,
			fields: [
				'lastValidation' => $validation + [
					'validatedAt' => $checked,
					'fingerprint' => $this->checks->cbcFingerprint(return: $return),
					'messageRefId' => $file['messageRefId'],
					'recordFingerprints' => $file['recordFingerprints'],
				],
				'docRefIds' => $file['keptDocRefIds'],
			]
		);

		return new GeneratedFile(
			fileName: sprintf('cbc-report-%s-%s.xml', self::yearOf(return: $return), $file['messageRefId']),
			mimeType: 'application/xml',
			format: 'xml',
			content: $xml,
		);
	}//end generate()

	/**
	 * Refuse while a mandatory check fails, naming every failing check.
	 *
	 * @param array<string,mixed> $return The CbcrReturn.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a blocking check fails.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	private function refuseOnBlockingChecks(array $return): void {
		$failing = [];
		foreach ($this->checks->runCbc(return: $return) as $result) {
			if ($result['passed'] === false && $result['severity'] === 'mandatory') {
				$failing[] = $result['id'] . ': ' . $this->l10n->t($result['statement']);
			}
		}

		if ($failing !== []) {
			throw new RuntimeException(
				$this->l10n->t('The country-by-country file is not made while a blocking check fails. %s', [implode(' ', $failing)])
			);
		}
	}//end refuseOnBlockingChecks()

	/**
	 * The parts the document renders, with the record fingerprints and the DocRefIds to keep.
	 *
	 * @param array<string,mixed> $return  The CbcrReturn.
	 * @param array<string,mixed> $records The records of CbcrCheckRunner::cbcRecords().
	 *
	 * @return array<string,mixed>
	 */
	private function fileParts(array $return, array $records): array {
		$returnId  = (string)($return['id'] ?? '');
		$reporting = (array)$records['reportingEntity'];
		$upe       = $reporting;
		if ($records['upeId'] !== (string)($reporting['id'] ?? '')) {
			$upe = ($this->record(schema: 'GroupEntityRegistry', id: $records['upeId']) ?? $reporting);
		}

		$entityKey    = $this->document->reportingEntityKey(returnId: $returnId);
		$fingerprints = [$entityKey => self::fingerprint(value: [$reporting, $return['reportingRole'] ?? null, $upe['entityName'] ?? null])];
		foreach ($records['summaries'] as $summary) {
			$code = strtoupper((string)($summary['jurisdiction'] ?? ''));
			$fingerprints[(string)($summary['id'] ?? '')] = self::fingerprint(
				value: [$summary, array_values(array_filter($records['entities'], static fn (array $entity): bool => self::residenceOf(entity: $entity) === $code))]
			);
		}

		$messageRefId = self::messageRefId(return: $return, reporting: $reporting);
		$correction   = $this->correctionPlan(return: $return, fingerprints: $fingerprints);
		$docRefIds    = [];
		$sequence     = 0;
		foreach (array_keys($fingerprints) as $key) {
			$sequence++;
			$docRefIds[$key] = $messageRefId . '-' . $sequence;
		}

		$note = implode(' ', array_values(array_unique(array_filter(array_map(
			static fn (array $summary): string => trim((string)($summary['employeeMethodologyNote'] ?? '')),
			$records['summaries']
		)))));
		if ($correction !== null) {
			// A correction resends records only; the explanatory note stays with the first file.
			$note = '';
		}

		if ($note !== '') {
			$docRefIds[$this->document->additionalInfoKey(returnId: $returnId)] = $messageRefId . '-' . ($sequence + 1);
		}

		$file = [
			'return' => $return,
			'reportingEntity' => $reporting,
			'upeId' => $records['upeId'],
			'groupName' => (string)($upe['entityName'] ?? ''),
			'entities' => $records['entities'],
			'summaries' => $records['summaries'],
			'messageRefId' => $messageRefId,
			'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s'),
			'docRefIds' => $docRefIds,
			'language' => 'EN',
			'currency' => strtoupper((string)($return['currency'] ?? 'EUR')),
			'additionalInfo' => $note,
			'recordFingerprints' => $fingerprints,
			'keptDocRefIds' => $docRefIds,
		];
		if ($correction !== null) {
			$file['correction']    = $correction;
			$file['keptDocRefIds'] = self::keptDocRefIds(correction: $correction, docRefIds: $docRefIds);
		}

		return $file;
	}//end fileParts()

	/**
	 * What a correction sends: the corrected file's DocRefIds and the records that changed since.
	 *
	 * @param array<string,mixed>  $return       The CbcrReturn.
	 * @param array<string,string> $fingerprints The record fingerprints now.
	 *
	 * @return array{previousDocRefIds:array<string,string>,changed:array<int,string>}|null Null for a first file.
	 *
	 * @throws RuntimeException When the corrected report has no file to correct.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.2
	 */
	private function correctionPlan(array $return, array $fingerprints): ?array {
		$correctedId = trim((string)($return['correctsReturn'] ?? ''));
		if ($correctedId === '') {
			return null;
		}

		$corrected = $this->record(schema: 'CbcrReturn', id: $correctedId);
		$previous  = array_map('strval', (array)($corrected['docRefIds'] ?? []));
		if ($corrected === null || $previous === []) {
			throw new RuntimeException($this->l10n->t('The corrected report has no file to correct.'));
		}

		// The corrected file's records are keyed by the corrected return; this return carries them on.
		$oldEntityKey = $this->document->reportingEntityKey(returnId: $correctedId);
		$newEntityKey = $this->document->reportingEntityKey(returnId: (string)($return['id'] ?? ''));
		$previous     = self::rekey(keyed: $previous, from: $oldEntityKey, to: $newEntityKey);
		$before       = self::rekey(
			keyed: array_map('strval', (array)(($corrected['lastValidation'] ?? [])['recordFingerprints'] ?? [])),
			from: $oldEntityKey,
			to: $newEntityKey
		);

		$changed = [];
		$added   = false;
		foreach ($fingerprints as $key => $fingerprint) {
			if (isset($previous[$key]) === false) {
				$added = true;
				continue;
			}

			if (($before[$key] ?? '') !== $fingerprint) {
				$changed[] = (string)$key;
			}
		}

		if ($changed === [] && $added === false) {
			throw new RuntimeException($this->l10n->t('Nothing changed since the corrected file, so there is nothing to correct.'));
		}

		return ['previousDocRefIds' => $previous, 'changed' => $changed];
	}//end correctionPlan()

	/**
	 * A map with one key renamed.
	 *
	 * @param array<string,string> $keyed The map.
	 * @param string               $from  The old key.
	 * @param string               $to    The new key.
	 *
	 * @return array<string,string>
	 */
	private static function rekey(array $keyed, string $from, string $to): array {
		if (isset($keyed[$from]) === true) {
			$keyed[$to] = $keyed[$from];
			unset($keyed[$from]);
		}

		return $keyed;
	}//end rekey()

	/**
	 * The DocRefIds a correction keeps: the new one of every record it sends, the old one of every record it leaves out.
	 *
	 * @param array{previousDocRefIds:array<string,string>,changed:array<int,string>} $correction The plan.
	 * @param array<string,string>                                                    $docRefIds  The DocRefIds of this file.
	 *
	 * @return array<string,string>
	 */
	private static function keptDocRefIds(array $correction, array $docRefIds): array {
		$kept = [];
		foreach ($docRefIds as $key => $docRefId) {
			$previous = ($correction['previousDocRefIds'][$key] ?? '');
			$sent     = ($previous === '' || in_array($key, $correction['changed'], true) === true || str_starts_with((string)$key, 'reporting-entity:') === true);
			$kept[$key] = $previous;
			if ($sent === true) {
				$kept[$key] = $docRefId;
			}
		}

		return $kept;
	}//end keptDocRefIds()

	/**
	 * The refusal for a file that does not pass the schema: its first three errors.
	 *
	 * @param array<int,array{line:int,element:string,message:string}> $errors The errors.
	 *
	 * @return string
	 */
	private function invalidMessage(array $errors): string {
		$lines = [];
		foreach (array_slice($errors, 0, 3) as $error) {
			$lines[] = trim(sprintf('%s %s', $error['line'] > 0 ? 'Line ' . $error['line'] . ':' : '', $error['message']));
		}

		return $this->l10n->t('The country-by-country file does not pass the OECD schema, so it was not stored. %s', [implode(' ', $lines)]);
	}//end invalidMessage()

	/**
	 * Write fields onto the return in place.
	 *
	 * @param array<string,mixed> $return The CbcrReturn.
	 * @param array<string,mixed> $fields The fields.
	 *
	 * @return void
	 */
	private function update(array $return, array $fields): void {
		$object = $fields + $return;
		unset($object['@self']);
		$id = (string)($return['id'] ?? '');
		$this->objects->setRegister($this->register())->setSchema('CbcrReturn')->saveObject(
			object: $object,
			options: new ObjectSaveOptions(uuid: $id)
		);
	}//end update()

	/**
	 * A unique message reference: NL, the year, the sender's TIN and a random part.
	 *
	 * @param array<string,mixed> $return    The CbcrReturn.
	 * @param array<string,mixed> $reporting The reporting entity.
	 *
	 * @return string
	 */
	private static function messageRefId(array $return, array $reporting): string {
		$tin = (string)preg_replace('/[^A-Za-z0-9]/', '', (string)($reporting['tin'] ?? ''));
		if ($tin === '') {
			$tin = CbcXmlDocument::NO_TIN;
		}

		return sprintf('NL%s%s-%s', self::yearOf(return: $return), $tin, bin2hex(random_bytes(6)));
	}//end messageRefId()

	/**
	 * A stable hash of a record without the keys that change on their own.
	 *
	 * @param mixed $value The record.
	 *
	 * @return string
	 */
	private static function fingerprint(mixed $value): string {
		return hash('sha256', (string)json_encode(self::normalise(value: $value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
	}//end fingerprint()

	/**
	 * A value with volatile keys removed and keys sorted, recursively.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function normalise(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach (self::VOLATILE as $key) {
			unset($value[$key]);
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(static fn (mixed $item): mixed => self::normalise(value: $item), $value);
	}//end normalise()

	/**
	 * The entity's tax residence: tax residency, else jurisdiction.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 */
	private static function residenceOf(array $entity): string {
		foreach (['taxResidency', 'jurisdiction'] as $field) {
			$code = strtoupper(trim((string)($entity[$field] ?? '')));
			if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
				return $code;
			}
		}

		return '';
	}//end residenceOf()

	/**
	 * The fiscal year of the return.
	 *
	 * @param array<string,mixed> $return The CbcrReturn.
	 *
	 * @return string
	 */
	private static function yearOf(array $return): string {
		if (preg_match('/(\d{4})/', (string)($return['period'] ?? ''), $match) === 1) {
			return $match[1];
		}

		return '0000';
	}//end yearOf()

	/**
	 * A record by id, null when absent.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function record(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $this->objects->setRegister($this->register())->setSchema($schema), id: $id);
	}//end record()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
