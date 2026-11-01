<?php
/* fc_game_nuove.php — «Hai aggiunto carte: giocare anche quelle?»

   Compare quando si riprende una partita a meta' (o si torna da una modifica)
   e nel mazzo ci sono carte nate dopo l'avvio (lib/fc/fc_nuove_carte.php).
   Il «si» le mette in coda alla sequenza, il «no» sposta il riferimento cosi'
   la domanda non torna per le stesse carte. In entrambi i casi si rientra nella
   partita alla posizione in cui si era, con nc=1 per non richiedere subito. */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/fc/fc_type.php';
require_once __DIR__ . '/../../lib/fc/fc_nuove_carte.php';

$current_user_id = (int)$utente_corrente['id'];
$game_id = sec_int($_REQUEST['game_id'] ?? 0, 1);
$game = sec_one("SELECT * FROM fc_games WHERE id = ? AND user_id = ?", [$game_id, $current_user_id]);
if (!$game) { header('Location: ' . fc_url('fc_history.php')); exit; }
fc_avvia_da_gioco($game);

$position = sec_int($_REQUEST['position'] ?? 1, 1);
$status = sec_enum($_REQUEST['status'] ?? null, ['all', '1', '0'], '0');
$order = sec_enum($_REQUEST['order'] ?? null, ['in_order', 'random'], 'random');
$torna = 'fc_game.php?game_id=' . $game_id . '&position=' . $position . '&status=' . urlencode($status)
       . '&order=' . urlencode($order) . '&nc=1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sec_guard_write($current_user_id, 'fc_nuove_carte', 0, 60, 60);
    if (sec_enum($_POST['scelta'] ?? null, ['si', 'no'], 'no') === 'si') fc_nuove_carte_accetta($game, $current_user_id);
    else fc_nuove_carte_rifiuta($game_id, $current_user_id);
    header('Location: ' . $torna);
    exit;
}

$ids = fc_nuove_carte_trova($game, $current_user_id);
if (!$ids) { header('Location: ' . $torna); exit; }

/* Un'anteprima: le prime carte, in testo semplice (le carte di studio hanno un
   documento JSON, qui basta un estratto leggibile). */
$col = fc_is_vocab() ? 'word' : 'question_desc';
$ph = implode(',', array_fill(0, min(5, count($ids)), '?'));
$anteprima = [];
foreach (sec_all("SELECT $col AS q FROM " . fc_t('cards') . " WHERE id IN ($ph) ORDER BY id", array_slice($ids, 0, 5)) as $r) {
    $testo = (string)$r['q'];
    if (!fc_is_vocab() && $testo !== '' && $testo[0] === '{') {
        $doc = json_decode($testo, true);
        $testo = '';
        foreach (($doc['blocks'] ?? []) as $b) {
            foreach (($b['runs'] ?? []) as $run) $testo .= (string)($run['s'] ?? '');
            $testo .= ' ';
        }
    }
    $testo = trim(preg_replace('/\s+/', ' ', $testo));
    $anteprima[] = mb_strlen($testo) > 70 ? mb_substr($testo, 0, 70) . '…' : ($testo !== '' ? $testo : '—');
}
$n = count($ids);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php te('fc.nc_titolo'); ?></title>
<?php echo fc_boot_json(); ?>
<link rel="stylesheet" href="<?php echo PWA_BASE; ?>_assets/ui/base.css?v=<?php echo @filemtime(__DIR__ . '/../_assets/ui/base.css') ?: 1; ?>">
<style nonce="<?php echo sec_nonce(); ?>">
.nc-box{ max-width:420px; margin:18vh auto 0; padding:26px 22px; text-align:center; }
.nc-box h1{ margin:0 0 8px; font-size:22px; letter-spacing:-.02em; }
.nc-box p{ margin:0 0 14px; color:var(--text-2); font-size:15px; line-height:1.45; }
.nc-lista{ list-style:none; margin:0 0 20px; padding:0; text-align:left; }
.nc-lista li{ padding:9px 12px; margin-bottom:6px; border-radius:11px; background:var(--surface); font-size:14px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.nc-box form{ display:flex; flex-direction:column; gap:9px; }
.nc-box button{ height:46px; border:none; border-radius:13px; font:inherit; font-size:15px; font-weight:750; cursor:pointer; }
.nc-si{ background:var(--blue, #007AFF); color:#fff; }
.nc-no{ background:var(--surface); color:var(--text); }
</style>
</head>
<body>
<main class="nc-box">
    <h1><?php te($n === 1 ? 'fc.nc_h_1' : 'fc.nc_h_n', ['n' => $n]); ?></h1>
    <p><?php te('fc.nc_sub'); ?></p>
    <ul class="nc-lista">
        <?php foreach ($anteprima as $a): ?><li><?php echo sec_h($a); ?></li><?php endforeach; ?>
        <?php if ($n > count($anteprima)): ?><li>+<?php echo (int)($n - count($anteprima)); ?></li><?php endif; ?>
    </ul>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo sec_h(sec_csrf_token()); ?>">
        <input type="hidden" name="game_id" value="<?php echo (int)$game_id; ?>">
        <input type="hidden" name="position" value="<?php echo (int)$position; ?>">
        <input type="hidden" name="status" value="<?php echo sec_h($status); ?>">
        <input type="hidden" name="order" value="<?php echo sec_h($order); ?>">
        <button type="submit" name="scelta" value="si" class="nc-si"><?php te('fc.nc_si'); ?></button>
        <button type="submit" name="scelta" value="no" class="nc-no"><?php te('fc.nc_no'); ?></button>
    </form>
</main>
</body>
</html>
