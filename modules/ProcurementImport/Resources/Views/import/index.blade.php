@extends('layout.dashboard')

@section('layout.dashboard.body')
<div>
    @include('../common/dashboard-header')
    
    <div id="dashboard-content" class="px-4">
        <div class="py-4">
            <div class="rounded-lg shadow-lg bg-surface-secondary border border-box-edge overflow-hidden">
                <div class="p-6 border-b border-box-edge">
                    <h3 class="text-xl font-semibold text-primary">{{ __('CSV Procurement Import') }}</h3>
                    <p class="text-sm text-secondary mt-1">{{ __('Upload CSV file to import products and stock for your branch') }}</p>
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

                    @if(session('error'))
                    <div class="mb-4 p-4 rounded-lg bg-error-tertiary border border-error-secondary text-error-primary">
                        <div class="flex items-center">
                            <i class="las la-exclamation-circle text-2xl mr-2"></i>
                            <span>{{ session('error') }}</span>
                        </div>
                    </div>
                    @endif

                    @if($errors->any())
                    <div class="mb-4 p-4 rounded-lg bg-error-tertiary border border-error-secondary text-error-primary">
                        <div class="flex items-center mb-2">
                            <i class="las la-exclamation-triangle text-2xl mr-2"></i>
                            <span class="font-semibold">{{ __('Validation Errors') }}</span>
                        </div>
                        <ul class="list-disc list-inside ml-8">
                            @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    <form action="{{ route('ns.procurement-import.upload') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
                        @csrf

                        <div>
                            <label class="block text-sm font-medium text-primary mb-2">
                                {{ __('Select CSV File') }}
                                <span class="text-error-primary">*</span>
                            </label>
                            
                            <div class="mt-2 flex justify-center px-6 pt-5 pb-6 border-2 border-dashed border-box-edge rounded-lg hover:border-info-secondary transition-colors cursor-pointer"
                                 onclick="document.getElementById('csv_file').click()">
                                <div class="space-y-1 text-center">
                                    <i class="las la-cloud-upload-alt text-6xl text-secondary"></i>
                                    <div class="flex text-sm text-secondary">
                                        <label for="csv_file" class="relative cursor-pointer rounded-md font-medium text-info-primary hover:text-info-secondary focus-within:outline-none">
                                            <span>{{ __('Upload a file') }}</span>
                                        </label>
                                        <p class="pl-1">{{ __('or drag and drop') }}</p>
                                    </div>
                                    <p class="text-xs text-tertiary">{{ __('CSV up to 10MB') }}</p>
                                </div>
                            </div>
                            
                            <input id="csv_file" 
                                   name="csv_file" 
                                   type="file" 
                                   accept=".csv,.txt" 
                                   class="sr-only" 
                                   required
                                   onchange="document.getElementById('file-name').textContent = this.files[0]?.name || ''">
                            
                            <p id="file-name" class="mt-2 text-sm text-secondary"></p>
                        </div>

                        <div class="bg-info-tertiary border border-info-secondary rounded-lg p-4">
                            <div class="flex items-start">
                                <i class="las la-info-circle text-xl text-info-primary mt-0.5 mr-2"></i>
                                <div class="text-sm text-secondary">
                                    <p class="font-semibold text-primary mb-2">{{ __('CSV File Requirements:') }}</p>
                                    <ul class="list-disc list-inside space-y-1 ml-4">
                                        <li>{{ __('File format: CSV (Comma-separated values)') }}</li>
                                        <li>{{ __('Maximum file size: 10 MB') }}</li>
                                        <li>{{ __('Required columns: BarCode, Product, Category, Supplier, BuyingPrice, SellingPrice, Unit, Quantity') }}</li>
                                        <li>{{ __('Optional columns: BuyingDate, ExpiredDate, TaxAmount, Description, Note, OrderNumber, OrderDate') }}</li>
                                        <li>{{ __('First row must be header row') }}</li>
                                        <li>{{ __('All products will be imported to your current branch: ') }}<strong>{{ Auth::user()->branch->name ?? 'N/A' }}</strong></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-4">
                            <a href="{{ route('ns.procurement-import.history') }}" 
                               class="inline-flex items-center px-4 py-2 border border-box-edge rounded-lg text-sm font-medium text-secondary hover:bg-surface-tertiary transition-colors">
                                <i class="las la-history mr-2"></i>
                                {{ __('View Import History') }}
                            </a>
                            
                            <button type="submit" 
                                    class="inline-flex items-center px-6 py-3 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-info-primary hover:bg-info-secondary focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-info-primary transition-colors">
                                <i class="las la-upload mr-2"></i>
                                {{ __('Upload & Preview') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
