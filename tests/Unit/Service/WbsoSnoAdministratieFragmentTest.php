<?php

/**
 * Unit tests for the bookkeeping-wbso-sno-administratie register fragment.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
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
 * Verifies the WBSO S&O-administratie fragment is valid JSON, declares the three
 * WBSO schemas (WbsoBeschikking, SoUurregistratie, WbsoMededeling) with their
 * lifecycle, relations and RBAC metadata (ADR-031 / ADR-037), merges additively
 * onto the monolith without dropping existing schemas, and ships consistent seed
 * objects.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoSnoAdministratieFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-wbso-sno-administratie.json';

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
	 * The fragment is present and valid JSON with the expected sections.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertArrayHasKey('schemas', $data['components']);
		self::assertArrayHasKey('objects', $data);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the three WBSO schemas (REQ-WBSO-001..003).
	 *
	 * @return void
	 */
	public function testDeclaresThreeWbsoSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		foreach (['WbsoBeschikking', 'SoUurregistratie', 'WbsoMededeling'] as $slug) {
			self::assertArrayHasKey($slug, $schemas, "fragment must declare $slug");
			self::assertSame($slug, $schemas[$slug]['slug']);
			self::assertArrayHasKey('administrationId', $schemas[$slug]['properties'], "$slug must carry administrationId");
			self::assertContains('administrationId', $schemas[$slug]['required']);
			self::assertArrayHasKey('x-openregister-rbac', $schemas[$slug], "$slug must declare RBAC");
			self::assertArrayHasKey('x-openregister-lifecycle', $schemas[$slug], "$slug must declare a lifecycle");
		}

	}//end testDeclaresThreeWbsoSchemas()

	/**
	 * WbsoBeschikking carries the RVO grant fields with a granted/expired/withdrawn lifecycle.
	 *
	 * WITHDRAWN ASSERTION — the `project` relation. It was declared in the
	 * per-schema `x-openregister-relations` block, which ADR-062 rule 7 retired
	 * on 2026-07-08 in favour of a property-level `$ref`. It is NOT expressible
	 * in the canonical dialect and was removed rather than migrated, on two
	 * counts: it was a SELF-relation (WbsoBeschikking -> WbsoBeschikking) whose
	 * `relatedField` was `projectNumber` — an RVO-issued business key, not the
	 * object identity a `$ref` resolves against. It also carried a bespoke
	 * `scopeField: administrationId` that the canonical dialect has no slot
	 * for. `projectNumber` itself is still asserted as a declared field above,
	 * which is what the register still guarantees.
	 *
	 * @return void
	 */
	public function testBeschikkingFieldsAndLifecycle(): void {
		$schema = $this->fragment()['components']['schemas']['WbsoBeschikking'];
		foreach (['decisionNumber', 'rvoReference', 'projectNumber', 'periodStart', 'periodEnd', 'grantedSoHours', 'soHourlyRate'] as $field) {
			self::assertArrayHasKey($field, $schema['properties'], "WbsoBeschikking must declare $field");
		}

		self::assertSame('granted', $schema['x-openregister-lifecycle']['initialState']);
		self::assertSame(['granted', 'expired', 'withdrawn'], $schema['properties']['state']['enum']);

	}//end testBeschikkingFieldsAndLifecycle()

	/**
	 * SoUurregistratie caps hours at 24/day and reuses the NC employee identity.
	 *
	 * @return void
	 */
	public function testUurregistratieConstraints(): void {
		$schema = $this->fragment()['components']['schemas']['SoUurregistratie'];
		self::assertSame(0, $schema['properties']['hours']['minimum']);
		self::assertSame(24, $schema['properties']['hours']['maximum']);
		// Employee is the Nextcloud identity, not an app-local person schema (ADR-022).
		self::assertArrayHasKey('employeeId', $schema['properties']);
		self::assertSame(['draft', 'confirmed', 'locked'], $schema['properties']['state']['enum']);

	}//end testUurregistratieConstraints()

	/**
	 * WbsoMededeling submit/resubmit transitions are guarded by the realisatie ceiling guard.
	 *
	 * @return void
	 */
	public function testMededelingSubmitIsGuarded(): void {
		$schema = $this->fragment()['components']['schemas']['WbsoMededeling'];
		$transitions = $schema['x-openregister-lifecycle']['transitions'];
		$guard = 'OCA\\Shillinq\\Lifecycle\\WbsoMededelingGuard::canSubmit';

		self::assertArrayHasKey('submit', $transitions);
		self::assertSame($guard, $transitions['submit']['requires']);
		self::assertArrayHasKey('resubmit', $transitions);
		self::assertSame($guard, $transitions['resubmit']['requires']);
		self::assertSame(['draft', 'submitted', 'accepted', 'rejected'], $schema['properties']['state']['enum']);

	}//end testMededelingSubmitIsGuarded()

	/**
	 * Merging the fragment adds the WBSO schemas without dropping the monolith's
	 * existing schemas (ADR-037 disjoint union).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		self::assertArrayHasKey('WbsoBeschikking', $schemas);
		self::assertArrayHasKey('SoUurregistratie', $schemas);
		self::assertArrayHasKey('WbsoMededeling', $schemas);
		// Pre-existing monolith foundation schemas survive the merge.
		self::assertArrayHasKey('Account', $schemas);
		self::assertArrayHasKey('GLTransaction', $schemas);
		// Top-level objects concatenate (the fragment seeds are appended).
		self::assertGreaterThanOrEqual(count($base['objects']) + count($frag['objects']), count($merged['objects']));

	}//end testFragmentMergesAdditivelyOntoMonolith()

	/**
	 * Seed objects target only declared schemas, the shillinq register, and obey
	 * the realisatie ceiling and the daily hour cap.
	 *
	 * @return void
	 */
	public function testSeedObjectsAreConsistent(): void {
		$frag = $this->fragment();
		$schemas = $frag['components']['schemas'];
		$objects = $frag['objects'];

		self::assertNotEmpty($objects);

		$grantedByDecision = [];
		foreach ($objects as $object) {
			self::assertArrayHasKey('@self', $object);
			self::assertSame('shillinq', $object['@self']['register']);
			self::assertArrayHasKey($object['@self']['schema'], $schemas);

			if ($object['@self']['schema'] === 'WbsoBeschikking') {
				$grantedByDecision[$object['decisionNumber']] = (float)$object['grantedSoHours'];
			}

			if ($object['@self']['schema'] === 'SoUurregistratie') {
				self::assertGreaterThanOrEqual(0, $object['hours']);
				self::assertLessThanOrEqual(24, $object['hours']);
			}
		}

		// Each seed mededeling must not exceed its beschikking's granted ceiling.
		foreach ($objects as $object) {
			if ($object['@self']['schema'] !== 'WbsoMededeling') {
				continue;
			}

			$decisionNumber = $object['decisionNumber'];
			self::assertArrayHasKey($decisionNumber, $grantedByDecision);
			self::assertLessThanOrEqual(
				$grantedByDecision[$decisionNumber],
				(float)$object['realisedSoHours'],
				'Seed mededeling realisatie must not exceed its beschikking ceiling (REQ-WBSO-005)'
			);
		}

	}//end testSeedObjectsAreConsistent()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
