<?php

// ============================================================
// CLASS: MacauScraper
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

// ============================================================
// CLASS: DataStore — otomatis buat & update data.json
// ============================================================
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

    // Seed pertama kali jika data.json belum ada / kosong
    public function seedIfEmpty(array $seedData): void
    {
        if (!empty($this->read())) return;
        $map = [];
        foreach ($seedData as $row) $map[$row['tanggal_raw']] = $row;
        krsort($map);
        $map = array_slice($map, 0, $this->maxDays, true);
        file_put_contents($this->file, json_encode(array_values($map), JSON_PRETTY_PRINT));
    }

    // Merge data live ke JSON — data lama TIDAK hilang
    public function merge(array $liveRows): void
    {
        $map = [];
        foreach ($this->read() as $row) $map[$row['tanggal_raw']] = $row;

        foreach ($liveRows as $liveRow) {
            $key = $liveRow['tanggal_raw'];
            if (!isset($map[$key])) {
                // Tanggal baru → tambah
                $map[$key] = $liveRow;
            } else {
                // Tanggal sudah ada → update slot yang ada datanya
                foreach ($liveRow['slots'] as $slot => $nomor) {
                    if ($nomor !== '') $map[$key]['slots'][$slot] = $nomor;
                }
            }
        }

        // Urutkan terbaru di atas, simpan max 30 hari
        krsort($map);
        $map = array_slice($map, 0, $this->maxDays, true);
        file_put_contents($this->file, json_encode(array_values($map), JSON_PRETTY_PRINT));
    }

    public function getDisplay(): array
    {
        $data = $this->read();
        usort($data, fn($a, $b) => strcmp($b['tanggal_raw'], $a['tanggal_raw']));
        return array_slice($data, 0, $this->maxDays);
    }
}

// ============================================================
// DATA DUMMY — seed awal (hanya dipakai SEKALI saat data.json kosong)
// ============================================================
$slots = ['00:01', '13:00', '16:00', '19:00', '22:00', '23:00'];

$dummyData = [
    ['tanggal'=>'04 Mar','tanggal_raw'=>'2026-03-04','slots'=>['00:01'=>'0814','13:00'=>'3301','16:00'=>'','19:00'=>'','22:00'=>'','23:00'=>'']],
    ['tanggal'=>'03 Mar','tanggal_raw'=>'2026-03-03','slots'=>['00:01'=>'0073','13:00'=>'5723','16:00'=>'5166','19:00'=>'5066','22:00'=>'2360','23:00'=>'5170']],
    ['tanggal'=>'02 Mar','tanggal_raw'=>'2026-03-02','slots'=>['00:01'=>'3719','13:00'=>'4024','16:00'=>'1075','19:00'=>'1203','22:00'=>'1619','23:00'=>'3285']],
    ['tanggal'=>'01 Mar','tanggal_raw'=>'2026-03-01','slots'=>['00:01'=>'8297','13:00'=>'1029','16:00'=>'8578','19:00'=>'3670','22:00'=>'0797','23:00'=>'8174']],
    ['tanggal'=>'28 Feb','tanggal_raw'=>'2026-02-28','slots'=>['00:01'=>'3204','13:00'=>'4100','16:00'=>'2129','19:00'=>'9295','22:00'=>'8947','23:00'=>'9393']],
    ['tanggal'=>'27 Feb','tanggal_raw'=>'2026-02-27','slots'=>['00:01'=>'1033','13:00'=>'0316','16:00'=>'0960','19:00'=>'1038','22:00'=>'3057','23:00'=>'9808']],
    ['tanggal'=>'26 Feb','tanggal_raw'=>'2026-02-26','slots'=>['00:01'=>'1863','13:00'=>'2142','16:00'=>'4097','19:00'=>'8248','22:00'=>'3276','23:00'=>'2228']],
    ['tanggal'=>'25 Feb','tanggal_raw'=>'2026-02-25','slots'=>['00:01'=>'7885','13:00'=>'0886','16:00'=>'7800','19:00'=>'2808','22:00'=>'4719','23:00'=>'5070']],
    ['tanggal'=>'24 Feb','tanggal_raw'=>'2026-02-24','slots'=>['00:01'=>'3381','13:00'=>'1250','16:00'=>'0810','19:00'=>'8420','22:00'=>'1766','23:00'=>'4285']],
    ['tanggal'=>'23 Feb','tanggal_raw'=>'2026-02-23','slots'=>['00:01'=>'3814','13:00'=>'5095','16:00'=>'5426','19:00'=>'5922','22:00'=>'3520','23:00'=>'0808']],
    ['tanggal'=>'22 Feb','tanggal_raw'=>'2026-02-22','slots'=>['00:01'=>'7176','13:00'=>'0317','16:00'=>'1773','19:00'=>'7849','22:00'=>'7269','23:00'=>'6794']],
    ['tanggal'=>'21 Feb','tanggal_raw'=>'2026-02-21','slots'=>['00:01'=>'3930','13:00'=>'8680','16:00'=>'6328','19:00'=>'0970','22:00'=>'3665','23:00'=>'2618']],
    ['tanggal'=>'20 Feb','tanggal_raw'=>'2026-02-20','slots'=>['00:01'=>'6403','13:00'=>'2910','16:00'=>'3582','19:00'=>'3610','22:00'=>'6619','23:00'=>'0597']],
    ['tanggal'=>'19 Feb','tanggal_raw'=>'2026-02-19','slots'=>['00:01'=>'4448','13:00'=>'8139','16:00'=>'0315','19:00'=>'6202','22:00'=>'5036','23:00'=>'9489']],
    ['tanggal'=>'18 Feb','tanggal_raw'=>'2026-02-18','slots'=>['00:01'=>'9452','13:00'=>'3939','16:00'=>'0348','19:00'=>'9950','22:00'=>'5319','23:00'=>'4852']],
    ['tanggal'=>'17 Feb','tanggal_raw'=>'2026-02-17','slots'=>['00:01'=>'8075','13:00'=>'5226','16:00'=>'9965','19:00'=>'9593','22:00'=>'4980','23:00'=>'6449']],
    ['tanggal'=>'16 Feb','tanggal_raw'=>'2026-02-16','slots'=>['00:01'=>'8534','13:00'=>'7740','16:00'=>'9755','19:00'=>'3382','22:00'=>'9711','23:00'=>'6474']],
    ['tanggal'=>'15 Feb','tanggal_raw'=>'2026-02-15','slots'=>['00:01'=>'1659','13:00'=>'4920','16:00'=>'3403','19:00'=>'0226','22:00'=>'5551','23:00'=>'0928']],
    ['tanggal'=>'14 Feb','tanggal_raw'=>'2026-02-14','slots'=>['00:01'=>'1400','13:00'=>'1618','16:00'=>'3461','19:00'=>'2949','22:00'=>'1611','23:00'=>'2459']],
    ['tanggal'=>'13 Feb','tanggal_raw'=>'2026-02-13','slots'=>['00:01'=>'6385','13:00'=>'5070','16:00'=>'0146','19:00'=>'8178','22:00'=>'9219','23:00'=>'0946']],
    ['tanggal'=>'12 Feb','tanggal_raw'=>'2026-02-12','slots'=>['00:01'=>'1903','13:00'=>'4833','16:00'=>'3437','19:00'=>'9346','22:00'=>'8954','23:00'=>'9620']],
    ['tanggal'=>'11 Feb','tanggal_raw'=>'2026-02-11','slots'=>['00:01'=>'9294','13:00'=>'0565','16:00'=>'8568','19:00'=>'5594','22:00'=>'4623','23:00'=>'9639']],
    ['tanggal'=>'10 Feb','tanggal_raw'=>'2026-02-10','slots'=>['00:01'=>'2195','13:00'=>'5923','16:00'=>'3043','19:00'=>'7416','22:00'=>'2495','23:00'=>'2059']],
    ['tanggal'=>'09 Feb','tanggal_raw'=>'2026-02-09','slots'=>['00:01'=>'6425','13:00'=>'0249','16:00'=>'0091','19:00'=>'5390','22:00'=>'4990','23:00'=>'7747']],
    ['tanggal'=>'08 Feb','tanggal_raw'=>'2026-02-08','slots'=>['00:01'=>'0251','13:00'=>'4283','16:00'=>'3890','19:00'=>'1650','22:00'=>'5224','23:00'=>'0111']],
    ['tanggal'=>'07 Feb','tanggal_raw'=>'2026-02-07','slots'=>['00:01'=>'4764','13:00'=>'8958','16:00'=>'1625','19:00'=>'4181','22:00'=>'0551','23:00'=>'9404']],
    ['tanggal'=>'06 Feb','tanggal_raw'=>'2026-02-06','slots'=>['00:01'=>'8047','13:00'=>'1716','16:00'=>'0073','19:00'=>'1895','22:00'=>'3775','23:00'=>'5428']],
    ['tanggal'=>'05 Feb','tanggal_raw'=>'2026-02-05','slots'=>['00:01'=>'0932','13:00'=>'3300','16:00'=>'4933','19:00'=>'9922','22:00'=>'3397','23:00'=>'3507']],
    ['tanggal'=>'04 Feb','tanggal_raw'=>'2026-02-04','slots'=>['00:01'=>'0070','13:00'=>'7822','16:00'=>'2323','19:00'=>'7022','22:00'=>'9701','23:00'=>'0900']],
    ['tanggal'=>'03 Feb','tanggal_raw'=>'2026-02-03','slots'=>['00:01'=>'3659','13:00'=>'1139','16:00'=>'0647','19:00'=>'3262','22:00'=>'8953','23:00'=>'0403']],
];

// ============================================================
// MAIN
// ============================================================
$todayRaw  = date('Y-m-d');
$store     = new DataStore(__DIR__ . '/data.json', 30);
$liveError = null;

// 1. Buat data.json otomatis dari dummy jika belum ada
$store->seedIfEmpty($dummyData);

// 2. Scraping rajabandot → merge ke data.json (data lama aman)
try {
    $scraper  = new MacauScraper();
    $liveRows = $scraper->getLiveGrouped();
    $store->merge($liveRows);
} catch (RuntimeException $e) {
    $liveError = $e->getMessage();
}

// 3. Baca dari data.json untuk ditampilkan
$grouped = $store->getDisplay();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Draw Macau</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
  background: #0a0a0a;
  font-family: Arial, sans-serif;
  min-height: 100vh;
}

.header-top {
  width: 100%;
  background: linear-gradient(135deg, #0a0a0a 0%, #1a1400 40%, #0a0a0a 100%);
  border-bottom: 3px solid #c9a227;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 22px 30px;
  gap: 24px;
  position: relative;
  overflow: hidden;
}
.header-top::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(ellipse 80% 60% at 50% 50%, rgba(201,162,39,.12) 0%, transparent 70%);
  pointer-events: none;
}
.header-top .logo-icon {
  width: 1080px;
  height: 100px;
  object-fit: contain;
  filter: drop-shadow(0 0 18px rgba(201,162,39,.6));
  flex-shrink: 0;
  position: relative;
  z-index: 1;
}

.banner-wrap {
  background: #080808;
  text-align: center;
  padding: 12px 20px;
  border-bottom: 1px solid #1a1400;
}
.banner-wrap a img {
  max-width: 1080px;
  width: 100%;
  border-radius: 8px;
  cursor: pointer;
  display: inline-block;
}

.judul-bar {
  background: linear-gradient(90deg, #8b6914, #c9a227, #f5d87a, #c9a227, #8b6914);
  text-align: center;
  padding: 10px 16px;
}
.judul-bar h2 {
  color: #0a0a0a;
  font-size: 1rem;
  font-weight: 900;
  letter-spacing: .18em;
  text-transform: uppercase;
  text-shadow: 0 1px 2px rgba(255,255,255,.2);
}

table { width:100%; border-collapse:collapse; table-layout:fixed; }
thead tr { background: linear-gradient(180deg, #1a1400, #120e00); border-bottom: 2px solid #c9a227; }
thead th {
  color: #c9a227; font-size: .85rem; font-weight: 700;
  text-align: center; padding: 12px 6px; letter-spacing: .06em;
  border-right: 1px solid #1a1400;
}
thead th:last-child { border-right: none; }
thead th:first-child { width: 14%; }

tbody tr { border-bottom: 1px solid #111; transition: background .12s; }
tbody tr:nth-child(odd)  { background: #0d0d0d; }
tbody tr:nth-child(even) { background: #111008; }
tbody tr:hover           { background: #1a1500; }

tbody td {
  text-align: center; padding: 10px 6px;
  font-size: .92rem; color: #d4b54a; font-weight: 700;
  letter-spacing: .06em; border-right: 1px solid #111;
}
tbody td:last-child { border-right: none; }
tbody td:first-child { font-size: .84rem; color: #c9a227; }

tbody tr.live-row { background: #120e00 !important; }
tbody tr.live-row td { color: #f5d87a; }
tbody tr.live-row td:first-child::after {
  content: ' ●'; color: #e74c3c; font-size: .5rem;
  vertical-align: super; animation: blink 1s step-start infinite;
}

.kosong { color: #2a2200; font-weight: 400; }

.footer-bar {
  background: #080808; border-top: 1px solid #1a1400;
  text-align: right; padding: 7px 14px; font-size: .7rem; color: #3a2e00;
}
.footer-bar a { color: #3a2e00; text-decoration: none; }
.footer-bar a:hover { color: #c9a227; }

@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} }

@media (max-width:600px) {
  .header-top { padding: 16px 14px; gap: 14px; }
  .header-top .logo-icon { width: 70px; height: 70px; }
  thead th, tbody td { font-size: .72rem; padding: 8px 3px; }
}
</style>
</head>
<body>

  <div class="header-top">
    <img class="logo-icon" src="https://tabelhokiterus.com/logomacau.webp" alt="Macau">
  </div>

  <div class="banner-wrap">
    <a href="https://linkrjb.me/Gass" target="_blank">
      <img src="https://imgsaya3.io/images/2025/09/07/Comp-1_1-07.09.gif" alt="Banner">
    </a>
  </div>

  <div class="judul-bar">
    <h2>Hasil Toto Macau</h2>
  </div>

  <table>
    <thead>
      <tr>
        <th>Tanggal</th>
        <?php foreach ($slots as $s): ?><th><?= $s ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($grouped as $row): ?>
      <?php $isToday = ($row['tanggal_raw'] === $todayRaw); ?>
      <tr<?= $isToday ? ' class="live-row"' : '' ?>>
        <td><?= htmlspecialchars($row['tanggal']) ?></td>
        <?php foreach ($slots as $s): ?>
          <?php $num = $row['slots'][$s] ?? ''; ?>
          <td><?= $num !== '' ? htmlspecialchars($num) : '<span class="kosong">-</span>' ?></td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="footer-bar">
    Update: <?= date('d M Y H:i:s') ?> &nbsp;|&nbsp;
    Sumber: Luke Engine
  </div>

</body>
</html>
