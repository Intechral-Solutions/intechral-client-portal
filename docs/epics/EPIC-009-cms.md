# EPIC-009: CMS & Documentation

**Status:** Implemented
**Committed:** 2026-03-25

---

## Goal

Provide a content management system for managing the public-facing intechral.solutions website content and platform documentation, with Markdown support.

---

## User Stories

### STORY-009-01: Page Content Management
**As a** platform operator,
**I want** to edit content blocks on the intechral.solutions website from within the portal,
**So that** the marketing site stays up to date without needing code deployments.

**Acceptance Criteria:**
- [x] Content pages manageable from within the operator area
- [ ] Rich text editor (Tiptap or ProseMirror) wired to content fields
- [ ] Changes preview before publishing
- [x] Publish / unpublish content
- [ ] Revision history with rollback

### STORY-009-02: Documentation Management
**As a** platform operator,
**I want** to manage platform documentation using Markdown,
**So that** documentation is version-controlled and easy to write.

**Acceptance Criteria:**
- [ ] Markdown editor with live preview (split pane)
- [ ] Documents organised in a tree (sections > pages)
- [ ] Documents versioned (edit history)
- [ ] Full-text search across all documentation

### STORY-009-03: Documentation Portal (User-Facing)
**As a** portal user,
**I want** to browse and search the platform documentation,
**So that** I can self-serve answers to common questions.

**Acceptance Criteria:**
- [x] Clean, readable page layout accessible to authenticated users
- [ ] Full-text search within documentation
- [ ] "Was this helpful?" feedback on each page
- [ ] Operator can restrict sections to specific roles

### STORY-009-04: Media Library
**As a** content editor,
**I want** a centralised media library for images and files,
**So that** assets are reusable across pages and documentation.

**Acceptance Criteria:**
- [ ] Upload, organise, and tag media files
- [ ] Image optimisation on upload
- [ ] Copy URL / embed code for use in content
- [ ] Storage backend configurable (local disk or S3-compatible)

---

## Implementation

### What Was Built

**Migrations**
- `create_cms_pages_table` — slug, title, content (stored text), status (draft/published), published_at, meta fields

**Controllers**
- `Operator\CmsPageController` — operator page management: index, create, store, edit, update, publish, unpublish, destroy
- `CmsController` — authenticated public viewer: index (list published pages), show (render by slug)

**Views**
- `operator/cms/index`, `create`, `edit`, `_form`
- `cms/index`, `cms/show` — public page listing and viewer

**Routes**
- `/operator/cms` (`can:cms.edit`) — operator CRUD + publish/unpublish
- `/pages` (`can:cms.view`) — public reader

### Known Gaps

- Rich text / Markdown editor not confirmed as wired in the frontend
- Preview before publish not implemented
- Revision history / rollback not implemented (`cms_pages` has no revisions table)
- Documentation tree (sections > pages hierarchy) not implemented — current CMS is flat
- Full-text search not implemented
- "Was this helpful?" feedback not implemented
- Media library (STORY-009-04) not built — `spatie/laravel-medialibrary` is declared in `composer.json` but no media UI exists

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`cms.view`, `cms.edit`, `cms.publish`, `cms.admin`)
- Merged to `main` via PR
