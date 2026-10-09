<?php
// cookie_probe.php — teste si les cookies CloudFront de MYM sont wildcard.
// Usage : php cookie_probe.php <creator>   (ex : php cookie_probe.php Thom5_4)
error_reporting(E_ALL); ini_set('display_errors', 1);
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

$creator = $argv[1] ?? 'Thom5_4';
$jar = tempnam(sys_get_temp_dir(), 'mymcj');

function req($url, $jar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_FOLLOWLOCATION=>1, CURLOPT_TIMEOUT=>25,
        CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_USERAGENT=>UA, CURLOPT_ENCODING=>'',
        CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar,
        CURLOPT_HTTPHEADER=>['Accept-Language: fr,en;q=0.8']]);
    $b = curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code'=>$c, 'body'=>$b===false?'':$b];
}

// 1) capture des cookies sur les points d'entrée
foreach (["https://mym.fans/$creator", 'https://mym.fans/'] as $u) req($u, $jar);

// 2) lecture du jar
$cookies = [];
foreach ((file($jar) ?: []) as $l) {
    $l = rtrim($l, "\r\n");
    if ($l === '' || $l[0] === '#') continue;
    $p = preg_split('~\t~', $l);
    if (count($p) >= 7) $cookies[$p[5]] = $p[6];
}
echo "Cookies capturés : " . implode(', ', array_keys($cookies)) . "\n\n";

$policy = $cookies['CloudFront-Policy'] ?? '';
if (!$policy) {
    echo "AUCUN CloudFront-Policy → MYM ne pose pas de cookies signés (ou seulement après login).\n";
} else {
    $json = json_decode(base64_decode(strtr($policy, '-_', '+/')), true);
    echo "--- Policy décodée ---\n" . print_r($json, true) . "\n";
    $res = $json['Statement'][0]['Resource'] ?? '';
    echo "Resource = $res\n";
    echo strpos((string)$res, '*') !== false
        ? ">>> WILDCARD → le net est atteignable avec les cookies seuls.\n"
        : ">>> Resource EXACTE → pas de wildcard.\n";
}

// 3) test net vs flou, avec et sans cookies
$html = req("https://mym.fans/$creator", $jar)['body'];
$blur = null;
if (preg_match_all('~https://media\.prod-v2\.mym\.fans/medias/v2/[^"\'<>\s\\\\]+\?[^"\'<>\s\\\\]+~', $html, $all))
    foreach ($all[0] as $u) {
        $k = @base64_decode(strtr(explode('?', basename($u))[0], '-_', '+/'));
        if ($k && strpos($k, '-blur') !== false) { $blur = $u; break; }
    }
if (!$blur) { echo "\nPas de média flouté sur la page.\n"; exit; }

preg_match('~^(https://media\.prod-v2\.mym\.fans/medias/v2/)([^?]+)\?(.+)$~', $blur, $m);
$j = json_decode(base64_decode(strtr($m[2], '-_', '+/')), true);
$j['key'] = str_replace('-blur', '', $j['key']);
$netB64 = rtrim(strtr(base64_encode(json_encode($j)), '+/', '-_'), '=');
$netUrl = $m[1] . $netB64 . '?' . $m[3];
$netBare = $m[1] . $netB64; // sans signature : cookies seuls

$empty = tempnam(sys_get_temp_dir(), 'e');
$b0 = req($blur, $jar);
$n1 = req($netBare, $jar);
$n2 = req($netUrl, $jar);
$n3 = req($netUrl, $empty);
printf("\nBASE FLOUE (signature)        : HTTP %d\n", $b0['code']);
printf("NET + cookies, SANS signature : HTTP %d (%d octets)  <- stratégie principale de l'app\n", $n1['code'], strlen($n1['body']));
printf("NET + cookies                 : HTTP %d (%d octets)\n", $n2['code'], strlen($n2['body']));
printf("NET sans cookies (sig seule)  : HTTP %d\n", $n3['code']);
