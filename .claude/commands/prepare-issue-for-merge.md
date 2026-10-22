---
description: Prepare one GitHub or JIRA issue's pull request for merge without merging, then consolidate the preparation comments
argument-hint: [GitHub issue or pull request URL, or JIRA issue key or URL]
---

Delegate this request to the `splinter` agent and run
`@skills/verify-merge-readiness/SKILL.md` with this reference:

$ARGUMENTS

If the reference is missing, or is not exactly one full GitHub issue or pull-request URL or one JIRA
issue key or URL, stop and ask for it. Follow the skill in full. Bring the linked pull request to a
verified merge-ready state, publish and verify its single source-issue TL;DR, remove only the
superseded actor-owned preparation comments the skill permits, and stop before merge.

Change only what the assignment's acceptance criteria and the merge gate require. Do not implement
an optimization, a refactoring, a pre-existing problem, or a nice-to-have point. Publish each one as a
question in the separate `Decisions before merge` section — on the source GitHub issue, or on the
pull request for a JIRA source, where the JIRA ticket shows only the Critical ones — so a human
decides it. The merge requires an answer to every question.

When the issue carries no pull request, `splinter` resolves the task first — implementation and the
review-and-fix loop to convergence — and then prepares the pull request that delivery opens.

For Codex, invoke `$verify-merge-readiness` with the same reference and ask the registered `splinter`
agent to orchestrate it.
