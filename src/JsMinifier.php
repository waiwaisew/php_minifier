<?php
namespace Waiwaisew\Minifier;

/* =============================================================================
 * JavaScript Minifier + Variable Mangler
 *
 * Quick usage:
 *   $out = Minify::js($code);              // minify + mangle
 *   $out = Minify::js($code, false);       // minify only, keep names
 *   $out = Minify::file('app.js');
 *
 * Low-level usage (unchanged, backwards compatible):
 *   $min = (new JsMinifier())->minify($code);          // mangles by default
 *   $min = (new JsMinifier())->minify($code, false);   // no mangling
 *   $out = (new JsMangler())->mangle($min);
 *
 * CLI:
 *   vendor/bin/minify app.js > app.min.js
 *   vendor/bin/minify --no-mangle app.js
 *   cat app.js | vendor/bin/minify
 *
 * -----------------------------------------------------------------------------
 * CHANGELOG — correctness fixes
 * -----------------------------------------------------------------------------
 * [FIX] Identifiers in parameter default values were collected as params.
 *       `(sel, scope = root) => …` renamed `root` to a fresh, undeclared
 *       letter (`(w, x=y)`), a ReferenceError at runtime. Defaults are now
 *       skipped by collectParamsFromParen() and keep the outer-scope rename.
 * [FIX] Spread inside a template expression was not renamed.
 *       `${fn({...group})}` kept `group` while the binding became `j`
 *       (ReferenceError at runtime). renameInExpr() treated each `.` of `...`
 *       as a member access. `...` and `?.` are now tokenised explicitly.
 * [FIX] Adjacent operators could merge into a different token.
 *       `a + +b` -> `a++b`, `a - -b` -> `a--b`, `a / /re/` -> `a//re/`
 *       (comment!), `a < !--b` -> `a<!--b`. Both the whitespace collapser and
 *       the newline joiner now consult needsSeparator().
 * [FIX] Same merge hazard in JsMangler::reconstruct(), which rebuilds output
 *       from tokens and only guarded word|word pairs.
 * [FIX] `1 .toString()` no longer collapses to the invalid `1.toString()`.
 * [FIX] Template HTML no longer loses meaningful spaces between inline
 *       elements: `<b>a</b> <i>b</i>` kept its space, only newline
 *       indentation is stripped. See $collapseInlineWhitespace to opt out.
 * [FIX] Class fields (`class A { x = 1 }`) could be rewritten as shorthand
 *       properties (`x:a`). Class bodies are now a distinct brace type.
 * [FIX] License-comment detection misread `/**` as `/*!`; it now keeps `/*!`
 *       and any comment carrying @license / @preserve.
 *
 * -----------------------------------------------------------------------------
 * CHANGELOG — compression improvements
 * -----------------------------------------------------------------------------
 * [NEW] Method shorthand (`m(){}`, `get v(){}`, `static m(){}`, class methods)
 *       is recognised as its own scope, so its params and locals mangle.
 * [NEW] Local function declarations are renamed (`function helper(){}` inside
 *       a function body). Top-level names are still left alone.
 * [NEW] Trailing commas dropped: `{a:1,}` -> `{a:1}`, `f(x,)` -> `f(x)`.
 *       Array holes (`[1,,]`) are preserved.
 * ============================================================================= */


/* =============================================================================
 * PART 1 – MINIFIER
 * ============================================================================= */
class JsMinifier
{
    private string $input  = '';
    private int    $pos    = 0;
    private int    $length = 0;
    private string $output = '';

    private array  $braceStack     = [];
    private string $lastPoppedBrace = 'block';

    /**
     * When true, whitespace between inline HTML elements inside template
     * literals is removed as well (`<b>a</b> <i>b</i>` -> `<b>a</b><i>b</i>`).
     * That is smaller but visibly changes rendering, so it is off by default:
     * only indentation whitespace (whitespace containing a newline) is removed.
     */
    public bool $collapseInlineWhitespace = false;

    /** Set right after a preserved comment so the newline after it survives. */
    private bool $keepNewlineAfterComment = false;

    private const PRESERVE_CONTENT_TAGS = ['pre', 'textarea', 'script', 'style'];

    private const CONTINUATION_KEYWORDS = [
        'case', 'typeof', 'instanceof', 'in',
        'delete', 'void', 'new', 'var', 'let', 'const', 'else',
        'extends', 'export', 'import', 'from', 'of', 'await',
    ];

    // ECMAScript "restricted productions": a LineTerminator immediately after
    // one of these keywords forces Automatic Semicolon Insertion, so the
    // statement ends at the newline. They must NEVER be joined with the next
    // line (e.g. `return\nlet x=1` means `return; let x=1`, not `return let x=1`).
    private const ASI_RESTRICTED_KEYWORDS = [
        'return', 'throw', 'break', 'continue', 'yield',
    ];

    private const CONTROL_FLOW_KEYWORDS = [
        'if', 'else', 'for', 'while', 'switch', 'function',
        'catch', 'with', 'try', 'finally', 'do',
    ];

    // ---- public ----

    public function minify(string $js, bool $mangle = true): string
    {
        $this->input          = $js;
        $this->length         = strlen($js);
        $this->pos            = 0;
        $this->output         = '';
        $this->braceStack     = [];
        $this->lastPoppedBrace = 'block';
        $this->process();
        $minified = trim($this->output);
        return $mangle ? (new JsMangler())->mangle($minified) : $minified;
    }

    public function minifyFile(string $path, bool $mangle = true): string
    {
        if (!file_exists($path)) throw new \RuntimeException("File not found: $path");
        return $this->minify(file_get_contents($path), $mangle);
    }

    // ---- core ----

    private function process(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->input[$this->pos];

            if ($ch === '"' || $ch === "'") { $this->output .= $this->readQuotedString($ch); continue; }
            if ($ch === '`') { $this->output .= $this->readTemplateLiteral(); continue; }

            if ($ch === '/') {
                $next = $this->input[$this->pos + 1] ?? '';
                if ($next === '/') { $this->skipLineComment(); $this->handleNewline(); continue; }
                if ($next === '*') {
                    $c = $this->readBlockComment();
                    if ($this->isLicenseComment($c)) {
                        $this->output .= $c . "\n";
                        $this->keepNewlineAfterComment = true;
                    }
                    $this->skipWhitespaceInline(); continue;
                }
                if ($this->isRegexStart()) { $this->output .= $this->readRegex(); continue; }
            }

            if ($ch === "\n" || $ch === "\r") { $this->handleNewline(); continue; }

            if ($ch === ' ' || $ch === "\t") {
                if ($this->needsSpaceBefore()) $this->output .= ' ';
                $this->skipWhitespaceInline(); continue;
            }

            if ($ch === '{') {
                $type = $this->detectBraceType();
                $this->braceStack[] = $type;
                // Orphan (standalone) blocks: suppress the { entirely; contents pass through.
                if ($type !== 'orphan') $this->output .= $ch;
                $this->pos++; continue;
            }
            if ($ch === '}') {
                $this->stripTrailingComma();
                $poppedType = array_pop($this->braceStack) ?? 'block';
                // Treat orphan as a block for semicolon-injection logic.
                $this->lastPoppedBrace = ($poppedType === 'orphan') ? 'block' : $poppedType;
                if ($poppedType !== 'orphan') {
                    $this->output .= $ch;
                } else {
                    // Orphan close is suppressed. If there was no preceding newline to trigger
                    // handleNewline (single-line orphan blocks), inject ; now if needed.
                    $this->injectSemicolonBeforeOrphanClose();
                }
                $this->pos++; continue;
            }

            if ($ch === ']' || $ch === ')') $this->stripTrailingComma();

            $this->output .= $ch; $this->pos++;
        }
    }

    private function detectBraceType(): string
    {
        $out = rtrim($this->output);
        if ($out === '') return 'orphan';
        $lastChar = substr($out, -1);
        $lastTwo  = substr($out, -2);
        $lastWord = $this->lastWord($out);

        // ── Orphan block detection ────────────────────────────────────────────────
        // A `{` that appears where a statement starts (after `;` or a block-closing
        // `}`) has nothing to attach to — strip it and its matching `}` entirely.
        if ($lastChar === ';') return 'orphan';
        if ($lastChar === '}' && $this->lastPoppedBrace !== 'object') return 'orphan';
        // `{` directly after another block-opening `{` means we are at
        // statement-start inside a code block (e.g. `function foo(){ { ... } }`).
        // Guard with braceStack so we never misfire inside an object literal.
        if ($lastChar === '{' && !empty($this->braceStack) && end($this->braceStack) !== 'object') return 'orphan';

        // Arrow function body: `=> {` is a code block, but its closing `}` still needs a
        // semicolon because arrow functions are always expressions (`const f = () => {...};`).
        // Use the dedicated 'fn_expr' type so handleNewline injects `;` after its `}`,
        // unlike plain control-flow blocks (if/for/while) which must NOT get a `;`.
        // Must be checked BEFORE the `>` object-trigger below.
        if ($lastTwo === '=>') return 'fn_expr';

        // ── Pure object-literal / RHS expression contexts ─────────────────────────
        if (in_array($lastChar, ['=', '(', '[', ',', ':', '|', '&', '?', '!', '>'], true)) return 'object';
        if (in_array($lastWord, ['return', 'yield'], true)) return 'object';

        // ── Everything else (control-flow, function declarations, method calls) ───
        // All of these produce a code block whose closing `}` does NOT need `;`.
        return 'block';
    }

    // Inject a semicolon before a suppressed orphan-block closing brace when needed.
    // Mirrors the logic in handleNewline() but operates without needing a newline.
    // Safe to call even if handleNewline() already injected a semicolon (idempotent).
    private function injectSemicolonBeforeOrphanClose(): void
    {
        $out = rtrim($this->output);
        if ($out === '') return;
        $lastChar = substr($out, -1);
        $lastTwo  = substr($out, -2);

        // Already terminated — nothing to do.
        if (in_array($lastChar, [';', '{', ',', '(', '[', ':', '\\'], true)) return;

        // Postfix ++ / -- need a semicolon.
        if ($lastTwo === '++' || $lastTwo === '--') { $this->output = $out . ';'; return; }

        // Trailing binary/unary operator — expression continues, no semicolon.
        if (in_array($lastChar, ['+', '-', '*', '%', '=', '&', '|', '^', '~', '<', '>', '!'], true)) return;

        // Identifier, number, ), ] or quote-like end — inject semicolon.
        if (preg_match('/[\w\d$_\)\]\'"`]$/', $lastChar)) { $this->output = $out . ';'; }
    }

    private function handleNewline(): void
    {
        $this->handleNewlineInner();
        // The join above may have butted two operators together (`a +` + `+b`).
        $next = $this->peekNonWhitespace();
        if ($next !== '' && $this->needsSeparator($this->output, $next)) {
            $this->output .= ' ';
        }
    }

    private function handleNewlineInner(): void
    {
        while ($this->pos < $this->length &&
               in_array($this->input[$this->pos], ["\n","\r",' ',"\t"], true)) $this->pos++;

        // A preserved /*! ... */ banner keeps its line break, otherwise the
        // rtrim() below would glue the next statement onto the comment.
        if ($this->keepNewlineAfterComment) {
            $this->keepNewlineAfterComment = false;
            $this->output = rtrim($this->output) . "\n";
            return;
        }

        $out  = rtrim($this->output);
        $next = $this->peekNonWhitespace();

        if ($out === '') { $this->output = ''; return; }
        $lastChar = substr($out, -1);

        if (in_array($lastChar, [';','{',',','(','[',':','\\'], true)) { $this->output = $out; return; }

        // ASI restricted productions: statement ends at the newline, no matter
        // what follows. Checked FIRST (before any lookahead-based joins) so that
        // e.g. `return\n(fn())` is not turned into `return(fn())`.
        $lw = $this->lastWord($out);
        if (in_array($lw, self::ASI_RESTRICTED_KEYWORDS, true)) {
            // Make sure it's the keyword itself, not a property access like `gen.return`
            $prevChar = substr(rtrim(substr($out, 0, strlen($out) - strlen($lw))), -1);
            if ($prevChar !== '.') { $this->output = $out . ';'; return; }
        }

        if ($lastChar === '}') {
            if ($next !== '' && in_array($next, ['.','?',':','(',',',')',']','{','}'], true)) { $this->output = $out; return; }
            $n2 = substr($this->input, $this->pos, 2);
            if (in_array($n2, ['&&','||','??','=>','+=','-=','*=','/='], true)) { $this->output = $out; return; }
            $this->output = $out . (in_array($this->lastPoppedBrace, ['object', 'fn_expr'], true) ? ';' : '');
            return;
        }

        if ($lastChar === ')' && $this->closesControlFlow($out)) { $this->output = $out; return; }

        // After a regular call foo(...), a { on the next line is an orphan block;
        // inject ; so detectBraceType() can strip it correctly as 'orphan'.
        if ($lastChar === ')' && $next === '{') { $this->output = $out . ';'; return; }

        if ($next !== '' && in_array($next, ['.','?',':','(',',',')',']','{','}'], true)) { $this->output = $out; return; }

        $n2 = substr($this->input, $this->pos, 2);
        if (in_array($n2, ['&&','||','??','=>','+=','-=','*=','/=','**'], true)) { $this->output = $out; return; }

        $lw = $this->lastWord($out);
        if (in_array($lw, self::CONTINUATION_KEYWORDS, true)) { $this->output = $out . ' '; return; }

        // Trailing binary/unary operator → continuation, BUT postfix ++ or -- ends a statement
        $lastTwo = substr($out, -2);
        if ($lastTwo === '++' || $lastTwo === '--') {
            $this->output = $out . ';';  // postfix increment/decrement → inject ;
            return;
        }
        if (in_array($lastChar, ['+','-','*','%','=','&','|','^','~','<','>','!'], true)) { $this->output = $out; return; }

        if (preg_match('/[\w\d$_\)\]\'"`]$/', $lastChar)) { $this->output = $out . ';'; return; }

        $this->output = $out;
    }

    private function closesControlFlow(string $out): bool
    {
        if (substr($out, -1) !== ')') return false;
        $depth = 0; $i = strlen($out) - 1;
        while ($i >= 0) {
            $c = $out[$i];
            if ($c === ')') $depth++;
            elseif ($c === '(') { $depth--; if ($depth === 0) break; }
            $i--;
        }
        $prefix = rtrim(substr($out, 0, $i));
        $lastW  = $this->lastWord($prefix);
        if (in_array($lastW, self::CONTROL_FLOW_KEYWORDS, true)) return true;
        // Named function declaration: `function name(` or `async function name(`
        $beforeName = rtrim(preg_replace('/[a-zA-Z_$][\w$]*\s*$/', '', $prefix));
        $prevW = $this->lastWord($beforeName);
        if (in_array($prevW, ['function', 'async'], true)) return true;
        return false;
    }

    // ---- string / template readers ----

    private function readQuotedString(string $q): string
    {
        $r = $q; $this->pos++;
        while ($this->pos < $this->length) {
            $ch = $this->input[$this->pos];
            if ($ch === '\\') { $r .= $ch . ($this->input[$this->pos+1]??''); $this->pos += 2; continue; }
            $r .= $ch; $this->pos++;
            if ($ch === $q) break;
        }
        return $r;
    }

    private function readTemplateLiteral(): string
    {
        $this->pos++; $result = '`'; $htmlChunk = ''; $afterExpr = false;
        while ($this->pos < $this->length) {
            $ch = $this->input[$this->pos];
            if ($ch === '\\') { $htmlChunk .= $ch.($this->input[$this->pos+1]??''); $this->pos+=2; continue; }
            if ($ch === '$' && ($this->input[$this->pos+1]??'') === '{') {
                $result .= $this->emitChunk($htmlChunk, $afterExpr, true);
                $htmlChunk = ''; $result .= '${'; $this->pos += 2;
                $result .= $this->readTemplateExpression();
                $afterExpr = true; continue;
            }
            if ($ch === '`') {
                $result .= $this->emitChunk($htmlChunk, $afterExpr, false).'`';
                $this->pos++; break;
            }
            $htmlChunk .= $ch; $this->pos++;
        }
        return $result;
    }

    /**
     * Minify one literal chunk of a template and decide what happens to the
     * whitespace touching an adjacent `${...}`.
     *
     * A deliberate space (`</b> ${x} <i>`) is meaningful in HTML and is kept;
     * whitespace that contains a newline is source indentation and is dropped.
     * Both edges are treated the same way — previously the trailing edge kept
     * any whitespace and the leading edge was always trimmed, so
     * `${price} <b>x</b>` silently lost its space and rendered as one word.
     */
    private function emitChunk(string $chunk, bool $afterExpr, bool $beforeExpr): string
    {
        if ($chunk === '') return '';

        $keepLead  = $afterExpr  && $this->edgeSpaceSurvives($chunk, true);
        $keepTrail = $beforeExpr && $this->edgeSpaceSurvives($chunk, false);

        $min = trim($this->minifyHtmlChunk($chunk));
        if ($min === '') return ($keepLead || $keepTrail) ? ' ' : '';

        return ($keepLead ? ' ' : '') . $min . ($keepTrail ? ' ' : '');
    }

    /** A single space at this edge is deliberate (no newline in the run of whitespace). */
    private function edgeSpaceSurvives(string $chunk, bool $leading): bool
    {
        if ($this->collapseInlineWhitespace) return false;
        $ws = $leading
            ? substr($chunk, 0, strlen($chunk) - strlen(ltrim($chunk)))
            : substr($chunk, strlen(rtrim($chunk)));
        return $ws !== '' && strpbrk($ws, "\r\n") === false;
    }

    private function readTemplateExpression(): string
    {
        $depth = 1; $result = '';
        while ($this->pos < $this->length && $depth > 0) {
            $ch = $this->input[$this->pos];
            if ($ch === '{') $depth++;
            elseif ($ch === '}') { $depth--; if ($depth===0){ $result.='}'; $this->pos++; break; } }
            if ($ch==='"'||$ch==="'") { $result .= $this->readQuotedString($ch); continue; }
            if ($ch==='`') { $result .= $this->readTemplateLiteral(); continue; }
            $result .= $ch; $this->pos++;
        }
        return $result;
    }

    // ---- HTML minifier (for template literals) ----

    private function minifyHtmlChunk(string $html): string
    {
        if (trim($html) === '') return '';
        if (strpos($html,'<') === false) return preg_replace('/\s+/',' ',$html);

        $ph = []; $idx = 0;
        foreach (self::PRESERVE_CONTENT_TAGS as $tag) {
            $html = preg_replace_callback('/<'.$tag.'(\s[^>]*)?>[\s\S]*?<\/'.$tag.'>/i',
                function($m) use (&$ph,&$idx){ $k="\x00P{$idx}\x00"; $ph[$k]=$m[0]; $idx++; return $k; }, $html);
        }
        $html = preg_replace('/<!--(?!\[if\s)[\s\S]*?-->/i','', $html);
        // Whitespace between tags: indentation (contains a newline) is noise and
        // is removed; a deliberate single space between inline elements is kept
        // because dropping it visibly joins words.
        $html = $this->collapseInlineWhitespace
            ? preg_replace('/>\s+</', '><', $html)
            : preg_replace_callback('/>[ \t\r\n]+</',
                static fn($m) => strpbrk($m[0], "\r\n") !== false ? '><' : '> <',
                $html);
        $html = preg_replace('/[ \t\r\n]{2,}/', ' ', $html);
        $html = preg_replace_callback('/<[^>]+>/', static fn($m)=>preg_replace('/\s{2,}/',' ',trim($m[0])), $html);
        foreach ($ph as $k=>$v) $html = str_replace($k,$v,$html);
        return trim($html);
    }

    // ---- comment / regex readers ----

    private function skipLineComment(): void
    { while ($this->pos < $this->length && $this->input[$this->pos] !== "\n") $this->pos++; }

    /** Keep `/*!` banners and anything marked @license / @preserve. */
    private function isLicenseComment(string $comment): bool
    {
        if (str_starts_with($comment, '/*!')) return true;
        return (bool)preg_match('/@(?:license|preserve|cc_on)\b/i', $comment);
    }

    private function readBlockComment(): string
    {
        $s = $this->pos; $this->pos += 2;
        while ($this->pos < $this->length) {
            if ($this->input[$this->pos]==='*'&&($this->input[$this->pos+1]??'')==='/') { $this->pos+=2; break; }
            $this->pos++;
        }
        return substr($this->input,$s,$this->pos-$s);
    }

    private function isRegexStart(): bool
    {
        $out = rtrim($this->output); if ($out==='') return true;
        $last = substr($out,-1);
        if (in_array($last,[')',']'],true)) {
            return in_array($this->lastWord($out),['return','typeof','instanceof','in','of','delete','void','throw','new','case'],true);
        }
        if (preg_match('/[\w\d$_]$/',$last)) return false;
        return true;
    }

    private function readRegex(): string
    {
        $r='/'; $this->pos++; $inClass=false;
        while ($this->pos < $this->length) {
            $ch=$this->input[$this->pos];
            if ($ch==='\\') { $r.=$ch.($this->input[$this->pos+1]??''); $this->pos+=2; continue; }
            if ($ch==='[') $inClass=true; elseif ($ch===']') $inClass=false;
            $r.=$ch; $this->pos++;
            if ($ch==='/'&&!$inClass) break;
        }
        while ($this->pos<$this->length&&preg_match('/[gimsuy]/',$this->input[$this->pos])) $r.=$this->input[$this->pos++];
        return $r;
    }

    // ---- whitespace helpers ----

    private function skipWhitespaceInline(): void
    { while ($this->pos<$this->length&&($this->input[$this->pos]===' '||$this->input[$this->pos]==="\t")) $this->pos++; }

    private function needsSpaceBefore(): bool
    {
        $out=$this->output; $next=$this->peekNonWhitespace();
        if ($out===''||$next==='') return false;
        return $this->needsSeparator($out,$next);
    }

    /**
     * True when removing the whitespace between $out and $next would fuse two
     * tokens into a single, different one. Covers word|word (`let x`) plus the
     * operator pairs that silently change meaning:
     *
     *   a + +b   -> a++b     (postfix increment, then a stray identifier)
     *   a - -b   -> a--b
     *   a / /r/  -> a//r/    (line comment — the rest of the line vanishes)
     *   a / * b  -> a/*b     (block comment)
     *   a < !--b -> a<!--b   (Annex B html-open comment)
     *   a-- > b  -> a-->b    (Annex B html-close comment)
     *   1 .toFixed() -> 1.toFixed()   (syntax error)
     */
    private function needsSeparator(string $out, string $next): bool
    {
        if ($out === '' || $next === '') return false;
        $last    = substr($out, -1);
        $lastTwo = substr($out, -2);
        $isWord  = static fn(string $c): bool => (bool)preg_match('/[\w$]/', $c);

        if ($isWord($last) && $isWord($next)) {
            // Numeric literal followed by `.` is handled below, not here.
            return true;
        }
        if ($last === '+' && $next === '+') return true;
        if ($last === '-' && $next === '-') return true;
        if ($last === '/' && ($next === '/' || $next === '*')) return true;
        if ($last === '<' && $next === '!') return true;
        if ($lastTwo === '--' && $next === '>') return true;

        // `1 .toString()` is legal; `1.toString()` is not. Only guard when the
        // trailing digits really are a number literal, not the tail of a name.
        if ($next === '.' && ctype_digit($last)
            && !preg_match('/[A-Za-z_$][\w$]*$/', $out)
            && !preg_match('/\.[\d_]*$/', $out)) return true;

        return false;
    }

    /**
     * Drop a trailing comma that is about to be closed by } ] or ).
     * Array holes are preserved: `[1,,]` and `[,]` keep their commas.
     */
    private function stripTrailingComma(): void
    {
        $out = rtrim($this->output);
        if (substr($out, -1) !== ',') return;
        $before = substr($out, -2, 1);
        if ($before === ',' || $before === '[') return;
        $this->output = substr($out, 0, -1);
    }

    private function peekNonWhitespace(): string
    {
        $i = $this->pos;
        $stackSim = $this->braceStack; // simulate future pops without mutating the real stack

        while ($i < $this->length) {
            $ch = $this->input[$i];

            // Skip whitespace
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $i++; continue;
            }

            // Skip line comment  // ...
            if ($ch === '/' && ($this->input[$i+1] ?? '') === '/') {
                while ($i < $this->length && $this->input[$i] !== "\n") $i++;
                continue;
            }

            // Skip block comment  /* ... */
            if ($ch === '/' && ($this->input[$i+1] ?? '') === '*') {
                $i += 2;
                while ($i < $this->length) {
                    if ($this->input[$i] === '*' && ($this->input[$i+1] ?? '') === '/') {
                        $i += 2; break;
                    }
                    $i++;
                }
                continue;
            }

            // If this } closes an orphan block it will be suppressed from output —
            // skip it so callers see the real next visible character.
            if ($ch === '}' && !empty($stackSim) && end($stackSim) === 'orphan') {
                array_pop($stackSim);
                $i++;
                continue;
            }

            return $ch;
        }
        return '';
    }

    private function lastWord(string $str): string
    { return preg_match('/([a-zA-Z_$][a-zA-Z0-9_$]*)$/',rtrim($str),$m)?$m[1]:''; }
}
