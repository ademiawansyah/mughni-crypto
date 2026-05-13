

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
| Konteks Makro | 1W (opsional, untuk filter tren besar) |
| Screening Awal (Struktur) | 1D atau 4H |
| Konfirmasi Entry | 1H |

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

## **1.6 Onchain Extension — Miaudinau Style**

Layer tambahan opsional yang meningkatkan presisi entry M1, terutama untuk micro-cap token. Berdasarkan metode trading miaudinau yang menggunakan data onchain sebagai trigger utama — bukan hanya price action atau derivatif. Dengan layer ini, sistem dapat mendeteksi setup sebelum pump terjadi, bukan hanya setelah sweep.

### **A. Perbedaan M1 Standard vs M1 Miaudinau Style**

| Elemen | M1 Standard | M1 Miaudinau Style |
| :---- | :---- | :---- |
| **Universe koin** | Altcoin rank 50–300, volume \>$5M | Micro-cap token yang sedang pump anomali (\>+200% dalam 24H) |
| **Trigger utama** | Liquidity Sweep \+ MSS (price action) | Onchain exchange inflow \+ pump anomali detection |
| **Timing entry** | Setelah sweep \+ MSS terkonfirmasi, retrace ke FVG/OB | Di puncak pump (market order), tidak nunggu retrace — onchain sudah konfirmasi distribusi |
| **Sumber confidence** | OI declining \+ CVD divergence \+ funding ekstrem | Tahu siapa yang dump dan berapa banyak — dari exchange inflow onchain |

### **B. Onchain Signal — 4 Layer Deteksi**

| Signal Onchain | Artinya | Cara Deteksi | API / Tool |
| :---- | :---- | :---- | :---- |
| **Exchange Inflow Spike** | Whale/insider kirim token massal ke exchange \= siap sell/dump | Token transfer ke wallet exchange (Binance, OKX, Bybit) \>5% supply dalam 1 jam | Etherscan/BSCScan API \+ daftar known exchange wallets |
| **Wallet Concentration** | Top 10 wallet pegang \>70% supply \= manipulasi mudah, dump bisa kapan saja | Token holders list dari Etherscan /api?module=token\&action=tokenholderlist | Etherscan Token API (free tier tersedia) |
| **Pump Anomali Detection** | Harga naik \>200% dalam \<24 jam tanpa katalis fundamental \= pump buatan, pasti dump | price\_change\_24h \> 200% AND volume\_ratio \> 10x rata-rata 7D AND market\_cap \< $50M | CoinGecko /coins/markets \+ Binance klines untuk volume ratio |
| **Volume Onchain vs CEX** | Volume onchain stagnan tapi harga naik \= pump di CEX saja, tidak ada demand riil | Bandingkan tx count Etherscan vs volume CEX Binance dalam window yang sama | Etherscan /api?module=account\&action=tokentx \+ Binance /api/v3/klines |

### **C. Alur Kerja Miaudinau Style — Step by Step**

| \# | Step | Yang Dilakukan | Catatan Teknis |
| :---- | :---- | :---- | :---- |
| **1** | **Scan pump anomali** | Cari token dengan 24h change \>200%, market cap \<$50M, volume x10 | CoinGecko /coins/markets?order=percent\_change\_24h\_desc, filter manual |
| **2** | **Cek exchange inflow onchain** | Apakah ada transfer besar dari wallet non-exchange ke exchange wallet dalam 1-2 jam terakhir? | Etherscan tokentx API, bandingkan dengan known exchange address list |
| **3** | **Konfirmasi derivatif (M1 standard)** | OI declining, funding ekstrem negatif, CVD divergence — semua dari Section 1.4B | Coinalyze OI \+ funding \+ Binance CVD |
| **4** | **Entry — market order di puncak** | Short langsung di harga pasar saat pump masih berlangsung. SL di atas ATH pump \+ 1%. | Tidak nunggu retrace atau FVG — karena onchain sudah konfirmasi distribusi. Leverage 20-25x. |
| **5** | **Pantau exchange outflow** | Jika dump terjadi dan token mulai keluar dari exchange (outflow), bisa jadi reversal — pertimbangkan close sebagian posisi. | Monitor Etherscan token transfer dari exchange wallet ke cold wallet |

### **D. Contoh Nyata — Trade STO/USDT miaudinau (Apr 2026\)**

| Parameter | Data | Interpretasi |
| :---- | :---- | :---- |
| **Token** | STO/USDT Perpetual (micro-cap) | Sesuai universe micro-cap — harga wajar \~0.11, pump ke 1.74 \= \+1.400% |
| **Entry price** | 1.74001 (SHORT) | Di atau sangat dekat puncak — onchain inflow sudah konfirmasi distribusi saat masuk |
| **Exit price** | 0.11519 (−93.4% dari entry) | Kembali ke harga wajar pre-pump. Dump natural setelah distribusi selesai. |
| **ROI dengan leverage 25x** | \+35.270,98% | Survivorship bias tinggi — yang tampil hanya winner. 1 salah arah \= likuidasi. Wajib position size kecil. |

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
| Trend Filter (EMA) | 1H |
| Sinyal Entry (MACD \+ RSI) | 15M |
| Konfirmasi BOS | 1D |

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
| **Timeframe utama** | 4H-1D | 4H-1D | 1H | 1D |
| **Leverage** | Opsional | Opsional | Opsional | Tidak (spot only) |
| **Frekuensi signal** | Beberapa/minggu | Beberapa/minggu | Beberapa/hari | 1x/hari (pagi) |
| **Hold period** | Hari-minggu | Hari-minggu | Jam-Hari | Hari |

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
| service\_model1.py | Counter Trend Scanner | Setiap 4 jam |
| service\_model2.py | Pre-Pump Detector | Setiap 4 jam |
| service\_model3.py | Trend Momentum Scanner | Setiap 1 jam |
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

* Setup cron/scheduler: Model 4 jam 07:00 WIB, Model 1-3 setiap 4 jam

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

![][image1]

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
| service\_model1.py | Setiap 4 jam | 07:00 WIB | IntervalTrigger(hours=4) |
| service\_model2.py | Setiap 4 jam | 07:00 WIB | IntervalTrigger(hours=4) |
| service\_model3.py | Setiap 1 jam | 07:00 WIB | IntervalTrigger(hours=1) |
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

| SECTION 10: ENTRY / SL / TARGET / LEVERAGE Kalkulasi otomatis per model — modal $100 USDT per model |
| :---: |

# **10\. Entry / SL / Target / Leverage — Per Model**

Setiap setup yang lolos pipeline menghasilkan 4 variabel kalkulasi otomatis: entry price, stop loss, target bertingkat (T1/T2/T3), dan leverage rekomendasi. Semua data disimpan ke database sebagai dasar backtest, forward test, dan AI Optimizer.

## **10.1  Framework leverage — modal $100 USDT per model**

Filosofi: bukan 'risk 1% dari modal per trade' — terlalu konservatif untuk growth. Melainkan fixed position size $5–$10 per entry dengan leverage tinggi yang dikalibrasi dari SL distance.

### **Formula inti**

sl\_dist        \= (entry − sl) / entry

leverage\_raw   \= 1 / sl\_dist

leverage\_final \= floor ke leverage exchange valid ≤ leverage\_raw

               valid: \[10, 20, 25, 30, 50, 75, 100\]

pos\_value      \= position\_size × leverage\_final

units          \= pos\_value / entry\_price

max\_loss       \= position\_size × sl\_dist   (≈ position\_size jika leverage dikalibrasi)

Intuisi: dengan leverage \= 1/sl\_dist, jika harga bergerak tepat sejauh SL distance, loss \= position\_size. SL menjadi 'natural boundary' yang terkalibrasi, bukan angka arbitrary.

## **10.2  Parameter per model**

| Model | Entry | Stop Loss | Lev. ceiling | T1 | T2 | T3 trailing |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| Counter Trend | Open candle 15M setelah MSS | Low sweep \+ 0.3% buffer | Max 20× | 1R exit 50% | 2R exit 30% | Swing high −0.5% |
| Pre-Pump | Close breakout dari range kompresi 4H | Bawah range \+ 0.5% | Max 50× | 1.5R exit 40% | 3R exit 40% | EMA 21 close 4H |
| Trend Momentum | Open 1D setelah BOS (atau limit 50% retrace) | Low candle BOS | Max 30× | 1R exit 33% | 2R exit 33% | EMA 50 daily |
| Spot Gainers | Close candle 1D trigger | Low candle 1D trigger | Max 50× | 1R exit 50% | 2R exit 30% | Low 1D sebelumnya |

## **10.3  Position size & skip rule**

| Parameter | Nilai | Keterangan |
| :---- | :---- | :---- |
| Modal per model | $100 USDT | Masing-masing model berdiri sendiri |
| Position size default | $7 | 7% dari modal — titik tengah yang direkomendasikan |
| Range position size | $5 – $10 | 5%–10% dari modal, user pilih saat konfirmasi entry |
| Leverage minimum valid | 10× | Di bawah ini risk/reward tidak efisien, setup di-skip |
| Skip jika SL dist \> 6% | Counter Trend di-skip | SL terlalu jauh dari entry |
| Skip jika SL dist \> 8% | Trend Momentum & Spot Gainers di-skip | Candle/BOS terlalu besar |
| Skip jika lev raw \< 10× | Setup di-skip semua model | Tidak ada leverage valid dalam range |

## **10.4  Contoh kalkulasi — position size $7**

| Model | SL dist | Lev raw | Lev final | Pos. value | Max loss | Profit T2 |
| :---- | :---- | :---- | :---- | :---- | :---- | :---- |
| Counter Trend | 7.5% | 13.3× | 10× (ceil 20×) | $70 | −$5.25 | \+$10.50 |
| Pre-Pump | 3.2% | 31.3× | 30× (ceil 50×) | $210 | −$6.72 | \+$13.44 |
| Trend Momentum | 3.9% | 25.6× | 25× (ceil 30×) | $175 | −$6.83 | \+$13.65 |
| Spot Gainers | 2.0% | 50.0× | 50× (ceil 50×) | $350 | −$7.00 | \+$14.00 |

## **10.5  Schema data yang disimpan ke database**

Setiap setup — baik yang di-entry maupun yang hanya disimpan — tercatat dengan schema berikut:

\# Field universal semua model

model         : string  \# counter\_trend | pre\_pump | trend\_momentum | spot\_gainers

symbol        : string  \# ticker, e.g. AKT

signal\_date   : date    \# tanggal sinyal terkonfirmasi

entry         : float   \# harga entry

sl            : float   \# stop loss price

sl\_dist       : float   \# (entry-sl)/entry dalam desimal

t1            : float   \# target 1 (1R atau 1.5R)

t2            : float   \# target 2 (2R atau 3R)

t3\_method     : string  \# swing\_high\_trail | ema21\_4h | ema50\_1d | low\_1d

modal         : float   \# 100 USD

position\_size : float   \# 5–10 USD, dipilih trader saat konfirmasi

leverage      : int     \# leverage final yang dipakai

pos\_value     : float   \# position\_size × leverage

max\_loss\_est  : float   \# estimasi max loss jika SL hit

\# Field exit (diisi setelah trade selesai)

exit\_price    : float   \# harga actual saat exit

exit\_reason   : string  \# t1 | t2 | sl | candle\_bearish | trailing | manual

r\_multiple    : float   \# actual R yang didapat

hold\_duration : float   \# durasi hold dalam jam

\# Field tambahan per model:

\# Counter Trend:    oi\_at\_entry, funding\_at\_entry, cvd\_status, snapshot\_count

\# Pre-Pump:         oi\_at\_entry, funding\_at\_entry, cvd\_at\_entry, atr\_at\_entry

\# Trend Momentum:   ema50\_at\_entry, ema200\_at\_entry, rsi\_at\_entry, macd\_hist

\# Spot Gainers:     change\_24h, vol\_ratio, body\_ratio, upper\_wick\_ratio, score

| SECTION 11: KLARIFIKASI ARSITEKTUR Python Scheduler \= Logging & Signal Only — Bukan Eksekutor Entry |
| :---: |

# **11\. Klarifikasi Arsitektur — Python Scheduler vs Entry**

| PENTING: File service\_model\*.py yang berjalan di VPS berfungsi HANYA sebagai logging dan signal detection — bukan eksekutor order. Developer tidak boleh menambahkan koneksi ke exchange trading API di dalam file scheduler ini. |
| :---- |

## **11.1  Yang dilakukan Python scheduler**

* ✓  Fetch data pasar dari CoinGecko, Binance public API, Coinalyze (read-only)

* ✓  Jalankan filter pipeline Layer 1–4 sesuai jadwal per model

* ✓  Hitung semua sinyal: OI trend, funding rate, CVD, candle criteria, EMA, MACD, RSI

* ✓  Hitung entry price, stop loss, target T1/T2/T3, dan leverage rekomendasi

* ✓  Simpan semua data ke database — logging untuk backtest dan forward test

* ✓  Kirim notifikasi alert ke Telegram atau Discord

* ✓  Update status watchlist di database (sinyal makin kuat atau melemah)

## **11.2  Yang TIDAK dilakukan Python scheduler**

* ✗  Eksekusi order beli/jual di exchange — ini dilakukan manual oleh trader

* ✗  Connect ke exchange API dengan trading key atau secret key

* ✗  Buka atau tutup posisi secara otomatis

* ✗  Manage stop loss secara live di exchange

* ✗  Menyentuh dana atau aset apapun secara langsung

## **11.3  Flow yang benar — end to end**

1\. Scheduler (VPS) deteksi sinyal → hitung entry/SL/leverage → simpan ke DB → kirim notif

2\. Trader terima notif → buka dashboard UI di browser

3\. Trader lihat panel konfirmasi: entry price, SL, leverage rekomendasi, target T1/T2/T3

4\. Trader adjust position size jika perlu (slider $5–$10 dari modal $100)

5\. Trader eksekusi MANUAL di exchange dengan parameter yang ditampilkan UI

6\. Trader klik 'Konfirmasi & simpan' di UI → data tercatat ke DB sebagai forward test entry

7\. AI Optimizer analisa data hasil forward test → rekomendasikan adjustment variabel

## **11.4  Alasan pemisahan ini penting**

| Alasan | Penjelasan |
| :---- | :---- |
| Keamanan dana | Tidak ada private key exchange yang tersimpan di VPS. Jika VPS dikompromikan, dana aman. |
| Human oversight | Setiap entry diputuskan manusia — AI dan sistem hanya memberi rekomendasi sinyal. |
| Data quality | Entry yang tercatat adalah entry yang benar-benar dieksekusi trader, bukan simulasi. |
| Debugging lebih mudah | Scheduler hanya read-only terhadap pasar — tidak ada side effect ke posisi trading. |
| Leverage management | Trader memilih leverage final dan position size — sistem hanya rekomendasikan, tidak force. |

 — Ditambahkan: Section 8 \+ Pipeline Architecture Diagram — For Developer Use Only

[image1]: <data:image/png;base64,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>