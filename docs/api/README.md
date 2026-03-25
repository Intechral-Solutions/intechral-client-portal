# API Documentation

The Intechral Client Portal exposes an internal REST API used by frontend components and potentially by third-party integrations.

## Authentication

API requests are authenticated via **Laravel Sanctum** (token-based). Tokens are issued after successful login.

```
Authorization: Bearer {token}
```

## Versioning

API routes are prefixed with `/api/v1/`. Breaking changes will introduce a new version prefix.

## Response Format

All API responses follow a consistent envelope:

```json
{
  "data": { ... },
  "meta": { ... },
  "links": { ... }
}
```

Errors:
```json
{
  "message": "Human-readable error message",
  "errors": {
    "field": ["Validation error detail"]
  }
}
```

## Modules

Documentation for each module's API will be added as epics are completed:

- [ ] Auth API (EPIC-002)
- [ ] Users & Roles API (EPIC-003)
- [ ] Tickets API (EPIC-004)
- [ ] Projects API (EPIC-005)
- [ ] Billing API (EPIC-006)
- [ ] Time Tracking API (EPIC-007)
- [ ] CRM API (EPIC-008)
- [ ] CMS API (EPIC-009)
