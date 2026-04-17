<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\EntidadTipo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GeminiPromptBuilder
{
    private const MAX_DIFF_CHARS = 12000;

    private const MAX_DIFF_FOR_FULL = 8000;

    private const MAX_DIFF_FOR_EXCERPT = 15000;

    public function __construct(
        private readonly ?PepCatalogService $catalog = null,
    ) {}

    /**
     * Build a prompt for Gemini Flash to classify PEP/OPI.
     */
    public function filtroPEP(string $texto, string $pais, string $categoria): string
    {
        if ($this->catalog !== null) {
            $cargos = $this->catalog->getCargos($pais);
            $entidades = $this->catalog->getEntidades($pais);

            if ($cargos->isNotEmpty()) {
                return $this->buildDynamicPrompt($cargos, $entidades, $pais, $categoria, $texto);
            }

            $this->logNoPepPositions($pais);
        }

        return $this->buildGenericPrompt($pais, $categoria, $texto);
    }

    private function entidadTipoValue(EntidadTipo|string $tipo): string
    {
        return $tipo instanceof EntidadTipo ? $tipo->value : $tipo;
    }

    private function logNoPepPositions(string $pais): void
    {
        try {
            Log::channel('gemini')->warning("No PEP positions for country: {$pais}");
        } catch (\RuntimeException) {
            // Facade not available in pure unit test context
        }
    }

    private function buildDynamicPrompt(
        Collection $cargos,
        Collection $entidades,
        string $pais,
        string $categoria,
        string $texto,
    ): string {
        $siemprePep = $cargos
            ->filter(fn ($c) => $this->entidadTipoValue($c->entidad_tipo) === EntidadTipo::Todas->value)
            ->map(fn ($c) => '- '.$c->nombre)
            ->implode("\n");

        $pepEnPublica = $cargos
            ->filter(fn ($c) => $this->entidadTipoValue($c->entidad_tipo) === EntidadTipo::Publica->value)
            ->map(fn ($c) => '- '.$c->nombre)
            ->implode("\n");

        $puedeSerPep = $cargos
            ->filter(fn ($c) => $this->entidadTipoValue($c->entidad_tipo) === EntidadTipo::Ambas->value)
            ->map(fn ($c) => '- '.$c->nombre)
            ->implode("\n");

        $entidadesStr = $entidades
            ->map(fn ($e) => $e->nombre)
            ->implode(', ');

        $entidadesLine = $entidadesStr !== ''
            ? "Entidades públicas conocidas de {$pais}: {$entidadesStr}"
            : '';

        $reglasClasificacion = $this->buildReglasClasificacion();
        $ejemplosNegativos = $this->buildEjemplosNegativos();
        $contextoCategoria = $this->buildContextoCategoria($categoria);

        return <<<PROMPT
Sos un experto en análisis de riesgo financiero y compliance AML/CFT en {$pais}.
Tu tarea: analizar el siguiente texto y determinar si menciona a una persona que sea PEP (Persona Políticamente Expuesta) u OPI (persona vinculada a crimen organizado o bajo investigación judicial).

DEFINICIONES PEP para {$pais}:

SIEMPRE_PEP (siempre son PEP independientemente de la entidad donde trabajen):
{$siemprePep}

PEP_EN_ENTIDAD_PUBLICA (son PEP solo si están en una entidad pública):
{$pepEnPublica}

PUEDE_SER_PEP (depende del contexto; considerar especialmente si están en estas entidades):
{$puedeSerPep}
{$entidadesLine}

- OPI: líderes de organizaciones criminales, personas bajo investigación por lavado de activos o narcotráfico

{$reglasClasificacion}

IMPORTANTE: Un artículo puede mencionar MÚLTIPLES personas. Devolvé TODAS las personas PEP/OPI encontradas en el array 'personas'. Si no hay ninguna, devolvé el array vacío.

Devolvé ÚNICAMENTE el siguiente JSON, sin explicaciones adicionales:
{"personas":[{"nombre":string,"cargo":string|null,"categoria":"PEP"|"OPI","entidad_tipo":"publica"|"privada"|"desconocido"|null,"confianza":0-100,"evento":"designacion"|"renuncia"|"crimen"|null,"motivo":string}],"motivo_general":string}

EJEMPLOS:

[PEP+] "El ministro de Economía Juan Pérez firmó el decreto de aumento salarial"
→ {"personas":[{"nombre":"Juan Pérez","cargo":"Ministro de Economía","categoria":"PEP","entidad_tipo":"publica","confianza":95,"evento":"designacion","motivo":"Cargo ejecutivo de alto nivel"}],"motivo_general":"Artículo sobre acción ministerial"}

[OPI+] "El líder de la organización criminal Rodrigo Vargas fue capturado en Operativo Sur"
→ {"personas":[{"nombre":"Rodrigo Vargas","cargo":null,"categoria":"OPI","entidad_tipo":"desconocido","confianza":92,"evento":"crimen","motivo":"Líder de organización criminal"}],"motivo_general":"Captura de líder criminal"}

[NEG] "El delantero Carlos Torres anotó el gol del campeonato en el estadio Luna"
→ {"personas":[],"motivo_general":"Texto deportivo sin mención de cargos públicos ni actividad criminal"}

[MULTI] "Se designa a Rubén Arce como Ministro de Economía, tras la renuncia de Carlos Álvarez"
→ {"personas":[{"nombre":"Rubén Arce","cargo":"Ministro de Economía","categoria":"PEP","entidad_tipo":"publica","confianza":95,"evento":"designacion","motivo":"Designado como nuevo ministro"},{"nombre":"Carlos Álvarez","cargo":"Ministro de Economía","categoria":"PEP","entidad_tipo":"publica","confianza":90,"evento":"renuncia","motivo":"Renuncia al cargo de ministro"}],"motivo_general":"Cambio de autoridades en cartera ministerial"}

{$ejemplosNegativos}
{$contextoCategoria}
Categoría investigada: {$categoria}
País: {$pais}

TEXTO:
{$texto}
PROMPT;
    }

    private function buildGenericPrompt(string $pais, string $categoria, string $texto): string
    {
        $reglasClasificacion = $this->buildReglasClasificacion();
        $ejemplosNegativos = $this->buildEjemplosNegativos();
        $contextoCategoria = $this->buildContextoCategoria($categoria);

        return <<<PROMPT
Sos un experto en análisis de riesgo financiero y compliance AML/CFT en {$pais}.
Tu tarea: analizar el siguiente texto y determinar si menciona a una persona que sea PEP (Persona Políticamente Expuesta) u OPI (persona vinculada a crimen organizado o bajo investigación judicial).

DEFINICIONES:
- PEP: ministros, legisladores, jueces, directores de entes públicos, militares de alto rango, embajadores
- OPI: líderes de organizaciones criminales, personas bajo investigación por lavado de activos o narcotráfico

{$reglasClasificacion}

IMPORTANTE: Un artículo puede mencionar MÚLTIPLES personas. Devolvé TODAS las personas PEP/OPI encontradas en el array 'personas'. Si no hay ninguna, devolvé el array vacío.

Devolvé ÚNICAMENTE el siguiente JSON, sin explicaciones adicionales:
{"personas":[{"nombre":string,"cargo":string|null,"categoria":"PEP"|"OPI","entidad_tipo":"publica"|"privada"|"desconocido"|null,"confianza":0-100,"evento":"designacion"|"renuncia"|"crimen"|null,"motivo":string}],"motivo_general":string}

EJEMPLOS:

[PEP+] "El ministro de Economía Juan Pérez firmó el decreto de aumento salarial"
→ {"personas":[{"nombre":"Juan Pérez","cargo":"Ministro de Economía","categoria":"PEP","entidad_tipo":"publica","confianza":95,"evento":"designacion","motivo":"Cargo ejecutivo de alto nivel"}],"motivo_general":"Artículo sobre acción ministerial"}

[OPI+] "El líder de la organización criminal Rodrigo Vargas fue capturado en Operativo Sur"
→ {"personas":[{"nombre":"Rodrigo Vargas","cargo":null,"categoria":"OPI","entidad_tipo":"desconocido","confianza":92,"evento":"crimen","motivo":"Líder de organización criminal"}],"motivo_general":"Captura de líder criminal"}

[NEG] "El delantero Carlos Torres anotó el gol del campeonato en el estadio Luna"
→ {"personas":[],"motivo_general":"Texto deportivo sin mención de cargos públicos ni actividad criminal"}

[MULTI] "Se designa a Rubén Arce como Ministro de Economía, tras la renuncia de Carlos Álvarez"
→ {"personas":[{"nombre":"Rubén Arce","cargo":"Ministro de Economía","categoria":"PEP","entidad_tipo":"publica","confianza":95,"evento":"designacion","motivo":"Designado como nuevo ministro"},{"nombre":"Carlos Álvarez","cargo":"Ministro de Economía","categoria":"PEP","entidad_tipo":"publica","confianza":90,"evento":"renuncia","motivo":"Renuncia al cargo de ministro"}],"motivo_general":"Cambio de autoridades en cartera ministerial"}

{$ejemplosNegativos}
{$contextoCategoria}
Categoría investigada: {$categoria}
País: {$pais}

TEXTO:
{$texto}
PROMPT;
    }

    private function buildReglasClasificacion(): string
    {
        return <<<'RULES'
REGLAS DE CLASIFICACIÓN — solo clasificar como PEP si se cumplen TODAS:
✓ El texto menciona EXPLÍCITAMENTE un cargo o función pública (no inferir ni inventar cargos)
✓ El artículo trata PRINCIPALMENTE sobre esa persona en contexto político/institucional
✓ La persona es el SUJETO ACTIVO del rol público (no fuente, no mencionada de paso)
Si alguna regla no se cumple → NO incluir esa persona en el array 'personas'.
RULES;
    }

    private function buildEjemplosNegativos(): string
    {
        return <<<'NEGATIVES'
[NEG] "El caso confirmado de fiebre amarilla obligó a las autoridades sanitarias a activar protocolo. Carlos Hurtado, de 45 años, fue hospitalizado en el centro médico."
→ {"personas":[],"motivo_general":"Artículo de salud pública. Carlos Hurtado es paciente, no funcionario público."}

[NEG] "En la protesta de vecinos de Villa Adela, Marcos Quispe fue detenido por las fuerzas del orden tras los incidentes."
→ {"personas":[],"motivo_general":"Persona civil en contexto de protesta social. Sin cargo público mencionado."}

[NEG] "La directora del hospital municipal, Dra. Silvia Mamani, informó sobre los avances en el plan de vacunación regional."
→ {"personas":[],"motivo_general":"Directora de hospital municipal es cargo de salud, no cargo político PEP."}

NEGATIVES;
    }

    private function buildContextoCategoria(string $categoria): string
    {
        return match ($categoria) {
            'OPI-crimen' => "CONTEXTO DE BÚSQUEDA: Este artículo fue detectado por palabras clave de categoría 'crimen'. Verificá que la persona mencionada sea un actor institucional (funcionario, investigador, autoridad), NO una víctima, testigo o civil detenido.\n",
            'PEP-designacion' => "CONTEXTO DE BÚSQUEDA: Este artículo fue detectado por palabras clave de categoría 'designacion'. Verificá que el artículo confirme efectivamente la designación a un cargo público específico.\n",
            'PEP-renuncia' => "CONTEXTO DE BÚSQUEDA: Este artículo fue detectado por palabras clave de categoría 'renuncia'. Verificá que la persona mencionada efectivamente ocupaba un cargo público antes de la renuncia.\n",
            default => '',
        };
    }

    /**
     * Build a prompt for Gemini Pro to analyze a government source change.
     */
    public function analisisCambio(string $diff, string $fuente, string $organismo): string
    {
        $truncatedDiff = $this->truncarDiff($diff);

        return <<<PROMPT
Sos un experto en gobierno corporativo y análisis de cambios en organismos públicos de Latinoamérica.
Analizá el siguiente diff de {$organismo} (fuente: {$fuente}) para detectar cambio de autoridades.

REGLA CRÍTICA: Solo reportá cambios de PERSONAS en cargos públicos.
Si el diff solo contiene cambios de documentos, resoluciones, decretos, números de expediente,
fechas de publicación, títulos de eventos o textos administrativos SIN mencionar personas
que entran o salen de un cargo, respondé con es_mae=false, riesgo="bajo" y explicá en el
análisis que no se detectaron cambios de personal.

PASOS:
1. Determiná si el diff contiene cambios de PERSONAS (nombres propios que entran/salen de cargos)
2. Si NO hay personas → responder con riesgo bajo y sin personas
3. Si SÍ hay personas:
   a. Identificá personas removidas (líneas que empiezan con -)
   b. Identificá personas nuevas (líneas que empiezan con +)
   c. Determiná si el cargo es MAE (Máxima Autoridad Ejecutiva: ministro, secretario ejecutivo, director general)
   d. Evaluá riesgo AML: alto (MAE o cargo clave), medio (gerencia), bajo (técnico/administrativo)
4. Escribí análisis conciso (máx 300 caracteres)

EJEMPLOS de cambios que NO son de personas (ignorar):
- "+ RESOLUCIÓN AETN Nº 163/2026" → Es un documento, no una persona
- "- Publicado el: 15/04/2026" → Es una fecha, no una persona
- "+ AETN REALIZÓ CAPACITACIÓN SOBRE OBLIGACIONES" → Es un evento, no una persona

JSON de salida (SOLO JSON válido):
{"persona_removida":string|null,"persona_nueva":string|null,"cargo":string|null,"es_mae":boolean,"riesgo":"alto"|"medio"|"bajo","analisis":string}

DIFF:
{$truncatedDiff}
PROMPT;
    }

    /**
     * Intelligently truncate a diff to fit within token limits.
     */
    public function truncarDiff(string $diff): string
    {
        $len = strlen($diff);

        // Case 1: Small enough to send complete
        if ($len <= self::MAX_DIFF_FOR_FULL) {
            return $diff;
        }

        // Case 2: Medium size - send first and last 4000 chars
        if ($len <= self::MAX_DIFF_FOR_EXCERPT) {
            $first = substr($diff, 0, 4000);
            $last = substr($diff, -4000);

            return $first."\n\n[... contenido truncado ...]\n\n".$last;
        }

        // Case 3: Large - extract only +/- lines with context
        $lines = explode("\n", $diff);
        $changeLines = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '+') || str_starts_with($trimmed, '-')) {
                // Get the change line plus some context before and after
                $start = max(0, $i - 2);
                $end = min(count($lines) - 1, $i + 2);
                for ($j = $start; $j <= $end; $j++) {
                    $changeLines[] = $lines[$j];
                }
                $changeLines[] = '---';
            }
        }

        $result = implode("\n", array_unique($changeLines));

        // Case 4: Still too long - cut middle
        if (strlen($result) > self::MAX_DIFF_CHARS) {
            $half = (int) (self::MAX_DIFF_CHARS / 2);
            $result = substr($result, 0, $half)
                ."\n\n[... contenido truncado ...]\n\n"
                .substr($result, -$half);
        }

        return $result;
    }
}
