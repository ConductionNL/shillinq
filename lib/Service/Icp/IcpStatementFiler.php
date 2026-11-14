<?php

/**
 * ICP statement filer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Icp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Icp;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\IcpServiceInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\IcpCalculator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Files a finalised ICP statement as an XBRL instance on the ICP entry
 * point through the Digipoort hand-off the VAT return uses (REQ-ICR-003,
 * design D3). The statement's status follows only what Digipoort answers:
 * a delivered instance is `submitted`, a refused one `rejected`, a
 * deferred one stays `finalized`. Acceptance arrives later, as Digipoort's
 * answer on the hand-off.
 *
 * While the hand-off is dormant (no Digipoort connection), filing is
 * refused and the XML can be downloaded instead.
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
 */
class IcpStatementFiler {

	/**
	 * The filing type on the Digipoort envelope.
	 *
	 * @var string
	 */
	public const FILING_TYPE = 'icp-opgaaf';

	/**
	 * The taxonomy the instance is composed on.
	 *
	 * @var string
	 */
	public const TAXONOMY_VERSION = 'NT18';

	/**
	 * Construct the filer.
	 *
	 * @param IcpStatementPreparer         $preparer      Finds the period's statement.
	 * @param IcpServiceInterface          $icpService    The reconciliation with box 3b.
	 * @param IcpCalculator                $calculator    Composes the XBRL instance.
	 * @param DigipoortSbrAdapterInterface $digipoort     The Digipoort hand-off.
	 * @param ObjectServiceInterface       $objectService OpenRegister's object service.
	 * @param IAppConfig                   $appConfig     App config for the register slug.
	 * @param ITimeFactory                 $time          The clock.
	 */
	public function __construct(
		private readonly IcpStatementPreparer $preparer,
		private readonly IcpServiceInterface $icpService,
		private readonly IcpCalculator $calculator,
		private readonly DigipoortSbrAdapterInterface $digipoort,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Whether filing through Digipoort is available.
	 *
	 * @return bool False while the hand-off is dormant.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
	 */
	public function isAvailable(): bool {
		return $this->digipoort->isDormant() === false;
	}//end isAvailable()

	/**
	 * The XBRL instance of the period's statement.
	 *
	 * @param string $administrationId Administration scope (checked by the caller).
	 * @param string $period           The period.
	 *
	 * @return string The instance XML.
	 *
	 * @throws DomainException When no statement has been prepared for the period.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
	 */
	public function instanceXml(string $administrationId, string $period): string {
		$statement = $this->requireStatement(administrationId: $administrationId, period: $period);

		return $this->compose(statement: $statement, administration: $this->administration(administrationId: $administrationId));
	}//end instanceXml()

	/**
	 * File the period's finalised statement.
	 *
	 * @param string $administrationId Administration scope (checked by the caller).
	 * @param string $period           The period.
	 *
	 * @return array{status:string,deliveryStatus:string,reference:string}
	 *
	 * @throws DomainException When filing is unavailable, or the statement is missing, not finalised or does not reconcile.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
	 */
	public function file(string $administrationId, string $period): array {
		if ($this->isAvailable() === false) {
			throw new DomainException('Filing through Digipoort is not available yet. Download the XML and file it yourself.');
		}

		$statement = $this->requireStatement(administrationId: $administrationId, period: $period);
		$status = (string)($statement['status'] ?? 'draft');
		if ($status !== 'finalized') {
			throw new DomainException(sprintf('Only a finalised ICP statement can be filed; this one is %s.', $status));
		}

		$reconciliation = $this->icpService->reconcile(administrationId: $administrationId, period: $period);
		if (($reconciliation['matches'] ?? false) !== true) {
			throw new DomainException('The ICP statement does not reconcile with box 3b of the VAT return within EUR 1.');
		}

		$administration = $this->administration(administrationId: $administrationId);
		$xml = $this->compose(statement: $statement, administration: $administration);
		$statementId = $this->preparer->idOf(record: $statement);
		$dates = $this->periodDates(period: $period);

		$result = $this->digipoort->submit(
			[
				'filingType' => self::FILING_TYPE,
				'reportingPeriodStartDate' => $dates[0],
				'reportingPeriodEndDate' => $dates[1],
				'organizationLegalName' => (string)($administration['name'] ?? ''),
				'kvkNumber' => (string)($administration['kvkNumber'] ?? ''),
				'fiscalNumber' => (string)($administration['rsin'] ?? ''),
				'xbrlInstanceBytes' => $xml,
				'xbrlChecksum' => hash('sha256', $xml),
				'taxonomyVersion' => self::TAXONOMY_VERSION,
				'identifierScheme' => 'vat',
				'correlationId' => $statementId,
			]
		);

		$newStatus = match (strtoupper($result->deliveryStatus)) {
			'ACCEPTED' => 'submitted',
			'REJECTED' => 'rejected',
			default => $status,
		};

		if ($newStatus !== $status) {
			$update = array_merge(
				self::payload(record: $statement),
				['status' => $newStatus, 'xmlPayload' => $xml]
			);
			if ($newStatus === 'submitted') {
				$update['taxAuthorityReference'] = $result->reference;
				$update['submittedAt'] = $this->time->getDateTime()->format(DATE_ATOM);
			}

			$uuid = null;
			if ($statementId !== '') {
				$uuid = $statementId;
			}

			$this->objectService->saveObject(
				object: $update,
				register: $this->register(),
				schema: IcpStatementPreparer::SCHEMA,
				uuid: $uuid,
			);
		}

		return [
			'status' => $newStatus,
			'deliveryStatus' => strtoupper($result->deliveryStatus),
			'reference' => $result->reference,
		];
	}//end file()

	/**
	 * The period's statement, or a refusal naming the missing preparation.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period           The period.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws DomainException When none has been prepared.
	 */
	private function requireStatement(string $administrationId, string $period): array {
		$statement = $this->preparer->statementOf(administrationId: $administrationId, period: $period);
		if ($statement === null) {
			throw new DomainException(sprintf('No ICP statement has been prepared for %s. Prepare it on the ICP page first.', $period));
		}

		return $statement;
	}//end requireStatement()

	/**
	 * The instance of a statement, filed under the administration's VAT number.
	 *
	 * @param array<string,mixed> $statement      The statement.
	 * @param array<string,mixed> $administration The administration.
	 *
	 * @return string
	 */
	private function compose(array $statement, array $administration): string {
		$filer = trim((string)($administration['vatNumber'] ?? ($administration['fiscalUnitVatId'] ?? '')));

		return $this->calculator->composeXbrl(
			period: (string)($statement['period'] ?? ''),
			filerVatId: $filer,
			lines: array_values(array_filter((array)($statement['lines'] ?? []), 'is_array')),
		);
	}//end compose()

	/**
	 * The administration record, or [] when it cannot be found.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,mixed>
	 */
	private function administration(string $administrationId): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('Administration')
			->findAll(['filters' => []]);
		foreach ($rows as $row) {
			$record = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$record = $row->jsonSerialize();
			}

			if (is_array($record) === false) {
				continue;
			}

			$keys = [
				(string)($record['@self']['slug'] ?? ''),
				(string)($record['@self']['id'] ?? ''),
				(string)($record['id'] ?? ''),
				(string)($record['slug'] ?? ''),
			];
			if (in_array($administrationId, $keys, true) === true) {
				return self::payload(record: $record);
			}
		}

		return [];
	}//end administration()

	/**
	 * The first and last day of a period (YYYY-Qn or YYYY-MM).
	 *
	 * @param string $period The period.
	 *
	 * @return array{0:string,1:string}
	 */
	private function periodDates(string $period): array {
		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $match) === 1) {
			$first = (((int)$match[2] - 1) * 3 + 1);
			$start = sprintf('%s-%02d-01', $match[1], $first);
			$end = sprintf('%s-%02d-01', $match[1], ($first + 2));

			return [$start, date('Y-m-t', (int)strtotime($end))];
		}

		if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) === 1) {
			$start = sprintf('%s-%s-01', $match[1], $match[2]);

			return [$start, date('Y-m-t', (int)strtotime($start))];
		}

		return ['', ''];
	}//end periodDates()

	/**
	 * A record without its metadata block.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return array<string,mixed>
	 */
	private static function payload(array $record): array {
		unset($record['@self']);

		return $record;
	}//end payload()

	/**
	 * The configured register slug, 'shillinq' by default.
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
