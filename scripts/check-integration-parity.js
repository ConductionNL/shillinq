#!/usr/bin/env node

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * App-side integration parity gate (hydra gate-24, `integration-parity`).
 *
 * WHAT THIS CHECKS, AND WHY IT LIVES HERE
 * ---------------------------------------
 * A leaf has TWO faces (ADR-019 AD-11/AD-13, ADR-066 decisions 4 and 7):
 *
 *   a SERVER face — either a `LeafDescriptor` contributed through
 *     `RegisterLeafProvidersEvent`, or an `IntegrationProvider` registered on
 *     OpenRegister's `IntegrationRegistry`. This face is what the
 *     `openregister.integrations.leaves` capability advertises.
 *   a JS face — a `registerIntegration({ id, … })` call that mounts the
 *     render pair on `window.OCA.OpenRegister.integrations`.
 *
 * The two are correlated ONLY by a shared `id`. Nothing at runtime notices when
 * one half is missing or when the two halves disagree: a descriptor with no JS
 * registration advertises a tab that renders nothing (a PHANTOM render
 * surface), and a JS registration with no server face mounts a widget that no
 * capability consumer can discover (an ORPHAN registration). A renderMode
 * disagreement is worse still — the host hands a `mount` leaf an SFC slot, or
 * asks a `component` leaf for a `mount()` it does not have, and the surface is
 * blank with no error.
 *
 * The canonical Node check that ships in `@conduction/nextcloud-vue`
 * (`scripts/check-integration-parity.js`) validates THAT library's own built-in
 * descriptors, and its ADR-066 cross-reference over `process.cwd()` is
 * WARN-only and self-disables in a repo with no `new LeafDescriptor(` at all.
 * Neither property is usable as an app-side gate: the library's `scripts/` dir
 * is not published to npm, so in CI the resolution fails and the historic
 * wrapper exits 0 having checked nothing. This check is therefore SELF-CONTAINED
 * — it reads only this repo's own sources, needs no node_modules, and FAILS.
 *
 * RULES (all hard; any violation exits 1)
 * ---------------------------------------
 *   R1  render pair       a JS registration declaring its own id must ship a
 *                         COMPLETE render pair for its renderMode:
 *                         `mount` + `unmount` for renderMode 'mount',
 *                         `tab` + `widget` for 'component' (the default).
 *   R2  id correlation    every server-side render-surface leaf id has a JS
 *                         registration; every data-provider leaf id is at
 *                         least NAMED somewhere in `src/**`, so the data it
 *                         serves is reachable from this app's frontend; and
 *                         every JS registration id has a server-side face
 *                         (descriptor OR provider).
 *   R3  renderMode        for a shared id, both faces declare the same
 *                         renderMode.
 *   R4  metadata          for a shared id, every field BOTH faces declare
 *                         statically (label, icon, group, requiredApp,
 *                         referenceType, surfaces) agrees.
 *   R5  spread source     a registration that inherits its identity by
 *                         spreading a descriptor (`...fooIntegration`) must
 *                         import that symbol from `@conduction/nextcloud-vue`
 *                         — the owning package. A spread of a local or
 *                         undefined binding registers a leaf with no id.
 *   R6  offlineConfig     an `offlineConfig` naming schemas/properties must
 *                         name ones this repo actually declares in its
 *                         OpenRegister register/schema JSON under
 *                         `lib/Settings/**`.
 *
 * A rule with no subject matter in this repo reports "0 checked" by name, so
 * the difference between "verified" and "there was nothing to verify" is
 * visible in the output rather than inferred from silence.
 *
 * Exit codes:
 *   0 — every rule that had subject matter passed
 *   1 — at least one parity violation (each printed as a `✗` line)
 */

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = process.argv[2] ? path.resolve(process.argv[2]) : process.cwd()

/**
 * Constants owned by OpenRegister's `LeafDescriptor`, which is not in this
 * repo's tree. Resolving them by name is the only way a static reader can
 * follow `renderMode: LeafDescriptor::RENDER_MODE_MOUNT`.
 *
 * @type {Object<string, string>}
 */
const FOREIGN_CONSTANTS = {
	'LeafDescriptor::RENDER_MODE_MOUNT': 'mount',
	'LeafDescriptor::RENDER_MODE_COMPONENT': 'component',
	'LeafDescriptor::KIND_RENDER_SURFACE': 'render-surface',
	'LeafDescriptor::KIND_DATA_PROVIDER': 'data-provider',
	'LeafDescriptor::KIND_AGENT_RUNNER': 'agent-runner',
}

/**
 * `offlineConfig` keys that name an OpenRegister schema slug, and those that
 * name a property on the planned-item schema. Mirrors
 * `DEFAULT_FIELD_INSPECTION_CONFIG` in `@conduction/nextcloud-vue`.
 *
 * @type {{schemas: string[], plannedFields: string[]}}
 */
const OFFLINE_CONFIG_CONTRACT = {
	schemas: ['plannedSchema', 'referenceSchema', 'resultSchema'],
	plannedFields: ['templateRefField', 'assigneeField', 'dateField', 'titleField'],
}

/**
 * Recursively collect files under `root` matching `test(filename)`.
 *
 * @param {string} root Directory to walk.
 * @param {(name: string) => boolean} test Filename predicate.
 * @param {number} [maxDepth] Maximum recursion depth.
 *
 * @return {string[]} Absolute paths of matching files.
 */
function collectFiles(root, test, maxDepth = 10) {
	const out = []
	if (fs.existsSync(root) === false) {
		return out
	}
	const skip = new Set([
		'node_modules',
		'vendor',
		'.git',
		'dist',
		'build',
		'js',
		'coverage',
	])
	const walk = (dir, depth) => {
		if (depth > maxDepth) {
			return
		}
		let entries
		try {
			entries = fs.readdirSync(dir, { withFileTypes: true })
		} catch {
			return
		}
		for (const ent of entries) {
			if (ent.isDirectory() === true) {
				if (skip.has(ent.name) === false) {
					walk(path.join(dir, ent.name), depth + 1)
				}
			} else if (ent.isFile() === true && test(ent.name) === true) {
				out.push(path.join(dir, ent.name))
			}
		}
	}
	walk(root, 0)
	return out
}

/**
 * The closing delimiter for each opening one.
 *
 * @type {Object<string, string>}
 */
const CLOSING = { '(': ')', '{': '}', '[': ']' }

/**
 * Characters that open a string literal.
 *
 * @type {string[]}
 */
const QUOTES = ["'", '"', '`']

/**
 * Advance the scan's string-literal state over `text[i]`.
 *
 * @param {object} scan The scan state (text, hash, quote, opens, closes).
 * @param {number} i The index.
 *
 * @return {boolean} True when `text[i]` belongs to a string literal, its quotes included.
 */
function inQuote(scan, i) {
	if (scan.quote !== null) {
		endQuoteAt(scan, i)
		return true
	}
	if (QUOTES.includes(scan.text[i]) === true) {
		scan.quote = scan.text[i]
		return true
	}
	return false
}

/**
 * Close the open string literal when `text[i]` is its unescaped quote.
 *
 * @param {object} scan The scan state.
 * @param {number} i The index.
 *
 * @return {void}
 */
function endQuoteAt(scan, i) {
	if (scan.text[i] === scan.quote && scan.text[i - 1] !== '\\') {
		scan.quote = null
	}
}

/**
 * Where to resume when a comment starts at `text[i]`: the newline ending a
 * line comment, the `/` closing a block comment, or the end of the text.
 *
 * @param {object} scan The scan state; `hash` makes `#` start a line comment.
 * @param {number} i The index.
 *
 * @return {number} The index to resume from, -1 when no comment starts here.
 */
function commentEnd(scan, i) {
	const two = scan.text.slice(i, i + 2)
	if (two === '//' || (scan.hash === true && scan.text[i] === '#')) {
		const nl = scan.text.indexOf('\n', i)
		return nl === -1 ? scan.text.length : nl
	}
	if (two === '/*') {
		const end = scan.text.indexOf('*/', i)
		return end === -1 ? scan.text.length : end + 1
	}
	return -1
}

/**
 * How a code character changes the nesting depth.
 *
 * @param {object} scan The scan state; `opens` and `closes` list the delimiters.
 * @param {string} c The character.
 *
 * @return {number} 1, -1 or 0.
 */
function depthStep(scan, c) {
	if (scan.opens.includes(c) === true) {
		return 1
	}
	return scan.closes.includes(c) === true ? -1 : 0
}

/**
 * Extract the balanced text that follows `open` at `src[from]`, honouring
 * string literals and comments so a brace inside a string never unbalances it.
 *
 * @param {string} src Source text.
 * @param {number} from Index of the opening delimiter.
 *
 * @return {string} The text between the delimiters (exclusive), '' when unbalanced.
 */
function balanced(src, from) {
	const scan = bracketScan(src, from)
	let depth = 0
	for (let i = from; i < src.length; i++) {
		const skip = skipNonCode(scan, i)
		if (skip !== -1) {
			i = skip
			continue
		}
		const step = depthStep(scan, src[i])
		depth += step
		if (step === -1 && depth === 0) {
			return src.slice(from + 1, i)
		}
	}
	return ''
}

/**
 * Where to resume when `text[i]` is not code: `i` itself inside a string
 * literal, the comment's end when one starts here.
 *
 * @param {object} scan The scan state.
 * @param {number} i The index.
 *
 * @return {number} The index to resume from, -1 when `text[i]` is code.
 */
function skipNonCode(scan, i) {
	return inQuote(scan, i) === true ? i : commentEnd(scan, i)
}

/**
 * The scan state for `balanced`: only the delimiter at `src[from]` and its
 * closing one nest, and `#` starts a comment only inside parentheses.
 *
 * @param {string} src Source text.
 * @param {number} from Index of the opening delimiter.
 *
 * @return {object} The scan state.
 */
function bracketScan(src, from) {
	const open = src[from]
	const close = CLOSING[open] || ''
	return { text: src, hash: open === '(', quote: null, opens: open, closes: close }
}

/**
 * Split an argument/member list on top-level commas.
 *
 * @param {string} body The text between the delimiters.
 *
 * @return {string[]} Trimmed top-level members.
 */
function splitTopLevel(body) {
	const scan = { text: body, hash: true, quote: null, opens: '([{', closes: ')]}' }
	const split = { depth: 0, current: '', parts: [] }
	for (let i = 0; i < body.length; i++) {
		if (inQuote(scan, i) === true) {
			split.current += body[i]
			continue
		}
		const skip = commentEnd(scan, i)
		if (skip !== -1) {
			i = skip
			continue
		}
		split.depth += depthStep(scan, body[i])
		addCodeChar(split, body[i])
	}
	return finishSplit(split)
}

/**
 * The named arguments of the `new LeafDescriptor(` call whose `(` is at
 * `open`, with its value objects read through: `identity: new LeafIdentity(…)`,
 * `availability: new LeafAvailability(…)` and `rendering: new LeafRendering(…)`
 * contribute their own named arguments, so the grouped form and the flat
 * eleven-argument form yield the same fields.
 *
 * @param {string} src The file text.
 * @param {number} open Index of the call's opening parenthesis.
 *
 * @return {string[]} The top-level arguments, value objects expanded.
 */
function leafDescriptorArgs(src, open) {
	const out = []
	for (const arg of splitTopLevel(balanced(src, open))) {
		const group = /^\s*(?:identity|availability|rendering)\s*:\s*new\s+Leaf(?:Identity|Availability|Rendering)\s*\(/.exec(arg)
		if (group === null) {
			out.push(arg)
			continue
		}
		const inner = arg.indexOf('(', group.index + group[0].length - 1)
		out.push(...splitTopLevel(balanced(arg, inner)).map((a) => a.trim()))
	}
	return out
}

/**
 * The split's parts, with the last one added when it is not blank.
 *
 * @param {{depth: number, current: string, parts: string[]}} split The split state.
 *
 * @return {string[]} Trimmed top-level members.
 */
function finishSplit(split) {
	if (split.current.trim() !== '') {
		split.parts.push(split.current.trim())
	}
	return split.parts
}

/**
 * Add one code character to a split: a top-level comma ends the current part.
 *
 * @param {{depth: number, current: string, parts: string[]}} split The split state.
 * @param {string} c The character.
 *
 * @return {void}
 */
function addCodeChar(split, c) {
	if (c === ',' && split.depth === 0) {
		split.parts.push(split.current.trim())
		split.current = ''
		return
	}
	split.current += c
}

/**
 * Resolve a PHP expression to a string or array of strings, or null when it is
 * not statically knowable (which is never a failure — unknown values are simply
 * not compared).
 *
 * @param {string} expr The expression text.
 * @param {Object<string, (string|string[])>} localConsts Same-file const table.
 * @param {Object<string, (string|string[])>} globalConsts `Class::CONST` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolvePhp(expr, localConsts, globalConsts) {
	const e = expr.trim()
	let m = /^'((?:[^'\\]|\\.)*)'$/.exec(e) || /^"((?:[^"\\]|\\.)*)"$/.exec(e)
	if (m !== null) {
		return m[1].replace(/\\'/g, "'").replace(/\\"/g, '"')
	}
	// `$this->l10n->t('X')` / `$this->l->t('X', […])` — the translated literal.
	m =
		/^\$this->[A-Za-z0-9_]+->t\(\s*'((?:[^'\\]|\\.)*)'/.exec(e)
		|| /^\$this->[A-Za-z0-9_]+->t\(\s*"((?:[^"\\]|\\.)*)"/.exec(e)
	if (m !== null) {
		return m[1]
	}
	m = /^(?:self|static)::([A-Z0-9_]+)$/.exec(e)
	if (m !== null) {
		return Object.hasOwn(localConsts, m[1]) ? localConsts[m[1]] : null
	}
	m = /^([A-Za-z_][A-Za-z0-9_]*)::([A-Z0-9_]+)$/.exec(e)
	if (m !== null) {
		const key = `${m[1]}::${m[2]}`
		if (Object.hasOwn(FOREIGN_CONSTANTS, key) === true) {
			return FOREIGN_CONSTANTS[key]
		}
		return Object.hasOwn(globalConsts, key) ? globalConsts[key] : null
	}
	if (e.startsWith('[') === true) {
		const members = splitTopLevel(balanced(e, 0))
		const out = []
		for (const member of members) {
			const v = resolvePhp(member, localConsts, globalConsts)
			if (typeof v !== 'string') {
				return null
			}
			out.push(v)
		}
		return out
	}
	return null
}

/**
 * Build the same-file `const NAME = …` table for a PHP source.
 *
 * @param {string} src PHP source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function phpLocalConsts(src) {
	const table = {}
	const re = /\bconst\s+([A-Z][A-Z0-9_]*)\s*=\s*/g
	let m
	while ((m = re.exec(src)) !== null) {
		const start = m.index + m[0].length
		let expr
		if (src[start] === '[') {
			expr = src.slice(start, start + balanced(src, start).length + 2)
		} else {
			const semi = src.indexOf(';', start)
			expr = semi === -1 ? '' : src.slice(start, semi)
		}
		const v = resolvePhp(expr, table, {})
		if (v !== null) {
			table[m[1]] = v
		}
	}
	return table
}

/**
 * Collect the server-side leaf faces declared in this repo's PHP.
 *
 * Two shapes count as a server face, because both are how a leaf reaches the
 * `openregister.integrations.leaves` capability:
 *   `new LeafDescriptor(…)` — the ADR-066 collect-event contribution;
 *   an `IntegrationProvider` (extends `AbstractIntegrationProvider` or
 *     implements `IntegrationProviderInterface`) whose `getId()` returns a
 *     literal — the `IntegrationRegistry::addProvider()` path.
 *
 * @return {Array<object>} The discovered server faces.
 */
function collectServerFaces() {
	const sources = readSources(
		collectFiles(path.join(REPO_ROOT, 'lib'), (n) => n.endsWith('.php')),
	)
	const globalConsts = phpGlobalConsts(sources)
	const faces = []
	for (const [file, src] of sources) {
		faces.push(...serverFacesOf({ file, src }, globalConsts))
	}
	return faces
}

/**
 * Read files into a path-to-text map, skipping any that cannot be read.
 *
 * @param {string[]} files Absolute paths.
 *
 * @return {Map<string, string>} Path to source text, in input order.
 */
function readSources(files) {
	const sources = new Map()
	for (const file of files) {
		try {
			sources.set(file, fs.readFileSync(file, 'utf8'))
		} catch {
			continue
		}
	}
	return sources
}

/**
 * Build the global `Class::CONST` table, so `Application::APP_ID` resolves.
 *
 * @param {Map<string, string>} sources PHP path to source text.
 *
 * @return {Object<string, (string|string[])>} `Class::CONST` to resolved value.
 */
function phpGlobalConsts(sources) {
	const globalConsts = {}
	for (const src of sources.values()) {
		const cls =
			/\b(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/.exec(src)
		if (cls === null) {
			continue
		}
		for (const [name, value] of Object.entries(phpLocalConsts(src))) {
			globalConsts[`${cls[1]}::${name}`] = value
		}
	}
	return globalConsts
}

/**
 * The server faces one PHP file declares: its `new LeafDescriptor(…)` calls,
 * then its IntegrationProvider when it is one.
 *
 * @param {{file: string, src: string}} source The file and its text.
 * @param {Object<string, (string|string[])>} globalConsts `Class::CONST` table.
 *
 * @return {Array<object>} The faces, in source order.
 */
function serverFacesOf(source, globalConsts) {
	const ctx = {
		rel: path.relative(REPO_ROOT, source.file),
		src: source.src,
		locals: phpLocalConsts(source.src),
		globalConsts,
	}
	const faces = leafDescriptorFaces(ctx)
	const provider = providerFace(ctx)
	if (provider !== null) {
		faces.push(provider)
	}
	return faces
}

/**
 * Every `new LeafDescriptor(…)` in one PHP file, read from its named arguments.
 *
 * @param {object} ctx The file context (rel, src, locals, globalConsts).
 *
 * @return {Array<object>} One face per call.
 */
function leafDescriptorFaces(ctx) {
	const faces = []
	let idx = 0
	while ((idx = ctx.src.indexOf('new LeafDescriptor(', idx)) !== -1) {
		const open = ctx.src.indexOf('(', idx)
		faces.push(leafDescriptorFace(ctx, open))
		idx = open + 1
	}
	return faces
}

/**
 * The face of the `new LeafDescriptor(` call whose `(` is at `open`.
 *
 * @param {object} ctx The file context (rel, src, locals, globalConsts).
 * @param {number} open Index of the call's opening parenthesis.
 *
 * @return {object} The face.
 */
function leafDescriptorFace(ctx, open) {
	const face = {
		kind: 'LeafDescriptor',
		file: ctx.rel,
		fields: namedPhpArgs(ctx, open),
	}
	face.id = stringField(face.fields, 'id')
	const kinds = Array.isArray(face.fields.kinds) ? face.fields.kinds : []
	face.renderSurface = kinds.includes('render-surface')
	face.renderMode = stringField(face.fields, 'renderMode')
	return face
}

/**
 * The statically resolvable named arguments of the call whose `(` is at `open`.
 *
 * @param {object} ctx The file context (rel, src, locals, globalConsts).
 * @param {number} open Index of the call's opening parenthesis.
 *
 * @return {Object<string, (string|string[])>} Argument name to resolved value.
 */
function namedPhpArgs(ctx, open) {
	const fields = {}
	for (const arg of leafDescriptorArgs(ctx.src, open)) {
		const m = /^([A-Za-z_][A-Za-z0-9_]*)\s*:\s*([\s\S]+)$/.exec(arg)
		const value =
			m === null ? null : resolvePhp(m[2], ctx.locals, ctx.globalConsts)
		if (value !== null) {
			fields[m[1]] = value
		}
	}
	return fields
}

/**
 * A field's value when it resolved to a string, else null.
 *
 * @param {object} fields Resolved fields.
 * @param {string} name The field name.
 *
 * @return {string|null} The string value.
 */
function stringField(fields, name) {
	return typeof fields[name] === 'string' ? fields[name] : null
}

/**
 * IntegrationProvider getter to the face field its literal return value fills.
 *
 * @type {Object<string, string>}
 */
const PROVIDER_GETTERS = {
	getId: 'id',
	getLabel: 'label',
	getIcon: 'icon',
	getGroup: 'group',
	getRequiredApp: 'requiredApp',
	getReferenceType: 'referenceType',
}

/**
 * The file's IntegrationProvider face, or null when the file declares no
 * provider or its `getId()` returns nothing statically readable.
 *
 * @param {object} ctx The file context (rel, src, locals, globalConsts).
 *
 * @return {object|null} The face.
 */
function providerFace(ctx) {
	if (isIntegrationProvider(ctx.src) === false) {
		return null
	}
	const fields = providerFields(ctx)
	if (typeof fields.id !== 'string') {
		return null
	}
	// A provider is a data face, not a render surface: it declares no
	// render pair, so R2 asks of it only that this app's frontend names
	// it somewhere. See the data-provider branch in R2 for why a
	// registration is the wrong thing to demand here.
	const face = { kind: 'IntegrationProvider', file: ctx.rel, fields }
	face.id = fields.id
	face.renderSurface = false
	face.renderMode = stringField(fields, 'renderMode')
	return face
}

/**
 * Whether a PHP source declares an IntegrationProvider.
 *
 * @param {string} src PHP source text.
 *
 * @return {boolean} True when it extends the abstract provider or implements the interface.
 */
function isIntegrationProvider(src) {
	return (
		/extends\s+AbstractIntegrationProvider\b/.test(src)
		|| /implements\s+[^{]*\bIntegrationProviderInterface\b/.test(src)
	)
}

/**
 * The provider's fields, read from the literal each known getter returns.
 *
 * @param {object} ctx The file context (rel, src, locals, globalConsts).
 *
 * @return {Object<string, (string|string[])>} Field name to resolved value.
 */
function providerFields(ctx) {
	const fields = {}
	for (const [method, field] of Object.entries(PROVIDER_GETTERS)) {
		const value = getterReturn(ctx, method)
		if (value !== null) {
			fields[field] = value
		}
	}
	return fields
}

/**
 * The resolved value of the first `return` in a method's body.
 *
 * @param {object} ctx The file context (rel, src, locals, globalConsts).
 * @param {string} method The method name.
 *
 * @return {string|string[]|null} The value, or null when absent or unknowable.
 */
function getterReturn(ctx, method) {
	const sig = new RegExp(`function\\s+${method}\\s*\\([^)]*\\)[^{;]*\\{`).exec(
		ctx.src,
	)
	if (sig === null) {
		return null
	}
	const ret = /\breturn\s+([^;]+);/.exec(
		balanced(ctx.src, sig.index + sig[0].length - 1),
	)
	return ret === null ? null : resolvePhp(ret[1], ctx.locals, ctx.globalConsts)
}

/**
 * Resolve a JS expression to a string or array of strings, or null when it is
 * not statically knowable.
 *
 * @param {string} expr The expression text.
 * @param {Object<string, (string|string[])>} locals Same-file `const` table.
 *
 * @return {string|string[]|null} The resolved value.
 */
function resolveJs(expr, locals) {
	const e = expr.trim()
	let m =
		/^'((?:[^'\\]|\\.)*)'$/.exec(e)
		|| /^"((?:[^"\\]|\\.)*)"$/.exec(e)
		|| /^`([^`$]*)`$/.exec(e)
	if (m !== null) {
		return m[1].replace(/\\'/g, "'").replace(/\\"/g, '"')
	}
	// `t('appid', 'Label')` / `t('Label')` — the translated literal.
	m =
		/^t\(\s*'[^']*'\s*,\s*'((?:[^'\\]|\\.)*)'/.exec(e)
		|| /^t\(\s*'((?:[^'\\]|\\.)*)'\s*\)/.exec(e)
	if (m !== null) {
		return m[1]
	}
	if (e.startsWith('[') === true) {
		const out = []
		for (const member of splitTopLevel(balanced(e, 0))) {
			const v = resolveJs(member, locals)
			if (typeof v !== 'string') {
				return null
			}
			out.push(v)
		}
		return out
	}
	if (
		/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(e) === true
		&& Object.hasOwn(locals, e) === true
	) {
		return locals[e]
	}
	return null
}

/**
 * Build the same-file `const NAME = { … }` table, keyed to the OBJECT LITERAL
 * TEXT rather than a resolved scalar.
 *
 * `jsLocalConsts` below resolves constants to VALUES, and for a `{` it only
 * reads to end-of-line, so a multi-line descriptor object never lands in its
 * table. That is fine for `id: SOME_CONST`, but it cannot serve a registration
 * whose whole descriptor is passed by name:
 *
 *   export const decisionsLeafDescriptor = { id: …, renderMode: 'mount', … }
 *   …
 *   OCA.OpenRegister.integrations.register(decisionsLeafDescriptor)
 *
 * The member parser in `collectJsRegistrations` needs the literal's TEXT, so
 * this table keeps the balanced `{ … }` body verbatim for that one purpose.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, string>} Constant name to object-literal inner text.
 */
function jsLocalObjectLiterals(src) {
	const table = {}
	const re = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\{/g
	let m
	while ((m = re.exec(src)) !== null) {
		const brace = m.index + m[0].length - 1
		table[m[1]] = balanced(src, brace)
	}
	return table
}

/**
 * Build the same-file `const NAME = …` table for a JS/Vue source.
 *
 * @param {string} src JS source text.
 *
 * @return {Object<string, (string|string[])>} Constant name to resolved value.
 */
function jsLocalConsts(src) {
	const table = {}
	const re = /\bconst\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*/g
	let m
	while ((m = re.exec(src)) !== null) {
		const start = m.index + m[0].length
		let expr
		if (src[start] === '[') {
			expr = src.slice(start, start + balanced(src, start).length + 2)
		} else {
			const nl = src.indexOf('\n', start)
			expr =
				nl === -1
					? src.slice(start)
					: src.slice(start, nl).replace(/,\s*$/, '')
		}
		const v = resolveJs(expr, table)
		if (v !== null) {
			table[m[1]] = v
		}
	}
	return table
}

/**
 * Collect the JS `registerIntegration({ … })` call sites in `src/**`.
 *
 * @return {Array<object>} The discovered registrations.
 */
function collectJsRegistrations() {
	const regs = []
	const sources = readSources(
		collectFiles(path.join(REPO_ROOT, 'src'), isFrontendSource),
	)
	for (const [file, src] of sources) {
		// BOTH SUPPORTED REGISTRATION APIs, NOT ONE.
		//
		// `registerIntegration(descriptor)` is the convenience wrapper exported
		// from @conduction/nextcloud-vue. The registry object it wraps is
		// equally canonical and is called directly as
		// `OCA.OpenRegister.integrations.register(descriptor)` — including by
		// nextcloud-vue's OWN built-in leaves. Matching only the wrapper made
		// this checker report ZERO registrations for a repo that plainly has
		// one, which the wrapper script then correctly refused to call a pass.
		// gate-24's own selector probe was fixed for exactly this and reads
		// both forms; this checker was a generation behind it.
		if (
			src.includes('registerIntegration') === true
			|| src.includes('integrations.register') === true
		) {
			regs.push(...registrationsIn({ file, src }))
		}
	}
	return regs
}

/**
 * Whether a file name is a frontend source this checker reads.
 *
 * @param {string} name The file name.
 *
 * @return {boolean} True for `.js`, `.ts` and `.vue`.
 */
function isFrontendSource(name) {
	return name.endsWith('.js') || name.endsWith('.ts') || name.endsWith('.vue')
}

/**
 * Every registration call in one frontend file.
 *
 * @param {{file: string, src: string}} source The file and its text.
 *
 * @return {Array<object>} The registrations, in source order.
 */
function registrationsIn(source) {
	const ctx = jsFileContext(source)
	const regs = []
	// `\s*\(` anchors both alternatives to a CALL, so neither
	// `registerIntegrationIcons(` nor `installIntegrationRegistry(` matches.
	const re = /(?:\bregisterIntegration|\bintegrations\s*\.\s*register)\s*\(/g
	let m
	while ((m = re.exec(ctx.src)) !== null) {
		const reg = registrationAt(ctx, m.index)
		if (reg !== null) {
			regs.push(reg)
		}
	}
	return regs
}

/**
 * The per-file context the registration readers share.
 *
 * @param {{file: string, src: string}} source The file and its text.
 *
 * @return {object} rel, src, the `const` value table and the object-literal table.
 */
function jsFileContext(source) {
	return {
		rel: path.relative(REPO_ROOT, source.file),
		src: source.src,
		locals: jsLocalConsts(source.src),
		objects: jsLocalObjectLiterals(source.src),
	}
}

/**
 * The registration whose call name starts at `index`, or null when it is the
 * library's own declaration or its descriptor cannot be read.
 *
 * @param {object} ctx The file context (rel, src, locals, objects).
 * @param {number} index Where the matched call name starts.
 *
 * @return {object|null} The registration.
 */
function registrationAt(ctx, index) {
	const before = ctx.src.slice(Math.max(0, index - 20), index)
	if (/function\s+$/.test(before) === true) {
		return null // the library's own `export function registerIntegration(`
	}
	const open = ctx.src.indexOf('(', index)
	const argText = descriptorText(ctx, balanced(ctx.src, open).trim())
	return argText === null ? null : registrationFrom(ctx, argText)
}

/**
 * The descriptor object literal's text for a call argument.
 *
 * A descriptor passed BY NAME is the same registration as an inline literal;
 * it resolves to the literal's text, or to null when it cannot.
 *
 * @param {object} ctx The file context (rel, src, locals, objects).
 * @param {string} argText The trimmed call argument.
 *
 * @return {string|null} The `{ … }` text.
 */
function descriptorText(ctx, argText) {
	if (argText.startsWith('{') === true) {
		return argText
	}
	const ident = /^([A-Za-z_$][A-Za-z0-9_$]*)$/.exec(argText)
	if (ident === null || ctx.objects[ident[1]] === undefined) {
		return null
	}
	return '{' + ctx.objects[ident[1]] + '}'
}

/**
 * Read one registration from its descriptor object literal.
 *
 * @param {object} ctx The file context (rel, src, locals, objects).
 * @param {string} argText The `{ … }` text.
 *
 * @return {object} The registration.
 */
function registrationFrom(ctx, argText) {
	const build = {
		reg: { file: ctx.rel, fields: {}, keys: [], spreads: [] },
		locals: ctx.locals,
	}
	for (const member of splitTopLevel(balanced(argText, 0))) {
		addMember(build, member)
	}
	const reg = build.reg
	reg.id = stringField(reg.fields, 'id')
	reg.renderMode = stringField(reg.fields, 'renderMode')
	reg.spreadSources = spreadSourcesOf(ctx, reg.spreads)
	return reg
}

/**
 * The import source per spread symbol, for R5.
 *
 * @param {object} ctx The file context (rel, src, locals, objects).
 * @param {string[]} spreads The spread symbols.
 *
 * @return {Object<string, (string|null)>} Symbol to module specifier.
 */
function spreadSourcesOf(ctx, spreads) {
	const sources = {}
	for (const symbol of spreads) {
		sources[symbol] = importSourceOf(ctx.src, symbol)
	}
	return sources
}

/**
 * A `key: value`, shorthand `key` or quoted-key member of an object literal.
 *
 * @type {RegExp}
 */
const MEMBER_RE =
	/^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*(?::\s*([\s\S]+))?$/

/**
 * A `key: value` member of an `offlineConfig` literal.
 *
 * @type {RegExp}
 */
const CONFIG_MEMBER_RE =
	/^(?:'([^']+)'|"([^"]+)"|([A-Za-z_$][A-Za-z0-9_$]*))\s*:\s*([\s\S]+)$/

/**
 * Add one descriptor member to the registration being built.
 *
 * @param {{reg: object, locals: object}} build The registration and the file's const table.
 * @param {string} member The member text.
 *
 * @return {void}
 */
function addMember(build, member) {
	const spread = /^\.\.\.\s*([A-Za-z_$][A-Za-z0-9_$]*)/.exec(member)
	if (spread !== null) {
		build.reg.spreads.push(spread[1])
		return
	}
	const kv = MEMBER_RE.exec(member)
	if (kv !== null) {
		addKeyedMember(build, kv)
	}
}

/**
 * Add a keyed member: its key, its resolved value, and a literal offlineConfig.
 *
 * @param {{reg: object, locals: object}} build The registration and the file's const table.
 * @param {string[]} kv The MEMBER_RE match.
 *
 * @return {void}
 */
function addKeyedMember(build, kv) {
	const key = memberKey(kv)
	build.reg.keys.push(key)
	// A shorthand (`mount,`) carries the key without a value.
	const value = kv[4] === undefined ? null : resolveJs(kv[4], build.locals)
	if (value !== null) {
		build.reg.fields[key] = value
	}
	if (key === 'offlineConfig' && isObjectLiteral(kv[4]) === true) {
		build.reg.offlineConfig = offlineConfigOf(build, kv[4].trim())
	}
}

/**
 * The key of a MEMBER_RE or CONFIG_MEMBER_RE match, quoted or bare.
 *
 * @param {string[]} kv The match.
 *
 * @return {string} The key.
 */
function memberKey(kv) {
	return kv[1] || kv[2] || kv[3]
}

/**
 * Whether a member value is an object literal.
 *
 * @param {string|undefined} text The value text, undefined for a shorthand.
 *
 * @return {boolean} True when it starts with `{`.
 */
function isObjectLiteral(text) {
	return text !== undefined && text.trim().startsWith('{') === true
}

/**
 * The string-valued members of a literal `offlineConfig`.
 *
 * @param {{reg: object, locals: object}} build The registration and the file's const table.
 * @param {string} text The trimmed `{ … }` text.
 *
 * @return {Object<string, string>} Config key to resolved string.
 */
function offlineConfigOf(build, text) {
	const config = {}
	for (const cfg of splitTopLevel(balanced(text, 0))) {
		const ckv = CONFIG_MEMBER_RE.exec(cfg)
		const value = ckv === null ? null : resolveJs(ckv[4], build.locals)
		if (typeof value === 'string') {
			config[memberKey(ckv)] = value
		}
	}
	return config
}

/**
 * Find the module a symbol is imported from.
 *
 * @param {string} src JS source text.
 * @param {string} symbol The imported binding name.
 *
 * @return {string|null} The module specifier, or null when not imported.
 */
function importSourceOf(src, symbol) {
	const re = /import\s+([\s\S]*?)\s+from\s+'([^']+)'/g
	let m
	while ((m = re.exec(src)) !== null) {
		const clause = m[1]
		const named =
			clause.indexOf('{') === -1 ? '' : balanced(clause, clause.indexOf('{'))
		const bindings = splitTopLevel(named).map((b) =>
			b
				.split(/\s+as\s+/)
				.pop()
				.trim(),
		)
		const defaultBinding = clause
			.replace(/\{[\s\S]*\}/, '')
			.replace(/,/g, '')
			.trim()
		if (bindings.includes(symbol) === true || defaultBinding === symbol) {
			return m[2]
		}
	}
	return null
}

/**
 * Collect the schema slugs and their property names from this repo's
 * OpenRegister register/schema declarations under `lib/Settings/**`.
 *
 * @return {{schemas: Object<string, string[]>, files: number}} Slug to property
 *   names, and how many declaration files were read.
 */
function collectOrSchemas() {
	const schemas = {}
	let files = 0
	for (const file of collectFiles(path.join(REPO_ROOT, 'lib', 'Settings'), (n) =>
		n.endsWith('.json'),
	)) {
		let doc
		try {
			doc = JSON.parse(fs.readFileSync(file, 'utf8'))
		} catch {
			continue
		}
		const declared = doc && doc.components && doc.components.schemas
		if (
			declared === undefined
			|| declared === null
			|| typeof declared !== 'object'
		) {
			continue
		}
		files++
		for (const [key, schema] of Object.entries(declared)) {
			const slug =
				schema && typeof schema.slug === 'string' ? schema.slug : key
			const props =
				schema && schema.properties && typeof schema.properties === 'object'
					? Object.keys(schema.properties)
					: []
			schemas[slug] = (schemas[slug] || []).concat(props)
		}
	}
	return { schemas, files }
}

/**
 * Compare two statically-resolved values for parity.
 *
 * @param {string|string[]} a Left value.
 * @param {string|string[]} b Right value.
 *
 * @return {boolean} True when equal (arrays compare as sets).
 */
function sameValue(a, b) {
	if (Array.isArray(a) === true && Array.isArray(b) === true) {
		const left = [...a].sort()
		const right = [...b].sort()
		return left.length === right.length && left.every((v, i) => v === right[i])
	}
	return a === b
}

/**
 * Every string literal in one source, with comments excluded.
 *
 * Comments are excluded deliberately and it is not a detail. The first version
 * of this scan read the raw text, and a mutation test then passed with the id
 * renamed everywhere in the CODE, because a docblock still mentioned the old
 * one in backticks. A check that a comment can satisfy is a check that cannot
 * fail.
 *
 * @param {string} src  The source text.
 * @param {Set<string>} out The set to add to.
 *
 * @return {void}
 */
function addStringLiterals(src, out) {
	// HTML comments first: a `.vue` file's file-level docblock is one, and the
	// JS state machine below would read straight through it.
	const text = src.replace(/<!--[\s\S]*?-->/g, ' ')
	let i = 0
	while (i < text.length) {
		const ch = text[i]
		if (ch === '/' && text[i + 1] === '/') {
			const nl = text.indexOf('\n', i)
			i = nl === -1 ? text.length : nl + 1
			continue
		}
		if (ch === '/' && text[i + 1] === '*') {
			const end = text.indexOf('*/', i + 2)
			i = end === -1 ? text.length : end + 2
			continue
		}
		if (ch === "'" || ch === '"' || ch === '`') {
			let j = i + 1
			let value = ''
			while (j < text.length && text[j] !== ch) {
				if (text[j] === '\\') {
					j += 2
					continue
				}
				value += text[j]
				j += 1
			}
			if (value.length >= 2 && value.length <= 200) {
				out.add(value)
			}
			i = j + 1
			continue
		}
		i += 1
	}
}

/**
 * Every string literal appearing in this app's own frontend under `src/**`.
 *
 * Used for ONE purpose: to tell a data-provider leaf that client code reads
 * from one that no client code names at all. A leaf id is a stable kebab-case
 * string, so a literal occurrence of it in the frontend is evidence that some
 * surface fetches it.
 *
 * @return {Set<string>} The literals.
 */
function collectSrcStringLiterals() {
	const literals = new Set()
	const files = collectFiles(path.join(REPO_ROOT, 'src'), isFrontendSource)
	for (const file of files) {
		try {
			addStringLiterals(fs.readFileSync(file, 'utf8'), literals)
		} catch {
			continue
		}
	}
	return literals
}

/**
 * Run every rule and report.
 *
 * @return {void}
 */
function main() {
	const run = parityRun()
	const counts = {
		R1: checkRenderPairs(run),
		R2: checkIdCorrelation(run),
		R3: checkRenderModes(run),
		R4: checkMetadata(run),
		R5: checkSpreadSources(run),
		R6: checkOfflineConfigs(run),
	}
	report(run, counts)
}

/**
 * Collect everything the rules read, once, plus the failure list they fill.
 *
 * @return {object} The run: faces, regs, srcLiterals, schemas, schemaFiles,
 *   jsById, faceById and failures.
 */
function parityRun() {
	const faces = collectServerFaces()
	const regs = collectJsRegistrations()
	const { schemas, files: schemaFiles } = collectOrSchemas()
	return {
		faces,
		regs,
		srcLiterals: collectSrcStringLiterals(),
		schemas,
		schemaFiles,
		jsById: byId(regs),
		faceById: byId(faces),
		failures: [],
	}
}

/**
 * Index items by their non-null `id`; a later item with the same id wins.
 *
 * @param {Array<object>} items Faces or registrations.
 *
 * @return {Map<string, object>} Id to item.
 */
function byId(items) {
	const map = new Map()
	for (const item of items) {
		if (item.id !== null) {
			map.set(item.id, item)
		}
	}
	return map
}

/**
 * R1: complete render pair for the declared renderMode.
 *
 * @param {object} run The parity run.
 *
 * @return {number} Assertions run.
 */
function checkRenderPairs(run) {
	let count = 0
	for (const r of run.regs) {
		if (r.id === null) {
			continue // identity inherited from a spread — covered by R5
		}
		count++
		checkRenderPairOf(run, r)
	}
	return count
}

/**
 * R1 for one registration that declares its own id.
 *
 * @param {object} run The parity run.
 * @param {object} r The registration.
 *
 * @return {void}
 */
function checkRenderPairOf(run, r) {
	const mode = r.renderMode === null ? 'component' : r.renderMode
	const required = mode === 'mount' ? ['mount', 'unmount'] : ['tab', 'widget']
	for (const key of required) {
		if (r.keys.includes(key) === false) {
			run.failures.push(
				`✗ [R1 render-pair] registerIntegration id "${r.id}" (${r.file}) declares `
					+ `renderMode "${mode}" but is missing the required \`${key}\` key — an `
					+ `incomplete render pair renders nothing on its surface (ADR-019 AD-11/AD-13, `
					+ `ADR-066 decision 7).`,
			)
		}
	}
}

/**
 * R2: server↔JS id correlation, server faces first, then registrations.
 *
 * @param {object} run The parity run.
 *
 * @return {number} Assertions run.
 */
function checkIdCorrelation(run) {
	let count = 0
	for (const f of run.faces) {
		count += checkServerFaceId(run, f)
	}
	for (const r of run.regs) {
		count += checkRegistrationId(run, r)
	}
	return count
}

/**
 * R2 for one server face.
 *
 * @param {object} run The parity run.
 * @param {object} f The server face.
 *
 * @return {number} Assertions run (0 for a face without a readable id).
 */
function checkServerFaceId(run, f) {
	if (f.id === null) {
		run.failures.push(
			`✗ [R2 id-correlation] a ${f.kind} in ${f.file} declares no statically-readable `
				+ `\`id\` — a leaf whose id cannot be read cannot be correlated with its JS half.`,
		)
		return 0
	}
	const failure =
		run.jsById.has(f.id) === true ? null : unregisteredFaceFailure(run, f)
	if (failure !== null) {
		run.failures.push(failure)
	}
	return 1
}

/**
 * The R2 failure for a server face no registration shares its id with, or
 * null when it needs none.
 *
 * A RENDER-SURFACE face with no registration is the phantom this rule
 * is named for: the capability advertises a tab that mounts nothing.
 *
 * A DATA-PROVIDER face is a different thing and gets a different test.
 * It ships no render pair by construction — OpenRegister's own
 * LeafDescriptor says a leaf's `surfaces` are "empty when the leaf
 * offers no render surface" — and a repo may deliberately split a leaf
 * in two, a provider that answers and a panel that reads it under its
 * own id. Demanding a registration for the provider half would put a
 * second identical tab on every object detail page, which is a UI
 * defect invented to satisfy a check.
 *
 * What still must hold is that SOMETHING in this app's frontend reads
 * it. A provider no client code so much as names is data nobody can
 * reach, which is the same darkness by another route. So the provider
 * half passes on a literal mention of its id in `src/**`, and fails
 * when there is none.
 *
 * @param {object} run The parity run.
 * @param {object} f The server face.
 *
 * @return {string|null} The failure line.
 */
function unregisteredFaceFailure(run, f) {
	if (f.renderSurface !== false) {
		return phantomLeafFailure(f)
	}
	return run.srcLiterals.has(f.id) === true ? null : unnamedProviderFailure(f)
}

/**
 * The R2 failure for a render-surface face with no registration.
 *
 * @param {object} f The server face.
 *
 * @return {string} The failure line.
 */
function phantomLeafFailure(f) {
	return (
		`✗ [R2 id-correlation] server leaf "${f.id}" (${f.kind}, ${f.file}) has NO matching `
		+ `registerIntegration({ id: '${f.id}' }) in src/** — phantom leaf: the `
		+ `openregister.integrations.leaves capability advertises a surface that never mounts.`
	)
}

/**
 * The R2 failure for a data-provider face nothing in `src/**` names.
 *
 * @param {object} f The server face.
 *
 * @return {string} The failure line.
 */
function unnamedProviderFailure(f) {
	return (
		`✗ [R2 id-correlation] server leaf "${f.id}" (${f.kind}, ${f.file}) is a data `
		+ `provider that NOTHING in src/** names: no registration under that id, and `
		+ `not one literal occurrence of it. Nothing in this app's frontend can reach `
		+ `the data it serves. Either register a surface for it, or read it from the `
		+ `panel that does.`
	)
}

/**
 * R2 for one registration.
 *
 * @param {object} run The parity run.
 * @param {object} r The registration.
 *
 * @return {number} Assertions run (0 for a registration without its own id).
 */
function checkRegistrationId(run, r) {
	if (r.id === null) {
		return 0
	}
	if (run.faceById.has(r.id) === false) {
		run.failures.push(
			`✗ [R2 id-correlation] registerIntegration id "${r.id}" (${r.file}) has NO matching `
				+ `server-side face in lib/** (neither a \`new LeafDescriptor(id: '${r.id}')\` nor an `
				+ `IntegrationProvider whose getId() returns '${r.id}') — orphan registration: it `
				+ `mounts on window.OCA.OpenRegister.integrations but is invisible to the `
				+ `openregister.integrations.leaves capability.`,
		)
	}
	return 1
}

/**
 * R3: renderMode agreement across layers.
 *
 * @param {object} run The parity run.
 *
 * @return {number} Assertions run.
 */
function checkRenderModes(run) {
	let count = 0
	for (const f of run.faceById.values()) {
		count += checkRenderModeOf(run, f)
	}
	return count
}

/**
 * R3 for one server face, when both faces declare a renderMode.
 *
 * @param {object} run The parity run.
 * @param {object} f The server face.
 *
 * @return {number} Assertions run.
 */
function checkRenderModeOf(run, f) {
	const r = run.jsById.get(f.id)
	if (r === undefined || f.renderMode === null || r.renderMode === null) {
		return 0
	}
	if (f.renderMode !== r.renderMode) {
		run.failures.push(
			`✗ [R3 renderMode] leaf "${f.id}" declares renderMode "${f.renderMode}" server-side `
				+ `(${f.file}) but "${r.renderMode}" in its JS registration (${r.file}) — a `
				+ `renderMode mismatch blanks the surface (ADR-066 decision 7).`,
		)
	}
	return 1
}

/**
 * The fields R4 compares when both faces declare them statically.
 *
 * @type {string[]}
 */
const COMPARED_FIELDS = [
	'label',
	'icon',
	'group',
	'requiredApp',
	'referenceType',
	'surfaces',
]

/**
 * R4: cross-layer metadata agreement.
 *
 * @param {object} run The parity run.
 *
 * @return {number} Assertions run.
 */
function checkMetadata(run) {
	let count = 0
	for (const f of run.faceById.values()) {
		const r = run.jsById.get(f.id)
		if (r !== undefined) {
			count += checkMetadataOf(run, { face: f, reg: r })
		}
	}
	return count
}

/**
 * R4 for one id both faces share.
 *
 * @param {object} run The parity run.
 * @param {{face: object, reg: object}} pair The server face and its registration.
 *
 * @return {number} Assertions run.
 */
function checkMetadataOf(run, pair) {
	let count = 0
	for (const field of COMPARED_FIELDS) {
		const left = pair.face.fields[field]
		const right = pair.reg.fields[field]
		if (left === undefined || right === undefined) {
			continue
		}
		count++
		if (sameValue(left, right) === false) {
			run.failures.push(metadataFailure(pair, field))
		}
	}
	return count
}

/**
 * The R4 failure for one field the two faces disagree on.
 *
 * @param {{face: object, reg: object}} pair The server face and its registration.
 * @param {string} field The field name.
 *
 * @return {string} The failure line.
 */
function metadataFailure(pair, field) {
	return (
		`✗ [R4 metadata] leaf "${pair.face.id}" field \`${field}\` mismatch across layers: `
		+ `server (${pair.face.file}) says ${JSON.stringify(pair.face.fields[field])}, JS (${pair.reg.file}) says `
		+ `${JSON.stringify(pair.reg.fields[field])} — the two halves describe different leaves.`
	)
}

/**
 * R5: a spread registration must spread the OWNING package's descriptor.
 *
 * @param {object} run The parity run.
 *
 * @return {number} Assertions run.
 */
function checkSpreadSources(run) {
	let count = 0
	for (const r of run.regs) {
		count += checkSpreadsOf(run, r)
		if (r.id === null && r.spreads.length === 0) {
			run.failures.push(
				`✗ [R5 spread-source] registerIntegration in ${r.file} declares neither an \`id\` `
					+ `nor a spread of a descriptor — it registers a leaf with no identity.`,
			)
		}
	}
	return count
}

/**
 * R5 for each spread of one registration.
 *
 * @param {object} run The parity run.
 * @param {object} r The registration.
 *
 * @return {number} Assertions run.
 */
function checkSpreadsOf(run, r) {
	for (const symbol of r.spreads) {
		const failure = spreadSourceFailure(r, symbol)
		if (failure !== null) {
			run.failures.push(failure)
		}
	}
	return r.spreads.length
}

/**
 * The R5 failure for one spread symbol, or null when it is imported from the
 * leaf-owning package.
 *
 * @param {object} r The registration.
 * @param {string} symbol The spread binding.
 *
 * @return {string|null} The failure line.
 */
function spreadSourceFailure(r, symbol) {
	const source = r.spreadSources[symbol]
	if (source === null) {
		return unimportedSpreadFailure(r, symbol)
	}
	return source === '@conduction/nextcloud-vue'
		? null
		: foreignSpreadFailure(r, symbol)
}

/**
 * The R5 failure for a spread of a binding the file does not import.
 *
 * @param {object} r The registration.
 * @param {string} symbol The spread binding.
 *
 * @return {string} The failure line.
 */
function unimportedSpreadFailure(r, symbol) {
	return (
		`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\`, `
		+ `which is not imported in that file — the spread of an undefined binding `
		+ `registers a leaf with no id.`
	)
}

/**
 * The R5 failure for a spread imported from another package than the leaf owner.
 *
 * @param {object} r The registration.
 * @param {string} symbol The spread binding.
 *
 * @return {string} The failure line.
 */
function foreignSpreadFailure(r, symbol) {
	return (
		`✗ [R5 spread-source] registerIntegration in ${r.file} spreads \`...${symbol}\` `
		+ `imported from "${r.spreadSources[symbol]}", not from the leaf-owning package `
		+ `@conduction/nextcloud-vue — an override must extend the descriptor it `
		+ `overrides, or the id it registers under is not the one it thinks.`
	)
}

/**
 * R6: offlineConfig names schemas/properties this repo declares.
 *
 * @param {object} run The parity run.
 *
 * @return {number} Assertions run.
 */
function checkOfflineConfigs(run) {
	let count = 0
	for (const r of run.regs) {
		if (r.offlineConfig !== undefined) {
			count += checkOfflineConfigOf(run, r)
		}
	}
	return count
}

/**
 * R6 for one registration with an offlineConfig.
 *
 * @param {object} run The parity run.
 * @param {object} r The registration.
 *
 * @return {number} Assertions run.
 */
function checkOfflineConfigOf(run, r) {
	if (run.schemaFiles === 0) {
		run.failures.push(
			`✗ [R6 offlineConfig] registerIntegration in ${r.file} supplies an \`offlineConfig\` `
				+ `naming OpenRegister schemas, but this repo declares no register/schema JSON under `
				+ `lib/Settings/** to check it against — the mapping cannot be verified.`,
		)
		return 0
	}
	return checkOfflineSchemas(run, r) + checkOfflineFields(run, r)
}

/**
 * R6: each schema slug the offlineConfig names is declared.
 *
 * @param {object} run The parity run.
 * @param {object} r The registration.
 *
 * @return {number} Assertions run.
 */
function checkOfflineSchemas(run, r) {
	let count = 0
	for (const key of OFFLINE_CONFIG_CONTRACT.schemas) {
		const slug = r.offlineConfig[key]
		if (slug === undefined) {
			continue
		}
		count++
		if (Object.hasOwn(run.schemas, slug) === false) {
			run.failures.push(unknownSchemaFailure(run, { reg: r, key }))
		}
	}
	return count
}

/**
 * The R6 failure for a schema slug this repo does not declare.
 *
 * @param {object} run The parity run.
 * @param {{reg: object, key: string}} named The registration and the offlineConfig key.
 *
 * @return {string} The failure line.
 */
function unknownSchemaFailure(run, named) {
	return (
		`✗ [R6 offlineConfig] leaf offlineConfig.${named.key} = "${named.reg.offlineConfig[named.key]}" (${named.reg.file}) names a `
		+ `schema this repo does not declare in lib/Settings/** — the leaf would query a `
		+ `schema that does not exist. Declared slugs: ${Object.keys(run.schemas).sort().join(', ')}`
	)
}

/**
 * R6: each planned-item property the offlineConfig names is declared on its
 * planned schema. A planned schema that is not declared was already reported
 * by checkOfflineSchemas.
 *
 * @param {object} run The parity run.
 * @param {object} r The registration.
 *
 * @return {number} Assertions run.
 */
function checkOfflineFields(run, r) {
	const props = plannedProps(run, r)
	if (props === undefined) {
		return 0
	}
	let count = 0
	for (const key of OFFLINE_CONFIG_CONTRACT.plannedFields) {
		const prop = r.offlineConfig[key]
		if (prop !== undefined) {
			count++
			checkOfflineField(run, { reg: r, key, prop, props })
		}
	}
	return count
}

/**
 * The declared properties of the offlineConfig's planned schema.
 *
 * @param {object} run The parity run.
 * @param {object} r The registration.
 *
 * @return {string[]|undefined} The property names, undefined when unnamed or undeclared.
 */
function plannedProps(run, r) {
	const planned = r.offlineConfig.plannedSchema
	return planned === undefined ? undefined : run.schemas[planned]
}

/**
 * R6 for one planned-item property.
 *
 * @param {object} run The parity run.
 * @param {{reg: object, key: string, prop: string, props: string[]}} field The
 *   registration, the config key, the property it names and the declared ones.
 *
 * @return {void}
 */
function checkOfflineField(run, field) {
	if (field.props.includes(field.prop) === true) {
		return
	}
	run.failures.push(
		`✗ [R6 offlineConfig] leaf offlineConfig.${field.key} = "${field.prop}" (${field.reg.file}) is not a `
			+ `property of schema "${field.reg.offlineConfig.plannedSchema}" as declared in lib/Settings/** — the leaf `
			+ `would filter/display on a property that does not exist. Declared properties: `
			+ `${[...new Set(field.props)].sort().join(', ')}`,
	)
}

/**
 * Print the verdict and exit.
 *
 * @param {object} run The parity run.
 * @param {Object<string, number>} counts Assertions run per rule.
 *
 * @return {void}
 */
function report(run, counts) {
	const summary = {
		scope:
			`${run.faces.length} server leaf face(s), ${run.regs.length} JS registration(s), `
			+ `${run.schemaFiles} OpenRegister schema declaration file(s)`,
		perRule: Object.entries(counts)
			.map(([rule, n]) => `${rule}:${n}`)
			.join(' '),
	}
	if (run.failures.length === 0) {
		reportPass(summary, counts)
	}
	reportFailures(run.failures, summary)
}

/**
 * Print a pass and exit 0, or exit 1 when no rule had subject matter.
 *
 * @param {{scope: string, perRule: string}} summary The run's scope and counts line.
 * @param {Object<string, number>} counts Assertions run per rule.
 *
 * @return {void}
 */
function reportPass(summary, counts) {
	console.log(
		`✓ integration parity: ${summary.scope} — all rules pass (assertions run per rule: ${summary.perRule})`,
	)
	if (Object.values(counts).every((n) => n === 0) === true) {
		console.error(
			'✗ integration parity: every rule had ZERO subject matter, yet gate-24 selected this '
				+ 'repo as one that registers leaves. That contradiction means this checker failed to '
				+ 'read what the gate can see — a pass here would be a pass over nothing.',
		)
		process.exit(1)
	}
	process.exit(0)
}

/**
 * Print every violation and exit 1.
 *
 * @param {string[]} failures The violation lines.
 * @param {{scope: string, perRule: string}} summary The run's scope and counts line.
 *
 * @return {void}
 */
function reportFailures(failures, summary) {
	// The header carries no `✗` on purpose: gate-24 counts violations by
	// grepping `^✗` in this log, so every violation — and only a violation —
	// starts a line with it.
	console.error(
		`integration parity gate FAILED — ${failures.length} violation(s) over ${summary.scope}:`,
	)
	for (const f of failures) {
		console.error(f)
	}
	console.error(`\nAssertions run per rule: ${summary.perRule}`)
	process.exit(1)
}

if (require.main === module) {
	main()
}

module.exports = {
	collectServerFaces,
	collectJsRegistrations,
	collectOrSchemas,
}
