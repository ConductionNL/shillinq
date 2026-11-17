<?php

/**
 * Unit tests for BackfillGroupEntityCbcFields: the old business activity
 * becomes an OECD code and the RSIN becomes the TIN, once.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Repair\BackfillGroupEntityCbcFields;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The repair step over an in-memory register.
 */
#[CoversClass(BackfillGroupEntityCbcFields::class)]
#[UsesClass(ObjectIdentifier::class)]
final class BackfillGroupEntityCbcFieldsTest extends TestCase {
	/**
	 * The step over the given store, with the info lines it writes.
	 *
	 * @param ObjectServiceInterface $store    The object surface.
	 * @param array<int,string>      $messages Collected info lines.
	 * @param array<int,string>      $warnings Collected warning lines.
	 *
	 * @return array{0:BackfillGroupEntityCbcFields,1:IOutput}
	 */
	private function step(ObjectServiceInterface $store, array &$messages, array &$warnings): array {
		$settings = $this->createMock(RegisterLocationInterface::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		return [new BackfillGroupEntityCbcFields($store, $settings, $this->createMock(LoggerInterface::class)), $output];

	}//end step()

	/**
	 * The Voorbeeld seed: the holding gets CBC511 and its padded RSIN, the
	 * GmbH keeps its typed TIN and activities, and a rerun changes nothing.
	 *
	 * @return void
	 */
	public function testTheSeedIsFilledOnceAndTypedValuesAreKept(): void {
		$store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [
					[
						'id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'jurisdiction' => 'NL',
						'mainBusinessActivity' => 'holding', 'administrationId' => 'adm-holding',
					],
					[
						'id' => 'ge-gmbh', 'entityName' => 'Voorbeeld GmbH', 'jurisdiction' => 'DE', 'mainBusinessActivity' => 'sales-marketing-distribution',
						'bizActivities' => ['CBC505', 'CBC506'], 'tin' => 'DE123456789', 'tinIssuedBy' => 'DE',
					],
					[
						'id' => 'ge-productie', 'entityName' => 'Voorbeeld Productie B.V.', 'jurisdiction' => 'NL',
						'mainBusinessActivity' => 'manufacturing-production', 'administrationId' => 'adm-productie',
					],
					['id' => 'ge-unknown', 'entityName' => 'Voorbeeld Onbekend', 'mainBusinessActivity' => 'mining'],
				],
				'Administration' => [
					['id' => 'adm-holding', 'administrationCode' => 'HOLD', 'rsin' => '1234567'],
					['id' => 'adm-productie', 'administrationCode' => 'PROD', 'rsin' => ''],
				],
			]
		);
		$messages = [];
		$warnings = [];
		[$step, $output] = $this->step($store, $messages, $warnings);

		$step->run($output);
		$step->run($output);

		$saved = array_map(static fn (array $entry): array => $entry['object'], $store->saved);
		$this->assertCount(2, $saved);
		$this->assertSame(['CBC511'], $saved[0]['bizActivities']);
		$this->assertSame('001234567', $saved[0]['tin']);
		$this->assertSame('NL', $saved[0]['tinIssuedBy']);
		$this->assertSame('ge-holding', $saved[0]['id']);
		$this->assertSame(['CBC504'], $saved[1]['bizActivities']);
		$this->assertArrayNotHasKey('tin', $saved[1]);
		$this->assertSame('ge-productie', $saved[1]['id']);
		$this->assertSame(
			[
				'Shillinq: 2 group entit(y/ies) given country-by-country activity codes or a TIN.',
				'Shillinq: 0 group entit(y/ies) given country-by-country activity codes or a TIN.',
			],
			$messages
		);
		$this->assertSame([], $warnings);

	}//end testTheSeedIsFilledOnceAndTypedValuesAreKept()

	/**
	 * Every old activity value maps onto a code of the OECD list.
	 *
	 * @return void
	 */
	public function testEveryOldActivityHasAnOecdCode(): void {
		$old = [
			'research-development', 'holding', 'purchasing-procurement', 'manufacturing-production', 'sales-marketing-distribution',
			'services', 'financial', 'insurance', 'internal-group-finance', 'regulated-financial-service', 'other',
		];
		sort($old);
		$mapped = array_keys(BackfillGroupEntityCbcFields::ACTIVITY_CODES);
		sort($mapped);

		$this->assertSame($old, $mapped);
		foreach (BackfillGroupEntityCbcFields::ACTIVITY_CODES as $code) {
			$this->assertMatchesRegularExpression('/^CBC5(0[1-9]|1[0-3])$/', $code);
		}

	}//end testEveryOldActivityHasAnOecdCode()

	/**
	 * An RSIN that is too long is not a TIN, and an unreadable register is a warning, not a crash.
	 *
	 * @return void
	 */
	public function testABadRsinIsSkippedAndAFailingReadWarns(): void {
		$store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [['id' => 'ge-1', 'entityName' => 'Lange B.V.', 'administrationId' => 'adm-1', 'bizActivities' => ['CBC513']]],
				'Administration' => [['id' => 'adm-1', 'rsin' => '1234567890']],
			]
		);
		$messages = [];
		$warnings = [];
		[$step, $output] = $this->step($store, $messages, $warnings);
		$step->run($output);
		$this->assertSame([], $store->saved);

		$broken = $this->createMock(ObjectServiceInterface::class);
		$broken->method('setRegister')->willReturnSelf();
		$broken->method('setSchema')->willReturnSelf();
		$broken->method('findAll')->willThrowException(new RuntimeException('register down'));
		$broken->method('runAsSystem')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		[$failing, $failingOutput] = $this->step($broken, $messages, $warnings);
		$failing->run($failingOutput);

		$this->assertSame(['Shillinq: filling the country-by-country fields of group entities failed: register down'], $warnings);

	}//end testABadRsinIsSkippedAndAFailingReadWarns()
}//end class
