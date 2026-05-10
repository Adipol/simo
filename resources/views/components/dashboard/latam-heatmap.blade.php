@props(['counts' => []])

@php
    use App\Services\Dashboard\HeatmapPalette;

    /**
     * @var array<string, int> $counts  ISO country code → detection count
     */
    $countries = HeatmapPalette::latamCountries();
    $maxCount  = !empty($counts) ? max($counts) : 0;
    $hasData   = $maxCount > 0;
@endphp

<div class="relative">
    <svg
        viewBox="0 0 220 245"
        class="w-full max-w-xs mx-auto"
        aria-label="Mapa de calor LATAM — distribución geográfica de detecciones"
        role="img"
    >
        @foreach($countries as $iso => $c)
            @php
                $count   = (int) ($counts[$iso] ?? 0);
                $fillHex = HeatmapPalette::bucketHex($count, max($maxCount, 1));
                $opacity = $hasData ? 1 : 0.4;
            @endphp

            <g>
                <rect
                    x="{{ $c['x'] }}"
                    y="{{ $c['y'] }}"
                    width="{{ $c['w'] }}"
                    height="{{ $c['h'] }}"
                    fill="{{ $fillHex }}"
                    stroke="#d4d4d8"
                    stroke-width="0.5"
                    rx="2"
                    opacity="{{ $opacity }}"
                />
                <text
                    x="{{ $c['lx'] }}"
                    y="{{ $c['ly'] }}"
                    font-size="6"
                    font-family="Inter, system-ui, sans-serif"
                    font-weight="600"
                    fill="#52525b"
                    text-anchor="middle"
                    dominant-baseline="middle"
                >{{ $iso }}</text>
                <title>{{ $c['name'] }}: {{ $count }} {{ $count === 1 ? 'detección' : 'detecciones' }}</title>
            </g>
        @endforeach

        {{-- No data overlay text — inside viewBox (y=237 is within 0–245 height) --}}
        @unless($hasData)
            <text
                x="110"
                y="237"
                font-size="7"
                font-family="Inter, system-ui, sans-serif"
                fill="#a1a1aa"
                text-anchor="middle"
                dominant-baseline="middle"
            >Aún sin detecciones por país</text>
        @endunless
    </svg>
</div>
