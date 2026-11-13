<div>
    @if($show)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 sm:items-center sm:p-4"
             x-data x-on:keydown.escape.window="$wire.close()">
            <form wire:submit="save"
                  class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 shadow-lg sm:max-w-2xl sm:rounded-2xl"
                  role="dialog" aria-modal="true" aria-labelledby="form-title">
                <div class="flex items-center justify-between">
                    <h3 id="form-title" class="font-display text-lg font-semibold text-gray-900">
                        {{ $meetingId ? 'Edit meeting' : 'New meeting' }}
                    </h3>
                    <button type="button" wire:click="close" aria-label="Close"
                            class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="mt-4 space-y-4">
                    <div>
                        <label for="mf-title" class="block text-sm font-medium text-gray-700">Title</label>
                        <input id="mf-title" type="text" wire:model="title" maxlength="160" autofocus
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                        @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label for="mf-date" class="block text-sm font-medium text-gray-700">Date</label>
                            <input id="mf-date" type="date" wire:model.live="date"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                            @error('date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="mf-start" class="block text-sm font-medium text-gray-700">Starts</label>
                            <input id="mf-start" type="time" wire:model.live="startTime"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                            @error('startTime') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="mf-end" class="block text-sm font-medium text-gray-700">Ends</label>
                            <input id="mf-end" type="time" wire:model.live="endTime"
                                   class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                            @error('endTime') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p class="-mt-2 text-xs text-gray-500">Times are in {{ $tz }}.</p>

                    <div>
                        <label for="mf-video" class="block text-sm font-medium text-gray-700">Video link <span class="font-normal text-gray-500">(optional)</span></label>
                        <input id="mf-video" type="url" wire:model="videoUrl" maxlength="500" placeholder="https://meet.google.com/…"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                        @error('videoUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-500">
                            Create a room, then paste its link here:
                            <a href="https://meet.google.com/new" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-text underline">Create a Google Meet</a>
                            ·
                            <a href="https://teams.microsoft.com/l/meeting/new" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-text underline">Create a Teams meeting</a>
                            (both open in a new tab).
                        </p>
                    </div>

                    @if($googleReady)
                        <label class="flex items-start gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-800">
                            <input type="checkbox" wire:model="createInGoogle" class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand">
                            <span>
                                <span class="font-medium">Create it in my Google Calendar with a Google Meet link</span>
                                <span class="block text-xs text-gray-500">Google sends the invitations and holds the room. If you pasted a video link above, that link is used instead of a new Meet.</span>
                            </span>
                        </label>
                    @elseif($googleManaged)
                        <p class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">This meeting lives in your Google Calendar. Changes you save here are sent to Google, which updates your guests.</p>
                    @endif

                    <div>
                        <label for="mf-location" class="block text-sm font-medium text-gray-700">Place <span class="font-normal text-gray-500">(optional)</span></label>
                        <input id="mf-location" type="text" wire:model="location" maxlength="255" placeholder="Office, address or phone number"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                        @error('location') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="mf-desc" class="block text-sm font-medium text-gray-700">Notes <span class="font-normal text-gray-500">(optional)</span></label>
                        <textarea id="mf-desc" wire:model="description" rows="3" maxlength="5000"
                                  class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand"></textarea>
                        @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if($canManageAll && ! $meetingId)
                        <div>
                            <label for="mf-organizer" class="block text-sm font-medium text-gray-700">Organised by</label>
                            <select id="mf-organizer" wire:model.live="organizerId"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                                <option value="{{ auth()->id() }}">Me</option>
                                @foreach($staff as $member)
                                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-gray-500">You can schedule on behalf of a colleague. They get the invitation as the organiser.</p>
                        </div>
                    @endif

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">Invite colleagues</legend>
                        <div class="mt-1 grid max-h-40 gap-1 overflow-y-auto rounded-lg border border-gray-200 p-2 sm:grid-cols-2">
                            @forelse($staff as $member)
                                <label class="flex items-center gap-2 rounded px-1.5 py-1 text-sm text-gray-800 hover:bg-gray-50">
                                    <input type="checkbox" wire:model.live="staffIds" value="{{ $member->id }}" class="rounded border-gray-300 text-brand focus:ring-brand">
                                    <span class="truncate">{{ $member->name }}</span>
                                </label>
                            @empty
                                <p class="text-sm text-gray-500">No other active colleagues.</p>
                            @endforelse
                        </div>
                    </fieldset>

                    @if($conflicts)
                        <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700" role="status">
                            <p class="font-medium">Heads up — these people already have something at that time:</p>
                            <ul class="mt-1 list-disc pl-5">
                                @foreach($conflicts as $c)
                                    <li>{{ $c['name'] }} ({{ $c['title'] }})</li>
                                @endforeach
                            </ul>
                            <p class="mt-1 text-xs">You can still save; this is only a warning.</p>
                        </div>
                    @endif

                    <div>
                        <label for="mf-contact" class="block text-sm font-medium text-gray-700">Invite contacts</label>
                        <input id="mf-contact" type="search" wire:model.live.debounce.300ms="contactSearch" placeholder="Search a contact by name or email" autocomplete="off"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand focus:ring-brand">
                        @if($contactResults->isNotEmpty())
                            <ul class="mt-1 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white text-sm">
                                @foreach($contactResults as $c)
                                    <li>
                                        <button type="button" wire:click="pickContact({{ $c->id }})" class="block w-full px-3 py-1.5 text-left hover:bg-gray-50">
                                            {{ $c->name }} <span class="text-gray-500">{{ $c->email }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if($pickedContacts->isNotEmpty())
                            <ul class="mt-2 flex flex-wrap gap-1.5">
                                @foreach($pickedContacts as $c)
                                    <li class="inline-flex items-center gap-1 rounded-full bg-gray-100 py-0.5 pl-2.5 pr-1 text-xs text-gray-800">
                                        {{ $c->name ?: $c->email }}
                                        <button type="button" wire:click="unpickContact({{ $c->id }})" aria-label="Remove {{ $c->name ?: $c->email }}" class="rounded-full p-0.5 hover:bg-gray-200">×</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <label for="mf-ext" class="block text-sm font-medium text-gray-700">Other email addresses <span class="font-normal text-gray-500">(optional)</span></label>
                        {{-- Chips are Alpine-managed (resources/js/email-chips.js) and mirrored into externalEmails, so
                             Livewire must not morph this block while the person is typing. --}}
                        <div wire:ignore x-data="bqEmailChips()" x-on:click="$refs.draft.focus()"
                             class="mt-1 flex min-h-[2.5rem] flex-wrap items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-2 py-1.5 shadow-sm focus-within:border-brand focus-within:ring-1 focus-within:ring-brand">
                            <template x-for="(chip, index) in chips" x-bind:key="chip">
                                <span class="inline-flex max-w-full items-center gap-1 rounded-full bg-gray-100 py-0.5 pl-2.5 pr-1 text-xs text-gray-800">
                                    <span class="truncate" x-text="chip"></span>
                                    <button type="button" x-on:click.stop="remove(index)" x-bind:aria-label="'Remove ' + chip"
                                            class="rounded-full px-1 text-gray-500 hover:bg-gray-200 hover:text-gray-800">×</button>
                                </span>
                            </template>
                            <input id="mf-ext" type="text" x-ref="draft" x-model="draft"
                                   x-on:keydown="keydown($event)" x-on:paste="paste($event)" x-on:blur="blur()"
                                   autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="email"
                                   placeholder="Type or paste addresses, then press Enter"
                                   class="min-w-[12rem] flex-1 border-0 bg-transparent p-0.5 text-sm focus:ring-0">
                            <p x-show="error" x-text="error" x-cloak class="basis-full text-xs text-red-600" role="alert"></p>
                        </div>
                        @error('externalEmails') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-gray-500">Add as many as you need (up to {{ \App\Models\Meeting::MAX_ATTENDEES }} people in total). Separate with Enter, comma, space or paste a whole list. Anyone can be invited, even without an account; they get an invitation with a calendar file and Accept / Decline buttons.</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="close" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-hover disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
                        {{ $meetingId ? 'Save changes' : 'Schedule and send invitations' }}
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
