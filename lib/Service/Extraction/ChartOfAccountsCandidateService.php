<?php

/**
 * Chart Of Accounts Candidate Service
 *
 * Change gl-account-suggestion-consume (REQ-GAC-002) — supplies shillinq's
 * own chart of accounts (the `Account` OR schema, `bookkeeping-chart-of-accounts`)
 * as the candidate set docudesk's GL-account suggestion ranks against.
 * docudesk never learns a chart of accounts (its own REQ-GLS-07) — the
 * consumer supplies opaque candidate codes/labels on every request. Only
 * `active` accounts (REQ-CoA-005) in the draft's own administration are
 * offered: a `blocked`/`archived` account, or one belonging to a different
 * tenant, is never a valid target for a new booking.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Extraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Extraction;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the administration-scoped, active chart-of-accounts candidate set.
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 */
class ChartOfAccountsCandidateService {
	/**
	 * The `Account` OR schema slug (bookkeeping-chart-of-accounts, REQ-CoA-001).
	 *
	 * @var string
	 */
	private const SCHEMA_ACCOUNT = 'Account';

	/**
	 * The only lifecycle state a new booking may target (REQ-CoA-005).
	 *
	 * @var string
	 */
	private const ACTIVE_STATE = 'active';

	/**
	 * Upper bound on the number of candidates supplied to docudesk — a large
	 * chart is still capped to a sane payload size; docudesk itself further
	 * caps ranked suggestions to 3 (its own MAX_SUGGESTIONS).
	 *
	 * @var int
	 */
	private const MAX_CANDIDATES = 200;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR ObjectService pulled lazily.
	 * @param IAppConfig $appConfig App config for the register slug (C3 convention).
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the active, administration-scoped chart-of-accounts candidates
	 * (REQ-GAC-002).
	 *
	 * @param string $administrationId The draft's administration id.
	 *
	 * @return array<int, array{code: string, label: string|null}> The candidate accounts. Empty
	 *                                                             (never an error) when OR is unavailable or the administration has no active
	 *                                                             accounts.
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function activeCandidates(string $administrationId): array {
		if ($administrationId === '') {
			return [];
		}

		try {
			$rows = $this->activeAccounts(administrationId: $administrationId);
		} catch (Throwable $e) {
			$context = ['administrationId' => $administrationId, 'exception' => $e->getMessage()];
			$this->logger->info('ChartOfAccountsCandidateService: OR query unavailable — no candidates supplied', $context);
			return [];
		}

		return $this->candidatesOf(rows: $rows);
	}//end activeCandidates()

	/**
	 * The administration's active accounts, capped at MAX_CANDIDATES.
	 *
	 * @param string $administrationId The draft's administration id.
	 *
	 * @return mixed The findAll() result.
	 */
	private function activeAccounts(string $administrationId): mixed {
		$query = [
			'filters' => ['administrationId' => $administrationId, 'lifecycleState' => self::ACTIVE_STATE],
			'limit' => self::MAX_CANDIDATES,
		];

		return $this->container->get('OCA\OpenRegister\Service\ObjectService')
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA_ACCOUNT)
			->findAll($query);
	}//end activeAccounts()

	/**
	 * The candidates among the rows: account rows carrying an account number.
	 *
	 * @param mixed $rows The findAll() result.
	 *
	 * @return array<int, array{code: string, label: mixed}>
	 */
	private function candidatesOf(mixed $rows): array {
		if (is_array($rows) === false) {
			return [];
		}

		$candidates = [];
		foreach ($rows as $row) {
			$code = $this->codeOf(row: $row);
			if ($code !== '') {
				$candidates[] = ['code' => $code, 'label' => ($row['name'] ?? null)];
			}
		}

		return $candidates;
	}//end candidatesOf()

	/**
	 * A row's trimmed account number, or '' for a non-account row.
	 *
	 * @param mixed $row One findAll() row.
	 *
	 * @return string
	 */
	private function codeOf(mixed $row): string {
		if (is_array($row) === false) {
			return '';
		}

		return trim((string)($row['accountNumber'] ?? ''));
	}//end codeOf()

	/**
	 * Resolve the configured register slug, falling back to 'shillinq'
	 * (mirrors {@see \OCA\Shillinq\Guard\AccountBalanceGuard::getRegisterSlug()}).
	 *
	 * @return string
	 */
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end registerSlug()
}//end class
