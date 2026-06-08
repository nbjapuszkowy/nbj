<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$ADMIN_PASSWORD = 'NBJ2026!';
$DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$DATA_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . 'nbj-data.json';

$CURRENCIES = ['zwykle', 'zlote', 'diamentowe', 'sekretne', 'og'];

function send_json(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function fail(string $message, int $status = 400): void {
    http_response_code($status);
    send_json(['ok' => false, 'error' => $message]);
}

function now_iso(): string {
    return date('c');
}

function normalize_login(string $login): string {
    $login = mb_strtolower(trim($login), 'UTF-8');
    return preg_replace('/\s+/', '', $login) ?? '';
}

function empty_balances(): array {
    return [
        'zwykle' => 0,
        'zlote' => 0,
        'diamentowe' => 0,
        'sekretne' => 0,
        'og' => 0
    ];
}

function random_id(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(8)) . '_' . time();
}

function account_no(): string {
    $parts = [];
    for ($i = 0; $i < 5; $i++) {
        $parts[] = (string) random_int(1000, 9999);
    }
    return 'NBJ ' . implode(' ', $parts);
}

function ensure_storage(): void {
    global $DATA_DIR, $DATA_FILE;

    if (!is_dir($DATA_DIR)) {
        if (!mkdir($DATA_DIR, 0755, true) && !is_dir($DATA_DIR)) {
            fail('Nie można utworzyć folderu data na hostingu.', 500);
        }
    }

    $htaccess = $DATA_DIR . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }

    if (!file_exists($DATA_FILE)) {
        $default = [
            'users' => [],
            'transactions' => [],
            'createdAt' => now_iso()
        ];
        file_put_contents($DATA_FILE, json_encode($default, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
}

function normalize_db(array $db): array {
    global $CURRENCIES;

    if (!isset($db['users']) || !is_array($db['users'])) $db['users'] = [];
    if (!isset($db['transactions']) || !is_array($db['transactions'])) $db['transactions'] = [];
    if (!isset($db['createdAt'])) $db['createdAt'] = now_iso();

    foreach ($db['users'] as &$u) {
        if (!isset($u['id'])) $u['id'] = random_id('user');
        if (!isset($u['name'])) $u['name'] = 'Gracz';
        if (!isset($u['login'])) $u['login'] = 'gracz';
        if (!isset($u['status'])) $u['status'] = 'pending';
        if (!isset($u['accountNo'])) $u['accountNo'] = account_no();
        if (!isset($u['createdAt'])) $u['createdAt'] = now_iso();
        if (!isset($u['approvedAt'])) $u['approvedAt'] = null;
        if (!isset($u['blockedAt'])) $u['blockedAt'] = null;
        if (!isset($u['balances']) || !is_array($u['balances'])) $u['balances'] = empty_balances();

        $base = empty_balances();
        foreach ($CURRENCIES as $c) {
            $base[$c] = max(0, (int)($u['balances'][$c] ?? 0));
        }
        $u['balances'] = $base;
    }
    unset($u);

    return $db;
}

function load_db(): array {
    global $DATA_FILE;
    ensure_storage();

    $raw = file_get_contents($DATA_FILE);
    $db = json_decode($raw ?: '', true);

    if (!is_array($db)) {
        $db = ['users' => [], 'transactions' => [], 'createdAt' => now_iso()];
    }

    return normalize_db($db);
}

function save_db(array $db): void {
    global $DATA_FILE;
    ensure_storage();

    $db = normalize_db($db);
    $ok = file_put_contents($DATA_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

    if ($ok === false) {
        fail('Nie można zapisać danych JSON na hostingu.', 500);
    }
}

function input_data(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function clean_user(array $u, bool $withSensitive = false): array {
    if (!$withSensitive) {
        unset($u['password'], $u['passwordHash']);
    }
    return $u;
}

function verify_password_for_user(array $u, string $password): bool {
    if (isset($u['passwordHash']) && password_verify($password, (string)$u['passwordHash'])) {
        return true;
    }

    // obsługa starych danych importowanych z wersji HTML
    if (isset($u['password']) && hash_equals((string)$u['password'], $password)) {
        return true;
    }

    return false;
}

function require_admin(): void {
    if (empty($_SESSION['nbj_admin'])) {
        fail('Brak dostępu do panelu admina.', 403);
    }
}

function require_user(array $db): array {
    $id = $_SESSION['nbj_user_id'] ?? '';
    if (!$id) fail('Musisz być zalogowany.', 401);

    foreach ($db['users'] as $u) {
        if (($u['id'] ?? '') === $id) {
            if (($u['status'] ?? '') !== 'approved') {
                unset($_SESSION['nbj_user_id']);
                fail('Konto nie jest aktywne.', 403);
            }
            return $u;
        }
    }

    unset($_SESSION['nbj_user_id']);
    fail('Nie znaleziono konta.', 404);
}

function user_index_by_id(array $db, string $id): int {
    foreach ($db['users'] as $i => $u) {
        if (($u['id'] ?? '') === $id) return $i;
    }
    return -1;
}

function user_index_by_login_or_account(array $db, string $value): int {
    $login = normalize_login($value);
    $raw = mb_strtolower(trim($value), 'UTF-8');

    foreach ($db['users'] as $i => $u) {
        $uLogin = normalize_login((string)($u['login'] ?? ''));
        $uAcc = mb_strtolower((string)($u['accountNo'] ?? ''), 'UTF-8');

        if ($uLogin === $login || $uAcc === $raw) {
            return $i;
        }
    }

    return -1;
}

function user_exists_login(array $db, string $login): bool {
    $login = normalize_login($login);
    foreach ($db['users'] as $u) {
        if (normalize_login((string)($u['login'] ?? '')) === $login) return true;
    }
    return false;
}

function user_display(?string $id, array $users): string {
    if (!$id) return 'Bank NBJ';
    foreach ($users as $u) {
        if (($u['id'] ?? '') === $id) {
            return ($u['name'] ?? 'Gracz') . ' (@' . ($u['login'] ?? 'login') . ')';
        }
    }
    return 'Gracz';
}

function tx_for_user(array $db, string $userId): array {
    $result = [];
    foreach ($db['transactions'] as $tx) {
        if (($tx['from'] ?? null) === $userId || ($tx['to'] ?? null) === $userId) {
            $label = $tx['type'] ?? 'operacja';
            $sign = '';
            $otherSide = 'Bank NBJ';

            if (($tx['type'] ?? '') === 'transfer') {
                if (($tx['from'] ?? '') === $userId) {
                    $label = 'Przelew wychodzący';
                    $sign = '-';
                    $otherSide = user_display($tx['to'] ?? null, $db['users']);
                } else {
                    $label = 'Przelew przychodzący';
                    $sign = '+';
                    $otherSide = user_display($tx['from'] ?? null, $db['users']);
                }
            } elseif (($tx['type'] ?? '') === 'admin_add') {
                $label = 'Zasilenie od admina';
                $sign = '+';
            } elseif (($tx['type'] ?? '') === 'admin_subtract') {
                $label = 'Potrącenie admina';
                $sign = '-';
            } elseif (($tx['type'] ?? '') === 'admin_set') {
                $label = 'Ustawienie salda';
                $sign = '=';
            } elseif (($tx['type'] ?? '') === 'register') {
                $label = 'Rejestracja';
            } elseif (($tx['type'] ?? '') === 'status') {
                $label = 'Zmiana statusu';
            }

            $tx['label'] = $label;
            $tx['sign'] = $sign;
            $tx['otherSide'] = $otherSide;
            $result[] = $tx;
        }
    }
    return $result;
}

function with_user_transactions(array $db, array $u): array {
    $safe = clean_user($u);
    $safe['transactions'] = tx_for_user($db, (string)$u['id']);
    return $safe;
}

$action = $_GET['action'] ?? '';
$data = input_data();

try {
    $db = load_db();

    switch ($action) {
        case 'register': {
            $name = trim((string)($data['name'] ?? ''));
            $login = normalize_login((string)($data['login'] ?? ''));
            $password = (string)($data['password'] ?? '');

            if (mb_strlen($name, 'UTF-8') < 2) fail('Podaj poprawne imię.');
            if (mb_strlen($login, 'UTF-8') < 3) fail('Login musi mieć minimum 3 znaki.');
            if (mb_strlen($password, 'UTF-8') < 4) fail('Hasło musi mieć minimum 4 znaki.');
            if (user_exists_login($db, $login)) fail('Taki login już istnieje.');

            $user = [
                'id' => random_id('user'),
                'name' => $name,
                'login' => $login,
                'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => 'pending',
                'accountNo' => account_no(),
                'balances' => empty_balances(),
                'createdAt' => now_iso(),
                'approvedAt' => null,
                'blockedAt' => null,
                'note' => ''
            ];

            array_unshift($db['transactions'], [
                'id' => random_id('tx'),
                'type' => 'register',
                'from' => null,
                'to' => $user['id'],
                'currency' => null,
                'amount' => 0,
                'title' => 'Rejestracja konta — oczekuje na zatwierdzenie',
                'createdAt' => now_iso()
            ]);

            $db['users'][] = $user;
            save_db($db);
            send_json(['ok' => true]);
        }

        case 'login': {
            $login = normalize_login((string)($data['login'] ?? ''));
            $password = (string)($data['password'] ?? '');

            $found = null;
            foreach ($db['users'] as $u) {
                if (normalize_login((string)($u['login'] ?? '')) === $login) {
                    $found = $u;
                    break;
                }
            }

            if (!$found || !verify_password_for_user($found, $password)) {
                fail('Nieprawidłowy login albo hasło.', 401);
            }

            if (($found['status'] ?? '') === 'pending') fail('To konto oczekuje na zatwierdzenie przez admina.', 403);
            if (($found['status'] ?? '') === 'blocked') fail('To konto jest zablokowane przez admina.', 403);

            $_SESSION['nbj_user_id'] = $found['id'];
            send_json(['ok' => true, 'user' => with_user_transactions($db, $found)]);
        }

        case 'me': {
            if (empty($_SESSION['nbj_user_id'])) send_json(['ok' => true, 'user' => null]);
            $u = require_user($db);
            send_json(['ok' => true, 'user' => with_user_transactions($db, $u)]);
        }

        case 'logout': {
            unset($_SESSION['nbj_user_id']);
            send_json(['ok' => true]);
        }

        case 'transfer': {
            $sender = require_user($db);
            $senderIdx = user_index_by_id($db, (string)$sender['id']);

            $recipientValue = trim((string)($data['recipient'] ?? ''));
            $recipientIdx = user_index_by_login_or_account($db, $recipientValue);
            if ($recipientIdx < 0) fail('Nie znaleziono odbiorcy.');

            $currency = (string)($data['currency'] ?? '');
            global $CURRENCIES;
            if (!in_array($currency, $CURRENCIES, true)) fail('Nieprawidłowy rodzaj Japuszki.');

            $amount = (int)($data['amount'] ?? 0);
            if ($amount < 1) fail('Kwota musi być większa od zera.');

            if ($recipientIdx === $senderIdx) fail('Nie możesz zrobić przelewu do samego siebie.');
            if (($db['users'][$recipientIdx]['status'] ?? '') !== 'approved') fail('Odbiorca nie ma aktywnego konta.');

            if ((int)$db['users'][$senderIdx]['balances'][$currency] < $amount) {
                fail('Brak wystarczającej liczby Japuszek.');
            }

            $title = trim((string)($data['title'] ?? ''));
            if ($title === '') $title = 'Przelew Japuszek';
            $title = mb_substr($title, 0, 100, 'UTF-8');

            $db['users'][$senderIdx]['balances'][$currency] -= $amount;
            $db['users'][$recipientIdx]['balances'][$currency] += $amount;

            array_unshift($db['transactions'], [
                'id' => random_id('tx'),
                'type' => 'transfer',
                'from' => $db['users'][$senderIdx]['id'],
                'to' => $db['users'][$recipientIdx]['id'],
                'currency' => $currency,
                'amount' => $amount,
                'title' => $title,
                'createdAt' => now_iso()
            ]);

            save_db($db);
            send_json(['ok' => true]);
        }

        case 'my_history': {
            $u = require_user($db);
            send_json([
                'ok' => true,
                'user' => clean_user($u),
                'transactions' => tx_for_user($db, (string)$u['id'])
            ]);
        }

        case 'admin_login': {
            $password = (string)($data['password'] ?? '');
            global $ADMIN_PASSWORD;
            if (!hash_equals($ADMIN_PASSWORD, $password)) {
                fail('Złe hasło admina.', 401);
            }

            $_SESSION['nbj_admin'] = true;
            send_json(['ok' => true]);
        }

        case 'admin_logout': {
            unset($_SESSION['nbj_admin']);
            send_json(['ok' => true]);
        }

        case 'admin_data': {
            require_admin();

            $safeUsers = array_map(fn($u) => clean_user($u), $db['users']);
            send_json([
                'ok' => true,
                'data' => [
                    'users' => $safeUsers,
                    'transactions' => $db['transactions'],
                    'createdAt' => $db['createdAt']
                ]
            ]);
        }

        case 'admin_create_user': {
            require_admin();

            $name = trim((string)($data['name'] ?? ''));
            $login = normalize_login((string)($data['login'] ?? ''));
            $password = (string)($data['password'] ?? '');
            $approved = !empty($data['approved']);

            if (mb_strlen($name, 'UTF-8') < 2) fail('Podaj poprawne imię.');
            if (mb_strlen($login, 'UTF-8') < 3) fail('Login musi mieć minimum 3 znaki.');
            if (mb_strlen($password, 'UTF-8') < 4) fail('Hasło musi mieć minimum 4 znaki.');
            if (user_exists_login($db, $login)) fail('Taki login już istnieje.');

            $user = [
                'id' => random_id('user'),
                'name' => $name,
                'login' => $login,
                'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
                'status' => $approved ? 'approved' : 'pending',
                'accountNo' => account_no(),
                'balances' => empty_balances(),
                'createdAt' => now_iso(),
                'approvedAt' => $approved ? now_iso() : null,
                'blockedAt' => null,
                'note' => 'Utworzone przez admina'
            ];

            $db['users'][] = $user;
            array_unshift($db['transactions'], [
                'id' => random_id('tx'),
                'type' => $approved ? 'status' : 'register',
                'from' => null,
                'to' => $user['id'],
                'currency' => null,
                'amount' => 0,
                'title' => $approved ? 'Konto utworzone i zatwierdzone przez admina' : 'Konto utworzone przez admina — oczekuje',
                'createdAt' => now_iso()
            ]);

            save_db($db);
            send_json(['ok' => true]);
        }

        case 'admin_set_status': {
            require_admin();

            $id = (string)($data['id'] ?? '');
            $status = (string)($data['status'] ?? '');
            if (!in_array($status, ['approved', 'blocked', 'pending'], true)) fail('Nieprawidłowy status.');

            $idx = user_index_by_id($db, $id);
            if ($idx < 0) fail('Nie znaleziono gracza.');

            $db['users'][$idx]['status'] = $status;
            if ($status === 'approved') {
                $db['users'][$idx]['approvedAt'] = now_iso();
                $db['users'][$idx]['blockedAt'] = null;
            }
            if ($status === 'blocked') {
                $db['users'][$idx]['blockedAt'] = now_iso();
            }

            array_unshift($db['transactions'], [
                'id' => random_id('tx'),
                'type' => 'status',
                'from' => null,
                'to' => $id,
                'currency' => null,
                'amount' => 0,
                'title' => 'Zmiana statusu konta: ' . $status,
                'createdAt' => now_iso()
            ]);

            save_db($db);
            send_json(['ok' => true]);
        }

        case 'admin_delete_user': {
            require_admin();

            $id = (string)($data['id'] ?? '');
            $idx = user_index_by_id($db, $id);
            if ($idx < 0) fail('Nie znaleziono gracza.');

            array_splice($db['users'], $idx, 1);

            if (($_SESSION['nbj_user_id'] ?? '') === $id) {
                unset($_SESSION['nbj_user_id']);
            }

            array_unshift($db['transactions'], [
                'id' => random_id('tx'),
                'type' => 'status',
                'from' => null,
                'to' => $id,
                'currency' => null,
                'amount' => 0,
                'title' => 'Konto usunięte przez admina',
                'createdAt' => now_iso()
            ]);

            save_db($db);
            send_json(['ok' => true]);
        }

        case 'admin_balance_operation': {
            require_admin();

            $userId = (string)($data['userId'] ?? '');
            $currency = (string)($data['currency'] ?? '');
            $type = (string)($data['type'] ?? '');
            $amount = (int)($data['amount'] ?? 0);
            $title = trim((string)($data['title'] ?? ''));

            global $CURRENCIES;
            if (!in_array($currency, $CURRENCIES, true)) fail('Nieprawidłowy rodzaj Japuszki.');
            if (!in_array($type, ['add', 'subtract', 'set'], true)) fail('Nieprawidłowa operacja.');
            if ($amount < 0) fail('Kwota nie może być ujemna.');
            if ($title === '') $title = 'Operacja administratora';
            $title = mb_substr($title, 0, 100, 'UTF-8');

            $idx = user_index_by_id($db, $userId);
            if ($idx < 0) fail('Nie znaleziono gracza.');

            if ($type === 'add') {
                $db['users'][$idx]['balances'][$currency] += $amount;
                $txType = 'admin_add';
            } elseif ($type === 'subtract') {
                if ((int)$db['users'][$idx]['balances'][$currency] < $amount) fail('Nie można odjąć więcej niż gracz posiada.');
                $db['users'][$idx]['balances'][$currency] -= $amount;
                $txType = 'admin_subtract';
            } else {
                $db['users'][$idx]['balances'][$currency] = $amount;
                $txType = 'admin_set';
            }

            array_unshift($db['transactions'], [
                'id' => random_id('tx'),
                'type' => $txType,
                'from' => null,
                'to' => $userId,
                'currency' => $currency,
                'amount' => $amount,
                'title' => $title,
                'createdAt' => now_iso()
            ]);

            save_db($db);
            send_json(['ok' => true]);
        }

        case 'admin_import_data': {
            require_admin();

            $newData = $data['data'] ?? null;
            if (!is_array($newData)) fail('Nieprawidłowy plik danych.');
            if (!isset($newData['users']) || !is_array($newData['users'])) fail('Brak listy użytkowników w JSON.');
            if (!isset($newData['transactions']) || !is_array($newData['transactions'])) fail('Brak historii operacji w JSON.');

            // Jeżeli import pochodzi ze starej wersji z hasłami plain text, zamieniamy na hashe.
            foreach ($newData['users'] as &$u) {
                if (!isset($u['passwordHash']) && isset($u['password'])) {
                    $u['passwordHash'] = password_hash((string)$u['password'], PASSWORD_DEFAULT);
                    unset($u['password']);
                }
            }
            unset($u);

            save_db($newData);
            send_json(['ok' => true]);
        }

        case 'admin_reset': {
            require_admin();

            save_db([
                'users' => [],
                'transactions' => [],
                'createdAt' => now_iso()
            ]);
            unset($_SESSION['nbj_user_id']);
            send_json(['ok' => true]);
        }

        case 'admin_seed_demo': {
            require_admin();

            $samples = [
                ['Kuba', 'kuba', '1234', ['zwykle' => 150, 'zlote' => 20, 'diamentowe' => 3, 'sekretne' => 1, 'og' => 0]],
                ['Mati', 'mati', '1234', ['zwykle' => 80, 'zlote' => 8, 'diamentowe' => 1, 'sekretne' => 0, 'og' => 1]],
                ['Olek', 'olek', '1234', ['zwykle' => 220, 'zlote' => 31, 'diamentowe' => 5, 'sekretne' => 2, 'og' => 0]]
            ];

            $added = 0;
            foreach ($samples as $s) {
                if (user_exists_login($db, $s[1])) continue;

                $balances = array_merge(empty_balances(), $s[3]);
                $user = [
                    'id' => random_id('user'),
                    'name' => $s[0],
                    'login' => $s[1],
                    'passwordHash' => password_hash($s[2], PASSWORD_DEFAULT),
                    'status' => 'approved',
                    'accountNo' => account_no(),
                    'balances' => $balances,
                    'createdAt' => now_iso(),
                    'approvedAt' => now_iso(),
                    'blockedAt' => null,
                    'note' => 'Konto przykładowe'
                ];

                $db['users'][] = $user;

                foreach ($balances as $currency => $amount) {
                    if ($amount <= 0) continue;
                    array_unshift($db['transactions'], [
                        'id' => random_id('tx'),
                        'type' => 'admin_add',
                        'from' => null,
                        'to' => $user['id'],
                        'currency' => $currency,
                        'amount' => $amount,
                        'title' => 'Pakiet startowy demo',
                        'createdAt' => now_iso()
                    ]);
                }

                $added++;
            }

            save_db($db);
            send_json(['ok' => true, 'message' => $added ? "Dodano przykładowych graczy: $added. Hasło demo: 1234" : 'Przykładowi gracze już istnieją.']);
        }

        default:
            fail('Nieznana akcja API.', 404);
    }
} catch (Throwable $e) {
    fail('Błąd serwera: ' . $e->getMessage(), 500);
}
