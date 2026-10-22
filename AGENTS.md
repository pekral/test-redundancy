# AGENTS.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## Codex integration

- Before changing files, inspect `.codex/rules/` and read every rule whose `paths` frontmatter matches the files in scope. Rules without `paths` apply to every task.
- Reusable workflows live in `.agents/skills/`. Invoke a matching skill with `$skill-name`, and follow its `SKILL.md` in full.
- Project-scoped custom agents live in `.codex/agents/`. Use the specialist named by the workflow and preserve the role boundaries in its instructions.
- AI Olympus run state lives in `.codex/run/`. In imported role instructions, interpret `.claude/run/` as `.codex/run/`, `@skills/` as `.agents/skills/`, `@rules/` as `.codex/rules/`, and `agents/<name>.md` as `.codex/agent-instructions/<name>.md`.
- A rule or skill that names a shipped script by a **bare** path — `skills/_shared/classify-risk.sh`, `skills/code-review-github/scripts/load-issue.sh` — means the matching file below `.agents/skills/`. Run it with the shell; these are deterministic helpers, not skills to invoke.
- Deterministic work is not an LLM's job. Before dispatching an agent for validation, routing, or a routine completion report, run the helper that owns it: `run-validation.sh`, `plan-route.sh`, `render-report.sh`. Each one returns a machine-readable verdict and says when a model is actually needed; a green verdict needs no session.
- A **model tier** named in a rule (*default tier* / *escalated tier*) is a role, never a model. Which model each tier means for a given specialist is stated in that specialist's own definition for this platform — `.codex/agents/<name>.toml` — never here and never in the rule.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:

- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them; don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No flexibility or configurability that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:

- Don't improve adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it; don't delete it.

When your changes create orphans:

- Remove imports, variables, and functions that your changes made unused.
- Don't remove pre-existing dead code unless asked.

Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:

- "Add validation" becomes "write tests for invalid inputs, then make them pass."
- "Fix the bug" becomes "write a test that reproduces it, then make it pass."
- "Refactor X" becomes "ensure tests pass before and after."

For multi-step tasks, state a brief plan with a verification check for every step.

Strong success criteria let you loop independently. Weak criteria require constant clarification.

## Project conventions

- The quality gate runs once, before the merge — not before every push. Do not run the project's gate, fixers, or checkers while implementing or while working through a code review. `$process-code-review` *Finalization* runs the full gate once, after the review converges; `$merge-github-pr` accepts that record only for the exact head commit being merged (or for a rebase that only moved the base, per `@skills/resolve-issue/references/quality-gates.md` *Rebase that moves the head — analyse the incoming changes first*), or runs the gate itself. `.agents/skills/resolve-issue/references/quality-gates.md` defines how the project's gate command is discovered.
- Run `skills/_shared/run-gate.sh --tier pr` before each push; it exits `5` and runs nothing unless the project manifest sets `pr-gate`, the one gate during a branch's working life. The full gate runs through `skills/_shared/run-gate.sh --tier full`, and every later step asks `skills/_shared/verify-gate.sh --tier full <sha>` before running it again; exit `5` from either means the built-in path applies (*Machine gate record* in `quality-gates.md`).
- Git worktrees are supported, but create one only when the user explicitly requests it.
- Read relevant entries in `docs/memory/PROJECT_MEMORY.md` before starting a task.
