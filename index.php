<?php

// ============================================================
// CLASS: MacauScraper
// Ambil & parse data dari rajabandot.com
// ============================================================
class MacauScraper
{
    private string $url;
    private int    $timeout;
    private int    $maxDays;

    public array $timeSlots = ['00:01', '13:00', '16:00', '19:00', '22:00', '23:00'];

    public function __construct(
        string $url     = 'https://rajabandot.com/history/result/m17/kosong',
        int    $timeout = 15,
        int    $maxDays = 30
    ) {
        $this->url     = $url;
        $this->timeout = $timeout;
        $this->maxDays = $maxDays;
    }

    // ── Fetch HTML via cURL ──────────────────────────────────
    private function fetchHtml(): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL tidak tersedia di server ini.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                                    . 'AppleWebKit/537.36 (KHTML, like Gecko) '
                                    . 'Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,*/*;q=0.9',
                'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
                'Referer: https://google.com/',
            ],
        ]);

        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!$html || $code !== 200) {
            throw new RuntimeException("Gagal mengambil data. HTTP {$code}. {$err}");
        }

        return $html;
    }

    // ── Parse baris tabel ───────────────────────────────────
    private function parseRows(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $rows  = $xpath->query('//table[contains(@class,"theTable")]//tbody/tr');

        $raw = [];
        foreach ($rows as $row) {
            $cells = $xpath->query('td', $row);
            if ($cells->length < 3) continue;

            $datetime = trim($cells->item(1)->textContent);
            $nomor    = trim($cells->item(2)->textContent);

            if (!preg_match('/(\d{4}-\d{2}-\d{2})\s*\|\s*(\d{2}:\d{2})/', $datetime, $m)) continue;

            $tanggalRaw = $m[1];
            $jamRaw     = $m[2];
            $tanggal    = date('d M', strtotime($tanggalRaw));
            $slot       = $this->nearestSlot($jamRaw);

            $raw[] = [
                'tanggal_raw' => $tanggalRaw,
                'tanggal'     => $tanggal,
                'slot'        => $slot,
                'nomor'       => $nomor,
            ];
        }

        return $raw;
    }

    // ── Kelompokkan per tanggal, potong ke maxDays ──────────
    public function getGroupedData(): array
    {
        $html = $this->fetchHtml();
        $raw  = $this->parseRows($html);

        $map   = [];
        $order = [];

        foreach ($raw as $item) {
            $key = $item['tanggal_raw'];
            if (!isset($map[$key])) {
                $map[$key] = ['tanggal' => $item['tanggal'], 'slots' => []];
                $order[]   = $key;
            }
            if (!isset($map[$key]['slots'][$item['slot']])) {
                $map[$key]['slots'][$item['slot']] = $item['nomor'];
            }
        }

        // Urutkan terbaru di atas
        usort($order, fn($a, $b) => strcmp($b, $a));

        // ── Potong hanya 30 hari terakhir ──
        $order = array_slice($order, 0, $this->maxDays);

        return array_map(fn($k) => $map[$k], $order);
    }

    // ── Cocokkan jam ke slot terdekat (toleransi 30 menit) ──
    private function nearestSlot(string $jam): string
    {
        $in   = $this->toMin($jam);
        $best = null;
        $diff = PHP_INT_MAX;

        foreach ($this->timeSlots as $slot) {
            $d = abs($in - $this->toMin($slot));
            if ($d < $diff) { $diff = $d; $best = $slot; }
        }

        return ($diff <= 30) ? $best : $jam;
    }

    private function toMin(string $t): int
    {
        [$h, $m] = explode(':', $t);
        return (int)$h * 60 + (int)$m;
    }
}

// ============================================================
// JALANKAN — max 30 hari
// ============================================================
$error   = null;
$grouped = [];
$slots   = [];

try {
    $scraper = new MacauScraper(
        url:     'https://rajabandot.com/history/result/m17/kosong',
        timeout: 15,
        maxDays: 30   // ← ganti angka ini untuk ubah jumlah baris
    );
    $slots   = $scraper->timeSlots;
    $grouped = $scraper->getGroupedData();
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hasil Toto Macau</title>
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
        crossorigin="anonymous"
    >
    <style>
        #judul {
            background: #c0392b;
            color: #fff;
            font-size: 1.1rem;
            letter-spacing: .08em;
            padding: 10px;
        }

        thead tr.row-jam th {
            background: #343a40;
            color: #fff;
            text-align: center;
            font-size: .85rem;
            padding: 7px 4px;
        }
        thead tr.row-jam th:first-child {
            text-align: left;
            padding-left: 10px;
        }

        tbody tr td {
            text-align: center;
            vertical-align: middle;
            font-size: .9rem;
            padding: 6px 4px;
        }
        tbody tr td:first-child {
            text-align: left;
            font-weight: 600;
            padding-left: 10px;
            color: #212529;
            white-space: nowrap;
        }

        /* Baris hari ini highlight */
        tbody tr:first-child {
            background: #fff8e1;
        }
        tbody tr:first-child td:first-child::after {
            content: ' ●';
            color: #e74c3c;
            font-size: .6rem;
            vertical-align: super;
            animation: blink 1s step-start infinite;
        }

        .angka {
            font-weight: 700;
            color: #c0392b;
            letter-spacing: .05em;
        }

        .kosong {
            color: #ccc;
            font-size: .8rem;
        }

        .logo-wrap {
            text-align: center;
            padding: 12px 0 6px;
        }

        .update-info {
            font-size: .72rem;
            color: #6c757d;
            text-align: right;
            padding: 4px 10px 6px;
        }

        .error-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 14px 18px;
            margin: 16px;
            font-size: .9rem;
            color: #856404;
        }

        /* Badge jumlah hari */
        .days-badge {
            display: inline-block;
            background: rgba(255,255,255,.2);
            border-radius: 20px;
            font-size: .65rem;
            padding: 1px 8px;
            margin-left: 8px;
            vertical-align: middle;
            letter-spacing: .05em;
        }

        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }
    </style>
</head>
<body class="bg-light">

<div class="container-fluid p-0" style="max-width:760px; margin:0 auto;">
<table class="table table-sm table-bordered table-hover mb-0 bg-white shadow-sm">
    <thead>

        <!-- Logo -->
        <tr>
            <td colspan="7" class="logo-wrap p-2">
                <img
                    src="https://tabelhokiterus.com/logomacau.webp"
                    class="img-fluid"
                    style="max-width:65%;"
                    alt="Logo Toto Macau"
                >
            </td>
        </tr>

        <!-- Judul -->
        <tr>
            <th colspan="7" id="judul" class="text-center text-uppercase">
                Hasil Toto Macau
                <span class="days-badge"><?= count($grouped) ?> Hari</span>
            </th>
        </tr>

        <!-- Header kolom jam -->
        <tr class="row-jam">
            <th>Tanggal</th>
            <?php foreach ($slots as $s): ?>
                <th><?= htmlspecialchars($s) ?></th>
            <?php endforeach; ?>
        </tr>

    </thead>
    <tbody>

        <?php if ($error): ?>
        <tr>
            <td colspan="7">
                <div class="error-box">
                    ⚠️ <strong>Gagal mengambil data:</strong> <?= htmlspecialchars($error) ?><br>
                    <small>Pastikan server mendukung cURL dan dapat mengakses internet.</small>
                </div>
            </td>
        </tr>

        <?php else: ?>
        <?php foreach ($grouped as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['tanggal']) ?></td>
            <?php foreach ($slots as $s): ?>
                <?php $num = $row['slots'][$s] ?? ''; ?>
                <td>
                    <?php if ($num !== ''): ?>
                        <span class="angka"><?= htmlspecialchars($num) ?></span>
                    <?php else: ?>
                        <span class="kosong">-</span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>

    </tbody>
    <tfoot>
        <tr>
            <td colspan="7" class="update-info">
                Menampilkan 30 hari terakhir &nbsp;|&nbsp;
                Update: <?= date('d M Y H:i:s') ?> &nbsp;|&nbsp;
                Sumber: <a href="https://rajabandot.com/history/result/m17/kosong" target="_blank" class="text-muted">rajabandot.com</a>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
    crossorigin="anonymous">
</script>
</body>
</html>
