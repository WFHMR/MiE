@props([
    'current' => 0,
    'total' => 100,
    'status' => 'processing',
    'showPercentage' => true,
])

@php
    $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0;
    
    $statusClasses = [
        'processing' => 'bg-blue-500',
        'success' => 'bg-green-500',
        'error' => 'bg-red-500',
        'warning' => 'bg-yellow-500',
    ];
    
    $barColor = $statusClasses[$status] ?? 'bg-blue-500';
@endphp

<div class="progress-bar-container">
    <div class="flex justify-between mb-2">
        <span class="text-sm font-medium text-gray-700">
            {{ $slot }}
        </span>
        
        @if($showPercentage)
            <span class="text-sm font-medium text-gray-700">
                {{ $percentage }}%
            </span>
        @endif
    </div>
    
    <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
        <div 
            class="h-full {{ $barColor }} transition-all duration-300 ease-out rounded-full flex items-center justify-end px-2"
            style="width: {{ $percentage }}%"
        >
            @if($percentage > 10)
                <span class="text-xs text-white font-semibold">
                    {{ $current }} / {{ $total }}
                </span>
            @endif
        </div>
    </div>
    
    @if($percentage < 10 && $percentage > 0)
        <div class="text-xs text-gray-600 mt-1">
            {{ $current }} / {{ $total }}
        </div>
    @endif
</div>

<style>
.progress-bar-container {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>
