<?php
/**
 * Rhythmic Beat Dance Court - MySQL Automated Workshop Tier & Slot Manager
 * 
 * Powered by MySQL database (`rhythmic_beat`):
 * - Table `workshop_slots`: Stores limits, prices, and manual offsets
 * - Table `workshop_bookings`: Stores all confirmed attendee registrations
 * 
 * Dynamic Rules (5 Progressive Tiers):
 * - Slab 1: Slots 1–10   ➔ ₹899
 * - Slab 2: Slots 11–20  ➔ ₹999
 * - Slab 3: Slots 21–30  ➔ ₹1,099
 * - Slab 4: Slots 31–40  ➔ ₹1,199
 * - Slab 5: Slots 41–50  ➔ ₹1,299
 * - Total Capacity: 50 seats
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db-config.php';

$backupFile = __DIR__ . '/workshop-counts.json';

/**
 * Get workshop data from MySQL (with JSON backup fallback)
 */
function getWorkshopData(string $workshopId): array {
    global $backupFile;
    $pdo = getDbConnection();

    if ($pdo) {
        try {
            // Fetch configuration
            $stmt = $pdo->prepare("SELECT * FROM workshop_slots WHERE workshop_id = :id LIMIT 1");
            $stmt->execute(['id' => $workshopId]);
            $slot = $stmt->fetch();

            if (!$slot) {
                // Auto-seed if not yet present
                $insert = $pdo->prepare("INSERT INTO workshop_slots (workshop_id, title, tier1_limit, tier2_limit, tier1_price, tier2_price, tier3_price, max_seats, manual_offset) VALUES (:id, :title, 10, 20, 899, 999, 1099, 50, 0)");
                $insert->execute(['id' => $workshopId, 'title' => 'Pookkal Pookum Tharunam']);
                $slot = [
                    'workshop_id' => $workshopId,
                    'title' => 'Pookkal Pookum Tharunam',
                    'tier1_limit' => 10,
                    'tier2_limit' => 20,
                    'tier1_price' => 899,
                    'tier2_price' => 999,
                    'tier3_price' => 1099,
                    'max_seats' => 50,
                    'manual_offset' => 0
                ];
            }

            // Sum booked seats from confirmed bookings
            $bStmt = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) AS total_booked FROM workshop_bookings WHERE workshop_id = :id AND status LIKE '%Paid%'");
            $bStmt->execute(['id' => $workshopId]);
            $bRow = $bStmt->fetch();
            $realBooked = (int)($bRow['total_booked'] ?? 0);
            $manualOffset = (int)($slot['manual_offset'] ?? 0);

            return [
                'id' => $workshopId,
                'title' => $slot['title'] ?? 'Pookkal Pookum Tharunam',
                'booked' => max(0, $realBooked + $manualOffset),
                'real_booked' => $realBooked,
                'manual_offset' => $manualOffset,
                'tier1_limit' => (int)($slot['tier1_limit'] ?? 10),
                'tier2_limit' => (int)($slot['tier2_limit'] ?? 20),
                'tier1_price' => (int)($slot['tier1_price'] ?? 899),
                'tier2_price' => (int)($slot['tier2_price'] ?? 999),
                'tier3_price' => (int)($slot['tier3_price'] ?? 1099),
                'max_seats'   => max(1, (int)($slot['max_seats'] ?? 50)),
                'source' => 'mysql'
            ];
        } catch (Throwable $e) {
            error_log('[Slots/MySQL] ' . $e->getMessage());
        }
    }

    // Fallback to local JSON if MySQL is unavailable
    if (file_exists($backupFile)) {
        $raw = @file_get_contents($backupFile);
        $data = json_decode($raw, true);
        if (isset($data[$workshopId])) {
            $data[$workshopId]['source'] = 'json_fallback';
            return $data[$workshopId];
        }
    }

    return [
        'id' => $workshopId,
        'title' => 'Pookkal Pookum Tharunam',
        'booked' => 0,
        'tier1_limit' => 10,
        'tier2_limit' => 20,
        'tier1_price' => 899,
        'tier2_price' => 999,
        'tier3_price' => 1099,
        'max_seats' => 50,
        'source' => 'default'
    ];
}

/**
 * Calculate dynamic tier availability based on booked count
 */
function calculateWorkshopTiers(array $ws): array {
    $booked = (int)($ws['booked'] ?? 0);
    $t1Limit = (int)($ws['tier1_limit'] ?? 10);
    $t2Limit = (int)($ws['tier2_limit'] ?? 20);
    $maxSeats = max($t2Limit, (int)($ws['max_seats'] ?? 50));

    $t1Price = (int)($ws['tier1_price'] ?? 899);
    $t2Price = (int)($ws['tier2_price'] ?? 999);
    $t3Price = (int)($ws['tier3_price'] ?? 1099);

    $defTiers = [
        [
            'k'     => 'tier1',
            'label' => 'Slots 1–10',
            'range' => '1–10',
            'start' => 0,
            'limit' => $t1Limit,
            'amt'   => $t1Price
        ],
        [
            'k'     => 'tier2',
            'label' => 'Slots 11–20',
            'range' => '11–20',
            'start' => $t1Limit,
            'limit' => $t2Limit,
            'amt'   => $t2Price
        ],
        [
            'k'     => 'tier3',
            'label' => 'Slots 21+',
            'range' => '21+',
            'start' => $t2Limit,
            'limit' => $maxSeats,
            'amt'   => $t3Price
        ]
    ];

    $totalRemaining = max(0, $maxSeats - $booked);
    $activeKey = 'sold_out';
    $activePrice = $t3Price;
    $activeLabel = 'Sold Out';
    $urgencyText = "⚠️ Workshop Housefull! All slots are fully booked.";

    $tiers = [];
    foreach ($defTiers as $t) {
        $tierCap = $t['limit'] - $t['start'];
        $isFilled = ($booked >= $t['limit']);
        $isActive = ($booked >= $t['start'] && $booked < $t['limit']);
        $isUpcoming = ($booked < $t['start']);

        $seatsLeftInTier = max(0, $t['limit'] - max($t['start'], $booked));

        if ($isActive) {
            $activeKey = $t['k'];
            $activePrice = $t['amt'];
            $activeLabel = $t['label'];
            if ($t['k'] === 'tier3') {
                $urgencyText = "🔥 Standard Booking open at ₹" . number_format($t['amt']) . " (Slots 21 onwards)!";
            } else {
                $urgencyText = "🔥 Only {$seatsLeftInTier} of {$tierCap} slots left at ₹" . number_format($t['amt']) . "! (Seats {$t['range']})";
            }
        }

        if ($isFilled) {
            $note = "Filled ({$tierCap}/{$tierCap} seats booked)";
            $badge = "Sold Out";
            $disabledBadge = "Sold Out";
            $status = "filled";
        } elseif ($isActive) {
            if ($t['k'] === 'tier3') {
                $note = "₹" . number_format($t['amt']) . "/- · Standard Entry";
            } else {
                $note = "₹" . number_format($t['amt']) . "/- · Only {$seatsLeftInTier} slots remaining!";
            }
            $badge = "Active";
            $disabledBadge = "";
            $status = "active";
        } else {
            $note = "₹" . number_format($t['amt']) . "/- · Opens at seat " . ($t['start'] + 1);
            $badge = "Upcoming";
            $disabledBadge = "Upcoming";
            $status = "upcoming";
        }

        $tiers[] = [
            'k'             => $t['k'],
            'label'         => $t['label'],
            'range'         => $t['range'],
            'amt'           => $t['amt'],
            'note'          => $note,
            'badge'         => $badge,
            'status'        => $status,
            'disabled'      => !$isActive,
            'disabledBadge' => $disabledBadge,
            'remaining'     => ($t['k'] === 'tier3' ? null : $seatsLeftInTier),
            'limit'         => $tierCap,
            'total_limit'   => $t['limit']
        ];
    }

    return [
        'booked_count'    => $booked,
        'max_seats'       => $maxSeats,
        'total_remaining' => $totalRemaining,
        'is_sold_out'     => ($booked >= $maxSeats),
        'active_key'      => $activeKey,
        'active_price'    => $activePrice,
        'active_label'    => $activeLabel,
        'urgency_text'    => $urgencyText,
        'source'          => $ws['source'] ?? 'mysql',
        'tiers'           => $tiers
    ];
}

/**
 * Calculate multi-tier split breakdown for a requested quantity
 * Dynamic split across: Slots 1–10, Slots 11–20, and Slots 21–50 (₹1,099 flat)
 */
function calculateBookingSplit(array $ws, int $qty = 1): array {
    $qty = max(1, $qty);
    $booked = (int)($ws['booked'] ?? 0);
    $t1Limit = (int)($ws['tier1_limit'] ?? 10);
    $t2Limit = (int)($ws['tier2_limit'] ?? 20);
    $maxSeats = max($t2Limit, (int)($ws['max_seats'] ?? 50));

    $t1Price = (int)($ws['tier1_price'] ?? 899);
    $t2Price = (int)($ws['tier2_price'] ?? 999);
    $t3Price = (int)($ws['tier3_price'] ?? 1099);

    $tiers = [
        ['k' => 'tier1', 'label' => 'Slots 1–10',  'range' => '1–10',  'start' => 0,        'end' => $t1Limit,  'price' => $t1Price],
        ['k' => 'tier2', 'label' => 'Slots 11–20', 'range' => '11–20', 'start' => $t1Limit, 'end' => $t2Limit,  'price' => $t2Price],
        ['k' => 'tier3', 'label' => 'Slots 21+',   'range' => '21+',   'start' => $t2Limit, 'end' => $maxSeats, 'price' => $t3Price],
    ];

    $totalAvail = max(0, $maxSeats - $booked);
    $effectiveQty = min($qty, $totalAvail);

    $cursor = $booked;
    $rem = $effectiveQty;
    $breakdown = [];
    $totalAmount = 0;

    foreach ($tiers as $t) {
        if ($rem <= 0) break;
        if ($cursor >= $t['end']) continue;
        $availInTier = $t['end'] - max($t['start'], $cursor);
        if ($availInTier <= 0) continue;

        $take = min($rem, $availInTier);
        $sub = $take * $t['price'];
        $totalAmount += $sub;
        $breakdown[] = [
            'tier'       => $t['k'],
            'label'      => $t['label'],
            'range'      => $t['range'],
            'qty'        => $take,
            'unit_price' => $t['price'],
            'subtotal'   => $sub,
            'text'       => "{$take} ticket" . ($take > 1 ? "s" : "") . " @ ₹" . number_format($t['price'])
        ];
        $cursor += $take;
        $rem -= $take;
    }

    $isSplit = (count($breakdown) > 1);
    $splitNotice = "";
    if ($isSplit) {
        $first = $breakdown[0];
        $parts = array_map(fn($b) => "<b>{$b['qty']} ticket" . ($b['qty'] > 1 ? "s" : "") . " @ ₹" . number_format($b['unit_price']) . "</b>", $breakdown);
        $splitNotice = "Only {$first['qty']} seat" . ($first['qty'] > 1 ? "s" : "") . " remaining in {$first['label']} ({$first['range']}). Your booking covers " . implode(" + ", $parts) . ".";
    }

    return [
        'qty'           => $qty,
        'booked_before' => $booked,
        'max_seats'     => $maxSeats,
        'total_avail'   => $totalAvail,
        'effective_qty' => $effectiveQty,
        'is_sold_out'   => ($totalAvail <= 0),
        'total_amount'  => $totalAmount,
        'is_split'      => $isSplit,
        'split_notice'  => $splitNotice,
        'breakdown'     => $breakdown,
        'summary_label' => implode(' + ', array_map(fn($b) => $b['text'], $breakdown))
    ];
}

/**
 * Record a verified booking into MySQL
 */
function recordWorkshopBooking(string $workshopId, int $qty = 1, array $meta = []): array {
    global $backupFile;
    $pdo = getDbConnection();
    $qty = max(1, $qty);

    if ($pdo) {
        try {
            $ref = $meta['ref'] ?? ('RB-' . date('md') . '-' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4));
            $paymentId = $meta['payment_id'] ?? null;

            // Check if this payment or ref was already inserted
            if (!empty($ref) || !empty($paymentId)) {
                $checkStmt = $pdo->prepare("SELECT id FROM workshop_bookings WHERE (ref = :ref AND ref != '') OR (payment_id = :pid AND payment_id != '') LIMIT 1");
                $checkStmt->execute(['ref' => (string)$ref, 'pid' => (string)$paymentId]);
                if ($checkStmt->fetch()) {
                    return calculateWorkshopTiers(getWorkshopData($workshopId));
                }
            }

            // Insert into MySQL
            $stmt = $pdo->prepare("
                INSERT INTO workshop_bookings 
                (workshop_id, name, phone, email, tier, qty, amount, ref, payment_id, order_id, method, status)
                VALUES 
                (:workshop_id, :name, :phone, :email, :tier, :qty, :amount, :ref, :payment_id, :order_id, :method, :status)
            ");
            $stmt->execute([
                'workshop_id' => $workshopId,
                'name'        => $meta['name'] ?? '',
                'phone'       => $meta['phone'] ?? '',
                'email'       => $meta['email'] ?? '',
                'tier'        => $meta['tier'] ?? '',
                'qty'         => $qty,
                'amount'      => (float)($meta['amount'] ?? 0),
                'ref'         => $ref,
                'payment_id'  => $paymentId,
                'order_id'    => $meta['order_id'] ?? null,
                'method'      => $meta['method'] ?? 'Razorpay',
                'status'      => $meta['status'] ?? 'Paid & Confirmed'
            ]);
        } catch (Throwable $e) {
            error_log('[Slots/Record/MySQL] ' . $e->getMessage());
        }
    }

    // Also update JSON backup cache
    try {
        $raw = file_exists($backupFile) ? @file_get_contents($backupFile) : '';
        $counts = !empty($raw) ? json_decode($raw, true) : [];
        if (!is_array($counts)) $counts = [];
        if (!isset($counts[$workshopId])) {
            $counts[$workshopId] = [
                'id' => $workshopId, 
                'booked' => 0, 
                'tier1_limit' => 10, 
                'tier2_limit' => 20, 
                'tier1_price' => 899, 
                'tier2_price' => 999, 
                'tier3_price' => 1099,
                'max_seats' => 50,
                'history' => []
            ];
        }
        $counts[$workshopId]['booked'] = ($counts[$workshopId]['booked'] ?? 0) + $qty;
        @file_put_contents($backupFile, json_encode($counts, JSON_PRETTY_PRINT), LOCK_EX);
    } catch (Throwable $eBackup) {}

    return calculateWorkshopTiers(getWorkshopData($workshopId));
}

// -------------------------------------------------------------
// Direct HTTP API handling
// -------------------------------------------------------------
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $wsId = trim($_GET['id'] ?? 'pookkal-pookkum');
    $pdo = getDbConnection();

    // Admin override action (for cash or manual offline updates)
    $action = $_GET['action'] ?? '';
    if ($action === 'set_count' && isset($_GET['count'])) {
        $newCount = max(0, (int)$_GET['count']);
        if ($pdo) {
            try {
                // Update manual offset so real bookings + offset = newCount
                $bStmt = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) AS total_booked FROM workshop_bookings WHERE workshop_id = :id AND status LIKE '%Paid%'");
                $bStmt->execute(['id' => $wsId]);
                $real = (int)($bStmt->fetch()['total_booked'] ?? 0);
                $offset = $newCount - $real;

                $upd = $pdo->prepare("UPDATE workshop_slots SET manual_offset = :offset WHERE workshop_id = :id");
                $upd->execute(['offset' => $offset, 'id' => $wsId]);
            } catch (Throwable $eUpd) {
                error_log('[Slots/Admin/MySQL] ' . $eUpd->getMessage());
            }
        }
        $data = getWorkshopData($wsId);
        echo json_encode([
            'success' => true,
            'message' => "Booked count updated to $newCount in MySQL",
            'data' => calculateWorkshopTiers($data)
        ]);
        exit;
    }

    $wsData = getWorkshopData($wsId);
    $calc = calculateWorkshopTiers($wsData);
    $calc['splits'] = [];
    for ($q = 1; $q <= 5; $q++) {
        $calc['splits'][$q] = calculateBookingSplit($wsData, $q);
    }

    echo json_encode([
        'success' => true,
        'workshop_id' => $wsId,
        'data' => $calc
    ]);
    exit;
}
