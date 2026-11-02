# Review corrections for the DeepSeek title gate

Pending selection, counts and self-chaining now require both `gemini_analyzed = false` and `gemini_analyzed_at IS NULL`, matching the processor's existing completed-timestamp guard. An inconsistent historical record is left unchanged and cannot starve older eligible articles when the active batch size is one. Held unrelated records still require an explicit bypass.

The additive-migration test creates its pre-migration fixture without model events. This prevents a current-schema observer from starting analysis while the test deliberately removes the new columns. Gemini and the synchronous queue are explicitly enabled in that test, so the previous CI failure does not depend on ambient flags.

The isolated PostgreSQL RED run reproduced both defects. After correction, focused PostgreSQL 17 and SQLite runs each passed 254 tests and 1102 assertions. The progress test verifies both older articles advance, chaining stops, provider calls occur exactly twice and every historical attribute remains unchanged. Full remote CI and independent review are separate gates, not implied by these focused results.

No historical backfill, production migration, activation, change to main or deployment is part of these corrections.

Independent PostgreSQL verification also passed 254 tests / 1102 assertions with matching source hashes and no new P1/P2 finding. The focused runs still log missing Vite manifests in unchanged permission-page tests; their passing assertions are not a frontend-build or clean-stderr claim. Remote CI must build real assets.

The authorized sibling Pro-only differential passed with identical exit code, stdout, stderr, queue counts and unchanged historical data. Migration setup logs were captured separately; the original failed harness attempt was preserved rather than treated as a passing comparison.
