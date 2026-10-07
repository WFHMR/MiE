@inject('menuService', 'App\Services\MenuService')
<div id="dashboard-header" class="w-full flex justify-between p-4 pb-2">
    <div class="flex items-center gap-3">
        <div>
            <div @click="toggleSideMenu()" class="rounded-xl h-10 w-10 cursor-pointer font-bold text-2xl justify-center items-center flex border ns-toggle-button transition-all duration-200 hover:scale-105">
                <i class="las la-bars"></i>
            </div>
        </div>
        
        {{--
            Branch selector.

            This was written with Alpine directives — x-data, x-show, x-text, @click.away —
            but Alpine is not part of this build, so every one of them was inert: the button
            rendered and swallowed clicks, and the dropdown kept the inline display:none it
            was given. The header sits inside the nsDashboardHeader Vue app, which is what
            drives the two buttons either side of it, so the state lives there now.

            Nothing here is reactive on purpose. Switching branch reloads the page, so the
            current branch name and the tick can be rendered by Blade from the session and
            cannot drift out of step with what the server actually selected.
        --}}
        @php
            // Only the branches this user may work in. A cashier belongs to one branch
            // and must not be offered another, or they read another branch's stock.
            $branches = \App\Models\Branch::selectableBy( auth()->user() )
                ->orderBy('is_main', 'desc')->orderBy('name')->get();
            $currentBranchId = session('selected_branch_id');
        @endphp
        <div class="relative">
            <div @click="toggleBranchMenu()" class="flex items-center gap-2 px-4 py-2 rounded-xl border cursor-pointer hover:shadow-md transition-all duration-200">
                <i class="las la-building text-lg"></i>
                <span class="font-medium text-sm hidden md:inline">{{ session( 'selected_branch_name', __( 'สาขาหลัก' ) ) }}</span>
                <i class="las text-sm" :class="branchMenuToggled ? 'la-angle-up' : 'la-angle-down'"></i>
            </div>

            {{--
                v-cloak keeps this hidden until Vue has compiled, the same way the account menu
                below does.

                The list has to be a ul carrying ns-vertical-menu, because the themes style that
                class as "ul.ns-vertical-menu". A div with the same class matches nothing, which
                left the panel with no background at all and the dashboard widgets reading
                straight through it. Going through the same element the account menu uses also
                picks up the font colour and the hover highlight for every theme, instead of
                hardcoding bg-white and a dark mode override that the phosphor theme never sees.
            --}}
            <div v-cloak v-if="branchMenuToggled" class="absolute left-0 mt-2 w-64 rounded-xl shadow-xl z-50 overflow-hidden">
                <ul class="w-full ns-vertical-menu">
                    {{-- A caption, not a choice. pointer-events-none stops it taking the li hover highlight. --}}
                    <li class="px-4 py-2 text-xs font-semibold uppercase opacity-60 pointer-events-none">
                        {{ __( 'เลือกสาขา' ) }}
                    </li>
                    @foreach($branches as $branch)
                    <li @click="switchBranch({{ $branch->id }})" class="cursor-pointer transition-colors duration-150">
                        <div class="flex items-center justify-between px-4 py-2 {{ $currentBranchId == $branch->id ? 'font-bold' : '' }}">
                            <div class="flex items-center gap-2">
                                <i class="las la-check {{ $currentBranchId == $branch->id ? '' : 'invisible' }}"></i>
                                <span class="text-sm">{{ $branch->name }}</span>
                                @if($branch->is_main)
                                    <span class="text-xs px-2 py-0.5 rounded border">
                                        {{ __( 'สำนักงานใหญ่' ) }}
                                    </span>
                                @endif
                            </div>
                            <span class="text-xs opacity-60">{{ $branch->code }}</span>
                        </div>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    <div class="top-tools-side flex items-center -mx-2 gap-2">
        <div clss="px-2">
            <ns-notifications></ns-notifications>
        </div>
        <div class="px-2">
            <div @click="toggleMenu()" :class="menuToggled ? 'toggled shadow-lg rounded-t-xl' : 'untoggled rounded-xl'" class="
                ns-avatar
                w-32 md:w-56 flex flex-col border py-2 justify-center hover:border-opacity-0 cursor-pointer hover:shadow-lg transition-all duration-200">
                <ns-avatar 
                    display-name="{{ Auth::user()->username }}"
                    url="{{ Auth::user()->attribute ? Auth::user()->attribute->avatar_link : asset( 'images/user.png' ) }}"></ns-avatar>
            </div>
            <div class="relative">
                <div v-cloak class="w-32 md:w-56 shadow-xl flex z-10 absolute -mb-2 rounded-b-xl overflow-hidden" v-if="menuToggled">
                    <ul class="w-full ns-vertical-menu">
                        @foreach( $menuService->getAccountMenus() as $identifier => $menu )
                        <li><a class="block px-3 py-2 transition-colors duration-150" href="{{ $menu['href'] }}"><i class="las text-lg mr-2 {{ $menu['icon'] }}"></i> {{ $menu['label'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@include( 'common.dashboard-ad' )
