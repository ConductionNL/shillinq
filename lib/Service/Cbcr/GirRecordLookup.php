<?php

/**
 * Shillinq GloBE information return record lookup
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

/**
 * Reads what a GloBE information return needs from its records: an entity's
 * TIN, residence and name, a jurisdiction's passed test, its computation and
 * its resident entities (design D5). Shared by the gap list and the builder,
 * so both read the records the same way.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */
final class GirRecordLookup {

	/**
	 * An entity's TIN as the GIR asks it: a Dutch RSIN padded to nine digits with issuer NL and type GIR3001.
	 *
	 * @param array<string,mixed> $entity The GroupEntityRegistry row.
	 *
	 * @return array{value:string,issuedBy:string,type:string} `type` is '' for a foreign TIN.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function tinOf(array $entity): array {
		$tin      = trim((string)($entity['tin'] ?? ''));
		$issuedBy = strtoupper(trim((string)($entity['tinIssuedBy'] ?? '')));
		if ($issuedBy === '') {
			$issuedBy = $this->jurisdictionOf(entity: $entity);
		}

		if ($tin === '') {
			return ['value' => GirXmlDocument::NO_TIN, 'issuedBy' => $issuedBy, 'type' => ''];
		}

		if ($issuedBy === 'NL') {
			$digits = (string)preg_replace('/\D/', '', $tin);
			if ($digits !== '' && strlen($digits) <= 9) {
				return ['value' => str_pad($digits, 9, '0', STR_PAD_LEFT), 'issuedBy' => 'NL', 'type' => GirXmlDocument::DUTCH_TIN_TYPE];
			}
		}

		return ['value' => $tin, 'issuedBy' => $issuedBy, 'type' => ''];
	}//end tinOf()

	/**
	 * The jurisdiction an entity is resident in: its tax residency, else its jurisdiction.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function jurisdictionOf(array $entity): string {
		$code = trim((string)($entity['taxResidency'] ?? ''));
		if ($code === '') {
			$code = trim((string)($entity['jurisdiction'] ?? ''));
		}

		return strtoupper($code);
	}//end jurisdictionOf()

	/**
	 * The test a jurisdiction passed, null when it passed none.
	 *
	 * @param array<string,mixed> $records The records.
	 * @param string              $code    The jurisdiction.
	 *
	 * @return string|null The test applied.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function passedTest(array $records, string $code): ?string {
		foreach ((array)($records['safeHarbours'] ?? []) as $test) {
			$test = (array)$test;
			if (strtoupper((string)($test['jurisdiction'] ?? '')) === $code && (string)($test['testResult'] ?? '') === 'pass') {
				return (string)($test['testApplied'] ?? '');
			}
		}

		return null;
	}//end passedTest()

	/**
	 * A jurisdiction's computation, null when there is none.
	 *
	 * @param array<string,mixed> $records The records.
	 * @param string              $code    The jurisdiction.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function computationOf(array $records, string $code): ?array {
		foreach ((array)($records['computations'] ?? []) as $computation) {
			if (strtoupper((string)(((array)$computation)['jurisdiction'] ?? '')) === $code) {
				return (array)$computation;
			}
		}

		return null;
	}//end computationOf()

	/**
	 * The entities resident in a jurisdiction.
	 *
	 * @param array<int,mixed> $entities The entities.
	 * @param string           $code     The jurisdiction.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function residentsOf(array $entities, string $code): array {
		$residents = [];
		foreach ($entities as $entity) {
			if ($this->jurisdictionOf(entity: (array)$entity) === $code) {
				$residents[] = (array)$entity;
			}
		}

		return $residents;
	}//end residentsOf()

	/**
	 * An entity's name, its id when it has none.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function nameOf(array $entity): string {
		$name = trim((string)($entity['entityName'] ?? ''));
		if ($name === '') {
			$name = (string)($entity['id'] ?? '');
		}

		return $name;
	}//end nameOf()
}//end class
