

**CRYPTO TRADING SYSTEM**  
Blueprint Teknis untuk Developer

| Versi 2.0 2026 — For Developer Use |
| :---: |

| MODEL 1 Counter Trend | MODEL 2 Pre-Pump | MODEL 3 Trend Momentum | MODEL 4 Spot Gainers |
| :---: | :---: | :---: | :---: |

# **0\. Gambaran Umum Sistem**

Dokumen ini adalah blueprint teknis untuk developer yang membangun sistem trading crypto otomatis di VPS. Terdapat 4 model trading yang berdiri sendiri dan TIDAK boleh digabungkan satu sama lain. Masing-masing model memiliki logika, sumber data, timeframe, dan kondisi entry/exit yang berbeda.

| Properti | Detail |
| :---- | :---- |
| Jumlah Model | 4 (Terpisah, tidak terintegrasi) |
| Data Sumber | Public API (CoinGecko, Binance Public, Coinalyze, CoinMarketCap, dll) |
| Bahasa Implementasi | Bebas (Python / Node.js direkomendasikan) |
| Platform | VPS Linux |
| Output | Top 10 koin per kategori beserta skor/sinyal |

**Catatan Penting:**

* Keempat model HARUS dijalankan dalam proses/service yang terpisah

* Setiap model menghasilkan list tersendiri (bukan satu list gabungan)

* Data derivatif (OI, Funding, CVD) berfungsi sebagai konfirmasi — bukan trigger utama

* Seluruh data yang digunakan adalah data publik, tidak memerlukan autentikasi berbayar

* Model 4 khusus SPOT trading — tidak ada posisi short, tidak ada leverage

| MODEL 1: COUNTER TREND Mia Style \+ Liquidity Sweep \+ Exhaustion Detection |
| :---: |

## **1.1 Deskripsi & Filosofi**

Model ini mendeteksi titik balik harga (reversal) ketika manipulasi harga bertemu dengan kejenuhan (exhaustion) tenaga pasar. Bukan mencari agresivitas, melainkan mencari titik di mana tekanan salah satu pihak telah mencapai batas maksimal dan mulai berbalik.

Filosofi inti: Smart money menyapu likuiditas (stop loss retail) sebelum membalik arah. Tugas sistem adalah mendeteksi momen setelah sapu tersebut.

## **1.2 Universe Koin**

| Parameter | Nilai |
| :---- | :---- |
| Target Koin | Altcoin volatile (rank 50-300 by market cap) |
| Filter Minimum | Volume 24h \> $5 juta, bukan stablecoin |
| Sumber List | CoinGecko /coins/markets?vs\_currency=usd\&order=market\_cap\_desc\&per\_page=300 |

## **1.3 Timeframe**

| Komponen | Timeframe |
| :---- | :---- |
| Screening Awal (Struktur) | 1H atau 4H |
| Konfirmasi Entry | 15M |
| Konteks Makro | 1D (opsional, untuk filter tren besar) |

## **1.4 Komponen Sinyal & Logika**

### **A. Price Action — Mia Style (Trigger Utama)**

Tiga kondisi ini harus terpenuhi secara berurutan:

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| Liquidity Sweep | Harga menembus Old High/Low atau Equal H/L, lalu berbalik | Wick melewati level, candle body kembali di dalam range | Harga OHLCV via CoinGecko /coins/{id}/ohlc atau Binance klines |
| MSS (Market Structure Shift) | Perubahan karakter tren mendadak dengan body break | Candle CLOSE menembus swing point berlawanan arah | Hitung swing high/low dari data OHLCV (library: ta-lib / pandas-ta) |
| FVG / OB Entry | Harga retrace ke area ketidakseimbangan setelah MSS | Fair Value Gap: gap antar 3 candle. Order Block: candle terakhir sebelum impulse move | Kalkulasi lokal dari OHLCV data |

### **B. Derivatif — Konfirmasi**

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| Open Interest | OI menurun saat harga sweep (exhaustion) | OI turun \>5% bersamaan dengan price spike | Coinalyze /futures/open-interest atau Bybit /v5/market/open-interest |
| Funding Rate | Funding sangat negatif/positif \= potensi reversal | Funding \< \-0.1% atau \> \+0.1% (threshold bisa dikalibrasi) | Coinalyze /futures/funding-rate |
| CVD (Cumulative Volume Delta) | CVD divergence dengan price \= konfirmasi exhaustion | Price buat new high tapi CVD turun (bearish div) | Hitung dari trade data: Binance /api/v3/trades (side beli vs jual) |

## **1.5 Scoring Model**

| Sinyal | Bobot | Catatan |
| :---- | :---- | :---- |
| Liquidity Sweep terkonfirmasi | 40% | Wajib ada, tanpa ini skip |
| MSS terbentuk | 30% | Wajib ada |
| FVG/OB sebagai entry | 15% | Opsional tapi menaikkan skor |
| OI menurun saat sweep | 8% | Konfirmasi derivatif |
| Funding Rate ekstrem | 7% | Konfirmasi derivatif |

| MODEL 2: PRE-PUMP DETECTOR Pressure Cooker \+ Momentum Runner |
| :---: |

## **2.1 Deskripsi & Filosofi**

Model ini mendeteksi koin yang sedang dalam fase 'kompresi' sebelum breakout besar. Tekanan dari short seller (funding negatif), volume yang mengering, dan volatilitas rendah menciptakan kondisi seperti panci bertekanan — ketika meledak, pergerakannya signifikan.

## **2.2 Universe Koin**

| Parameter | Nilai |
| :---- | :---- |
| Target Koin | Mid-cap (rank 20-150 by market cap) |
| Filter Minimum | Volume 24h \> $10 juta, ada futures market (OI tersedia) |
| Sumber List | CoinGecko /coins/markets?vs\_currency=usd\&order=market\_cap\_desc\&per\_page=150 |

## **2.3 Timeframe**

| Komponen | Timeframe |
| :---- | :---- |
| Screening Funding Rate | Real-time / 8 jam |
| Screening OI & Volume | 4H |
| Entry Confirmation | 1H |

## **2.4 Komponen Sinyal & Logika**

### **A. Funding Rate Squeeze**

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| Funding Negatif Persisten | Short seller mendominasi, potensi short squeeze | Funding \< \-0.05% per 8 jam selama 3 periode berturut | Coinalyze /futures/funding-rate |
| OI Naik \+ Harga Sideways | Posisi bertambah tapi harga tidak naik \= bom waktu | OI naik \>10% dalam 24 jam, harga dalam range \<3% | Coinalyze /futures/open-interest |
| ATR Rendah | Volatilitas menyempit \= sebelum ledakan | ATR 14 periode di bawah rata-rata 30 hari | Hitung dari OHLCV data (ta-lib) |

### **B. Momentum Runner**

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| Volume Kering | Volume turun drastis \= akumulasi diam | Volume 24h turun \>50% dari 7-day average | CoinGecko /coins/{id}/market\_chart |
| CVD Divergence | CVD diam-diam naik saat volume turun \= akumulasi | CVD trend naik dalam 24H meski price flat | Binance public trade data |
| RSI Compression | RSI terperangkap di zona netral \= siap breakout | RSI 14 antara 45-55 selama \>5 candle 4H | Hitung dari OHLCV (pandas-ta) |

## **2.5 Scoring Model**

| Sinyal | Bobot | Catatan |
| :---- | :---- | :---- |
| Funding Rate negatif persisten | 35% | Trigger utama |
| OI naik \+ price sideways | 25% | Konfirmasi akumulasi |
| ATR rendah (kompresi volatilitas) | 20% | Semakin rendah semakin baik |
| CVD diam-diam naik | 12% | Sinyal akumulasi tersembunyi |
| RSI Compression | 8% | Konfirmasi teknikal |

| MODEL 3: TREND MOMENTUM MACD-RSI-EMA Confirmation System |
| :---: |

## **3.1 Deskripsi & Filosofi**

Model ini mendeteksi koin yang sudah dalam tren kuat dan masih memiliki 'kaki' untuk lanjut naik. Tidak menangkap bottom, tapi mengikuti momentum yang sudah terbukti. Entry dilakukan setelah konfirmasi struktur tren, bukan spekulasi.

## **3.2 Universe Koin**

| Parameter | Nilai |
| :---- | :---- |
| Target Koin | Large-cap (rank 1-50 by market cap), plus BTC/ETH selalu masuk |
| Filter Minimum | Volume 24h \> $50 juta, sudah listed minimal 6 bulan |
| Sumber List | CoinGecko /coins/markets?vs\_currency=usd\&order=market\_cap\_desc\&per\_page=50 |

## **3.3 Timeframe**

| Komponen | Timeframe |
| :---- | :---- |
| Trend Filter (EMA) | 1D |
| Sinyal Entry (MACD \+ RSI) | 4H |
| Konfirmasi BOS | 1D atau 4H |

## **3.4 Komponen Sinyal & Logika**

### **A. EMA Filter (Trend Direction)**

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| EMA 50 & 200 | Harga di atas kedua EMA \= tren bullish | Close \> EMA50 \> EMA200, jarak EMA melebar | Hitung dari OHLCV daily (ta-lib / pandas-ta) |
| EMA Slope | EMA naik \= tren sehat | Slope EMA50 positif minimal 3 hari berturut | Hitung delta EMA per periode |

### **B. RSI & MACD Synergy**

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| RSI Momentum Zone | RSI 50-65 \= momentum kuat tanpa overbought | RSI 14 berada di range 50-65 pada 4H | pandas-ta / ta-lib dari OHLCV |
| MACD Confirmation | MACD line di atas signal, keduanya di atas 0 | MACD \> Signal \> 0, histogram positif dan membesar | Hitung MACD (12,26,9) dari close price |
| BOS (Break of Structure) | Koin terus buat higher high \= struktur tren valid | Close 4H menembus swing high sebelumnya | Deteksi swing high dari OHLCV lokal |

### **C. Derivatif — Konfirmasi Kesehatan Tren**

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| OI Naik \+ Harga Naik | Ada uang baru masuk \= tren sehat | OI dan harga naik bersamaan \>5% dalam 24H | Coinalyze /futures/open-interest |
| CVD Positif | Agresivitas pembeli mendominasi | CVD 24H trending positif | Binance trade data |

## **3.5 Scoring Model**

| Sinyal | Bobot | Catatan |
| :---- | :---- | :---- |
| EMA Filter terpenuhi (price \> EMA50 \> EMA200) | 30% | Gate — jika tidak terpenuhi, skip |
| MACD di zona positif | 25% | Konfirmasi momentum |
| RSI di zona 50-65 | 20% | Quality filter |
| BOS terkonfirmasi | 15% | Struktur tren valid |
| OI \+ CVD positif | 10% | Konfirmasi derivatif |

| MODEL 4: SPOT MOMENTUM GAINERS CMC Top Gainers \+ Candle \+ Volume Screening |
| :---: |

## **4.1 Deskripsi & Filosofi**

Model ini adalah strategi spot trading murni yang memanfaatkan momentum jangka pendek. Tidak ada short, tidak ada leverage. Logikanya sederhana: koin yang masuk top gainers 24 jam dengan konfirmasi candle bullish kuat dan volume besar kemungkinan besar sedang dalam momentum yang bisa berlanjut beberapa hari ke depan.

Filosofi inti: Ikuti momentum yang sudah terbukti hari ini. Masuk dengan risiko terukur, keluar disiplin saat momentum berbalik atau target tercapai.

## **4.2 Universe Koin**

| Parameter | Nilai |
| :---- | :---- |
| Target Koin | Top 200 by market cap (filter di CoinMarketCap) |
| Minimum Filter | Market cap \> $100 juta, bukan stablecoin, bukan wrapped token |
| Sumber List | CoinMarketCap API /v1/cryptocurrency/listings/latest?limit=200\&sort=market\_cap |
| Sorted By | 24h percentage change (descending) — ambil top 10 terbesar |

## **4.3 Timeframe**

| Komponen | Timeframe |
| :---- | :---- |
| Screening Gainers | 24H (setiap pagi, sekitar jam 07:00 WIB) |
| Validasi Candle \+ Volume | 1D (Daily chart) |
| Hold Period | Beberapa hari (swing pendek) |

## **4.4 Alur Kerja Sistem**

### **Step 01 — Ambil Top Gainers**

| Komponen | Deskripsi | Cara Deteksi | Sumber Data |
| :---- | :---- | :---- | :---- |
| Data yang Diambil | Daftar koin top 200 market cap, sorted 24h% descending | Ambil 10 koin dengan 24h% tertinggi | CoinMarketCap /v1/cryptocurrency/listings/latest atau CoinGecko /coins/markets?order=percent\_change\_24h\_desc |

### **Step 02 — Validasi Candle Bullish (1D)**

Setiap koin dari top 10 harus divalidasi satu per satu di chart 1 hari. Sebuah setup dianggap valid jika SEMUA kondisi berikut terpenuhi:

| Kriteria | Definisi | Cara Deteksi Sistem |
| :---- | :---- | :---- |
| Candle Hijau | Candle close lebih tinggi dari open | close \> open pada candle harian terakhir |
| Body Besar | Body candle minimal 60% dari total range (high-low) | (close \- open) / (high \- low) \>= 0.6 |
| Wick Atas Minimal | Upper wick tidak lebih dari 20% dari body | (high \- close) / (close \- open) \<= 0.2 |
| Close \> High Sebelumnya | Close candle hari ini melewati high candle kemarin | close\[hari\_ini\] \> high\[hari\_kemarin\] |
| Volume Besar | Volume hari ini lebih besar dari rata-rata 5 bar sebelumnya | volume\[hari\_ini\] \> mean(volume\[hari\_ini-5 : hari\_ini-1\]) |

Jika semua 5 kriteria terpenuhi: aset masuk watchlist entry. Jika ada satu saja yang tidak terpenuhi: skip, lanjut ke koin berikutnya.

Jika dari 10 koin tidak ada yang lolos: jadwal screening diulang keesokan harinya. Tidak ada entry paksa.

### **Step 03 — Entry & Stop Loss**

| Parameter | Nilai / Cara Hitung |
| :---- | :---- |
| Waktu Entry | Pagi hari setelah screening selesai (\~07:15-07:30 WIB) |
| Tipe Order | Market order atau limit order dekat harga close candle |
| Stop Loss | Di bawah low candle bullish hari itu (low candle trigger) |
| Sizing Posisi | Hitung dari risiko per trade yang ditetapkan user (misal 1-2% modal) |
| Formula Sizing | Jumlah unit \= (modal × risk%) / (entry price \- stop loss price) |

### **Step 04 — Exit Management**

| Kondisi Exit | Aksi | Catatan |
| :---- | :---- | :---- |
| Profit sudah \+2R | Exit sebagian atau full | 2R \= profit 2x besarnya risiko yang diambil |
| Terbentuk candle bearish | Exit segera | Kebalikan kriteria bullish: body merah besar, close \< low sebelumnya |
| Trailing stop loss | Geser SL ke bawah low baru | Setiap kali harga buat higher low, pindahkan SL ke sana |
| Tidak ada perubahan | Hold | Cek lagi esok hari pagi |

## **4.5 Definisi Candle Bearish (Exit Trigger)**

Candle bearish dianggap valid sebagai sinyal exit jika SEMUA kondisi ini terpenuhi:

* Candle merah (close \< open)

* Body besar: (open \- close) / (high \- low) \>= 0.6

* Close lebih rendah dari low candle sebelumnya

* Volume lebih besar dari rata-rata 5 bar sebelumnya (opsional, menguatkan sinyal)

## **4.6 Sumber Data API**

| Data | Endpoint | Library |
| :---- | :---- | :---- |
| Top 200 Market Cap \+ 24h% | CoinMarketCap /v1/cryptocurrency/listings/latest?limit=200 | requests / axios |
| OHLCV Daily | Binance /api/v3/klines?symbol=XYZUSDT\&interval=1d\&limit=10 | ccxt / axios |
| OHLCV Alternatif | CoinGecko /coins/{id}/ohlc?vs\_currency=usd\&days=30 | requests |
| Volume Historis | CoinGecko /coins/{id}/market\_chart?vs\_currency=usd\&days=14 | requests |

## **4.7 Pseudocode Logika Inti**

**Berikut pseudocode untuk diimplementasikan developer:**

**SETIAP HARI JAM 07:00 WIB:**

1\. Ambil top\_gainers \= top 10 dari /listings/latest sorted 24h\_change desc

2\. Filter: market\_cap \> 100M, bukan stablecoin/wrapped

3\. Untuk setiap koin di top\_gainers:

   a. Ambil 7 candle daily terakhir (OHLCV)

   b. candle\_today \= candle\[-1\], candle\_prev \= candle\[-2\]

   c. Cek kriteria bullish:

      \- close \> open  (candle hijau)

      \- body\_ratio \= (close-open)/(high-low) \>= 0.6

      \- upper\_wick\_ratio \= (high-close)/(close-open) \<= 0.2

      \- close \> candle\_prev.high  (breakout)

      \- volume \> mean(volume\[-6:-1\])  (volume spike)

   d. Jika semua terpenuhi: masukkan ke watchlist\_entry

4\. Output watchlist\_entry ke notifikasi / dashboard

5\. Catat stop\_loss \= low candle\_today untuk setiap entry

## **4.8 Scoring & Output**

| Komponen | Bobot | Catatan |
| :---- | :---- | :---- |
| Semua 5 kriteria candle terpenuhi | Gate (wajib) | Tidak terpenuhi \= tidak masuk output sama sekali |
| Besarnya 24h% change | 40% | Semakin besar, semakin prioritas |
| Rasio volume spike | 35% | volume\_today / avg\_volume\_5\_hari — semakin tinggi semakin baik |
| Rasio body candle | 25% | Semakin besar body, semakin kuat momentum |

## **4.9 Perbedaan dengan Model Lain**

| Aspek | Model 1 | Model 2 | Model 3 | Model 4 |
| :---- | :---- | :---- | :---- | :---- |
| **Tipe trade** | Reversal | Pre-breakout | Trend following | Momentum spot |
| **Arah posisi** | Long/Short | Long/Short | Long/Short | Long only (SPOT) |
| **Universe** | Rank 50-300 | Rank 20-150 | Rank 1-50 | Rank 1-200 (top gainers) |
| **Timeframe utama** | 15M-4H | 4H-1D | 4H-1D | 1D |
| **Leverage** | Opsional | Opsional | Opsional | Tidak (spot only) |
| **Frekuensi signal** | Beberapa/hari | Beberapa/minggu | Beberapa/minggu | 1x/hari (pagi) |
| **Hold period** | Jam-hari | Hari-minggu | Minggu | Hari |

# **5\. Sumber Data & API Endpoints**

| Model | Provider | Endpoint | Data |
| :---- | :---- | :---- | :---- |
| Model 1-3 | CoinGecko | /coins/markets | List koin \+ market cap \+ volume |
| Model 1-4 | Binance | /api/v3/klines | OHLCV semua timeframe |
| Model 1-3 | Coinalyze | /futures/open-interest | Open Interest per koin |
| Model 1-3 | Coinalyze | /futures/funding-rate | Funding rate historis |
| Model 1-3 | Binance | /api/v3/trades | Raw trades (untuk CVD) |
| Model 4 | CoinMarketCap | /v1/cryptocurrency/listings/latest | Top gainers 24h |
| Model 4 | CoinGecko | /coins/{id}/ohlc | OHLCV alternatif daily |

## **5.1 Library Rekomendasi**

| Library | Kegunaan |
| :---- | :---- |
| pandas-ta / ta-lib | Kalkulasi EMA, RSI, MACD, ATR, Swing H/L |
| ccxt | Unified interface untuk OHLCV dari berbagai exchange |
| requests / axios | HTTP calls ke REST API |
| APScheduler (Python) | Jadwal task harian (Model 4 jam 07:00 WIB) |
| node-cron (Node.js) | Alternatif scheduler untuk Node.js |

# **6\. Arsitektur VPS**

## **6.1 Service Structure**

| Service | Fungsi | Jadwal Run |
| :---- | :---- | :---- |
| service\_model1.py | Counter Trend Scanner | Setiap 15 menit |
| service\_model2.py | Pre-Pump Detector | Setiap 4 jam |
| service\_model3.py | Trend Momentum Scanner | Setiap 4 jam |
| service\_model4.py | Spot Gainers Scanner | Setiap hari jam 07:00 WIB |
| notifier.py | Kirim alert ke Telegram/Discord | Dipanggil oleh masing-masing service |

## **6.2 Output Format Standar (JSON)**

Setiap service menghasilkan output JSON dengan format berikut:

{

  "model": "model4\_spot\_gainers",

  "timestamp": "2026-05-04T07:00:00+07:00",

  "results": \[

    {

      "rank": 1,

      "symbol": "AKT",

      "price": 0.6327,

      "change\_24h": 14.22,

      "volume\_ratio": 3.8,

      "body\_ratio": 0.82,

      "stop\_loss": 0.5642,

      "score": 87.5

    }

  \]

}

# **7\. Developer Checklist**

## **Infrastruktur**

* VPS Linux (Ubuntu 20.04+ atau Debian) dengan Python 3.9+ atau Node.js 18+

* Install library: pandas-ta, ccxt, requests, APScheduler (Python) atau ccxt, axios, node-cron (Node.js)

* Buat file .env untuk API keys (CoinMarketCap, Coinalyze jika premium)

* Setup cron/scheduler: Model 4 jam 07:00 WIB, Model 2-3 setiap 4 jam, Model 1 setiap 15 menit

* Siapkan notifier (Telegram bot atau Discord webhook)

## **Model 4 — Spot Gainers (Checklist Spesifik)**

* Implementasi fetcher: ambil 200 koin dari CMC atau CoinGecko, sort 24h% desc, ambil top 10

* Filter: exclude stablecoin (USDT, USDC, DAI, BUSD, dll), wrapped token (WBTC, WETH, dll), market cap \< $100 juta

* Untuk setiap koin: ambil 7 candle daily dari Binance /api/v3/klines

* Implementasi 5 kriteria candle bullish (lihat Section 4.4)

* Hitung volume ratio: volume\_today / mean(volume\[-6:-1\])

* Hitung body ratio: (close-open) / (high-low)

* Hitung upper wick ratio: (high-close) / (close-open)

* Output: symbol, price, 24h%, volume\_ratio, body\_ratio, stop\_loss, score

* Stop loss \= low candle trigger (low candle harian)

* Kirim notifikasi jika ada 1+ koin lolos kriteria

* Kirim notifikasi 'Tidak ada setup hari ini' jika 0 koin lolos

## **Model 1-3 (Checklist Umum)**

* Implementasi kalkulasi Swing High/Low (rolling window min 5 candle)

* Implementasi deteksi Liquidity Sweep (wick lewat level, body kembali)

* Implementasi deteksi MSS (close menembus swing point berlawanan)

* Implementasi EMA 50 & 200 (daily) untuk Model 3

* Implementasi MACD (12,26,9) dan RSI (14) via pandas-ta

* Koneksi ke Coinalyze untuk OI dan Funding Rate

* Implementasi CVD dari Binance raw trade data

* Scoring bobot sesuai Section 1.5, 2.5, 3.5

* Rate limiting: jangan hit API lebih dari 10 req/detik

* Cache data OHLCV selama 5 menit untuk menghindari redundan request

| SECTION 8: FILTER PIPELINE ARCHITECTURE Shared Pre-Filter \+ Model-Specific Secondary Filter \+ OHLCV Cache |
| :---: |

# **8\. Arsitektur Filter Pipeline**

Setiap model TIDAK melakukan fetch coin universe sendiri-sendiri. Pendekatan tersebut menghasilkan 4× redundant API call untuk data yang sebetulnya identik. Sistem menggunakan 3-layer pipeline:

| Layer | Nama | Frekuensi | Output |
| :---- | :---- | :---- | :---- |
| Layer 1 | Shared Fetch | 1× per run, cache 5 menit | Raw list top 300 koin (market cap, volume, 24h%) |
| Layer 2 | Shared Pre-Filter | Langsung setelah Layer 1 | \~150–200 koin lolos (buang stablecoin, volume rendah, dll) |
| Layer 3 | Model Secondary Filter | Per model dari hasil Layer 2 | Tiap model dapat subset 10–80 koin sesuai karakternya |
| Layer 4 | Heavy Analysis | Per model, hanya untuk subset | OHLCV, OI, Funding, CVD — API call mahal, dikerjakan terakhir |

![4-layer filter pipeline architecture for 4 trading models][image1]

Prinsip utama: API call yang mahal (OHLCV, OI, Funding) hanya dipanggil setelah coin lolos Layer 2 dan Layer 3\. Coin yang sama di dua model berbeda cukup di-fetch OHLCV-nya sekali (in-memory cache).

## **8.1  Layer 1 — Shared Fetch**

Satu endpoint dipanggil sekali, hasilnya di-share ke semua model dan di-cache agar tidak dipanggil ulang dalam 5 menit.

| Parameter | Nilai |
| :---- | :---- |
| Endpoint | CoinGecko: GET /coins/markets |
| Query params | vs\_currency=usd, order=market\_cap\_desc, per\_page=300, page=1, sparkline=false, price\_change\_percentage=24h |
| Data diambil | id, symbol, name, current\_price, market\_cap, market\_cap\_rank, total\_volume, price\_change\_percentage\_24h |
| Cache TTL | 300 detik (5 menit). Jika cache masih valid, skip API call |
| Rate limit | CoinGecko free tier: 10–30 req/menit. Shared fetch hanya 1 req, aman |
| Fallback | Jika CoinGecko gagal: gunakan Binance /api/v3/ticker/24hr untuk volume \+ price change |

**\# Python — shared\_fetch.py**  
import requests, time

\_cache \= {'data': None, 'ts': 0}

CACHE\_TTL \= 300

def get\_market\_data() \-\> list\[dict\]:

    now \= time.time()

    if \_cache\['data'\] and (now \- \_cache\['ts'\]) \< CACHE\_TTL:

        return \_cache\['data'\]

    url \= 'https://api.coingecko.com/api/v3/coins/markets'

    params \= {'vs\_currency':'usd','order':'market\_cap\_desc','per\_page':300,

              'page':1,'sparkline':False,'price\_change\_percentage':'24h'}

    resp \= requests.get(url, params=params, timeout=10)

    resp.raise\_for\_status()

    \_cache\['data'\] \= resp.json()

    \_cache\['ts'\]   \= now

    return \_cache\['data'\]

## **8.2  Layer 2 — Shared Pre-Filter**

Filter universal yang berlaku untuk SEMUA model. Dijalankan tepat setelah Layer 1, hasilnya dibagi ke tiap model.

| Kriteria | Rule | Alasan |
| :---- | :---- | :---- |
| Bukan stablecoin | symbol tidak ada di blacklist stablecoin | USDT/USDC/DAI/BUSD/TUSD/FRAX tidak memiliki price action yang relevan |
| Bukan wrapped token | name tidak mengandung 'Wrapped'/'wBTC'/'wETH' | Pergerakan harganya identik dengan underlying asset |
| Volume minimum | total\_volume \>= 1.000.000 USD | Coin di bawah $1M terlalu illiquid, spread besar, manipulasi mudah |
| Market cap minimum | market\_cap \>= 50.000.000 USD | Coin di bawah $50M sangat berisiko, data derivatif jarang tersedia |
| Data lengkap | current\_price dan total\_volume tidak null/0 | Beberapa koin baru di CoinGecko belum memiliki data lengkap |

**\# Python — pre\_filter.py**  
STABLECOINS \= {'usdt','usdc','dai','busd','tusd','frax','usdd','usdp','gusd','lusd'}

WRAPPED\_KW   \= \['wrapped','wbtc','weth','steth','reth','cbeth'\]

def pre\_filter(coins: list\[dict\]) \-\> list\[dict\]:

    result \= \[\]

    for c in coins:

        sym  \= c\['symbol'\].lower()

        name \= c\['name'\].lower()

        if sym in STABLECOINS: continue

        if any(kw in name for kw in WRAPPED\_KW): continue

        if (c.get('total\_volume') or 0\)  \< 1\_000\_000: continue

        if (c.get('market\_cap')   or 0\) \< 50\_000\_000: continue

        if not c.get('current\_price'): continue

        result.append(c)

    return result  \# \~150–200 koin dari top 300

## **8.3  Layer 3 — Model Secondary Filter**

Tiap model punya filter tambahan sesuai karakteristik strateginya, dijalankan di dalam masing-masing service menggunakan data hasil Layer 2\.

| Model | Filter Tambahan | Target Jumlah Coin |
| :---- | :---- | :---- |
| Model 1 | market\_cap\_rank antara 50–300  |  total\_volume \>= 5.000.000 USD | \~50–80 koin |
| Model 2 | market\_cap\_rank antara 20–150  |  total\_volume \>= 10.000.000 USD | \~40–60 koin |
| Model 3 | market\_cap\_rank \<= 50  |  total\_volume \>= 50.000.000 USD | \~30–40 koin |
| Model 4 | market\_cap\_rank \<= 200  |  sort price\_change\_24h descending  |  ambil top 10 | 10 koin (fixed) |

**\# Python — model\_filters.py**  
def filter\_model1(coins): return \[c for c in coins

    if 50 \<= c.get('market\_cap\_rank',999) \<= 300

    and (c.get('total\_volume') or 0\) \>= 5\_000\_000\]

def filter\_model2(coins): return \[c for c in coins

    if 20 \<= c.get('market\_cap\_rank',999) \<= 150

    and (c.get('total\_volume') or 0\) \>= 10\_000\_000\]

def filter\_model3(coins): return \[c for c in coins

    if c.get('market\_cap\_rank',999) \<= 50

    and (c.get('total\_volume') or 0\) \>= 50\_000\_000\]

def filter\_model4(coins):

    eligible \= \[c for c in coins if c.get('market\_cap\_rank',999) \<= 200\]

    return sorted(eligible,

        key=lambda c: c.get('price\_change\_percentage\_24h') or 0,

        reverse=True)\[:10\]

## **8.4  Layer 4 — OHLCV In-Memory Cache**

Jika coin yang sama muncul sebagai kandidat di dua model berbeda, OHLCV-nya tidak boleh di-fetch dua kali. Gunakan shared in-memory cache dengan key symbol\_interval.

| Cache Key Format | Contoh | Keterangan |
| :---- | :---- | :---- |
| symbol\_interval | BTC\_1d | Daily candle untuk Model 3 dan Model 4 |
| symbol\_interval | AKT\_4h | 4H candle untuk Model 1 dan 2 |
| symbol\_interval | AKT\_15m | 15M candle untuk Model 1 entry confirmation |
| symbol\_oi | AKT\_oi | Open Interest — cache by symbol |
| symbol\_fr | AKT\_fr | Funding Rate — cache by symbol |

**\# Python — ohlcv\_cache.py**  
import requests, time

\_ohlcv\_cache: dict \= {}

OHLCV\_TTL \= 300

def get\_ohlcv(symbol: str, interval: str, limit: int \= 10\) \-\> list:

    key \= f'{symbol.upper()}\_{interval}'

    now \= time.time()

    cached \= \_ohlcv\_cache.get(key)

    if cached and (now \- cached\['ts'\]) \< OHLCV\_TTL:

        return cached\['data'\]

    url    \= 'https://api.binance.com/api/v3/klines'

    params \= {'symbol': f'{symbol.upper()}USDT', 'interval': interval, 'limit': limit}

    resp   \= requests.get(url, params=params, timeout=8)

    resp.raise\_for\_status()

    \_ohlcv\_cache\[key\] \= {'data': resp.json(), 'ts': now}

    return \_ohlcv\_cache\[key\]\['data'\]

def parse\_candles(raw: list) \-\> list\[dict\]:

    return \[{'open':float(c\[1\]),'high':float(c\[2\]),

             'low':float(c\[3\]),'close':float(c\[4\]),'volume':float(c\[5\])}

            for c in raw\]

## **8.5  Pipeline Orchestrator**

Main runner yang merakit semua layer. Tiap model service memanggil fungsi run\_pipeline(model=N) ini — tidak ada yang fetch sendiri-sendiri.

**\# Python — pipeline.py**  
from shared\_fetch  import get\_market\_data

from pre\_filter    import pre\_filter

from model\_filters import filter\_model1, filter\_model2, filter\_model3, filter\_model4

from ohlcv\_cache   import get\_ohlcv, parse\_candles

from analysis      import analyze\_model1, analyze\_model2, analyze\_model3, analyze\_model4

def run\_pipeline(model: int) \-\> list\[dict\]:

    \# Layer 1 — shared fetch (cache otomatis)

    raw\_coins   \= get\_market\_data()

    \# Layer 2 — shared pre-filter

    clean\_coins \= pre\_filter(raw\_coins)

    \# Layer 3 — model-specific secondary filter

    filters     \= {1: filter\_model1, 2: filter\_model2,

                   3: filter\_model3, 4: filter\_model4}

    candidates  \= filters\[model\](clean\_coins)

    \# Layer 4 — heavy analysis, hanya untuk candidates

    results \= \[\]

    for coin in candidates:

        try:

            sym \= coin\['symbol'\]

            if model in (1, 2):

                c4h  \= parse\_candles(get\_ohlcv(sym, '4h',  limit=20))

                c15m \= parse\_candles(get\_ohlcv(sym, '15m', limit=20))

            else:

                c1d  \= parse\_candles(get\_ohlcv(sym, '1d',  limit=10))

            if model \== 1: score \= analyze\_model1(coin, c4h, c15m)

            if model \== 2: score \= analyze\_model2(coin, c4h)

            if model \== 3: score \= analyze\_model3(coin, c1d)

            if model \== 4: score \= analyze\_model4(coin, c1d)

            if score \> 0: results.append({\*\*coin, 'score': score})

        except Exception as e:

            import logging; logging.warning(f'Skip {coin\["symbol"\]}: {e}')

            continue  \# jangan crash seluruh pipeline karena 1 coin

    return sorted(results, key=lambda x: x\['score'\], reverse=True)\[:10\]

## **8.6  Scheduler — Jadwal Run Per Model**

| Service | Interval | Jam pertama | Library scheduler |
| :---- | :---- | :---- | :---- |
| service\_model1.py | Setiap 15 menit | 07:00 WIB | IntervalTrigger(minutes=15) |
| service\_model2.py | Setiap 4 jam | 07:00 WIB | IntervalTrigger(hours=4) |
| service\_model3.py | Setiap 4 jam | 07:00 WIB | IntervalTrigger(hours=4) |
| service\_model4.py | Sekali sehari | 07:00 WIB tepat | CronTrigger(hour=7, minute=0, timezone='Asia/Jakarta') |

**\# Python — service\_model4.py (contoh scheduler)**  
from apscheduler.schedulers.blocking import BlockingScheduler

from apscheduler.triggers.cron import CronTrigger

from pipeline import run\_pipeline

from notifier import send\_alert

import logging, json

logging.basicConfig(level=logging.INFO)

def job\_model4():

    logging.info('Model 4: running pipeline...')

    results \= run\_pipeline(model=4)

    if not results:

        send\_alert('Model 4: tidak ada setup hari ini.')

        return

    msg \= 'Model 4 — Spot Gainers:\\n'

    for i, r in enumerate(results, 1):

        sl  \= r.get('stop\_loss', 0\)

        msg \+= f"{i}. {r\['symbol'\].upper()} | Price: {r\['current\_price'\]} | 24h: \+{r\['price\_change\_percentage\_24h'\]:.1f}% | SL: {sl} | Score: {r\['score'\]:.1f}\\n"

    send\_alert(msg)

scheduler \= BlockingScheduler(timezone='Asia/Jakarta')

scheduler.add\_job(job\_model4, CronTrigger(hour=7, minute=0, timezone='Asia/Jakarta'))

scheduler.start()

## **8.7  Estimasi API Call — Perbandingan Dengan/Tanpa Pipeline**

| Skenario | Shared fetch | OHLCV per model | Derivatif per model | Total per hari |
| :---- | :---- | :---- | :---- | :---- |
| Tanpa pipeline (tiap model scan all) | 4×/run × 96 run \= 384× | \~300×/run × 4 model | \~60×/run × 4 model | \~150.000+ call/hari |
| Dengan pipeline (v2.1) | 1×/run, cached | \~15–40× per run per model | \~10–20× per model per run | \~5.000–8.000 call/hari |
| **Penghematan** | **75%** | **\~90–95%** | **\~80–90%** | **\~95% lebih hemat** |

* CoinGecko free tier: \~10–30 req/menit. Dengan shared fetch, sangat aman.

* Binance public API: limit 1200 req/menit (weight-based). OHLCV cache mencegah fetch ulang dalam 5 menit.

* Coinalyze free tier lebih ketat (\~10 req/menit). Fetch OI dan Funding hanya untuk coin yang lolos Layer 3\.

* Tambahkan time.sleep(0.2) antar request dalam loop heavy analysis untuk menghindari burst.

## **8.8  Struktur Data Standar Coin**

Format dict yang mengalir dari Layer 2 ke semua model. Semua model menggunakan field yang sama.

**\# Coin dict setelah pre\_filter (Layer 2 output)**  
{

    'id':                          'akash-network',

    'symbol':                      'akt',

    'name':                        'Akash Network',

    'current\_price':               0.6327,

    'market\_cap':                  185\_412\_492,

    'market\_cap\_rank':             78,

    'total\_volume':                75\_654\_668,

    'price\_change\_percentage\_24h': 14.22,

}

\# Field tambahan setelah heavy analysis (Layer 4 output):

\# 'candles\_1d':    \[{open, high, low, close, volume}, ...\]

\# 'candles\_4h':    \[{open, high, low, close, volume}, ...\]

\# 'candles\_15m':   \[{open, high, low, close, volume}, ...\]

\# 'open\_interest': 0.0   — USD

\# 'funding\_rate':  0.0   — %

\# 'cvd\_24h':       0.0   — cumulative volume delta

\# 'stop\_loss':     0.0   — dihitung masing-masing model

\# 'score':         0.0   — skor final 0–100

## **8.9  Error Handling & Fallback**

| Kondisi Error | Aksi | Impact |
| :---- | :---- | :---- |
| CoinGecko rate limit (429) | Retry 1× setelah 60 detik. Jika masih gagal, gunakan cache terakhir | Data mungkin basi, output tetap jalan |
| Binance OHLCV gagal 1 coin | Log warning, skip coin, lanjut ke berikutnya | Coin tidak masuk output, model lain tidak terpengaruh |
| Coinalyze tidak tersedia | Skip derivatif, jalankan model tanpa OI/Funding | Score derivatif \= 0, tambahkan flag 'no\_deriv: true' |
| Semua coin gagal analisa | Kirim notif: 'Pipeline error — tidak ada output model X' | Developer perlu cek log VPS |
| Cache expired \+ API down | Gunakan cache terakhir dengan flag 'stale\_data: true' | Notifikasi bisa di-filter oleh user |

**\# Pattern try/except dalam loop heavy analysis**  
for coin in candidates:

    try:

        raw     \= get\_ohlcv(coin\['symbol'\], '1d', limit=10)

        candles \= parse\_candles(raw)

        score   \= analyze\_model4(coin, candles)

        if score \> 0: results.append({\*\*coin, 'score': score})

    except Exception as e:

        logging.warning(f"Skip {coin\['symbol'\]}: {e}")

        continue  \# jangan crash seluruh pipeline karena 1 coin

Dokumen Versi 2.2 — Ditambahkan: Section 8 \+ Pipeline Architecture Diagram — For Developer Use Only

[image1]: <data:image/png;base64,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>