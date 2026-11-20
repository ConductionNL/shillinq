<?php

/**
 * EU Declaration Builder
 *
 * Bundles an EU project's expenditure of a period into a declaration
 * (public-sector-grant-accountability design D3). An expenditure is included
 * when it is declared (state gedeclareerd), its eligibility is confirmed, it
 * has at least one certified supporting document and its evidence is complete
 * as EuExpenditureGuard::canSubmit judges it. Every other expenditure of the
 * project in the period is listed as left out, with the reason. The
 * declaration carries the eligible total and the EU share, summed in cents
 * (REQ-EUF-012).
 *
 * An open irregularity on the project (initial or in follow-up) refuses a new
 * declaration until its correction is booked (REQ-EUF-007). Submitting the
 * declaration runs `submit` on each included expenditure, after checking
 * every one of them first, so a declaration is submitted whole or not at all
 * (REQ-EUF-013).
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
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DateTimeImmutable;
use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\Contracts\EuDeclarationBuilderInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Lifecycle\EuExpenditureGuard;
use OCA\Shillinq\Support\RegisterSlug;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;

/**
 * Proposes and submits the declaration of an EU project's expenditure.
 *
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */
class EuDeclarationBuilder implements EuDeclarationBuilderInterface {

	/**
	 * Irregularity states that still block a new declaration.
	 */
	public const OPEN_IRREGULARITY_STATES = ['initieel', 'vervolgcontrole'];

	/**
	 * Declaration states that hold their expenditure.
	 */
	private const HOLDING_STATES = ['draft', 'submitted', 'paid'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                      $appConfig     App config for the register slug.
	 * @param ObjectServiceInterface          $objectService OpenRegister's object service.
	 * @param EuExpenditureGuard              $guard         Whether an expenditure's evidence is complete.
	 * @param ObjectTransitionRunnerInterface $transitions   Runs the expenditures' submit transition.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly EuExpenditureGuard $guard,
		private readonly ObjectTransitionRunnerInterface $transitions,
	) {
	}//end __construct()

	/**
	 * Propose and store the draft declaration of a project for a period.
	 *
	 * @param array<string,mixed> $project    The EuProject, with its id.
	 * @param string              $periodFrom The first day of the period (Y-m-d).
	 * @param string              $periodTo   The last day of the period (Y-m-d).
	 *
	 * @return array<string,mixed> The stored draft EuDeclaration, with its id.
	 *
	 * @throws DomainException When the period is invalid or an open irregularity blocks the project.
	 *
	 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md#requirement-a-declaration-bundles-eligible-expenditure-with-evidence-req-euf-012
	 */
	public function propose(array $project, string $periodFrom, string $periodTo): array {
		$projectId = (string)($project['id'] ?? '');
		if ($projectId === '' || self::isDate(day: $periodFrom) === false || self::isDate(day: $periodTo) === false || $periodFrom > $periodTo) {
			throw new DomainException('Choose the project and the period of the declaration as two dates, the first on or before the second.');
		}

		foreach ($this->rows(schema: 'IrregularityReport', filters: ['euProjectId' => $projectId]) as $irregularity) {
			if (in_array((string)($irregularity['state'] ?? ''), self::OPEN_IRREGULARITY_STATES, true) === true) {
				throw new DomainException('An open irregularity on this project blocks new declarations until its correction is booked.');
			}
		}

		$held = $this->heldExpenditure(projectId: $projectId);
		$rate = (float)($project['euCoFundingRate'] ?? 0);
		$included = [];
		$leftOut = [];
		$eligibleCents = 0;
		$euCents = 0;
		foreach ($this->rows(schema: 'EuExpenditure', filters: ['euProjectId' => $projectId]) as $expenditure) {
			$id = (string)($expenditure['id'] ?? '');
			if ($id === '' || isset($held[$id]) === true || self::inPeriod(expenditure: $expenditure, from: $periodFrom, to: $periodTo) === false) {
				continue;
			}

			$reason = $this->reasonLeftOut(expenditure: $expenditure);
			if ($reason === null && in_array((string)($expenditure['state'] ?? ''), ['posted', 'gedeclareerd'], true) === false) {
				continue;
			}

			if ($reason !== null) {
				$leftOut[] = ['expenditureId' => $id, 'description' => (string)($expenditure['description'] ?? ''), 'reason' => $reason];
				continue;
			}

			$declared = self::cents(value: ($expenditure['declaredAmount'] ?? $expenditure['grossAmount'] ?? 0));
			$included[] = $id;
			$eligibleCents += $declared;
			$euCents += self::euCents(expenditure: $expenditure, declaredCents: $declared, rate: $rate);
		}

		$declaration = [
			'administrationId' => (string)($project['administrationId'] ?? ''),
			'euProjectId'      => $projectId,
			'periodFrom'       => $periodFrom,
			'periodTo'         => $periodTo,
			'expenditureIds'   => $included,
			'leftOut'          => $leftOut,
			'eligibleTotal'    => round($eligibleCents / 100, 2),
			'euShare'          => round($euCents / 100, 2),
			'state'            => 'draft',
		];
		$declaration['id'] = $this->save(schema: 'EuDeclaration', object: $declaration);

		return $declaration;

	}//end propose()

	/**
	 * Submit every expenditure of a declaration being submitted.
	 *
	 * @param array<string,mixed> $declaration The EuDeclaration as the submit transition leaves it.
	 *
	 * @return array<string,mixed> The declaration with submittedAt set.
	 *
	 * @throws DomainException When an expenditure of the declaration can no longer be submitted.
	 *
	 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md#requirement-submitting-a-declaration-submits-its-expenditures-req-euf-013
	 */
	public function submit(array $declaration): array {
		$ids = array_values(array_filter(array_map('strval', (array)($declaration['expenditureIds'] ?? [])), static fn (string $id): bool => $id !== ''));
		if ($ids === []) {
			throw new DomainException('The declaration holds no expenditure to submit.');
		}

		$scoped = $this->objectService->setRegister($this->register())->setSchema('EuExpenditure');
		$blocked = [];
		foreach ($ids as $id) {
			$expenditure = ObjectIdentifier::findOneStrict(scoped: $scoped, id: $id);
			if ($expenditure === null
				|| (string)($expenditure['state'] ?? '') !== 'gedeclareerd'
				|| $this->guard->canSubmit(euExpenditureId: $id, object: $expenditure) === false
			) {
				$blocked[] = $id;
			}
		}

		if ($blocked !== []) {
			throw new DomainException('These expenditures can no longer be submitted, so nothing was submitted: ' . implode(', ', $blocked) . '.');
		}

		foreach ($ids as $id) {
			$this->transitions->run(objectId: $id, action: 'submit');
		}

		$declaration['submittedAt'] = (new DateTimeImmutable('now'))->format(DATE_ATOM);
		return $declaration;

	}//end submit()

	/**
	 * Why an expenditure stays out of the declaration, or null when it goes in.
	 *
	 * @param array<string,mixed> $expenditure The EuExpenditure.
	 *
	 * @return string|null
	 */
	private function reasonLeftOut(array $expenditure): ?string {
		$state = (string)($expenditure['state'] ?? '');
		if ($state === 'posted') {
			return 'Not declared yet: declare the expenditure first.';
		}

		if ($state !== 'gedeclareerd') {
			return null;
		}

		if (($expenditure['eligibilityConfirmed'] ?? false) !== true) {
			return 'Its eligibility is not confirmed.';
		}

		$id = (string)$expenditure['id'];
		$certified = array_filter(
			$this->rows(schema: 'SupportingDocument', filters: ['euExpenditureId' => $id]),
			static fn (array $document): bool => (string)($document['state'] ?? '') === 'certified'
		);
		if ($certified === []) {
			return 'Its evidence is missing: no certified supporting document.';
		}

		if ($this->guard->canSubmit(euExpenditureId: $id, object: $expenditure) === false) {
			return 'Its evidence is incomplete: a required supporting document or the procurement file is missing.';
		}

		return null;

	}//end reasonLeftOut()

	/**
	 * The expenditure another declaration of the project already holds.
	 *
	 * @param string $projectId The project.
	 *
	 * @return array<string,true>
	 */
	private function heldExpenditure(string $projectId): array {
		$held = [];
		foreach ($this->rows(schema: 'EuDeclaration', filters: ['euProjectId' => $projectId]) as $declaration) {
			if (in_array((string)($declaration['state'] ?? ''), self::HOLDING_STATES, true) === false) {
				continue;
			}

			foreach ((array)($declaration['expenditureIds'] ?? []) as $id) {
				$held[(string)$id] = true;
			}
		}

		return $held;

	}//end heldExpenditure()

	/**
	 * Whether an expenditure's declaration quarter starts within the period.
	 *
	 * @param array<string,mixed> $expenditure The EuExpenditure, with declarationPeriod such as 2026-Q4.
	 * @param string              $from        The first day of the period.
	 * @param string              $to          The last day of the period.
	 *
	 * @return bool
	 */
	private static function inPeriod(array $expenditure, string $from, string $to): bool {
		if (preg_match('/^(\d{4})-Q([1-4])$/', (string)($expenditure['declarationPeriod'] ?? ''), $match) !== 1) {
			return false;
		}

		$start = sprintf('%s-%02d-01', $match[1], ((((int)$match[2]) - 1) * 3) + 1);
		return $start >= $from && $start <= $to;

	}//end inPeriod()

	/**
	 * The EU share of an expenditure: its recorded co-funding, else the declared amount at the project's rate.
	 *
	 * @param array<string,mixed> $expenditure   The EuExpenditure.
	 * @param int                 $declaredCents Its declared amount in cents.
	 * @param float               $rate          The project's co-funding rate as a fraction.
	 *
	 * @return int Cents.
	 */
	private static function euCents(array $expenditure, int $declaredCents, float $rate): int {
		if (is_numeric($expenditure['euCoFundingAmount'] ?? null) === true) {
			return self::cents(value: $expenditure['euCoFundingAmount']);
		}

		return (int)round($declaredCents * $rate);

	}//end euCents()

	/**
	 * Whether a value is a calendar date in Y-m-d.
	 *
	 * @param string $day The value.
	 *
	 * @return bool
	 */
	private static function isDate(string $day): bool {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
		return $date !== false && $date->format('Y-m-d') === $day;

	}//end isDate()

	/**
	 * Rows of a schema as arrays.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$found = $this->objectService->setRegister($this->register())->setSchema($schema)->findAll(['filters' => $filters]);
		$rows = [];
		foreach ($found as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$rows[] = $row;
		}

		return $rows;

	}//end rows()

	/**
	 * Create a record and return its id.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The record.
	 *
	 * @return string
	 *
	 * @throws DomainException When the store returns no id.
	 */
	private function save(string $schema, array $object): string {
		$saved = $this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $this->register(), schema: $schema))
		);
		$id = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			throw new DomainException('The declaration could not be written.');
		}

		return $id;

	}//end save()

	/**
	 * Euros to integer cents.
	 *
	 * @param mixed $value The amount in euros.
	 *
	 * @return int
	 */
	private static function cents(mixed $value): int {
		return (int)round(((float)$value) * 100);

	}//end cents()

	/**
	 * The configured register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		return RegisterSlug::of(config: $this->appConfig);

	}//end register()
}//end class
