"""Pure unit tests for incremental-index logic (scope predicate + git-diff
planner). No Qdrant, no deps:  python3.11 test_incremental.py
"""
import sys
from common import in_scope, plan_from_name_status, discover_files, repo_root

failures = 0


def check(cond, msg):
    global failures
    if not cond:
        failures += 1
        print(f"  FAIL: {msg}")
    else:
        print(f"  ok:   {msg}")


print("== in_scope: included paths ==")
for p in ["flavor-agent.php", "readme.txt", "inc/AI/FeatureBootstrap.php",
          "src/index.js", "src/store/index.js", "src/admin/activity-log.css",
          "assets/abilities-bridge.js"]:
    check(in_scope(p), f"in scope: {p}")

print("== in_scope: excluded paths ==")
for p in ["src/store/update-helpers.test.js", "src/__tests__/x.js",
          "src/test-utils/render.js", "vendor/foo/bar.php", "build/index.js",
          "docs/README.md", "scripts/verify.js", "tools/code-search/index.py",
          "inc/Foo.md", "composer.json", "src/notes.stories.js"]:
    check(not in_scope(p), f"out of scope: {p}")

print("== consistency: every discovered file is in_scope ==")
files = discover_files(repo_root())
bad = [f for f in files if not in_scope(f)]
check(not bad, f"all {len(files)} discovered files pass in_scope (offenders={bad[:3]})")

print("== plan_from_name_status: mixed diff ==")
sample = "\n".join([
    "M\tinc/AI/FeatureBootstrap.php",      # modified, in scope -> reindex
    "A\tsrc/new/Panel.js",                 # added, in scope -> reindex
    "D\tsrc/old/Gone.js",                  # deleted, in scope -> remove
    "M\tdocs/SOURCE_OF_TRUTH.md",          # out of scope -> ignored
    "A\tsrc/store/thing.test.js",          # test file -> ignored
    "R096\tinc/Old/Name.php\tinc/New/Name.php",  # rename in->in: remove old, reindex new
    "R100\tinc/Moved.php\tdocs/Moved.php",       # rename in->out: remove old only
    "C075\tinc/Base.php\tinc/Copy.php",          # copy in->in: reindex new, keep src
])
reindex, remove = plan_from_name_status(sample)
check("inc/AI/FeatureBootstrap.php" in reindex, "modified php reindexed")
check("src/new/Panel.js" in reindex, "added js reindexed")
check("inc/New/Name.php" in reindex, "rename target reindexed")
check("inc/Copy.php" in reindex, "copy target reindexed")
check("src/old/Gone.js" in remove, "deleted js removed")
check("inc/Old/Name.php" in remove, "rename source removed")
check("inc/Moved.php" in remove, "rename-out-of-scope source removed")
check("docs/SOURCE_OF_TRUTH.md" not in reindex and "docs/SOURCE_OF_TRUTH.md" not in remove,
      "out-of-scope doc ignored")
check("src/store/thing.test.js" not in reindex, "test file ignored")
check("inc/Base.php" not in remove, "copy source not removed")
check(not (set(reindex) & set(remove)), "reindex and remove disjoint")

print()
if failures:
    print(f"FAILED: {failures} assertion(s)")
    sys.exit(1)
print("ALL INCREMENTAL TESTS PASSED")
