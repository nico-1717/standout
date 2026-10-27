<?php
/* =====================================================================
   STANDOUT — LA SCENA DELLA PAUSA (componente includibile)

       include __DIR__ . '/pausa_scena.php';

   Perche' esiste: guardando il quadrante non si capiva se si era in
   pausa o al lavoro. Cambiava solo il colore del numero, e un numero
   che scende assomiglia a un numero che scende, qualunque colore
   abbia. Qui la pausa ha una faccia sua, che non si puo' confondere
   con niente.

   Perche' e' fatta cosi': la scena e' all'aperto — il sole, l'erba che
   si piega, gli uccelli, l'ape — e dentro c'e' qualcuno seduto con una
   tisana che non sta guardando niente. E' l'unica cosa che questa
   pagina possa fare per suggerire che la pausa migliore e' fuori, non
   in un altro schermo. Per lo stesso motivo tutto si muove piano:
   niente qui deve catturare lo sguardo, deve solo respirare.

   Tutto in CSS, nessun JavaScript: sono trasformazioni e opacita', le
   fa il compositore senza svegliare il processore, e mentre e' nascosta
   non costa niente perche' display:none ferma le animazioni.

   Lo stile sta qui dentro e non in pomodoro.css, come per clessidra.php:
   e' un pezzo unico che si porta dietro il suo aspetto, cosi' chi lo
   include non deve ricordarsi anche di un foglio di stile.
   ===================================================================== */
if (!defined('PAUSA_SCENA_EMESSA')):
define('PAUSA_SCENA_EMESSA', true);
?>
<style nonce="<?php echo sec_nonce(); ?>">
/* --------------------------------------------------------------------
   La scena c'e' solo in pausa. display:none e non opacita': cosi' non
   occupa spazio quando non serve, e soprattutto il browser smette
   davvero di animare invece di far girare tutto sotto un velo
   trasparente.
   -------------------------------------------------------------------- */
.pausa-scena{ display:none; width:100%; max-width:340px; margin:10px 0 -6px; }
body.pausa-mode .pausa-scena{ display:block; animation:psEntra .9s var(--ease-out, cubic-bezier(.16,1,.3,1)) both; }

/* Su schermi bassi la scena si stringe invece di spingere i tasti fuori:
   stessa scelta gia' fatta per il cerchio del timer. */
@media (max-height: 740px){ .pausa-scena{ max-width:250px; margin:2px 0 -10px; } }
@media (max-height: 620px){ .pausa-scena{ max-width:190px; } }
@keyframes psEntra{ from{ opacity:0; transform:translateY(8px); } to{ opacity:1; transform:none; } }

.pausa-scena svg{ width:100%; height:auto; display:block; overflow:visible; }

/* Tutti i tratti hanno lo stesso peso e lo stesso colore tenue: e' un
   disegno a matita, non un'illustrazione. Il verde della pausa lo mette
   solo dove serve distinguere. */
.ps-tratto{
    fill:none;
    stroke:color-mix(in srgb, var(--text) 42%, transparent);
    stroke-width:1.6; stroke-linecap:round; stroke-linejoin:round;
}
.ps-vivo{ stroke:color-mix(in srgb, var(--pausa-accent) 78%, transparent); }
.ps-pieno{ fill:color-mix(in srgb, var(--pausa-accent) 70%, transparent); stroke:none; }

/* Le rotazioni su SVG hanno bisogno di sapere attorno a cosa girare:
   senza queste due righe il centro sarebbe l'origine del disegno e ogni
   oscillazione diventerebbe un salto attraverso lo schermo. */
.pausa-scena [class*="ps-"]{ transform-box:fill-box; }

/* ---- il sole: non gira, respira. Un sole che ruota fa guardare il
        sole; uno che pulsa piano si dimentica, ed e' quello che vogliamo. */
.ps-sole{ transform-origin:center; animation:psRespiro 7s ease-in-out infinite; }
@keyframes psRespiro{ 0%,100%{ transform:scale(1); opacity:.85; } 50%{ transform:scale(1.06); opacity:1; } }
.ps-raggi{ transform-origin:center; animation:psRaggi 22s linear infinite; }
@keyframes psRaggi{ to{ transform:rotate(360deg); } }

/* ---- il vapore della tisana: tre filini che salgono e svaniscono,
        sfasati, cosi' non sembrano una cosa sola che pulsa.

        Tutta l'alzata sta in un numero solo, --ps-vapore-su, messa sul
        gruppo: i tre filini restano dove sono nel disegno e si sposta
        l'insieme. Il giorno che va alzato ancora si cambia quello e
        basta, invece di tre coordinate a mano.

        transform-box:fill-box sui filini non e' un dettaglio: senza,
        l'origine "bottom center" non e' il piede del filino ma il fondo
        dell'intero disegno (y=132, la riga del terreno), e lo scaleY
        della salita diventava una slittata di venti pixel verso il
        basso — il vapore partiva molto piu' in giu' di dove e'
        disegnato, che e' il motivo per cui sembrava incollato al bordo
        della tazza. Il resto del disegno la regola ce l'ha gia', questi
        no perche' non hanno una classe loro.

        E dura meno: due secondi e mezzo invece di tre e mezzo. Un filo
        di vapore che ci mette troppo a salire si guarda, e questa scena
        deve solo respirare. */
.ps-vapore{ transform:translateY(var(--ps-vapore-su, -7px)); }
.ps-vapore path{
    transform-box:fill-box; transform-origin:bottom center;
    animation:psVapore 2.5s ease-in-out infinite;
}
.ps-vapore path:nth-child(2){ animation-delay:.83s; }
.ps-vapore path:nth-child(3){ animation-delay:1.66s; }
@keyframes psVapore{
    0%   { opacity:0;  transform:translateY(2px)  scaleY(.7); }
    35%  { opacity:.7; }
    100% { opacity:0;  transform:translateY(-7px) scaleY(1.15); }
}

/* ---- chi e' seduto: respira anche lui, appena appena. Due pixel di
        movimento ogni cinque secondi bastano a non farlo sembrare morto. */
.ps-persona{ transform-origin:bottom center; animation:psSeduto 5.5s ease-in-out infinite; }
@keyframes psSeduto{ 0%,100%{ transform:scaleY(1); } 50%{ transform:scaleY(1.015); } }

/* ---- l'albero e i ciuffi d'erba: la stessa brezza, tempi diversi ---- */
.ps-albero{ transform-origin:bottom center; animation:psBrezza 6.5s ease-in-out infinite; }
.ps-erba1 { transform-origin:bottom center; animation:psBrezza 4.2s ease-in-out infinite; }
.ps-erba2 { transform-origin:bottom center; animation:psBrezza 5.1s ease-in-out infinite .8s; }
.ps-erba3 { transform-origin:bottom center; animation:psBrezza 4.7s ease-in-out infinite 1.6s; }
@keyframes psBrezza{ 0%,100%{ transform:rotate(-1.6deg); } 50%{ transform:rotate(1.6deg); } }

/* ---- gli uccelli: attraversano il cielo e ogni tanto non ci sono.
        Il colpo d'ala e' una scalatura verticale del tratto: due archi
        che si schiacciano, ed e' gia' un volo. */
.ps-uccello1, .ps-uccello2{ animation:psVolo 15s linear infinite; }
/* il secondo piu' lento e in ritardo: due uccelli sincronizzati sono uno
   sdoppiamento, non uno stormo */
.ps-uccello2{ animation-duration:19s; animation-delay:5s; }
@keyframes psVolo{
    0%   { transform:translate(-30px, 6px);  opacity:0; }
    12%  { opacity:.8; }
    50%  { transform:translate(150px, -6px); opacity:.8; }
    88%  { opacity:.8; }
    100% { transform:translate(330px, 4px);  opacity:0; }
}
.ps-ala{ transform-origin:center; animation:psAla 1.1s ease-in-out infinite; }
@keyframes psAla{ 0%,100%{ transform:scaleY(1); } 50%{ transform:scaleY(.45); } }

/* ---- l'ape: gira senza meta attorno ai fiori. Il percorso e' fatto di
        quattro tappe con tempi disuguali: se fossero regolari si
        leggerebbe come un ingranaggio invece che come un insetto. */
.ps-ape{ animation:psApe 11s ease-in-out infinite; }
@keyframes psApe{
    0%   { transform:translate(0,0); }
    23%  { transform:translate(-26px,-13px); }
    41%  { transform:translate(-14px, 4px); }
    68%  { transform:translate(18px,-16px); }
    85%  { transform:translate(9px,-3px); }
    100% { transform:translate(0,0); }
}

/* ---- la farfalla: sale e scende in tondo, con le ali che si chiudono.
        Le due ali hanno origini opposte, cosi' si aprono a libro. */
.ps-farfalla{ animation:psFarfalla 13s ease-in-out infinite; }
@keyframes psFarfalla{
    0%   { transform:translate(0,0); }
    30%  { transform:translate(22px,-18px); }
    55%  { transform:translate(38px,-4px); }
    80%  { transform:translate(16px,-22px); }
    100% { transform:translate(0,0); }
}
.ps-alaSx{ transform-origin:right center; animation:psBattito 1.4s ease-in-out infinite; }
.ps-alaDx{ transform-origin:left center;  animation:psBattito 1.4s ease-in-out infinite; }
@keyframes psBattito{ 0%,100%{ transform:scaleX(1); } 50%{ transform:scaleX(.35); } }

/* Chi ha chiesto meno movimento vede lo stesso disegno, fermo: la scena
   serve a dire "sei in pausa", e quello lo dice anche da ferma. */
@media (prefers-reduced-motion: reduce){
    .pausa-scena *{ animation:none !important; }
}
</style>

<div class="pausa-scena" id="pausaScena" aria-hidden="true">
<svg viewBox="0 0 320 132" xmlns="http://www.w3.org/2000/svg" role="img">

    <!-- il sole, in alto a destra, con i raggi che girano lentissimi -->
    <g class="ps-sole">
        <circle class="ps-tratto ps-vivo" cx="266" cy="30" r="11"/>
        <g class="ps-raggi ps-tratto ps-vivo" opacity=".65">
            <path d="M266 12v-5"/><path d="M266 48v5"/>
            <path d="M248 30h-5"/><path d="M284 30h5"/>
            <path d="M253.3 17.3l-3.6-3.6"/><path d="M278.7 42.7l3.6 3.6"/>
            <path d="M278.7 17.3l3.6-3.6"/><path d="M253.3 42.7l-3.6 3.6"/>
        </g>
    </g>

    <!-- due uccelli che passano: sono quattro archi in tutto -->
    <g class="ps-uccello1 ps-tratto" opacity="0">
        <g class="ps-ala"><path d="M0 22q5-5 10 0"/></g>
        <g class="ps-ala"><path d="M10 22q5-5 10 0"/></g>
    </g>
    <g class="ps-uccello2 ps-tratto" opacity="0">
        <g class="ps-ala"><path d="M0 40q4-4 8 0"/></g>
        <g class="ps-ala"><path d="M8 40q4-4 8 0"/></g>
    </g>

    <!-- l'albero a sinistra, che si piega nella brezza -->
    <g class="ps-albero">
        <path class="ps-tratto" d="M42 106V74"/>
        <path class="ps-tratto ps-vivo" d="M42 76c-13 0-20-8-20-16s9-15 20-15 20 7 20 15-7 16-20 16z"/>
        <path class="ps-tratto" d="M42 84l-8-7M42 90l9-8" opacity=".5"/>
    </g>

    <!-- la persona seduta, di profilo, con la tisana in mano -->
    <g class="ps-persona">
        <!-- testa, un po' inclinata verso il sole -->
        <circle class="ps-tratto" cx="150" cy="62" r="7.5"/>
        <!-- La schiena arriva fino all'anca (147,90) e da li' partono le gambe:
             prima erano due archi staccati, che cominciavano a terra a sinistra
             del corpo e finivano nel vuoto a destra — il tronco restava
             sospeso e le gambe sembravano un ghirigoro per terra.
             Adesso ogni gamba e' una linea sola in due tratti: coscia in
             avanti fino al ginocchio, stinco che rientra verso il corpo e
             appoggia il piede sul terreno. E' la posa a gambe incrociate
             vista di profilo, che e' quella che si stava cercando. -->
        <path class="ps-tratto" d="M151 70q-6 16-4 33"/>
        <path class="ps-tratto" d="M147 103q10-6 18-4q-4 9-13 7"/>
        <path class="ps-tratto" d="M147 104q9-4 15-2q-4 6-11 4" opacity=".55"/>
        <!-- il braccio che porge la tazza: parte dalla spalla, non da meta'
             schiena, cosi' la mano arriva davvero al bordo della tazza -->
        <path class="ps-tratto" d="M149 79q9 3 13-1"/>
        <!-- la tazza -->
        <path class="ps-tratto ps-vivo" d="M162 72h11v5a5.5 5.5 0 0 1-5.5 5.5h0A5.5 5.5 0 0 1 162 77z"/>
        <path class="ps-tratto ps-vivo" d="M173 74h2.4a2.2 2.2 0 0 1 0 4.4H173" opacity=".8"/>
        <!-- Il vapore: tre filini sfasati. Le coordinate qui sotto sono il
             disegno a riposo; a portarlo in alto e' la variabile ps-vapore-su
             nel foglio di stile qui sopra, cosi' l'altezza si regola in un
             posto solo. (Niente due trattini di fila qui dentro: dentro un
             commento non sono ammessi.) -->
        <g class="ps-vapore ps-tratto ps-vivo" stroke-width="1.3">
            <path d="M165 70q2.5-3.5 0-7"/>
            <path d="M169 70q2.5-3.5 0-7"/>
            <path d="M172.5 70q2.5-3.5 0-7" opacity=".7"/>
        </g>
    </g>

    <!-- l'ape, che ronza attorno all'erba -->
    <g class="ps-ape">
        <ellipse class="ps-pieno" cx="212" cy="72" rx="3.2" ry="2.3"/>
        <path class="ps-tratto" d="M210 69.5q2-3.5 4.5-1" stroke-width="1.2" opacity=".7"/>
    </g>

    <!-- la farfalla -->
    <g class="ps-farfalla">
        <g class="ps-alaSx"><path class="ps-tratto ps-vivo" d="M92 52q-6-6-9 0t9 3z" stroke-width="1.3"/></g>
        <g class="ps-alaDx"><path class="ps-tratto ps-vivo" d="M92 52q6-6 9 0t-9 3z" stroke-width="1.3"/></g>
    </g>

    <!-- il terreno: una linea sola, appena mossa -->
    <path class="ps-tratto" d="M4 108q76-9 156-3t156-1" opacity=".55"/>

    <!-- tre ciuffi d'erba che si piegano con tempi diversi -->
    <g class="ps-erba1 ps-tratto ps-vivo" opacity=".7">
        <path d="M198 108v-9M198 108q-4-5-6-7M198 108q4-4 6-6"/>
    </g>
    <g class="ps-erba2 ps-tratto ps-vivo" opacity=".7">
        <path d="M232 107v-7M232 107q-3.5-4-5.5-5.5"/>
    </g>
    <g class="ps-erba3 ps-tratto ps-vivo" opacity=".7">
        <path d="M86 108v-8M86 108q4-4 6-6"/>
    </g>
</svg>
</div>
<?php endif; ?>
