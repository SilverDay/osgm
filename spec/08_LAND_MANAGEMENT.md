# OSGridManager — Land Management

## Overview

Land management in OpenSim operates at two levels:

| Level | Data Source | OSGridManager Role |
|-------|------------|-------------------|
| **Region** | OpenSim `Regions` table | Read + manage metadata, access rules, ownership |
| **Parcel** | OpenSim `land` + `landaccesslist` tables | Read + manage parcel properties, access lists |

OSGridManager manages land via **direct DB access** to OpenSim's tables (not XMLRPC), using the `opensim_limited` DB user with expanded grants.

> **Important:** OSGridManager cannot trigger live terrain or parcel reloads in a running simulator. Changes to parcel data take effect when the region restarts or when the region operator runs `terrain load` / `land reload` from the OpenSim console. The admin panel must display a clear warning about this.

---

## Additional MariaDB Grants Required

```sql
-- Add to mariadb_setup.sql

-- Region management (write access for selected fields only)
GRANT UPDATE (serverIP, serverPort, serverHttpPort, regionName, locX, locY,
              sizeX, sizeY, flags, access, owner_uuid, gatekeeperURL)
  ON opensim.Regions TO 'opensim_limited'@'localhost';

-- Parcel management
GRANT SELECT, INSERT, UPDATE, DELETE ON opensim.land TO 'opensim_limited'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON opensim.landaccesslist TO 'opensim_limited'@'localhost';

FLUSH PRIVILEGES;
```

---

## OpenSim Land Data Model

### Key OpenSim Tables

**`Regions`** — one row per region (sim):
| Column | Type | Notes |
|--------|------|-------|
| `uuid` | CHAR(36) | Region UUID |
| `regionName` | VARCHAR(128) | Display name |
| `locX`, `locY` | INT | Grid coordinates (in meters, divide by 256 for grid units) |
| `sizeX`, `sizeY` | INT | Region size (256, 512, 1024, 2048) |
| `owner_uuid` | CHAR(36) | Region owner avatar UUID |
| `access` | INT | Region access level (0=public, 1=mature, 2=adult, 13=PG/general) |
| `flags` | INT | Bitmask (see RegionFlags enum in OpenSim source) |
| `serverIP` | VARCHAR(64) | Simulator IP |
| `serverPort` | INT | Simulator UDP port |
| `gatekeeperURL` | VARCHAR(255) | For HG-enabled regions |

**`land`** — one row per parcel:
| Column | Type | Notes |
|--------|------|-------|
| `UUID` | CHAR(36) | Parcel UUID |
| `RegionUUID` | CHAR(36) | Parent region |
| `LocalLandID` | INT | Parcel number within region |
| `Name` | VARCHAR(255) | Parcel name |
| `Description` | VARCHAR(255) | Parcel description |
| `OwnerUUID` | CHAR(36) | Parcel owner avatar UUID |
| `GroupUUID` | CHAR(36) | Owning group (if group-owned) |
| `IsGroupOwned` | INT | 0/1 |
| `Area` | INT | Area in square meters |
| `SalePrice` | INT | Price if for sale (0 = not for sale) |
| `LandFlags` | INT UNSIGNED | Parcel flags bitmask |
| `PassHours` | FLOAT | Temp pass duration |
| `PassPrice` | INT | Temp pass price |
| `AuthBuyerID` | CHAR(36) | If set: only this avatar can buy |
| `Category` | INT | Parcel category |
| `ClaimDate` | INT | Unix timestamp of claim |
| `ClaimPrice` | INT | Price paid at claim |
| `Status` | INT | 0=Leased, 1=Available |
| `UserLookAtX/Y/Z` | FLOAT | Landing point look-at |
| `UserLocationX/Y/Z` | FLOAT | Landing point position |
| `Bitmap` | BLOB | Parcel shape bitmap (do not edit) |
| `MusicURL` | VARCHAR(255) | Parcel music stream URL |
| `MediaURL` | VARCHAR(255) | Parcel media URL |
| `MediaType` | VARCHAR(32) | MIME type of media |
| `ObscureMusic` | TINYINT | Hide music URL from visitors |
| `ObscureMedia` | TINYINT | Hide media URL |

**`landaccesslist`** — per-parcel access control entries:
| Column | Type | Notes |
|--------|------|-------|
| `LandUUID` | CHAR(36) | Parent parcel UUID |
| `AccessUUID` | CHAR(36) | Avatar or group UUID |
| `Flags` | INT | 1=allowed, 2=banned |
| `Expires` | INT | Unix timestamp (0 = no expiry) |

---

## OGM Land Schema Additions

```sql
-- Add to 02_DATABASE_SCHEMA.sql

-- Parcel lease/assignment records (OGM overlay on OpenSim land table)
-- Used when grid operator "leases" parcels to users (optional workflow)
CREATE TABLE IF NOT EXISTS ogm_parcel_leases (
    lease_id        INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    parcel_uuid     CHAR(36)        NOT NULL,
    region_uuid     CHAR(36)        NOT NULL,
    tenant_uuid     CHAR(36)        NOT NULL,           -- leaseholder avatar UUID
    leased_by       CHAR(36)        NOT NULL,           -- admin who created lease
    lease_start     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_end       DATETIME        NULL,               -- NULL = indefinite
    rent_amount     INT UNSIGNED    NOT NULL DEFAULT 0, -- currency per period (0 = free)
    rent_period     ENUM('weekly','monthly','once','free') NOT NULL DEFAULT 'free',
    notes           VARCHAR(512)    NULL,
    status          ENUM('active','expired','terminated') NOT NULL DEFAULT 'active',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lease_id),
    INDEX idx_parcel (parcel_uuid),
    INDEX idx_tenant (tenant_uuid),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Region flags history (track flag changes for audit)
CREATE TABLE IF NOT EXISTS ogm_region_flag_history (
    history_id      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    region_uuid     CHAR(36)        NOT NULL,
    old_flags       INT             NOT NULL,
    new_flags       INT             NOT NULL,
    changed_by      CHAR(36)        NOT NULL,
    reason          VARCHAR(512)    NULL,
    changed_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    INDEX idx_region (region_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Region Management Module (Extended)

### Web UI Pages

| URL | Description |
|-----|-------------|
| `GET /admin/regions` | All regions list with status |
| `GET /admin/regions/{uuid}` | Region detail + parcels list |
| `GET /admin/regions/{uuid}/edit` | Edit region properties |
| `POST /admin/regions/{uuid}/save` | Save region changes |
| `GET /admin/regions/{uuid}/access` | Region access list (banned/allowed users) |
| `POST /admin/regions/{uuid}/access/add` | Add access entry |
| `POST /admin/regions/{uuid}/access/remove` | Remove access entry |
| `GET /admin/regions/{uuid}/parcels` | All parcels in region |
| `POST /admin/regions/{uuid}/transfer` | Transfer region ownership |

### Editable Region Fields (via OSGridManager)

| Field | OpenSim Column | Notes |
|-------|---------------|-------|
| Region name | `regionName` | Cosmetic only once registered |
| Access rating | `access` | 0=General, 1=Mature, 2=Adult |
| Owner | `owner_uuid` | Avatar UUID lookup by name |
| Hypergrid URL | `gatekeeperURL` | Blank to disable HG for this region |
| OGM: Access level | `ogm_regions.access_level` | public / private / hypergrid_allowed |
| OGM: Featured | `ogm_regions.is_featured` | Show on grid homepage |
| OGM: Notes | `ogm_regions.notes` | Admin-only notes |
| OGM: Web URL | `ogm_regions.web_url` | Optional website for the region |

**Read-only in web UI** (managed by OpenSim/ROBUST directly):
- Server IP, port — shown for info only
- Region UUID — immutable
- Grid coordinates — shown, not editable (changing breaks map)
- Region size — not editable after creation

### Region Flags (bitmask display)

Display as named checkboxes, read from `Regions.flags`:

```
AllowDamage       = 0x01
BlockTerraform    = 0x40
BlockLandResell   = 0x80
SandBox           = 0x100
AllowLandmark     = 0x200 (usually always set)
AllowParcelChanges= 0x400
PublicAllowed     = 0x20000
```

Show flags as labelled read-only indicators in the UI. Full flag editing is out of scope (too risky without region restart) — document this clearly.

### Region Ownership Transfer

```
Admin selects: source region UUID + new owner (search by avatar name → UUID)
OSGridManager:
  1. Verify new owner exists in UserAccounts and is Active
  2. UPDATE Regions SET owner_uuid = :new_owner WHERE uuid = :region_uuid
  3. Log to ogm_audit_log (action: 'region.ownership_transferred')
  4. Log to ogm_region_flag_history for record
  5. Show warning: "Region restart required for inworld effect"
```

---

## Parcel Management Module

### Web UI Pages

| URL | Description |
|-----|-------------|
| `GET /admin/regions/{uuid}/parcels` | All parcels in region |
| `GET /admin/parcels/{parcel_uuid}` | Parcel detail |
| `GET /admin/parcels/{parcel_uuid}/edit` | Edit parcel properties |
| `POST /admin/parcels/{parcel_uuid}/save` | Save parcel changes |
| `GET /admin/parcels/{parcel_uuid}/access` | Parcel access list |
| `POST /admin/parcels/{parcel_uuid}/access/add` | Add access entry |
| `POST /admin/parcels/{parcel_uuid}/access/remove` | Remove access entry |
| `GET /admin/parcels/{parcel_uuid}/lease` | Manage lease |
| `POST /admin/parcels/{parcel_uuid}/lease/create` | Create lease |
| `POST /admin/parcels/{parcel_uuid}/lease/terminate` | Terminate lease |

### Editable Parcel Fields

| Field | Column | Notes |
|-------|--------|-------|
| Name | `Name` | Max 255 chars |
| Description | `Description` | Max 255 chars |
| Owner | `OwnerUUID` | Avatar UUID, lookup by name |
| For sale | `SalePrice` | 0 = not for sale; >0 = price |
| Authorised buyer | `AuthBuyerID` | Null UUID = anyone can buy |
| Music stream URL | `MusicURL` | http/https only |
| Media URL | `MediaURL` | http/https only |
| Landing point | `UserLocationX/Y/Z` | Float coords within region |
| Pass price | `PassPrice` | 0 = no temp pass |
| Pass duration | `PassHours` | Hours for temp pass |
| Category | `Category` | Mapped to OpenSim parcel categories |
| Status | `Status` | 0=Leased, 1=Available |

**Never edit:** `Bitmap` (parcel shape), `RegionUUID`, `UUID`, `LocalLandID`

### Parcel Flags Display

Show selected `LandFlags` as named read-only indicators:

```
AllowOtherScripts    = 0x00000001  -- Others' scripts can run
AllowGroupScripts    = 0x00000002  -- Group scripts can run
AllowAPrims          = 0x00000004  -- Autoreturn applies to others
CreateObjects        = 0x00000008  -- Anyone can rez objects
AllowAllObjectEntry  = 0x00000020  -- All objects can enter
AllowGroupObjectEntry= 0x00000040
AllowFly             = 0x00000080
AllowLandmark        = 0x00000100
AllowTerraform       = 0x00000400
AllowDamage          = 0x00000800
AllowSeeAvatars      = 0x00001000
ForSale              = 0x80000000
```

Show as labelled read-only badges. Full flag editing requires simulator cooperation — mark as future scope.

### Parcel Access List Management

The `landaccesslist` table has two entry types (by `Flags` value):

| Flags value | Meaning |
|-------------|---------|
| `1` (0x01) | Access allowed (whitelist) |
| `2` (0x02) | Banned |

Web UI:
- Separate tabs: **Allowed** | **Banned**
- Add entry: search avatar by name → UUID, select type, optional expiry date
- Remove entry: delete row from `landaccesslist`
- Show avatar name (resolved from OpenSim `UserAccounts`) alongside UUID

```sql
-- Add access entry
INSERT INTO landaccesslist (LandUUID, AccessUUID, Flags, Expires)
VALUES (:parcel_uuid, :avatar_uuid, :flags, :expires_unix);

-- Remove access entry
DELETE FROM landaccesslist
WHERE LandUUID = :parcel_uuid AND AccessUUID = :avatar_uuid AND Flags = :flags;
```

### Parcel Lease Workflow

This is an OGM-managed overlay — not a native OpenSim concept.

**Creating a lease:**
```
Admin selects parcel → clicks "Create Lease"
  - Select tenant (search avatar by name)
  - Set lease end date (or indefinite)
  - Set rent amount + period (or free)
  - Add notes
OSGridManager:
  1. INSERT into ogm_parcel_leases
  2. UPDATE land SET OwnerUUID = :tenant_uuid WHERE UUID = :parcel_uuid
  3. Log to ogm_audit_log
  4. Show warning: "Parcel ownership change requires region restart"
```

**Terminating a lease:**
```
Admin clicks "Terminate Lease"
  - Optional: reassign ownership (back to grid admin, or new tenant)
OSGridManager:
  1. SET ogm_parcel_leases.status = 'terminated'
  2. UPDATE land SET OwnerUUID = :new_owner_uuid WHERE UUID = :parcel_uuid
  3. Log to ogm_audit_log
```

**Lease listing for admin:**
- `/admin/leases` — all active leases, grouped by region
- Shows: parcel name, region, tenant name, lease end, rent info
- Filter: active / expired / terminated

---

## User-Facing Land Views

Regular users can see (but not edit):

**`GET /land`** — Browse available regions and parcels
- List of public regions with region name, owner, parcel count
- Click region → list of public parcels (those with `Status=1` or for-sale)
- Shows: parcel name, owner, area, price (if for sale)

**`GET /profile/{uuid}`** — User profile extended with:
- "Land Holdings" section: list of regions owned + parcels owned by this avatar
- Query: `SELECT * FROM land WHERE OwnerUUID = :uuid`
- Query: `SELECT * FROM Regions WHERE owner_uuid = :uuid`

---

## Important Caveats for Claude Code

1. **Bitmap field**: The `land.Bitmap` BLOB defines the parcel shape on the terrain. Never modify it — only read for display (it's a 512-byte bit-array representing 64×64 cells). Display parcel area from the `Area` field instead.

2. **Live region reload**: Changes to `land` and `Regions` tables do NOT take effect in a running simulator without a reload. Always display this warning after any land write operation:
   ```
   ⚠️ Land changes will take effect after the region is restarted or the 
   estate manager runs "land reload" from the region console.
   ```

3. **Group ownership**: Parcels with `IsGroupOwned=1` have group UUID in `GroupUUID`. Group management is out of scope for v1 — show group UUID as-is, do not attempt to resolve group names (OpenSim groups are complex and may use an external groups module).

4. **Parcel coordinates**: `UserLocationX/Y/Z` and landing point are region-local coordinates (0–255 for standard regions, 0–511 for var regions). Validate ranges based on region `sizeX`/`sizeY`.

5. **ScopeID**: All OpenSim DB queries involving UserAccounts should include `ScopeID = '00000000-0000-0000-0000-000000000000'` for single-grid setups. This ensures compatibility with multi-scope configurations.

---

## Module Build Order Addition

Insert after Phase 4 (Region Management) in `00_CLAUDE_CODE_INSTRUCTIONS.md`:

```
Phase 4b: Land Management
  - src/Modules/Land/LandModel.php         (region + parcel queries)
  - src/Modules/Land/LandController.php    (admin CRUD)
  - src/Modules/Land/ParcelAccessModel.php (landaccesslist management)
  - src/Modules/Land/LeaseModel.php        (ogm_parcel_leases)
  - templates/land/ (region list, parcel detail, access list, lease management)
  - templates/admin/land/ (admin views)
```

Insert after Phase 2 (User & Auth) in `00_CLAUDE_CODE_INSTRUCTIONS.md`:

```
Phase 2b: User Levels & Registration
  - src/Modules/Registration/RegistrationModel.php
  - src/Modules/Registration/RegistrationController.php
  - src/Core/Mailer.php
  - src/Core/Totp.php (for admin 2FA — single file RFC 6238 implementation)
  - templates/register/ (form, verify, pending, approved, rejected)
  - templates/email/ (plaintext email templates as PHP files)
  - Admin: src/Admin/AdminRegistrationController.php
  - Admin: templates/admin/registrations/ (queue, approve, reject)
  - scripts/expire_registrations.php (cron: mark unverified as expired after 48h)
```
