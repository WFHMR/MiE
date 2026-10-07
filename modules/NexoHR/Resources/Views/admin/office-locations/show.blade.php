@extends('NexoHR::layout.hr-dashboard')

@section('layout.dashboard.body')
<div class="h-full flex-auto flex flex-col">
    <div class="px-4 flex-auto flex flex-col" id="dashboard-content">
        <div class="page-inner-header mb-4">
            <h3 class="text-3xl text-gray-800 font-bold">{{ __('รายละเอียดพื้นที่สำนักงาน') }}</h3>
            <p class="text-gray-600">{{ __('ข้อมูลสถานที่สำนักงานสำหรับตรวจสอบการเข้าออกของพนักงาน') }}</p>
        </div>

        <div class="flex-auto overflow-y-auto">
            <div class="bg-white rounded shadow p-4">
                <div class="flex justify-end mb-4 space-x-2">
                    <a href="{{ route('nexohr.admin.office-locations.edit', $location->id) }}" class="ns-button info">
                        <i class="las la-edit"></i> {{ __('แก้ไข') }}
                    </a>
                    <a href="{{ route('nexohr.admin.office-locations.index') }}" class="ns-button">
                        <i class="las la-arrow-left"></i> {{ __('กลับ') }}
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Left Column: Location Details -->
                    <div>
                        <h4 class="font-bold text-lg mb-4">{{ __('ข้อมูลสถานที่') }}</h4>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ชื่อสถานที่') }}</label>
                            <div class="text-gray-900">{{ $location->name }}</div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ที่อยู่') }}</label>
                            <div class="text-gray-900">{{ $location->address ?? '-' }}</div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ละติจูด') }}</label>
                            <div class="text-gray-900 font-mono">{{ $location->latitude }}</div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ลองจิจูด') }}</label>
                            <div class="text-gray-900 font-mono">{{ $location->longitude }}</div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('รัศมี (เมตร)') }}</label>
                            <div class="text-gray-900">{{ $location->radius }} {{ __('เมตร') }}</div>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('สถานะ') }}</label>
                            <div>
                                @if($location->is_active)
                                    <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-sm">{{ __('เปิดใช้งาน') }}</span>
                                @else
                                    <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-sm">{{ __('ปิดใช้งาน') }}</span>
                                @endif
                            </div>
                        </div>

                        @if($location->description)
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('คำอธิบาย') }}</label>
                            <div class="text-gray-900">{{ $location->description }}</div>
                        </div>
                        @endif
                    </div>

                    <!-- Right Column: Map -->
                    <div>
                        <h4 class="font-bold text-lg mb-4">{{ __('แผนที่') }}</h4>
                        <div id="map" class="w-full h-96 bg-gray-200 rounded"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const lat = {{ $location->latitude }};
    const lng = {{ $location->longitude }};
    const radius = {{ $location->radius }};
    const locationName = @json($location->name);

    // Initialize Leaflet map
    const map = L.map('map').setView([lat, lng], 16);

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    // Add marker for office location
    const marker = L.marker([lat, lng]).addTo(map);
    marker.bindPopup(`<b>${locationName}</b><br>ละติจูด: ${lat}<br>ลองจิจูด: ${lng}`).openPopup();

    // Add circle showing coverage radius
    const circle = L.circle([lat, lng], {
        color: 'blue',
        fillColor: '#3b82f6',
        fillOpacity: 0.2,
        radius: radius
    }).addTo(map);

    // Adjust map view to show marker and circle
    const bounds = circle.getBounds();
    map.fitBounds(bounds);
});
</script>
@endsection
