# OSGridManager — Architectural Decisions

---

## 001 — Config file location
**Date:** 2026-03-28
**Decision:** Store `config/config.php` inside the project root (not `/etc/osgridmanager/`).
**Rationale:** Simpler deployment on a single-tenant VPS. Apache vhost already denies web access to `/config`. Path override still available via `OGM_CONFIG` env var.

---

## 002 — No Composer autoloader
**Date:** 2026-03-28
**Decision:** All `require_once` calls are explicit with `__DIR__` paths.
**Rationale:** Spec requirement. Reduces supply-chain attack surface, no lock file drift, predictable include order.

---

## 003 — DB-backed sessions
**Date:** 2026-03-28
**Decision:** Sessions stored in `ogm_sessions` table, not PHP's default file handler.
**Rationale:** Enables IP + User-Agent binding, centralised invalidation (force-logout), and cluster-safe storage.

---

## 004 — session_data column added to ogm_sessions
**Date:** 2026-03-28
**Decision:** Added `session_data TEXT NULL` to `ogm_sessions` (not in original spec schema).
**Rationale:** Session needs to store arbitrary data (CSRF tokens, flash messages) beyond the fixed columns. Serialised PHP array.

---

## 006 — Logout is POST-only
**Date:** 2026-03-28
**Decision:** `GET /auth/logout` removed; only `POST /auth/logout` accepted.
**Rationale:** A GET logout endpoint is a CSRF vulnerability — any `<img src="/auth/logout">` on any page can log users out silently. The layout already emitted a POST form with a CSRF token; the route registration in Phase 1 was the error.

---

## 007 — Two-query pattern for UserModel::findByUuid
**Date:** 2026-03-28
**Decision:** `findByUuid` uses two separate queries (opensimRo for UserAccounts, ogmRo for ogm_profiles) merged in PHP — no cross-database JOIN.
**Rationale:** A cross-DB JOIN would require granting `opensim_ro` SELECT on the OGM database. Separate queries keep DB user privileges cleanly scoped.

---

## 008 — Audit log is controller responsibility
**Date:** 2026-03-28
**Decision:** `writeAuditLog` is a static helper on UserModel but called exclusively from UserController. Models do data access; controllers handle business logic and audit trails.
**Rationale:** Clean separation of concerns. Models must not know about the audit log.

---

## 005 — Migration system over single schema file
**Date:** 2026-03-28
**Decision:** Incremental numbered SQL files in `schema/migrations/` tracked by `ogm_migrations` table.
**Rationale:** `schema/ogm_schema.sql` is kept as a reference but `scripts/migrate.php` is the canonical install/upgrade path. Safe to re-run, idempotent seed.
