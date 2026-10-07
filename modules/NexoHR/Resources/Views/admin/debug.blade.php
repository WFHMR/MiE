@extends('layout.dashboard')

@section('layout.dashboard.body')
<div class="h-full flex-auto flex flex-col">
    <div class="px-4 flex-auto flex flex-col" id="dashboard-content">
        <div class="page-inner-header mb-4">
            <h3 class="text-3xl text-gray-800 font-bold">{{ __('Debug - ตรวจสอบระบบ') }}</h3>
            <p class="text-gray-600">{{ __('ตรวจสอบว่า JavaScript และ Vue ทำงานถูกต้อง') }}</p>
        </div>

        <div class="flex-auto overflow-y-auto">
            <div class="bg-white rounded shadow p-6 space-y-4">
                <div>
                    <h4 class="font-bold text-lg mb-2">{{ __('ตรวจสอบ JavaScript') }}</h4>
                    <div id="js-check" class="p-3 bg-gray-100 rounded">
                        <p class="text-red-600">❌ JavaScript ยังไม่โหลด</p>
                    </div>
                </div>

                <div>
                    <h4 class="font-bold text-lg mb-2">{{ __('ตรวจสอบ Vue Instance') }}</h4>
                    <div id="vue-check" class="p-3 bg-gray-100 rounded">
                        <p class="text-red-600">❌ Vue ยังไม่โหลด</p>
                    </div>
                </div>

                <div>
                    <h4 class="font-bold text-lg mb-2">{{ __('ตรวจสอบ nsState') }}</h4>
                    <div id="state-check" class="p-3 bg-gray-100 rounded">
                        <p class="text-red-600">❌ nsState ยังไม่โหลด</p>
                    </div>
                </div>

                <div>
                    <h4 class="font-bold text-lg mb-2">{{ __('ตรวจสอบ Sidebar State') }}</h4>
                    <div id="sidebar-check" class="p-3 bg-gray-100 rounded">
                        <p class="text-red-600">❌ sidebar state ยังไม่โหลด</p>
                    </div>
                </div>

                <div>
                    <h4 class="font-bold text-lg mb-2">{{ __('ตรวจสอบ Elements') }}</h4>
                    <div id="element-check" class="p-3 bg-gray-100 rounded">
                        <p class="text-red-600">❌ Elements ยังไม่โหลด</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('layout.dashboard.footer.inject')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Check JavaScript
    document.getElementById('js-check').innerHTML = '<p class="text-green-600">✅ JavaScript ทำงานแล้ว</p>';
    
    // Check Vue
    if (typeof window.createApp !== 'undefined') {
        document.getElementById('vue-check').innerHTML = '<p class="text-green-600">✅ Vue ถูกโหลดแล้ว</p>';
    }
    
    // Check nsState
    if (typeof window.nsState !== 'undefined') {
        const state = window.nsState.state || {};
        document.getElementById('state-check').innerHTML = `
            <p class="text-green-600">✅ nsState พร้อมใช้งาน</p>
            <pre class="mt-2 text-xs">${JSON.stringify(state, null, 2)}</pre>
        `;
    }
    
    // Check sidebar state
    if (typeof window.nsState !== 'undefined' && window.nsState.state) {
        const sidebarState = window.nsState.state.sidebar || 'undefined';
        document.getElementById('sidebar-check').innerHTML = `
            <p class="text-green-600">✅ sidebar state = "${sidebarState}"</p>
        `;
    }
    
    // Check elements
    const aside = document.querySelector('#dashboard-aside');
    const overlay = document.querySelector('#dashboard-overlay');
    const asideMounted = window.nsDashboardAside ? 'mounted' : 'not mounted';
    const overlayMounted = window.nsDashboardOverlay ? 'mounted' : 'not mounted';
    
    document.getElementById('element-check').innerHTML = `
        <p class="text-green-600">✅ Elements พบแล้ว</p>
        <ul class="mt-2 text-sm space-y-1">
            <li>#dashboard-aside: ${aside ? '✅ พบ' : '❌ ไม่พบ'}</li>
            <li>#dashboard-overlay: ${overlay ? '✅ พบ' : '❌ ไม่พบ'}</li>
            <li>nsDashboardAside: ${asideMounted}</li>
            <li>nsDashboardOverlay: ${overlayMounted}</li>
        </ul>
    `;
    
    console.log('Debug Info:', {
        nsState: window.nsState,
        sidebar: window.nsState?.state?.sidebar,
        aside: aside,
        overlay: overlay,
        nsDashboardAside: window.nsDashboardAside,
        nsDashboardOverlay: window.nsDashboardOverlay
    });
});
</script>
@endpush
@endsection
