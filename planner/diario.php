<?php
/* =====================================================================
   NISAMA — DIARIO PERSONALE
   Un quaderno vero: una pagina per ogni giorno, si sfoglia con lo swipe,
   si salva da sé. Umore, energia, testo e i due elenchi sono gli stessi
   dati del popup "diario del giorno" (habits e planner): dove scrivi non
   importa. Tutto passa da diary_api.php.
   ===================================================================== */
require_once 'config.php';
require_once 'security_core.php';
require_once 'auth_check.php';

$current_user_id = (int)$utente_corrente['id'];
$conn = sec_db();
date_default_timezone_set('Europe/Rome');

$startDate = sec_date($_GET['data'] ?? '', date('Y-m-d'));

$boot = [
    'csrf'  => sec_csrf_token(),
    'today' => date('Y-m-d'),
    'date'  => $startDate,
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#FDFCF9">
<title>Diario</title>
<meta name="csrf-token" content="<?php echo sec_h(sec_csrf_token()); ?>">
<script src="nisama_fetch.js?v=<?php echo @filemtime(__DIR__ . '/nisama_fetch.js') ?: time(); ?>"></script>
<link rel="stylesheet" href="diario.css?v=<?php echo @filemtime(__DIR__ . '/diario.css') ?: time(); ?>">
</head>
<body class="dy-body">

<!-- ============ IL QUADERNO ============ -->
<div class="dy-stage" id="dyStage">

    <!-- pagina del giorno -->
    <article class="dy-page" id="dyPage">
        <header class="dy-head">
            <div class="dy-faces">
                <button type="button" class="dy-face-btn" id="dyMoodBtn" aria-label="Com'è andata"></button>
                <button type="button" class="dy-face-btn" id="dyEnergyBtn" aria-label="Energia"></button>
            </div>

            <div class="dy-title">
                <span class="dy-num" id="dyNum">1</span>
                <span class="dy-wd" id="dyWd">Lunedì</span>
                <button type="button" class="dy-month-btn" id="dyMonthBtn">
                    <span id="dyMonthLabel">Luglio 2026</span>
                </button>
            </div>

            <button type="button" class="dy-ring" id="dyRing" aria-label="Abitudini">
                <?php /* I cerchi li disegna diario.js: quanti sono dipende da
                         quali famiglie di frequenza esistono davvero. */ ?>
                <svg viewBox="0 0 54 54" aria-hidden="true"></svg>
                <span class="dy-ring-n" id="dyRingN">–</span>
            </button>
        </header>

        <div class="dy-lists">
            <section class="dy-list-col">
                <span class="dy-lab">Cose belle di oggi</span>
                <div class="dy-list" id="dyGood"></div>
            </section>
            <section class="dy-list-col">
                <span class="dy-lab">Cosa ho imparato oggi</span>
                <div class="dy-list" id="dyLearned"></div>
            </section>
        </div>

        <div class="dy-write">
            <textarea id="dyText" spellcheck="true" maxlength="20000"
                      placeholder="Com'è andata oggi…"></textarea>
        </div>
        <button type="button" class="dy-mic" id="dyMic" hidden aria-label="Detta invece di scrivere">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0013 0"/><path d="M12 18v3"/></svg>
        </button>

        <div class="dy-page-foot">
            <span class="dy-saved" id="dySaved">salvato</span>
        </div>
    </article>

    <!-- pagina del mese -->
    <article class="dy-page dy-page-month" id="dyMonthPage" hidden>
        <header class="dy-head dy-head-month">
            <button type="button" class="dy-back" id="dyMonthBack" aria-label="Torna al giorno">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
            </button>
            <div class="dy-title dy-title-month">
                <span class="dy-num dy-num-month" id="dyMTitle">Luglio</span>
                <span class="dy-wd" id="dyMYear">2026</span>
            </div>
            <span class="dy-back dy-back-ghost" aria-hidden="true"></span>
        </header>

        <div class="dy-cal" id="dyCal"></div>

        <div class="dy-stats" id="dyStats"></div>

        <div class="dy-taped" id="dyTaped">
            <span class="dy-tape" aria-hidden="true"></span>
            <button type="button" class="dy-taped-head" id="dyTapedHead">
                <span class="dy-lab">Cose imparate questo mese</span>
                <span class="dy-taped-n" id="dyTapedN">0</span>
            </button>
            <div class="dy-taped-body" id="dyTapedBody"></div>
        </div>
    </article>
</div>

<!-- ============ STRUMENTI ============ -->
<div class="dy-tools">
    <button type="button" class="dy-tool" id="dyUndo" aria-label="Annulla l'ultima cosa">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h9.5a4.5 4.5 0 010 9H8"/><path d="M7.5 5.5L4 9l3.5 3.5"/></svg>
    </button>
    <button type="button" class="dy-tool" id="dyTodayBtn" aria-label="Vai a oggi">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="3.2"/><path d="M12 3.2v2.4M12 18.4v2.4M3.2 12h2.4M18.4 12h2.4"/></svg>
    </button>
    <button type="button" class="dy-tool" id="dyFontBtn" aria-label="Scegli la scrittura">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M5 18L10.5 6l5.5 12"/><path d="M7.2 14h6.6"/><path d="M18.5 18V9"/></svg>
    </button>
</div>

<!-- scelta di com'è andata / energia -->
<div class="dy-pop" id="dyFacePop" hidden>
    <span class="dy-pop-lab" id="dyPopLab">Com'è andata</span>
    <div class="dy-pop-row" id="dyPopRow"></div>
</div>

<!-- scelta della scrittura -->
<div class="dy-pop dy-pop-font" id="dyFontPop" hidden>
    <span class="dy-pop-lab">La tua scrittura</span>
    <div class="dy-font-list" id="dyFontList"></div>
    <div class="dy-size-row">
        <button type="button" class="dy-size" data-step="-1" aria-label="Più piccolo">A<span>−</span></button>
        <span class="dy-size-v" id="dySizeV">18</span>
        <button type="button" class="dy-size" data-step="1" aria-label="Più grande">A<span>+</span></button>
    </div>
</div>

<!-- da computer non si può scorrere: due frecce discrete ai lati -->
<button type="button" class="dy-arrow dy-arrow-prev" id="dyPrev" aria-label="Pagina precedente">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M14.5 5L8 12l6.5 7"/></svg>
</button>
<button type="button" class="dy-arrow dy-arrow-next" id="dyNext" aria-label="Pagina successiva">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M9.5 5L16 12l-6.5 7"/></svg>
</button>

<div class="dy-toast" id="dyToast"></div>

<?php
/* il popup condiviso porta anche window.nisamaFace: le faccine a matita */
include 'diario_giorno.php';
?>
<script>window.DY_BOOT = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<?php include 'sidebar.php'; ?>
<script src="diario.js?v=<?php echo @filemtime(__DIR__ . '/diario.js') ?: time(); ?>"></script>
</body>
</html>