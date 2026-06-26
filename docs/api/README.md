# API Documentation

> **Status:** Not yet implemented.
>
> The Intechral Client Portal is currently a fully server-rendered Blade application. There is no REST API layer at this time. A handful of JSON endpoints exist for internal UI use (timer state, cascading selectors, Stripe payment intents) but they are not versioned, not documented here, and not intended for external consumption.
>
> A formal REST API is planned for a future epic. The design notes below reflect the intended architecture when that work begins.

---

## Planned Design

### Authentication

API requests will be authenticated via **Laravel Sanctum** (token-based). Tokens will be issued after successful login.

```
Authorization: Bearer {token}
```

### Versioning

API routes will be prefixed with `/api/v1/`. Breaking changes will introduce a new version prefix.

### Response Format

All API responses will follow a consistent envelope:

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

### Planned Module Coverage

- [ ] Auth API (EPIC-002)
- [ ] Users & Roles API (EPIC-003)
- [ ] Tickets API (EPIC-004)
- [ ] Projects API (EPIC-005)
- [ ] Billing API (EPIC-006)
- [ ] Time Tracking API (EPIC-007)
- [ ] CRM API (EPIC-008)
- [ ] CMS API (EPIC-009)
