<?php

/**
 * ICP statement preparer
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
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Icp;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\IcpServiceInterface;
use OCP\IAppConfig;

/**
 * Prepares the ICP statement of a period from the books (REQ-ICR-002,
 * design D2): one line per buyer VAT number and supply type, written as
 * the period's draft `IcpOpgaaf`, with its reconciliation against box 3b.
 *
 * A draft of the period is replaced in place; a statement past draft is
 * never replaced (IcpStatementFiledException).
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */
class IcpStatementPreparer {

	/**
	 * The schema the statement is written to.
	 *
	 * @var string
	 */
	public const SCHEMA = 'IcpOpgaaf';

	/**
	 * A filing period: a quarter or a month.
	 *
	 * @var string
	 */
	private const PERIOD_PATTERN = '/^\d{4}-(Q[1-4]|0[1-9]|1[0-2])$/';

	/**
	 * Construct the preparer.
	 *
	 * @param IcpServiceInterface    $icpService    The ICP ledger and reconciliation.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 */
	public function __construct(
		private readonly IcpServiceInterface $icpService,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Prepare the period's statement and write it as its draft.
	 *
	 * @param string $administrationId Administration scope (checked by the caller).
	 * @param string $period           YYYY-Qn or YYYY-MM.
	 *
	 * @return array{statement:array<string,mixed>,reconciliation:array<string,mixed>,replaced:bool}
	 *
	 * @throws InvalidArgumentException   When the period is not a quarter or a month.
	 * @throws IcpStatementFiledException When the period's statement is past draft.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
	 */
	public function prepare(string $administrationId, string $period): array {
		if (preg_match(self::PERIOD_PATTERN, $period) !== 1) {
			throw new InvalidArgumentException('The period must be a quarter (2026-Q3) or a month (2026-07).');
		}

		$existing = $this->statementOf(administrationId: $administrationId, period: $period);
		$status = (string)($existing['status'] ?? 'draft');
		if ($existing !== null && $status !== 'draft') {
			throw new IcpStatementFiledException(period: $period, status: $status);
		}

		$ledger = $this->icpService->ledger(administrationId: $administrationId, period: $period);
		$statement = [
			'administrationId' => $administrationId,
			'period' => $period,
			'periodicity' => $this->periodicity(period: $period),
			'status' => 'draft',
			'type' => 'initial',
			'lines' => array_map(
				static fn (array $line): array => [
					'buyerVatId' => (string)($line['buyerVatId'] ?? ''),
					'supplyType' => (string)($line['supplyType'] ?? ''),
					'amountExclVat' => (float)($line['amountExclVat'] ?? 0),
				],
				$ledger['lines']
			),
			'total' => $ledger['total'],
			'totalGoods' => $ledger['totalGoods'],
			'totalServices' => $ledger['totalServices'],
			'totalTriangulation' => $ledger['totalTriangulation'],
		];

		$uuid = null;
		if ($existing !== null && $this->idOf(record: $existing) !== '') {
			$uuid = $this->idOf(record: $existing);
		}

		$saved = $this->objectService->saveObject(
			object: $statement,
			register: $this->register(),
			schema: self::SCHEMA,
			uuid: $uuid,
		);

		return [
			'statement' => array_merge($statement, (array)$saved->jsonSerialize()),
			'reconciliation' => $this->icpService->reconcile(administrationId: $administrationId, period: $period),
			'replaced' => $existing !== null,
		];
	}//end prepare()

	/**
	 * The period's initial statement, or null when there is none. A
	 * statement past draft wins over a draft, so it is never overlooked.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period           The period.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
	 */
	public function statementOf(string $administrationId, string $period): ?array {
		$found = null;
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema(self::SCHEMA)
			->findAll(['filters' => ['administrationId' => $administrationId, 'period' => $period]]);
		foreach ($rows as $row) {
			$record = self::asArray(row: $row);
			if ((string)($record['period'] ?? '') !== $period
				|| (string)($record['administrationId'] ?? '') !== $administrationId
				|| (string)($record['type'] ?? 'initial') !== 'initial'
			) {
				continue;
			}

			if ((string)($record['status'] ?? 'draft') !== 'draft') {
				return $record;
			}

			$found = ($found ?? $record);
		}

		return $found;
	}//end statementOf()

	/**
	 * A record's id: its @self id, else its id, else its uuid.
	 *
	 * @param array<string,mixed> $record The record.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
	 */
	public function idOf(array $record): string {
		return trim((string)($record['@self']['id'] ?? ($record['id'] ?? ($record['uuid'] ?? ''))));
	}//end idOf()

	/**
	 * Monthly for YYYY-MM, quarterly for YYYY-Qn.
	 *
	 * @param string $period The period.
	 *
	 * @return string
	 */
	private function periodicity(string $period): string {
		if (str_contains($period, '-Q') === true) {
			return 'quarterly';
		}

		return 'monthly';
	}//end periodicity()

	/**
	 * An ObjectService row as an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string,mixed>
	 */
	private static function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}
		}

		return [];
	}//end asArray()

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
