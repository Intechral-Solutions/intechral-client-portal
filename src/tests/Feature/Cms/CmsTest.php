<?php

use App\Models\CmsPage;
use App\Models\User;
use App\Services\CmsService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Access control ────────────────────────────────────────────────────────────

it('redirects guests from cms operator index to login', function () {
    $this->get(route('operator.cms.index'))->assertRedirect('/login');
});

it('returns 403 for users without cms.edit on operator index', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // user role has cms.view, not cms.edit

    $this->actingAs($user)->get(route('operator.cms.index'))->assertForbidden();
});

it('allows operators to access the cms operator index', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('operator.cms.index'))->assertOk();
});

// ── User CMS viewer ───────────────────────────────────────────────────────────

it('allows users with cms.view to see the pages list', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->get(route('cms.index'))->assertOk();
});

it('returns 403 for users without cms.view on the pages list', function () {
    $user = User::factory()->create();
    // No role — no permissions

    $this->actingAs($user)->get(route('cms.index'))->assertForbidden();
});

it('shows only published pages on the public listing', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $published = CmsPage::factory()->published()->create(['created_by' => $operator->id]);
    $draft = CmsPage::factory()->create(['created_by' => $operator->id]);

    $user = User::factory()->create();
    $user->assignRole('user');

    $response = $this->actingAs($user)->get(route('cms.index'));
    $response->assertOk();
    $response->assertSee($published->title);
    $response->assertDontSee($draft->title);
});

it('shows a published page to a logged-in user', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $page = CmsPage::factory()->published()->create([
        'created_by' => $operator->id,
        'body' => '<p>Hello World</p>',
    ]);

    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->get(route('cms.show', $page->slug))
        ->assertOk()
        ->assertSee('Hello World');
});

it('returns 404 for a draft page on the public route', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $page = CmsPage::factory()->create(['created_by' => $operator->id]); // draft

    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->get(route('cms.show', $page->slug))->assertNotFound();
});

// ── Create / edit ─────────────────────────────────────────────────────────────

it('creates a page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('operator.cms.store'), [
        'title' => 'Privacy Policy',
        'slug' => 'privacy-policy',
        'body' => '<p>Our privacy policy.</p>',
    ])->assertRedirect();

    expect(CmsPage::where('slug', 'privacy-policy')->exists())->toBeTrue();
});

it('auto-generates a slug when blank', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('operator.cms.store'), [
        'title' => 'Terms of Service',
        'slug' => '',
    ])->assertRedirect();

    expect(CmsPage::where('slug', 'terms-of-service')->exists())->toBeTrue();
});

it('validates slug format', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('operator.cms.store'), [
        'title' => 'Bad Slug',
        'slug' => 'Has Spaces!',
    ])->assertSessionHasErrors('slug');
});

it('validates slug is unique', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    CmsPage::factory()->create(['slug' => 'taken-slug', 'created_by' => $operator->id]);

    $this->actingAs($operator)->post(route('operator.cms.store'), [
        'title' => 'Another Page',
        'slug' => 'taken-slug',
    ])->assertSessionHasErrors('slug');
});

it('updates a page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $page = CmsPage::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->put(route('operator.cms.update', $page), [
        'title' => 'Updated Title',
        'slug' => $page->slug,
    ])->assertRedirect();

    expect($page->fresh()->title)->toBe('Updated Title');
});

// ── Publish / unpublish ───────────────────────────────────────────────────────

it('publishes a draft page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $page = CmsPage::factory()->create(['created_by' => $operator->id]);
    expect($page->isPublished())->toBeFalse();

    $this->actingAs($operator)->post(route('operator.cms.publish', $page))->assertRedirect();

    expect($page->fresh()->isPublished())->toBeTrue();
    expect($page->fresh()->published_at)->not->toBeNull();
});

it('unpublishes a published page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $page = CmsPage::factory()->published()->create(['created_by' => $operator->id]);
    expect($page->isPublished())->toBeTrue();

    $this->actingAs($operator)->post(route('operator.cms.unpublish', $page))->assertRedirect();

    expect($page->fresh()->isPublished())->toBeFalse();
});

it('returns 403 when a non-publisher tries to publish', function () {
    // Create a user with cms.edit but NOT cms.publish
    $user = User::factory()->create();
    // Manually assign only cms.edit
    $user->givePermissionTo('cms.edit');

    $page = CmsPage::factory()->create(['created_by' => $user->id]);

    $this->actingAs($user)->post(route('operator.cms.publish', $page))->assertForbidden();
});

// ── Delete ────────────────────────────────────────────────────────────────────

it('deletes a page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $page = CmsPage::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->delete(route('operator.cms.destroy', $page))->assertRedirect();

    expect(CmsPage::find($page->id))->toBeNull();
});

it('returns 403 when a non-admin tries to delete', function () {
    // Create a user with cms.edit and cms.publish but NOT cms.admin
    $user = User::factory()->create();
    $user->givePermissionTo(['cms.edit', 'cms.publish']);

    $page = CmsPage::factory()->create(['created_by' => $user->id]);

    $this->actingAs($user)->delete(route('operator.cms.destroy', $page))->assertForbidden();
});

// ── Service helpers ───────────────────────────────────────────────────────────

it('generates unique slugs when titles collide', function () {
    $user = User::factory()->create();
    $service = app(CmsService::class);

    $page1 = $service->create($user, ['title' => 'About Us']);
    $page2 = $service->create($user, ['title' => 'About Us']);

    expect($page1->slug)->toBe('about-us');
    expect($page2->slug)->toBe('about-us-2');
});

it('preserves the original published_at on re-publish', function () {
    $user = User::factory()->create();
    $service = app(CmsService::class);

    $page = $service->create($user, ['title' => 'Stable Date']);
    $service->publish($page, $user);
    $originalDate = $page->fresh()->published_at;

    $service->unpublish($page->fresh(), $user);
    $service->publish($page->fresh(), $user);

    expect($page->fresh()->published_at->toDateString())->toBe($originalDate->toDateString());
});
