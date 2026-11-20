<?php

/**
 * Shillinq CbC Entered Figures Service
 *
 * "Enter figures" and "Import figures" on a group entity whose books are not
 * kept in Shillinq (a foreign subsidiary): its country-by-country figures for
 * a fiscal year are given by hand, or as rows of a file, and stored as the
 * entity's CbcrEntityFigures with source `entered` or `imported`
 * (REQ-CBC-002). The summary per jurisdiction then counts them like figures
 * read from the books.
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Stores entered or imported CbC figures of a group entity.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 */
class CbcrEnteredFiguresService {

	/**
	 * The nine figures of the country-by-country table, each required.
	 */
	public const REQUIRED_FIGURES = [
		'unrelatedPartyRevenue',
		'relatedPartyRevenue',
		'profitBeforeTax',
		'incomeTaxPaidCash',
		'incomeTaxAccrued',
		'statedCapital',
		'accumulatedEarnings',
		'numberOfEmployees',
		'tangibleAssetsOtherThanCash',
	];

	/**
	 * Figures the minimum tax reads, kept when given.
	 */
	public const OPTIONAL_FIGURES = ['deferredTaxExpense', 'eligiblePayroll'];

	/**
	 * The sources a figure record can be given with here.
	 */
	private const SOURCES = ['entered', 'imported'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     Names the register.
	 * @param IL10N                  $l10n          Translated refusals.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Store the figures of a group entity for a fiscal year, in place of the
	 * figures it has for that year.
	 *
	 * @param array<string,mixed>    $entity     The GroupEntityRegistry record.
	 * @param int                    $fiscalYear The fiscal year.
	 * @param array<string,mixed>    $input      The figures as given, and optionally `currency`.
	 * @param string                 $source     `entered` or `imported`.
	 * @param DateTimeImmutable|null $now        The moment of entry; now when null.
	 *
	 * @return array<string,mixed> The stored CbcrEntityFigures record.
	 *
	 * @throws InvalidArgumentException When the entity keeps its books here, or a figure is missing or malformed.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
	 */
	public function enter(array $entity, int $fiscalYear, array $input, string $source = 'entered', ?DateTimeImmutable $now = null): array {
		$entityId = (string)($entity['id'] ?? '');
		if ($entityId === '' || in_array($source, self::SOURCES, true) === false) {
			throw new InvalidArgumentException($this->l10n->t('These figures cannot be stored.'));
		}

		if (trim((string)($entity['administrationId'] ?? '')) !== '') {
			throw new InvalidArgumentException(
				$this->l10n->t('%s keeps its books in Shillinq; read its figures from the books instead.', [(string)($entity['entityName'] ?? $entityId)])
			);
		}

		$record = [
			'entity' => $entityId,
			'fiscalYear' => $fiscalYear,
			'source' => $source,
			'currency' => $this->currency(value: $input['currency'] ?? null),
		] + $this->figures(input: $input) + [
			'employeeMethod' => $source,
			'unmappedAccounts' => [],
			'computedAt' => ($now ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		$existing = $this->existing(entityId: $entityId, fiscalYear: $fiscalYear);
		if ($existing !== null) {
			$record['id'] = $existing;
		}

		$saved = $this->objectService->setRegister($this->register())->setSchema('CbcrEntityFigures')->saveObject(
			object: $record,
			options: new ObjectSaveOptions(uuid: $existing)
		);

		return (ObjectIdentifier::recordWithId(candidate: $saved) ?? []) + $record;
	}//end enter()

	/**
	 * Read imported figures: one figure per line, its name and its amount,
	 * separated by a comma, semicolon or tab (a spreadsheet saved as CSV). A
	 * first line naming the columns, empty lines and quotes are skipped; a
	 * `currency` line gives the currency.
	 *
	 * @param string $text The file's text.
	 *
	 * @return array<string,string> The figures by name, for enter().
	 *
	 * @throws InvalidArgumentException When a line names no known figure or a figure appears twice.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
	 */
	public function parse(string $text): array {
		$known  = array_merge(self::REQUIRED_FIGURES, self::OPTIONAL_FIGURES, ['currency']);
		$byName = array_combine(array_map('strtolower', $known), $known);
		$input  = [];
		foreach ((array)preg_split('/\R/', $text) as $number => $line) {
			$cells = array_map(static fn (string $cell): string => trim($cell, " \t\"'"), (array)preg_split('/[,;\t]/', (string)$line, 2));
			$name  = strtolower($cells[0] ?? '');
			if ($name === '' || ($number === 0 && in_array($name, ['figure', 'name', 'field', 'cijfer'], true) === true)) {
				continue;
			}

			$field = $byName[$name] ?? null;
			if ($field === null) {
				throw new InvalidArgumentException($this->l10n->t('Line %1$s names no known figure: %2$s.', [(string)($number + 1), $cells[0]]));
			}

			if (array_key_exists($field, $input) === true) {
				throw new InvalidArgumentException($this->l10n->t('The figure %s is given twice.', [$field]));
			}

			$input[$field] = ($cells[1] ?? '');
		}

		return $input;
	}//end parse()

	/**
	 * The figures read from the input: every required one present and a
	 * number, the optional ones when given, the employees a whole number of
	 * at least zero.
	 *
	 * @param array<string,mixed> $input The input.
	 *
	 * @return array<string,float|int>
	 *
	 * @throws InvalidArgumentException When a figure is missing or malformed.
	 */
	private function figures(array $input): array {
		$figures = [];
		foreach (array_merge(self::REQUIRED_FIGURES, self::OPTIONAL_FIGURES) as $field) {
			$value = $input[$field] ?? null;
			if (is_string($value) === true) {
				$value = trim($value);
			}

			if ($value === null || $value === '') {
				if (in_array($field, self::REQUIRED_FIGURES, true) === true) {
					throw new InvalidArgumentException($this->l10n->t('Give the figure %s.', [$field]));
				}

				continue;
			}

			if (is_numeric($value) === false || is_finite((float)$value) === false) {
				throw new InvalidArgumentException($this->l10n->t('The figure %s is not a number.', [$field]));
			}

			$figures[$field] = (float)$value;
		}

		$employees = $figures['numberOfEmployees'];
		if ($employees < 0 || floor($employees) !== $employees) {
			throw new InvalidArgumentException($this->l10n->t('The number of employees is a whole number of at least zero.'));
		}

		$figures['numberOfEmployees'] = (int)$employees;

		return $figures;
	}//end figures()

	/**
	 * The currency of the figures: three letters, EUR when none is given.
	 *
	 * @param mixed $value The currency as given.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the currency is not three letters.
	 */
	private function currency(mixed $value): string {
		$currency = strtoupper(trim((string)($value ?? '')));
		if ($currency === '') {
			return 'EUR';
		}

		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			throw new InvalidArgumentException($this->l10n->t('The currency is a three-letter code, for example EUR.'));
		}

		return $currency;
	}//end currency()

	/**
	 * The id of the entity's figures for the year, or null.
	 *
	 * @param string $entityId   The entity.
	 * @param int    $fiscalYear The fiscal year.
	 *
	 * @return string|null
	 */
	private function existing(string $entityId, int $fiscalYear): ?string {
		$rows = $this->objectService->setRegister($this->register())->setSchema('CbcrEntityFigures')->findAll(
			['filters' => ['entity' => $entityId, 'fiscalYear' => $fiscalYear], 'limit' => 10]
		);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && (string)($record['entity'] ?? '') === $entityId && (int)($record['fiscalYear'] ?? 0) === $fiscalYear) {
				return (string)$record['id'];
			}
		}

		return null;
	}//end existing()

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
