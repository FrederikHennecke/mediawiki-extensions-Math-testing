# Math LocalChecker benchmark

Micro-benchmark for `LocalChecker`.

Uses `extensions/Math/tests/phpunit/integration/WikiTexVC/data/reference.json` for test input.

## Prereqs

- Run from a MediaWiki checkout with the Math extension present.
- Composer deps installed: `composer install` (from MediaWiki root).
- Install PHPBench from MediaWiki root `composer require phpbench/phpbench --dev`.
- PHPBench is in `vendor/bin/phpbench`.
- Setup memcached: https://www.mediawiki.org/wiki/Manual:Memcached#Setup

## Choose WAN backend and Run

You can run the benchmark and switch backends via envs. Run from MediaWiki root:

```bash
# in-process hash
MATH_BENCH_WAN=hash XDEBUG_MODE=off MW_BENCH_SQLITE=1 \
vendor/bin/phpbench run --config extensions/Math/benchmarks/phpbench.json

# memcached
MATH_BENCH_WAN=memcached XDEBUG_MODE=off MW_BENCH_SQLITE=1 \
vendor/bin/phpbench run --config extensions/Math/benchmarks/phpbench.json

# disable WAN cache entirely
MATH_BENCH_WAN=none XDEBUG_MODE=off MW_BENCH_SQLITE=1 \
vendor/bin/phpbench run --config extensions/Math/benchmarks/phpbench.json
```

You’ll see four subjects:

* `benchAllMiss` — forces recompute (`purge=true`) with comparison to check for correct MathML
* `benchAllHit`  — warms once, then measures cache hits (`purge=false`) with comparison to check for correct MathML
* `benchAllMissCore` — forces recompute (`purge=true`)
* `benchAllHitCore`  — warms once, then measures cache hits (`purge=false`)

## Notes

* The benchmark verifies output by comparing **MathML structure** (XML) against
  `reference.json`, ignoring the outer `<math>` element and formatting.
* Results show **median of medians** (Mo) per PHPBench defaults.

## Results
Results for memcached:
```
benchAllMiss............................I49 - Mo153.159ms (±1.27%)
benchAllHit.............................I49 - Mo148.208ms (±1.41%)
benchAllMissCore........................I49 - Mo152.840ms (±1.30%)
benchAllHitCore.........................I49 - Mo148.083ms (±2.42%)
```
