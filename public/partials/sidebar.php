<?php
/* =====================================================================
   STANDOUT — SIDEBAR (componente includibile)
   Da includere una volta sola, prima di </body>:

       include __DIR__ . '/sidebar.php';

   La sidebar vera resta in `sidebar.html`, che è anche una pagina di
   prova apribile da sola: questo file ne estrae stili, markup e script e
   li innesta nella pagina ospite. Così esiste una sola copia da
   modificare, e non due che col tempo divergono.

   Sopra a tutto viene aggiunto un ritocco al pulsante di apertura: più
   piccolo e quasi trasparente, così non copre quello che c'è sotto e si
   fa notare solo quando lo si sfiora.
   ===================================================================== */
if (!defined('STANDOUT_SIDEBAR')):
define('STANDOUT_SIDEBAR', true);

$sbFile = __DIR__ . '/sidebar.html';
if (is_readable($sbFile)):
    /* include e non file_get_contents: sidebar.html contiene PWA_BASE nei
       collegamenti, quindi va eseguita da PHP come quando la si include. */
    ob_start();
    include $sbFile;
    $sbHtml = (string)ob_get_clean();

    /* --- gli stili --- */
    if (preg_match_all('#<style[^>]*>(.*?)</style>#is', $sbHtml, $m)) {
        echo '<style nonce="' . sec_nonce() . '">' . implode("\n", $m[1]) . '</style>';
    }

    /* --- il contenuto del body, senza gli script che prendiamo dopo --- */
    if (preg_match('#<body[^>]*>(.*?)</body>#is', $sbHtml, $mb)) {
        $body = $mb[1];
        $body = preg_replace('#<script[^>]*>.*?</script>#is', '', $body);
        echo $body;
    }

    /* --- e infine gli script, dopo il markup a cui si agganciano --- */
    if (preg_match_all('#<script[^>]*>(.*?)</script>#is', $sbHtml, $ms)) {
        echo '<script nonce="' . sec_nonce() . '">' . implode("\n", $ms[1]) . '</script>';
    }
endif;
?>
<style nonce="<?php echo sec_nonce(); ?>">
/* Il pulsante di apertura: quasi trasparente, cosi' non copre quello che c'e'
   sotto, e leggibile solo quando lo si tocca o ci si passa sopra.
   LA MISURA NON SI TOCCA QUI. Prima questo file lo rimpiccioliva (40px, a 12px
   dal bordo) e le pagine che includono sidebar.html direttamente — la home fra
   queste — lo avevano da 56px a 16px: due misure diverse a seconda della
   pagina. Adesso dimensione, posizione e icona sono solo quelle di
   sidebar.html, uguali ovunque; qui resta soltanto l'aspetto discreto. */
.sb-fab{
    background:rgba(27,27,32,.22) !important;
    border-color:rgba(255,255,255,.10) !important;
    box-shadow:0 4px 14px rgba(0,0,0,.16) !important;
    opacity:.5;
    transition:opacity .25s ease, background-color .25s ease,
               transform .3s var(--sb-spring), box-shadow .3s var(--sb-ease) !important;
}
.sb-fab:hover,
.sb-fab:focus-visible,
.sb-fab.sb-fab-open{
    opacity:1;
    background:rgba(27,27,32,.78) !important;
}
.sb-fab:active{ opacity:1; }

/* sulle pagine chiare (planner, diario, attività) il pulsante scuro
   stonerebbe: qui diventa chiaro e altrettanto discreto */
.pl-body .sb-fab, .dy-body .sb-fab, body.tp-body .sb-fab{
    background:rgba(255,253,250,.35) !important;
    border-color:rgba(74,68,61,.12) !important;
}
.pl-body .sb-fab:hover, .dy-body .sb-fab:hover, body.tp-body .sb-fab:hover,
.pl-body .sb-fab.sb-fab-open, .dy-body .sb-fab.sb-fab-open{
    background:rgba(255,253,250,.92) !important;
}
.pl-body .sb-burger span, .dy-body .sb-burger span, body.tp-body .sb-burger span{
    background:#4A443D !important;
}

/* quando è aperta una finestra sopra la pagina, il pulsante si toglie di
   mezzo: la sidebar ha già la sua euristica, questa è la nostra */
.pl-locked .sb-fab, html.dg-open .sb-fab{ opacity:0 !important; pointer-events:none !important; }
</style>
<?php endif; ?>