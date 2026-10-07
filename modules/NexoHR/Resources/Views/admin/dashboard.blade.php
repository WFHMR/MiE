@extends('NexoHR::layout.hr-dashboard')

@section('layout.dashboard.body')
<div class="h-full flex-auto flex flex-col">
    <div class="px-4 flex-auto flex flex-col" id="dashboard-content">
        <div class="page-inner-header mb-4">
            <h3 class="text-3xl text-gray-800 font-bold">{{ __('แดชบอร์ด HR') }}</h3>
            <p class="text-gray-600">{{ __('ภาพรวมระบบบริหารจัดการบุคคล') }}</p>
        </div>

        <div class="flex-auto overflow-y-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                <!-- Card 1: Total Employees -->
                <div class="bg-white rounded shadow p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-blue-100 rounded-full p-3">
                            <i class="las la-users text-2xl text-blue-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-600">{{ __('พนักงานทั้งหมด') }}</p>
                            <p class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Present Today -->
                <div class="bg-white rounded shadow p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-green-100 rounded-full p-3">
                            <i class="las la-check-circle text-2xl text-green-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-600">{{ __('มาทำงานวันนี้') }}</p>
                            <p class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Absent Today -->
                <div class="bg-white rounded shadow p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-red-100 rounded-full p-3">
                            <i class="las la-times-circle text-2xl text-red-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-600">{{ __('ขาดงานวันนี้') }}</p>
                            <p class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                    </div>
                </div>

                <!-- Card 4: On Leave -->
                <div class="bg-white rounded shadow p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 bg-yellow-100 rounded-full p-3">
                            <i class="las la-calendar-times text-2xl text-yellow-600"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-600">{{ __('ลางาน') }}</p>
                            <p class="text-2xl font-bold text-gray-900">0</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Recent Attendance -->
                <div class="bg-white rounded shadow p-4">
                    <h4 class="font-bold text-lg mb-4">{{ __('การเข้างานล่าสุด') }}</h4>
                    <div class="text-center text-gray-500 py-8">
                        {{ __('ยังไม่มีข้อมูล') }}
                    </div>
                </div>

                <!-- Pending Leave Requests -->
                <div class="bg-white rounded shadow p-4">
                    <h4 class="font-bold text-lg mb-4">{{ __('คำขอลาที่รอดำเนินการ') }}</h4>
                    <div class="text-center text-gray-500 py-8">
                        {{ __('ยังไม่มีข้อมูล') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
