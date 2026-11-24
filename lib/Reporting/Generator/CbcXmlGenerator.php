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

use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Service\Cbcr\CbcXmlDocument;
use OCA\Shillinq\Service\Cbcr\OecdFileGeneratorSupport;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCP\IL10N;
use Override;
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
	use OecdFileGeneratorSupport;

	/**
	 * Constructor.
	 *
	 * @param SbrRecords      $records  The register's records.
	 * @param CbcrCheckRunner $checks   The checks and the records they read.
	 * @param CbcXmlDocument  $document Renders and validates the file.
	 * @param IL10N           $l10n     Translated refusals.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function __construct(
		private readonly SbrRecords $records,
		private readonly CbcrCheckRunner $checks,
		private readonly CbcXmlDocument $document,
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
	#[Override]
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
	#[Override]
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
	#[Override]
	public function generate(array $context, string $format): GeneratedFile {
		if ($format !== 'xml') {
			throw new RuntimeException($this->l10n->t('The country-by-country file is XML only.'));
		}

		$return = $this->records->find(schema: 'CbcrReturn', id: (string)($context['returnId'] ?? ''));
		if ($return === null) {
			throw new RuntimeException($this->l10n->t('Generate the country-by-country file from its report page.'));
		}

		$this->refuseOnBlockingChecks(return: $return);

		$records = $this->checks->cbcRecords(return: $return);
		if ($records['reportingEntity'] === null) {
			throw new RuntimeException($this->l10n->t('The report names no reporting entity.'));
		}

		$file       = $this->fileParts(return: $return, records: $records);
		$xml        = $this->document->render(file: $file);
		$validation = $this->document->validate(xml: $xml) + ['validatedAt' => self::utcNow()->format('Y-m-d\TH:i:s\Z')];
		if ($validation['valid'] === false) {
			$this->update(return: $return, fields: ['lastValidation' => $validation]);
			throw new RuntimeException($this->invalidMessage(errors: $validation['errors']));
		}

		$validation['fingerprint']        = $this->checks->cbcFingerprint(return: $return);
		$validation['messageRefId']       = $file['messageRefId'];
		$validation['recordFingerprints'] = $file['recordFingerprints'];
		$this->update(return: $return, fields: ['lastValidation' => $validation, 'docRefIds' => $file['keptDocRefIds']]);

		return new GeneratedFile(
			fileName: sprintf('cbc-report-%s-%s.xml', self::fiscalYearOf(return: $return), $file['messageRefId']),
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
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private function refuseOnBlockingChecks(array $return): void {
		$failing = $this->failingBlockingChecks(results: $this->checks->runCbc(return: $return));
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
		$returnId     = (string)($return['id'] ?? '');
		$reporting    = (array)$records['reportingEntity'];
		$upe          = $this->upe(reporting: $reporting, upeId: $records['upeId']);
		$fingerprints = $this->recordFingerprints(return: $return, records: $records, groupName: (string)($upe['entityName'] ?? ''));
		$messageRefId = self::messageRefId(return: $return, reporting: $reporting);
		$correction   = $this->correctionPlan(return: $return, fingerprints: $fingerprints);

		$docRefIds = [];
		foreach (array_keys($fingerprints) as $index => $key) {
			$docRefIds[$key] = $messageRefId . '-' . ($index + 1);
		}

		// A correction resends records only; the explanatory note stays with the first file.
		$note = '';
		if ($correction === null) {
			$note = self::methodologyNote(summaries: $records['summaries']);
		}

		if ($note !== '') {
			$docRefIds[$this->document->additionalInfoKey(returnId: $returnId)] = $messageRefId . '-' . (count($docRefIds) + 1);
		}

		$file = [
			'return' => $return,
			'reportingEntity' => $reporting,
			'upeId' => $records['upeId'],
			'groupName' => (string)($upe['entityName'] ?? ''),
			'entities' => $records['entities'],
			'summaries' => $records['summaries'],
			'messageRefId' => $messageRefId,
			'timestamp' => self::utcNow()->format('Y-m-d\TH:i:s'),
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
	 * The ultimate parent: the reporting entity itself, or the entity it names.
	 *
	 * @param array<string,mixed> $reporting The reporting entity.
	 * @param string              $upeId     The ultimate parent's id.
	 *
	 * @return array<string,mixed>
	 */
	private function upe(array $reporting, string $upeId): array {
		if ($upeId === (string)($reporting['id'] ?? '')) {
			return $reporting;
		}

		return ($this->records->find(schema: 'GroupEntityRegistry', id: $upeId) ?? $reporting);
	}//end upe()

	/**
	 * A fingerprint per block of the file: the reporting entity, and each jurisdiction with its residents.
	 *
	 * Entity figures and asset book values are left out: the file shows the summaries, not them.
	 *
	 * @param array<string,mixed> $return    The CbcrReturn.
	 * @param array<string,mixed> $records   The records of CbcrCheckRunner::cbcRecords().
	 * @param string              $groupName The group's name.
	 *
	 * @return array<string,string> Record key => fingerprint, the reporting entity first.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.2
	 */
	private function recordFingerprints(array $return, array $records, string $groupName): array {
		$entities = array_map(
			static fn (array $entity): array => array_diff_key($entity, ['figures' => true, 'fixedAssetBookValue' => true]),
			$records['entities']
		);

		$key          = $this->document->reportingEntityKey(returnId: (string)($return['id'] ?? ''));
		$fingerprints = [$key => $this->checks->fingerprintOf(records: [(array)$records['reportingEntity'], $return['reportingRole'] ?? null, $groupName])];
		foreach ($records['summaries'] as $summary) {
			$residents = $this->document->residentsOf(entities: $entities, code: strtoupper((string)($summary['jurisdiction'] ?? '')));
			$fingerprints[(string)($summary['id'] ?? '')] = $this->checks->fingerprintOf(records: [$summary, $residents]);
		}

		return $fingerprints;
	}//end recordFingerprints()

	/**
	 * What a correction sends: the corrected file's DocRefIds and the records that changed since.
	 *
	 * @param array<string,mixed>  $return       The CbcrReturn.
	 * @param array<string,string> $fingerprints The record fingerprints now.
	 *
	 * @return array{previousDocRefIds:array<string,string>,changed:array<int,string>}|null Null for a first file.
	 *
	 * @throws RuntimeException When the corrected report has no file, or nothing changed.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.2
	 */
	private function correctionPlan(array $return, array $fingerprints): ?array {
		$correctedId = trim((string)($return['correctsReturn'] ?? ''));
		if ($correctedId === '') {
			return null;
		}

		$corrected = $this->records->find(schema: 'CbcrReturn', id: $correctedId);
		$previous  = array_map('strval', (array)($corrected['docRefIds'] ?? []));
		if ($corrected === null || $previous === []) {
			throw new RuntimeException($this->l10n->t('The corrected report has no file to correct.'));
		}

		// The corrected file's reporting entity is keyed by the corrected return; this return carries it on.
		$oldKey   = $this->document->reportingEntityKey(returnId: $correctedId);
		$newKey   = $this->document->reportingEntityKey(returnId: (string)($return['id'] ?? ''));
		$previous = self::rekey(keyed: $previous, from: $oldKey, to: $newKey);
		$before   = self::rekey(
			keyed: array_map('strval', (array)(($corrected['lastValidation'] ?? [])['recordFingerprints'] ?? [])),
			from: $oldKey,
			to: $newKey
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
			$resent   = str_starts_with((string)$key, 'reporting-entity:');
			$changed  = in_array($key, $correction['changed'], true);

			$kept[$key] = $previous;
			if ($previous === '' || $changed === true || $resent === true) {
				$kept[$key] = $docRefId;
			}
		}

		return $kept;
	}//end keptDocRefIds()

	/**
	 * The employee methodology notes of the summaries, each once.
	 *
	 * @param array<int,array<string,mixed>> $summaries The summaries.
	 *
	 * @return string
	 */
	private static function methodologyNote(array $summaries): string {
		$notes = [];
		foreach ($summaries as $summary) {
			$note = trim((string)($summary['employeeMethodologyNote'] ?? ''));
			if ($note !== '' && in_array($note, $notes, true) === false) {
				$notes[] = $note;
			}
		}

		return implode(' ', $notes);
	}//end methodologyNote()

	/**
	 * The refusal for a file that does not pass the schema: its first three errors.
	 *
	 * @param array<int,array{line:int,element:string,message:string}> $errors The errors.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private function invalidMessage(array $errors): string {
		$lines = self::firstSchemaErrors(errors: $errors);

		return $this->l10n->t('The country-by-country file does not pass the OECD schema, so it was not stored. %s', [$lines]);
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
		$this->records->save(schema: 'CbcrReturn', record: $object);
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

		return sprintf('NL%s%s-%s', self::fiscalYearOf(return: $return), $tin, bin2hex(random_bytes(6)));
	}//end messageRefId()

}//end class
