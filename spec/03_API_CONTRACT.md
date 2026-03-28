# OSGridManager — API Contract

## Overview

OSGridManager exposes two API surfaces:

1. **REST API** (`/api/v1/`) — for LSL HTTP requests and future clients
2. **XMLRPC** (`/xmlrpc/`) — for OpenSim module compatibility (economy, profiles)

All REST endpoints return `application/json`. All XMLRPC endpoints return standard XMLRPC responses.

---

## REST API Authentication

### Region Token (for region/system-level calls)

Every request must include:

```
X-OGM-Region: <region_uuid>
X-OGM-Timestamp: <unix_timestamp>
X-OGM-Signature: <hmac_sha256_hex>
```

Signature = `HMAC-SHA256(region_uuid + ":" + timestamp + ":" + request_body, region_secret)`

Timestamp must be within ±120 seconds of server time.

### User Token (for user-level calls, combined with region token)

```
X-OGM-User: <user_uuid>
X-OGM-User-Token: <user_token>
```

User tokens are issued at login (web or inworld) and scoped to specific capabilities.

### Admin Token (web session only, not exposed to LSL)

Cookie-based session. Admin endpoints not accessible from LSL.

---

## REST API Endpoints

### Auth Module `/api/v1/auth`

#### POST `/api/v1/auth/inworld-login`
Authenticates a user inworld (via LSL) and issues a user token.

**Auth required:** Region token only

**Request:**
```json
{
  "user_uuid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "avatar_name": "Firstname Lastname"
}
```

**Response 200:**
```json
{
  "success": true,
  "user_token": "eyJ...",
  "scope": ["profile", "economy", "messaging", "search"],
  "expires_at": 1700000000,
  "balance": 1500,
  "display_name": "Firstname Lastname",
  "notifications_unread": 2
}
```

**Response 401:**
```json
{ "success": false, "error": "invalid_region_token" }
```

**Response 429:**
```json
{ "success": false, "error": "rate_limited", "retry_after": 30 }
```

---

#### POST `/api/v1/auth/token-revoke`
Revokes a user token inworld (on teleport away, logout, etc.)

**Auth required:** Region token + User token

**Request:**
```json
{ "user_uuid": "..." }
```

**Response 200:**
```json
{ "success": true }
```

---

### Economy Module `/api/v1/economy`

#### GET `/api/v1/economy/balance`
Get current balance for a user.

**Auth required:** Region token + User token

**Query:** `?user_uuid=<uuid>`

**Response 200:**
```json
{
  "success": true,
  "user_uuid": "...",
  "balance": 1500,
  "currency_name": "GridBucks",
  "currency_symbol": "G$"
}
```

---

#### POST `/api/v1/economy/transfer`
Transfer currency between users.

**Auth required:** Region token + User token (of sender)

**Request:**
```json
{
  "from_uuid": "...",
  "to_uuid": "...",
  "amount": 100,
  "description": "Payment for art",
  "object_uuid": "...",
  "region_uuid": "..."
}
```

**Validations:**
- `amount` must be > 0 and <= configured daily limit
- `from_uuid` must match authenticated user token
- Sender must have sufficient balance (check hold + balance)
- `to_uuid` must be a valid, active grid user

**Response 200:**
```json
{
  "success": true,
  "tx_uuid": "...",
  "new_balance": 1400
}
```

**Response 402:**
```json
{ "success": false, "error": "insufficient_funds", "balance": 50 }
```

---

#### GET `/api/v1/economy/history`
Transaction history for a user.

**Auth required:** Region token + User token (or web session)

**Query:** `?user_uuid=<uuid>&limit=20&offset=0&type=all`

**Response 200:**
```json
{
  "success": true,
  "transactions": [
    {
      "tx_uuid": "...",
      "from_uuid": "...",
      "from_name": "Avatar Name",
      "to_uuid": "...",
      "to_name": "Avatar Name",
      "amount": 100,
      "tx_type": "transfer",
      "description": "Payment for art",
      "created_at": "2025-01-01T12:00:00Z",
      "status": "confirmed"
    }
  ],
  "total": 42,
  "balance": 1400
}
```

---

#### POST `/api/v1/economy/pay-object`
Inworld object purchase hook. Called by OpenSim economy module via LSL or XMLRPC.

**Auth required:** Region token

**Request:**
```json
{
  "buyer_uuid": "...",
  "seller_uuid": "...",
  "object_uuid": "...",
  "object_name": "Red Widget",
  "amount": 250,
  "region_uuid": "..."
}
```

**Response 200:**
```json
{ "success": true, "tx_uuid": "..." }
```

---

### Messaging Module `/api/v1/messaging`

#### GET `/api/v1/messaging/inbox`
Get messages for a user.

**Auth required:** Region token + User token (or web session)

**Query:** `?user_uuid=<uuid>&limit=20&offset=0&unread_only=false`

**Response 200:**
```json
{
  "success": true,
  "messages": [
    {
      "msg_id": 42,
      "from_uuid": "...",
      "from_name": "Avatar Name",
      "subject": "Hello!",
      "body": "Hey there...",
      "sent_at": "2025-01-01T12:00:00Z",
      "read_at": null,
      "source": "inworld"
    }
  ],
  "total": 5,
  "unread": 2
}
```

---

#### POST `/api/v1/messaging/send`
Send a grid message to another user.

**Auth required:** Region token + User token (or web session)

**Request:**
```json
{
  "from_uuid": "...",
  "to_uuid": "...",
  "subject": "Hello!",
  "body": "Hey there, how are you?",
  "source": "inworld"
}
```

**Validations:**
- `body` max 4096 chars
- `subject` max 255 chars
- `to_uuid` must be active grid user
- Rate limit: max 20 messages per hour per user

**Response 200:**
```json
{ "success": true, "msg_id": 42 }
```

---

#### POST `/api/v1/messaging/mark-read`
Mark messages as read.

**Auth required:** Region token + User token

**Request:**
```json
{ "user_uuid": "...", "msg_ids": [42, 43, 44] }
```

**Response 200:**
```json
{ "success": true, "marked": 3 }
```

---

#### GET `/api/v1/messaging/notifications`
Get unread notifications for a user.

**Auth required:** Region token + User token

**Query:** `?user_uuid=<uuid>&limit=10`

**Response 200:**
```json
{
  "success": true,
  "notifications": [
    {
      "notif_id": 7,
      "type": "money_received",
      "title": "You received G$100",
      "body": "Avatar Name sent you G$100: Payment for art",
      "created_at": "2025-01-01T12:00:00Z"
    }
  ],
  "unread_count": 1
}
```

---

### Profile Module `/api/v1/profile`

#### GET `/api/v1/profile`
Get a user's profile.

**Auth required:** Region token (public profile data)

**Query:** `?user_uuid=<uuid>`

**Response 200:**
```json
{
  "success": true,
  "user_uuid": "...",
  "avatar_name": "Firstname Lastname",
  "display_name": "Nick",
  "bio": "Explorer and builder.",
  "website": "https://example.com",
  "avatar_pic_url": "https://grid.example.com/avatars/uuid.jpg",
  "show_online": true,
  "online": true,
  "last_region": "Welcome Island",
  "last_login": "2025-01-01T12:00:00Z",
  "member_since": "2023-05-01T00:00:00Z"
}
```

---

#### POST `/api/v1/profile/update`
Update own profile.

**Auth required:** Region token + User token (user can only update own profile)

**Request:**
```json
{
  "user_uuid": "...",
  "bio": "Updated bio",
  "website": "https://example.com",
  "show_online": true,
  "show_in_search": true
}
```

**Response 200:**
```json
{ "success": true }
```

---

### Region Module `/api/v1/region`

#### GET `/api/v1/region/list`
List all active regions.

**Auth required:** Region token

**Response 200:**
```json
{
  "success": true,
  "regions": [
    {
      "region_uuid": "...",
      "region_name": "Welcome Island",
      "owner_uuid": "...",
      "owner_name": "Admin Avatar",
      "location_x": 1000,
      "location_y": 1000,
      "size_x": 256,
      "size_y": 256,
      "access_level": "public",
      "allow_hypergrid": true,
      "is_online": true
    }
  ]
}
```

---

#### GET `/api/v1/region/status`
Get status of a specific region.

**Auth required:** Region token

**Query:** `?region_uuid=<uuid>`

**Response 200:**
```json
{
  "success": true,
  "region_uuid": "...",
  "region_name": "Welcome Island",
  "is_online": true,
  "agent_count": 3,
  "uptime_seconds": 86400
}
```

---

### Search Module `/api/v1/search`

#### GET `/api/v1/search`
Full-text search across regions, users, classifieds.

**Auth required:** Region token

**Query:** `?q=<query>&type=all&limit=10&offset=0`
`type` = `all | region | user | classifieds`

**Response 200:**
```json
{
  "success": true,
  "query": "welcome",
  "results": [
    {
      "type": "region",
      "uuid": "...",
      "title": "Welcome Island",
      "description": "Starting point for new avatars.",
      "region_uuid": "...",
      "position": {"x": 128, "y": 128, "z": 25}
    }
  ],
  "total": 1
}
```

---

## XMLRPC Endpoints

### Economy XMLRPC `/xmlrpc/economy.php`

Compatible with OpenSim's `MoneyModule` XMLRPC interface. Handles calls from the simulator for economy events.

#### Method: `getCurrencyQuote`
Returns currency exchange rate (or placeholder for internal-only economy).

**Params:** `agentId`, `secureSessionId`, `currencyBuy`, `language`

**Returns:** `{ success: true, currency: { estimatedCost: 0, currencyBuy: <amount> } }`

---

#### Method: `buyCurrency`
Handles buy-currency request (noop for internal economy — only admin grants).

**Params:** `agentId`, `secureSessionId`, `currencyBuy`, `cost`

**Returns:** `{ success: false, errorMessage: "Direct purchase not supported. Contact admin." }`

---

#### Method: `preflightBuyLandPrep`
Land purchase preflight check.

**Returns:** `{ success: true, membership: { upgrade: false } }`

---

#### Method: `buyLandPrep`
Land purchase handler (stub — region ownership managed via admin panel).

**Returns:** `{ success: false, errorMessage: "Land sales managed by grid admin." }`

---

### Profile XMLRPC `/xmlrpc/profile.php`

Compatible with OpenSim's `osprofile` module.

#### Method: `avatar_properties_request`
Returns avatar profile properties.

**Params:** `avatar_id`, `sender_id`

**Returns:** Standard osprofile property set including bio, web URL, image UUID.

---

#### Method: `avatar_properties_update`
Updates avatar profile properties from inworld.

**Params:** `avatar_id`, `sender_id`, properties map

**Returns:** `{ success: true }`

---

#### Method: `avatar_interests_update`
Updates avatar interests (skills, wants).

**Returns:** `{ success: true }`

---

#### Method: `avatar_notes_request` / `avatar_notes_update`
Read/write per-avatar notes.

---

#### Method: `user_preferences_request` / `user_preferences_update`
Read/write user preferences (IM-to-email, visible online status).

---

## Error Response Format (REST)

All errors follow:

```json
{
  "success": false,
  "error": "error_code",
  "message": "Human readable description",
  "details": {}
}
```

### Standard Error Codes

| Code                    | HTTP Status | Meaning                            |
|-------------------------|-------------|------------------------------------|
| `invalid_signature`     | 401         | HMAC validation failed             |
| `expired_timestamp`     | 401         | Request timestamp outside window   |
| `invalid_region_token`  | 401         | Unknown or inactive region token   |
| `invalid_user_token`    | 401         | Unknown, expired, or revoked token |
| `insufficient_scope`    | 403         | Token lacks required scope         |
| `user_not_found`        | 404         | UUID not found in grid             |
| `region_not_found`      | 404         | Region UUID not found              |
| `insufficient_funds`    | 402         | Balance too low                    |
| `rate_limited`          | 429         | Too many requests                  |
| `validation_error`      | 422         | Input validation failed            |
| `server_error`          | 500         | Unhandled exception                |

---

## LSL Script Pattern

```lsl
// Example: Get balance inworld
string OGM_API = "https://grid.example.com/api/v1/";
string REGION_UUID = "...";
string REGION_TOKEN = "...";

string hmac_sign(string payload) {
    // Note: LSL cannot do native HMAC
    // Use llRequestURL + external signing relay, OR
    // use a pre-shared per-session token issued at avatar login
    return ""; // see token flow in auth spec
}

default {
    state_entry() {
        // On avatar touch: authenticate and get balance
        string timestamp = (string)llGetUnixTime();
        llHTTPRequest(OGM_API + "auth/inworld-login",
            [HTTP_METHOD, "POST",
             HTTP_MIMETYPE, "application/json",
             HTTP_CUSTOM_HEADER, "X-OGM-Region", REGION_UUID,
             HTTP_CUSTOM_HEADER, "X-OGM-Timestamp", timestamp,
             HTTP_CUSTOM_HEADER, "X-OGM-Signature", hmac_sign(...)],
            llList2Json(JSON_OBJECT, [
                "user_uuid", llGetOwner(),
                "avatar_name", llKey2Name(llGetOwner())
            ])
        );
    }
}
```

> **Note for Claude Code:** LSL cannot compute HMAC natively. The recommended approach is a two-step flow: the avatar touches the object → the server issues a short-lived session token via the inworld-login endpoint (authenticated by region token only) → subsequent LSL calls use that session token. Implement this flow in `auth.php`.
