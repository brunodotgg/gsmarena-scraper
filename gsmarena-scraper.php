<?php

/**
 * GSMArena Scraper
 * Scrapes device information from GSMArena including brand, model name, and serial code
 */

// Set execution time limit to allow for scraping
set_time_limit(300);

/**
 * Fetch HTML content with mobile user agent
 */
function fetchPage($url)
{
    $ch = curl_init();

    // Mobile user agent to ensure we get mobile version
    $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_7_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.2 Mobile/15E148 Safari/604.1';

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return false;
    }

    return $response;
}

/**
 * Extract device URLs from the results page
 */
function extractDeviceUrls($html)
{
    $urls = [];

    // Create DOM document
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    // Create XPath
    $xpath = new DOMXPath($dom);

    // Find all device links
    // GSMArena mobile version uses <a> tags with device links
    $links = $xpath->query('//a[contains(@href, ".php")]');

    foreach ($links as $link) {
        $href = $link->getAttribute('href');

        // Filter for device pages (they usually have a pattern like brand_model-1234.php)
        if (preg_match('/[a-zA-Z]+_[a-zA-Z0-9_]+-\d+\.php/', $href)) {
            $fullUrl = 'https://m.gsmarena.com/' . ltrim($href, '/');
            if (!in_array($fullUrl, $urls)) {
                $urls[] = $fullUrl;
            }
        }
    }

    return $urls;
}

/**
 * Extract device information from a device page
 */
function extractDeviceInfo($html, $url)
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    $xpath = new DOMXPath($dom);

    $info = [
        'url' => $url,
        'brand' => '',
        'model' => '',
        'serial_code' => '',
        'misc_model' => ''  // Add specific field for MISC model
    ];

    // Extract title which usually contains brand and model
    $title = $xpath->query('//title')->item(0);
    if ($title) {
        $titleText = trim($title->textContent);
        // Remove " - Full phone specifications" and " - GSMArena.com" from title
        $titleText = preg_replace('/ - Full phone specifications.*$/i', '', $titleText);
        $titleText = preg_replace('/ - GSMArena\.com.*$/i', '', $titleText);

        // Split brand and model (first word is usually brand)
        $parts = explode(' ', $titleText, 2);
        if (count($parts) >= 2) {
            $info['brand'] = $parts[0];
            $info['model'] = $parts[1];
        } else {
            $info['model'] = $titleText;
        }
    }

    // Try to find serial/model code in the page content
    // Look for specific patterns in the device specifications

    // Method 1: Look for <td data-spec="models"> which often contains model codes
    $modelSection = $xpath->query('//td[@data-spec="models"]');
    if ($modelSection->length > 0) {
        $modelText = trim($modelSection->item(0)->textContent);
        if (!empty($modelText) && $modelText !== '-') {
            // Extract first model code (usually separated by comma)
            $models = explode(',', $modelText);
            $info['serial_code'] = trim($models[0]);
            $info['misc_model'] = trim($models[0]);  // Store in misc_model field as well
            echo "  - Found model in MISC section: " . $info['misc_model'] . "\n";
        }
    }

    // Method 2: Look for "Also known as" section
    if (empty($info['serial_code'])) {
        $alsoKnown = $xpath->query('//td[contains(text(), "Also known as")]/following-sibling::td');
        if ($alsoKnown->length > 0) {
            $alsoKnownText = trim($alsoKnown->item(0)->textContent);
            if (!empty($alsoKnownText) && $alsoKnownText !== '-') {
                $info['serial_code'] = $alsoKnownText;
            }
        }
    }

    // Method 3: Look in the network section for model numbers
    if (empty($info['serial_code'])) {
        $content = $dom->textContent;
        // Look for patterns like SM-A565, A2342, etc.
        if (preg_match('/\b([A-Z]{2,3}-[A-Z0-9]{3,6}|[A-Z]\d{4}[A-Z]?)\b/', $content, $matches)) {
            $info['serial_code'] = $matches[1];
        }
    }

    // Method 4: Extract from URL if still no serial code found
    if (empty($info['serial_code'])) {
        // Try to extract model number from URL (e.g., samsung_galaxy_s21-10625.php)
        if (preg_match('/([a-zA-Z]+)_([a-zA-Z0-9_]+)-(\d+)\.php/', $url, $matches)) {
            $info['serial_code'] = 'GSM-' . $matches[3];
        }
    }

    return $info;
}

// Main execution
echo "Starting GSMArena scraper...\n\n";

$mainUrl = 'https://m.gsmarena.com/results.php3?nYearMin=2025&chkESIM=selected&sAvailabilities=1';

// Fetch main results page
echo "Fetching results page...\n";
$mainHtml = fetchPage($mainUrl);

if (!$mainHtml) {
    die("Error: Could not fetch the main results page.\n");
}

// Extract device URLs
echo "Extracting device URLs...\n";
$deviceUrls = extractDeviceUrls($mainHtml);

echo "Found " . count($deviceUrls) . " devices.\n\n";

// Process all devices without limit
// $deviceUrls = array_slice($deviceUrls, 0, 1);

// Array to store all device information
$devices = [];

// Fetch each device page and extract information
foreach ($deviceUrls as $index => $deviceUrl) {
    echo "Fetching device " . ($index + 1) . "/" . count($deviceUrls) . ": $deviceUrl\n";

    $deviceHtml = fetchPage($deviceUrl);

    if ($deviceHtml) {
        $deviceInfo = extractDeviceInfo($deviceHtml, $deviceUrl);
        $devices[] = $deviceInfo;

        // Small delay to not get rate limited
        sleep(2);
    } else {
        echo "  - Error fetching device page\n";
    }
}

echo "\n\nResults:\n";
echo "========\n\n";

// Display results in a more readable format
foreach ($devices as $index => $device) {
    echo "Device " . ($index + 1) . ":\n";
    echo "  Brand: " . $device['brand'] . "\n";
    echo "  Model: " . $device['model'] . "\n";
    echo "  MISC Model: " . ($device['misc_model'] ?: 'Not found') . "\n";
    echo "  Serial Code: " . ($device['serial_code'] ?: 'Not found') . "\n";
    echo "  URL: " . $device['url'] . "\n";
    echo "  ---\n\n";
}

// Also output as PHP array for programmatic use
echo "\n\nPHP Array Output:\n";
echo "=================\n";
var_dump($devices);

// Dump devices to device.json
$json = json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents('device.json', $json);
echo "\n\nDevices dumped to device.json\n";
