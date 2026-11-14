<?php

/**
 * ENSIA Cycle Steps
 *
 * What the ENSIA cycle's transitions produce (REQ-ENSIA-011, REQ-ENSIA-012):
 * the findings from the answers when the cycle moves to peer review, the
 * college statement when it moves to college approval, and the submission
 * XML when it is submitted, each kept on OpenRegister objects.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Kernel\Contracts\EnsiaCycleStepsInterface;
use OCA\Shillinq\PaymentRun\RenderedFile;
use OCA\Shillinq\Service\ENSIABevindingGenerator;
use OCA\Shillinq\Service\ENSIAVerklaringGenerator;
use OCA\Shillinq\Service\ENSIAXmlExporter;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Runs the generators of an ENSIA cycle over its stored questions and findings.
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class EnsiaCycleSteps implements EnsiaCycleStepsInterface {

	/**
	 * The media type of the college statement.
	 */
	public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * The finding states the statement lists as outstanding.
	 */
	private const OUTSTANDING = ['open', 'in-behandeling'];

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords      $records   Reads and creates the register objects.
	 * @param ENSIABevindingGenerator  $findings  Derives the findings from the answers.
	 * @param ENSIAVerklaringGenerator $statement Renders the college statement.
	 * @param ENSIAXmlExporter         $exporter  Renders the submission XML.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
		private readonly ENSIABevindingGenerator $findings,
		private readonly ENSIAVerklaringGenerator $statement,
		private readonly ENSIAXmlExporter $exporter,
	) {
	}//end __construct()

	/**
	 * Store a Bevinding for every question answered `nee` or scored below its
	 * norm that has no finding of this cycle yet, so moving the cycle again
	 * creates no duplicates.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return list<string> The ids of the findings created.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function createFindings(array $cycle): array {
		$cycle = self::identified(cycle: $cycle);
		$known = [];
		foreach ($this->ofCycle(schema: 'Bevinding', cycle: $cycle) as $finding) {
			$known[(string)($finding['questionId'] ?? '')] = true;
		}

		$created = [];
		foreach ($this->findings->fromAnswers(cyclus: $cycle, vragen: $this->ofCycle(schema: 'Evaluatievraag', cycle: $cycle)) as $finding) {
			$questionId = (string)($finding['questionId'] ?? '');
			if (isset($known[$questionId]) === true) {
				continue;
			}

			$known[$questionId] = true;
			$created[] = $this->records->create(schema: 'Bevinding', object: $finding);
		}

		return $created;
	}//end createFindings()

	/**
	 * The cycle with its college statement rendered into `declarationDocx`
	 * (base64), and `declarationFile` naming it unless a file is already named.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function withStatement(array $cycle): array {
		$identified = self::identified(cycle: $cycle);
		$outstanding = array_values(
			array_filter(
				$this->ofCycle(schema: 'Bevinding', cycle: $identified),
				static fn (array $finding): bool => in_array((string)($finding['status'] ?? 'open'), self::OUTSTANDING, true)
			)
		);

		$docx = $this->statement->render(
			cyclus: $identified,
			vragen: $this->ofCycle(schema: 'Evaluatievraag', cycle: $identified),
			bevindingen: $outstanding
		);

		$cycle['declarationDocx'] = base64_encode($docx);
		if (trim((string)($cycle['declarationFile'] ?? '')) === '') {
			$cycle['declarationFile'] = self::stem(cycle: $cycle, prefix: 'college-verklaring-ensia') . '.docx';
		}

		return $cycle;
	}//end withStatement()

	/**
	 * The cycle with its submission XML in `submissionXml`.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus, approved or submitted.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DomainException When the cycle is not approved or has no statement.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function withSubmission(array $cycle): array {
		if ($this->exporter->canExport(cyclus: $cycle) === false) {
			throw new DomainException('Only a cycle approved by the college with its statement can be submitted.');
		}

		$submittedAt = trim((string)($cycle['submittedAt'] ?? ''));
		$cycle['submissionXml'] = $this->exporter->render(
			cyclus: $cycle,
			vragen: $this->ofCycle(schema: 'Evaluatievraag', cycle: self::identified(cycle: $cycle)),
			submittedAt: ($submittedAt === '' ? null : $submittedAt)
		);

		return $cycle;
	}//end withSubmission()

	/**
	 * The stored college statement as a download, or null without one.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return RenderedFile|null
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function statementFile(array $cycle): ?RenderedFile {
		$docx = base64_decode((string)($cycle['declarationDocx'] ?? ''), true);
		if ($docx === false || $docx === '') {
			return null;
		}

		return new RenderedFile(
			fileName: self::stem(cycle: $cycle, prefix: 'college-verklaring-ensia') . '.docx',
			mimeType: self::DOCX_MIME,
			format: 'docx',
			content: $docx,
		);
	}//end statementFile()

	/**
	 * The stored submission XML as a download, or null without one.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus.
	 *
	 * @return RenderedFile|null
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function submissionFile(array $cycle): ?RenderedFile {
		$xml = (string)($cycle['submissionXml'] ?? '');
		if (trim($xml) === '') {
			return null;
		}

		return new RenderedFile(
			fileName: self::stem(cycle: $cycle, prefix: 'ensia') . '.xml',
			mimeType: 'text/xml',
			format: 'ensia-xml',
			content: $xml,
		);
	}//end submissionFile()

	/**
	 * The records of a schema that belong to the cycle, in its administration.
	 *
	 * @param string               $schema The schema: Evaluatievraag or Bevinding.
	 * @param array<string, mixed> $cycle  The cycle with its id.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ofCycle(string $schema, array $cycle): array {
		$cycleId = (string)$cycle['id'];
		$administrationId = (string)($cycle['administrationId'] ?? '');
		if ($cycleId === '') {
			return [];
		}

		$rows = $this->records->records(schema: $schema, filters: ['cyclusId' => $cycleId, 'administrationId' => $administrationId]);

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => (string)($row['cyclusId'] ?? '') === $cycleId
					&& (string)($row['administrationId'] ?? '') === $administrationId
			)
		);
	}//end ofCycle()

	/**
	 * The cycle with its object id under `id`.
	 *
	 * @param array<string, mixed> $cycle The ENSIAJaarcyclus as the transition hands it over.
	 *
	 * @return array<string, mixed>
	 */
	private static function identified(array $cycle): array {
		$cycle['id'] = ObjectIdentifier::resolve(saved: $cycle);

		return $cycle;
	}//end identified()

	/**
	 * A file name stem for the cycle: the prefix, the administration and the year.
	 *
	 * @param array<string, mixed> $cycle  The ENSIAJaarcyclus.
	 * @param string               $prefix The prefix.
	 *
	 * @return string
	 */
	private static function stem(array $cycle, string $prefix): string {
		$parts = [$prefix, (string)($cycle['administrationId'] ?? ''), (string)($cycle['year'] ?? '')];

		return trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', implode('-', array_filter($parts, static fn (string $part): bool => $part !== ''))), '-');
	}//end stem()
}//end class
