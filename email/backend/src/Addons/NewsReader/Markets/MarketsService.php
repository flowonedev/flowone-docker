<?php

namespace Webmail\Addons\NewsReader\Markets;

/**
 * Markets data service for the News dashboard panel.
 *
 * Pulls a user-configurable basket of stocks (from a curated allow-list)
 * via Twelve Data's authenticated REST API and a basket of
 * cryptocurrencies via CoinGecko's /coins/markets endpoint. CoinGecko
 * is key-less; Twelve Data uses the API key configured in
 * `markets.twelvedata_api_key` (env: `TWELVEDATA_API_KEY`).
 *
 * Why Twelve Data and not Yahoo / Stooq: both key-less providers we
 * previously used have stopped being reliable from typical VPS IP
 * ranges in 2026 — Stooq's CSV endpoint now requires a captcha-acquired
 * apikey, and Yahoo's v8 chart endpoint returns empty payloads / blocks
 * datacentre IPs. Twelve Data exposes a single `time_series` endpoint
 * that, in one batched HTTP request, returns the current price + the
 * trailing closes we need for the sparkline + a usable previous close.
 * The free Basic plan (800 credits/day, 8/min) covers every realistic
 * dashboard load when we cache for an hour.
 *
 *   https://api.twelvedata.com/time_series
 *     ?symbol=AAPL,MSFT,...
 *     &interval=1day
 *     &outputsize=40
 *     &apikey={key}
 *
 * Twelve Data charges 1 credit per symbol in the batch (regardless of
 * how many bars we request), so the typical default basket (6 stocks)
 * costs 6 credits / fetch. With a 1-hour cache that's 144 credits/day
 * shared across all users on the default basket — still under the free
 * quota of 800/day even if a few users add bespoke baskets.
 *
 * Symbol translation:
 *   Our `STOCKS_ALLOW` keys are kept in Yahoo's convention
 *   (`^GSPC`, `EURUSD=X`, `GC=F`, plain tickers like `AAPL`) so saved
 *   baskets keep working across provider changes. `TWELVEDATA_SYMBOL_MAP`
 *   translates each one to Twelve Data's symbol syntax.
 *
 * Caching:
 *   - Result is cached in Redis. Cache key includes a hash of the
 *     basket so different baskets don't trample each other.
 *   - Fresh window: configurable (default 1 hour, `MARKETS_CACHE_TTL`).
 *   - Stale window: 24h — if upstream is down, we serve up to a
 *     day-old data rather than wiping the panel.
 *
 * Network safety: 5s per-request timeout, SSL verification on, all
 * upstream URLs hardcoded (no user-controlled URLs).
 */
class MarketsService
{
    // v7 = Twelve Data with paid-tier pre-filter + chunked per-symbol
    // fallback. Each bump invalidates the previous cache so we never
    // serve a stale empty payload from an earlier broken provider /
    // basket combination.
    private const CACHE_KEY_PREFIX = 'markets:overview:v7:';
    private const CACHE_TTL_FRESH_DEFAULT = 3600;     // 1 hour fresh window (override via MARKETS_CACHE_TTL)
    private const CACHE_TTL_STALE = 24 * 60 * 60;     // 24h stale-on-failure window
    private const HTTP_TIMEOUT = 8;
    private const HTTP_CONNECT_TIMEOUT = 4;
    private const UA = 'FlowOne/1.0 (+https://flowone.pro)';

    /**
     * Maximum number of symbols to send in one Twelve Data batch
     * request. The endpoint is fine with arbitrary counts but we cap
     * the basket itself at 12 in `sanitiseBasket`, so a single batch
     * always covers the whole request.
     */
    private const TWELVEDATA_BATCH_LIMIT = 12;
    private const TWELVEDATA_OUTPUTSIZE = 40;         // ~40 trading days

    /**
     * Translation table from our user-facing stock keys (the ones used
     * in `STOCKS_ALLOW`, the Settings UI, and the saved basket) to the
     * symbol Twelve Data actually serves. Indices drop the leading `^`,
     * `BRK-B` uses a dot, FX uses the `BASE/QUOTE` form, gold and oil
     * map to their spot/commodity equivalents that Twelve Data carries
     * on the Basic plan.
     */
    /**
     * Symbols that are present in `STOCKS_ALLOW` for back-compat but
     * are gated to a paid Twelve Data plan (raw indices, raw commodity
     * futures). We pre-filter these out of the request before hitting
     * the API so a single plan-locked pick can't poison the whole
     * batch — Twelve Data returns a top-level error envelope when ANY
     * symbol in a batch is plan-locked, which would otherwise drop
     * every other row alongside it. Users on a paid plan can remove
     * entries from this list to opt back in.
     */
    private const TWELVEDATA_PAID_TIER = [
        '^GSPC', '^NDX', '^DJI', '^RUT',
        '^FTSE', '^GDAXI', '^STOXX50E',
        'GC=F', 'CL=F',
    ];

    private const TWELVEDATA_SYMBOL_MAP = [
        // Index ETF proxies (identity — Twelve Data uses the same
        // tickers as Yahoo for these). Free on Basic plan.
        'SPY'   => 'SPY',
        'QQQ'   => 'QQQ',
        'DIA'   => 'DIA',
        'IWM'   => 'IWM',
        // Raw indices (paid plan only — kept in case the operator
        // upgrades; otherwise the parser silently drops these rows
        // when Twelve Data refuses them).
        '^GSPC' => 'SPX',
        '^NDX'  => 'NDX',
        '^DJI'  => 'DJI',
        '^RUT'  => 'RUT',
        // Major EU / UK indices (paid plan only)
        '^FTSE'     => 'UKX',
        '^GDAXI'    => 'DAX',
        '^STOXX50E' => 'SX5E',
        // Mega-cap tech (kept verbatim)
        'AAPL'  => 'AAPL',
        'MSFT'  => 'MSFT',
        'GOOGL' => 'GOOGL',
        'AMZN'  => 'AMZN',
        'META'  => 'META',
        'NVDA'  => 'NVDA',
        'TSLA'  => 'TSLA',
        'NFLX'  => 'NFLX',
        // Other large caps
        'JPM'   => 'JPM',
        'V'     => 'V',
        'BRK-B' => 'BRK.B',
        'WMT'   => 'WMT',
        'XOM'   => 'XOM',
        'JNJ'   => 'JNJ',
        'KO'    => 'KO',
        'DIS'   => 'DIS',
        // Commodity ETF proxies (free; track gold and oil prices)
        'GLD'   => 'GLD',
        'USO'   => 'USO',
        // Currencies (free on Basic plan)
        'EURUSD=X' => 'EUR/USD',
        'GBPUSD=X' => 'GBP/USD',
        // Raw commodities (paid plan only)
        'GC=F'     => 'XAU/USD',
        'CL=F'     => 'WTI/USD',
    ];

    /**
     * Curated allow-list of stocks the user can pick from in Settings.
     * Key = the user-facing ticker (kept stable so existing saved
     * baskets keep working), value = friendly display name.
     *
     * Twelve Data's free Basic plan covers US stocks + ETFs + forex
     * but NOT raw indices (`SPX`, `NDX`, `DJI`) or many commodities
     * (`XAU/USD`, `WTI/USD`) — those are gated to paid tiers. So the
     * defaults use ETF proxies (SPY=S&P 500, QQQ=Nasdaq 100, DIA=Dow)
     * which track their underlying index to within rounding. The raw
     * index / commodity keys stay in the allow-list so a future paid
     * plan can opt back in without changing the saved-basket format.
     * Symbols that the configured plan can't serve are dropped from
     * the panel (no zero-priced placeholder rows).
     */
    private const STOCKS_ALLOW = [
        // Index ETF proxies (free on Twelve Data Basic — these are what
        // the default basket uses for market-overview headlines).
        'SPY'   => 'S&P 500 ETF',
        'QQQ'   => 'Nasdaq 100 ETF',
        'DIA'   => 'Dow Jones ETF',
        'IWM'   => 'Russell 2000 ETF',
        // Raw indices (require paid Twelve Data plan; kept for back-
        // compat with previously saved baskets).
        '^GSPC' => 'S&P 500',
        '^NDX'  => 'Nasdaq 100',
        '^DJI'  => 'Dow Jones',
        '^RUT'  => 'Russell 2000',
        // Major EU / UK indices (paid plan only)
        '^FTSE' => 'FTSE 100',
        '^GDAXI' => 'DAX',
        '^STOXX50E' => 'Euro Stoxx 50',
        // Mega-cap tech
        'AAPL'  => 'Apple',
        'MSFT'  => 'Microsoft',
        'GOOGL' => 'Alphabet',
        'AMZN'  => 'Amazon',
        'META'  => 'Meta',
        'NVDA'  => 'NVIDIA',
        'TSLA'  => 'Tesla',
        'NFLX'  => 'Netflix',
        // Other large caps
        'JPM'   => 'JPMorgan',
        'V'     => 'Visa',
        'BRK-B' => 'Berkshire Hathaway',
        'WMT'   => 'Walmart',
        'XOM'   => 'ExxonMobil',
        'JNJ'   => 'Johnson & Johnson',
        'KO'    => 'Coca-Cola',
        'DIS'   => 'Disney',
        // Commodity ETF proxies (free; track gold and oil prices)
        'GLD'   => 'Gold ETF',
        'USO'   => 'Crude Oil ETF',
        // Currencies (free on Basic plan)
        'EURUSD=X' => 'EUR / USD',
        'GBPUSD=X' => 'GBP / USD',
        // Raw commodities (paid plan only; kept for back-compat)
        'GC=F'  => 'Gold',
        'CL=F'  => 'Crude Oil',
    ];

    /**
     * Curated allow-list of crypto coins. CoinGecko id -> ticker symbol.
     */
    private const CRYPTO_ALLOW = [
        'bitcoin'         => 'BTC',
        'ethereum'        => 'ETH',
        'solana'          => 'SOL',
        'binancecoin'     => 'BNB',
        'ripple'          => 'XRP',
        'dogecoin'        => 'DOGE',
        'cardano'         => 'ADA',
        'avalanche-2'     => 'AVAX',
        'polkadot'        => 'DOT',
        'tron'            => 'TRX',
        'chainlink'       => 'LINK',
        'matic-network'   => 'MATIC',
        'litecoin'        => 'LTC',
        'shiba-inu'       => 'SHIB',
        'uniswap'         => 'UNI',
        'cosmos'          => 'ATOM',
        'monero'          => 'XMR',
        'stellar'         => 'XLM',
        'aptos'           => 'APT',
        'arbitrum'        => 'ARB',
    ];

    // Default basket uses ETF proxies for the index headlines because
    // Twelve Data's free plan doesn't carry the raw indices. SPY/QQQ/DIA
    // track S&P/Nasdaq/Dow to within rounding and are free.
    private const DEFAULT_STOCKS = ['SPY', 'QQQ', 'DIA', 'AAPL', 'NVDA', 'TSLA'];
    private const DEFAULT_CRYPTO = ['bitcoin', 'ethereum', 'solana', 'binancecoin', 'ripple', 'dogecoin'];

    private array $config;
    private ?\Redis $redis = null;
    private string $twelveDataApiKey = '';
    private int $cacheTtlFresh;

    public function __construct(array $config)
    {
        $this->config = $config;
        $marketsCfg = $config['markets'] ?? [];
        $this->twelveDataApiKey = trim((string) ($marketsCfg['twelvedata_api_key'] ?? ''));
        $this->cacheTtlFresh = (int) (
            $marketsCfg['cache_ttl_fresh'] ?? self::CACHE_TTL_FRESH_DEFAULT
        );
        if ($this->cacheTtlFresh < 60) {
            // Sanity floor — anything tighter than a minute risks
            // burning through Twelve Data's daily quota on a hot
            // dashboard load.
            $this->cacheTtlFresh = self::CACHE_TTL_FRESH_DEFAULT;
        }
        $this->initRedis();
    }

    /**
     * Exposed for diagnostics (markets-test.php) so the operator can
     * confirm whether the upstream key is present without having to
     * crack open the env file.
     */
    public function hasStocksProviderKey(): bool
    {
        return $this->twelveDataApiKey !== '';
    }

    private function initRedis(): void
    {
        $cfg = $this->config['redis'] ?? [];
        if (empty($cfg['host']) || !extension_loaded('redis')) {
            return;
        }
        try {
            $this->redis = new \Redis();
            $this->redis->connect($cfg['host'], (int) ($cfg['port'] ?? 6379), 2.0);
            if (!empty($cfg['password'])) {
                $this->redis->auth($cfg['password']);
            }
            if (!empty($cfg['database'])) {
                $this->redis->select((int) $cfg['database']);
            }
        } catch (\Throwable $e) {
            $this->redis = null;
            error_log('MarketsService Redis: ' . $e->getMessage());
        }
    }

    private function redisKey(array $stocks, array $crypto): string
    {
        $prefix = ($this->config['redis']['prefix'] ?? 'webmail:') . 'news_reader:' . self::CACHE_KEY_PREFIX;
        // Sort first so {AAPL,NVDA} and {NVDA,AAPL} share a cache slot.
        $s = $stocks;
        $c = $crypto;
        sort($s);
        sort($c);

        return $prefix . substr(md5(implode(',', $s) . '|' . implode(',', $c)), 0, 12);
    }

    /**
     * Returns the lists of pickable stocks and crypto for the Settings UI.
     *
     * @return array{stocks: list<array{symbol:string,name:string}>, crypto: list<array{id:string,symbol:string,name:string}>, defaults: array{stocks: list<string>, crypto: list<string>}}
     */
    public function getAvailable(): array
    {
        $stocks = [];
        foreach (self::STOCKS_ALLOW as $sym => $name) {
            $stocks[] = ['symbol' => $sym, 'name' => $name];
        }
        $crypto = [];
        foreach (self::CRYPTO_ALLOW as $id => $sym) {
            $crypto[] = ['id' => $id, 'symbol' => $sym, 'name' => $this->cryptoFriendlyName($id)];
        }

        return [
            'stocks'   => $stocks,
            'crypto'   => $crypto,
            'defaults' => [
                'stocks' => self::DEFAULT_STOCKS,
                'crypto' => self::DEFAULT_CRYPTO,
            ],
        ];
    }

    /**
     * Friendly display names for crypto coins. CoinGecko ids are
     * lowercase-with-dashes which doesn't read well, so we keep a
     * tiny lookup that picks up the title-cased version we want.
     */
    private function cryptoFriendlyName(string $id): string
    {
        $map = [
            'bitcoin'        => 'Bitcoin',
            'ethereum'       => 'Ethereum',
            'solana'         => 'Solana',
            'binancecoin'    => 'BNB',
            'ripple'         => 'XRP',
            'dogecoin'       => 'Dogecoin',
            'cardano'        => 'Cardano',
            'avalanche-2'    => 'Avalanche',
            'polkadot'       => 'Polkadot',
            'tron'           => 'TRON',
            'chainlink'      => 'Chainlink',
            'matic-network'  => 'Polygon',
            'litecoin'       => 'Litecoin',
            'shiba-inu'      => 'Shiba Inu',
            'uniswap'        => 'Uniswap',
            'cosmos'         => 'Cosmos',
            'monero'         => 'Monero',
            'stellar'        => 'Stellar',
            'aptos'          => 'Aptos',
            'arbitrum'       => 'Arbitrum',
        ];

        return $map[$id] ?? ucfirst($id);
    }

    /**
     * Filter a user-supplied basket against the allow-list. Keeps the
     * input order so the user's preferred ordering survives the round
     * trip. Falls back to the default basket when the input is empty
     * or all entries are unknown.
     *
     * @param mixed       $input       array of strings, csv string, or null
     * @param array<string,mixed> $allow keyed by valid id
     * @param list<string> $default
     * @return list<string>
     */
    private function sanitiseBasket($input, array $allow, array $default): array
    {
        $items = [];
        if (is_string($input)) {
            $items = array_map('trim', explode(',', $input));
        } elseif (is_array($input)) {
            foreach ($input as $v) {
                if (is_string($v)) {
                    $items[] = trim($v);
                }
            }
        }
        $items = array_values(array_unique(array_filter($items, fn($v) => $v !== '' && isset($allow[$v]))));
        if (empty($items)) {
            return $default;
        }
        // Cap basket size as a defensive measure (rendering more than
        // ~12 rows in the panel makes it unreadable anyway).
        return array_slice($items, 0, 12);
    }

    /**
     * @param mixed $stocksInput user-provided stock basket (or null for default)
     * @param mixed $cryptoInput user-provided crypto basket (or null for default)
     * @return array{stocks: list<array>, crypto: list<array>, updated_at: int, stale: bool}
     */
    public function getOverview($stocksInput = null, $cryptoInput = null): array
    {
        $stocks = $this->sanitiseBasket($stocksInput, self::STOCKS_ALLOW, self::DEFAULT_STOCKS);
        $crypto = $this->sanitiseBasket($cryptoInput, self::CRYPTO_ALLOW, self::DEFAULT_CRYPTO);

        $now = time();
        $cached = $this->readCache($stocks, $crypto);

        // Fresh cache (within configured TTL): serve immediately.
        if ($cached !== null && ($now - (int) $cached['updated_at']) <= $this->cacheTtlFresh) {
            $cached['stale'] = false;

            return $cached;
        }

        try {
            $fresh = $this->fetchAll($stocks, $crypto);
            $payload = [
                'stocks'     => $fresh['stocks'],
                'crypto'     => $fresh['crypto'],
                'updated_at' => $now,
                'stale'      => false,
            ];
            $this->writeCache($stocks, $crypto, $payload);

            return $payload;
        } catch (\Throwable $e) {
            error_log('MarketsService getOverview: ' . $e->getMessage());
            // Fall back to last known good (even stale up to the
            // configured stale window).
            if ($cached !== null && ($now - (int) $cached['updated_at']) <= self::CACHE_TTL_STALE) {
                $cached['stale'] = true;

                return $cached;
            }

            return [
                'stocks'     => [],
                'crypto'     => [],
                'updated_at' => $now,
                'stale'      => true,
            ];
        }
    }

    /**
     * Force-refresh the cache for a given basket (bypasses the fresh
     * window check). Used by the test harness.
     */
    public function refresh($stocksInput = null, $cryptoInput = null): array
    {
        $stocks = $this->sanitiseBasket($stocksInput, self::STOCKS_ALLOW, self::DEFAULT_STOCKS);
        $crypto = $this->sanitiseBasket($cryptoInput, self::CRYPTO_ALLOW, self::DEFAULT_CRYPTO);
        $fresh = $this->fetchAll($stocks, $crypto);
        $payload = [
            'stocks'     => $fresh['stocks'],
            'crypto'     => $fresh['crypto'],
            'updated_at' => time(),
            'stale'      => false,
        ];
        $this->writeCache($stocks, $crypto, $payload);

        return $payload;
    }

    private function readCache(array $stocks, array $crypto): ?array
    {
        if (!$this->redis) {
            return null;
        }
        try {
            $raw = $this->redis->get($this->redisKey($stocks, $crypto));
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['updated_at'])) {
            return null;
        }
        $decoded['stocks'] = is_array($decoded['stocks'] ?? null) ? $decoded['stocks'] : [];
        $decoded['crypto'] = is_array($decoded['crypto'] ?? null) ? $decoded['crypto'] : [];

        return $decoded;
    }

    private function writeCache(array $stocks, array $crypto, array $payload): void
    {
        if (!$this->redis) {
            return;
        }
        try {
            // TTL is the stale window so the fallback path can still grab it;
            // the freshness check is done in code.
            $this->redis->setex($this->redisKey($stocks, $crypto), self::CACHE_TTL_STALE, json_encode($payload));
        } catch (\Throwable $e) {
            error_log('MarketsService writeCache: ' . $e->getMessage());
        }
    }

    /**
     * Fan out all upstream HTTP calls in parallel via curl_multi:
     *   - 1 CoinGecko call returns crypto data + sparklines in one shot
     *   - 1 Twelve Data `time_series` batch call returns prices + the
     *     trailing closes for every stock in one shot
     *
     * When the Twelve Data API key isn't configured (env empty) we skip
     * the stocks job entirely so the panel just renders the crypto
     * card instead of all-failing the page.
     *
     * Resilience layer: Twelve Data returns a TOP-LEVEL error envelope
     * (dropping every row) when even a single symbol in the batch is
     * plan-locked. So if the batch comes back empty but we asked for
     * stocks, we retry as N parallel single-symbol calls — that way a
     * single plan-locked symbol only drops its own row, not the whole
     * basket. The retry costs the same number of API credits as the
     * batch (Twelve Data charges per symbol regardless of batching).
     *
     * @param list<string> $stockSymbols
     * @param list<string> $cryptoIds
     * @return array{stocks: list<array>, crypto: list<array>}
     */
    private function fetchAll(array $stockSymbols, array $cryptoIds): array
    {
        $jobs = [];
        if (!empty($cryptoIds)) {
            $jobs['cg'] = $this->coinGeckoUrl($cryptoIds);
        }
        // Only queue the stocks job when we have a key AND at least
        // one mappable symbol in the basket. Twelve Data 401s on a
        // missing key, which the parser would log noisily otherwise.
        // We also pre-filter paid-tier symbols (raw indices, raw
        // commodity futures) so a single plan-locked pick can't
        // poison the whole batch — those rows are silently dropped
        // from the panel instead.
        $mappableStocks = [];
        $droppedPaid = [];
        if ($this->twelveDataApiKey !== '') {
            foreach ($stockSymbols as $sym) {
                if (!isset(self::TWELVEDATA_SYMBOL_MAP[$sym])) {
                    continue;
                }
                if (in_array($sym, self::TWELVEDATA_PAID_TIER, true)) {
                    $droppedPaid[] = $sym;
                    continue;
                }
                $mappableStocks[] = $sym;
            }
        }
        if (!empty($droppedPaid)) {
            // Single error_log line so the operator can see what was
            // filtered without grepping symbol-by-symbol; doesn't fire
            // on every request because the result is cached for 1h.
            error_log('MarketsService: dropped paid-tier symbols from batch: '
                . implode(',', $droppedPaid));
        }
        if (!empty($mappableStocks)) {
            $jobs['td'] = $this->twelveDataTimeSeriesUrl($mappableStocks);
        }

        $responses = $this->multiGet($jobs);

        $crypto = $this->parseCoinGecko($responses['cg'] ?? null, $cryptoIds);
        $stocks = empty($mappableStocks)
            ? []
            : $this->parseTwelveDataBatch($responses['td'] ?? null, $mappableStocks);

        // Per-symbol fallback: batch came back empty but we asked for
        // stocks. Almost always means one symbol in the basket is
        // plan-locked and Twelve Data short-circuited the whole batch
        // with a top-level error envelope. Retrying per-symbol isolates
        // the bad one so the good rows still render.
        if (empty($stocks) && !empty($mappableStocks)) {
            $stocks = $this->fetchStocksIndividually($mappableStocks);
        }

        return ['stocks' => $stocks, 'crypto' => $crypto];
    }

    /**
     * Per-symbol fetch fallback used when the Twelve Data batch comes
     * back empty (top-level error envelope). Fires symbols in small
     * parallel groups so we never burst over Twelve Data's free-tier
     * rate limit (8 req/min). With pre-filtering in place this is a
     * rare safety net, only invoked when the batch failed for a
     * non-plan reason (transient network glitch, upstream 5xx).
     *
     * @param list<string> $userFacingSymbols
     * @return list<array>
     */
    private function fetchStocksIndividually(array $userFacingSymbols): array
    {
        $rows = [];
        // Group of 4 keeps us under the 8 req/min Basic-plan ceiling
        // (the crypto/CoinGecko call already happened upstream and
        // doesn't count against this provider).
        $groupSize = 4;
        $groups = array_chunk($userFacingSymbols, $groupSize);
        foreach ($groups as $groupIndex => $group) {
            $jobs = [];
            foreach ($group as $sym) {
                $tdSym = self::TWELVEDATA_SYMBOL_MAP[$sym] ?? null;
                if ($tdSym === null) {
                    continue;
                }
                $jobs['td1:' . $sym] = 'https://api.twelvedata.com/time_series'
                    . '?symbol=' . rawurlencode($tdSym)
                    . '&interval=1day'
                    . '&outputsize=' . self::TWELVEDATA_OUTPUTSIZE
                    . '&order=asc'
                    . '&apikey=' . rawurlencode($this->twelveDataApiKey);
            }
            if (empty($jobs)) {
                continue;
            }
            $responses = $this->multiGet($jobs);
            foreach ($group as $sym) {
                $body = $responses['td1:' . $sym] ?? null;
                // Reuse the batch parser with a 1-element symbol list —
                // it handles the single-symbol response shape and the
                // error-envelope case.
                $parsed = $this->parseTwelveDataBatch($body, [$sym]);
                if (!empty($parsed)) {
                    $rows[] = $parsed[0];
                }
            }
            // Throttle between groups when there's another group
            // coming. 1.2s gap keeps us safely under 8/min even if a
            // group was 4 requests fired simultaneously.
            if ($groupIndex < count($groups) - 1) {
                usleep(1_200_000);
            }
        }

        return $rows;
    }

    /**
     * Build the CoinGecko markets URL for a specific basket.
     */
    private function coinGeckoUrl(array $ids): string
    {
        return 'https://api.coingecko.com/api/v3/coins/markets'
            . '?vs_currency=usd'
            . '&ids=' . rawurlencode(implode(',', $ids))
            . '&order=market_cap_desc'
            . '&per_page=' . max(1, count($ids))
            . '&page=1'
            . '&sparkline=true'
            . '&price_change_percentage=24h';
    }

    /**
     * Build the Twelve Data `time_series` URL for a batched stocks
     * request. We pull `outputsize=40` daily bars per symbol — that
     * gives us ≥ 30 closes for the sparkline plus a previous-close
     * fallback even after weekends/holidays/half-days. Twelve Data
     * accepts a comma-separated `symbol` parameter and returns one
     * nested object per symbol in the response.
     *
     * @param list<string> $userFacingSymbols our STOCKS_ALLOW keys
     */
    private function twelveDataTimeSeriesUrl(array $userFacingSymbols): string
    {
        $batch = array_slice($userFacingSymbols, 0, self::TWELVEDATA_BATCH_LIMIT);
        $mapped = [];
        foreach ($batch as $sym) {
            if (isset(self::TWELVEDATA_SYMBOL_MAP[$sym])) {
                $mapped[] = self::TWELVEDATA_SYMBOL_MAP[$sym];
            }
        }

        return 'https://api.twelvedata.com/time_series'
            . '?symbol=' . rawurlencode(implode(',', $mapped))
            . '&interval=1day'
            . '&outputsize=' . self::TWELVEDATA_OUTPUTSIZE
            . '&order=asc'
            . '&apikey=' . rawurlencode($this->twelveDataApiKey);
    }

    /**
     * @param array<string,string> $jobs id => url
     * @return array<string,?string> id => body (null on error)
     */
    private function multiGet(array $jobs): array
    {
        if (empty($jobs)) {
            return [];
        }

        $mh = curl_multi_init();
        $handles = [];
        foreach ($jobs as $id => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_FOLLOWLOCATION  => true,
                CURLOPT_MAXREDIRS       => 3,
                CURLOPT_TIMEOUT         => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT  => self::HTTP_CONNECT_TIMEOUT,
                CURLOPT_USERAGENT       => self::UA,
                // Generic browser-ish headers: both Yahoo's v8 chart
                // endpoint and CoinGecko are happy with anything that
                // accepts JSON; we keep `text/plain` in the Accept list
                // so we don't trip Yahoo's content negotiation on edge
                // cases where it falls back to text.
                CURLOPT_HTTPHEADER      => [
                    'Accept: application/json,text/plain,*/*',
                    'Accept-Language: en-US,en;q=0.8',
                ],
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_ENCODING        => '',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$id] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);

        $out = [];
        foreach ($handles as $id => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($body === false || $err !== '') {
                // True transport failure (DNS, TLS, timeout). Drop the
                // body — there's nothing useful to parse.
                if ($err !== '') {
                    error_log('MarketsService ' . $id . ' fetch err: ' . $err);
                }
                $out[$id] = null;

                continue;
            }
            // Twelve Data ships actionable error JSON on 401 / 422 / 429
            // (bad key, plan-locked symbol, quota exceeded). Keep the
            // body so the per-provider parser can log the upstream
            // message — we only log the bare status code here so the
            // operator can correlate.
            if ($code < 200 || $code >= 300) {
                error_log('MarketsService ' . $id . ' HTTP ' . $code);
            }
            $out[$id] = (string) $body;
        }
        curl_multi_close($mh);

        return $out;
    }

    /**
     * @param list<string> $orderIds preferred display order
     * @return list<array{symbol:string,name:string,price:float,change_pct:float,sparkline:array,image:?string}>
     */
    private function parseCoinGecko(?string $body, array $orderIds): array
    {
        if ($body === null) {
            return [];
        }
        $rows = json_decode($body, true);
        if (!is_array($rows)) {
            return [];
        }
        $byId = [];
        foreach ($rows as $r) {
            $id = (string) ($r['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $r;
            }
        }
        $out = [];
        foreach ($orderIds as $id) {
            $r = $byId[$id] ?? null;
            if ($r === null) {
                continue;
            }
            $sparkline = [];
            if (isset($r['sparkline_in_7d']['price']) && is_array($r['sparkline_in_7d']['price'])) {
                // CoinGecko ships 168 hourly points; downsample to ~24
                // for a tighter sparkline that still reads as a trend.
                $sparkline = $this->downsample($r['sparkline_in_7d']['price'], 24);
            }
            $symbolUpper = strtoupper((string) ($r['symbol'] ?? self::CRYPTO_ALLOW[$id] ?? $id));
            $out[] = [
                'symbol'     => $symbolUpper,
                'name'       => (string) ($r['name'] ?? $this->cryptoFriendlyName($id)),
                'price'      => (float) ($r['current_price'] ?? 0),
                'change_pct' => (float) ($r['price_change_percentage_24h'] ?? 0),
                'sparkline'  => $sparkline,
                'image'      => isset($r['image']) ? (string) $r['image'] : null,
            ];
        }

        return $out;
    }

    /**
     * Parse a Twelve Data batched `time_series` payload into one row
     * per requested symbol. Returns the rows in the same order as the
     * basket (caller's `$userFacingSymbols`) and drops any symbol whose
     * response was empty / malformed so the panel never renders a
     * 0.00 placeholder.
     *
     * Two response shapes have to be handled:
     *
     *   1) Single symbol — Twelve Data returns the data object at the
     *      top level:
     *        { "meta": {...}, "values": [...], "status": "ok" }
     *
     *   2) Multiple symbols — Twelve Data wraps each in a key:
     *        {
     *          "AAPL":  { "meta": {...}, "values": [...], "status": "ok" },
     *          "MSFT":  { "meta": {...}, "values": [...], "status": "ok" }
     *        }
     *
     *   3) Auth / quota error — single top-level error envelope:
     *        { "code": 401, "message": "...", "status": "error" }
     *
     * The `values` array is `order=asc` (oldest first) so the last
     * entry is today's close and the second-to-last is yesterday's
     * close — exactly what we need for the change % calculation.
     *
     * @param list<string> $userFacingSymbols
     * @return list<array{symbol:string,name:string,price:float,change_pct:float,sparkline:list<float>,image:null}>
     */
    private function parseTwelveDataBatch(?string $body, array $userFacingSymbols): array
    {
        if (!is_string($body) || $body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Top-level error (bad key, quota exceeded, etc.) — surface it
        // to the operator log so the panel-empty case is debuggable.
        if (isset($decoded['status']) && $decoded['status'] === 'error') {
            $code = (string) ($decoded['code'] ?? '?');
            $msg = (string) ($decoded['message'] ?? '');
            error_log("MarketsService TwelveData error code={$code}: {$msg}");

            return [];
        }

        $rows = [];
        $isBatch = count($userFacingSymbols) > 1
            || (isset($decoded[self::TWELVEDATA_SYMBOL_MAP[$userFacingSymbols[0]] ?? '']));

        foreach ($userFacingSymbols as $userSym) {
            $tdSym = self::TWELVEDATA_SYMBOL_MAP[$userSym] ?? null;
            if ($tdSym === null) {
                continue;
            }

            $entry = $isBatch
                ? ($decoded[$tdSym] ?? null)
                : $decoded;
            if (!is_array($entry)) {
                continue;
            }
            // Per-symbol error (unknown ticker, plan-locked symbol).
            // Twelve Data shapes these as { code, message, status:error }
            // *inside* the per-symbol slot when batching.
            if (isset($entry['status']) && $entry['status'] === 'error') {
                $msg = (string) ($entry['message'] ?? '');
                error_log("MarketsService TwelveData {$userSym}: {$msg}");

                continue;
            }

            $closes = [];
            $values = $entry['values'] ?? [];
            if (is_array($values)) {
                foreach ($values as $bar) {
                    if (!is_array($bar) || !isset($bar['close'])) {
                        continue;
                    }
                    $c = $bar['close'];
                    if (!is_numeric($c)) {
                        continue;
                    }
                    $closes[] = (float) $c;
                }
            }
            if (empty($closes)) {
                continue;
            }

            $price = (float) end($closes);
            if ($price <= 0) {
                continue;
            }
            $prevClose = count($closes) >= 2 ? (float) $closes[count($closes) - 2] : 0.0;
            $changePct = 0.0;
            if ($prevClose > 0) {
                $changePct = (($price - $prevClose) / $prevClose) * 100.0;
            }

            $sparkline = count($closes) >= 2 ? $this->downsample($closes, 30) : [];

            $rows[] = [
                'symbol'     => $userSym,
                'name'       => self::STOCKS_ALLOW[$userSym] ?? $userSym,
                'price'      => $price,
                'change_pct' => $changePct,
                'sparkline'  => $sparkline,
                'image'      => null,
            ];
        }

        return $rows;
    }

    /**
     * Downsample a numeric series to roughly N points by even striding.
     * Always preserves the first and last sample so the visible trend
     * line starts and ends where it should.
     *
     * @param array<int,float|int> $values
     * @return list<float>
     */
    private function downsample(array $values, int $target): array
    {
        $values = array_values($values);
        $n = count($values);
        if ($n <= $target) {
            return array_map('floatval', $values);
        }
        $out = [];
        $step = ($n - 1) / ($target - 1);
        for ($i = 0; $i < $target; $i++) {
            $idx = (int) round($i * $step);
            if ($idx >= $n) {
                $idx = $n - 1;
            }
            $out[] = (float) $values[$idx];
        }

        return $out;
    }
}
