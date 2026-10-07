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
                            <h3 class="text-xl font-semibold text-primary">{{ __('Preview CSV Import') }}</h3>
                            <p class="text-sm text-secondary mt-1">{{ __('Review data before processing') }}</p>
                        </div>
                        <div class="text-right">
                            <div class="inline-block px-3 py-1 rounded-full text-xs font-medium bg-warning-tertiary text-warning-primary border border-warning-secondary">
                                {{ __('Pending') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-file-csv text-3xl text-info-primary"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-secondary">{{ __('File Name') }}</p>
                                    <p class="text-sm font-semibold text-primary">{{ $batch->filename }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-list-ol text-3xl text-success-primary"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-secondary">{{ __('Total Rows') }}</p>
                                    <p class="text-sm font-semibold text-primary">{{ number_format($batch->total_rows) }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-store text-3xl text-purple-500"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-secondary">{{ __('Branch') }}</p>
                                    <p class="text-sm font-semibold text-primary">{{ $batch->branch->name ?? 'N/A' }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-surface-tertiary border border-box-edge rounded-lg p-4">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <i class="las la-user text-3xl text-teal-500"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-xs text-secondary">{{ __('Uploaded By') }}</p>
                                    <p class="text-sm font-semibold text-primary">{{ $batch->user->username ?? 'N/A' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-warning-tertiary border border-warning-secondary rounded-lg p-4">
                        <div class="flex items-start">
                            <i class="las la-exclamation-triangle text-xl text-warning-primary mt-0.5 mr-2"></i>
                            <div class="text-sm text-secondary">
                                <p class="font-semibold text-primary mb-1">{{ __('Important Notice') }}</p>
                                <p>{{ __('This preview shows the first 10 rows. Once you click "Process Import", all rows will be processed and stock will be created immediately.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="border border-box-edge rounded-lg overflow-hidden">
                        <div class="bg-surface-tertiary px-4 py-3 border-b border-box-edge">
                            <h4 class="text-sm font-semibold text-primary">{{ __('Preview (First 10 Rows)') }}</h4>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-box-edge">
                                <thead class="bg-surface-tertiary">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">#</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('BarCode') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Product') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Category') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Supplier') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Quantity') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Unit') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Buy Price') }}</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-secondary uppercase tracking-wider">{{ __('Sell Price') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-secondary divide-y divide-box-edge">
                                    @forelse($previewRows as $row)
                                    <tr class="hover:bg-surface-tertiary transition-colors">
                                        <td class="px-4 py-3 text-sm text-secondary">{{ $loop->iteration }}</td>
                                        <td class="px-4 py-3 text-sm text-primary font-mono">{{ $row->data['BarCode'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-primary">{{ $row->data['Product'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-secondary">{{ $row->data['Category'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-secondary">{{ $row->data['Supplier'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-primary font-semibold">{{ $row->data['Quantity'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-secondary">{{ $row->data['Unit'] ?? '-' }}</td>
                                        <td class="px-4 py-3 text-sm text-success-primary">{{ number_format($row->data['BuyingPrice'] ?? 0, 2) }}</td>
                                        <td class="px-4 py-3 text-sm text-info-primary">{{ number_format($row->data['SellingPrice'] ?? 0, 2) }}</td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="9" class="px-4 py-8 text-center text-sm text-secondary">
                                            <i class="las la-inbox text-4xl mb-2"></i>
                                            <p>{{ __('No data found') }}</p>
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if($batch->total_rows > 10)
                        <div class="bg-surface-tertiary px-4 py-3 border-t border-box-edge text-center">
                            <p class="text-sm text-secondary">
                                {{ __('Showing 10 of :total rows', ['total' => number_format($batch->total_rows)]) }}
                            </p>
                        </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between pt-4">
                        <a href="{{ route('ns.procurement-import.index') }}" 
                           class="inline-flex items-center px-4 py-2 border border-box-edge rounded-lg text-sm font-medium text-secondary hover:bg-surface-tertiary transition-colors">
                            <i class="las la-arrow-left mr-2"></i>
                            {{ __('Cancel') }}
                        </a>
                        
                        <form action="{{ route('ns.procurement-import.process', $batch->id) }}" method="POST" onsubmit="return confirm('{{ __('Are you sure you want to process this import? This action cannot be undone.') }}')">
                            @csrf
                            <button type="submit" 
                                    class="inline-flex items-center px-6 py-3 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-success-primary hover:bg-success-secondary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-success-primary transition-colors">
                                <i class="las la-cogs mr-2"></i>
                                {{ __('Process Import') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
