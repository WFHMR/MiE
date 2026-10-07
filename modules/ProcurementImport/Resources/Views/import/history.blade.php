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
                            <h3 class="text-xl font-semibold text-primary">{{ __('Import History') }}</h3>
                            <p class="text-sm text-secondary mt-1">{{ __('All CSV import batches for your branch') }}</p>
                        </div>
                        <a href="{{ route('ns.procurement-import.index') }}" 
                           class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-info-primary hover:bg-info-secondary transition-colors">
                            <i class="las la-plus mr-2"></i>
                            {{ __('New Import') }}
                        </a>
                    </div>
                </div>

                <div class="p-6">
                    @if(session('success'))
                    <div class="mb-4 p-4 rounded-lg bg-success-tertiary border border-success-secondary text-success-primary">
                        <div class="flex items-center">
                            <i class="las la-check-circle text-2xl mr-2"></i>
                            <span>{{ session('success') }}</span>
                        </div>
                    </div>
                    @endif

                    @if($batches->total() > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-box-edge">
                            <thead class="bg-surface-tertiary">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('ID') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('File Name') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Branch') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Total') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Success') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Failed') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Status') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Date') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-surface-secondary divide-y divide-box-edge">
                                @foreach($batches as $batch)
                                <tr class="hover:bg-surface-tertiary transition-colors">
                                    <td class="px-4 py-3 text-sm text-secondary font-mono">#{{ $batch->id }}</td>
                                    <td class="px-4 py-3 text-sm text-primary">
                                        <div class="flex items-center">
                                            <i class="las la-file-csv text-lg text-info-primary mr-2"></i>
                                            <span>{{ Str::limit($batch->filename, 30) }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-secondary">{{ $batch->branch->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-sm text-primary font-semibold">{{ number_format($batch->total_rows) }}</td>
                                    <td class="px-4 py-3 text-sm text-success-primary font-semibold">{{ number_format($batch->successful_rows) }}</td>
                                    <td class="px-4 py-3 text-sm text-error-primary font-semibold">{{ number_format($batch->failed_rows) }}</td>
                                    <td class="px-4 py-3">
                                        @if($batch->status === 'completed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-success-tertiary text-success-primary border border-success-secondary">
                                            <i class="las la-check-circle mr-1"></i> {{ __('Completed') }}
                                        </span>
                                        @elseif($batch->status === 'pending')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-warning-tertiary text-warning-primary border border-warning-secondary">
                                            <i class="las la-clock mr-1"></i> {{ __('Pending') }}
                                        </span>
                                        @elseif($batch->status === 'processing')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-info-tertiary text-info-primary border border-info-secondary">
                                            <i class="las la-spinner la-spin mr-1"></i> {{ __('Processing') }}
                                        </span>
                                        @elseif($batch->status === 'failed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-error-tertiary text-error-primary border border-error-secondary">
                                            <i class="las la-times-circle mr-1"></i> {{ __('Failed') }}
                                        </span>
                                        @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-tertiary text-secondary border border-box-edge">
                                            {{ ucfirst($batch->status) }}
                                        </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm text-secondary">
                                        <div>{{ $batch->created_at->format('Y-m-d') }}</div>
                                        <div class="text-xs text-tertiary">{{ $batch->created_at->format('H:i:s') }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium">
                                        <div class="flex items-center space-x-2">
                                            @if($batch->status === 'pending')
                                            <a href="{{ route('ns.procurement-import.preview', $batch->id) }}" 
                                               class="text-info-primary hover:text-info-secondary" 
                                               title="{{ __('Preview') }}">
                                                <i class="las la-eye text-lg"></i>
                                            </a>
                                            @else
                                            <a href="{{ route('ns.procurement-import.result', $batch->id) }}" 
                                               class="text-info-primary hover:text-info-secondary" 
                                               title="{{ __('View Result') }}">
                                                <i class="las la-chart-bar text-lg"></i>
                                            </a>
                                            @endif
                                            
                                            <form action="{{ route('ns.procurement-import.destroy', $batch->id) }}" 
                                                  method="POST" 
                                                  class="inline"
                                                  onsubmit="return confirm('{{ __('Are you sure you want to delete this import batch?') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" 
                                                        class="text-error-primary hover:text-error-secondary" 
                                                        title="{{ __('Delete') }}">
                                                    <i class="las la-trash text-lg"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6">
                        {{ $batches->links() }}
                    </div>
                    @else
                    <div class="text-center py-12">
                        <i class="las la-inbox text-6xl text-secondary mb-4"></i>
                        <h3 class="text-lg font-medium text-primary mb-2">{{ __('No Import History') }}</h3>
                        <p class="text-sm text-secondary mb-6">{{ __('You haven\'t imported any CSV files yet.') }}</p>
                        <a href="{{ route('ns.procurement-import.index') }}" 
                           class="inline-flex items-center px-6 py-3 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-info-primary hover:bg-info-secondary transition-colors">
                            <i class="las la-upload mr-2"></i>
                            {{ __('Import Your First File') }}
                        </a>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
