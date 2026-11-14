<?php

/**
 * Unit tests for PortalSubjectResolver.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Portal;

use OCA\Shillinq\Portal\PortalSubjectResolver;
use PHPUnit\Framework\TestCase;

/**
 * The ownership chain the pay and decline receivers share.
 */
final class PortalSubjectResolverTest extends TestCase {
	/**
	 * A portalAccount reader returning these rows for any filter it matches.
	 *
	 * @param array<int, array<string, mixed>> $rows The portal accounts.
	 *
	 * @return object The reader.
	 */
	private function accounts(array $rows): object {
		return new class($rows) {
			/**
			 * The filters of the last read.
			 *
			 * @var array<string, mixed>
			 */
			public array $lastFilters = [];

			/**
			 * @param array<int, array<string, mixed>> $rows The accounts.
			 */
			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->lastFilters = $config['filters'];
				return array_values(
					array_filter(
						$this->rows,
						fn (array $row): bool => $row['subjectRef'] === $config['filters']['subjectRef'] && $row['audience'] === $config['filters']['audience']
					)
				);
			}
		};
	}//end accounts()

	/**
	 * Only a plain object id passes; URLs, paths, traversal and empty strings
	 * do not (REQ-SCON-013, REQ-SPPI-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testOnlyAnOpaqueIdPasses(): void {
		$resolver = new PortalSubjectResolver();

		self::assertTrue($resolver->isOpaqueId(target: '30000000-0000-4000-8000-000000000003'));
		self::assertTrue($resolver->isOpaqueId(target: 'ar-invoice-ctb-2026-ouderbijdrage-1'));
		foreach (['', 'https://attacker.example/', '/etc/passwd', '\\share', '../x', 'a/../b'] as $target) {
			self::assertFalse($resolver->isOpaqueId(target: $target), $target);
		}
	}//end testOnlyAnOpaqueIdPasses()

	/**
	 * The customer comes from the subject's own portal account for the asserted
	 * audience (REQ-SCON-013, REQ-SPPI-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testTheCustomerComesFromTheSubjectsOwnAccount(): void {
		$reader = $this->accounts(
			[
				['subjectRef' => 'sub-1', 'audience' => 'parent', 'claims' => ['shillinq' => ['customerMasterId' => 'cm-1']]],
				['subjectRef' => 'sub-1', 'audience' => 'customer', 'claims' => ['shillinq' => ['customerMasterId' => 'cm-9']]],
			]
		);

		self::assertSame('cm-1', (new PortalSubjectResolver())->customerMasterId(objectService: $reader, subjectRef: 'sub-1', audience: 'parent'));
		self::assertSame(['subjectRef' => 'sub-1', 'audience' => 'parent'], $reader->lastFilters);
	}//end testTheCustomerComesFromTheSubjectsOwnAccount()

	/**
	 * No account, no shillinq claim, an empty claim or an empty subject all
	 * resolve to nothing (REQ-SCON-013).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 */
	public function testAMissingOrMalformedClaimResolvesToNothing(): void {
		$resolver = new PortalSubjectResolver();
		$reader = $this->accounts(
			[
				['subjectRef' => 'sub-2', 'audience' => 'parent', 'claims' => ['pipelinq' => ['customerMasterId' => 'cm-2']]],
				['subjectRef' => 'sub-3', 'audience' => 'parent', 'claims' => ['shillinq' => ['customerMasterId' => '']]],
				['subjectRef' => 'sub-4', 'audience' => 'parent', 'claims' => 'broken'],
			]
		);

		foreach (['sub-1', 'sub-2', 'sub-3', 'sub-4'] as $subject) {
			self::assertNull($resolver->customerMasterId(objectService: $reader, subjectRef: $subject, audience: 'parent'), $subject);
		}

		self::assertNull($resolver->customerMasterId(objectService: $reader, subjectRef: '', audience: 'parent'));
	}//end testAMissingOrMalformedClaimResolvesToNothing()
}//end class
