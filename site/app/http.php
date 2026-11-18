<?php
declare(strict_types=1);

/** Read a bounded response from a configured HTTP(S) endpoint without following redirects. */
function http_get(string $url, int $limit, int $timeout, array $headers = []): array
{
    if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        return [null, 0];
    }
    if (function_exists('curl_init')) {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'VideoBlog/1.0',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_MAXFILESIZE => $limit,
            // Older libcurl releases only check MAXFILESIZE when Content-Length is known.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $limit): int {
                $length = strlen($chunk);
                if (strlen($body) + $length > $limit) {
                    return 0;
                }
                $body .= $chunk;
                return $length;
            },
        ]);
        $ok = curl_exec($ch);
        return [$ok === false ? null : $body, (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE)];
    }
    if (!ini_get('allow_url_fopen')) {
        return [null, 0];
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout, 'user_agent' => 'VideoBlog/1.0', 'header' => $headers,
        'ignore_errors' => true, 'follow_location' => 0,
    ]]);
    $body = @file_get_contents($url, false, $ctx, 0, $limit + 1);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int)$m[1];
        }
    }
    return [is_string($body) && strlen($body) <= $limit ? $body : null, $status];
}
