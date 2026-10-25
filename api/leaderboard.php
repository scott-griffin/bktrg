<?php
// Train Rat leaderboard API (originally Dragon Run)
// GET  → full board (top 100) as JSON — public fields only, never tokens
// POST {name, score, token, cheese, secs, pows, char, flag} → submit a score; one row per name, best kept
// POST {rename: {from, to}, token} → rename a player; the row follows; taken names are rejected
// POST {flag: {name, code}, token} → set a player's country flag (two-letter ISO code) without playing a run
// POST {claim: {name}, token} → "is this name mine?" — hands the rat code back to the connection that first claimed it
// Names are owned by a device token ("rat code"): the first token to score under a name claims it;
// later writes must present it. Every mutation is appended to an undo log that nothing deletes.

date_default_timezone_set('America/New_York'); // without this PHP defaults to UTC here, which dated evening scores tomorrow (log entries before 2026-10-23 are UTC)

$allowed_origins = ['https://bktrg.com', 'https://www.bktrg.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Outside the repo and the docroot: a tracked scores.json made /dev dirty on every saved score, which silently froze the sync-dev-main cron (found 2026-10-22).
// CONFIGURE THESE THREE PATHS for the hosting server: a writable directory OUTSIDE the web root.
$dataDir = getenv('TRAINRAT_DATA') ?: '/var/www/trainrat-data';
$file = $dataDir . '/bktrg_scores.json';
$logFile = $dataDir . '/bktrg_scores_log.jsonl';
$ghostDir = $dataDir . '/bktrg_ghosts';   // one ghost per player: the lane/height trace of the run that set their best

function ghostPath($name) {
    global $ghostDir;
    return $ghostDir . '/' . strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name)) . '.json';
}
// Ghosts exist only for players on the board: after every board write, delete any ghost whose player is no longer a row.
function gcGhosts($scores) {
    global $ghostDir;
    if (!is_dir($ghostDir)) return;
    $keep = [];
    foreach ($scores as $r) $keep[basename(ghostPath($r['name']))] = true;
    foreach (scandir($ghostDir) as $f) {
        if (substr($f, -5) === '.json' && empty($keep[$f])) @unlink($ghostDir . '/' . $f);
    }
}

// Where the first nineteen players are from, so the board shows flags without anyone having to visit settings first.
// A fallback, not a record: the moment a player picks their own flag it is stored on their row and this map stops applying to them.
$seedFlags = [
    'ben' => 'US', 'rebecca' => 'US', 'grammy' => 'US', 'papi' => 'US', 'thomas' => 'US',
    'lily' => 'US', 'kimmy bf' => 'US', 'evie' => 'US',
    'kent' => 'AU', 'gn' => 'AU', 'scott' => 'AU', 'ellie' => 'AU', 'gdad' => 'AU',
    'di' => 'AU', 'jaydan' => 'AU',
    'frans' => 'NL', 'sanne' => 'NL', 'willem' => 'NL',
    'lenny' => 'DE',
];

// One board snapshot per day (the board as it stood at that day's first activity), kept for a year — disputes read a file instead of replaying the log.
function dailySnapshot() {
    global $file;
    global $dataDir;
    $dir = $dataDir . '/bktrg_history';
    $snap = $dir . '/scores-' . date('Y-m-d') . '.json';
    if (is_file($snap) || !is_file($file)) return;
    if (!is_dir($dir)) @mkdir($dir, 0755);
    @copy($file, $snap);
    $cutoff = date('Y-m-d', strtotime('-365 days'));
    foreach (scandir($dir) as $f) {
        if (preg_match('/^scores-(\d{4}-\d{2}-\d{2})\.json$/', $f, $m) && $m[1] < $cutoff) @unlink($dir . '/' . $f);
    }
}

function clientIp() {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}
// Rat codes were 8 chars; they are 4 now. The row's stored token is the authority: a device
// still holding the old 8-char code authenticates when the row's token is a prefix of what it
// submits. Short-to-long never matches, so a cleared device can't guess its way past a legacy row.
function tokenMatches($rowToken, $submittedToken) {
    if ($rowToken === '' || $rowToken === null) return false;
    if ($submittedToken === '' || $submittedToken === null) return false;
    return substr($submittedToken, 0, strlen($rowToken)) === $rowToken;
}
function logit($entry) {
    global $logFile;
    $entry['ts'] = date('Y-m-d H:i:s');
    $entry['ip'] = clientIp();
    file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}
// A country as two uppercase letters, or '' — the picker's list lives in the game, so anything well-formed is accepted here.
function cleanFlag($raw) {
    return substr(preg_replace('/[^A-Z]/', '', strtoupper(trim($raw ?? ''))), 0, 2);
}
function rowFlag($row) {
    global $seedFlags;
    return $row['flag'] ?? $seedFlags[strtolower($row['name'])] ?? '';
}
// Did this connection ever score or rename under this name? The log is the only record of who played from where.
function sameConnectionPlayed($name) {
    global $logFile;
    $ip = clientIp();
    if (!$ip || !file_exists($logFile)) return false;
    foreach (file($logFile, FILE_IGNORE_NEW_LINES) as $line) {
        if (strpos($line, $ip) === false) continue;
        $e = json_decode($line, true);
        if (!$e || ($e['ip'] ?? '') !== $ip) continue;
        if ($e['action'] !== 'score' && $e['action'] !== 'rename') continue;
        if (strcasecmp($e['name'] ?? $e['to'] ?? '', $name) === 0) return true;
    }
    return false;
}
// Names nobody has taken, so a player turned away from a taken name has somewhere to go.
function freeNames($name, $scores) {
    $taken = array_map(fn($r) => strtolower($r['name']), $scores);
    $stem = substr($name, 0, 18);
    $out = [];
    foreach (['2', '3', '7', '99'] as $suffix) {
        if (!in_array(strtolower($stem . $suffix), $taken)) $out[] = $stem . $suffix;
        if (count($out) === 3) break;
    }
    return $out;
}
function publicRows($scores) {
    return array_map(fn($r) => [
        'name' => $r['name'], 'score' => $r['score'], 'date' => $r['date'],
        'cheese' => $r['cheese'] ?? null, 'secs' => $r['secs'] ?? null,
        'char' => ($r['char'] ?? '') ?: 'rat',   // runs from before the game tracked characters have none recorded, and a rat is what they will have been
        'ghost' => is_file(ghostPath($r['name'])),
        'flag' => rowFlag($r),
    ], $scores);
}

// GET — return the whole board (the file already caps at 100)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['ghost'])) {   // ?ghost=<name> → that player's best-run replay
        $p = ghostPath(htmlspecialchars(trim($_GET['ghost']), ENT_QUOTES, 'UTF-8'));
        if (is_file($p)) { readfile($p); } else { http_response_code(404); echo json_encode(['error' => 'no replay']); }
        exit;
    }
    if (!file_exists($file)) { echo '[]'; exit; }
    $scores = json_decode(file_get_contents($file), true) ?: [];
    echo json_encode(publicRows(array_slice($scores, 0, 100)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dailySnapshot();   // before any mutation, so each day's file is the board at the day's start
    $input = json_decode(file_get_contents('php://input'), true);
    $token = substr(trim($input['token'] ?? ''), 0, 64);

    // ---- claim: who owns this name, and is it this player? ----
    // A device that loses its rat code (new phone, cleared browser) is otherwise locked out of its own name for good.
    if (isset($input['claim'])) {
        $nameRaw = trim($input['claim']['name'] ?? '');
        if (!$nameRaw || strlen($nameRaw) > 20) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid name']);
            exit;
        }
        $name = htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8');
        $scores = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $row = null;
        foreach ($scores as $r) {
            if (strcasecmp($r['name'], $name) === 0) { $row = $r; break; }
        }
        if (!$row || empty($row['token'])) {          // unclaimed: scoring under it will claim it
            echo json_encode(['status' => 'free']);
        } elseif (tokenMatches($row['token'], $token)) {
            echo json_encode(['status' => 'yours']);
        } elseif (sameConnectionPlayed($name)) {      // played from this same connection before — we think this is you
            logit(['action' => 'claim-match', 'name' => $name]);
            echo json_encode(['status' => 'match', 'code' => $row['token'], 'score' => $row['score'], 'date' => $row['date'], 'flag' => rowFlag($row), 'suggest' => freeNames($name, $scores)]);
        } else {
            logit(['action' => 'claim-denied', 'name' => $name]);
            echo json_encode(['status' => 'taken', 'score' => $row['score'], 'flag' => rowFlag($row), 'suggest' => freeNames($name, $scores)]);
        }
        exit;
    }

    // ---- flag: change a player's country from settings, no run needed ----
    if (isset($input['flag']) && is_array($input['flag'])) {
        $nameRaw = trim($input['flag']['name'] ?? '');
        $code = cleanFlag($input['flag']['code'] ?? '');
        if (!$nameRaw || strlen($nameRaw) > 20 || strlen($code) !== 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid flag']);
            exit;
        }
        $name = htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8');
        $fp = fopen($file, 'c+');
        flock($fp, LOCK_EX);
        $scores = json_decode(stream_get_contents($fp), true) ?: [];
        $set = false;
        foreach ($scores as &$row) {
            if (strcasecmp($row['name'], $name) !== 0) continue;
            if (!empty($row['token']) && !tokenMatches($row['token'], $token)) {
                flock($fp, LOCK_UN); fclose($fp);
                logit(['action' => 'flag-rejected-token', 'name' => $name, 'flag' => $code]);
                http_response_code(403);
                echo json_encode(['error' => 'not your name']);
                exit;
            }
            if (empty($row['token']) && $token) $row['token'] = $token;   // same as rename and score: the first device to write to a legacy row claims it
            $row['flag'] = $code;
            $set = true;
            break;
        }
        unset($row);
        if ($set) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($scores, JSON_PRETTY_PRINT));
            logit(['action' => 'flag', 'name' => $name, 'flag' => $code]);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode(['ok' => true, 'set' => $set]);   // set=false means there is no row yet; the next saved score carries the flag
        exit;
    }

    // ---- rename ----
    if (isset($input['rename'])) {
        $fromRaw = trim($input['rename']['from'] ?? '');
        $toRaw   = trim($input['rename']['to'] ?? '');
        if (!$fromRaw || !$toRaw || strlen($toRaw) > 20) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid rename']);
            exit;
        }
        $from = htmlspecialchars($fromRaw, ENT_QUOTES, 'UTF-8');
        $to   = htmlspecialchars($toRaw, ENT_QUOTES, 'UTF-8');
        $fp = fopen($file, 'c+');
        flock($fp, LOCK_EX);
        $scores = json_decode(stream_get_contents($fp), true) ?: [];
        $fromIdx = -1; $toIdx = -1;
        foreach ($scores as $i => $row) {
            if (strcasecmp($row['name'], $from) === 0) $fromIdx = $i;
            elseif (strcasecmp($row['name'], $to) === 0) $toIdx = $i;
        }
        if ($toIdx >= 0) {   // names are unique: the target name is taken
            flock($fp, LOCK_UN); fclose($fp);
            logit(['action' => 'rename-rejected-taken', 'from' => $from, 'to' => $to]);
            http_response_code(409);
            echo json_encode(['error' => 'name taken']);
            exit;
        }
        if ($fromIdx >= 0) {
            if (!empty($scores[$fromIdx]['token']) && !tokenMatches($scores[$fromIdx]['token'], $token)) {
                flock($fp, LOCK_UN); fclose($fp);
                logit(['action' => 'rename-rejected-token', 'from' => $from, 'to' => $to]);
                http_response_code(403);
                echo json_encode(['error' => 'not your name']);
                exit;
            }
            if (empty($scores[$fromIdx]['token']) && $token) $scores[$fromIdx]['token'] = $token;
            // the seed flag is keyed on the old name, so write the resolved one onto the row before the name changes under it
            if (!isset($scores[$fromIdx]['flag'])) {
                $seeded = rowFlag($scores[$fromIdx]);
                if ($seeded) $scores[$fromIdx]['flag'] = $seeded;
            }
            $scores[$fromIdx]['name'] = $to;
            @rename(ghostPath($from), ghostPath($to));   // the replay follows the name
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($scores, JSON_PRETTY_PRINT));
            // the dispute record: what moved (best score) and who moved it (owner token's tail) travel with the rename entry
            logit(['action' => 'rename', 'from' => $from, 'to' => $to, 'score' => $scores[$fromIdx]['score'] ?? null, 'tok' => substr($scores[$fromIdx]['token'] ?? '', -4)]);
            gcGhosts($scores);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        echo json_encode(['ok' => true, 'renamed' => $fromIdx >= 0]);
        exit;
    }

    // ---- score ----
    $name  = trim($input['name'] ?? '');
    $score = intval($input['score'] ?? 0);
    $cheese = max(0, intval($input['cheese'] ?? 0));
    $secs   = max(0, intval($input['secs'] ?? 0));
    $pows   = max(0, intval($input['pows'] ?? 0));
    $char   = substr(preg_replace('/[^a-z]/', '', strtolower($input['char'] ?? '')), 0, 12);   // which character ran it — kept loose so a new one needs no change here
    $flag   = cleanFlag($input['flag'] ?? '');   // carried on every run so a device that knows its country can flag a row that has none

    if (!$name || strlen($name) > 20 || $score < 0 || $score > 99999999) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    // Device adoptions: names that were really one player on extra devices (admin-confirmed, see admin-merge log entries).
    // A run arriving under the old name counts for the real one, and the response hands the device its true identity ('become'),
    // so each stray device heals itself the first time it plays. Entries are cheap to leave in place.
    $adoptions = ['kiran2' => 'Kiran', 'kiran3' => 'Kiran', 'willem2' => 'Willem', 'willem3' => 'Willem'];
    $adopt = false; $become = null;
    if (isset($adoptions[strtolower($name)])) {
        logit(['action' => 'adopt', 'from' => $name, 'to' => $adoptions[strtolower($name)], 'score' => $score]);
        $name = $adoptions[strtolower($name)];
        $adopt = true;
    }

    $fp = fopen($file, 'c+');
    flock($fp, LOCK_EX);
    $scores = json_decode(stream_get_contents($fp), true) ?: [];
    $found = false; $improved = false;
    foreach ($scores as &$row) {
        if (strcasecmp($row['name'], $name) === 0) {
            $found = true;
            if ($adopt) $become = ['name' => $name, 'code' => $row['token'] ?? ''];   // hand the device the real identity
            if (!$adopt && !empty($row['token']) && !tokenMatches($row['token'], $token)) {
                flock($fp, LOCK_UN); fclose($fp);
                logit(['action' => 'score-rejected-token', 'name' => $name, 'score' => $score, 'cheese' => $cheese, 'secs' => $secs, 'pows' => $pows, 'char' => $char]);   // stats too, so a refused run can be restored whole
                http_response_code(403);
                echo json_encode(['error' => 'name taken']);
                exit;
            }
            if (empty($row['token']) && $token) $row['token'] = $token;   // first device to show up claims a legacy row
            if ($flag && empty($row['flag'])) $row['flag'] = $flag;   // only seed a flag on an unflagged row — explicit flag changes come through the /flag endpoint, so a stale localStorage flag can't clobber the row
            if ($score > $row['score']) {
                $row['score'] = $score; $row['date'] = date('Y-m-d');
                $row['cheese'] = $cheese; $row['secs'] = $secs; $row['pows'] = $pows; $row['char'] = $char;
                $improved = true;
            }
            break;
        }
    }
    unset($row);
    if (!$found) { $scores[] = ['name' => $name, 'score' => $score, 'date' => date('Y-m-d'), 'token' => $token, 'cheese' => $cheese, 'secs' => $secs, 'pows' => $pows, 'char' => $char, 'flag' => $flag]; $improved = true; }
    usort($scores, fn($a, $b) => $b['score'] - $a['score']);
    $scores = array_slice($scores, 0, 100); // keep top 100 in file
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($scores, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);

    logit(['action' => 'score', 'name' => $name, 'score' => $score, 'cheese' => $cheese, 'secs' => $secs, 'pows' => $pows, 'char' => $char, 'flag' => $flag]);

    $rank = 0;
    foreach ($scores as $i => $r) { if (strcasecmp($r['name'], $name) === 0) { $rank = $i + 1; break; } }

    // a new personal best brings its ghost along — but only runs that rank on the board keep one
    if ($improved && $rank > 0 && isset($input['replay']['samples']) && is_array($input['replay']['samples'])) {
        $samples = $input['replay']['samples'];
        if (count($samples) >= 8 && count($samples) <= 160000) {
            if (!is_dir($ghostDir)) @mkdir($ghostDir, 0755);
            file_put_contents(ghostPath($name), json_encode([
                'name' => $name,
                'char' => $char ?: 'rat',
                'score' => $score,
                'date' => date('Y-m-d'),
                'samples' => array_map('intval', $samples),
            ]));
            logit(['action' => 'ghost', 'name' => $name, 'score' => $score, 'samples' => count($samples)]);
        }
    }
    gcGhosts($scores);

    echo json_encode(['ok' => true, 'rank' => $rank] + ($become && $become['code'] ? ['become' => $become] : []));
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
