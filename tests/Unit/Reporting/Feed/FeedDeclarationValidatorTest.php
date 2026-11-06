<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * reporting-data-delivery REQ-RDD-003: the shipped feed declaration names
 * real schemas and fields and binds every dataset to one administration, and
 * a dataset without that binding is refused.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Feed;

use OCA\Shillinq\Reporting\Feed\FeedDeclarationValidator;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

class FeedDeclarationValidatorTest extends TestCase {

	/**
	 * The shipped declaration.
	 *
	 * @return array<string, mixed> The decoded feeds.json.
	 */
	private static function declaration(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/feeds.json'), true, 512, JSON_THROW_ON_ERROR);

	}//end declaration()

	/**
	 * Property names per schema the declaration names, from the merged register.
	 *
	 * @param array<string, mixed> $declaration The declaration.
	 *
	 * @return array<string, array<int, string>> The fields.
	 */
	private static function fields(array $declaration): array {
		$fields = [];
		foreach ($declaration['datasets'] as $dataset) {
			$fields[$dataset['schema']] = array_keys(RegisterSchema::schema($dataset['schema'])['properties'] ?? []);
		}

		return $fields;

	}//end fields()

	/**
	 * The four datasets are valid against the real register.
	 *
	 * @return void
	 */
	public function testTheShippedDeclarationIsValid(): void {
		$declaration = self::declaration();

		self::assertSame([], (new FeedDeclarationValidator())->problems($declaration, self::fields($declaration)));
		self::assertSame(
			['ledger-lines', 'accounts', 'periods', 'relations'],
			array_column($declaration['datasets'], 'key')
		);

	}//end testTheShippedDeclarationIsValid()

	/**
	 * A dataset that is not bound to one administration is refused.
	 *
	 * @return void
	 */
	public function testAnUnboundDatasetIsRefused(): void {
		$declaration = self::declaration();
		unset($declaration['datasets'][0]['binding']);

		self::assertSame(
			['Dataset ledger-lines is not bound to one administration through the credential.'],
			(new FeedDeclarationValidator())->problems($declaration, self::fields($declaration))
		);

	}//end testAnUnboundDatasetIsRefused()

	/**
	 * A field the schema does not have is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownFieldIsRefused(): void {
		$declaration = self::declaration();
		$declaration['datasets'][1]['fields'][] = 'salary';

		self::assertSame(
			['Dataset accounts lists field salary, which Account does not have.'],
			(new FeedDeclarationValidator())->problems($declaration, self::fields($declaration))
		);

	}//end testAnUnknownFieldIsRefused()
}//end class
