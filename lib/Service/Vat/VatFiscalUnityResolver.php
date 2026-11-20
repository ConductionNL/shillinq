<?php

/**
 * Shillinq VatFiscalUnityResolver
 *
 * Says which VAT fiscal unity covers an administration in a return period,
 * and in what role (REQ-TVRB-003, design D5). A unity covers a period when
 * its dates overlap it: it starts on or before the period's last day and has
 * no end date or ends on or after the period's first day.
 *
 * The representative administration files one return for the unity, over
 * its own lines and those of every member. A member does not file its own
 * return for a period the unity covers.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-5.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Vat;

use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * The VAT fiscal unity of an administration in a period.
 *
 * @spec openspec/changes/tax-vat-return-from-books/specs/bookkeeping-vat-btw-filing/spec.md#requirement-a-vat-fiscal-unity-files-one-return-req-tvrb-003
 */
class VatFiscalUnityResolver {

	/**
	 * The schema of a VAT fiscal unity.
	 */
	public const SCHEMA = 'VatFiscalUnity';

	/**
	 * The administration files for the unity.
	 */
	public const ROLE_REPRESENTATIVE = 'representative';

	/**
	 * The administration is a member and files no return of its own.
	 */
	public const ROLE_MEMBER = 'member';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $register      The register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly string $register,
	) {
	}//end __construct()

	/**
	 * The unity that covers an administration in a period, with its role.
	 *
	 * Read failures are not swallowed: a return prepared while the unities
	 * cannot be read would silently leave out the members' lines, or let a
	 * member file its own return.
	 *
	 * @param string $administrationId The administration.
	 * @param string $startDate        The period's first day (ISO-8601).
	 * @param string $endDate          The period's last day (ISO-8601).
	 *
	 * @return array{unity: array<string,mixed>, role: string, administrationIds: list<string>}|null
	 *         The unity, the administration's role in it and every administration whose lines the
	 *         unity's return reads (the representative first); null when no unity covers it.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-5.1
	 */
	public function covering(string $administrationId, string $startDate, string $endDate): ?array {
		if ($administrationId === '') {
			return null;
		}

		$asMember = null;
		foreach ($this->unities() as $unity) {
			if ($this->covers(unity: $unity, startDate: $startDate, endDate: $endDate) === false) {
				continue;
			}

			$representative = (string)($unity['administrationId'] ?? '');
			$members        = $this->members(unity: $unity);
			if ($representative === $administrationId) {
				return [
					'unity' => $unity,
					'role' => self::ROLE_REPRESENTATIVE,
					'administrationIds' => array_values(array_unique(array_merge([$representative], $members))),
				];
			}

			if ($asMember === null && in_array($administrationId, $members, true) === true) {
				$asMember = [
					'unity' => $unity,
					'role' => self::ROLE_MEMBER,
					'administrationIds' => array_values(array_unique(array_merge([$representative], $members))),
				];
			}
		}//end foreach

		return $asMember;
	}//end covering()

	/**
	 * The name a refusal or a page shows for a unity.
	 *
	 * @param array<string,mixed> $unity The unity.
	 *
	 * @return string Its name, else its VAT number, else its id.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-5.1
	 */
	public static function nameOf(array $unity): string {
		foreach (['name', 'vatNumber', 'id'] as $field) {
			$value = trim((string)($unity[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end nameOf()

	/**
	 * Whether a unity's dates overlap the period.
	 *
	 * @param array<string,mixed> $unity     The unity.
	 * @param string              $startDate The period's first day.
	 * @param string              $endDate   The period's last day.
	 *
	 * @return bool
	 */
	private function covers(array $unity, string $startDate, string $endDate): bool {
		$from = substr((string)($unity['startDate'] ?? ''), 0, 10);
		$to   = substr((string)($unity['endDate'] ?? ''), 0, 10);
		if ($from === '' || $from > $endDate) {
			return false;
		}

		return ($to === '' || $to >= $startDate);
	}//end covers()

	/**
	 * The member administrations of a unity.
	 *
	 * @param array<string,mixed> $unity The unity.
	 *
	 * @return list<string>
	 */
	private function members(array $unity): array {
		$members = [];
		foreach ((array)($unity['memberAdministrationIds'] ?? []) as $member) {
			if (is_string($member) === true && trim($member) !== '') {
				$members[] = trim($member);
			}
		}

		return $members;
	}//end members()

	/**
	 * Every VAT fiscal unity, as arrays.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function unities(): array {
		$rows = $this->objectService->setRegister($this->register)->setSchema(self::SCHEMA)->findAll(['limit' => 500]);

		$unities = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$unities[] = $row;
			}
		}

		return $unities;
	}//end unities()
}//end class
