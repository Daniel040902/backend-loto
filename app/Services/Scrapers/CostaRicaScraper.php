<?php

namespace App\Services\Scrapers;

use App\Services\LotteryScraper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CostaRicaScraper implements LotteryScraper
{
    protected string $jpsBaseUrl = 'https://www.jps.go.cr';

    protected array $jpsPages = [
        '/resultados/nuevos-tiempos-reventados',
        '/resultados/3-monazos',
        '/resultados/lotto',
        '/resultados/chances',
        '/resultados/loteria-nacional',
    ];

    protected string $fallbackBaseUrl = 'https://www.nacionalloteria.com/costarica';

    protected string $proxyUrl = 'https://r.jina.ai';

    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    protected string $htmlCacheKey = 'cr_jps_html';

    protected array $drawTimes = [
        'Medio día' => '12:55 PM',
        'Tarde' => '4:30 PM',
        'Noche' => '7:30 PM',
    ];

    protected array $statsWindows = [
        '12:55 PM' => ['12:55', '13:00'],
        '4:30 PM' => ['16:30', '16:35'],
        '7:30 PM' => ['19:30', '19:35'],
    ];

    public function getCountrySlug(): string
    {
        return 'costa-rica';
    }

    public function getApiBaseUrl(): string
    {
        return $this->jpsBaseUrl;
    }

    public function getCalendarBaseUrl(): ?string
    {
        return null;
    }

    public function fetchResults(\DateTime $date): array
    {
        $nowCr = Carbon::now('America/Costa_Rica');
        $today = $nowCr->toDateString();
        $yesterday = $nowCr->copy()->subDay()->toDateString();

        $results = [];
        $ntHtml = null;

        foreach ($this->jpsPages as $path) {
            $html = $this->fetchJpsHtml($path);
            if ($html === null) {
                continue;
            }

            if ($ntHtml === null) {
                $ntHtml = $html;
            }

            foreach ($this->parseJpsPage($html) as $rec) {
                $this->addResultsForDate($results, $rec, $today);
                $this->addResultsForDate($results, $rec, $yesterday);
            }
        }

        if ($ntHtml !== null) {
            $results = $this->mergeSameDayFromStats($results, $this->extractJpsStats($ntHtml), $today);
        }

        if (!empty($results)) {
            return array_values($results);
        }

        $fallback = $this->fetchFallbackResults($today);
        if (!empty($fallback)) {
            return array_values($fallback);
        }

        return array_values($this->fetchFallbackResults($yesterday));
    }

    public function parseResult(array $rawData, string $gameName, string $drawTime): ?array
    {
        if (!isset($rawData['winning_numbers']) || empty($rawData['winning_numbers'])) {
            return null;
        }

        return [
            'draw_date' => $rawData['draw_date'] ?? now()->toDateString(),
            'draw_time' => $drawTime,
            'winning_numbers' => $rawData['winning_numbers'],
            'prizes' => $rawData['prizes'] ?? null,
            'draw_number' => $rawData['draw_number'] ?? null,
            'date_iso' => $rawData['date_iso'] ?? $rawData['draw_date'] ?? now()->toDateString(),
        ];
    }

    public function normalizeNumber(string $number): string
    {
        $num = trim($number);
        if (ctype_digit($num)) {
            return str_pad($num, 2, '0', STR_PAD_LEFT);
        }
        return $num;
    }

    protected function addResultsForDate(array &$results, array $rec, string $date): void
    {
        $fecha = (string) ($rec['fecha'] ?? '');
        if (substr($fecha, 0, 10) !== $date) {
            return;
        }

        $drawNumber = (string) ($rec['numeroSorteo'] ?? '');
        $tipo = (string) ($rec['tipoSorteoName'] ?? '');

        switch ($tipo) {
            case 'Nuevos Tiempos':
                $drawTime = $this->timeToDrawTime(substr($fecha, 11, 5));
                if ($drawTime === null) {
                    return;
                }
                $num = $this->normalizeNumber((string) ($rec['numero'] ?? ''));
                if ($num === '') {
                    return;
                }
                $this->putResult($results, 'Nuevos Tiempos', $date, $drawTime, [$num], $this->nuevosTiemposPrizes($rec), $drawNumber);
                return;

            case 'Tres_Monazos':
                $drawTime = $this->timeToDrawTime(substr($fecha, 11, 5));
                if ($drawTime === null) {
                    return;
                }
                $numeros = $this->normalizeNumbers($rec['numeros'] ?? []);
                if (empty($numeros)) {
                    return;
                }
                $this->putResult($results, 'Tres Monazos', $date, $drawTime, $numeros, null, $drawNumber);
                return;

            case 'Lotto':
                $numeros = $this->normalizeNumbers($rec['numeros'] ?? []);
                if (!empty($numeros)) {
                    $this->putResult($results, 'Lotto', $date, '7:30 PM', $numeros, $this->lottoPrizes($rec['premiosLotto'] ?? null), $drawNumber);
                }
                $revancha = $this->normalizeNumbers($rec['numerosRevancha'] ?? []);
                if (!empty($revancha)) {
                    $this->putResult($results, 'Lotto Revancha', $date, '7:30 PM', $revancha, null, $drawNumber);
                }
                return;

            case 'Chances':
                $this->addSeriesResult($results, $rec, $date, 'Chances');
                return;

            case 'Lotería Nacional':
                $this->addSeriesResult($results, $rec, $date, 'Lotería Nacional');
                return;
        }
    }

    protected function addSeriesResult(array &$results, array $rec, string $date, string $gameName): void
    {
        $premios = $rec['premios'] ?? [];
        if (!is_array($premios) || empty($premios)) {
            return;
        }

        $main = $premios[0];
        foreach ($premios as $p) {
            if (($p['monto'] ?? 0) > ($main['monto'] ?? 0)) {
                $main = $p;
            }
        }

        $numero = $this->normalizeNumber((string) ($main['numero'] ?? ''));
        if ($numero === '') {
            return;
        }

        $serie = $this->normalizeSerie((string) ($main['serie'] ?? ''));
        $numbers = $serie === '' ? [$numero] : [$numero, $serie];

        $prizes = [];
        foreach (array_slice($premios, 0, 10) as $i => $p) {
            $prizes[] = [
                'position' => 'Premio ' . ($i + 1),
                'number' => (string) ($p['numero'] ?? ''),
                'serie' => (string) ($p['serie'] ?? ''),
            ];
        }

        $this->putResult($results, $gameName, $date, '7:30 PM', $numbers, $prizes, (string) ($rec['numeroSorteo'] ?? ''));
    }

    protected function putResult(array &$results, string $gameName, string $date, string $drawTime, array $numbers, ?array $prizes, string $drawNumber): void
    {
        $key = $gameName . '|' . $date . '|' . $drawTime;
        $results[$key] = [
            'game_name' => $gameName,
            'draw_date' => $date,
            'draw_time' => $drawTime,
            'winning_numbers' => $numbers,
            'prizes' => $prizes,
            'draw_number' => $drawNumber,
            'date_iso' => $date,
        ];
    }

    /**
     * Construye los prizes del sorteo Nuevos Tiempos con la info "reventado":
     *  - "REVENTADO" => el "mega reventado" (meganNumero / MgRev)
     *  - "BOLITA"    => color de bolita ("Roja" si in_reventado, si no "Blanca")
     */
    protected function nuevosTiemposPrizes(array $rec): ?array
    {
        $megan = (string) ($rec['meganNumero'] ?? '');
        $inReventado = (int) ($rec['in_reventado'] ?? 0);
        $color = strtoupper(trim((string) ($rec['colorBolita'] ?? '')));
        if ($color === '') {
            $color = $inReventado ? 'ROJA' : 'BLANCA';
        }

        $prizes = [];
        if ($megan !== '') {
            $prizes[] = [
                'position' => 'REVENTADO',
                'number' => $this->normalizeNumber($megan),
                'amount' => (string) ($rec['porcentaje'] ?? ''),
            ];
        }
        $prizes[] = [
            'position' => 'BOLITA',
            'number' => $color === 'ROJA' ? 'Roja' : 'Blanca',
            'amount' => '',
        ];

        return $prizes;
    }

    protected function lottoPrizes($data): ?array
    {
        if (!is_array($data) || empty($data)) {
            return null;
        }

        $prizes = [];
        foreach ($data as $key => $value) {
            $prizes[] = [
                'position' => (string) $key,
                'number' => (string) $value,
            ];
        }

        return $prizes;
    }

    protected function normalizeNumbers($numbers): array
    {
        if (!is_array($numbers)) {
            return [];
        }

        $out = [];
        foreach ($numbers as $n) {
            $n = trim((string) $n);
            if ($n === '') {
                continue;
            }
            $out[] = $n;
        }

        return $out;
    }

    protected function normalizeSerie(string $serie): string
    {
        $serie = trim($serie);
        if (ctype_digit($serie)) {
            return str_pad($serie, 3, '0', STR_PAD_LEFT);
        }
        return $serie;
    }

    protected function parseJpsPage(string $html): array
    {
        $refs = [];
        foreach ($this->extractNextJsChunks($html) as $chunk) {
            $decoded = $this->decodePayload($chunk);
            if ($decoded === null || $decoded === '') {
                continue;
            }
            $this->collectJpsRefs($decoded, $refs);
        }

        $records = [];
        foreach ($refs as $value) {
            $this->collectJpsRecords($value, $records);
        }

        $unique = [];
        foreach ($records as $rec) {
            $key = (string) ($rec['numeroSorteo'] ?? ($rec['fecha'] ?? ''));
            if ($key === '') {
                continue;
            }
            $unique[$key] = $rec;
        }

        $resolved = [];
        foreach ($unique as $rec) {
            $resolved[] = $this->resolveRefs($rec, $refs);
        }

        return $resolved;
    }

    protected function extractNextJsChunks(string $html): array
    {
        $chunks = [];
        $marker = 'self.__next_f.push([1,"';
        $offset = 0;

        while (($start = strpos($html, $marker, $offset)) !== false) {
            $i = $start + strlen($marker);
            $length = strlen($html);

            while ($i < $length) {
                $char = $html[$i];
                if ($char === '\\') {
                    $i += 2;
                    continue;
                }
                if ($char === '"') {
                    break;
                }
                $i++;
            }

            $chunks[] = substr($html, $start + strlen($marker), $i - ($start + strlen($marker)));
            $offset = $i + 1;
        }

        return $chunks;
    }

    protected function collectJpsRefs(string $decoded, array &$refs): void
    {
        if (!preg_match_all('/^([0-9a-fA-F]+):(.*)$/m', $decoded, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $pair) {
            $value = json_decode(trim($pair[2]), true);
            if ($value === null && strtolower(trim($pair[2])) !== 'null') {
                continue;
            }
            $refs[$pair[1]] = $value;
        }
    }

    protected function collectJpsRecords($value, array &$records): void
    {
        if (!is_array($value)) {
            return;
        }

        if (isset($value['tipoSorteoCode'])) {
            $records[] = $value;
            return;
        }

        foreach ($value as $v) {
            $this->collectJpsRecords($v, $records);
        }
    }

    protected function resolveRefs($value, array $refs, array &$visited = []): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $out = [];
                foreach ($value as $v) {
                    $out[] = $this->resolveRefs($v, $refs, $visited);
                }
                return $out;
            }

            $out = [];
            foreach ($value as $key => $v) {
                $out[$key] = $this->resolveRefs($v, $refs, $visited);
            }
            return $out;
        }

        if (is_string($value) && str_starts_with($value, '$')) {
            $key = substr($value, 1);
            if (isset($refs[$key]) && !isset($visited[$key])) {
                $visited[$key] = true;
                $resolved = $this->resolveRefs($refs[$key], $refs, $visited);
                unset($visited[$key]);
                return $resolved;
            }
        }

        return $value;
    }

    protected function extractJpsStats(string $html): array
    {
        $stats = [];

        if (!preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)\]\)/s', $html, $matches)) {
            return $stats;
        }

        foreach ($matches[1] as $chunk) {
            $decoded = $this->decodePayload($chunk);
            if ($decoded === null) {
                continue;
            }

            $decoded = $this->unescapeJsonString($decoded);

            if (preg_match_all('/"_Numero":(\d+),"_CantidadVeces":(\d+),"_FechaUltimaVez":"([^"]+)"/', $decoded, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $num = (int) $m[1];
                    $veces = (int) $m[2];

                    if (!isset($stats[$num]) || $veces > $stats[$num]['veces']) {
                        $stats[$num] = [
                            'veces' => $veces,
                            'fecha' => $m[3],
                        ];
                    }
                }
            }
        }

        return $stats;
    }

    protected function mergeSameDayFromStats(array $results, array $stats, string $today): array
    {
        if (empty($stats)) {
            return $results;
        }

        $nowCr = Carbon::now('America/Costa_Rica');
        if ($nowCr->toDateString() !== $today) {
            return $results;
        }

        $nowTime = $nowCr->format('H:i');

        foreach ($this->statsWindows as $drawTime => [$start, $end]) {
            if ($nowTime < $start) {
                continue;
            }

            foreach ($stats as $num => $info) {
                $fecha = $info['fecha'];
                if (substr($fecha, 0, 10) !== $today) {
                    continue;
                }

                $hm = substr($fecha, 11, 5);
                if ($hm < $start || $hm > $end) {
                    continue;
                }

                $key = 'Nuevos Tiempos|' . $today . '|' . $drawTime;
                if (!isset($results[$key])) {
                    $results[$key] = [
                        'game_name' => 'Nuevos Tiempos',
                        'draw_date' => $today,
                        'draw_time' => $drawTime,
                        'winning_numbers' => [$this->normalizeNumber((string) $num)],
                        'prizes' => null,
                        'draw_number' => '',
                        'date_iso' => $today,
                    ];
                }

                break;
            }
        }

        return $results;
    }

    protected function fetchJpsHtml(string $path): ?string
    {
        $cacheKey = $this->htmlCacheKey . '_' . md5($path);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $html = $this->fetchJpsDirect($path);
        if ($html === null) {
            $html = $this->fetchJpsViaProxy($path);
        }

        if ($html === null) {
            return null;
        }

        Cache::put($cacheKey, $html, now()->addMinutes(5));

        return $html;
    }

    protected function fetchJpsDirect(string $path): ?string
    {
        foreach ([$this->jpsBaseUrl, 'https://jps.go.cr'] as $base) {
            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'es-CR,es;q=0.9,en;q=0.8',
                        'User-Agent' => $this->userAgent,
                    ])
                    ->get($base . $path);

                if ($response->successful() && str_contains($response->body(), 'tipoSorteoCode')) {
                    return $response->body();
                }
            } catch (\Throwable $e) {
                Log::warning('Costa Rica JPS direct failed (' . $base . '): ' . $e->getMessage());
            }
        }

        return null;
    }

    protected function fetchJpsViaProxy(string $path): ?string
    {
        try {
            $response = Http::timeout(50)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'x-respond-with' => 'html',
                    'x-timeout' => '25',
                ])
                ->get($this->proxyUrl . '/' . $this->jpsBaseUrl . $path . '?_t=' . time());

            if ($response->successful() && str_contains($response->body(), 'tipoSorteoCode')) {
                return $response->body();
            }

            Log::warning('Costa Rica JPS proxy returned status ' . $response->status());
        } catch (\Throwable $e) {
            Log::warning('Costa Rica JPS proxy failed: ' . $e->getMessage());
        }

        return null;
    }

    protected function fetchFallbackResults(string $date): array
    {
        $pages = [
            'nuevos-tiempos.php',
            '3-monazos.php',
            'lotto.php',
            'chances.php',
            'loteria-nacional.php',
        ];

        $results = [];

        foreach ($pages as $page) {
            try {
                $response = Http::timeout(20)
                    ->withHeaders([
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'es-CR,es;q=0.9,en;q=0.8',
                        'User-Agent' => $this->userAgent,
                    ])
                    ->get($this->fallbackBaseUrl . '/' . $page, ['del-dia' => $date]);

                if (!$response->successful()) {
                    continue;
                }

                $results = array_merge($results, $this->parseFallbackHtml($response->body(), $date, $page));
            } catch (\Throwable $e) {
                Log::warning('Costa Rica fallback page failed (' . $page . '): ' . $e->getMessage());
            }
        }

        return $results;
    }

    protected function parseFallbackHtml(string $html, string $date, string $page): array
    {
        if (!preg_match('/<h1[^>]*>Resultado[^<]*JPS\s+(\d{2})\/(\d{2})\/(\d{4})/', $html, $m)) {
            return [];
        }

        $pageDate = "{$m[3]}-{$m[2]}-{$m[1]}";
        if ($pageDate !== $date) {
            return [];
        }

        return match ($page) {
            'nuevos-tiempos.php' => $this->parseFallbackNuevosTiempos($html, $date),
            '3-monazos.php' => $this->parseFallbackDaily($html, $date, 'Tres Monazos', true),
            'lotto.php' => $this->parseFallbackLotto($html, $date),
            'chances.php' => $this->parseFallbackSerie($html, $date, 'Chances'),
            'loteria-nacional.php' => $this->parseFallbackSerie($html, $date, 'Lotería Nacional'),
            default => [],
        };
    }

    protected function parseFallbackDaily(string $html, string $date, string $gameName, bool $threeDigits): array
    {
        $pairs = $this->extractFallbackPairs($html);
        if (empty($pairs)) {
            return [];
        }

        $drawNumbers = [];
        if ($threeDigits && preg_match_all('/(\d+(?:\|\d+)+)/', $html, $m)) {
            $drawNumbers = explode('|', end($m[1]));
        }

        $results = [];
        $index = 0;

        foreach ($pairs as [$label, $value]) {
            $drawTime = $this->fallbackTimeForLabel($label);
            if ($drawTime === null) {
                continue;
            }

            $numbers = $threeDigits
                ? $this->normalizeNumbers(explode(' ', $value))
                : [$this->normalizeNumber($value)];
            if (empty($numbers)) {
                continue;
            }

            $drawNumber = $drawNumbers[$index] ?? '';
            $index++;

            $key = $gameName . '|' . $date . '|' . $drawTime;
            $results[$key] = [
                'game_name' => $gameName,
                'draw_date' => $date,
                'draw_time' => $drawTime,
                'winning_numbers' => $numbers,
                'prizes' => null,
                'draw_number' => $drawNumber,
                'date_iso' => $date,
            ];
        }

        return $results;
    }

    /**
     * Fallback Nuevos Tiempos desde nacionalloteria.com (formato HTML con
     * columnas: Nº, Rev., Bolita (roja/blanca) y MgRev).
     */
    protected function parseFallbackNuevosTiempos(string $html, string $date): array
    {
        $results = [];

        if (!preg_match_all('/<tr>(.*?)<\/tr>/s', $html, $trMatches)) {
            return [];
        }

        foreach ($trMatches[1] as $row) {
            $label = '';
            if (preg_match('/<span class="label label-normal[^"]*"[^>]*>\s*([^<]*?)\s*<\/span>/', $row, $lm)) {
                $label = trim($lm[1]);
            }

            $drawTime = $this->fallbackTimeForLabel($label);
            if ($drawTime === null) {
                continue;
            }

            if (!preg_match_all('/<span class="label label-numero"[^>]*>\s*([^<]*?)\s*<\/span>/', $row, $nm)) {
                continue;
            }

            $nums = array_map('trim', $nm[1]);
            $nums = array_values(array_filter($nums, fn ($v) => $v !== ''));
            if (empty($nums)) {
                continue;
            }

            $numero = $this->normalizeNumber($nums[0] ?? '');
            $megan = $this->normalizeNumber($nums[1] ?? '');
            if ($numero === '') {
                continue;
            }

            $prizes = [];
            if ($megan !== '') {
                $prizes[] = ['position' => 'REVENTADO', 'number' => $megan, 'amount' => ''];
            }

            $esRoja = str_contains($row, 'label-bolita-roja');
            if ($esRoja || str_contains($row, 'label-bolita-blanca')) {
                $prizes[] = ['position' => 'BOLITA', 'number' => $esRoja ? 'Roja' : 'Blanca', 'amount' => ''];
            }

            $key = 'Nuevos Tiempos|' . $date . '|' . $drawTime;
            $results[$key] = [
                'game_name' => 'Nuevos Tiempos',
                'draw_date' => $date,
                'draw_time' => $drawTime,
                'winning_numbers' => [$numero],
                'prizes' => $prizes ?: null,
                'draw_number' => '',
                'date_iso' => $date,
            ];
        }

        return $results;
    }

    protected function parseFallbackLotto(string $html, string $date): array
    {
        $numbers = [];
        if (preg_match_all('/<span class="label label-numero"[^>]*>\s*([^<]*?)\s*<\/span>/', $html, $mm)) {
            $numbers = array_map('trim', $mm[1]);
            $numbers = array_values(array_filter($numbers, fn ($v) => $v !== ''));
        }

        $results = [];

        $lotto = $this->normalizeNumbers(array_slice($numbers, 0, 5));
        if (!empty($lotto)) {
            $results['Lotto|' . $date . '|7:30 PM'] = [
                'game_name' => 'Lotto',
                'draw_date' => $date,
                'draw_time' => '7:30 PM',
                'winning_numbers' => $lotto,
                'prizes' => null,
                'draw_number' => '',
                'date_iso' => $date,
            ];
        }

        $revancha = $this->normalizeNumbers(array_slice($numbers, 5, 5));
        if (!empty($revancha)) {
            $results['Lotto Revancha|' . $date . '|7:30 PM'] = [
                'game_name' => 'Lotto Revancha',
                'draw_date' => $date,
                'draw_time' => '7:30 PM',
                'winning_numbers' => $revancha,
                'prizes' => null,
                'draw_number' => '',
                'date_iso' => $date,
            ];
        }

        return $results;
    }

    protected function parseFallbackSerie(string $html, string $date, string $gameName): array
    {
        $pairs = $this->extractFallbackPairs($html);
        if (empty($pairs)) {
            return [];
        }

        $numero = '';
        $serie = '';

        foreach ($pairs as [$label, $value]) {
            if (stripos($label, 'primer premio') !== false || stripos($label, 'premio mayor') !== false) {
                $numero = $this->normalizeNumber($value);
            } elseif (stripos($label, 'serie') !== false) {
                $serie = $this->normalizeSerie(preg_replace('/[^0-9].*$/', '', trim($value)));
            }
        }

        if ($numero === '') {
            return [];
        }

        $numbers = $serie === '' ? [$numero] : [$numero, $serie];

        $drawNumber = '';
        if (preg_match('/Resultados de (?:Chances|Loteria Nacional) (\d+)/', $html, $m)) {
            $drawNumber = $m[1];
        }

        $key = $gameName . '|' . $date . '|7:30 PM';
        return [
            $key => [
                'game_name' => $gameName,
                'draw_date' => $date,
                'draw_time' => '7:30 PM',
                'winning_numbers' => $numbers,
                'prizes' => null,
                'draw_number' => $drawNumber,
                'date_iso' => $date,
            ],
        ];
    }

    protected function extractFallbackPairs(string $html): array
    {
        $pairs = [];
        if (preg_match_all(
            '/<span class="label label-normal[^"]*"[^>]*>\s*([^<]*?)\s*<\/span>\s*(?:<\/td>)?\s*(?:<td[^>]*>\s*)?<span class="label label-numero"[^>]*>\s*([^<]*?)\s*<\/span>/',
            $html,
            $mm,
            PREG_SET_ORDER
        )) {
            foreach ($mm as $x) {
                $label = trim($x[1]);
                $value = trim($x[2]);
                if ($label !== '' && $value !== '') {
                    $pairs[] = [$label, $value];
                }
            }
        }

        return $pairs;
    }

    protected function fallbackTimeForLabel(string $label): ?string
    {
        $normalized = mb_strtolower($label);

        if (str_contains($normalized, 'medio') || str_contains($normalized, 'mediodía')) {
            return '12:55 PM';
        }
        if (str_contains($normalized, 'tarde')) {
            return '4:30 PM';
        }
        if (str_contains($normalized, 'noche')) {
            return '7:30 PM';
        }

        return null;
    }

    protected function decodePayload(string $chunk): ?string
    {
        $decoded = json_decode('"' . $chunk . '"', false);
        if (is_string($decoded)) {
            return $decoded;
        }

        return stripcslashes($chunk);
    }

    protected function unescapeJsonString(string $text): string
    {
        $previous = null;
        while ($text !== $previous) {
            $previous = $text;
            $text = str_replace(['\\"', '\\\\'], ['"', '\\'], $text);
        }

        return $text;
    }

    protected function timeToDrawTime(string $time): ?string
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = $m[2];
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour % 12;
        if ($displayHour === 0) {
            $displayHour = 12;
        }

        return "{$displayHour}:{$minute} {$suffix}";
    }
}
