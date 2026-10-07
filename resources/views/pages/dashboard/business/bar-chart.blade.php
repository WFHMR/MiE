@php
    $chartRows = collect($chartRows)->values();
    $chartMin = min(0, (float) ($chartRows->min('value') ?? 0));
    $chartMax = max(0, (float) ($chartRows->max('value') ?? 0));
    $chartSpan = $chartMax - $chartMin ?: 1;
    $chartZero = -$chartMin / $chartSpan * 100;
@endphp
<section class="card dashboard-chart" aria-label="{{ $chartTitle }}">
    <h2>{{ $chartTitle }}</h2>
    <p class="muted">{{ $chartNote }} · หน่วย: {{ $chartUnit }}</p>
    @if($chartRows->isEmpty())
        <p class="chart-empty">ยังไม่มีข้อมูลสำหรับกราฟนี้ในช่วงที่เลือก</p>
    @else
        <div class="chart-scale" aria-hidden="true"><span>{{ number_format($chartMin, 2) }}</span><span>{{ number_format($chartMax, 2) }}</span></div>
        <div class="chart-rows">
        @foreach($chartRows as $chartRow)
            @php
                $chartValue = (float) $chartRow['value'];
                $chartStart = (min(0, $chartValue) - $chartMin) / $chartSpan * 100;
                $chartWidth = abs($chartValue) / $chartSpan * 100;
            @endphp
            <div class="chart-row" data-value="{{ $chartValue }}">
                <div class="chart-label"><span>{{ $chartRow['label'] }}</span><strong>{{ number_format($chartValue, $chartUnit === 'บาท' || $chartUnit === '%' ? 2 : 0) }} {{ $chartUnit }}</strong></div>
                <div class="chart-track" aria-hidden="true">
                    <span class="chart-zero" style="left:{{ $chartZero }}%"></span>
                    <span class="chart-bar {{ $chartValue < 0 ? 'chart-negative' : '' }} {{ !empty($chartRow['target']) ? 'chart-target' : '' }}" style="left:{{ $chartStart }}%;width:{{ $chartWidth }}%"></span>
                </div>
            </div>
        @endforeach
        </div>
    @endif
</section>
