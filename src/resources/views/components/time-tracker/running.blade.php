{{--
    EPIC-016 WP2 (§9.9): the embedded ticket time tracker's RUNNING state, rendered by the server.

    <x-time-tracker.running :entry-id="$runningEntry->id" />

    Used twice by components/time-tracker.blade.php, from this one source: directly, when the viewer already
    has a running entry, and inside a <template> that the script clones when a timer starts on the page. So
    the classes come from the Blade components and no markup or colour lives in a JavaScript string.

    - Running is `live`, not `success` (Direction D §2.3.1): a live dot and the existing "Timer running"
      text in `text-live-text`.
    - Stop is a non-destructive timer control: `secondary` `sm`, never danger. Its visible name, "Stop", is
      unchanged. The script finds it through `data-time-tracker-stop`, and reads the entry id from
      `data-entry-id` (the template carries none; the script sets it from the start response).
--}}
@props(['entryId' => null])

<x-ui.status tone="live" data-time-tracker-running>Timer running</x-ui.status>
<x-ui.button variant="secondary" size="sm" class="ml-auto" data-time-tracker-stop :data-entry-id="$entryId">Stop</x-ui.button>
