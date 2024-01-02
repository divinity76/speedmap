<?php

declare(strict_types=1);

const DEBUGGING_SPEEDHACKS = false;

require_once __DIR__ . '/vendor/autoload.php';

function dd(...$args)
{
    $trace = debug_backtrace(0, 1)[0];
    echo "dd() called in {$trace['file']}:{$trace['line']}" . PHP_EOL;
    var_dump(...$args);
    die();
}
function fetch(string $url)
{
    static $curl = null;
    if ($curl === null) {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'speedmap',
        ]);
    }
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
    ]);
    $ret = curl_exec($curl);
    if (curl_errno($curl) !== CURLE_OK) {
        throw new RuntimeException(curl_error($curl), curl_errno($curl));
    }
    return $ret;
}

function cache(string $key, $value = null)
{
    if (!DEBUGGING_SPEEDHACKS) {
        return null;
    }
    static $pdo = null;
    if ($pdo === null) {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'speedmapcache.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE IF NOT EXISTS cache (key BLOB PRIMARY KEY, value BLOB);');
    }
    if ($value !== null) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO cache (key, value) VALUES (?, ?);');
        $stmt->execute([$key, serialize($value)]);
        return $value;
    }
    $ret = $pdo->query('SELECT value FROM cache WHERE key = ' . $pdo->quote($key) . ';')->fetchColumn();
    if ($ret === false) {
        return null;
    }
    return unserialize($ret);
}
function get_countries()
{
    $cached = cache('countries');
    if ($cached !== null) {
        return $cached;
    }
    $countries = file_get_contents('https://restcountries.com/v3.1/all?fields=name');
    $countries = json_decode($countries, true, 512, JSON_THROW_ON_ERROR);
    $countries = array_map(fn ($country) => $country['name']['common'], $countries);
    natsort($countries);
    $countries = array_values($countries);
    cache('countries', $countries);
    return $countries;
}
function getSpeedtestServers(string $country): ?array
{
    if (false) {
        return array(
            0 =>
            array(
                'url' => 'http://hrtspeedtest.etisalat.af:8080/speedtest/upload.php',
                'lat' => 34.3529,
                'lon' => 62.204,
                'distance' => 2892,
                'name' => 'Herat',
                'country' => 'Afghanistan',
                'cc' => 'AF',
                'sponsor' => 'Etisalat Afghanistan',
                'id' => '49811',
                'preferred' => 0,
                'https_functional' => 1,
                'host' => 'hrtspeedtest.etisalat.af.prod.hosts.ooklaserver.net:8080',
            ),
            1 =>
            array(
                'url' => 'http://mzrspeedtest.etisalat.af:8080/speedtest/upload.php',
                'lat' => 36.6926,
                'lon' => 67.118,
                'distance' => 2929,
                'name' => 'Mazar',
                'country' => 'Afghanistan',
                'cc' => 'AF',
                'sponsor' => 'Etisalat Afghanistan',
                'id' => '49660',
                'preferred' => 0,
                'https_functional' => 1,
                'host' => 'mzrspeedtest.etisalat.af.prod.hosts.ooklaserver.net:8080',
            ),
        );
    }
    $extractInterestingServers = function (array $servers) use ($country): array {
        $servers = array_filter($servers, fn ($server) => stripos($server['country'], $country) !== false);
        usort($servers, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        $servers = array_values($servers);
        // up to the 5 most distant servers-ish (ths algorithm is not perfect, IT SHOULD be based on lat/lon, not distance
        // but I dont have time to implement the haversine formula)
        $interestingServers = [];
        $interestingServers[] = $servers[0] ?? null;
        $interestingServers[] = $servers[floor(count($servers) / 4)] ?? null;
        $interestingServers[] = $servers[floor(count($servers) / 2)] ?? null;
        $interestingServers[] = $servers[floor(count($servers) * 3 / 4)] ?? null;
        $interestingServers[] = $servers[count($servers) - 1] ?? null;
        $interestingServers = array_unique($interestingServers, SORT_REGULAR);
        $interestingServers = array_filter($interestingServers, fn ($server) => $server !== null);
        return array_values($interestingServers);
    };
    $url = 'https://www.speedtest.net/api/js/servers?engine=js&https_functional=true&limit=999&search=' . urlencode($country);
    $cached = cache($url);
    if ($cached !== null) {
        return $extractInterestingServers($cached);
    }
    for (;;) {
        $servers = fetch($url);
        if (str_contains($servers, 'id="challenge-error-text"')) {
            echo "api rate-limited, waiting 10 seconds" . PHP_EOL;
            sleep(10);
            continue;
        }
        break;
    }
    $servers = json_decode($servers, true, 512, JSON_THROW_ON_ERROR);
    foreach ($servers as &$server) {
        $server['lat'] = (float)$server['lat'];
        $server['lon'] = (float)$server['lon'];
    }
    unset($server);
    cache($url, $servers);
    return $extractInterestingServers($servers);
}
function generateHTML(array $results): string
{
    $html =
        <<<'HTML'
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Document</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@latest/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@latest/dist/leaflet.js"></script>
</head>

<body>
    <div id="map"></div>
    <style>
        html,
        body {
            height: 100%;
            margin: 0;
        }

        #map {
            height: inherit;
        }
    </style>
    <script>
        window.map = L.map('map').setView([50.0, 0.0], 2.5);
        function mark(lat, lon, name) {
            if(1){
            L.marker([lat, lon]).addTo(map).bindTooltip(name, {
                //permanent: true,
                //direction: 'right',
                //opacity: 0.5,
            }); return;
        }
        if(0){
            L.marker([lat, lon]).addTo(map).bindPopup(
                L.popup({
                    //opacity: 0.5,
                    minWidth: 1,
                    minHeight: 1,
                    //autoClose: false,
                    //closeOnClick: false,
                    //closeButton: false,
                    className: 'popup',
                    content: '<span style="color: red">' + name + '</span>',
                })
            )//.openPopup();
        }
        return;
        }
        L.tileLayer(
            //'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'https://tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
            {
                maxZoom: 19,
                attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);
        // famous places
        //mark(48.8584, 2.2945, 'Eiffel Tower');
        const results = %RESULTS_JSON%;
        for (const result in results) {
            const { lat, lon, text } = results[result];
            mark(lat, lon, text);
        }

    </script>

</html>
HTML;
    $html = strtr($html, [
        '%RESULTS_JSON%' => json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    return $html;
}
function performSpeedtest(array $server, int $cachebuster): array
{
    $cacheKey = 'speedtest_' . $server['id'] . '_' . $cachebuster;
    $cached = cache($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    static $browser = null;
    static $page = null;
    $internal = function (array $server) use(&$browser, &$page): array {
        if (false) {
            return array(
                'upload_speed_mbps' => 26.58,
                'download_speed_mbps' => 413.38,
                'ping' => 107,
            );
        }
        if ($browser === null) {
            // initialize
            $chromiumSearchPaths = [
                '/usr/bin/chromium',
                '/usr/bin/google-chrome',
                '/snap/bin/chromium',
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            ];
            $home = getenv('HOME');
            if (!empty($home)) {
                $chromiumSearchPaths[] = $home . '/bin/chromium';
                $chromiumSearchPaths[] = $home . '/bin/google-chrome';
            }
            foreach ($chromiumSearchPaths as $chromiumSearchPath) {
                if (file_exists($chromiumSearchPath)) {
                    $chromiumPath = $chromiumSearchPath;
                    break;
                }
            }
            if (!isset($chromiumPath)) {
                throw new RuntimeException('chromium not found. please install it, it should be reachable via one of these paths: ' . implode(', ', $chromiumSearchPaths));
            }
            $factory = new \HeadlessChromium\BrowserFactory($chromiumPath);
            $browser = $factory->createBrowser(
                [
                    'headless' => true, // false for debugging
                    'noSandbox' => true,
                ]
            );
            $page = $browser->createPage();
        }
        $retry_until_success = function (callable $fn, float $timeout = 10.0, float $interval = 0.1) {
            $timeout = microtime(true) + $timeout;
            $e = null;
            for (;;) {
                try {
                    $ret = ($fn)();
                    if ($ret !== null && $ret !== false) {
                        return $ret;
                    }
                } catch (\Throwable $e) {
                    //
                }
                $time = microtime(true);
                if ($time > $timeout) {
                    throw new \LogicException('timeout reached', 0, $e);
                }
                @time_sleep_until($time + $interval);
            }
            throw new \LogicException('max retries reached');
        };
        try {
            $page->navigate('https://www.speedtest.net')->waitForNavigation(\HeadlessChromium\Page::LOAD);
        } catch (\Throwable $e) {
            $page->navigate('https://example.com')->waitForNavigation(\HeadlessChromium\Page::LOAD);
            $page->navigate('https://www.speedtest.net')->waitForNavigation(\HeadlessChromium\Page::LOAD);
        }
        // first change to correct server, this is tricky because we can search for country OR city OR isp but only 1 at once,
        // there might be multiple servers in the same city, or 1 provider might have multiple servers in different cities,
        // first we try $server['sponsor']
        $page->evaluate('document.querySelector(\'[data-modal-target="#find-servers"]\').click();')->getReturnValue();
        // we don't care about the result, but fetching it gives time (milliseconds) for the modal to open.
        $page->evaluate('jQuery("#host-search").val(' . json_encode($server['sponsor']) . ').change();');
        // todo: wait for search results to load.. how?
        sleep(1);
        // <a href="#" data-server-id="49661" data-https-host="1">
        $found = $page->evaluate('document.querySelectorAll(\'[data-server-id="' . $server['id'] . '"]\').length;')->getReturnValue();
        if (!$found) {
            // try again, this time with city name
            $page->evaluate('jQuery("#host-search").val(' . json_encode($server['name']) . ').change();');
            sleep(1);
            $found = $page->evaluate('document.querySelectorAll(\'[data-server-id="' . $server['id'] . '"]\').length;')->getReturnValue();
        }
        if (!$found) {
            // try again, this time with country
            $page->evaluate('jQuery("#host-search").val(' . json_encode($server['country']) . ').change();');
            sleep(1);
            $found = $page->evaluate('document.querySelectorAll(\'[data-server-id="' . $server['id'] . '"]\').length;')->getReturnValue();
        }
        if (!$found) {
            // probably unreachable :)
            throw new \LogicException('server not found, searched for ' . json_encode($server['sponsor']) . ' and ' . json_encode($server['name']) . ' and ' . json_encode($server['country']));
        }
        $page->evaluate('document.querySelector(\'[data-server-id="' . $server['id'] . '"]\').click();')->getReturnValue();
        // again don't care about result but we need to give a few milliseconds for the modal to respond, getReturnValue() gives us that :) (it's nearly instant)    
        // starts the test:
        //<a href="#" role="button" tabindex="3" aria-label="start speed test - connection type multi" onclick="window.OOKLA.globals.shouldStartOnLoad = true;" class="js-start-test test-mode-multi" data-start-button-state="before" style="opacity: 1; transform: translateY(18px) translateX(0px) scale(1);">
        $page->evaluate('document.querySelector("a.js-start-test").click();')->getReturnValue();
        // <span data-download-status-value="0.40" class="result-data-large number result-data-value download-speed">400.90</span>
        $uploadSpeed = $retry_until_success(function () use ($page) {
            $ret = $page->evaluate('document?.querySelector("span.upload-speed")?.textContent?.trim();')->getReturnValue();
            if ($ret === '-' || $ret === "\xe2\x80\x94") {
                return null;
            }
            return $ret;
        }, 5 * 60);
        $downloadSpeed = $page->evaluate('document.querySelector("span.download-speed").textContent.trim();')->getReturnValue();
        $tmp = filter_var($uploadSpeed, FILTER_VALIDATE_FLOAT);
        if ($tmp === false) {
            throw new \LogicException('invalid upload speed: ' . $uploadSpeed);
        }
        $uploadSpeed = $tmp;
        $tmp = filter_var($downloadSpeed, FILTER_VALIDATE_FLOAT);
        if ($tmp === false) {
            throw new \LogicException('invalid download speed: ' . $downloadSpeed);
        }
        $downloadSpeed = $tmp;
        // <span class="result-data-value ping-speed" data-latency-status-value="true">107</span>
        $ping = $page->evaluate('document.querySelector("span.ping-speed").textContent.trim();')->getReturnValue();
        $tmp = filter_var($ping, FILTER_VALIDATE_INT);
        if ($tmp === false) {
            throw new \LogicException('invalid ping: ' . $ping);
        }
        $ping = $tmp;
        return array(
            'upload_speed_mbps' => $uploadSpeed,
            'download_speed_mbps' => $downloadSpeed,
            'ping' => $ping,
        );
    };

    $attempts = 0;
    $maxAttempts = 6;
    for (;;) {
        ++$attempts;
        try {
            $ret = $internal($server);
            cache($cacheKey, $ret);
            return $ret;
        } catch (\Throwable $e) {
            if ($attempts >= $maxAttempts) {
                throw $e;
            }
            $page = null;
            $browser = null;
            $sleepSeconds = 60 * $attempts;
            echo "speedtest attempt #{$attempts} failed, retrying in {$sleepSeconds} seconds.." . PHP_EOL;
            sleep($sleepSeconds);
        }
    }
}
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (error_reporting() & $errno) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
});
$countries = get_countries();
$countryCount = count($countries);
$results = [];
foreach ($countries as $countryIndex => $country) {
    $countryIndexPlusOne = $countryIndex + 1;
    echo "$countryIndexPlusOne/$countryCount $country:\n";
    $servers = getSpeedtestServers($country);
    if (empty($servers)) {
        echo "no servers found" . PHP_EOL;
        continue;
    }
    $last = null;
    foreach ($servers as $server) {
        if ($server['name'] === $last) {
            // 2 servers in same city?
            //continue;
        }
        $last = $server['name'];
        var_dump($server);
        $speedtestResult1 = performSpeedtest($server, 1);
        $speedtestResult2 = performSpeedtest($server, 2);
        $speedtestResult = ($speedtestResult1['upload_speed_mbps'] > $speedtestResult2['upload_speed_mbps']) ? $speedtestResult1 : $speedtestResult2;
        $result = [
            "lat" => $server['lat'],
            "lon" => $server['lon'],
            "text" =>  "{$server['name']}, $country: ⬇️ " . $speedtestResult['download_speed_mbps'] . " Mbit/s ⬆️ " . $speedtestResult['upload_speed_mbps'] . " Mbit/s ping " . $speedtestResult['ping'] . " ms",
        ];
        $results[] = $result;
        file_put_contents('speedmap.html', generateHTML($results));
        echo $result['text'] . PHP_EOL;
    }
}
