---
description: Redesign one application page from its URL — previews for approval first, then implementation, review, and an interactive browser walkthrough
argument-hint: [page URL]
---

Run `@skills/deliver-page-redesign/SKILL.md` with this page URL. Resolve and capture the page in
this session, get the redesign previews approved by me, then delegate the delivery route to the
`splinter` agent as the skill describes:

$ARGUMENTS

If the URL is missing or is not exactly one absolute `http://` or `https://` URL of a page in this
application, stop and ask for it. Follow the skill in full. Show me the redesign previews first and
refine them until I explicitly approve them. Write no code before that approval. Then implement the
approved design without changing business logic, open the pull request, drive its review to
convergence, verify the page in a real interactive browser, and report in the skill's output shape.
Never merge the pull request.

For Codex, invoke `$deliver-page-redesign` with the same URL. Ask the registered `michelangelo`
agent for the design phase and the registered `splinter` agent for the delivery phase.
