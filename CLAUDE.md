# CLAUDE.md — Project AI Coding Guidelines
> Klaus Klingner · SilverDay Media · Information Security Standards

---

## Core Philosophy

- **Simplicity First**: Every change should be as small and focused as possible.
- **No Laziness**: Find root causes. No temporary fixes. No workarounds that create debt.
- **Security by Default**: Security is not a feature to add later — it is a baseline constraint.
- **Minimal Impact**: Touch only what is necessary. Avoid side effects and collateral changes.

---

## Workflow Orchestration

### 1. Plan Before You Build
- Enter plan mode for ANY non-trivial task (3+ steps, architectural decisions, data handling).
- Write a brief spec upfront — clarify inputs, outputs, edge cases, and trust boundaries.
- If something goes sideways mid-task: **STOP. Re-plan. Don't push through.**
- Use plan mode for verification steps too, not just implementation.

### 2. Subagent Strategy
- Use subagents to keep the main context window clean on complex tasks.
- Offload research, exploration, and parallel analysis to subagents.
- One focused task per subagent — no multi-tasking within a single subagent.
- For complex problems: throw more compute at it via subagents rather than hacking shortcuts.

### 3. Self-Improvement Loop
- After **any** correction from the user: update `tasks/lessons.md` immediately.
- Write a pattern rule that prevents the same mistake from recurring.
- Review `tasks/lessons.md` at the start of each new session for this project.
- Iterate ruthlessly on these lessons until the mistake rate drops to near zero.

### 4. Verification Before Done
- Never mark a task complete without **proving it works**.
- Run tests, check logs, demonstrate correctness — don't just say it should work.
- Diff behavior between main and your changes when relevant.
- Ask yourself: *"Would a senior security engineer approve this PR?"*

### 5. Demand Elegance (Balanced)
- For non-trivial changes: pause and ask *"Is there a more elegant solution?"*
- If a fix feels hacky: *"Knowing everything I know now, implement the clean version."*
- Skip this for simple, obvious fixes — don't over-engineer trivialities.
- Challenge your own output before presenting it.

### 6. Autonomous Bug Fixing
- When given a bug report: fix it. Don't ask for hand-holding.
- Use logs, errors, and failing tests as your primary guide.
- Go fix failing CI tests without being asked how.
- Zero unnecessary context switching required from the user.

---

## Security Standards

> These apply to all projects: Web apps (PHP, Python, Node.js) and security tooling & scripts.
> Violation of these rules must be flagged — they are never silently skipped.

### Input Handling
- **Validate and sanitize all input** — user-supplied, API-sourced, file-based, environment-sourced.
- Use allowlists, not denylists, wherever feasible.
- Never trust data from external systems without validation at the boundary.
- For PHP: use `htmlspecialchars()`, `intval()`, PDO prepared statements — never raw string SQL.
- For Python: use parameterized queries (never f-strings in SQL), validate with `pydantic` or similar.
- For Node.js: validate with `zod` or `joi`; never pass raw user input to shell commands or `eval()`.

### Authentication & Session Management
- Never roll your own crypto or auth logic — use established libraries.
- Enforce strong session tokens (min. 128-bit entropy), proper expiry, and secure/HttpOnly cookie flags.
- Implement account lockout or rate limiting on login endpoints.
- MFA where the architecture supports it.

### Secret & Credential Handling
- **Never hardcode credentials, tokens, or keys** — in code, comments, or commit history.
- Use `.env` files (gitignored) for local dev; use a secrets manager (Vault, AWS Secrets Manager, etc.) for production.
- If a project dictates a different approach: document it explicitly in the README and flag it in findings.
- Scan for accidentally committed secrets before finalizing any PR.

### Output & Error Handling
- Never expose stack traces, internal paths, or system details in user-facing error messages.
- Log errors internally with context; show users generic messages only.
- Sanitize all output rendered in HTML contexts to prevent XSS.

### Dependencies
- Prefer well-maintained, minimal dependencies.
- Before adding a new library: check for known CVEs (use `pip-audit`, `npm audit`, `composer audit`).
- Pin dependency versions in production configs; use lockfiles.
- Flag unmaintained or abandoned packages.

### File & System Operations
- Never use user input directly in file paths, shell commands, or system calls.
- Validate and canonicalize paths before use; check for path traversal patterns.
- Apply principle of least privilege: scripts and services should run with minimum required permissions.
- Avoid `shell=True` (Python), `exec()` / `system()` (PHP), or `child_process.exec()` with unsanitized input (Node.js).

### Cryptography
- Use industry-standard algorithms only: AES-256, RSA-2048+, SHA-256+, bcrypt/argon2 for passwords.
- Never use MD5 or SHA-1 for security purposes.
- Always use a cryptographically secure random source (`secrets` in Python, `crypto.randomBytes()` in Node.js).

### API Security
- Authenticate and authorize every API endpoint — no security by obscurity.
- Rate-limit all public-facing endpoints.
- Use HTTPS only; validate TLS certificates in outbound requests (no `verify=False`).
- Return minimal data — never expose fields that aren't explicitly needed by the caller.

### Logging & Auditing
- Log security-relevant events: auth attempts, permission failures, input validation failures.
- **Never log sensitive data**: passwords, tokens, PII, session IDs.
- Ensure logs are tamper-evident and stored separately from the application where possible.

---

## Security Findings File

When a security issue is discovered during coding — intentionally or incidentally — do **not** silently fix it or embed it in commit comments.

**Create or append to `tasks/security_findings.md`:**

```markdown
## [FINDING] Short Title
- **Date**: YYYY-MM-DD
- **Severity**: Critical / High / Medium / Low / Informational
- **Location**: file:line or module
- **Type**: e.g. SQL Injection, Hardcoded Credential, Missing Input Validation
- **Description**: What was found and why it is a risk.
- **Recommendation**: What the fix should be.
- **Status**: Open / Fixed / Accepted Risk
```

- Report findings **before** continuing with other work if severity is High or Critical.
- Do not mark a finding as Fixed without verifying the remediation.
- Accepted Risk entries require an explicit acknowledgment from the user.

---

## Secret Management Reference

| Context | Approach |
|---|---|
| Local development | `.env` file, gitignored, documented in `.env.example` |
| Shared/team dev | Password manager export or secrets manager with scoped access |
| CI/CD pipelines | CI secret store (GitHub Actions secrets, GitLab CI variables) |
| Production | Vault, AWS Secrets Manager, or platform-native equivalent |
| Security tooling scripts | Prompt at runtime or read from env — never embed |

When project-specific constraints require deviation, document the decision and rationale in the project README.

---

## Task Management

- Track tasks in `tasks/todo.md` with status: `[ ]` open, `[x]` done, `[!]` blocked.
- Document architectural decisions in `tasks/decisions.md` with rationale.
- Log lessons learned in `tasks/lessons.md` after every user correction.
- Log security findings in `tasks/security_findings.md` (see above).

---

## Language-Specific Quick Rules

### PHP
- Always use PDO with prepared statements.
- Never use `eval()`, `shell_exec()`, or `system()` with user input.
- Set `error_reporting(0)` and `display_errors = Off` in production.
- Use `password_hash()` / `password_verify()` — never MD5/SHA1 for passwords.

### Python
- Use parameterized queries (SQLAlchemy, psycopg2 `%s` params) — never f-strings in SQL.
- Use `secrets` module for tokens and random values, not `random`.
- Avoid `subprocess` with `shell=True` — use list form always.
- Use `httpx` or `requests` with `verify=True` (default) — never disable TLS verification.

### Node.js
- Use `helmet` for HTTP security headers in Express apps.
- Validate all input with `zod` or `joi` before processing.
- Never use `eval()` or `Function()` with user-controlled strings.
- Use `crypto.randomBytes()` for tokens — never `Math.random()`.
- Run `npm audit` before finalizing dependencies.

---

*These guidelines apply to all SilverDay Media coding projects unless a project-specific CLAUDE.md overrides them.*
