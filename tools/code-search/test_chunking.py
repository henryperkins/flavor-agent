"""Pure unit tests for the symbol-aware chunker. No deps required:
    python3.11 test_chunking.py    (or .venv/bin/python test_chunking.py)

Invariants under test:
  1. Line-number fidelity: lines[start-1:end] reconstructs each chunk's code.
  2. Symbol boundaries: each symbol start begins its own chunk.
  3. Size cap: chunks stay <= MAX_CHARS (except a single physical line).
  4. Coverage: every non-blank source line lands in at least one chunk.
  5. Empty input yields no chunks.
"""
import sys
from common import chunk_file, MAX_CHARS

failures = 0


def check(cond, msg):
    global failures
    if not cond:
        failures += 1
        print(f"  FAIL: {msg}")
    else:
        print(f"  ok:   {msg}")


def assert_invariants(rel_path, text, language=None):
    lines = text.replace("\r\n", "\n").replace("\r", "\n").split("\n")
    chunks = chunk_file(rel_path, text, language)
    # 1. fidelity
    for c in chunks:
        recon = "\n".join(lines[c.start_line - 1:c.end_line])
        check(recon == c.code,
              f"{rel_path}: chunk {c.symbol} L{c.start_line}-{c.end_line} reconstructs from source")
    # 3. size cap (allow a lone physical line to exceed)
    for c in chunks:
        single_line = c.start_line == c.end_line
        check(len(c.code) <= MAX_CHARS or single_line,
              f"{rel_path}: chunk {c.symbol} L{c.start_line}-{c.end_line} within {MAX_CHARS} chars ({len(c.code)})")
    # 4. coverage of non-blank lines
    covered = set()
    for c in chunks:
        covered.update(range(c.start_line, c.end_line + 1))
    nonblank = {i + 1 for i, l in enumerate(lines) if l.strip()}
    missing = nonblank - covered
    check(not missing, f"{rel_path}: all non-blank lines covered (missing={sorted(missing)[:5]})")
    return chunks


print("== PHP class with methods ==")
php = """<?php
namespace FlavorAgent\\AI;

use FlavorAgent\\Support\\Thing;

class RecommendBlockAbility extends RecommendationAbility {
    const CAPABILITY = 'edit_posts';

    public function execute( $input ) {
        $post_id = $input['postId'];
        return $this->run( $post_id );
    }

    private function run( $post_id ) {
        return array( 'ok' => true );
    }
}
"""
chunks = assert_invariants("inc/AI/Abilities/RecommendBlockAbility.php", php)
syms = [c.symbol for c in chunks]
check("RecommendBlockAbility" in syms, "class symbol captured")
check("execute" in syms, "method 'execute' captured as its own chunk")
check("run" in syms, "method 'run' captured as its own chunk")
# the file header (namespace/use) should be its own leading chunk with symbol None
check(any(c.symbol is None and "namespace" in c.code for c in chunks), "file-header chunk present")

print("== JS function + exported arrow component ==")
js = """import { useState } from '@wordpress/element';

export function helper( a, b ) {
    return a + b;
}

const Panel = ( { title } ) => {
    const [ open, setOpen ] = useState( false );
    return open ? title : null;
};

export default Panel;
"""
chunks = assert_invariants("src/components/Panel.js", js)
syms = [c.symbol for c in chunks]
check("helper" in syms, "JS function 'helper' captured")
check("Panel" in syms, "JS arrow component 'Panel' captured")

print("== oversized symbol gets windowed, still within cap ==")
big_body = "\n".join(f"    $x{i} = compute_value_number_{i}( $context, $state );" for i in range(400))
big = f"<?php\nclass Big {{\n    public function huge() {{\n{big_body}\n    }}\n}}\n"
chunks = assert_invariants("inc/Big.php", big)
check(len(chunks) > 1, f"oversized function split into multiple windows ({len(chunks)})")

print("== CSS windows by lines ==")
css = ".a { color: red; }\n.b { color: blue; }\n" * 3
assert_invariants("src/style.css", css)

print("== empty / whitespace ==")
check(chunk_file("src/empty.js", "   \n\n  \n") == [], "whitespace-only file -> no chunks")
check(chunk_file("src/empty.js", "") == [], "empty file -> no chunks")

print()
if failures:
    print(f"FAILED: {failures} assertion(s)")
    sys.exit(1)
print("ALL CHUNKER TESTS PASSED")
