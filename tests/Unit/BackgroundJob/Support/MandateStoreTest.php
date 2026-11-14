<?php

/**
 * Unit tests for MandateStore.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob\Support;

use OCA\Shillinq\BackgroundJob\Support\MandateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The store scopes the object service to its register and the SepaMandate schema.
 */
#[CoversClass(MandateStore::class)]
final class MandateStoreTest extends TestCase {

	/**
	 * A fluent object service that records each scoping call.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return new class {
			/**
			 * @var array<int, array{string, string}>
			 */
			public array $calls = [];

			/**
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->calls[] = ['register', $register];
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->calls[] = ['schema', $schema];
				return $this;
			}
		};
	}//end objectService()

	/**
	 * scoped sets the register first, then the SepaMandate schema, and returns the service.
	 *
	 * @return void
	 */
	public function testScopedSetsRegisterThenSchema(): void {
		$service = $this->objectService();

		$scoped = (new MandateStore(objectService: $service, register: 'shillinq'))->scoped();

		$this->assertSame($service, $scoped);
		$this->assertSame([['register', 'shillinq'], ['schema', 'SepaMandate']], $service->calls);
	}//end testScopedSetsRegisterThenSchema()

	/**
	 * Every call to scoped re-applies the scope, so a caller that re-scoped the
	 * shared service in between gets the mandate scope back.
	 *
	 * @return void
	 */
	public function testEveryCallReappliesTheScope(): void {
		$service = $this->objectService();
		$store   = new MandateStore(objectService: $service, register: 'tenant-a');

		$store->scoped();
		$service->setSchema('Invoice');
		$store->scoped();

		$this->assertSame(
			[
				['register', 'tenant-a'],
				['schema', 'SepaMandate'],
				['schema', 'Invoice'],
				['register', 'tenant-a'],
				['schema', 'SepaMandate'],
			],
			$service->calls
		);
	}//end testEveryCallReappliesTheScope()

	/**
	 * scoped returns what the service's setSchema returns, not the original service.
	 *
	 * @return void
	 */
	public function testScopedReturnsTheChainResult(): void {
		$scopedService = new \stdClass();
		$service       = new class($scopedService) {
			/**
			 * @param object $scoped What setSchema answers.
			 */
			public function __construct(private object $scoped) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return object
			 */
			public function setSchema(string $schema): object {
				return $this->scoped;
			}
		};

		$this->assertSame($scopedService, (new MandateStore(objectService: $service, register: 'r'))->scoped());
	}//end testScopedReturnsTheChainResult()
}//end class
