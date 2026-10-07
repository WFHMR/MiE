@extends('NexoHR::layout.hr-dashboard')

@section('layout.dashboard.body')
<div class="px-4 py-6">
    <div class="max-w-3xl mx-auto">
        <div class="mb-6">
            <h1 class="text-3xl font-bold">เพิ่มพื้นที่สำนักงานใหม่</h1>
            <p class="text-gray-600 mt-1">กรอกข้อมูลพื้นที่สำนักงานและพิกัด GPS</p>
        </div>

        <form action="{{ route('nexohr.admin.office-locations.store') }}" method="POST" class="bg-white rounded-lg shadow p-6">
            @csrf

            <div class="space-y-6">
                <!-- Name -->
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                        ชื่อสำนักงาน <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        id="name" 
                        value="{{ old('name') }}"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('name') border-red-500 @enderror"
                        placeholder="เช่น สำนักงานใหญ่"
                        required
                    >
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                        คำอธิบาย
                    </label>
                    <textarea 
                        name="description" 
                        id="description" 
                        rows="3"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="ข้อมูลเพิ่มเติมเกี่ยวกับสำนักงาน"
                    >{{ old('description') }}</textarea>
                </div>

                <!-- Latitude -->
                <div>
                    <label for="latitude" class="block text-sm font-medium text-gray-700 mb-2">
                        ละติจูด (Latitude) <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="latitude" 
                        id="latitude" 
                        step="0.000001"
                        value="{{ old('latitude') }}"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('latitude') border-red-500 @enderror"
                        placeholder="เช่น 13.736717"
                        required
                    >
                    @error('latitude')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Longitude -->
                <div>
                    <label for="longitude" class="block text-sm font-medium text-gray-700 mb-2">
                        ลองจิจูด (Longitude) <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="longitude" 
                        id="longitude" 
                        step="0.000001"
                        value="{{ old('longitude') }}"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('longitude') border-red-500 @enderror"
                        placeholder="เช่น 100.523186"
                        required
                    >
                    @error('longitude')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Radius -->
                <div>
                    <label for="radius" class="block text-sm font-medium text-gray-700 mb-2">
                        รัศมีการตรวจสอบ (เมตร) <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="radius" 
                        id="radius" 
                        value="{{ old('radius', 100) }}"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('radius') border-red-500 @enderror"
                        placeholder="100"
                        min="1"
                        required
                    >
                    @error('radius')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-sm text-gray-500">พนักงานต้องอยู่ภายในรัศมีนี้เพื่อลงเวลา</p>
                </div>

                <!-- Is Active -->
                <div>
                    <label class="flex items-center">
                        <input 
                            type="checkbox" 
                            name="is_active" 
                            value="1"
                            {{ old('is_active', true) ? 'checked' : '' }}
                            class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                        >
                        <span class="ml-2 text-sm text-gray-700">เปิดใช้งาน</span>
                    </label>
                </div>
            </div>

            <!-- Buttons -->
            <div class="flex justify-end space-x-3 mt-8 pt-6 border-t">
                <a href="{{ route('nexohr.admin.office-locations.index') }}" class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition">
                    ยกเลิก
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition">
                    <i class="las la-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
