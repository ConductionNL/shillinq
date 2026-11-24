<?php

/**
 * Filed return drift check.
 *
 * Checks every filed VAT return of an administration's fiscal year against
 * the books, the way "Check for corrections" does for one return, so drift
 * shows on the corrections page without anyone asking (REQ-VPS-003).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\FiledReturnDriftCheckInterface;
use OCA\Shillinq\Kernel\Contracts\VatSuppletieDetectionServiceInterface;
use OCA\Shillinq\Support\RegisterSlug;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the drift check on each filed return of a fiscal year.
 *
 * A return still in draft is not filed and is left alone. A return that
 * already has a correction waiting in draft is left alone too: checking it
 * again would write the same correction a second time.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 */
class FiledReturnDriftCheck implements FiledReturnDriftCheckInterface {

	/**
	 * The schema a VAT return is stored in.
	 *
	 * @var string
	 */
	public const RETURN_SCHEMA = 'BtwAangifte';

	/**
	 * The schema a correction is stored in.
	 *
	 * @var string
	 */
	public const CORRECTION_SCHEMA = 'VatCorrection';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService The object store.
	 * @param VatSuppletieDetectionServiceInterface $suppletie     Compares one filed return with the books.
	 * @param IAppConfig                   $appConfig     The register slug.
	 * @param LoggerInterface              $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly VatSuppletieDetectionServiceInterface $suppletie,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check the filed returns of one fiscal year of an administration.
	 *
	 * One return that cannot be checked is logged and the others still are.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fiscalYear       The fiscal year.
	 *
	 * @return array{checked:int,corrections:int} How many returns were checked and how many corrections were written.
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
	 */
	#[Override]
	public function checkFiscalYear(string $administrationId, int $fiscalYear): array {
		$outcome = ['checked' => 0, 'corrections' => 0];
		if ($administrationId === '' || $fiscalYear <= 0) {
			return $outcome;
		}

		$waiting = $this->returnsWithOpenCorrection(administrationId: $administrationId);
		foreach ($this->filedReturns(administrationId: $administrationId, fiscalYear: $fiscalYear) as $returnId) {
			if (in_array($returnId, $waiting, true) === true) {
				continue;
			}

			$outcome['checked']++;
			try {
				if ($this->suppletie->detect(vatReturnId: $returnId) !== null) {
					$outcome['corrections']++;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'FiledReturnDriftCheck: a filed return could not be checked for drift',
					['returnId' => $returnId, 'exception' => $e->getMessage()]
				);
			}
		}

		$this->logger->info(
			'FiledReturnDriftCheck: filed returns checked for drift',
			['administrationId' => $administrationId, 'fiscalYear' => $fiscalYear] + $outcome
		);

		return $outcome;
	}//end checkFiscalYear()

	/**
	 * The ids of the administration's returns of the year that were filed.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fiscalYear       The fiscal year.
	 *
	 * @return list<string>
	 */
	private function filedReturns(string $administrationId, int $fiscalYear): array {
		$ids = [];
		foreach ($this->rows(schema: self::RETURN_SCHEMA, filters: ['administrationId' => $administrationId]) as $row) {
			$status = (string)($row['statusCode'] ?? '');
			$id = (string)($row['id'] ?? '');
			if ($id === '' || $status === '' || $status === 'draft' || (int)($row['periodYear'] ?? 0) !== $fiscalYear) {
				continue;
			}

			$ids[] = $id;
		}

		return $ids;
	}//end filedReturns()

	/**
	 * The ids of the returns that have a correction still in draft.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<string>
	 */
	private function returnsWithOpenCorrection(string $administrationId): array {
		$ids = [];
		foreach ($this->rows(schema: self::CORRECTION_SCHEMA, filters: ['administrationId' => $administrationId]) as $row) {
			if ((string)($row['state'] ?? '') !== 'draft') {
				continue;
			}

			$returnId = (string)($row['originalVatReturnId'] ?? ($row['originalReturnId'] ?? ''));
			if ($returnId !== '') {
				$ids[] = $returnId;
			}
		}

		return $ids;
	}//end returnsWithOpenCorrection()

	/**
	 * The objects of a schema matching the filters, each as an array.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string,string> $filters The filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		$rows = [];
		foreach ($found as $candidate) {
			$row = ObjectIdentifier::recordWithId(candidate: $candidate);
			if ($row !== null) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * The configured register slug, 'shillinq' when unset.
	 *
	 * @return string
	 */
	private function register(): string {
		return RegisterSlug::of(config: $this->appConfig);
	}//end register()
}//end class
