@php
    use App\Helpers\MenuHelper;
    $workspace = $workspace ?? auth()->user()?->tenant;
    $teamMembers = $workspace?->users()->orderBy('name')->get() ?? collect();
    $menuGroups = MenuHelper::getMenuGroups();
    $currentPath = request()->path();
@endphp

<aside id="sidebar"
    class="fixed top-0 mt-0 flex h-screen flex-col px-5 start-0 border border-gray-200/80 bg-white/90 text-gray-900 shadow-[0_0_0_1px_rgba(16,24,40,0.02),0_12px_32px_-18px_rgba(15,28,63,0.35)] backdrop-blur-sm transition-all duration-300 ease-in-out z-99999 ltr:border-r ltr:rounded-r-3xl rtl:border-l rtl:rounded-l-3xl dark:border-gray-800 dark:bg-gray-900/90 dark:text-white/90 w-[90px] [.sidebar-expanded_&]:min-w-[290px]"
    x-data="{
        openSubmenus: {},
        init() {
            this.initializeActiveMenus();
        },
        initializeActiveMenus() {
            const currentPath = '{{ $currentPath }}';
            @foreach ($menuGroups as $groupIndex => $menuGroup)
                @foreach ($menuGroup['items'] as $itemIndex => $item)
                    @if (isset($item['subItems']))
                        @foreach ($item['subItems'] as $subItem)
                            if (currentPath === '{{ ltrim($subItem['path'], '/') }}' || window.location.pathname === '{{ $subItem['path'] }}') {
                                this.openSubmenus['{{ $groupIndex }}-{{ $itemIndex }}'] = true;
                            }
                        @endforeach
                    @endif
                @endforeach
            @endforeach
        },
        toggleSubmenu(groupIndex, itemIndex) {
            const key = groupIndex + '-' + itemIndex;
            const newState = !this.openSubmenus[key];
            if (newState) {
                this.openSubmenus = {};
            }
            this.openSubmenus[key] = newState;
        },
        isSubmenuOpen(groupIndex, itemIndex) {
            return this.openSubmenus[groupIndex + '-' + itemIndex] || false;
        },
        isActive(path) {
            return window.location.pathname === path || '{{ $currentPath }}' === path.replace(/^\//, '');
        }
    }"
    :class="{
        'translate-x-0': $store.sidebar.isMobileOpen,
        'max-xl:-translate-x-full max-xl:rtl:translate-x-full': !$store.sidebar.isMobileOpen
    }"
    @mouseenter="if (!$store.sidebar.isExpanded) $store.sidebar.setHovered(true)"
    @mouseleave="$store.sidebar.setHovered(false)">

    <div class="flex items-center gap-2 pb-6 pt-7" :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'justify-center' : 'justify-start'">
        <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-3">
            <img src="{{ asset('images/logo/logo.png') }}" alt="Scrutium" class="h-9 w-9 shrink-0 rounded-xl border border-gray-200 bg-white object-cover shadow-sm" />
            <span x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen" class="truncate text-lg font-semibold tracking-[-0.02em] text-gray-800 dark:text-white/90">Scrutium</span>
        </a>
    </div>

    @if ($workspace)
        <div class="relative mb-6 hidden rounded-2xl border border-gray-200/80 bg-gradient-to-br from-gray-50 via-white to-brand-25/40 px-3 py-2.5 shadow-sm dark:border-gray-800 dark:from-white/[0.02] dark:via-gray-900 dark:to-brand-500/5 [.sidebar-expanded_&]:block"
            x-data="{ workspaceMenuOpen: false }"
            @click.outside="workspaceMenuOpen = false"
            @keydown.escape.window="workspaceMenuOpen = false">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-[0.18em] text-gray-400">Workspace</p>
                    <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $workspace->name }}</p>
                </div>
                <button type="button"
                    class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-gray-100 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
                    aria-label="Open workspace team menu"
                    aria-haspopup="true"
                    :aria-expanded="workspaceMenuOpen.toString()"
                    @click="workspaceMenuOpen = !workspaceMenuOpen">
                    <svg class="h-[18px] w-[18px] text-blue-light-600 dark:text-blue-light-300" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M11.98 2.1c1.05 5.8 2.12 6.87 7.92 7.92-5.8 1.05-6.87 2.12-7.92 7.92-1.05-5.8-2.12-6.87-7.92-7.92 5.8-1.05 6.87-2.12 7.92-7.92Z" />
                        <path d="M19.1 15.1c.43 2.36.87 2.8 3.23 3.23-2.36.43-2.8.87-3.23 3.23-.43-2.36-.87-2.8-3.23-3.23 2.36-.43 2.8-.87 3.23-3.23Z" />
                        <path d="M5 2.5c.3 1.64.61 1.95 2.25 2.25C5.61 5.05 5.3 5.36 5 7 4.7 5.36 4.39 5.05 2.75 4.75 4.39 4.45 4.7 4.14 5 2.5Z" />
                    </svg>
                </button>
                @if (auth()->user()?->canManageWorkspace())
                    <button type="button"
                        class="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-gray-100 text-gray-500 transition-colors hover:bg-gray-200 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10 dark:hover:text-white"
                        aria-label="{{ __('Open workspace settings') }}"
                        title="{{ __('Workspace settings') }}"
                        @click="$dispatch('workspace-settings-open', { anchor: $el })">
                        <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M10 2.25a2.1 2.1 0 0 1 2.05 1.65l.13.57c.38.13.74.34 1.06.61l.56-.19a2.1 2.1 0 0 1 2.52 1.02 2.1 2.1 0 0 1-.5 2.67l-.44.38c.07.41.07.82 0 1.23l.44.38a2.1 2.1 0 0 1 .5 2.67 2.1 2.1 0 0 1-2.52 1.02l-.56-.19c-.32.27-.68.48-1.06.61l-.13.57A2.1 2.1 0 0 1 10 16.9a2.1 2.1 0 0 1-2.05-1.65l-.13-.57a4.1 4.1 0 0 1-1.06-.61l-.56.19a2.1 2.1 0 0 1-2.52-1.02 2.1 2.1 0 0 1 .5-2.67l.44-.38a3.7 3.7 0 0 1 0-1.23l-.44-.38a2.1 2.1 0 0 1-.5-2.67 2.1 2.1 0 0 1 2.52-1.02l.56.19c.32-.27.68-.48 1.06-.61l.13-.57A2.1 2.1 0 0 1 10 2.25Z" stroke="currentColor" stroke-width="1.35" stroke-linejoin="round" />
                            <circle cx="10" cy="9.55" r="2.2" stroke="currentColor" stroke-width="1.35" />
                        </svg>
                    </button>
                @endif
            </div>

            <div x-cloak x-show="workspaceMenuOpen" x-transition.origin.top.right
                class="absolute start-0 end-0 top-full z-50 mt-2 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900"
                role="menu">
                @if (auth()->user()?->canManageWorkspace())
                    <div class="space-y-1 border-b border-gray-100 p-2 dark:border-gray-800">
                        <a href="{{ route('settings') }}" role="menuitem"
                            class="block rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">Create Team</a>
                        <a href="{{ route('settings') }}" role="menuitem"
                            class="block rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">Invite</a>
                    </div>
                @endif
                <div class="max-h-56 overflow-y-auto p-2">
                    <p class="px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Team members</p>
                    @forelse ($teamMembers as $member)
                        <div class="flex min-w-0 items-center justify-between gap-2 rounded-md px-3 py-2 text-sm">
                            <span class="truncate text-gray-700 dark:text-gray-200">{{ $member->name }}</span>
                            @if ($member->is(auth()->user()))
                                <span class="shrink-0 text-xs text-gray-400">You</span>
                            @else
                                <span class="shrink-0 text-xs text-gray-400">{{ $member->role()->label() }}</span>
                            @endif
                        </div>
                    @empty
                        <p class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">No team members yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <div class="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar flex-1">
        <nav class="mb-6">
            <div class="flex flex-col gap-4">
                @foreach ($menuGroups as $groupIndex => $menuGroup)
                    <div>
                        @if (!empty($menuGroup['title']))
                            <h2 class="mb-3 flex text-[10px] uppercase leading-[20px] tracking-[0.18em] text-gray-400"
                                :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'lg:justify-center' : 'justify-start'">
                                <template x-if="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen">
                                    <span>{{ __($menuGroup['title']) }}</span>
                                </template>
                                <template x-if="!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M4.25 12C4.25 11.5858 4.58579 11.25 5 11.25H19C19.4142 11.25 19.75 11.5858 19.75 12C19.75 12.4142 19.4142 12.75 19 12.75H5C4.58579 12.75 4.25 12.4142 4.25 12Z" fill="currentColor"/>
                                    </svg>
                                </template>
                            </h2>
                        @endif
                        <ul class="flex flex-col gap-1">
                            @foreach ($menuGroup['items'] as $itemIndex => $item)
                                <li>
                                    <a href="{{ $item['path'] }}" class="menu-item group"
                                        :class="[
                                            isActive('{{ $item['path'] }}') ? 'menu-item-active' : 'menu-item-inactive',
                                            (!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'xl:justify-center' : 'xl:justify-start'
                                        ]">
                                        <span :class="isActive('{{ $item['path'] }}') ? 'menu-item-icon-active' : 'menu-item-icon-inactive'">
                                            {!! MenuHelper::getIconSvg($item['icon']) !!}
                                        </span>
                                        <span x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen" class="menu-item-text">
                                            {{ __($item['name']) }}
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </nav>
    </div>

    <div class="mt-auto border-t border-gray-200 py-5 dark:border-gray-800">
        <div class="rounded-2xl border border-gray-200/80 bg-gradient-to-br from-gray-50 via-white to-brand-25/40 p-3 shadow-sm dark:border-gray-800 dark:from-white/[0.02] dark:via-gray-900 dark:to-brand-500/5"
            :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'flex justify-center' : ''">
            <div class="flex items-center gap-2" :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'justify-center' : 'justify-start'">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-100 text-[10px] font-bold text-brand-700 shadow-sm ring-1 ring-brand-100 dark:bg-brand-500/15 dark:text-brand-300 dark:ring-brand-400/20">gbf</span>
                <div x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen" class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-300">✦ DISCOVER</p>
                </div>
            </div>

            <div x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen" class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                <a href="{{ route('campaign-tools') }}" @class([
                    'flex items-center gap-2 rounded-lg px-2.5 py-2 font-medium transition-colors',
                    'bg-brand-50 text-brand-700 ring-1 ring-brand-100 dark:bg-brand-500/15 dark:text-brand-300 dark:ring-brand-400/20' => request()->routeIs('campaign-tools'),
                    'text-gray-700 hover:bg-white hover:text-brand-700 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-brand-300' => ! request()->routeIs('campaign-tools'),
                ]) @if(request()->routeIs('campaign-tools')) aria-current="page" @endif>
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5 11.8 8l5.7 2-5.7 2L10 17.5 8.2 12 2.5 10l5.7-2L10 2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="m15.5 2 .7 2.3 2.3.7-2.3.7-.7 2.3-.7-2.3-2.3-.7 2.3-.7.7-2.3Z" fill="currentColor"/></svg>
                    <span>Campaign tools</span>
                </a>
                <a href="{{ route('partnerintegrations') }}" class="block font-medium text-gray-800 transition-colors hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-300">{{ __('Partner integrations') }}</a>
                <p class="font-medium text-gray-800 dark:text-white/90">Featured services</p>
                <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-brand-600 transition-colors hover:text-brand-700 dark:text-brand-300 dark:hover:text-brand-200">
                    Explore
                    <span aria-hidden="true">→</span>
                </button>
            </div>
        </div>
    </div>
</aside>
