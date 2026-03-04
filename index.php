<?php

// ============================================================
// CLASS: MacauScraper
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
            throw new RuntimeException("Gagal fetch. HTTP {$code}. {$err}");
        }
        return $html;
    }

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

            $raw[] = [
                'tanggal_raw' => $m[1],
                'tanggal'     => date('d M', strtotime($m[1])),
                'slot'        => $this->nearestSlot($m[2]),
                'nomor'       => $nomor,
            ];
        }
        return $raw;
    }

    public function getGroupedData(): array
    {
        $raw   = $this->parseRows($this->fetchHtml());
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

        usort($order, fn($a, $b) => strcmp($b, $a));
        $order = array_slice($order, 0, $this->maxDays);
        return array_map(fn($k) => $map[$k], $order);
    }

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
// JALANKAN
// ============================================================
$error   = null;
$grouped = [];
$slots   = [];

try {
    $scraper = new MacauScraper(maxDays: 30);
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
<title>Live Draw Macau</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    background: #0a0e1a;
    font-family: Arial, sans-serif;
    min-height: 100vh;
  }

  /* ── WRAPPER ── */
  .wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
  }

  /* ── HEADER / LOGO ── */
  .header-logo {
    background: #0a0e1a;
    text-align: center;
    padding: 18px 10px 10px;
  }

  .header-logo img {
    max-width: 320px;
    width: 60%;
  }

  /* ── JUDUL BAR ── */
  .judul-bar {
    background: #c0392b;
    text-align: center;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
  }

  .judul-bar h1 {
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
  }

  .judul-bar .days-pill {
    background: rgba(255,255,255,.2);
    color: #fff;
    font-size: .65rem;
    font-weight: 700;
    padding: 2px 10px;
    border-radius: 20px;
    letter-spacing: .08em;
  }

  /* ── TABLE ── */
  table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
  }

  /* Header row */
  thead tr {
    background: #1a2035;
  }

  thead th {
    color: #7ecfc8;
    font-size: .82rem;
    font-weight: 700;
    text-align: center;
    padding: 10px 4px;
    letter-spacing: .05em;
    border-bottom: 2px solid #c0392b;
  }

  thead th:first-child {
    width: 13%;
  }

  /* Body rows */
  tbody tr {
    border-bottom: 1px solid #151c2e;
    transition: background .15s;
  }

  tbody tr:nth-child(odd)  { background: #0d1220; }
  tbody tr:nth-child(even) { background: #111827; }
  tbody tr:hover           { background: #1a2540; }

  tbody td {
    text-align: center;
    padding: 9px 4px;
    font-size: .88rem;
    color: #7ecfc8;
    font-weight: 700;
    letter-spacing: .05em;
  }

  /* Tanggal kolom */
  tbody td:first-child {
    color: #7ecfc8;
    font-size: .82rem;
    font-weight: 700;
  }

  /* Baris terbaru */
  tbody tr:first-child td:first-child {
    position: relative;
  }
  tbody tr:first-child td:first-child::after {
    content: ' ●';
    color: #e74c3c;
    font-size: .55rem;
    vertical-align: super;
    animation: blink 1s step-start infinite;
  }

  /* Kosong */
  .kosong {
    color: #2a3550;
    font-size: .75rem;
    font-weight: 400;
  }

  /* ── FOOTER ── */
  .footer-bar {
    background: #0a0e1a;
    text-align: right;
    padding: 6px 12px;
    font-size: .68rem;
    color: #2a3550;
    border-top: 1px solid #151c2e;
  }

  .footer-bar a { color: #2a3550; text-decoration: none; }
  .footer-bar a:hover { color: #7ecfc8; }

  /* ── ERROR ── */
  .error-box {
    background: #1a0a0a;
    border: 1px solid #c0392b;
    color: #e74c3c;
    padding: 16px 20px;
    margin: 20px;
    border-radius: 6px;
    font-size: .88rem;
  }

  @keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

  /* Mobile */
  @media (max-width: 500px) {
    thead th, tbody td { font-size: .72rem; padding: 7px 2px; }
    .judul-bar h1 { font-size: .85rem; }
  }
</style>
</head>
<body>
<div class="wrap">

  <!-- Logo -->
  <div class="header-logo">
    <img src="https://tabelhokiterus.com/logomacau.webp" alt="Live Draw Macau">
  </div>

  <!-- Judul -->
  <div class="judul-bar">
    <h1>Hasil Toto Macau</h1>
    <span class="days-pill"><?= count($grouped) ?> Hari</span>
  </div>

  <?php if ($error): ?>
    <div class="error-box">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php else: ?>

  <!-- Tabel -->
  <table>
    <thead>
      <tr>
        <th>Tanggal</th>
        <?php foreach ($slots as $s): ?>
          <th><?= $s ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($grouped as $row): ?>
      <tr>
        <td><?= htmlspecialchars($row['tanggal']) ?></td>
        <?php foreach ($slots as $s): ?>
          <?php $num = $row['slots'][$s] ?? ''; ?>
          <td>
            <?php if ($num !== ''): ?>
              <?= htmlspecialchars($num) ?>
            <?php else: ?>
              <span class="kosong">-</span>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Footer -->
  <div class="footer-bar">
    Menampilkan 30 hari terakhir &nbsp;|&nbsp;
    Update: <?= date('d M Y H:i:s') ?> &nbsp;|&nbsp;
    Sumber: <a href="https://rajabandot.com" target="_blank">rajabandot.com</a>
  </div>

  <?php endif; ?>

</div>
</body>
</html>
