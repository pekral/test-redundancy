---
description: Test one task's pull request against its assignment, prove that nothing else broke, then publish a test report or prepare the pull request for merge
argument-hint: [GitHub issue or pull request URL, or JIRA issue key or URL] [report|fix] [optional test data]
---

Delegate this request to the `splinter` agent in test mode and run
`@skills/test-assignment/SKILL.md` with these arguments:

$ARGUMENTS

The first argument is the source reference. If it is missing, or is not exactly one full GitHub
issue or pull-request URL or one JIRA issue key or URL, stop and ask for it. The optional second
argument is the mode: `report` (the default) or `fix`. Everything after the mode is test data the
user wants to see.

Follow the skill in full. Load the assignment and every comment, map every input that reaches the
changed behaviour, build a test matrix of real data, run the whole test suite and the gate on the
final head, check CI, and exercise the running application against the base branch.

In `report` mode, change nothing and publish one test report. In `fix` mode, add the missing tests
and fixes, then prepare the pull request for merge through `@skills/verify-merge-readiness/SKILL.md`.
Never merge. Publish every business decision, optimization, refactoring, and pre-existing problem
as a question in the `Decisions before merge` section instead of implementing it.

For Codex, invoke `$test-assignment` with the same arguments and ask the registered `splinter` agent
to orchestrate it.
