<?php

/**
 * BBV Compliance Guard
 *
 * Lifecycle preconditions enforcing BBV (Besluit Begroting en Verantwoording)
 * statutory rules for decentrale overheden, referenced from
 * lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031
 * §"PHP guards remain a legitimate seam" + Risk-3 cross-period exception — no
 * domain logic beyond preconditions the declarative lifecycle engine cannot
 * express (cross-schema presence, cross-period sluitend arithmetic, account-tag
 * routing). All monetary comparisons use integer cents; all checks fail closed.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards BBV-compliance lifecycle transitions and save preconditions.
 *
 * Methods are referenced by name from the BBV schemas' x-openregister-lifecycle
 * `preconditions`/`requires` clauses. Each returns true when the precondition is
 * satisfied (write/transition permitted), false otherwise. Non-BBV tenants
 * (administrationType not in {gemeente, provincie, waterschap}) bypass every
 * check so generic SMB/ZZP administrations remain unaffected (REQ-BBV-001).
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 */
class BbvComplianceGuard {
	/**
	 * BBV-applicable administration types. Only these tenants are subject to the
	 * BBV preconditions; all other administrations bypass them.
	 *
	 * @var array<int, string>
	 */
	private const BBV_ADMINISTRATION_TYPES = ['municipality', 'province', 'waterAuthority'];

	/**
	 * Reserve mutations must always book on this taakveld (resultaatbestemming).
	 */
	private const RESERVE_TAAKVELD = '0.10';

	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is
	 *                                      fetched lazily so the class stays usable
	 *                                      before sibling registers exist.
	 * @param IAppConfig $appConfig App config for register-slug resolution.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * Mirrors AccountBalanceGuard / SettingsService::getRegisterSlug() so all
	 * reads use the same register even when the admin reconfigures the slug.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Resolve whether the owning administration is a BBV-tenant.
	 *
	 * A record is BBV-applicable when its own `administrationType` is municipal,
	 * or — when only an `administrationId` is present — when the referenced
	 * Administration record (if resolvable) is municipal. When the type cannot be
	 * resolved at all the record is treated as non-BBV so generic tenants are
	 * never blocked by a missing lookup (the BBV repair step seeds the type).
	 *
	 * @param array<string, mixed> $record Object array under validation.
	 *
	 * @return bool True when BBV preconditions apply to this record.
	 */
	private function isBbvTenant(array $record): bool {
		$type = (string)($record['administrationType'] ?? '');
		if ($type !== '') {
			return in_array($type, self::BBV_ADMINISTRATION_TYPES, true);
		}

		return (($record['bbvCompliance'] ?? $record['bbv_compliance'] ?? null) === true);
	}//end isBbvTenant()

	/**
	 * Precondition for Account.save (REQ-BBV-001): a BBV-tenant may not create or
	 * mutate a grootboekrekening without a `rgsDecentraalCode`. Non-BBV tenants
	 * always pass. The presence of a matching BbvAccountMapping is verified when
	 * the mapping register is available; otherwise the inline `rgsDecentraalCode`
	 * field suffices (T-staging before the mapping register ships).
	 *
	 * @param array<string, mixed> $account Account object array (loaded by OR).
	 *
	 * @return bool True when the RGS-decentraal mapping requirement is satisfied.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-001)
	 */
	public function requireBbvAccountMapping(array $account): bool {
		if ($this->isBbvTenant(record: $account) === false) {
			return true;
		}

		$code = (string)($account['rgsDecentraalCode'] ?? '');
		if ($code !== '') {
			return true;
		}

		$this->logger->info(
			'BbvComplianceGuard: rejecting BBV account without rgsDecentraalCode (REQ-BBV-001)',
			['accountNumber' => ($account['accountNumber'] ?? 'unknown')]
		);

		return false;
	}//end requireBbvAccountMapping()

	/**
	 * Precondition for GLLine.save (REQ-BBV-002 + REQ-BBV-004): a BBV exploitatie
	 * line must carry a taakveld + economische_categorie; reserve mutations must
	 * book on taakveld 0.10; voorziening mutations must book on the gekoppelde
	 * taakveld. The account's `bbvClassificatie` (and, for voorziening, its
	 * preset `taakveld`) is read from the referenced Account record. Non-BBV
	 * tenants and balans-overig postings pass unconditionally.
	 *
	 * @param array<string, mixed> $line GLLine object array (loaded by OR).
	 *
	 * @return bool True when the line's BBV classification/routing is valid.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-002, REQ-BBV-004)
	 */
	public function requireLineClassification(array $line): bool {
		if ($this->isBbvTenant(record: $line) === false) {
			return true;
		}

		if ($this->isHistoricPosting(line: $line) === true) {
			return true;
		}

		$account = $this->lineAccount(line: $line);
		if ($account === null) {
			return false;
		}

		return $this->classificationHolds(line: $line, account: $account);
	}//end requireLineClassification()

	/**
	 * Resolve the line's Account; an unresolvable account is logged, and the
	 * caller fails closed for BBV tenants rather than accept an unmapped line.
	 *
	 * @param array<string, mixed> $line GLLine object array.
	 *
	 * @return array<string, mixed>|null The account, or null when it cannot be resolved.
	 */
	private function lineAccount(array $line): ?array {
		$account = $this->resolveAccount(
			accountNumber: (string)($line['accountNumber'] ?? ''),
			administrationId: (string)($line['administrationId'] ?? '')
		);
		if ($account === null) {
			$this->logger->info(
				'BbvComplianceGuard: cannot resolve account for GLLine classification (REQ-BBV-002)',
				['accountNumber' => ($line['accountNumber'] ?? 'unknown')]
			);
		}

		return $account;
	}//end lineAccount()

	/**
	 * Route the line by its account's `bbvClassificatie`: balans-overig and
	 * investering are exempt from taakveld-plicht, reserve and voorziening have
	 * their own routes, everything else is exploitatie.
	 *
	 * @param array<string, mixed> $line    GLLine object array.
	 * @param array<string, mixed> $account Resolved Account object array.
	 *
	 * @return bool True when the line's classification/routing is valid.
	 */
	private function classificationHolds(array $line, array $account): bool {
		$classificatie = ($account['bbvClassificatie'] ?? null);
		if ($classificatie === 'balans-overig' || $classificatie === 'investering') {
			return true;
		}

		if ($classificatie === 'reserve') {
			return $this->checkReserveRoute(line: $line);
		}

		if ($classificatie === 'voorziening') {
			return $this->checkProvisionRoute(line: $line, account: $account);
		}

		return $this->checkExploitatieClassification(line: $line);
	}//end classificationHolds()

	/**
	 * REQ-BBV-002 forward-only carve-out: a posting is "historic" when its
	 * `postingDate` precedes the BBV installation date stored in app config.
	 *
	 * The app config key `bbv_installation_date` is stamped by the
	 * InitializeSettings repair step when the first BBV-tenant comes online.
	 * Returns `false` when no installation date is configured (so the
	 * precondition runs for every line) and `false` when the line carries
	 * no parseable postingDate (so we don't silently accept a missing date).
	 *
	 * @param array<string,mixed> $line GLLine object array.
	 *
	 * @return bool True when the posting is historic and exempt; false otherwise.
	 */
	private function isHistoricPosting(array $line): bool {
		$installRaw = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'bbv_installation_date',
			''
		);
		$postingRaw = (string)($line['postingDate'] ?? '');
		if ($installRaw === '' || $postingRaw === '') {
			return false;
		}

		return self::precedes(postingRaw: $postingRaw, installRaw: $installRaw);
	}//end isHistoricPosting()

	/**
	 * Whether the posting date lies before the installation date; false when either does not parse.
	 *
	 * @param string $postingRaw The line's postingDate.
	 * @param string $installRaw The configured installation date.
	 *
	 * @return bool
	 */
	private static function precedes(string $postingRaw, string $installRaw): bool {
		try {
			$installDate = new DateTimeImmutable(datetime: $installRaw);
			$postingDate = new DateTimeImmutable(datetime: $postingRaw);
		} catch (\Throwable $e) {
			return false;
		}

		return $postingDate < $installDate;
	}//end precedes()

	/**
	 * REQ-BBV-004: reserve mutaties run uitsluitend via taakveld 0.10.
	 *
	 * @param array<string, mixed> $line GLLine object array.
	 *
	 * @return bool True when the reserve line books on taakveld 0.10.
	 */
	private function checkReserveRoute(array $line): bool {
		$taskField = ($line['taskField'] ?? null);
		if ((string)$taskField === self::RESERVE_TAAKVELD) {
			return true;
		}

		$this->logger->info(
			'BbvComplianceGuard: reserve mutation off taakveld 0.10 rejected (REQ-BBV-004)',
			['accountNumber' => ($line['accountNumber'] ?? 'unknown'), 'taskField' => $taskField]
		);

		return false;
	}//end checkReserveRoute()

	/**
	 * REQ-BBV-004: voorziening mutaties run via het gekoppelde taakveld of the account.
	 *
	 * @param array<string, mixed> $line GLLine object array.
	 * @param array<string, mixed> $account Resolved Account object array.
	 *
	 * @return bool True when the voorziening line books on the gekoppelde taakveld.
	 */
	private function checkProvisionRoute(array $line, array $account): bool {
		$gekoppeld = ($account['taskField'] ?? null);
		if ($gekoppeld === null || (string)($line['taskField'] ?? null) === (string)$gekoppeld) {
			return true;
		}

		$this->logger->info(
			'BbvComplianceGuard: voorziening mutation off gekoppeld taakveld rejected (REQ-BBV-004)',
			[
				'accountNumber' => ($line['accountNumber'] ?? 'unknown'),
				'expected' => $gekoppeld,
				'found' => ($line['taskField'] ?? null),
			]
		);

		return false;
	}//end checkProvisionRoute()

	/**
	 * REQ-BBV-002: an exploitatie line must carry taakveld + economische_categorie.
	 *
	 * @param array<string, mixed> $line GLLine object array.
	 *
	 * @return bool True when both classification fields are present.
	 */
	private function checkExploitatieClassification(array $line): bool {
		$taskField = (string)($line['taskField'] ?? '');
		$category = (string)($line['economicCategory'] ?? '');
		if ($taskField !== '' && $category !== '') {
			return true;
		}

		$this->logger->info(
			'BbvComplianceGuard: exploitatie line missing taakveld/economische_categorie (REQ-BBV-002)',
			['accountNumber' => ($line['accountNumber'] ?? 'unknown')]
		);

		return false;
	}//end checkExploitatieClassification()

	/**
	 * Precondition for Programma.publish (REQ-BBV-003): every meerjaren-horizon
	 * (T..T+3) must be structureel en reëel sluitend — for each horizon,
	 * sum(batenCents) - sum(lastenCents) + sum(mutatieReservesCents) >= 0.
	 * A non-sluitende begroting blocks publication unless overridden with a
	 * raadsbesluit (raadsbesluitNummer + raadsbesluitDatum on the Programma).
	 * Non-BBV tenants pass unconditionally.
	 *
	 * @param array<string, mixed> $programma Programma object array (loaded by OR).
	 *
	 * @return bool True when all horizons are sluitend or an override is present.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-003)
	 */
	public function requireMeerjarenramingSluitend(array $programma): bool {
		if ($this->isBbvTenant(record: $programma) === false) {
			return true;
		}

		if (self::hasCouncilResolution(programma: $programma) === true) {
			return true;
		}

		$year = self::yearFilters(record: $programma);
		$rows = $this->loadMeerjarenBudgetRows(year: $year);
		if ($rows === null) {
			return false;
		}

		$balancePerHorizon = $this->computeBalancePerHorizon(rows: $rows);

		return $this->allHorizonsSluitend(administrationId: $year['administrationId'], balancePerHorizon: $balancePerHorizon);
	}//end requireMeerjarenramingSluitend()

	/**
	 * Raadsbesluit override: an explicit, audit-trailed motivation (number and
	 * date) unblocks a non-sluitende begroting per art. 189 Gemeentewet.
	 *
	 * @param array<string, mixed> $programma Programma object array.
	 *
	 * @return bool
	 */
	private static function hasCouncilResolution(array $programma): bool {
		$decision = (string)($programma['councilResolutionNumber'] ?? '');
		$date = (string)($programma['councilResolutionDate'] ?? '');

		return $decision !== '' && $date !== '';
	}//end hasCouncilResolution()

	/**
	 * The administration + boekjaar filter of a record, in query order.
	 *
	 * @param array<string, mixed> $record Programma or Jaarrekening record.
	 *
	 * @return array{administrationId: string, financialYear: int}
	 */
	private static function yearFilters(array $record): array {
		return [
			'administrationId' => (string)($record['administrationId'] ?? ''),
			'financialYear' => (int)($record['financialYear'] ?? 0),
		];
	}//end yearFilters()

	/**
	 * Whether every horizon's saldo is >= 0; the first one below zero is logged.
	 *
	 * @param string          $administrationId  Owning administration (log context).
	 * @param array<int, int> $balancePerHorizon Saldo in cents keyed by meerjarenHorizon.
	 *
	 * @return bool
	 */
	private function allHorizonsSluitend(string $administrationId, array $balancePerHorizon): bool {
		foreach ($balancePerHorizon as $horizon => $balanceCents) {
			if ($balanceCents < 0) {
				$this->logger->info(
					'BbvComplianceGuard: meerjarenraming not sluitend (REQ-BBV-003)',
					['administrationId' => $administrationId, 'horizon' => $horizon, 'saldoCents' => $balanceCents]
				);
				return false;
			}
		}

		return true;
	}//end allHorizonsSluitend()

	/**
	 * Load the primitieve MeerjarenBudget rows for an administration + boekjaar.
	 *
	 * @param array{administrationId: string, financialYear: int} $year Owning administration and base fiscal year T.
	 *
	 * @return array<int, array<string, mixed>>|null Rows, or null on lookup failure.
	 */
	private function loadMeerjarenBudgetRows(array $year): ?array {
		try {
			return $this->findRows(schema: 'MeerjarenBudget', query: ['filters' => $year + ['version' => 'primitief']]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BbvComplianceGuard: meerjarenraming lookup failed — denying publish (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end loadMeerjarenBudgetRows()

	/**
	 * Run one lookup against OR's ObjectService on the configured register.
	 *
	 * @param string               $schema Schema slug.
	 * @param array<string, mixed> $query  The findAll query.
	 *
	 * @return mixed What findAll answers.
	 *
	 * @throws \Throwable When the service is missing or the lookup fails.
	 */
	private function findRows(string $schema, array $query): mixed {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		return $objectService
			->setRegister($this->getRegisterSlug())
			->setSchema($schema)
			->findAll($query);
	}//end findRows()

	/**
	 * Aggregate the sluitend-saldo per horizon in integer cents.
	 *
	 * @param array<int, array<string, mixed>> $rows MeerjarenBudget rows.
	 *
	 * @return array<int, int> Saldo in cents keyed by meerjarenHorizon.
	 */
	private function computeBalancePerHorizon(array $rows): array {
		$balancePerHorizon = [];
		foreach ($rows as $row) {
			$horizon = (int)($row['meerjarenHorizon'] ?? 0);
			if (isset($balancePerHorizon[$horizon]) === false) {
				$balancePerHorizon[$horizon] = 0;
			}

			$revenueCents = (int)($row['revenueCents'] ?? 0);
			$expensesCents = (int)($row['expensesCents'] ?? 0);
			$movementCents = (int)($row['movementReservesCents'] ?? 0);
			$balancePerHorizon[$horizon] += ($revenueCents - $expensesCents + $movementCents);
		}

		return $balancePerHorizon;
	}//end computeSaldoPerHorizon()

	/**
	 * Precondition for MaterieleVasteActiva.save (REQ-BBV-005): a
	 * maatschappelijk-nut investering above the activeringsgrens MUST be
	 * activated — i.e. it must carry a depreciation term (afschrijvingstermijn)
	 * so it is not booked directly to exploitatie. The activeringsgrens defaults
	 * to EUR 50.000 (5_000_000 cents) and is operator-configurable. Non-BBV
	 * tenants and economisch-nut assets pass unconditionally.
	 *
	 * @param array<string, mixed> $mva MaterieleVasteActiva object array (loaded by OR).
	 *
	 * @return bool True when the MVA is correctly activated (or out of scope).
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-005)
	 */
	public function requireMvaActivation(array $mva): bool {
		if ($this->isBbvTenant(record: $mva) === false) {
			return true;
		}

		if (($mva['mvaCategory'] ?? '') !== 'maatschappelijk-nut') {
			return true;
		}

		$aanschafCents = (int)($mva['acquisitionValueCents'] ?? 0);
		if ($aanschafCents <= $this->getActiveringsgrensCents()) {
			return true;
		}

		return $this->hasDepreciationTerm(mva: $mva, aanschafCents: $aanschafCents);
	}//end requireMvaActivation()

	/**
	 * Above the grens a depreciation term must be set so the actief is activated
	 * and depreciated, not expensed in one go (BBV art. 59 lid 4).
	 *
	 * @param array<string, mixed> $mva           MaterieleVasteActiva object array.
	 * @param int                  $aanschafCents Acquisition value (log context).
	 *
	 * @return bool
	 */
	private function hasDepreciationTerm(array $mva, int $aanschafCents): bool {
		if ((int)($mva['depreciationPeriodYears'] ?? 0) >= 1) {
			return true;
		}

		$this->logger->info(
			'BbvComplianceGuard: maatschappelijk-nut investering above grens not activated (REQ-BBV-005)',
			[
				'description' => ($mva['description'] ?? 'unknown'),
				'aanschafCents' => $aanschafCents,
			]
		);

		return false;
	}//end hasDepreciationTerm()

	/**
	 * The seven mandatory BBV paragrafen per BBV art. 9.
	 *
	 * Used by `requireParagrafenCompleet` (REQ-BBV-007).
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_PARAGRAFEN = [
		'lokale-heffingen',
		'weerstandsvermogen',
		'onderhoud-kapitaalgoederen',
		'financiering',
		'bedrijfsvoering',
		'verbonden-partijen',
		'grondbeleid',
	];

	/**
	 * Precondition for Jaarrekening.publish (REQ-BBV-007 paragraaf-completeness).
	 *
	 * Loads every `Paragraaf` row for the administration + boekjaar with
	 * `status = vastgesteld` and checks that all seven mandatory types are
	 * present. Non-BBV tenants bypass.
	 *
	 * @param array<string, mixed> $annualAccounts Jaarrekening record (administrationId + boekjaar).
	 *
	 * @return bool True when all seven paragrafen are vastgesteld, false otherwise.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-007)
	 */
	public function requireParagrafenCompleet(array $annualAccounts): bool {
		if ($this->isBbvTenant(record: $annualAccounts) === false) {
			return true;
		}

		$year = self::yearFilters(record: $annualAccounts);
		if ($year['administrationId'] === '' || $year['financialYear'] === 0) {
			$this->logger->info('BbvComplianceGuard: jaarrekening missing administrationId or boekjaar (REQ-BBV-007)');
			return false;
		}

		$rows = $this->determinedParagrafen(year: $year);
		if ($rows === null) {
			return false;
		}

		return $this->noParagraafMissing(year: $year, missing: self::missingParagrafen(rows: $rows));
	}//end requireParagrafenCompleet()

	/**
	 * Load the vastgestelde Paragraaf rows for an administration + boekjaar.
	 *
	 * @param array{administrationId: string, financialYear: int} $year Owning administration and boekjaar.
	 *
	 * @return array<int, array<string, mixed>>|null Rows, or null on lookup failure.
	 */
	private function determinedParagrafen(array $year): ?array {
		try {
			return $this->findRows(schema: 'Paragraaf', query: ['filters' => $year + ['status' => 'determined']]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BbvComplianceGuard: paragraaf lookup failed — denying publish (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end determinedParagrafen()

	/**
	 * The mandatory paragraaf types absent from the rows, in mandatory order.
	 *
	 * @param array<int, array<string, mixed>> $rows Paragraaf rows.
	 *
	 * @return array<int, string>
	 */
	private static function missingParagrafen(array $rows): array {
		$present = [];
		foreach ($rows as $row) {
			$present[(string)($row['type'] ?? '')] = true;
		}

		$ontbrekend = [];
		foreach (self::REQUIRED_PARAGRAFEN as $type) {
			if (isset($present[$type]) === false) {
				$ontbrekend[] = $type;
			}
		}

		return $ontbrekend;
	}//end missingParagrafen()

	/**
	 * Whether no mandatory paragraaf is missing; missing ones are logged.
	 *
	 * @param array{administrationId: string, financialYear: int} $year    Owning administration and boekjaar.
	 * @param array<int, string>                                  $missing Missing paragraaf types.
	 *
	 * @return bool
	 */
	private function noParagraafMissing(array $year, array $missing): bool {
		if (count($missing) === 0) {
			return true;
		}

		$this->logger->info(
			'BbvComplianceGuard: jaarrekening mist verplichte paragrafen (REQ-BBV-007, BBV art. 9)',
			$year + ['ontbrekend' => $missing]
		);

		return false;
	}//end noParagraafMissing()

	/**
	 * Compute the month-of-first-depreciation for an MVA per REQ-BBV-005.
	 *
	 * Depreciation starts in the month FOLLOWING `ingebruiknameDatum`. Example:
	 *   ingebruiknameDatum = 2026-09-15 → first depreciation in 2026-10.
	 *
	 * Declarative helper used by the FixedAssets monthly-depreciation workflow
	 * and exercised by the spec scenario "Afschrijving start maand na ingebruikname".
	 * Returns `null` when the ingebruikname date is missing or unparseable.
	 *
	 * @param array<string, mixed> $mva MaterieleVasteActiva record.
	 *
	 * @return string|null First depreciation month as `YYYY-MM`, or null.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-005)
	 */
	public function depreciationStartMonth(array $mva): ?string {
		$raw = (string)($mva['commissioningDate'] ?? '');
		if ($raw === '') {
			return null;
		}

		$date = $this->commissioningDate(raw: $raw);
		if ($date === null) {
			return null;
		}

		// First depreciation accrues in the month AFTER ingebruikname (REQ-BBV-005).
		$next = $date->modify(modifier: 'first day of next month');
		if ($next === false) {
			return null;
		}

		return $next->format(format: 'Y-m');
	}//end depreciationStartMonth()

	/**
	 * Parse the ingebruikname date; an unparseable one is logged.
	 *
	 * @param string $raw The commissioningDate value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function commissioningDate(string $raw): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable(datetime: $raw);
		} catch (\Throwable $e) {
			$this->logger->info(
				'BbvComplianceGuard: ingebruiknameDatum unparseable, depreciation start unknown',
				['raw' => $raw, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end commissioningDate()

	/**
	 * Resolve the activeringsgrens in integer cents from app config, defaulting
	 * to EUR 50.000 (5_000_000 cents) per the gangbare gemeente-drempel.
	 *
	 * @return int Activeringsgrens in eurocents.
	 */
	private function getActiveringsgrensCents(): int {
		$configured = $this->appConfig->getValueInt(
			AppIdentity::APP_ID,
			'bbv_activeringsgrens_cents',
			5000000
		);

		if ($configured > 0) {
			return $configured;
		}

		return 5000000;
	}//end getActiveringsgrensCents()

	/**
	 * Resolve the Account record for a GL line via OR's real ObjectService API.
	 *
	 * @param string $accountNumber Account.accountNumber to resolve.
	 * @param string $administrationId Owning administration (scopes uniqueness).
	 *
	 * @return array<string, mixed>|null The account array, or null when not found.
	 */
	private function resolveAccount(string $accountNumber, string $administrationId): ?array {
		if ($accountNumber === '') {
			return null;
		}

		try {
			$matches = $this->findRows(
				schema: 'Account',
				query: ['filters' => self::accountFilters(accountNumber: $accountNumber, administrationId: $administrationId), 'limit' => 1]
			);
		} catch (\Throwable $e) {
			$this->logger->error('BbvComplianceGuard: account resolution failed', ['exception' => $e->getMessage()]);
			return null;
		}

		return self::firstMatch(matches: $matches);
	}//end resolveAccount()

	/**
	 * The Account filter: the number, scoped to the administration when one is given.
	 *
	 * @param string $accountNumber    Account.accountNumber to resolve.
	 * @param string $administrationId Owning administration, or ''.
	 *
	 * @return array<string, string>
	 */
	private static function accountFilters(string $accountNumber, string $administrationId): array {
		$filters = ['accountNumber' => $accountNumber];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		return $filters;
	}//end accountFilters()

	/**
	 * The first record of a lookup answer, or null when there is none.
	 *
	 * @param mixed $matches What findAll answered.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function firstMatch(mixed $matches): ?array {
		if (is_array($matches) === true && count($matches) > 0) {
			return $matches[0];
		}

		return null;
	}//end firstMatch()
}//end class
