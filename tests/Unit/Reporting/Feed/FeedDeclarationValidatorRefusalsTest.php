<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reporting-data-delivery REQ-RDD-003: every way a feed declaration can be
 * malformed is named, one sentence per problem, without stopping at the first.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Feed;

// phpcs:disable CustomSniffs.Functions.NamedParameters -- PHPUnit positional assertions.

use OCA\Shillinq\Reporting\Feed\FeedDeclarationValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FeedDeclarationValidator::class)]
class FeedDeclarationValidatorRefusalsTest extends TestCase {

	/**
	 * The schemas the register declares in these tests.
	 */
	private const FIELDS = [
		'Account' => ['code', 'name', 'administrationId'],
		'Period'  => ['start', 'end'],
	];

	/**
	 * A valid dataset with the given overrides.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 *
	 * @return array<string, mixed> The dataset.
	 */
	private static function dataset(array $overrides = []): array {
		return array_merge(
			[
				'key'     => 'accounts',
				'schema'  => 'Account',
				'binding' => ['administrationId' => FeedDeclarationValidator::REQUIRED_BINDING],
				'fields'  => ['code', 'name'],
			],
			$overrides
		);

	}//end dataset()

	/**
	 * Declarations without datasets.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function emptyDeclarations(): array {
		return [
			'no datasets member' => [[]],
			'empty list'         => [['datasets' => []]],
			'not a list'         => [['datasets' => 'accounts']],
		];

	}//end emptyDeclarations()

	/**
	 * A declaration without datasets is one problem.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return void
	 */
	#[DataProvider('emptyDeclarations')]
	public function testADeclarationWithoutDatasetsIsRefused(array $declaration): void {
		self::assertSame(
			['The declaration lists no datasets.'],
			(new FeedDeclarationValidator())->problems($declaration, self::FIELDS)
		);

	}//end testADeclarationWithoutDatasetsIsRefused()

	/**
	 * A valid dataset has no problems.
	 *
	 * @return void
	 */
	public function testAValidDatasetHasNoProblems(): void {
		self::assertSame([], (new FeedDeclarationValidator())->problems(['datasets' => [self::dataset()]], self::FIELDS));

	}//end testAValidDatasetHasNoProblems()

	/**
	 * A dataset without a key is named by its position and not checked further; the others still are.
	 *
	 * @return void
	 */
	public function testADatasetWithoutAKeyIsNamedByPosition(): void {
		$declaration = [
			'datasets' => [
				self::dataset(),
				self::dataset(['key' => '', 'schema' => 'Nope']),
				self::dataset(['key' => 'periods', 'schema' => 'Period', 'fields' => ['start', 'salary']]),
			],
		];

		self::assertSame(
			['Dataset 1 has no key.', 'Dataset periods lists field salary, which Period does not have.'],
			(new FeedDeclarationValidator())->problems($declaration, self::FIELDS)
		);

	}//end testADatasetWithoutAKeyIsNamedByPosition()

	/**
	 * A key declared twice is named, and the second dataset is still checked.
	 *
	 * @return void
	 */
	public function testADuplicateKeyIsRefused(): void {
		$declaration = ['datasets' => [self::dataset(), self::dataset(['binding' => []])]];

		self::assertSame(
			[
				'Dataset accounts is declared twice.',
				'Dataset accounts is not bound to one administration through the credential.',
			],
			(new FeedDeclarationValidator())->problems($declaration, self::FIELDS)
		);

	}//end testADuplicateKeyIsRefused()

	/**
	 * A binding to anything but the credential's administration is refused.
	 *
	 * @return void
	 */
	public function testABindingToAnotherSourceIsRefused(): void {
		$declaration = ['datasets' => [self::dataset(['binding' => ['administrationId' => 'query.administrationId']])]];

		self::assertSame(
			['Dataset accounts is not bound to one administration through the credential.'],
			(new FeedDeclarationValidator())->problems($declaration, self::FIELDS)
		);

	}//end testABindingToAnotherSourceIsRefused()

	/**
	 * An undeclared schema is named and its fields are not checked.
	 *
	 * @return void
	 */
	public function testAnUndeclaredSchemaStopsTheFieldCheck(): void {
		$declaration = ['datasets' => [self::dataset(['schema' => 'Salary', 'fields' => ['amount']])]];

		self::assertSame(
			['Dataset accounts names schema Salary, which the register does not declare.'],
			(new FeedDeclarationValidator())->problems($declaration, self::FIELDS)
		);

	}//end testAnUndeclaredSchemaStopsTheFieldCheck()

	/**
	 * Missing, empty or non-list fields are one problem.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function emptyFields(): array {
		return ['empty list' => [[]], 'not a list' => ['code']];

	}//end emptyFields()

	/**
	 * A dataset without fields is refused.
	 *
	 * @param mixed $fields The fields member.
	 *
	 * @return void
	 */
	#[DataProvider('emptyFields')]
	public function testADatasetWithoutFieldsIsRefused(mixed $fields): void {
		$declaration = ['datasets' => [self::dataset(['fields' => $fields])]];

		self::assertSame(
			['Dataset accounts lists no fields.'],
			(new FeedDeclarationValidator())->problems($declaration, self::FIELDS)
		);

	}//end testADatasetWithoutFieldsIsRefused()

	/**
	 * Every unknown field is named, in order, alongside a missing binding.
	 *
	 * @return void
	 */
	public function testEveryProblemOfADatasetIsNamed(): void {
		$declaration = ['datasets' => [self::dataset(['binding' => null, 'fields' => ['code', 'iban', 'bic']])]];

		self::assertSame(
			[
				'Dataset accounts is not bound to one administration through the credential.',
				'Dataset accounts lists field iban, which Account does not have.',
				'Dataset accounts lists field bic, which Account does not have.',
			],
			(new FeedDeclarationValidator())->problems($declaration, self::FIELDS)
		);

	}//end testEveryProblemOfADatasetIsNamed()
}//end class
