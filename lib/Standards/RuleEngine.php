<?php

/**
 * Rule Engine
 *
 * Evaluates a bookkeeping object against the machine-checkable rules that apply
 * to it, returning structured Violations. This is the executable layer over the
 * static RuleCatalogue: each registered check is a pure predicate over an object
 * (plus context), keyed by a real catalogue rule id, so a violation carries the
 * rule's severity and source straight from the corpus. Applicability is scoped by
 * jurisdiction (a rule applies to its own country, plus EU-wide rules for EU
 * members and `global` rules everywhere) so the engine only enforces what the
 * administration is actually subject to.
 *
 * Only rules with a registered predicate here are enforced today; the rest of the
 * ~1,300-rule corpus is catalogued and grows an executable check per wave. The
 * predicates are side-effect free and unit-tested; the lifecycle wiring + object
 * loading live in OCA\Shillinq\Lifecycle\RuleComplianceGuard.
 *
 * @category Standards
 * @package  OCA\Shillinq\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-rule-engine/specs/bookkeeping-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Standards;

/**
 * Evaluates objects against applicable machine-checkable rules.
 */
final class RuleEngine
{

    /**
     * Memoised rule index (id => rule), built from RuleCatalogue.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $index = null;


    /**
     * The registered predicates, keyed by object type then rule id. Each
     * predicate is `fn(array $object, array $context): bool` — true = the rule is
     * satisfied. Every key is a real RuleCatalogue id.
     *
     * @return array<string, array<string, callable>>
     */
    private static function checks(): array
    {
        return [
            'ARInvoice' => [
                // EN 16931 mandatory invoice fields (BT-1/2/5/109/112) mapped to the ARInvoice header.
                'en16931-br-02'                   => static fn(array $o): bool => self::present($o, 'invoiceNumber'),
                'en16931-br-03'                   => static fn(array $o): bool => self::present($o, 'invoiceDate'),
                'en16931-br-05'                   => static fn(array $o): bool => self::present($o, 'currency'),
                'en16931-br-13'                   => static fn(array $o): bool => self::numericPresent($o, 'netAmount'),
                'en16931-br-14'                   => static fn(array $o): bool => self::numericPresent($o, 'grossAmount'),
                // BR-CO-15: total with VAT = total without VAT + total VAT (at cent precision).
                'en16931-br-co-15'                => static fn(array $o): bool => self::centsEqual(
                    (float) ($o['grossAmount'] ?? 0),
                    ((float) ($o['netAmount'] ?? 0) + (float) ($o['vatAmount'] ?? 0))
                ),
                // A gapless sequential invoice number — presence is enforceable per-object.
                'gl-sequential-invoice-numbering' => static fn(array $o): bool => self::present($o, 'invoiceNumber'),
                // Buyer / customer must be identified (VAT Directive art. 226(5) / EN 16931 BT-44).
                'vatdir-art226-5'                 => static fn(array $o): bool => self::present($o, 'customerId'),
                'en16931-br-07'                   => static fn(array $o): bool => self::present($o, 'customerId'),
                // The VAT amount payable must be stated (VAT Directive art. 226(10)).
                'vatdir-art226-10'                => static fn(array $o): bool => self::numericPresent($o, 'vatAmount'),
            ],
            'GLTransaction' => [
                // Completeness: a real double entry — at least two lines, each with an account and a non-zero, sided amount.
                'gl-completeness-timeliness'      => static fn(array $o): bool => self::glComplete($o),
                'gl-sequential-journal-numbering' => static fn(array $o): bool => self::present($o, 'transactionNumber'),
                // Recorded with a posting date (chronological order / timeliness).
                'gl-chronological-order'          => static fn(array $o): bool => self::present($o, 'postingDate'),
                // Each entry traceable to a source document (Belegfunktion / GoBD).
                'gl-source-document-traceability' => static fn(array $o): bool => self::present($o, 'sourceReference'),
            ],
        ];

    }//end checks()


    /**
     * Evaluate an object of $objectType against its applicable machine-checkable
     * rules, returning the Violations (empty when compliant).
     *
     * @param string               $objectType OpenRegister schema name (e.g. `ARInvoice`).
     * @param array<string, mixed> $object     The object (GL transactions also carry `lines`).
     * @param array<string, mixed> $context    `{ jurisdiction?: string }` — defaults to NL.
     *
     * @return array<int, Violation>
     */
    public static function evaluate(string $objectType, array $object, array $context=[]): array
    {
        $rules      = self::index();
        $violations = [];

        foreach ((self::checks()[$objectType] ?? []) as $ruleId => $predicate) {
            $rule = ($rules[$ruleId] ?? null);
            if ($rule === null || self::applies($rule, $context) === false) {
                continue;
            }

            $satisfied = false;
            try {
                $satisfied = (bool) $predicate($object, $context);
            } catch (\Throwable $e) {
                $satisfied = false;
            }

            if ($satisfied === false) {
                $violations[] = self::violationFor($ruleId);
            }
        }

        return $violations;

    }//end evaluate()


    /**
     * True when any violation is `mandatory` (i.e. a lifecycle guard must block).
     *
     * @param array<int, Violation> $violations Violations to inspect.
     *
     * @return bool
     */
    public static function hasMandatory(array $violations): bool
    {
        foreach ($violations as $violation) {
            if ($violation->severity === 'mandatory') {
                return true;
            }
        }

        return false;

    }//end hasMandatory()


    /**
     * Build a Violation for a rule id from the catalogue (severity/source/text).
     *
     * @param string $ruleId The catalogue rule id.
     *
     * @return Violation
     */
    public static function violationFor(string $ruleId): Violation
    {
        $rule = (self::index()[$ruleId] ?? null);
        return new Violation(
            $ruleId,
            (string) ($rule['severity'] ?? 'mandatory'),
            (string) ($rule['source'] ?? $ruleId),
            (string) ($rule['statement'] ?? '')
        );

    }//end violationFor()


    /**
     * Object types that have at least one registered executable check.
     *
     * @return array<int, string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::checks());

    }//end supportedTypes()


    /**
     * All catalogue rule ids that have a registered executable check (across all
     * object types) — i.e. the rules the engine can actually enforce today.
     *
     * @return array<int, string>
     */
    public static function checkedRuleIds(): array
    {
        $ids = [];
        foreach (self::checks() as $byRule) {
            foreach (array_keys($byRule) as $ruleId) {
                $ids[$ruleId] = true;
            }
        }

        return array_keys($ids);

    }//end checkedRuleIds()


    /**
     * Reset the memoised index (test hook).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$index = null;

    }//end reset()


    /**
     * Whether a rule applies to the context jurisdiction: its own country, plus
     * EU-wide rules for EU members and `global` rules everywhere.
     *
     * @param array<string, mixed> $rule    The catalogue rule.
     * @param array<string, mixed> $context Evaluation context.
     *
     * @return bool
     */
    private static function applies(array $rule, array $context): bool
    {
        $ruleJ = strtoupper((string) ($rule['jurisdiction'] ?? ''));
        $code  = strtoupper((string) ($context['jurisdiction'] ?? 'NL'));

        if ($ruleJ === $code || $ruleJ === 'GLOBAL') {
            return true;
        }

        return $ruleJ === 'EU' && in_array($code, ComplianceCatalogue::EU_MEMBER_STATES, true);

    }//end applies()


    /**
     * @param array<string, mixed> $o   Object.
     * @param string               $key Field.
     *
     * @return bool True when the field is a non-empty value.
     */
    private static function present(array $o, string $key): bool
    {
        return isset($o[$key]) === true && trim((string) $o[$key]) !== '';

    }//end present()


    /**
     * @param array<string, mixed> $o   Object.
     * @param string               $key Field.
     *
     * @return bool True when the field is a present, numeric value.
     */
    private static function numericPresent(array $o, string $key): bool
    {
        return isset($o[$key]) === true && $o[$key] !== '' && is_numeric($o[$key]) === true;

    }//end numericPresent()


    /**
     * @param float $a Left amount.
     * @param float $b Right amount.
     *
     * @return bool True when equal at cent precision (avoids float-equality issues).
     */
    private static function centsEqual(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);

    }//end centsEqual()


    /**
     * A GL transaction is "complete" when it has at least two lines and every
     * line carries an account, a non-zero amount and a valid debit/credit side.
     *
     * @param array<string, mixed> $o The GL transaction, with `lines`.
     *
     * @return bool
     */
    private static function glComplete(array $o): bool
    {
        $lines = ($o['lines'] ?? []);
        if (is_array($lines) === false || count($lines) < 2) {
            return false;
        }

        foreach ($lines as $line) {
            if (is_array($line) === false) {
                return false;
            }

            $hasAccount = trim((string) ($line['accountNumber'] ?? '')) !== '';
            $nonZero    = ((int) round(((float) ($line['amount'] ?? 0)) * 100)) !== 0;
            $sided      = in_array(($line['side'] ?? ''), ['debit', 'credit'], true);
            if ($hasAccount === false || $nonZero === false || $sided === false) {
                return false;
            }
        }

        return true;

    }//end glComplete()


    /**
     * Build the id => rule index from RuleCatalogue (memoised).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (RuleCatalogue::all() as $rule) {
            $index[(string) $rule['id']] = $rule;
        }

        self::$index = $index;
        return $index;

    }//end index()


}//end class
