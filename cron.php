<?php
// ============================================================
// CRON JOB — Jalankan setiap jam oleh Railway
// Tugasnya: scraping rajabandot → simpan ke data.json
// ============================================================

class MacauScraper
{
    private string $url;
    private int    $timeout;
    public array   $timeSlots = ['00:01', '13:00', '16:00', '19:00', '22:00', '23:00'];

    public function __construct(string $url = 'https://rajabandot.com/history/result/m17/kosong', int $timeout = 15)
    {
        $this->url     = $url;
        $this->timeout = $timeout;
    }

    private function fetchHtml(): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,*/*;q=0.9', 'Accept-Language: id-ID,id;q=0.9', 'Referer: https://google.com/'],
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if (!$html || $code !== 200) throw new RuntimeException("HTTP {$code}. {$err}");
        return $html;
    }

    public function getLiveGrouped(): array
    {
        $html  = $this->fetchHtml();
        $dom   = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $rows  = $xpath->query('//table[contains(@class,"theTable")]//tbody/tr');
        $map   = []; $order = [];

        foreach ($rows as $row) {
            $cells = $xpath->query('td', $row);
            if ($cells->length < 3) continue;
            $datetime = trim($cells->item(1)->textContent);
            $nomor    = trim($cells->item(2)->textContent);
            if (!preg_match('/(\d{4}-\d{2}-\d{2})\s*\|\s*(\d{2}:\d{2})/', $datetime, $m)) continue;
            $key  = $m[1];
            $slot = $this->nearestSlot($m[2]);
            if (!isset($map[$key])) {
                $map[$key] = ['tanggal' => date('d M', strtotime($key)), 'tanggal_raw' => $key, 'slots' => array_fill_keys($this->timeSlots, '')];
                $order[] = $key;
            }
            if ($map[$key]['slots'][$slot] === '') $map[$key]['slots'][$slot] = $nomor;
        }

        usort($order, fn($a, $b) => strcmp($b, $a));
        return array_map(fn($k) => $map[$k], $order);
    }

    private function nearestSlot(string $jam): string
    {
        $in = $this->toMin($jam); $best = null; $diff = PHP_INT_MAX;
        foreach ($this->timeSlots as $slot) { $d = abs($in - $this->toMin($slot)); if ($d < $diff) { $diff = $d; $best = $slot; } }
        return ($diff <= 30) ? $best : $jam;
    }
    private function toMin(string $t): int { [$h, $m] = explode(':', $t); return (int)$h * 60 + (int)$m; }
}

class DataStore
{
    private string $file;
    private int    $maxDays;

    public function __construct(string $file, int $maxDays = 30)
    {
        $this->file    = $file;
        $this->maxDays = $maxDays;
    }

    public function read(): array
    {
        if (!file_exists($this->file)) return [];
        $data = json_decode(file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }

    public function merge(array $liveRows): void
    {
        $map = [];
        foreach ($this->read() as $row) $map[$row['tanggal_raw']] = $row;

        foreach ($liveRows as $liveRow) {
            $key = $liveRow['tanggal_raw'];
            if (!isset($map[$key])) {
                $map[$key] = $liveRow;
            } else {
                foreach ($liveRow['slots'] as $slot => $nomor) {
                    if ($nomor !== '') $map[$key]['slots'][$slot] = $nomor;
                }
            }
        }

        krsort($map);
        $map = array_slice($map, 0, $this->maxDays, true);
        file_put_contents($this->file, json_encode(array_values($map), JSON_PRETTY_PRINT));
    }
}

// ── JALANKAN ──
$store = new DataStore(__DIR__ . '/data.json', 30);
$time  = date('Y-m-d H:i:s');

try {
    $scraper  = new MacauScraper();
    $liveRows = $scraper->getLiveGrouped();
    $store->merge($liveRows);
    echo "[{$time}] OK — " . count($liveRows) . " tanggal diproses, data.json diperbarui.\n";
} catch (RuntimeException $e) {
    echo "[{$time}] ERROR — " . $e->getMessage() . "\n";
    exit(1);
}
