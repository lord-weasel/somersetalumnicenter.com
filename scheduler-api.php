<?php
// Shared storage endpoint for the Somerset Alumni Center scheduler.
// Put this file in the same folder as scheduler_shared_server.html.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataFile = __DIR__ . '/scheduler-data.json';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function defaultData(): array {
    return [
        'employees' => [
            'Gianni Anderson', 'Makenna Baker', 'Thomas Bradshaw', 'James Brown',
            'Julian Carroll', 'Corban Cimala', 'Mattie Cooper', 'Jaycee Cothron',
            'Jacob Estep', 'Keaton Goetz', 'Jordan Gragg', 'Charleston Girdler',
            'Tristian Harrell', 'Hannah Haste', 'Hayden Hernandez', 'Tripp Hoseclaw',
            'Ty Jacobs', 'Marcus Jones', 'Gavyn Kozak', 'Clark Litteral',
            'Josh Lewis', 'Paisley Poore', 'Leah Quillen', 'Will Robinson',
            'Adelyn White', 'Sarah White', 'Cameron Underwood'
        ],
        'schedules' => ['frontdesk' => new stdClass(), 'lifeguard' => new stdClass()],
        'currentYear' => intval(date('Y')),
        'currentMonth' => intval(date('n')) - 1,
        'activeShift' => 'am',
        'activeCalendar' => 'frontdesk',
        'updatedAt' => gmdate('c')
    ];
}

function sendJson($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizeData($data): array {
    $defaults = defaultData();
    if (!is_array($data)) return $defaults;

    $employees = [];
    if (isset($data['employees']) && is_array($data['employees'])) {
        foreach ($data['employees'] as $name) {
            if (!is_string($name)) continue;
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name !== '') $employees[] = $name;
        }
    }
    if (!$employees) $employees = $defaults['employees'];

    $schedules = ['frontdesk' => [], 'lifeguard' => []];
    if (isset($data['schedules']) && is_array($data['schedules'])) {
        foreach (['frontdesk', 'lifeguard'] as $calKey) {
            if (!isset($data['schedules'][$calKey]) || !is_array($data['schedules'][$calKey])) continue;
            foreach ($data['schedules'][$calKey] as $dateKey => $entries) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', strval($dateKey)) || !is_array($entries)) continue;
                $cleanEntries = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry)) continue;
                    $name = isset($entry['name']) ? trim(strval($entry['name'])) : '';
                    $shift = isset($entry['shift']) ? strval($entry['shift']) : 'am';
                    if ($name === '') continue;
                    if (!in_array($shift, ['am', 'pm', 'sat'], true)) $shift = 'am';
                    $cleanEntries[] = ['name' => $name, 'shift' => $shift];
                }
                if ($cleanEntries) $schedules[$calKey][$dateKey] = $cleanEntries;
            }
        }
    }

    return [
        'employees' => $employees,
        'schedules' => $schedules,
        'currentYear' => isset($data['currentYear']) ? intval($data['currentYear']) : $defaults['currentYear'],
        'currentMonth' => isset($data['currentMonth']) ? max(0, min(11, intval($data['currentMonth']))) : $defaults['currentMonth'],
        'activeShift' => (isset($data['activeShift']) && in_array($data['activeShift'], ['am', 'pm'], true)) ? $data['activeShift'] : 'am',
        'activeCalendar' => (isset($data['activeCalendar']) && in_array($data['activeCalendar'], ['frontdesk', 'lifeguard'], true)) ? $data['activeCalendar'] : 'frontdesk',
        'updatedAt' => gmdate('c')
    ];
}

if ($method === 'GET') {
    if (!file_exists($dataFile)) {
        sendJson(defaultData());
    }

    $fp = fopen($dataFile, 'r');
    if (!$fp) sendJson(defaultData());
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $decoded = json_decode($raw, true);
    sendJson(normalizeData($decoded));
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        sendJson(['ok' => false, 'error' => 'Invalid JSON.'], 400);
    }

    $clean = normalizeData($decoded);
    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        sendJson(['ok' => false, 'error' => 'Could not encode JSON.'], 500);
    }

    $fp = fopen($dataFile, 'c+');
    if (!$fp) {
        sendJson(['ok' => false, 'error' => 'Data file is not writable.'], 500);
    }

    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    sendJson(['ok' => true, 'savedAt' => $clean['updatedAt']]);
}

sendJson(['ok' => false, 'error' => 'Method not allowed.'], 405);
