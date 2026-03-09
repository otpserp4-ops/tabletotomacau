<?php
// ============================================================
// CRON JOB — scraping rajabandot → simpan ke PostgreSQL
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
    private PDO $pdo;
    private int $maxDays;

    public function __construct(int $maxDays = 30)
    {
        $this->maxDays = $maxDays;
        $dsn = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL') ?: '';
        if (!$dsn) throw new RuntimeException("DATABASE_URL tidak ditemukan.");
        $dsn = preg_replace('/^postgres:\/\//', 'postgresql://', $dsn);
        $p   = parse_url($dsn);
        if (empty($p['host'])) throw new RuntimeException("Format DATABASE_URL tidak valid.");
        $dbname = ltrim($p['path'] ?? '/railway', '/');
        $port   = $p['port'] ?? 5432;
        $this->pdo = new PDO(
            "pgsql:host={$p['host']};port={$port};dbname={$dbname}",
            $p['user'] ?? '', $p['pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS macau_results (
                tanggal_raw DATE PRIMARY KEY,
                tanggal     VARCHAR(10) NOT NULL,
                slot_0001   VARCHAR(4) DEFAULT '',
                slot_1300   VARCHAR(4) DEFAULT '',
                slot_1600   VARCHAR(4) DEFAULT '',
                slot_1900   VARCHAR(4) DEFAULT '',
                slot_2200   VARCHAR(4) DEFAULT '',
                slot_2300   VARCHAR(4) DEFAULT '',
                updated_at  TIMESTAMP DEFAULT NOW()
            )
        ");
    }

    public function merge(array $liveRows): int
    {
        $count = 0;
        foreach ($liveRows as $row) {
            $s = $row['slots'];
            $stmt = $this->pdo->prepare("
                INSERT INTO macau_results
                    (tanggal_raw, tanggal, slot_0001, slot_1300, slot_1600, slot_1900, slot_2200, slot_2300)
                VALUES (:dr, :tgl, :s1, :s2, :s3, :s4, :s5, :s6)
                ON CONFLICT (tanggal_raw) DO UPDATE SET
                    slot_0001  = CASE WHEN EXCLUDED.slot_0001 <> '' THEN EXCLUDED.slot_0001 ELSE macau_results.slot_0001 END,
                    slot_1300  = CASE WHEN EXCLUDED.slot_1300 <> '' THEN EXCLUDED.slot_1300 ELSE macau_results.slot_1300 END,
                    slot_1600  = CASE WHEN EXCLUDED.slot_1600 <> '' THEN EXCLUDED.slot_1600 ELSE macau_results.slot_1600 END,
                    slot_1900  = CASE WHEN EXCLUDED.slot_1900 <> '' THEN EXCLUDED.slot_1900 ELSE macau_results.slot_1900 END,
                    slot_2200  = CASE WHEN EXCLUDED.slot_2200 <> '' THEN EXCLUDED.slot_2200 ELSE macau_results.slot_2200 END,
                    slot_2300  = CASE WHEN EXCLUDED.slot_2300 <> '' THEN EXCLUDED.slot_2300 ELSE macau_results.slot_2300 END,
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':dr'  => $row['tanggal_raw'],
                ':tgl' => $row['tanggal'],
                ':s1'  => $s['00:01'] ?? '',
                ':s2'  => $s['13:00'] ?? '',
                ':s3'  => $s['16:00'] ?? '',
                ':s4'  => $s['19:00'] ?? '',
                ':s5'  => $s['22:00'] ?? '',
                ':s6'  => $s['23:00'] ?? '',
            ]);
            $count++;
        }
        // Hapus data lebih dari 30 hari
        $this->pdo->exec("
            DELETE FROM macau_results WHERE tanggal_raw NOT IN (
                SELECT tanggal_raw FROM macau_results ORDER BY tanggal_raw DESC LIMIT {$this->maxDays}
            )
        ");
        return $count;
    }
}

// ── JALANKAN ──
$time = date('Y-m-d H:i:s');
try {
    $store    = new DataStore(30);
    $scraper  = new MacauScraper();
    $liveRows = $scraper->getLiveGrouped();
    $n        = $store->merge($liveRows);
    echo "[{$time}] OK — {$n} tanggal diproses, database diperbarui.\n";
} catch (Exception $e) {
    echo "[{$time}] ERROR — " . $e->getMessage() . "\n";
    exit(1);
}
