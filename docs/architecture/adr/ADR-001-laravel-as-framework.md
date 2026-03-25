# ADR-001: Use Laravel as the Application Framework

**Date:** 2024-03-24
**Status:** Accepted

## Context

The Intechral Client Portal needs a PHP framework that:
- Is compatible with cPanel/shared hosting environments
- Has mature ecosystem for auth, queues, mail, and testing
- Supports MariaDB natively
- Has long-term community and commercial support

## Decision

Use **Laravel 11** with **PHP 8.3**.

## Rationale

- Laravel is the most widely deployed PHP framework and is universally supported on cPanel hosts
- Built-in support for queues, events, mail, testing, and caching reduces custom boilerplate
- Fortify, Socialite, and Sanctum provide auth primitives out of the box
- Spatie packages (Permission, Activity Log, Media Library) are production-proven
- Pest PHP provides a modern test runner with a readable DSL on top of PHPUnit
- Laravel's release cadence and LTS guarantees provide stability

## Consequences

- Team must be comfortable with Laravel conventions (Eloquent, Blade, Artisan)
- cPanel deployment requires ensuring PHP 8.3 is selectable in the host's "PHP Version" tool
