---
description: Review one task's pull request and report the result to its tracker and GitHub; adds only the missing tests for the diff, in one commit, and fixes nothing else
argument-hint: [GitHub issue or pull request URL, JIRA issue key or URL, or Bugsnag error URL]
---

Delegate this request to the `splinter` agent in review-only mode and ensure a code review of the task
with this reference:

$ARGUMENTS

If the reference is missing, or is not exactly one full GitHub issue or pull-request URL, one JIRA
issue key or URL, or one Bugsnag error URL, stop and ask for it.

Always review the current code of the pull request branch. Before the review, the run switches the
current working tree to the pull request's head branch: `git fetch origin`, `git checkout
<headRefName>`, and `git pull`. It then confirms that local `HEAD` equals the pull request's head SHA.
Never review from the remote diff alone, from a stale local branch, or from a separate worktree. When
the checkout fails, for example because local changes would be overwritten, stop and report it.

Do not fix anything in the code under review. The one permitted change is the missing tests for the
current diff of the pull request. Before the review, `splinter` dispatches `donatello` once in its
*Missing-tests mode*: it adds the tests the diff is missing, runs them, and pushes **all** of them in
**exactly one commit** on the pull request branch. That commit changes test code only. It never
changes the business logic of the change under review, unless the user explicitly asks for that
change in the request. When the diff is missing no test, the run creates no commit. A test that
fails against the current code is not committed; `donatello` reports the behaviour it exposed, and
the review reports it as a finding. Then `splinter` dispatches `leonardo` once, and `leonardo` runs the code-review
wrapper that matches the tracker: `@skills/code-review-github/SKILL.md`,
`@skills/code-review-jira/SKILL.md`, or `@skills/code-review-bugsnag/SKILL.md`. The wrapper
publishes the result in the output each tracker requires: the technical findings on the GitHub pull
request, and the non-technical summary on the source issue. The summary carries no `How to test`
section. In its place, `Review findings` retells the GitHub report in plain language, so a
non-technical reader understands what the review reported and can reply with feedback. The wrapper
passes `review-only` to `@skills/pr-summary/SKILL.md`. On each finding about a missing test and each DRY
finding, and on each test the missing-tests commit added, the report mentions the author of the changes
(`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request*). Every comment the run publishes is a new comment: the
wrapper and `pr-summary` call the publish helper with `--create`, so the run never updates a comment an
earlier review left. Dispatch no other implementation, run no
fix loop, change no tracker status, do not promote the pull request, and never merge. When the task
has no pull request, stop and report that there is nothing to review.

For Codex, invoke the matching wrapper — `$code-review-github`, `$code-review-jira`, or
`$code-review-bugsnag` — with the same reference and ask the registered `splinter` agent to orchestrate
it.
