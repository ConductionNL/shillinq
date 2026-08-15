<?php

/**
 * Unit tests for the restore-overdue-verantwoording-notification change.
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
 * @spec openspec/changes/restore-overdue-verantwoording-notification/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the declarative replacement for the retired
 * `OverdueVerantwoordingJob` (issue #505): `SubsidieVerantwoording.awardDate`
 * (a real schema field, no string-split calc op required),
 * `x-openregister-calculations.daysSinceAward` / `.isOverdue`, and the
 * `onOverdue` scheduled notification rule in
 * `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`.
 *
 * Two layers of coverage:
 * - Shape assertions: every calc operator used is in
 *   `CalculationAnnotationValidator::VALID_OPS`, every `prop` reference
 *   resolves to a real schema property or sibling calc, and the
 *   notification rule shape satisfies `NotificationAnnotationValidator`
 *   (trigger.type=scheduled needs intervalSec>=60 + a filter map;
 *   recipients[].kind must be in VALID_RECIPIENT_KINDS; channels must be
 *   in VALID_CHANNELS — this is also the regression lock for the
 *   pre-existing "in-app" channel bug this change fixed alongside the
 *   restore, see `channels: ["in-app", ...]` was never `nc-notification`
 *   so none of this schema's notifications ever rendered an in-app NC
 *   notification).
 * - Functional mirror-evaluation: a small local evaluator implementing
 *   exactly the operator semantics `CalculationEvaluator` documents for
 *   prop/lit/now/diffDays/gt/ne/and (null-safety on comparisons, floor
 *   division for diffDays) is run directly against the JSON-decoded
 *   expression trees — the same test cases the original
 *   `OverdueVerantwoordingJobTest` (recovered from git history at
 *   23d9d014) used for `OverdueVerantwoordingJob::isOverdue()`.
 */
final class OverdueVerantwoordingNotificationTest extends TestCase {

	/**
	 * Operator vocabulary recognised by OpenRegister's v1 calculation
	 * evaluator (`CalculationAnnotationValidator::VALID_OPS`,
	 * openregister `lib/Service/Calculation/CalculationAnnotationValidator.php`).
	 * Copied here (not imported — openregister is a soft cross-app
	 * dependency, not a composer package of this app) so the test fails
	 * loudly if a future edit introduces an operator outside the engine's
	 * vocabulary.
	 *
	 * @var array<int,string>
	 */
	private const VALID_CALC_OPS = [
		'prop',
		'lit',
		'concat',
		'if',
		'not',
		'and',
		'or',
		'+',
		'-',
		'*',
		'/',
		'%',
		'eq',
		'ne',
		'lt',
		'lte',
		'gt',
		'gte',
		'now',
		'diffDays',
		'formatDate',
		'dateDiff',
		'dateAdd',
		'sequence',
		'max',
		'min',
		'coalesce',
		'abs',
		'round',
		'year',
		'monthsElapsed',
		'sha256',
	];

	/**
	 * `NotificationAnnotationValidator::VALID_RECIPIENT_KINDS`.
	 *
	 * @var array<int,string>
	 */
	private const VALID_RECIPIENT_KINDS = ['users', 'field', 'groups', 'relation', 'object-acl', 'expression'];

	/**
	 * `NotificationAnnotationValidator::VALID_CHANNELS`.
	 *
	 * @var array<int,string>
	 */
	private const VALID_CHANNELS = ['nc-notification', 'email', 'activity', 'webhook', 'talk', 'web-push'];

	/**
	 * Absolute path to the fragment under test.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json';

	/**
	 * Decode the fragment to an array.
	 *
	 * @return array<string,mixed>
	 */
	private function loadFragment(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end loadFragment()

	/**
	 * The SubsidieVerantwoording schema block.
	 *
	 * @param array<string,mixed> $fragment The decoded fragment.
	 *
	 * @return array<string,mixed>
	 */
	private function subsidyAccountabilitySchema(array $fragment): array {
		$schema = ($fragment['components']['schemas']['SubsidieVerantwoording'] ?? null);
		self::assertIsArray($schema);
		return $schema;
	}//end subsidieVerantwoordingSchema()

	// -----------------------------------------------------------------
	// Shape assertions
	// -----------------------------------------------------------------

	/**
	 * AwardDate is a real, nullable date field on SubsidieVerantwoording —
	 * the honest fix (issue #505 option (a)) that avoids needing a
	 * string-split calc op to extract the reportingPeriod start.
	 *
	 * @return void
	 */
	public function testAwardDateFieldIsARealNullableDateProperty(): void {
		$schema = $this->subsidyAccountabilitySchema($this->loadFragment());
		$awardDate = ($schema['properties']['awardDate'] ?? null);

		self::assertIsArray($awardDate);
		self::assertSame('string', $awardDate['type']);
		self::assertSame('date', $awardDate['format']);
		self::assertTrue($awardDate['nullable']);

		// Not required — existing rows may not be backfilled; isOverdue
		// must tolerate a null awardDate (see testNullAwardDateNeverOverdue).
		self::assertNotContains('awardDate', $schema['required']);

	}//end testAwardDateFieldIsARealNullableDateProperty()

	/**
	 * The lifecycle declares `final` as its terminal state so
	 * TemporalCalculationSweepJob's hourly re-evaluation (which is what
	 * keeps `now`-dependent `isOverdue` live without an object write)
	 * skips already-finalised records instead of rewriting them forever.
	 *
	 * @return void
	 */
	public function testLifecycleDeclaresFinalAsTerminal(): void {
		$schema = $this->subsidyAccountabilitySchema($this->loadFragment());
		self::assertSame(['final'], $schema['x-openregister-lifecycle']['final']);

	}//end testLifecycleDeclaresFinalAsTerminal()

	/**
	 * Both calculations are materialised (required for the scheduled
	 * notification's `filter: {isOverdue: true}` to query stored data,
	 * and for TemporalCalculationSweepJob to detect+resweep them), typed
	 * correctly, and use only real engine operators.
	 *
	 * @return void
	 */
	public function testCalculationsAreMaterialisedAndUseOnlyValidOps(): void {
		$schema = $this->subsidyAccountabilitySchema($this->loadFragment());
		$calcs = ($schema['x-openregister-calculations'] ?? null);
		self::assertIsArray($calcs);
		self::assertArrayHasKey('daysSinceAward', $calcs);
		self::assertArrayHasKey('isOverdue', $calcs);

		self::assertTrue($calcs['daysSinceAward']['materialise']);
		self::assertSame('integer', $calcs['daysSinceAward']['type']);

		self::assertTrue($calcs['isOverdue']['materialise']);
		self::assertSame('boolean', $calcs['isOverdue']['type']);

		$propKeys = array_keys($schema['properties']);
		$calcNames = array_keys($calcs);
		$allRefs = array_merge($propKeys, $calcNames);

		foreach ($calcs as $name => $spec) {
			$this->assertExpressionUsesOnlyValidOps(
				expr: $spec['expression'],
				allRefs: $allRefs,
				calcName: (string)$name
			);
		}

	}//end testCalculationsAreMaterialisedAndUseOnlyValidOps()

	/**
	 * Recursively assert every operator key in an expression tree is in
	 * the engine's vocabulary, and every `prop` reference resolves to a
	 * real schema property or a sibling calculation name.
	 *
	 * @param mixed $expr The expression (sub)tree.
	 * @param array<string> $allRefs Valid prop/calc names.
	 * @param string $calcName The owning calc name (for assertion messages).
	 *
	 * @return void
	 */
	private function assertExpressionUsesOnlyValidOps(mixed $expr, array $allRefs, string $calcName): void {
		if (is_array($expr) === false) {
			return;
		}

		self::assertCount(
			1,
			$expr,
			sprintf('Calculation "%s": expression node must be single-key.', $calcName)
		);

		$op = (string)array_key_first($expr);
		self::assertContains(
			$op,
			self::VALID_CALC_OPS,
			sprintf('Calculation "%s": operator "%s" is not in the engine\'s vocabulary.', $calcName, $op)
		);

		$args = $expr[$op];
		if ($op === 'prop') {
			$propName = (string)($args[0] ?? '');
			if (is_string($args) === true) {
				$propName = $args;
			}

			self::assertContains(
				$propName,
				$allRefs,
				sprintf('Calculation "%s": prop "%s" is not a declared property or calc.', $calcName, $propName)
			);
			return;
		}

		if (is_array($args) === false) {
			$this->assertExpressionUsesOnlyValidOps(expr: $args, allRefs: $allRefs, calcName: $calcName);
			return;
		}

		foreach ($args as $sub) {
			$this->assertExpressionUsesOnlyValidOps(expr: $sub, allRefs: $allRefs, calcName: $calcName);
		}

	}//end assertExpressionUsesOnlyValidOps()

	/**
	 * The onOverdue rule: scheduled trigger with a valid interval + filter,
	 * a `field` recipient (matches the retired job's `approverUserId`
	 * lookup 1:1), and only validator-recognised channels.
	 *
	 * @return void
	 */
	public function testOnOverdueNotificationRuleShape(): void {
		$schema = $this->subsidyAccountabilitySchema($this->loadFragment());
		$rule = ($schema['x-openregister-notifications']['onOverdue'] ?? null);
		self::assertIsArray($rule);

		$trigger = $rule['trigger'];
		self::assertSame('scheduled', $trigger['type']);
		self::assertIsInt($trigger['intervalSec']);
		self::assertGreaterThanOrEqual(60, $trigger['intervalSec']);
		self::assertSame(86400, $trigger['intervalSec'], 'Matches the retired job\'s 24h re-check cadence.');
		self::assertIsArray($trigger['filter']);
		self::assertSame(['isOverdue' => true], $trigger['filter']);

		self::assertCount(1, $rule['recipients']);
		self::assertContains($rule['recipients'][0]['kind'], self::VALID_RECIPIENT_KINDS);
		self::assertSame('field', $rule['recipients'][0]['kind']);
		self::assertSame('approverUserId', $rule['recipients'][0]['field']);

		self::assertIsArray($rule['channels']);
		self::assertNotEmpty($rule['channels']);
		foreach ($rule['channels'] as $channel) {
			self::assertContains($channel, self::VALID_CHANNELS);
		}

		// `subject` is the canonical per-locale map (ADR-031). The
		// validator accepts a bare string too, but this rule carries the
		// interpolated identifiers the retired job put in its body, so
		// both locales must render them with the `{{prop}}` token — the
		// legacy `@self.` form is what gate-18 rejects.
		$subject = $rule['subject'];
		self::assertIsArray($subject, 'subject must be a per-locale map.');
		foreach (['nl', 'en'] as $locale) {
			self::assertArrayHasKey($locale, $subject);
			self::assertIsString($subject[$locale]);
			self::assertNotSame('', $subject[$locale]);
			self::assertStringContainsString('{{verantwoordingId}}', $subject[$locale]);
			self::assertStringContainsString('{{grantId}}', $subject[$locale]);
			self::assertStringNotContainsString('@self.', $subject[$locale]);
		}

	}//end testOnOverdueNotificationRuleShape()

	/**
	 * Regression lock for the pre-existing "in-app" channel bug fixed
	 * alongside the restore: `"in-app"` is not in
	 * `NotificationAnnotationValidator::VALID_CHANNELS` (the dispatcher
	 * only recognises the literal `"nc-notification"`), so every
	 * SubsidieVerantwoording/AuditorStatement notification declaring it
	 * never actually rendered an in-app NC notification. Locks in that
	 * the fragment no longer contains the typo anywhere.
	 *
	 * @return void
	 */
	public function testNoNotificationRuleUsesTheInvalidInAppChannel(): void {
		$fragment = $this->loadFragment();
		$raw = (string)file_get_contents($this->fragmentPath);
		self::assertStringNotContainsString('"in-app"', $raw);

		foreach (['SubsidieVerantwoording', 'AuditorStatement'] as $schemaName) {
			$rules = $fragment['components']['schemas'][$schemaName]['x-openregister-notifications'];
			foreach ($rules as $ruleName => $rule) {
				foreach ($rule['channels'] as $channel) {
					self::assertContains(
						$channel,
						self::VALID_CHANNELS,
						sprintf('%s.%s declares an invalid channel "%s".', $schemaName, (string)$ruleName, (string)$channel)
					);
				}
			}
		}

	}//end testNoNotificationRuleUsesTheInvalidInAppChannel()

	// -----------------------------------------------------------------
	// Functional mirror-evaluation
	// -----------------------------------------------------------------

	/**
	 * Load the two calculation expression trees from the fragment.
	 *
	 * @return array<string,mixed>
	 */
	private function loadCalcs(): array {
		$schema = $this->subsidyAccountabilitySchema($this->loadFragment());
		return $schema['x-openregister-calculations'];
	}//end loadCalcs()

	/**
	 * Evaluate every declared calc, in declaration order, into $object —
	 * mirroring `CalculationOnSaveListener::process()`'s sequential
	 * materialisation so `isOverdue`'s `{"prop": "daysSinceAward"}`
	 * resolves against the already-computed sibling value.
	 *
	 * @param array<string,mixed> $calcs The declared calculations.
	 * @param array<string,mixed> $object The seed object data.
	 * @param DateTimeImmutable $now The logical "now".
	 *
	 * @return array<string,mixed> Object data enriched with calc results.
	 */
	private function materialise(array $calcs, array $object, DateTimeImmutable $now): array {
		foreach ($calcs as $name => $spec) {
			$object[$name] = $this->evaluate(expr: $spec['expression'], object: $object, now: $now);
		}

		return $object;
	}//end materialise()

	/**
	 * Minimal evaluator for the operator subset this change's expressions
	 * use (prop, lit, now, diffDays, gt, ne, and). Semantics mirror
	 * `CalculationEvaluator`: `gt`/`ne` are null-safe comparisons (a null
	 * operand never satisfies `gt`), `diffDays` returns null when either
	 * operand is not a parseable date, and `diffDays(later, earlier)` is
	 * `floor((later - earlier) / 86400)`.
	 *
	 * @param mixed $expr The expression (sub)tree, or a bare scalar.
	 * @param array<string,mixed> $object The object data (includes prior-materialised calcs).
	 * @param DateTimeImmutable $now The logical "now" `{"now": []}` resolves to.
	 *
	 * @return mixed The evaluated value.
	 */
	private function evaluate(mixed $expr, array $object, DateTimeImmutable $now): mixed {
		if (is_array($expr) === false) {
			return $expr;
		}

		$op = array_key_first($expr);
		$args = $expr[$op];

		return match ($op) {
			'prop' => $this->propValue(args: $args, object: $object),
			'lit' => $args,
			'now' => $now,
			'diffDays' => $this->diffDays(args: $args, object: $object, now: $now),
			'gt' => $this->compare(args: $args, object: $object, now: $now, op: 'gt'),
			'ne' => $this->compare(args: $args, object: $object, now: $now, op: 'ne'),
			'and' => $this->reduceAnd(args: $args, object: $object, now: $now),
			default => self::fail('Mirror evaluator does not implement operator "' . (string)$op . '".'),
		};

	}//end evaluate()

	/**
	 * Resolve a `{"prop": "name"}` reference against the object data.
	 *
	 * @param mixed $args Property name (string) or single-element array containing it.
	 * @param array<string,mixed> $object The object data.
	 *
	 * @return mixed The resolved value, or null when absent.
	 */
	private function propValue(mixed $args, array $object): mixed {
		$name = $args;
		if (is_string($args) === false) {
			$name = $args[0];
		}

		return ($object[$name] ?? null);
	}//end propValue()

	/**
	 * `{"diffDays": [later, earlier]}` — floor day difference, or null
	 * when either operand is not a parseable date.
	 *
	 * @param mixed $args Two-operand list.
	 * @param array<string,mixed> $object The object data.
	 * @param DateTimeImmutable $now The logical "now".
	 *
	 * @return int|null
	 */
	private function diffDays(mixed $args, array $object, DateTimeImmutable $now): ?int {
		$later = $this->toDate($this->evaluate(expr: $args[0], object: $object, now: $now));
		$earlier = $this->toDate($this->evaluate(expr: $args[1], object: $object, now: $now));
		if ($later === null || $earlier === null) {
			return null;
		}

		return (int)floor(($later->getTimestamp() - $earlier->getTimestamp()) / 86400);
	}//end diffDays()

	/**
	 * Coerce a mirror-evaluated value to a date, or null.
	 *
	 * @param mixed $value The value to coerce.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function toDate(mixed $value): ?DateTimeImmutable {
		if ($value instanceof DateTimeImmutable) {
			return $value;
		}

		if (is_string($value) === true && $value !== '') {
			try {
				return new DateTimeImmutable($value);
			} catch (\Throwable) {
				return null;
			}
		}

		return null;
	}//end toDate()

	/**
	 * Null-safe `gt`/`ne` comparison, mirroring `CalculationEvaluator::compare()`.
	 *
	 * @param mixed $args Two-operand list.
	 * @param array<string,mixed> $object The object data.
	 * @param DateTimeImmutable $now The logical "now".
	 * @param string $op 'gt' or 'ne'.
	 *
	 * @return bool
	 */
	private function compare(mixed $args, array $object, DateTimeImmutable $now, string $op): bool {
		$a = $this->evaluate(expr: $args[0], object: $object, now: $now);
		$b = $this->evaluate(expr: $args[1], object: $object, now: $now);

		return match ($op) {
			'gt' => ($a !== null && $b !== null && $a > $b),
			'ne' => ($a != $b),
			default => false,
		};

	}//end compare()

	/**
	 * `{"and": [...]}` — short-circuiting logical AND.
	 *
	 * @param mixed $args List of sub-expressions.
	 * @param array<string,mixed> $object The object data.
	 * @param DateTimeImmutable $now The logical "now".
	 *
	 * @return bool
	 */
	private function reduceAnd(mixed $args, array $object, DateTimeImmutable $now): bool {
		foreach ($args as $sub) {
			if ($this->evaluate(expr: $sub, object: $object, now: $now) !== true) {
				return false;
			}
		}

		return true;
	}//end reduceAnd()

	/**
	 * A draft report more than 90 days after award is overdue (REQ-SUBV-010) —
	 * mirrors the retired job's `testDraftBeyond90DaysIsOverdue`.
	 *
	 * @return void
	 */
	public function testDraftBeyond90DaysIsOverdue(): void {
		$now = new DateTimeImmutable('2026-06-01');
		$object = ['status' => 'draft', 'awardDate' => '2026-01-01'];
		$result = $this->materialise(calcs: $this->loadCalcs(), object: $object, now: $now);

		self::assertTrue($result['isOverdue']);

	}//end testDraftBeyond90DaysIsOverdue()

	/**
	 * A submitted report within 90 days is NOT overdue (REQ-SUBV-010) —
	 * mirrors `testSubmittedWithin90DaysNotOverdue`.
	 *
	 * @return void
	 */
	public function testSubmittedWithin90DaysNotOverdue(): void {
		$now = new DateTimeImmutable('2026-03-01');
		$object = ['status' => 'submitted', 'awardDate' => '2026-01-01'];
		$result = $this->materialise(calcs: $this->loadCalcs(), object: $object, now: $now);

		self::assertFalse($result['isOverdue']);

	}//end testSubmittedWithin90DaysNotOverdue()

	/**
	 * A final report is never overdue regardless of age — mirrors
	 * `testFinalReportNeverOverdue`.
	 *
	 * @return void
	 */
	public function testFinalReportNeverOverdue(): void {
		$now = new DateTimeImmutable('2027-01-01');
		$object = ['status' => 'final', 'awardDate' => '2024-01-01'];
		$result = $this->materialise(calcs: $this->loadCalcs(), object: $object, now: $now);

		self::assertFalse($result['isOverdue']);

	}//end testFinalReportNeverOverdue()

	/**
	 * The 90-day boundary is exclusive: exactly 90 days is not yet
	 * overdue, 91 days is overdue — mirrors `testNinetyDayBoundary`.
	 *
	 * @return void
	 */
	public function testNinetyDayBoundary(): void {
		$calcs = $this->loadCalcs();
		$award = '2026-01-01';

		$day90 = $this->materialise(
			calcs: $calcs,
			object: ['status' => 'draft', 'awardDate' => $award],
			now: new DateTimeImmutable('2026-04-01')
		);
		self::assertSame(90, $day90['daysSinceAward']);
		self::assertFalse($day90['isOverdue']);

		$day91 = $this->materialise(
			calcs: $calcs,
			object: ['status' => 'draft', 'awardDate' => $award],
			now: new DateTimeImmutable('2026-04-02')
		);
		self::assertSame(91, $day91['daysSinceAward']);
		self::assertTrue($day91['isOverdue']);

	}//end testNinetyDayBoundary()

	/**
	 * A record with a null/unset awardDate is never overdue — the
	 * documented null-safe fallback (issue #505 task 4): `diffDays`
	 * returns null on an unparseable operand, and `gt(null, 90)` is
	 * false, so no reportingPeriod-split guess is attempted.
	 *
	 * @return void
	 */
	public function testNullAwardDateNeverOverdue(): void {
		$now = new DateTimeImmutable('2026-12-01');
		$object = ['status' => 'draft', 'awardDate' => null];
		$result = $this->materialise(calcs: $this->loadCalcs(), object: $object, now: $now);

		self::assertNull($result['daysSinceAward']);
		self::assertFalse($result['isOverdue']);

	}//end testNullAwardDateNeverOverdue()

	/**
	 * An approved report (the third non-final state) is also covered by
	 * the same `status != final` rule, not just draft/submitted.
	 *
	 * @return void
	 */
	public function testApprovedBeyond90DaysIsOverdue(): void {
		$now = new DateTimeImmutable('2026-12-01');
		$object = ['status' => 'approved', 'awardDate' => '2026-01-01'];
		$result = $this->materialise(calcs: $this->loadCalcs(), object: $object, now: $now);

		self::assertTrue($result['isOverdue']);

	}//end testApprovedBeyond90DaysIsOverdue()
}//end class
