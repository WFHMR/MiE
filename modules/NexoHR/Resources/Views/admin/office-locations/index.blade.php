@extends('NexoHR::layout.hr-dashboard')

@section('layout.dashboard.body')
<div class="px-4 py-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold">จัดการพื้นที่สำนักงาน</h1>
            <p class="text-gray-600 mt-1">จัดการพื้นที่สำนักงานพร้อมพิกัด GPS สำหรับตรวจสอบการลงเวลา</p>
        </div>
        <a href="{{ route('nexohr.admin.office-locations.create') }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
            <i class="las la-plus"></i> เพิ่มพื้นที่สำนักงานใหม่
        </a>
    </div>

    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">{{ session('success') }}</span>
        </div>
    @endif

    @if($officeLocations->count() > 0)
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ชื่อสำนักงาน</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">พิกัด GPS</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">รัศมี (เมตร)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">สถานะ</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($officeLocations as $location)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ $location->name }}</div>
                                @if($location->description)
                                    <div class="text-sm text-gray-500">{{ Str::limit($location->description, 50) }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ number_format($location->latitude, 6) }}, {{ number_format($location->longitude, 6) }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm text-gray-900">{{ $location->radius }} ม.</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($location->is_active)
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                        ใช้งาน
                                    </span>
                                @else
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                        ปิดใช้งาน
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium space-x-2">
                                <a href="{{ route('nexohr.admin.office-locations.show', $location) }}" class="text-blue-600 hover:text-blue-900">
                                    <i class="las la-eye"></i>
                                </a>
                                <a href="{{ route('nexohr.admin.office-locations.edit', $location) }}" class="text-indigo-600 hover:text-indigo-900">
                                    <i class="las la-edit"></i>
                                </a>
                                <form action="{{ route('nexohr.admin.office-locations.destroy', $location) }}" method="POST" class="inline-block" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบพื้นที่นี้?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:text-red-900">
                                        <i class="las la-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="bg-white rounded-lg shadow p-12 text-center">
            <div class="text-gray-400 mb-4">
                <i class="las la-map-marked-alt text-6xl"></i>
            </div>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">ยังไม่มีพื้นที่สำนักงาน</h3>
            <p class="text-gray-500 mb-4">เริ่มต้นด้วยการเพิ่มพื้นที่สำนักงานแรกของคุณ</p>
            <a href="{{ route('nexohr.admin.office-locations.create') }}" class="inline-block px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                <i class="las la-plus"></i> เพิ่มพื้นที่สำนักงานใหม่
            </a>
        </div>
    @endif
</div>
@endsection
