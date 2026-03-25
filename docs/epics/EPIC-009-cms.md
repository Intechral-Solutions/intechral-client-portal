# EPIC-009: CMS & Documentation

**Status:** Pending
**Branch:** `epic/009-cms`
**Goal:** Provide a content management system for managing the public-facing intechral.solutions website content and platform documentation, with Markdown support.

---

## User Stories

### STORY-009-01: Page Content Management
**As a** platform operator,
**I want** to edit content blocks on the intechral.solutions website from within the portal,
**So that** the marketing site stays up to date without needing code deployments.

**Acceptance Criteria:**
- [ ] Content areas on the static site are wired to CMS content blocks
- [ ] Rich text editor (Tiptap or ProseMirror) for content blocks
- [ ] Changes preview before publishing
- [ ] Publish/unpublish/schedule content
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
- [ ] Documentation portal accessible to users based on permissions

### STORY-009-03: Documentation Portal (User-Facing)
**As a** portal user,
**I want** to browse and search the platform documentation,
**So that** I can self-serve answers to common questions.

**Acceptance Criteria:**
- [ ] Clean, readable documentation layout with sidebar navigation
- [ ] Full-text search within documentation
- [ ] "Was this helpful?" feedback on each page
- [ ] Operator can restrict sections to specific roles

### STORY-009-04: Media Library
**As a** content editor,
**I want** a centralised media library for images and files,
**So that** assets are reusable across pages and documentation.

**Acceptance Criteria:**
- [ ] Upload, organise, and tag media files
- [ ] Image optimisation on upload (resize, compress)
- [ ] Copy URL / embed code for use in content
- [ ] Storage backend configurable (local disk or S3-compatible)

---

## Definition of Done

- All acceptance criteria above are checked
- TDD applied throughout
- Permissions wired (`cms.view`, `cms.edit`, `cms.publish`, `cms.admin`)
- Merged to `main` via PR
