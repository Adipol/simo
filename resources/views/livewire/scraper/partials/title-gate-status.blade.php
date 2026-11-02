@php($health = $this->titleGateHealth)
<div class="text-xs text-zinc-600 space-y-1" aria-label="Estado DeepSeek">
    <p>DeepSeek:
        @if($health['configuration'] === 'disabled')
            Filtro deshabilitado
        @elseif($health['configuration'] === 'not_configured')
            Sin configurar
        @else
            Configuración lista (no prueba disponibilidad actual)
        @endif
    </p>
    <p>{{ $health['label'] }}@if($health['last_attempt_at']) · {{ $health['last_attempt_at'] }}@endif</p>
    @if($health['last_status'] === 'invalid_response')<p>Respuesta no válida.</p>@endif
    @if($health['last_success_at'])
        <p>Última llamada validada registrada: {{ $health['last_success_at'] }}. Es un antecedente, no una comprobación actual.</p>
    @endif
</div>
