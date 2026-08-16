<?php

/**
 * Unit tests for the bookkeeping-multi-administratie register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/specs/bookkeeping-multi-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the multi-administratie fragment is valid JSON, declares the five new
 * administratie-family schemas with their required fields + lifecycles, merges
 * additively onto the monolith without disturbing existing schemas (ADR-037), and
 * ships internally-consistent seed objects (holding-werkmij + accounting-firm).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MultiAdministratieFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-multi-administratie.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Load the fragment as an array.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment is present, valid JSON, and has the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The five administratie-family schemas are declared (REQ-MA-001 .. REQ-MA-006).
	 *
	 * @return void
	 */
	public function testDeclaresFiveSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach ([
			'Administration',
			'AdministrationMembership',
			'IntercompanyJournalEntry',
			'ConsolidationMapping',
			'AdministrationMigration',
		] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertSame($name, $schemas[$name]['slug']);
		}

	}//end testDeclaresFiveSchemas()

	/**
	 * Administration declares the multi-tenant + lifecycle fields (REQ-MA-001/002/007).
	 *
	 * @return void
	 */
	public function testAdministrationFields(): void {
		$administration = $this->fragment()['components']['schemas']['Administration'];
		foreach ([
			'administrationCode',
			'name',
			'legalForm',
			'fiscalYearStartMonth',
			'vatRegime',
			'parentAdministrationId',
			'status',
			'backupSchedule',
			'dataRetentionYears',
		] as $field) {
			self::assertArrayHasKey($field, $administration['properties'], "Administration must declare $field");
		}

		self::assertContains('administrationCode', $administration['required']);

		// Archival lifecycle (REQ-MA-007): actief is the initial state and archive is a transition.
		self::assertSame('status', $administration['x-openregister-lifecycle']['field']);
		self::assertSame('actief', $administration['x-openregister-lifecycle']['initialState']);
		self::assertArrayHasKey('archive', $administration['x-openregister-lifecycle']['transitions']);

	}//end testAdministrationFields()

	/**
	 * Membership is a user-administratie-role join (REQ-MA-003).
	 *
	 * @return void
	 */
	public function testMembershipJoinFields(): void {
		$membership = $this->fragment()['components']['schemas']['AdministrationMembership'];
		foreach (['userId', 'administrationId', 'role'] as $field) {
			self::assertContains($field, $membership['required'], "Membership must require $field");
		}

		$roles = $membership['properties']['role']['enum'];
		foreach (['eigenaar', 'controller', 'boekhouder', 'inkijker', 'accountant_extern'] as $role) {
			self::assertContains($role, $roles, "Membership role enum must include $role");
		}

	}//end testMembershipJoinFields()

	/**
	 * Intercompany status lifecycle is concept → gekoppeld → bevestigd_beide → eliminatie_geboekt (REQ-MA-004).
	 *
	 * @return void
	 */
	public function testIntercompanyLifecycle(): void {
		$entry = $this->fragment()['components']['schemas']['IntercompanyJournalEntry'];
		$states = array_keys($entry['x-openregister-lifecycle']['states']);
		self::assertSame(
			['draft', 'gekoppeld', 'bevestigd_beide', 'eliminatie_geboekt'],
			$states
		);

	}//end testIntercompanyLifecycle()

	/**
	 * Merging the fragment adds the new schemas without dropping the monolith's
	 * existing ConsolidationGroup / Account schemas (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('Administration', $schemas);
		self::assertArrayHasKey('IntercompanyJournalEntry', $schemas);
		// Pre-existing schemas survive the merge.
		self::assertArrayHasKey('ConsolidationGroup', $schemas);
		self::assertArrayHasKey('Account', $schemas);
		self::assertArrayHasKey('GLTransaction', $schemas);

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas and carry the shillinq register (REQ-MA-001).
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['components']['objects'];

		self::assertNotEmpty($objects);
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);
		}

	}//end testSeedObjectsAreConsistent()

	/**
	 * The holding-werkmij example links Werk → Beheer via parent + intercompany (REQ-MA-003/004).
	 *
	 * @return void
	 */
	public function testHoldingWerkmijExampleIsLinked(): void {
		$objects = $this->fragment()['components']['objects'];
		$bySlug = [];
		foreach ($objects as $object) {
			$bySlug[$object['@self']['slug']] = $object;
		}

		// Werk is a dochter of Beheer.
		self::assertSame('adm-beheer-001', $bySlug['adm-werk-001']['parentAdministrationId']);
		self::assertContains('adm-werk-001', $bySlug['adm-beheer-001']['childAdministrationIds']);

		// The controller has different roles per administration.
		self::assertSame('controller', $bySlug['lid-controller-werk-001']['role']);
		self::assertSame('inkijker', $bySlug['lid-controller-beheer-001']['role']);

		// The intercompany entry flows from Werk to Beheer and balances (variance 0).
		$ic = $bySlug['ic-2026-00042'];
		self::assertSame('adm-werk-001', $ic['sourceAdministrationId']);
		self::assertSame('adm-beheer-001', $ic['destinationAdministrationId']);
		self::assertSame(0.0, (float)$ic['varianceAmount']);

	}//end testHoldingWerkmijExampleIsLinked()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
