<?php

/**
 * Shillinq VatFiscalYearCorrectionService
 *
 * Checks the VAT returns of a broken fiscal year for corrections at once
 * (REQ-TVRB-002, design D4). For an administration whose fiscal year is not
 * the calendar year, every accepted return whose period lies in the fiscal
 * year is compared with the books; each one that drifted gets its draft
 * correction, stamped with the fiscal year and the year's total. The EUR
 * 1,000 threshold for a separate supplementary return then applies to that
 * total, as the Belastingdienst allows a correction per fiscal year when it
 * is not the calendar year.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Vat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Vat;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Check a broken fiscal year's VAT returns for corrections and total them.
 *
 * @spec openspec/changes/tax-vat-return-from-books/specs/bookkeeping-vat-btw-filing/spec.md#requirement-corrections-are-grouped-by-a-broken-fiscal-year-req-tvrb-002
 */
class VatFiscalYearCorrectionService {

	/**
	 * The return states the Belastingdienst has received.
	 *
	 * @var list<string>
	 */
	private const ACCEPTED_STATES = ['submitted', 'verified', 'filed'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service.
	 * @param IAppConfig                   $appConfig     The register slug.
	 * @param VatSuppletieDetectionService $suppletie     Compares one filed return with the books.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly VatSuppletieDetectionService $suppletie,
	) {
	}//end __construct()

	/**
	 * A fiscal year by id, null when it does not exist.
	 *
	 * @param string $fiscalYearId The FiscalYear id.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
	 */
	public function findFiscalYear(string $fiscalYearId): ?array {
		return $this->findOne(schema: 'FiscalYear', id: $fiscalYearId);
	}//end findFiscalYear()

	/**
	 * Check every accepted return of a broken fiscal year for corrections.
	 *
	 * @param array<string,mixed> $fiscalYear The FiscalYear: id, administrationId, startDate, endDate.
	 *
	 * @return array{fiscalYearId:string,fiscalYear:string,returns:list<array{returnId:string,returnNumber:string,drift:bool,correctionId:string|null,correctionTotal:float}>,total:float,thresholdExceeded:bool}
	 *
	 * @throws RuntimeException When the administration's fiscal year is the calendar year, or the year has no dates.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
	 */
	public function check(array $fiscalYear): array {
		$fiscalYearId     = ObjectIdentifier::resolve(saved: $fiscalYear);
		$administrationId = (string)($fiscalYear['administrationId'] ?? '');
		$start            = substr((string)($fiscalYear['startDate'] ?? ''), 0, 10);
		$end              = substr((string)($fiscalYear['endDate'] ?? ''), 0, 10);
		if ($start === '' || $end === '') {
			throw new RuntimeException('The fiscal year has no start or end date.');
		}

		$administration = $this->findOne(schema: 'Administration', id: $administrationId);
		if (($administration['nonCalendarFiscalYear'] ?? false) !== true) {
			throw new RuntimeException('The fiscal year of this administration is the calendar year; check its returns one by one.');
		}

		$label   = self::label(start: $start, end: $end);
		$checked = [];
		$total   = 0.0;
		foreach ($this->acceptedReturns(administrationId: $administrationId, start: $start, end: $end) as $vatReturn) {
			$returnId   = ObjectIdentifier::resolve(saved: $vatReturn);
			$correction = $this->suppletie->detect(vatReturnId: $returnId);
			$amount     = 0.0;
			if ($correction !== null) {
				$amount = $this->suppletie->correctionTotal(correction: $correction);
				$total += $amount;
			}

			$checked[] = [
				'returnId' => $returnId,
				'returnNumber' => (string)($vatReturn['returnNumber'] ?? ''),
				'drift' => ($correction !== null),
				'correction' => $correction,
				'correctionTotal' => $amount,
			];
		}

		$total = round($total, 2);
		foreach ($checked as $index => $row) {
			$checked[$index]['correctionId'] = $this->stampFiscalYear(
				correction: $row['correction'],
				fiscalYearId: $fiscalYearId,
				label: $label,
				total: $total
			);
			unset($checked[$index]['correction']);
		}

		return [
			'fiscalYearId' => $fiscalYearId,
			'fiscalYear' => $label,
			'returns' => $checked,
			'total' => $total,
			'thresholdExceeded' => (abs($total) >= VatSuppletieDetectionService::SUPPLETIE_THRESHOLD_EUR),
		];
	}//end check()

	/**
	 * The name of a fiscal year: 2025/2026 when it spans two calendar years, else 2026.
	 *
	 * @param string $start The first day.
	 * @param string $end   The last day.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-4.1
	 */
	public static function label(string $start, string $end): string {
		$from = substr($start, 0, 4);
		$to   = substr($end, 0, 4);
		if ($from === $to) {
			return $from;
		}

		return $from . '/' . $to;
	}//end label()

	/**
	 * Write the fiscal year and its total onto a detected correction.
	 *
	 * @param array<string,mixed>|null $correction   The correction, null when the return still matches.
	 * @param string                   $fiscalYearId The fiscal year.
	 * @param string                   $label        Its name.
	 * @param float                    $total        The year's correction total.
	 *
	 * @return string|null The correction id.
	 */
	private function stampFiscalYear(?array $correction, string $fiscalYearId, string $label, float $total): ?string {
		if ($correction === null) {
			return null;
		}

		$correction['fiscalYearId']              = $fiscalYearId;
		$correction['fiscalYearLabel']           = $label;
		$correction['fiscalYearCorrectionTotal'] = $total;
		$saved = $this->objectService->setRegister($this->register())->setSchema('VatCorrection')->saveObject($correction);

		$id = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			$id = ObjectIdentifier::resolve(saved: $correction);
		}

		return $id;
	}//end stampFiscalYear()

	/**
	 * The administration's accepted returns whose period lies in the fiscal year, oldest first.
	 *
	 * @param string $administrationId The administration.
	 * @param string $start            The fiscal year's first day.
	 * @param string $end              The fiscal year's last day.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function acceptedReturns(string $administrationId, string $start, string $end): array {
		$rows = $this->objectService->setRegister($this->register())->setSchema('BtwAangifte')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$returns = [];
		foreach ($rows as $row) {
			$row = self::asArray(row: $row);
			$from = substr((string)($row['startDate'] ?? ''), 0, 10);
			$to   = substr((string)($row['endDate'] ?? ''), 0, 10);
			if ($from >= $start && $to !== '' && $to <= $end
				&& in_array((string)($row['statusCode'] ?? ''), self::ACCEPTED_STATES, true) === true
			) {
				$returns[] = $row;
			}
		}

		usort($returns, static fn (array $a, array $b): int => strcmp((string)$a['startDate'], (string)$b['startDate']));
		return $returns;
	}//end acceptedReturns()

	/**
	 * One object by id as an array, null when absent.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$found = ObjectIdentifier::findScoped(
			scoped: $this->objectService->setRegister($this->register())->setSchema($schema),
			id: $id
		);
		if ($found === null) {
			return null;
		}

		return self::asArray(row: $found);
	}//end findOne()

	/**
	 * A row as an array.
	 *
	 * @param mixed $row An array or an entity.
	 *
	 * @return array<string,mixed>
	 */
	private static function asArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		return (array)$row;
	}//end asArray()

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
