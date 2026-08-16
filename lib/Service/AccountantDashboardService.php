<?php

/**
 * Accountant Dashboard Service
 *
 * Aggregates a per-client status overview for the accountant-portal in-app
 * surface (REQ-ACP-002): for every administration the authenticated user has
 * a valid {@see AdministrationContextService} membership for, it composes a
 * status card from the existing bookkeeping surfaces — it does NOT invent a
 * new status model. Every accessible administration is derived from
 * {@see AdministrationContextService::buildContext()} (REQ-ACP-001), which is
 * itself the tenant-isolation boundary (REQ-ACP-003): an administration this
 * user has no membership for never appears in the result, so this service
 * cannot leak another tenant's status by construction.
 *
 * Signals composed per client:
 *  - Period-close state: the most recent `FiscalPeriod` record for the
 *    administration (open/closing/closed/audit-locked, bookkeeping-period-close).
 *  - Open items / attention flags: delegated wholesale to
 *    {@see PeriodCloseAssistantService::analyse()} — the existing AI close
 *    assistant already detects open AP/AR transactions, unreconciled bank
 *    receipts and outstanding expense claims; this service reuses it rather
 *    than re-implementing detection.
 *  - BTW filing status: the most recent `VATReturn` record
 *    (draft/submitted/verified/filed, bookkeeping-vat-btw-filing) plus a
 *    statutory 1-month filing deadline derived from its period end date
 *    (Wet OB 1968 art. 14) when the return is not yet filed.
 *  - Missing documents: a best-effort count of `SupplierInvoice` rows with no
 *    `ublSourceUri` recorded — the source-document surface Shillinq ships
 *    today; a dedicated document-completeness model is a candidate follow-up.
 *
 * Every read is fail-soft: a schema/query failure degrades that one signal to
 * null/0 and is logged, it never fails the whole dashboard (mirrors the
 * resilient posture of ReportGenerationService and AdministrationContextService).
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
 * @spec openspec/specs/accountant-portal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Composes the accountant multi-client dashboard from existing bookkeeping surfaces.
 *
 * @spec openspec/specs/accountant-portal/spec.md
 */
class AccountantDashboardService {

	/**
	 * Statutory BTW filing term: aangifte + betaling due one month after the
	 * period end date (Wet OB 1968 art. 14).
	 *
	 * @var string
	 */
	private const VAT_FILING_TERM = '+1 month';

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container — lazily resolves OpenRegister's
	 *                                      ObjectService.
	 * @param AdministrationContextService $context Administratie-aware RBAC context (the tenant-isolation boundary).
	 * @param PeriodCloseAssistantService $assistant Reused open-items / attention-flag detector.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger — every degraded signal is logged as a
	 *                                warning.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly AdministrationContextService $context,
		private readonly PeriodCloseAssistantService $assistant,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the multi-client dashboard for the authenticated user (REQ-ACP-001, REQ-ACP-002).
	 *
	 * @return array{userId:?string,administrations:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/accountant-portal/spec.md
	 */
	public function buildDashboard(): array {
		$userId = $this->context->currentUserId();
		if ($userId === null) {
			return ['userId' => null, 'administrations' => []];
		}

		$cards = [];
		foreach ($this->context->buildContext()['administrations'] as $administration) {
			$cards[] = $this->buildCard(administration: $administration);
		}

		return [
			'userId' => $userId,
			'administrations' => $cards,
		];

	}//end buildDashboard()

	/**
	 * Build a single client's status card. Never throws — every signal degrades
	 * independently on failure.
	 *
	 * @param array<string,mixed> $administration One entry from {@see AdministrationContextService::buildContext()}.
	 *
	 * @return array<string,mixed>
	 */
	private function buildCard(array $administration): array {
		$administrationId = (string)($administration['administrationId'] ?? '');

		$period = $this->latestFiscalPeriod(administrationId: $administrationId);

		$openItems = [];
		$periodIdForFlag = ($period['periodId'] ?? null);
		if (is_string($periodIdForFlag) === true && $periodIdForFlag !== '') {
			$openItems = $this->assistantFlags(administrationId: $administrationId, periodId: $periodIdForFlag);
		}

		return [
			'administrationId' => $administrationId,
			'administrationCode' => (string)($administration['administrationCode'] ?? ''),
			'name' => (string)($administration['name'] ?? ''),
			'role' => (string)($administration['role'] ?? ''),
			'periodClose' => $period,
			'vatFiling' => $this->latestVatFilingStatus(administrationId: $administrationId),
			'missingDocuments' => $this->missingDocumentCount(administrationId: $administrationId),
			'openItemsCount' => count($openItems),
			'attentionItems' => $openItems,
		];

	}//end buildCard()

	/**
	 * Reuse the existing AI close-assistant flag detector for "open items" /
	 * "items needing attention" (REQ-ACP-002) rather than re-implementing
	 * open-AP/AR, unreconciled-bank and outstanding-expense detection.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The most recent FiscalPeriod's periodId.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function assistantFlags(string $administrationId, string $periodId): array {
		try {
			return $this->assistant->analyse(administrationId: $administrationId, periodId: $periodId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'AccountantDashboardService: close-assistant analysis failed',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return [];
		}

	}//end assistantFlags()

	/**
	 * Resolve the most recent FiscalPeriod for an administration (period-close state).
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array{periodId:string,state:string,endDate:string}|null
	 */
	private function latestFiscalPeriod(string $administrationId): ?array {
		$rows = $this->findAll(schema: 'FiscalPeriod', administrationId: $administrationId);
		if ($rows === []) {
			return null;
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp((string)($right['endDate'] ?? ''), (string)($left['endDate'] ?? ''))
		);

		$latest = $rows[0];

		return [
			'periodId' => (string)($latest['periodId'] ?? ($latest['id'] ?? '')),
			'state' => (string)($latest['state'] ?? 'open'),
			'endDate' => (string)($latest['endDate'] ?? ''),
		];

	}//end latestFiscalPeriod()

	/**
	 * Resolve the most recent VATReturn's filing status + statutory deadline.
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array{statusCode:string,periodEndDate:string,dueDate:?string,overdue:bool}|null
	 */
	private function latestVatFilingStatus(string $administrationId): ?array {
		$rows = $this->findAll(schema: 'BtwAangifte', administrationId: $administrationId);
		if ($rows === []) {
			return null;
		}

		usort(
			$rows,
			static fn (array $left, array $right): int => strcmp((string)($right['endDate'] ?? ''), (string)($left['endDate'] ?? ''))
		);

		$latest = $rows[0];
		$statusCode = (string)($latest['statusCode'] ?? 'draft');
		$endDate = (string)($latest['endDate'] ?? '');

		$dueDate = null;
		$overdue = false;
		if ($statusCode !== 'filed' && $endDate !== '') {
			try {
				$due = (new DateTimeImmutable($endDate))->modify(self::VAT_FILING_TERM);
				$dueDate = $due->format('Y-m-d');
				$overdue = ($due < new DateTimeImmutable('today'));
			} catch (\Throwable $e) {
				$dueDate = null;
			}
		}

		return [
			'statusCode' => $statusCode,
			'periodEndDate' => $endDate,
			'dueDate' => $dueDate,
			'overdue' => $overdue,
		];

	}//end latestVatFilingStatus()

	/**
	 * Best-effort count of SupplierInvoice rows with no recorded source document
	 * (`ublSourceUri` empty) for the administration (REQ-ACP-002). Returns 0 when
	 * the schema is unavailable rather than failing the dashboard.
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return int
	 */
	private function missingDocumentCount(string $administrationId): int {
		$rows = $this->findAll(schema: 'SupplierInvoice', administrationId: $administrationId);

		$missing = 0;
		foreach ($rows as $row) {
			$source = (string)($row['ublSourceUri'] ?? '');
			if (trim($source) === '') {
				$missing++;
			}
		}

		return $missing;
	}//end missingDocumentCount()

	/**
	 * Fail-soft OpenRegister findAll() scoped to one administration + schema.
	 *
	 * @param string $schema The schema slug.
	 * @param string $administrationId The administration to filter by.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, string $administrationId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rows = $objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => ['administrationId' => $administrationId]]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'AccountantDashboardService: findAll failed',
				['schema' => $schema, 'administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			$arr = $this->asArray(row: $row);
			if ($arr !== []) {
				$result[] = $arr;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Normalise an OpenRegister ObjectService row (ObjectEntity or array) to a
	 * plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService::findAll().
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
