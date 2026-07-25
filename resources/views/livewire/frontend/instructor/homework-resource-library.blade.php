<div>
    @if (session('success'))
        <div class="mb-4 rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    <div class="mb-6 flex flex-wrap items-center gap-3">
        <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search resources…"
               class="rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40" />

        <select wire:model.live="statusFilter" class="rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
            <option value="active">Active</option>
            <option value="archived">Archived</option>
            <option value="all">All</option>
        </select>

        <div class="flex-1"></div>

        @if (! $showCreateForm)
            <button wire:click="openCreateForm"
                    class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                New Resource
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <x-account.card :title="'New Resource'">
            <div class="grid gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Title</label>
                    <input type="text" wire:model="createTitle"
                           class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40" />
                    @error('createTitle')<p class="text-xs text-rose-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="block text-xs text-slate-400 mb-1">Description (optional)</label>
                    <textarea wire:model="createDescription" rows="3"
                              class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40"></textarea>
                    @error('createDescription')<p class="text-xs text-rose-400 mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Subject (optional)</label>
                        <select wire:model="createSubjectId" class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                            <option value="">No subject</option>
                            @foreach($subjectOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-400 mb-1">Level (optional)</label>
                        <select wire:model="createAcademicLevelId" class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                            <option value="">No level</option>
                            @foreach($academicLevelOptions as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @error('createSubjectId')<p class="text-xs text-rose-400 mt-1">{{ $message }}</p>@enderror

                <div class="flex items-center gap-2">
                    <button wire:click="createResource" class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                        Create
                    </button>
                    <button wire:click="cancelCreate" class="px-4 py-2 rounded-xl text-xs font-medium text-slate-400 hover:text-white transition-all">
                        Cancel
                    </button>
                </div>
            </div>
        </x-account.card>
    @endif

    <div class="mt-6">
        <x-account.card>
            @forelse($resources as $resource)
                <div wire:key="resource-{{ $resource->id }}" class="py-4 {{ !$loop->last ? 'border-b border-white/[0.05]' : '' }}">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <p class="text-sm font-medium text-white truncate">{{ $resource->title }}</p>
                                <x-ui.badge :color="$resource->status->color()">{{ $resource->status->label() }}</x-ui.badge>
                            </div>
                            <p class="text-xs text-slate-400">
                                {{ $resource->subject?->name ?? 'No subject' }} &middot; {{ $resource->academicLevel?->name ?? 'No level' }}
                                &middot; {{ $resource->versions->count() }} {{ \Illuminate\Support\Str::plural('version', $resource->versions->count()) }}
                            </p>
                            @if($resource->description)
                                <p class="text-xs text-slate-500 mt-1">{{ $resource->description }}</p>
                            @endif
                        </div>

                        <div class="flex-shrink-0 flex items-center gap-2">
                            <button wire:click="toggleHistory('{{ $resource->id }}')" class="text-xs text-indigo-300 underline">
                                {{ $expandedResourceId === $resource->id ? 'Hide versions' : 'Versions' }}
                            </button>
                            @if($resource->status->value === 'active')
                                <button wire:click="archiveResource('{{ $resource->id }}')" wire:confirm="Archive this resource? It can no longer be attached to new homework."
                                        class="text-xs text-rose-400 hover:text-rose-300">
                                    Archive
                                </button>
                            @endif
                        </div>
                    </div>

                    @if($expandedResourceId === $resource->id)
                        <div class="mt-3 pl-0">
                            @foreach($resource->versions as $version)
                                <div class="flex items-center gap-2 text-xs text-slate-300 mb-1.5" wire:key="version-{{ $version->id }}">
                                    <span class="font-semibold">v{{ $version->version_number }}</span>
                                    @if($version->getFirstMedia('file'))
                                        <a href="{{ route('dashboard.homework.resources.download', $version->getFirstMedia('file')) }}" class="underline text-indigo-300">
                                            {{ $version->getFirstMedia('file')->file_name }} ({{ $version->getFirstMedia('file')->human_readable_size }})
                                        </a>
                                    @endif
                                    <span class="text-slate-500">{{ $version->published_at->format('M j, Y g:i A') }}</span>

                                    @if($resource->status->value === 'active')
                                        <button wire:click="startAttach('{{ $version->id }}')" class="text-indigo-300 underline">Attach to homework</button>
                                    @endif
                                </div>

                                @if($attachingVersionId === $version->id)
                                    <div class="mb-3 rounded-xl bg-white/[0.03] p-3">
                                        <select wire:model="attachAssignmentId" class="w-full rounded-xl bg-white/[0.04] border border-white/[0.08] text-sm text-slate-200 px-3 py-2 focus:outline-none focus:border-indigo-500/40">
                                            <option value="">Choose an assignment…</option>
                                            @foreach($assignableAssignments as $assignmentId => $label)
                                                <option value="{{ $assignmentId }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('attachAssignmentId')<p class="text-xs text-rose-400 mt-1">{{ $message }}</p>@enderror

                                        <div class="flex items-center gap-2 mt-2">
                                            <button wire:click="attachToAssignment" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                                                Attach
                                            </button>
                                            <button wire:click="cancelAttach" class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white transition-all">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            @endforeach

                            @if($resource->status->value === 'active')
                                @if($publishingResourceId === $resource->id)
                                    <div class="mt-2">
                                        <input type="file" wire:model="newVersionFile" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-xs text-slate-300">
                                        @error('newVersionFile')<p class="text-xs text-rose-400 mt-1">{{ $message }}</p>@enderror
                                        <div class="flex items-center gap-2 mt-2">
                                            <button wire:click="publishVersion('{{ $resource->id }}')" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 transition-all">
                                                Publish
                                            </button>
                                            <button wire:click="cancelPublish" class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white transition-all">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                @else
                                    <button wire:click="startPublish('{{ $resource->id }}')" class="mt-1 text-xs text-indigo-300 underline">
                                        + Publish new version
                                    </button>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <x-ui.empty-state title="No resources yet" description="Create a reusable teaching resource to attach it to homework across multiple students and lessons." />
            @endforelse

            @if($resources->hasPages())
                <div class="mt-6 pt-4 border-t border-white/[0.04]">
                    {{ $resources->links() }}
                </div>
            @endif
        </x-account.card>
    </div>
</div>
