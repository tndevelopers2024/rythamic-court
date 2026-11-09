<?php
/**
 * Rhythmic Beat Dance Court - MySQL Automated Workshop Tier & Slot Manager
 * 
 * Powered by MySQL database (`rhythmic_beat`):
 * - Table `workshop_slots`: Stores limits, prices, and manual offsets
 * - Table `workshop_bookings`: Stores all confirmed attendee registrations
 * 
 * Dynamic Rules:
 * - First 10 slots:    ₹899
 * - Second 10 slots:   ₹999 (slots 11 to 20)
 * - Thereafter:        ₹1099 (slots 21 onwards)
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
                $insert = $pdo->prepare("INSERT INTO workshop_slots (workshop_id, title, tier1_limit, tier2_limit, tier1_price, tier2_price, tier3_price, manual_offset) VALUES (:id, :title, 10, 20, 899, 999, 1099, 0)");
                $insert->execute(['id' => $workshopId, 'title' => 'Pookkal Pookum Tharunam']);
                $slot = [
                    'workshop_id' => $workshopId,
                    'title' => 'Pookkal Pookum Tharunam',
                    'tier1_limit' => 10,
                    'tier2_limit' => 20,
                    'tier1_price' => 899,
                    'tier2_price' => 999,
                    'tier3_price' => 1099,
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
    $t1Price = (int)($ws['tier1_price'] ?? 899);
    $t2Price = (int)($ws['tier2_price'] ?? 999);
    $t3Price = (int)($ws['tier3_price'] ?? 1099);

    $t1Left = max(0, $t1Limit - $booked);
    $t2Left = max(0, $t2Limit - max($t1Limit, $booked));

    // Determine current active tier
    if ($booked < $t1Limit) {
        $activeKey = 'first10';
        $activePrice = $t1Price;
        $urgencyText = "🔥 Only {$t1Left} of {$t1Limit} slots left at ₹{$t1Price}!";
    } elseif ($booked < $t2Limit) {
        $activeKey = 'second10';
        $activePrice = $t2Price;
        $urgencyText = "⚡ Second Tier: Only {$t2Left} slots left at ₹{$t2Price}!";
    } else {
        $activeKey = 'thereafter';
        $activePrice = $t3Price;
        $urgencyText = "✨ Standard Booking open at ₹{$t3Price}";
    }

    $tiers = [
        [
            'k' => 'first10',
            'label' => 'First 10 Slots',
            'note' => $booked >= $t1Limit 
                ? 'Early bird sold out (10/10 slots filled)' 
                : "₹{$t1Price}/- · Only {$t1Left} slots remaining!",
            'amt' => $t1Price,
            'badge' => $booked >= $t1Limit ? 'Sold Out' : "",
            'disabled' => ($booked >= $t1Limit),
            'disabledBadge' => 'Sold Out',
            'remaining' => $t1Left,
            'limit' => $t1Limit
        ],
        [
            'k' => 'second10',
            'label' => 'Second 10 Slots',
            'note' => $booked < $t1Limit 
                ? "₹{$t2Price}/- · Unlocks after first 10 slots fill" 
                : ($booked >= $t2Limit 
                    ? 'Second slot sold out (10/10 slots filled)' 
                    : "₹{$t2Price}/- · Only {$t2Left} slots remaining!"),
            'amt' => $t2Price,
            'badge' => $booked < $t1Limit 
                ? 'Opens Next' 
                : ($booked >= $t2Limit ? 'Sold Out' : "₹{$t2Price} · Active"),
            'disabled' => ($booked < $t1Limit || $booked >= $t2Limit),
            'disabledBadge' => $booked < $t1Limit ? 'Upcoming' : 'Sold Out',
            'remaining' => $t2Left,
            'limit' => 10
        ],
        [
            'k' => 'thereafter',
            'label' => 'Standard Thereafter Pass',
            'note' => $booked < $t2Limit 
                ? "₹{$t3Price}/- · Standard rate (opens after slot 20)" 
                : "₹{$t3Price}/- · Regular Entry",
            'amt' => $t3Price,
            'badge' => $booked < $t2Limit ? 'Opens at Slot 21' : "₹{$t3Price} · Active",
            'disabled' => ($booked < $t2Limit),
            'disabledBadge' => 'Upcoming',
            'remaining' => null,
            'limit' => null
        ]
    ];

    return [
        'booked_count' => $booked,
        'active_key'   => $activeKey,
        'active_price' => $activePrice,
        'urgency_text' => $urgencyText,
        'source'       => $ws['source'] ?? 'mysql',
        'tiers'        => $tiers
    ];
}

/**
 * Calculate multi-tier split breakdown for a requested quantity
 * e.g., if only 2 slots left in Tier 1 and customer books 3:
 * 2 seats charged @ Tier 1 price + 1 seat charged @ Tier 2 price!
 */
function calculateBookingSplit(array $ws, int $qty = 1): array {
    $qty = max(1, $qty);
    $booked = (int)($ws['booked'] ?? 0);
    $t1Limit = (int)($ws['tier1_limit'] ?? 10);
    $t2Limit = (int)($ws['tier2_limit'] ?? 20);
    $t1Price = (int)($ws['tier1_price'] ?? 899);
    $t2Price = (int)($ws['tier2_price'] ?? 999);
    $t3Price = (int)($ws['tier3_price'] ?? 1099);

    $availT1 = max(0, $t1Limit - $booked);
    $takeT1 = min($qty, $availT1);

    $rem1 = $qty - $takeT1;
    $bookedT2 = max(0, ($booked + $takeT1) - $t1Limit);
    $availT2 = max(0, ($t2Limit - $t1Limit) - $bookedT2);
    $takeT2 = min($rem1, $availT2);

    $takeT3 = $rem1 - $takeT2;

    $breakdown = [];
    $totalAmount = 0;

    if ($takeT1 > 0) {
        $sub = $takeT1 * $t1Price;
        $totalAmount += $sub;
        $breakdown[] = [
            'tier'       => 'first10',
            'label'      => 'First 10 Slots',
            'qty'        => $takeT1,
            'unit_price' => $t1Price,
            'subtotal'   => $sub,
            'text'       => "{$takeT1} ticket" . ($takeT1 > 1 ? "s" : "") . " @ ₹{$t1Price}"
        ];
    }
    if ($takeT2 > 0) {
        $sub = $takeT2 * $t2Price;
        $totalAmount += $sub;
        $breakdown[] = [
            'tier'       => 'second10',
            'label'      => 'Second 10 Slots',
            'qty'        => $takeT2,
            'unit_price' => $t2Price,
            'subtotal'   => $sub,
            'text'       => "{$takeT2} ticket" . ($takeT2 > 1 ? "s" : "") . " @ ₹{$t2Price}"
        ];
    }
    if ($takeT3 > 0) {
        $sub = $takeT3 * $t3Price;
        $totalAmount += $sub;
        $breakdown[] = [
            'tier'       => 'thereafter',
            'label'      => 'Standard Thereafter Pass',
            'qty'        => $takeT3,
            'unit_price' => $t3Price,
            'subtotal'   => $sub,
            'text'       => "{$takeT3} ticket" . ($takeT3 > 1 ? "s" : "") . " @ ₹{$t3Price}"
        ];
    }

    $isSplit = (count($breakdown) > 1);
    $splitNotice = "";
    if ($isSplit) {
        $parts = array_map(fn($b) => "<b>{$b['qty']} ticket" . ($b['qty'] > 1 ? "s" : "") . " @ ₹{$b['unit_price']}</b>", $breakdown);
        if ($takeT1 > 0 && $takeT2 > 0 && $takeT3 == 0) {
            $splitNotice = "Only {$takeT1} seat" . ($takeT1 > 1 ? "s" : "") . " available in Tier 1. {$parts[0]} and {$parts[1]}.";
        } elseif ($takeT2 > 0 && $takeT3 > 0 && $takeT1 == 0) {
            $splitNotice = "Only {$takeT2} seat" . ($takeT2 > 1 ? "s" : "") . " available in Tier 2. {$parts[0]} and {$parts[1]}.";
        } else {
            $splitNotice = implode(" + ", $parts);
        }
    }

    return [
        'qty'           => $qty,
        'booked_before' => $booked,
        'avail_tier1'   => $availT1,
        'avail_tier2'   => $availT2,
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
            $counts[$workshopId] = ['id' => $workshopId, 'booked' => 0, 'tier1_limit' => 10, 'tier2_limit' => 20, 'tier1_price' => 899, 'tier2_price' => 999, 'tier3_price' => 1099, 'history' => []];
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
