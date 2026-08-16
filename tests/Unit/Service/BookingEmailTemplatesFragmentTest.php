<?php

/**
 * Unit tests for the bookings-email-templates register fragment (ADR-037).
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
 * @spec openspec/changes/bookings-email-templates/specs/notification-booking-email-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the shipped 10-bookings-email-templates.json fragment is well-formed,
 * declares the three template schemas with the required lifecycle/calculation
 * extensions, and unions cleanly onto the base register via deepMergeConfig.
 */
final class BookingEmailTemplatesFragmentTest extends TestCase {
	/**
	 * Absolute path to the shipped register fragment under test.
	 *
	 * @var string
	 */
	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/10-bookings-email-templates.json';

	/**
	 * The three template schema slugs declared by this change.
	 *
	 * @var array<string>
	 */
	private const TEMPLATE_SCHEMAS = [
		'BookingConfirmationTemplate',
		'BookingReminderTemplate',
		'BookingCancellationTemplate',
	];

	/**
	 * Decode the shipped fragment into an array.
	 *
	 * @return array<mixed>
	 */
	private function loadFragment(): array {
		$this->assertFileExists(self::FRAGMENT, 'Register fragment must be present.');
		$content = file_get_contents(self::FRAGMENT);
		$this->assertNotFalse($content, 'Fragment must be readable.');
		$data = json_decode($content, true);
		$this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Fragment must be valid JSON.');
		$this->assertIsArray($data);
		return $data;
	}//end loadFragment()

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
	 * The fragment declares the three template schemas with the documented shape.
	 *
	 * @return void
	 */
	public function testFragmentDeclaresThreeTemplateSchemas(): void {
		$data = $this->loadFragment();
		$schemas = ($data['components']['schemas'] ?? []);

		foreach (self::TEMPLATE_SCHEMAS as $slug) {
			$this->assertArrayHasKey($slug, $schemas, $slug . ' schema must be declared.');
			$schema = $schemas[$slug];

			$this->assertSame($slug, $schema['slug']);
			$this->assertSame('object', $schema['type']);

			// Required core fields per spec Data Model.
			foreach (['name', 'subjectLine', 'htmlBody', 'plainTextBody'] as $req) {
				$this->assertContains($req, $schema['required'], $slug . ' must require ' . $req);
				$this->assertArrayHasKey($req, $schema['properties']);
			}

			// Branding + sender fields present.
			foreach (['logoUri', 'accentColor', 'footerText', 'senderName', 'senderAddress', 'locale'] as $brand) {
				$this->assertArrayHasKey($brand, $schema['properties'], $slug . ' must declare ' . $brand);
			}
		}
	}//end testFragmentDeclaresThreeTemplateSchemas()

	/**
	 * Every template schema carries the draft -> published -> archived lifecycle.
	 *
	 * @return void
	 */
	public function testLifecycleDraftPublishedArchived(): void {
		$schemas = ($this->loadFragment()['components']['schemas'] ?? []);

		foreach (self::TEMPLATE_SCHEMAS as $slug) {
			$lifecycle = ($schemas[$slug]['x-openregister-lifecycle'] ?? []);
			$this->assertSame('status', $lifecycle['field'], $slug . ' lifecycle field must be status.');
			$this->assertSame('draft', $lifecycle['initialState'], $slug . ' must start in draft.');

			$states = array_keys($lifecycle['states']);
			$this->assertSame(['draft', 'published', 'archived'], $states, $slug . ' states.');

			// Publish transition draft -> published is present.
			$publish = ($lifecycle['transitions']['publish'] ?? []);
			$this->assertSame('draft', $publish['from']);
			$this->assertSame('published', $publish['to']);

			// Archive transition published -> archived is present.
			$archive = ($lifecycle['transitions']['archive'] ?? []);
			$this->assertSame('published', $archive['from']);
			$this->assertSame('archived', $archive['to']);
		}
	}//end testLifecycleDraftPublishedArchived()

	/**
	 * Variable substitution is expressed declaratively (no PHP render service).
	 *
	 * @return void
	 */
	public function testCalculationsDeclareRenderedFields(): void {
		$schemas = ($this->loadFragment()['components']['schemas'] ?? []);

		foreach (self::TEMPLATE_SCHEMAS as $slug) {
			$calc = ($schemas[$slug]['x-openregister-calculations'] ?? []);
			foreach (['renderedSubject', 'renderedHtmlBody', 'renderedPlainTextBody'] as $field) {
				$this->assertArrayHasKey($field, $calc, $slug . ' must declare calculation ' . $field);
				$this->assertNotEmpty($calc[$field]['expression']);
			}

			// NFR validation calculations (subject length, body size) present.
			$this->assertArrayHasKey('subjectLineLength', $calc, $slug . ' must validate subject length (NFR-BET-002).');
			$this->assertArrayHasKey('bodySizeBytes', $calc, $slug . ' must validate body size (NFR-BET-003).');
		}
	}//end testCalculationsDeclareRenderedFields()

	/**
	 * The reminder schema carries the integer hoursBeforeBooking timing field.
	 *
	 * @return void
	 */
	public function testReminderHasHoursBeforeBooking(): void {
		$schemas = ($this->loadFragment()['components']['schemas'] ?? []);
		$reminder = $schemas['BookingReminderTemplate'];

		$this->assertContains('hoursBeforeBooking', $reminder['required']);
		$field = $reminder['properties']['hoursBeforeBooking'];
		$this->assertSame('integer', $field['type']);
		$this->assertSame(0, $field['minimum']);
	}//end testReminderHasHoursBeforeBooking()

	/**
	 * The cancellation schema carries the cancellationReasonRequired boolean.
	 *
	 * @return void
	 */
	public function testCancellationHasReasonFlag(): void {
		$schemas = ($this->loadFragment()['components']['schemas'] ?? []);
		$cancellation = $schemas['BookingCancellationTemplate'];

		$this->assertArrayHasKey('cancellationReasonRequired', $cancellation['properties']);
		$this->assertSame('boolean', $cancellation['properties']['cancellationReasonRequired']['type']);
	}//end testCancellationHasReasonFlag()

	/**
	 * The fragment seeds nl + en default templates for all three types.
	 *
	 * @return void
	 */
	public function testSeedObjectsCoverNlAndEnForEachType(): void {
		$objects = ($this->loadFragment()['objects'] ?? []);
		$this->assertCount(6, $objects, 'Expect one nl + one en default per template type.');

		$byKey = [];
		foreach ($objects as $object) {
			$self = ($object['@self'] ?? []);
			$this->assertSame('shillinq', $self['register'], 'Seed objects target the shillinq register.');
			$byKey[$self['schema'] . '|' . $object['locale']] = $object;

			// Seed templates ship published and carry a subject + both bodies.
			$this->assertSame('published', $object['status']);
			$this->assertNotEmpty($object['subjectLine']);
			$this->assertNotEmpty($object['htmlBody']);
			$this->assertNotEmpty($object['plainTextBody']);
		}

		foreach (self::TEMPLATE_SCHEMAS as $slug) {
			$this->assertArrayHasKey($slug . '|nl', $byKey, $slug . ' must seed a Dutch default.');
			$this->assertArrayHasKey($slug . '|en', $byKey, $slug . ' must seed an English default.');
		}
	}//end testSeedObjectsCoverNlAndEnForEachType()

	/**
	 * The shipped fragment unions cleanly onto a base register via deepMergeConfig
	 * without clobbering existing schemas or seed objects (ADR-037).
	 *
	 * @return void
	 */
	public function testFragmentUnionsOntoBaseRegister(): void {
		$fragment = $this->loadFragment();

		$base = [
			'components' => ['schemas' => ['Account' => ['type' => 'object']]],
			'objects' => [['@self' => ['schema' => 'Account', 'slug' => 'pre-existing']]],
		];

		$merged = $this->merge($base, $fragment);

		// Existing schema and seed object survive.
		$this->assertArrayHasKey('Account', $merged['components']['schemas']);

		// All three new schemas are unioned in.
		foreach (self::TEMPLATE_SCHEMAS as $slug) {
			$this->assertArrayHasKey($slug, $merged['components']['schemas']);
		}

		// Objects list is concatenated: 1 pre-existing + 6 seeds.
		$this->assertCount(7, $merged['objects']);
	}//end testFragmentUnionsOntoBaseRegister()
}//end class
