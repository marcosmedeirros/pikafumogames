<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../core/conexao.php';

const BJ_TABLE_ID = 1;
const BJ_SEATS = 6;
const BJ_BET_MAX = 15;
const BJ_BET_SECONDS = 15;
const BJ_RESULT_SECONDS = 5;

// --- Helpers de estado -----------------------------------------------------

function requireUser(PDO $pdo): array {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /auth/login.php');
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, nome, pontos FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        session_destroy();
        header('Location: /auth/login.php');
        exit;
    }
    return $user;
}

function ensureTable(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS blackjack_tables (
        id INT PRIMARY KEY,
        state LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM blackjack_tables WHERE id = :id');
    $stmt->execute([':id' => BJ_TABLE_ID]);
    $exists = (int) $stmt->fetchColumn();
    if ($exists === 0) {
        $default = defaultState();
        $pdo->prepare('INSERT INTO blackjack_tables (id, state) VALUES (:id, :st)')
            ->execute([':id' => BJ_TABLE_ID, ':st' => json_encode($default)]);
    }
}

function currentSaldo(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT pontos FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    return (int) $stmt->fetchColumn();
}

function defaultState(): array {
    $seats = [];
    for ($i = 1; $i <= BJ_SEATS; $i++) {
        $seats[] = [
            'seat' => $i,
            'user_id' => null,
            'name' => null,
            'bet' => 0,
            'status' => 'empty', // empty | seated | betting | playing | stood | busted
            'hand' => [],
            'total' => 0,
            'outcome' => null
        ];
    }

    return [
        'phase' => 'betting',
        'round' => 1,
        'bet_deadline' => time() + BJ_BET_SECONDS,
        'results_until' => null,
        'seats' => $seats,
        'dealer' => [
            'hand' => [],
            'hide_hole' => true
        ],
        'deck' => []
    ];
}

function loadState(PDO $pdo, bool $forUpdate = false): array {
    $sql = 'SELECT state FROM blackjack_tables WHERE id = :id' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => BJ_TABLE_ID]);
    $data = $stmt->fetchColumn();
    return $data ? json_decode($data, true) : defaultState();
}

function saveState(PDO $pdo, array $state): void {
    $pdo->prepare('UPDATE blackjack_tables SET state = :st WHERE id = :id')
        ->execute([':st' => json_encode($state), ':id' => BJ_TABLE_ID]);
}

function buildDeck(): array {
    $suits = ['♠', '♥', '♦', '♣'];
    $ranks = ['2','3','4','5','6','7','8','9','10','J','Q','K','A'];
    $deck = [];
    foreach ($suits as $s) {
        foreach ($ranks as $r) {
            $deck[] = ['r' => $r, 's' => $s];
        }
    }
    shuffle($deck);
    return $deck;
}

function cardValue(string $rank): int {
    if ($rank === 'A') return 11;
    if (in_array($rank, ['J', 'Q', 'K'], true)) return 10;
    return (int) $rank;
}

function handTotal(array $hand): int {
    $total = 0;
    $aces = 0;
    foreach ($hand as $card) {
        $total += cardValue($card['r']);
        if ($card['r'] === 'A') $aces++;
    }
    while ($total > 21 && $aces > 0) {
        $total -= 10;
        $aces--;
    }
    return $total;
}

function findSeatIndex(array $state, int $userId): ?int {
    foreach ($state['seats'] as $idx => $seat) {
        if ($seat['user_id'] === $userId) return $idx;
    }
    return null;
}

function allPlayersDone(array $state): bool {
    foreach ($state['seats'] as $seat) {
        if ($seat['bet'] > 0 && in_array($seat['status'], ['playing', 'betting'], true)) return false;
    }
    return true;
}

function hasAnyBet(array $state): bool {
    foreach ($state['seats'] as $seat) {
        if ($seat['bet'] > 0) return true;
    }
    return false;
}

// --- Avanço de fases ------------------------------------------------------

function startBettingPhase(array &$state): void {
    $state['phase'] = 'betting';
    $state['bet_deadline'] = time() + BJ_BET_SECONDS;
    $state['results_until'] = null;
    $state['dealer'] = ['hand' => [], 'hide_hole' => true];
    $state['deck'] = [];
    foreach ($state['seats'] as &$seat) {
        $seat['hand'] = [];
        $seat['total'] = 0;
        $seat['outcome'] = null;
        if ($seat['user_id']) {
            $seat['status'] = 'seated';
            $seat['bet'] = 0;
        } else {
            $seat['status'] = 'empty';
            $seat['bet'] = 0;
        }
    }
    unset($seat);
}

function startDeal(array &$state): void {
    if (!hasAnyBet($state)) {
        $state['bet_deadline'] = time() + BJ_BET_SECONDS;
        return;
    }

    $state['phase'] = 'dealing';
    $state['deck'] = buildDeck();
    $state['dealer'] = [
        'hand' => [array_shift($state['deck']), array_shift($state['deck'])],
        'hide_hole' => true
    ];

    foreach ($state['seats'] as &$seat) {
        if ($seat['bet'] > 0) {
            $seat['hand'] = [array_shift($state['deck']), array_shift($state['deck'])];
            $seat['total'] = handTotal($seat['hand']);
            $seat['status'] = 'playing';
        }
    }
    unset($seat);
}

function finishRound(PDO $pdo, array &$state): void {
    // Dealer compra até 17
    $dealerHand = $state['dealer']['hand'];
    while (handTotal($dealerHand) < 17) {
        $dealerHand[] = array_shift($state['deck']);
    }
    $state['dealer']['hand'] = $dealerHand;
    $state['dealer']['hide_hole'] = false;
    $dealerTotal = handTotal($dealerHand);
    $dealerBJ = count($dealerHand) === 2 && $dealerTotal === 21;

    foreach ($state['seats'] as &$seat) {
        if ($seat['bet'] <= 0) continue;
        $bet = $seat['bet'];
        $playerTotal = $seat['total'];
        $playerBJ = count($seat['hand']) === 2 && $playerTotal === 21;
        $payout = 0;
        $outcome = 'push';

        if ($seat['status'] === 'busted') {
            $outcome = 'bust';
        } elseif ($dealerBJ && !$playerBJ) {
            $outcome = 'lose';
        } elseif ($playerBJ && !$dealerBJ) {
            $outcome = 'blackjack';
            $payout = $bet * 2.5;
        } elseif ($dealerTotal > 21 || $playerTotal > $dealerTotal) {
            $outcome = 'win';
            $payout = $bet * 2;
        } elseif ($playerTotal < $dealerTotal) {
            $outcome = 'lose';
        } else {
            $outcome = 'push';
            $payout = $bet;
        }

        if ($payout > 0) {
            $pdo->prepare('UPDATE usuarios SET pontos = pontos + :v WHERE id = :id')
                ->execute([':v' => $payout, ':id' => $seat['user_id']]);
        }

        $seat['outcome'] = $outcome;
        $seat['bet'] = 0;
        $seat['status'] = 'seated';
    }
    unset($seat);

    $state['phase'] = 'results';
    $state['results_until'] = time() + BJ_RESULT_SECONDS;
    $state['round']++;
}

function advanceState(PDO $pdo, array &$state): void {
    $now = time();
    if ($state['phase'] === 'betting' && $now >= $state['bet_deadline']) {
        startDeal($state);
    }

    if ($state['phase'] === 'dealing' && allPlayersDone($state)) {
        finishRound($pdo, $state);
    }

    if ($state['phase'] === 'results' && $state['results_until'] !== null && $now >= $state['results_until']) {
        startBettingPhase($state);
    }
}

function publicState(array $state): array {
    $now = time();
    $countdown = 0;
    if ($state['phase'] === 'betting') {
        $countdown = max(0, $state['bet_deadline'] - $now);
    } elseif ($state['phase'] === 'results' && $state['results_until']) {
        $countdown = max(0, $state['results_until'] - $now);
    }

    $dealerHand = $state['dealer']['hand'] ?? [];
    $dealerPublic = $dealerHand;
    if ($state['phase'] === 'dealing' && ($state['dealer']['hide_hole'] ?? false) && count($dealerPublic) === 2) {
        $dealerPublic = [$dealerPublic[0], ['r' => '?', 's' => '?']];
    }
    $dealerTotal = 0;
    if (count($dealerHand) > 0) {
        $dealerTotal = ($state['dealer']['hide_hole'] ?? false) && $state['phase'] === 'dealing'
            ? cardValue($dealerHand[0]['r'])
            : handTotal($dealerHand);
    }

    return [
        'phase' => $state['phase'],
        'round' => $state['round'],
        'countdown' => $countdown,
        'seats' => $state['seats'],
        'dealer' => $dealerPublic,
        'dealer_total' => $dealerTotal
    ];
}

// --- Ações ---------------------------------------------------------------

function jsonError(string $msg): void {
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

ensureTable($pdo);
$me = requireUser($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        $pdo->beginTransaction();
        $state = loadState($pdo, true);
        advanceState($pdo, $state);

        if ($action === 'poll') {
            saveState($pdo, $state);
            $meSaldo = currentSaldo($pdo, $me['id']);
            $pdo->commit();
            echo json_encode([
                'ok' => true,
                'state' => publicState($state),
                'me' => ['saldo' => $meSaldo, 'seat' => findSeatIndex($state, $me['id'])]
            ]);
            exit;
        }

        // Senta no primeiro assento disponível
        if ($action === 'sit') {
            $already = findSeatIndex($state, $me['id']);
            if ($already !== null) jsonError('Você já está na mesa.');
            $spot = null;
            foreach ($state['seats'] as $idx => $seat) {
                if ($seat['user_id'] === null) { $spot = $idx; break; }
            }
            if ($spot === null) jsonError('Mesa cheia.');
            $state['seats'][$spot]['user_id'] = $me['id'];
            $state['seats'][$spot]['name'] = $me['nome'];
            $state['seats'][$spot]['status'] = 'seated';
        }

        if ($action === 'leave') {
            $idx = findSeatIndex($state, $me['id']);
            if ($idx === null) jsonError('Você não está sentado.');
            if ($state['phase'] === 'dealing') jsonError('Espere a rodada terminar.');
            $seat = $state['seats'][$idx];
            if ($state['phase'] === 'betting' && $seat['bet'] > 0) {
                $pdo->prepare('UPDATE usuarios SET pontos = pontos + :v WHERE id = :id')
                    ->execute([':v' => $seat['bet'], ':id' => $me['id']]);
            }
            $state['seats'][$idx] = [
                'seat' => $seat['seat'],
                'user_id' => null,
                'name' => null,
                'bet' => 0,
                'status' => 'empty',
                'hand' => [],
                'total' => 0,
                'outcome' => null
            ];
        }

        if ($action === 'bet') {
            if ($state['phase'] !== 'betting') jsonError('Apostas fechadas.');
            $amount = max(0, (int) ($_POST['amount'] ?? 0));
            if ($amount <= 0) jsonError('Valor inválido.');
            $idx = findSeatIndex($state, $me['id']);
            if ($idx === null) jsonError('Sente-se primeiro.');
            if ($amount > BJ_BET_MAX) jsonError('Aposta máxima é 15.');

            $userStmt = $pdo->prepare('SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE');
            $userStmt->execute([':id' => $me['id']]);
            $saldo = (int) $userStmt->fetchColumn();

            if ($saldo < $amount) jsonError('Saldo insuficiente.');
            if ($state['seats'][$idx]['bet'] + $amount > BJ_BET_MAX) jsonError('Limite por mão é 15.');

            $pdo->prepare('UPDATE usuarios SET pontos = pontos - :v WHERE id = :id')
                ->execute([':v' => $amount, ':id' => $me['id']]);

            $state['seats'][$idx]['bet'] += $amount;
            $state['seats'][$idx]['status'] = 'betting';
        }

        if ($action === 'clear_bet') {
            if ($state['phase'] !== 'betting') jsonError('Apostas fechadas.');
            $idx = findSeatIndex($state, $me['id']);
            if ($idx === null) jsonError('Sente-se primeiro.');
            $bet = $state['seats'][$idx]['bet'];
            if ($bet > 0) {
                $pdo->prepare('UPDATE usuarios SET pontos = pontos + :v WHERE id = :id')
                    ->execute([':v' => $bet, ':id' => $me['id']]);
            }
            $state['seats'][$idx]['bet'] = 0;
            $state['seats'][$idx]['status'] = 'seated';
        }

        if ($action === 'hit') {
            if ($state['phase'] !== 'dealing') jsonError('A rodada ainda não começou.');
            $idx = findSeatIndex($state, $me['id']);
            if ($idx === null) jsonError('Sente-se primeiro.');
            if ($state['seats'][$idx]['status'] !== 'playing') jsonError('Sua mão já finalizou.');
            $card = array_shift($state['deck']);
            $state['seats'][$idx]['hand'][] = $card;
            $state['seats'][$idx]['total'] = handTotal($state['seats'][$idx]['hand']);
            if ($state['seats'][$idx]['total'] > 21) {
                $state['seats'][$idx]['status'] = 'busted';
            }
        }

        if ($action === 'stand') {
            if ($state['phase'] !== 'dealing') jsonError('A rodada ainda não começou.');
            $idx = findSeatIndex($state, $me['id']);
            if ($idx === null) jsonError('Sente-se primeiro.');
            if ($state['seats'][$idx]['status'] === 'playing') {
                $state['seats'][$idx]['status'] = 'stood';
            }
        }

        // Após qualquer ação, reavalia fases
        advanceState($pdo, $state);
        saveState($pdo, $state);
        $meSaldo = currentSaldo($pdo, $me['id']);
        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'state' => publicState($state),
            'me' => ['saldo' => $meSaldo, 'seat' => findSeatIndex($state, $me['id'])]
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError('Erro: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mesa Blackjack (Multiplayer)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: radial-gradient(circle at 20% 20%, #0f172a, #020617 60%); color: #e2e8f0; font-family: 'Inter', sans-serif; }
        .card { background: #0f172a; border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 6px 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.35); }
        .card-face { width: 52px; height: 72px; border-radius: 10px; background: #fff; color: #0f172a; display: grid; place-items: center; font-weight: 900; }
        .pill { border-radius: 999px; padding: 4px 12px; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.05); font-size: 12px; }
        .seat-dim { opacity: 0.5; }
        .table-shell { position: absolute; inset: 0; pointer-events: none; }
        .table-rim { position: absolute; inset: 0; border-radius: 999px; background: linear-gradient(145deg, #6b3d20 0%, #4b260f 40%, #2f170a 100%); box-shadow: inset 0 0 40px rgba(0,0,0,0.85), 0 20px 45px rgba(0,0,0,0.65); transform: scale(1.03); }
        .table-felt { position: absolute; inset: 16px; border-radius: 900px; background: radial-gradient(circle at 50% 30%, rgba(255,255,255,0.04), transparent 42%), radial-gradient(circle at 35% 70%, rgba(255,255,255,0.05), transparent 48%), #0a5e3c; box-shadow: inset 0 0 70px rgba(0,0,0,0.65), inset 0 0 20px rgba(0,0,0,0.35); filter: drop-shadow(0 14px 36px rgba(0,0,0,0.5)); }
        .seat-markers { position: absolute; inset: 0; }
        .seat-dot { position: absolute; width: 22px; height: 22px; border-radius: 50%; background: radial-gradient(circle, #f8fafc 0%, #e2e8f0 55%, #94a3b8 100%); box-shadow: 0 0 14px rgba(255,255,255,0.75); opacity: 0.85; }
        .seat-dot::after { content: ''; position: absolute; inset: -12px; border-radius: 50%; border: 1px dashed rgba(255,255,255,0.22); }
        .glow-ring { position: absolute; inset: 36px; border-radius: 999px; border: 1px dashed rgba(16,185,129,0.25); }
        .seat-layer { min-height: 430px; }
        .seat-spot { position: absolute; width: 170px; height: 160px; transform: translate(-50%, -50%); display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .bet-circle { width: 94px; height: 94px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.14); box-shadow: inset 0 0 14px rgba(0,0,0,0.5); background: radial-gradient(circle, rgba(255,255,255,0.08), rgba(0,0,0,0.22)); display: grid; place-items: center; color: #e2e8f0; font-weight: 800; letter-spacing: 0.04em; }
        .seat-cards { display: flex; gap: 6px; }
        .seat-tag { font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: #9ae6b4; }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-6xl mx-auto">
        <header class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 mb-8">
            <div>
                <p class="text-xs uppercase tracking-[0.4em] text-slate-400">Ao vivo</p>
                <h1 class="text-3xl font-black text-white tracking-tight">Mesa Blackjack Multiplayer</h1>
                <p class="text-sm text-slate-400">6 lugares, apostas até 15 pontos, rodada a cada <?= BJ_BET_SECONDS ?>s.</p>
            </div>
            <div class="flex gap-2 items-center">
                <span class="pill" id="status-pill">Preparando</span>
                <span class="pill bg-emerald-500/20 border-emerald-400/40 text-emerald-200">Próxima etapa em <span id="countdown">--</span>s</span>
            </div>
        </header>

        <div class="bg-emerald-900/40 border border-emerald-700/50 rounded-3xl p-6 shadow-2xl">
            <div class="flex flex-col md:flex-row gap-6">
                <div class="md:w-2/3">
                    <div class="relative bg-emerald-950/80 border border-emerald-700/60 rounded-[32px] p-6 overflow-hidden shadow-[0_20px_60px_rgba(0,0,0,0.4)]">
                        <div class="table-shell">
                            <div class="table-rim"></div>
                            <div class="table-felt"></div>
                            <div class="glow-ring"></div>
                            <div class="seat-markers">
                                <span class="seat-dot" style="top: 8%; left: 50%; transform: translate(-50%, -50%);"></span>
                                <span class="seat-dot" style="top: 28%; left: 16%; transform: translate(-50%, -50%);"></span>
                                <span class="seat-dot" style="top: 28%; right: 16%; transform: translate(50%, -50%);"></span>
                                <span class="seat-dot" style="bottom: 20%; left: 16%; transform: translate(-50%, 50%);"></span>
                                <span class="seat-dot" style="bottom: 20%; right: 16%; transform: translate(50%, 50%);"></span>
                                <span class="seat-dot" style="bottom: 4%; left: 50%; transform: translate(-50%, 50%);"></span>
                            </div>
                        </div>
                        <div class="flex justify-between items-center mb-4">
                            <div>
                                <p class="text-xs uppercase tracking-[0.25em] text-emerald-200">Dealer</p>
                                <div class="flex gap-2 items-center" id="dealer-cards"></div>
                                <p class="text-xs text-emerald-100 mt-1">Total: <span id="dealer-total">0</span></p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs uppercase tracking-[0.25em] text-slate-400">Rodada</p>
                                <p class="text-lg font-black" id="round-label">#1</p>
                            </div>
                        </div>

                        <div id="seats" class="seat-layer relative w-full">
                        </div>
                    </div>
                </div>

                <div class="md:w-1/3 flex flex-col gap-4">
                    <div class="bg-slate-900/70 border border-slate-700 rounded-2xl p-4 space-y-3">
                        <h3 class="text-sm font-black uppercase tracking-[0.2em] text-slate-200">Você</h3>
                        <p class="text-sm text-slate-300">Saldo: <span id="saldo">--</span> pts</p>
                        <div class="flex gap-2">
                            <button id="btn-sit" class="flex-1 bg-emerald-500 hover:bg-emerald-400 text-black font-black px-3 py-2 rounded-lg transition">Sentar</button>
                            <button id="btn-leave" class="flex-1 bg-slate-800 border border-slate-700 text-slate-200 font-bold px-3 py-2 rounded-lg transition">Sair</button>
                        </div>
                        <div class="border-t border-slate-800 pt-3">
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-400 mb-2">Fichas</p>
                            <div class="flex flex-wrap gap-2">
                                <button data-chip="1" class="pill bg-slate-800 border border-slate-700 text-white">1</button>
                                <button data-chip="5" class="pill bg-slate-800 border border-slate-700 text-white">5</button>
                                <button data-chip="10" class="pill bg-slate-800 border border-slate-700 text-white">10</button>
                                <button data-chip="15" class="pill bg-slate-800 border border-slate-700 text-white">15</button>
                                <button id="btn-clear-bet" class="pill bg-amber-500/20 border border-amber-500/40 text-amber-200">Limpar</button>
                            </div>
                            <p class="text-xs text-slate-400 mt-2">Aposte somente na fase de apostas.</p>
                        </div>
                        <div class="flex gap-2">
                            <button id="btn-hit" class="flex-1 bg-emerald-500/20 border border-emerald-400/40 text-emerald-100 px-3 py-2 rounded-lg">Pedir</button>
                            <button id="btn-stand" class="flex-1 bg-amber-500/20 border border-amber-500/40 text-amber-100 px-3 py-2 rounded-lg">Parar</button>
                        </div>
                        <div class="text-xs text-slate-400 space-y-1">
                            <p class="uppercase tracking-[0.15em] text-slate-300">Comandos</p>
                            <p>- Sentar / Sair: ocupa ou libera um lugar</p>
                            <p>- Fichas: somam até 15 por rodada</p>
                            <p>- Limpar: devolve fichas apostadas enquanto apostas abertas</p>
                            <p>- Pedir / Parar: só na fase de jogo</p>
                            <p>- Entrando no meio? Você aguarda a próxima rodada.</p>
                        </div>
                    </div>

                    <div class="bg-slate-900/70 border border-slate-700 rounded-2xl p-4">
                        <h3 class="text-sm font-black uppercase tracking-[0.2em] text-slate-200 mb-2">Log</h3>
                        <div id="log" class="text-xs text-slate-300 space-y-1 max-h-64 overflow-y-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const api = (action, extra = {}) => {
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
        return fetch('blackjack.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .catch(() => ({ ok: false, error: 'Falha na conexão.' }));
    };

    const seatPositions = [
        { top: '78%', left: '18%' },
        { top: '86%', left: '42%' },
        { top: '86%', left: '58%' },
        { top: '78%', left: '82%' },
        { top: '32%', left: '30%' },
        { top: '32%', left: '70%' },
    ];

    const dealerCardsEl = document.getElementById('dealer-cards');
    const dealerTotalEl = document.getElementById('dealer-total');
    const roundLabel = document.getElementById('round-label');
    const countdownEl = document.getElementById('countdown');
    const statusPill = document.getElementById('status-pill');
    const seatsEl = document.getElementById('seats');
    const saldoEl = document.getElementById('saldo');
    const logEl = document.getElementById('log');

    function renderState(data) {
        if (!data.ok) {
            appendLog(data.error || 'Erro ao carregar estado.');
            return;
        }
        const state = data.state;
        saldoEl.textContent = data.me ? Number(data.me.saldo || 0).toLocaleString('pt-BR') : '--';
        roundLabel.textContent = '#' + state.round;
        countdownEl.textContent = state.countdown ?? '--';
        statusPill.textContent = state.phase === 'betting' ? 'Apostas' : state.phase === 'dealing' ? 'Em jogo' : 'Resultados';
        statusPill.className = 'pill ' + (state.phase === 'betting'
            ? 'bg-amber-500/20 border-amber-400/40 text-amber-200'
            : state.phase === 'dealing'
                ? 'bg-emerald-500/20 border-emerald-400/40 text-emerald-200'
                : 'bg-slate-600/30 border-slate-500/60 text-slate-100');

        renderDealer(state.dealer, state.dealer_total);
        renderSeats(state.seats, data.me ? data.me.seat : null);
    }

    function renderDealer(cards, total) {
        dealerCardsEl.innerHTML = cards.map(c => {
            if (c.r === '?') return '<div class="card-face">??</div>';
            return `<div class="card-face">${c.r}${c.s}</div>`;
        }).join('');
        dealerTotalEl.textContent = total ?? 0;
    }

    function renderSeats(seats, mySeat) {
        seatsEl.innerHTML = '';
        seats.forEach(seat => {
            const div = document.createElement('div');
            const pos = seatPositions[seat.seat - 1] || { top: '50%', left: '50%' };
            div.className = 'seat-spot ' + (seat.user_id ? '' : 'seat-dim');
            div.style.top = pos.top;
            div.style.left = pos.left;
            const statusLabel = seat.status === 'playing'
                ? 'Jogando'
                : seat.status === 'busted'
                    ? 'Estourou'
                    : seat.status === 'betting'
                        ? 'Apostando'
                        : seat.user_id ? 'Sentado' : 'Vago';
            const outcomeText = seat.outcome ? seat.outcome : '';
            const highlight = (mySeat !== null && seat.seat - 1 === mySeat) ? 'ring-2 ring-emerald-400/70' : '';
            div.innerHTML = `
                <div class="seat-tag">Lugar ${seat.seat} · ${statusLabel}</div>
                <div class="bet-circle ${highlight}">${seat.bet > 0 ? seat.bet : 'Aposta'}</div>
                <div class="seat-cards">${renderCards(seat.hand)}</div>
                <div class="text-xs text-emerald-100">Total: ${seat.total || 0} ${outcomeText ? '· ' + outcomeText : ''}</div>
                <div class="text-sm font-black text-emerald-50">${seat.name || '---'}</div>
            `;
            seatsEl.appendChild(div);
        });
    }

    function renderCards(hand) {
        return (hand || []).map(c => `<div class="card-face">${c.r}${c.s}</div>`).join('');
    }

    function appendLog(msg) {
        const row = document.createElement('div');
        row.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
        logEl.prepend(row);
    }

    // Controles
    document.querySelectorAll('[data-chip]').forEach(btn => {
        btn.onclick = async () => {
            const val = Number(btn.dataset.chip);
            const res = await api('bet', { amount: val });
            if (!res.ok) return appendLog(res.error || 'Erro ao apostar');
            renderState(res);
        };
    });

    document.getElementById('btn-clear-bet').onclick = async () => {
        const res = await api('clear_bet');
        if (!res.ok) return appendLog(res.error || 'Erro ao limpar');
        renderState(res);
    };

    document.getElementById('btn-sit').onclick = async () => {
        const res = await api('sit');
        if (!res.ok) return appendLog(res.error || 'Erro ao sentar');
        renderState(res);
        appendLog('Você sentou na mesa.');
    };

    document.getElementById('btn-leave').onclick = async () => {
        const res = await api('leave');
        if (!res.ok) return appendLog(res.error || 'Erro ao sair');
        renderState(res);
        appendLog('Você saiu da mesa.');
    };

    document.getElementById('btn-hit').onclick = async () => {
        const res = await api('hit');
        if (!res.ok) return appendLog(res.error || 'Erro ao pedir carta');
        renderState(res);
    };

    document.getElementById('btn-stand').onclick = async () => {
        const res = await api('stand');
        if (!res.ok) return appendLog(res.error || 'Erro ao parar');
        renderState(res);
    };

    async function poll() {
        const res = await api('poll');
        if (res.ok) renderState(res);
    }

    poll();
    setInterval(poll, 1000);
    </script>
</body>
</html>
