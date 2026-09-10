<?php

/**
 * No code under lib/ pins a superseded register slug.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The case that has never once been caught.
 *
 * A consumer pinned to a superseded register slug on a MIGRATED instance does
 * not raise. `openregister_registers` has no row with that slug, so the read
 * matches nothing and returns an empty result set, which is byte for byte what
 * a healthy, empty register returns. There is no exception, no 404, no log line
 * that separates the two. Every other guard in this repository watches
 * behaviour, and this defect has no behaviour to watch: it is a feature that
 * quietly stops happening.
 *
 * So the guard is static, and it is repo-wide rather than diff-scoped. Diff
 * scope is right for debt a PR could reasonably be asked to carry; it is wrong
 * here, because every one of these references was written BEFORE the slug was
 * renamed and will therefore never appear in a diff. A diff-scoped version of
 * this test passes on a repository full of the defect.
 *
 * ## What it does NOT catch
 *
 * It reads lines, not data flow. A superseded slug arriving from app config,
 * from a manifest, or through more than one assignment is invisible to it, as
 * is a `match` arm built at run time. That is why
 * {@see \OCA\Shillinq\Tests\Unit\Controller\ExternalAdaptersRegisterResolutionTest}
 * exists beside it: this guard stops the literal being TYPED, and that one
 * stops the resolved slug being IGNORED.
 *
 * Measured on the mutation that reinstated `openconnector` in
 * `ExternalAdaptersAdminController`: the unmigrated-instance test still passed,
 * because on an unmigrated instance the pinned literal happens to be the right
 * answer. Only the migrated case failed, and only the migrated case has ever
 * mattered.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RegisterSlugPinTest extends TestCase {

	/**
	 * Superseded register slug => the canonical slug replacing it.
	 *
	 * openregister owns the full fleet map in
	 * `lib/Support/RegisterSlugAliases.php`, which is not published
	 * to consumers, and this list is transcribed from it rather than derived: the
	 * map is not recoverable from the app-rename map, because `stackiq` renamed
	 * the register `voorzieningen` while its former app id `softwarecatalog` was
	 * never a register slug on any instance.
	 *
	 * All ten, and this list used to hold one. It said it covered "the slugs
	 * THIS app could plausibly type", and that premise is measurably wrong about
	 * this repository. A sweep of register position under `lib/` finds
	 * `integriq`, `pipelinq` and `portaliq` already there, beside the 77 sites
	 * naming `shillinq` itself. Billing reads what it bills for, so it reaches
	 * into other apps' registers by design, and a guard scoped to `openconnector`
	 * alone watched the one slug this repository has already been cleaned of.
	 *
	 * `hrmq` is now listed, and the note saying it was deliberately absent was
	 * wrong about why. {@see \OCA\Shillinq\Service\HrmqCostRateAdapter} probes
	 * `['humaniq', 'hrmq']` in order and caches whichever answered, which is the
	 * resolution this guard exists to require, hand rolled before the contract
	 * was published. That line is an array, and every pattern below needs a
	 * single quoted slug after the operator, so listing `hrmq` never could have
	 * flagged it. Measured, not reasoned: the widened patterns over all ten slugs
	 * report nothing under `lib/`.
	 *
	 * @var array<string, string>
	 */
	private const SUPERSEDED = [
		'openconnector'   => 'integriq',
		'openbuild'       => 'buildiq',
		'decidesk'        => 'decidiq',
		'hrmq'            => 'humaniq',
		'larpingapp'      => 'larpinq',
		'planix'          => 'planninq',
		'voorzieningen'   => 'stackiq',
		'procest'         => 'dossiq',
		'procest-default' => 'dossiq-default',
		'scholiq'         => 'learniq',
	];

	/**
	 * Files allowed to name a superseded slug, and why.
	 *
	 * Each entry must be a genuine exception, a file that exists in order to
	 * name the old slug, not a deferral. Anything else belongs in a resolver
	 * call.
	 *
	 * `FleetAppId` is the standing example, and it is the distinction this whole
	 * mechanism turns on: it maps APP IDS, whose source of truth is
	 * `IAppManager`, not register slugs, whose source of truth is
	 * `openregister_registers`. Two different repair steps move them and either
	 * can run first, so one cannot be used to predict the other.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED = [
		'lib/Support/FleetAppId.php' => 'app ids, not register slugs; a different question with a different source of truth',
	];

	/**
	 * Source patterns that put a string literal in REGISTER position.
	 *
	 * Deliberately narrow. A slug is only a defect where it identifies a
	 * register; the same word in a log message, a skip reason, an app id or a
	 * seeded workflow `engine` is not this defect, and a guard that flagged
	 * those would be turned off.
	 *
	 * The last three cover the NULL-COALESCING DEFAULT, and they are the reason
	 * this list is eight long rather than five. integriq shipped
	 * `register: ($data['register'] ?? 'openconnector')` in MappingsController
	 * and this guard, which is the same guard, did not see it: all five of the
	 * original patterns require the quote to follow `register:` directly, and
	 * the coalesce operator sits in between. It was found by a hand grep. A
	 * default is the likeliest place for a pin to survive a rename, because it
	 * is the branch nobody exercises on a healthy instance.
	 *
	 * @var list<string>
	 */
	private const REGISTER_POSITION = [
		'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\bregister:\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\'register\'\s*=>\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
		'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/',
	];

	/**
	 * A line whose first non-whitespace character opens or continues a comment.
	 *
	 * Comment lines are skipped, and the reason is the judgement call this whole
	 * guard turns on. A docblock that DESCRIBES a read is prose, not register
	 * position: it cannot make a request go to the wrong register, so flagging
	 * it is a false finding. False findings are not harmless here. The way this
	 * guard dies is that someone hits one, adds the file to ALLOWED to get green,
	 * and the real pin in that same file stops being watched. A stale docblock is
	 * still worth fixing, and the one in ExternalAdaptersAdminController was
	 * fixed alongside the pin it described, but it is a prose defect and this is
	 * not the instrument for it.
	 *
	 * PHP cannot put a string literal in register position on a line that opens
	 * with `*`, `//` or `#`, so nothing real is lost. A pin with a trailing
	 * comment still starts with code and is still caught.
	 *
	 * @var string
	 */
	private const COMMENT_LINE = '/^\s*(\*|\/\/|\/\*|#)/';

	/**
	 * No file under lib/ names a superseded register slug in register position.
	 *
	 * @return void
	 */
	public function testNoSourceFilePinsASupersededRegisterSlug(): void {
		$findings = [];
		foreach ($this->sourceFiles() as $relative => $absolute) {
			if (isset(self::ALLOWED[$relative]) === true) {
				continue;
			}

			// NOT FILE_SKIP_EMPTY_LINES. Skipping blank lines renumbers every
			// line after the first one, so `$index + 1` stops being the line
			// number and becomes the count of non-blank lines. Measured on
			// openregister's reconciler: a pin on line 590 was reported as line
			// 528, because 62 blank lines preceded it. A guard that names the
			// wrong line is a guard whose next reader concludes it is broken.
			$lines = file($absolute, FILE_IGNORE_NEW_LINES);
			if ($lines === false) {
				continue;
			}

			foreach ($lines as $index => $line) {
				if (preg_match(self::COMMENT_LINE, $line) === 1) {
					continue;
				}

				foreach (self::REGISTER_POSITION as $pattern) {
					if (preg_match($pattern, $line, $matches) !== 1) {
						continue;
					}

					$slug = strtolower($matches[1]);
					if (isset(self::SUPERSEDED[$slug]) === false) {
						continue;
					}

					$findings[] = sprintf(
						'%s:%d pins the superseded register slug \'%s\'. Resolve \'%s\' through '
						. 'RegisterSlugResolverInterface::resolve() instead, and branch on isResolved(), '
						. 'because reading with a slug this instance does not carry returns zero rows, '
						. 'not an error.',
						$relative,
						($index + 1),
						$slug,
						self::SUPERSEDED[$slug]
					);
				}
			}
		}

		$this->assertSame([], $findings, "Superseded register slugs are pinned:\n" . implode("\n", $findings));
	}//end testNoSourceFilePinsASupersededRegisterSlug()

	/**
	 * The guard actually looks at something.
	 *
	 * A file walker that silently finds no files is the classic hollow green:
	 * the assertion above would pass on an empty list forever. This pins the
	 * walker to a floor well below the real count (732 PHP files under lib/ when
	 * this was written), so a broken path fails here rather than passing there.
	 *
	 * @return void
	 */
	public function testTheGuardScansTheSourceTree(): void {
		$files = $this->sourceFiles();

		$this->assertGreaterThan(500, count($files), 'The walker must see lib/, or the guard above cannot fail.');
		$this->assertArrayHasKey(
			'lib/Controller/ExternalAdaptersAdminController.php',
			$files,
			'The adapters controller is the file this guard was written for; the walker must reach it.'
		);
		$this->assertArrayHasKey(
			'lib/Support/FleetAppId.php',
			$files,
			'The one allow-listed file must be reachable, or its entry silently stops meaning anything.'
		);
	}//end testTheGuardScansTheSourceTree()

	/**
	 * The patterns match a pinned slug when one is present.
	 *
	 * Watched failing is not enough on its own once the tree is clean: from then
	 * on the guard passes whether or not its regexes still work. This feeds each
	 * register-position form a known-bad line and requires a match, so a regex
	 * that stops matching reddens immediately instead of going quiet.
	 *
	 * The sixth sample is not invented. It is integriq's MappingsController line
	 * as it stood on `development`, copied verbatim, and it is here because the
	 * five patterns above let it through.
	 *
	 * @return void
	 */
	public function testEachRegisterPositionPatternStillMatches(): void {
		$samples = [
			'/setRegister\(\s*(?:register:\s*)?\'([a-zA-Z0-9_-]+)\'/' => "\$objectService->setRegister('openconnector');",
			'/\bregister:\s*\'([a-zA-Z0-9_-]+)\'/'                    => "\$svc->find(id: \$id, register: 'openconnector', schema: 'source');",
			'/\'register\'\s*=>\s*\'([a-zA-Z0-9_-]+)\'/'              => "'filters' => ['register' => 'openconnector', 'schema' => 'source'],",
			'/\bconst\s+[A-Z0-9_]*REGISTER[A-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\tprivate const CONNECTOR_REGISTER = 'openconnector';",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$registerSlug = 'openconnector';",
			'/\bregister:\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/'   => "\t\t\tregister: (\$data['register'] ?? 'openconnector'),",
			'/\'register\'\s*=>\s*\(?[^,()]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/' => "'register' => (\$data['register'] ?? 'hrmq'),",
			'/\$[a-zA-Z0-9_]*(?:[Rr]egister|[Ss]lug)[a-zA-Z0-9_]*\s*=\s*[^;]*\?\?\s*\'([a-zA-Z0-9_-]+)\'/' => "\t\t\$register = \$resolution?->slug ?? 'hrmq';",
		];

		foreach (self::REGISTER_POSITION as $pattern) {
			$this->assertArrayHasKey($pattern, $samples, 'Every register-position pattern needs a known-bad sample.');
			$this->assertSame(
				1,
				preg_match($pattern, $samples[$pattern], $matches),
				'Pattern must match its known-bad sample: ' . $pattern
			);
			$this->assertArrayHasKey(
				strtolower($matches[1]),
				self::SUPERSEDED,
				'The sample must capture a slug this guard calls superseded: ' . $pattern
			);
		}
	}//end testEachRegisterPositionPatternStillMatches()

	/**
	 * Prose is skipped and code is not, on lines that differ only in a leading `*`.
	 *
	 * The skip above is a hole by construction, so it gets its own assertion
	 * rather than being left to whatever the tree happens to contain. Both
	 * samples carry the same superseded slug in the same register position; only
	 * the comment marker separates them.
	 *
	 * @return void
	 */
	public function testProseIsSkippedButTheSameLineAsCodeIsNot(): void {
		$prose = "\t * for `register: 'openconnector', schema: 'source'` filtered by";
		$code = "\t\t\t->setRegister('openconnector')";

		$this->assertSame(1, preg_match(self::COMMENT_LINE, $prose), 'A docblock line must be recognised as prose.');
		$this->assertSame(0, preg_match(self::COMMENT_LINE, $code), 'A code line must not be.');

		$this->assertSame(
			1,
			preg_match(self::REGISTER_POSITION[0], $code, $matches),
			'The code sample must still be matched by a register-position pattern.'
		);
		$this->assertArrayHasKey(strtolower($matches[1]), self::SUPERSEDED);
	}//end testProseIsSkippedButTheSameLineAsCodeIsNot()

	/**
	 * Every PHP file under lib/, keyed by repository-relative path.
	 *
	 * @return array<string, string> Relative path => absolute path.
	 */
	private function sourceFiles(): array {
		$root = dirname(__DIR__, 3);
		$lib = ($root . '/lib');

		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($lib, RecursiveDirectoryIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if (($file instanceof SplFileInfo) === false || $file->isFile() === false) {
				continue;
			}

			if ($file->getExtension() !== 'php') {
				continue;
			}

			$path = $file->getPathname();
			$files[ltrim(str_replace($root, '', $path), '/')] = $path;
		}

		return $files;
	}//end sourceFiles()
}//end class
