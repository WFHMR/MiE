@extends('layout.dashboard')

@section('layout.dashboard.body')
<div>
    @include('../common/dashboard-header')
    
    <div id="dashboard-content" class="px-4">
        <div class="py-4">
            <div class="rounded-lg shadow-lg bg-surface-secondary border border-box-edge overflow-hidden">
                <div class="p-6 border-b border-box-edge">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-semibold text-primary">{{ __('Import Result') }}</h3>
                            <p class="text-sm text-secondary mt-1">{{ __('Import processing completed') }}</p>
                        </div>
                        <div class="text-right">
                            @if($batch->status === 'completed')
                            <div class="inline-block px-3 py-1 rounded-full text-xs font-medium bg-success-tertiary text-success-primary border border-success-secondary">
                                <i class="las la-check-circle"></i> {{ __('Completed') }}
                            </div>
                            @elseif($batch->status === 'failed')
                            <div class="inline-block px-3 py-1 rounded-full text-xs font-medium bg-error-tertiary text-error-primary border border-error-secondary">
                                <i class="las la-times-circle"></i> {{ __('Failed') }}
                            </div>
                            @else
                            <div class="inline-block px-3 py-1 rounded-full text-xs font-medium bg-info-tertiary text-info-primary border border-info-secondary">
                                <i class="las la-info-circle"></i> {{ ucfirst($batch->status) }}
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="p-6 space-y-6">
                    @if(session('success'))
                    <div class="p-4 rounded-lg bg-success-tertiary border border-success-secondary text-success-primary">
                        <div class="flex items-center">
                            <i class="las la-check-circle text-2xl mr-2"></i>
                            <span>{{ session('success') }}</span>
                        </div>
                    </div>
                    @endif

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-list-ol text-3xl text-info-primary"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-secondary">{{ __('Total Rows') }}</p>
                                    <p class="text-2xl font-bold text-primary">{{ number_format($batch->total_rows) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-success-tertiary border border-success-secondary rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-check-circle text-3xl text-success-primary"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-success-secondary">{{ __('Successful') }}</p>
                                    <p class="text-2xl font-bold text-success-primary">{{ number_format($batch->successful_rows) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-error-tertiary border border-error-secondary rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-times-circle text-3xl text-error-primary"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-error-secondary">{{ __('Failed') }}</p>
                                    <p class="text-2xl font-bold text-error-primary">{{ number_format($batch->failed_rows) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-percentage text-3xl text-purple-500"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-secondary">{{ __('Success Rate') }}</p>
                                    <p class="text-2xl font-bold text-primary">
                                        @if($batch->total_rows > 0)
                                        {{ number_format(($batch->successful_rows / $batch->total_rows) * 100, 1) }}%
                                        @else
                                        0%
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <p class="text-xs text-secondary mb-1">{{ __('File Name') }}</p>
                            <p class="text-sm font-semibold text-primary">{{ $batch->filename }}</p>
                        </div>
                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <p class="text-xs text-secondary mb-1">{{ __('Branch') }}</p>
                            <p class="text-sm font-semibold text-primary">{{ $batch->branch->name ?? 'N/A' }}</p>
                        </div>
                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <p class="text-xs text-secondary mb-1">{{ __('Processed At') }}</p>
                            <p class="text-sm font-semibold text-primary">{{ $batch->updated_at->format('Y-m-d H:i:s') }}</p>
                        </div>
                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <p class="text-xs text-secondary mb-1">{{ __('Uploaded By') }}</p>
                            <p class="text-sm font-semibold text-primary">{{ $batch->user->username ?? 'N/A' }}</p>
                        </div>
                    </div>

                    @if($batch->failed_rows > 0 && $batch->rows->count() > 0)
                    <div class="border border-box-edge rounded-lg overflow-hidden">
                        <div class="bg-error-tertiary px-4 py-3 border-b border-error-secondary">
                            <h4 class="text-sm font-semibold text-error-primary flex items-center">
                                <i class="las la-exclamation-triangle mr-2"></i>
                                {{ __('Failed Rows Details') }}
                            </h4>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-box-edge">
                                <thead class="bg-surface-tertiary">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Row') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('BarCode') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Product') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Error Message') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-secondary divide-y divide-box-edge">
                                    @foreach($batch->rows as $row)
                                    <tr class="hover:bg-surface-tertiary transition-colors">
                                        <td class="px-4 py-3 text-sm text-secondary">{{ $row->row_number }}</td>
                                        <td class="px-4 py-3 text-sm text-primary font-mono">{{ $row->data['BarCode'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-primary">{{ $row->data['Product'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-error-primary">
                                            <div class="flex items-start">
                                                <i class="las la-exclamation-circle mt-0.5 mr-1"></i>
                                                <span>{{ $row->error_message }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endif

                    @if($batch->successful_rows > 0)
                    <div class="bg-success-tertiary border border-success-secondary rounded-lg p-4">
                        <div class="flex items-start">
                            <i class="las la-check-circle text-xl text-success-primary mt-0.5 mr-2"></i>
                            <div class="text-sm text-secondary">
                                <p class="font-semibold text-primary mb-1">{{ __('Import Successful') }}</p>
                                <p>{{ __(':count products have been successfully imported to branch :branch', [
                                    'count' => number_format($batch->successful_rows),
                                    'branch' => $batch->branch->name ?? 'N/A'
                                ]) }}</p>
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="flex items-center justify-between pt-4">
                        <a href="{{ route('ns.procurement-import.history') }}" 
                           class="inline-flex items-center px-4 py-2 border border-box-edge rounded-lg text-sm font-medium text-secondary hover:bg-surface-tertiary transition-colors">
                            <i class="las la-history mr-2"></i>
                            {{ __('View History') }}
                        </a>
                        
                        <a href="{{ route('ns.procurement-import.index') }}" 
                           class="inline-flex items-center px-6 py-3 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-info-primary hover:bg-info-secondary transition-colors">
                            <i class="las la-upload mr-2"></i>
                            {{ __('Import Another File') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
