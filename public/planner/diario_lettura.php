<?php
/* =====================================================================
   STANDOUT — LEGGERE IL RESOCONTO O IL LIBRO
   diario_lettura.php?resoconto=ID  oppure  ?libro=ID
   Pagina da leggere, non da usare: un solo carattere grande, una colonna
   stretta, e la stampa (o "salva come PDF") che esce pulita.
   ===================================================================== */
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/security_core.php';
require_once __DIR__ . '/../../lib/auth_check.php';
require_once __DIR__ . '/../../lib/stile.php';
require_once __DIR__ . '/../../lib/planner/diario_numeri.php';

$uid = (int)$utente_corrente['id'];
dn_tabelle_ai();
$idR = sec_int($_GET['resoconto'] ?? 0, 0, PHP_INT_MAX, 0);
$idL = sec_int($_GET['libro'] ?? 0, 0, PHP_INT_MAX, 0);
$rep = $idR ? sec_one("SELECT * FROM diary_ai_reports WHERE id = ? AND user_id = ?", [$idR, $uid], 'ii') : null;
$lib = $idL ? sec_one("SELECT * FROM diary_ai_books WHERE id = ? AND user_id = ?", [$idL, $uid], 'ii') : null;
if (!$rep && !$lib) { header('Location: diario_anno.php'); exit; }

$lingua = function_exists('standout_lingua') ? standout_lingua() : 'it';
$fmt = function (string $d, string $schema = 'd MMM yyyy') use ($lingua) {
    if (class_exists('IntlDateFormatter')) {
        $f = new IntlDateFormatter($lingua, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Rome', null, $schema);
        return $f->format(new DateTime($d));
    }
    return date('d/m/Y', strtotime($d));
};
/* un paragrafo di testo dell'IA: si scappa tutto, gli a capo diventano paragrafi */
$par = function (string $t): string {
    $out = '';
    foreach (preg_split('/\n\s*\n/u', trim($t)) as $p) {
        $p = trim($p);
        if ($p !== '') $out .= '<p>' . nl2br(sec_h($p)) . '</p>';
    }
    return $out;
};
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(standout_lingua(), ENT_QUOTES); ?>" <?php echo standout_stile_attr(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo sec_h($rep ? t('diario.dn_resoconto') : ($lib['titolo'] ?: t('diario.dn_libro_senza_titolo'))); ?></title>
<?php echo standout_i18n_json(['comune', 'diario']); ?>
<link rel="stylesheet" href="diario.css?v=<?php echo @filemtime(__DIR__ . '/diario.css') ?: 1; ?>">
<link rel="stylesheet" href="diario_anno.css?v=<?php echo @filemtime(__DIR__ . '/diario_anno.css') ?: 1; ?>">
<?php echo standout_stile_css(); ?>
</head>
<body class="dn-body dn-lettura">

<header class="dn-head dn-head-lettura">
    <a class="dn-back" href="diario_anno.php<?php echo $rep ? '?anno=' . (int)$rep['anno'] : ''; ?>" aria-label="<?php te('comune.indietro'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
    </a>
    <span class="dn-head-sp"></span>
    <button type="button" class="dn-btn" onclick="window.print()"><?php te('diario.dn_stampa'); ?></button>
</header>

<main class="dn-read">
<?php if ($rep):
    $c = json_decode($rep['contenuto'], true) ?: [];
    $n = json_decode($rep['numeri'], true) ?: [];
    $ora = $n['ora'] ?? []; $prima = $n['prima'] ?? [];
    $freccia = function ($a, $b) { if ($a === null || $b === null) return ''; return $a > $b ? ' ↑' : ($a < $b ? ' ↓' : ' ='); };
?>
    <p class="dn-read-kicker"><?php te('diario.dn_resoconto_di', ['q' => (int)$rep['trimestre'], 'anno' => (int)$rep['anno']]); ?> · <?php echo sec_h($fmt($rep['periodo_da'], 'd MMM') . ' – ' . $fmt($rep['periodo_a'])); ?></p>
    <h1 class="dn-read-h1"><?php echo sec_h($c['titolo'] ?? ''); ?></h1>
    <p class="dn-read-lead"><?php echo sec_h($c['in_breve'] ?? ''); ?></p>

    <div class="dn-read-nums">
        <div><b><?php echo (int)($ora['scritti'] ?? 0); ?></b><span><?php te('diario.dn_giorni_scritti'); ?></span></div>
        <div><b><?php echo $ora['umore'] !== null ? number_format((float)$ora['umore'], 1, $lingua === 'en' ? '.' : ',', '') : '–'; ?><?php echo $freccia($ora['umore'] ?? null, $prima['umore'] ?? null); ?></b><span><?php te('diario.dn_umore_medio'); ?></span></div>
        <div><b><?php echo $ora['energia'] !== null ? number_format((float)$ora['energia'], 1, $lingua === 'en' ? '.' : ',', '') : '–'; ?><?php echo $freccia($ora['energia'] ?? null, $prima['energia'] ?? null); ?></b><span><?php te('diario.dn_energia_media'); ?></span></div>
        <div><b><?php echo $ora['abitudini_pct'] !== null ? (int)$ora['abitudini_pct'] . '%' : '–'; ?><?php echo $freccia($ora['abitudini_pct'] ?? null, $prima['abitudini_pct'] ?? null); ?></b><span><?php te('diario.dn_abitudini_fatte'); ?></span></div>
        <div><b><?php echo (int)($ora['pomodori'] ?? 0); ?><?php echo $freccia($ora['pomodori'] ?? null, $prima['pomodori'] ?? null); ?></b><span><?php te('diario.dn_pomodori'); ?></span></div>
    </div>

    <?php if (!empty($c['pensieri'])): ?>
    <section class="dn-read-sec dn-read-quote">
        <h2><?php te('diario.dn_i_tuoi_pensieri'); ?></h2>
        <?php foreach ($c['pensieri'] as $p): ?><blockquote><?php echo sec_h($p); ?></blockquote><?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="dn-read-sec">
        <h2><?php te('diario.dn_come_ti_sei_sentito'); ?></h2>
        <?php foreach ((array)($c['sentimenti'] ?? []) as $p) echo $par((string)$p); ?>
    </section>

    <?php if (!empty($c['alti']) || !empty($c['bassi'])): ?>
    <section class="dn-read-sec dn-read-two">
        <div><h3><?php te('diario.dn_momenti_alti'); ?></h3><ul><?php foreach ((array)($c['alti'] ?? []) as $x) echo '<li>' . sec_h($x) . '</li>'; ?></ul></div>
        <div><h3><?php te('diario.dn_momenti_bassi'); ?></h3><ul><?php foreach ((array)($c['bassi'] ?? []) as $x) echo '<li>' . sec_h($x) . '</li>'; ?></ul></div>
    </section>
    <?php endif; ?>

    <?php if (!empty($c['temi'])): ?>
    <section class="dn-read-sec">
        <h2><?php te('diario.dn_temi'); ?></h2>
        <?php foreach ($c['temi'] as $tm): ?><h3><?php echo sec_h($tm['tema'] ?? ''); ?></h3><?php echo $par((string)($tm['testo'] ?? '')); ?><?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="dn-read-sec">
        <h2><?php te('diario.dn_abitudini'); ?></h2>
        <?php echo $par((string)($c['abitudini'] ?? '')); ?>
        <?php if (!empty($n['abitudini'])): ?>
        <table class="dn-read-table">
            <thead><tr><th></th><th><?php te('diario.dn_tab_fatte'); ?></th><th><?php te('diario.dn_tab_obiettivo'); ?></th><th><?php te('diario.dn_tab_prima'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($n['abitudini'] as $h): ?>
                <tr><td><?php echo sec_h($h['nome']); ?></td><td><?php echo (int)$h['fatte']; ?></td><td><?php echo (int)$h['obiettivo']; ?></td><td><?php echo (int)$h['fatte_prima']; ?>/<?php echo (int)$h['obiettivo_prima']; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section class="dn-read-sec">
        <h2><?php te('diario.dn_concentrazione_t'); ?></h2>
        <?php echo $par((string)($c['concentrazione'] ?? '')); ?>
    </section>

    <?php if (!empty($c['spunti'])): ?>
    <section class="dn-read-sec">
        <h2><?php te('diario.dn_per_il_prossimo'); ?></h2>
        <ul><?php foreach ($c['spunti'] as $x) echo '<li>' . sec_h($x) . '</li>'; ?></ul>
    </section>
    <?php endif; ?>
    <p class="dn-read-foot"><?php te('diario.dn_scritto_da_ia', ['data' => $fmt($rep['created_at'])]); ?></p>

<?php else:
    $caps = json_decode($lib['capitoli'], true) ?: [];
?>
    <div class="dn-cover">
        <p class="dn-read-kicker"><?php echo sec_h($fmt($lib['periodo_da'], 'MMMM yyyy') . ' – ' . $fmt($lib['periodo_a'], 'MMMM yyyy')); ?></p>
        <h1 class="dn-read-h1 dn-book-h1"><?php echo sec_h($lib['titolo'] ?: t('diario.dn_libro_senza_titolo')); ?></h1>
        <p class="dn-read-by"><?php echo sec_h($utente_corrente['username'] ?? ''); ?></p>
    </div>
    <?php if ($lib['stato'] !== 'fatto'): ?><p class="dn-read-nota"><?php te('diario.dn_libro_incompleto'); ?></p><?php endif; ?>
    <nav class="dn-toc">
        <h2><?php te('diario.dn_indice'); ?></h2>
        <ol><?php foreach ($caps as $i => $cp): if (trim((string)($cp['testo'] ?? '')) === '') continue; ?>
            <li><a href="#cap<?php echo $i + 1; ?>"><?php echo sec_h($cp['titolo'] ?? ''); ?></a></li>
        <?php endforeach; ?></ol>
    </nav>
    <?php $k = 0; foreach ($caps as $i => $cp): if (trim((string)($cp['testo'] ?? '')) === '') continue; $k++; ?>
    <section class="dn-chapter" id="cap<?php echo $i + 1; ?>">
        <p class="dn-chapter-n"><?php te('diario.dn_capitolo_n', ['n' => $k]); ?></p>
        <h2><?php echo sec_h($cp['titolo'] ?? ''); ?></h2>
        <?php echo $par((string)$cp['testo']); ?>
    </section>
    <?php endforeach; ?>
    <p class="dn-read-foot"><?php te('diario.dn_libro_nota'); ?></p>
<?php endif; ?>
</main>
</body>
</html>
