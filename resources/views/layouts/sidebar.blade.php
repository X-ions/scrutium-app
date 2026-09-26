@php
    use App\Helpers\MenuHelper;
    $menuGroups = MenuHelper::getMenuGroups();
    $currentPath = request()->path();
    $workspace = auth()->user()?->tenant;
    $authUser = auth()->user();
@endphp

<aside id="sidebar"
    class="fixed flex flex-col mt-0 top-0 px-5 start-0 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-900 h-screen transition-all duration-300 ease-in-out z-99999 ltr:border-r rtl:border-l border-gray-200 w-[90px] [.sidebar-expanded_&]:min-w-[290px]"
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

    <div class="pt-8 pb-6 flex items-center gap-2" :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'justify-center' : 'justify-start'">
        <a href="{{ url('/') }}" class="flex items-center gap-3 min-w-0">
            <img src="{{ asset('images/logo/logo.png') }}" alt="Scrutium" class="h-9 w-9 shrink-0 rounded-xl object-cover bg-white" />
            <span x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen" class="truncate text-lg font-semibold tracking-tight text-gray-800 dark:text-white/90">Scrutium</span>
        </a>
    </div>

    @if ($workspace)
        <div class="mb-6 hidden rounded-xl bg-gray-50 px-3 py-2.5 dark:bg-white/5 [.sidebar-expanded_&]:block">
            <p class="text-[10px] uppercase tracking-wider text-gray-400">Workspace</p>
            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $workspace->name }}</p>
        </div>
    @endif

    <div class="flex flex-col overflow-y-auto duration-300 ease-linear no-scrollbar flex-1">
        <nav class="mb-6">
            <div class="flex flex-col gap-4">
                @foreach ($menuGroups as $groupIndex => $menuGroup)
                    <div>
                        <h2 class="mb-4 text-xs uppercase flex leading-[20px] text-gray-400"
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

    @if ($authUser)
        <div class="mt-auto border-t border-gray-200 py-5 dark:border-gray-800">
            <div class="flex items-center gap-3" :class="(!$store.sidebar.isExpanded && !$store.sidebar.isHovered && !$store.sidebar.isMobileOpen) ? 'justify-center' : ''">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-semibold text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">{{ $authUser->initials() }}</span>
                <div x-show="$store.sidebar.isExpanded || $store.sidebar.isHovered || $store.sidebar.isMobileOpen" class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $authUser->name }}</p>
                    <p class="truncate text-xs text-gray-400">{{ $authUser->job_title ?: $authUser->role()->name }}</p>
                </div>
            </div>
        </div>
    @endif
</aside>
