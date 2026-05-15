<?php
namespace Waiwaisew\Minifier;
 
   
/* =============================================================================
 * JavaScript Minifier + Variable Mangler
 *
 * Usage (string):
 *   $min  = (new JsMinifier())->minify($code);
 *   $out  = (new JsMangler())->mangle($min);
 *
 * Usage (file):
 *   $out  = (new JsMinifier())->minifyFile('app.js');
 *   $out  = (new JsMangler())->mangle($out);
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

    private const PRESERVE_CONTENT_TAGS = ['pre', 'textarea', 'script', 'style'];

    private const CONTINUATION_KEYWORDS = [
        'return', 'throw', 'case', 'typeof', 'instanceof', 'in',
        'delete', 'void', 'new', 'var', 'let', 'const', 'else',
        'extends', 'export', 'import', 'from', 'of', 'yield', 'await',
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
        if (!file_exists($path)) throw new RuntimeException("File not found: $path");
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
                    $lic = $this->isLicenseComment(); $c = $this->readBlockComment();
                    if ($lic) $this->output .= $c . "\n";
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
        while ($this->pos < $this->length &&
               in_array($this->input[$this->pos], ["\n","\r",' ',"\t"], true)) $this->pos++;

        $out  = rtrim($this->output);
        $next = $this->peekNonWhitespace();

        if ($out === '') { $this->output = ''; return; }
        $lastChar = substr($out, -1);

        if (in_array($lastChar, [';','{',',','(','[',':','\\'], true)) { $this->output = $out; return; }

        if ($lastChar === '}') {
            if ($next !== '' && in_array($next, ['.','?',':','(',',',')',']','{','}'], true)) { $this->output = $out; return; }
            $n2 = substr($this->input, $this->pos, 2);
            if (in_array($n2, ['&&','||','??','=>','+=','-=','*=','/='], true)) { $this->output = $out; return; }
            $this->output = $out . (in_array($this->lastPoppedBrace, ['object', 'fn_expr'], true) ? ';' : '');
            return;
        }

        if ($lastChar === ')' && $this->closesControlFlow($out)) { $this->output = $out; return; }

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
        return in_array($this->lastWord(rtrim(substr($out, 0, $i))), self::CONTROL_FLOW_KEYWORDS, true);
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
        $this->pos++; $result = '`'; $htmlChunk = '';
        while ($this->pos < $this->length) {
            $ch = $this->input[$this->pos];
            if ($ch === '\\') { $htmlChunk .= $ch.($this->input[$this->pos+1]??''); $this->pos+=2; continue; }
            if ($ch === '$' && ($this->input[$this->pos+1]??'') === '{') {
                $trailing = strlen($htmlChunk) > 0 && ctype_space(substr($htmlChunk,-1));
                $m = $this->minifyHtmlChunk($htmlChunk);
                $result .= $trailing ? rtrim($m).' ' : $m;
                $htmlChunk = ''; $result .= '${'; $this->pos += 2;
                $result .= $this->readTemplateExpression(); continue;
            }
            if ($ch === '`') { $result .= $this->minifyHtmlChunk($htmlChunk).'`'; $this->pos++; break; }
            $htmlChunk .= $ch; $this->pos++;
        }
        return $result;
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
        $html = preg_replace('/>\s+</', '><', $html);
        $html = preg_replace('/[ \t\r\n]{2,}/', ' ', $html);
        $html = preg_replace_callback('/<[^>]+>/', static fn($m)=>preg_replace('/\s{2,}/',' ',trim($m[0])), $html);
        foreach ($ph as $k=>$v) $html = str_replace($k,$v,$html);
        return trim($html);
    }

    // ---- comment / regex readers ----

    private function skipLineComment(): void
    { while ($this->pos < $this->length && $this->input[$this->pos] !== "\n") $this->pos++; }

    private function isLicenseComment(): bool
    { return isset($this->input[$this->pos+2]) && ($this->input[$this->pos+2]==='!'||(isset($this->input[$this->pos+3])&&$this->input[$this->pos+3]==='*')); }

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
        $last=substr($out,-1);
        return preg_match('/[\w$_]/',$last)&&preg_match('/[\w$_]/',$next);
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




/* =============================================================================
 * CLI entry point
 * ============================================================================= */
// if (php_sapi_name() === 'cli') {
//     if (!isset($argv[1]) || !file_exists($argv[1])) {
//         echo "Usage: php js_minifier.php input.js\n";
//         echo "       php js_minifier.php input.js --no-mangle\n";
//         exit(1);
//     }

//     $mangle   = !in_array('--no-mangle', $argv);
//     $src      = file_get_contents($argv[1]);
//     $minifier = new JsMinifier();
//     $output   = $minifier->minify($src, $mangle);

//     $outPath = preg_replace('/\.js$/', '.min.js', $argv[1]);
//     if ($outPath === $argv[1]) $outPath .= '.min.js';

//     file_put_contents($outPath, $output);

//     printf("Original  : %d bytes\n", strlen($src));
//     printf("Output    : %d bytes\n", strlen($output));
//     printf("Saved     : %.1f%%\n",   (1 - strlen($output)/strlen($src))*100);
//     echo   "Written   : $outPath\n";
// }