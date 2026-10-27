<?php
/* Informativa sulla privacy, italiano. Deve descrivere cio' che il sito fa davvero:
   se cambia un servizio esterno o un tempo di conservazione, si cambia qui. */
return [
    ['h' => 'Chi siamo', 'b' => [
        'Standout è un progetto personale e gratuito per studenti: flashcard, planner, timer Pomodoro, abitudini, diario e altro. Il titolare del trattamento dei dati è {TITOLARE}, persona fisica, in Italia.',
        'Per qualsiasi domanda sui tuoi dati scrivi a {EMAIL}.',
    ]],
    ['h' => 'Quali dati trattiamo', 'b' => [
        ['Dati dell’account: nome utente, indirizzo email, password (conservata solo in forma cifrata, mai in chiaro), lingua e stile scelti, data di registrazione e dell’ultimo accesso, data e versione di queste condizioni accettate.',
         'Contenuti che inserisci tu: flashcard con le loro immagini e audio, attività, planner, abitudini, diario, sessioni Pomodoro, progetti, esami e voti, impostazioni.',
         'Dati d’uso: partite di flashcard, risposte, statistiche e cronologia necessarie a farti vedere i tuoi progressi.',
         'Dati tecnici: indirizzo IP e tipo di browser nei registri di sicurezza e nei contatori anti-abuso (tentativi di accesso, numero di richieste).'],
    ]],
    ['h' => 'Perché li usiamo e su quale base', 'b' => [
        ['Per darti il servizio che hai chiesto registrandoti (art. 6.1.b GDPR): account, contenuti, funzioni.',
         'Per tenere il sito sicuro e prevenire abusi, per esempio bloccare chi prova tante password (art. 6.1.f GDPR, legittimo interesse).',
         'Per le funzioni facoltative (funzioni di intelligenza artificiale, Google Calendar) solo quando le attivi tu: il testo o il collegamento parte soltanto se premi il pulsante.'],
        'Non facciamo pubblicità, non vendiamo i tuoi dati e non li usiamo per profilarti. Non usiamo strumenti di analisi o tracciamento.',
    ]],
    ['h' => 'Con chi li condividiamo', 'b' => [
        'Per far funzionare il sito ci appoggiamo a questi fornitori. Ognuno riceve solo ciò che serve alla funzione:',
        ['Altervista (hosting): ospita il sito e il database, in Italia.',
         'Google: Gemini (funzioni di intelligenza artificiale: riceve il testo che scegli di elaborare), Cloud Text-to-Speech (lettura ad alta voce: riceve il testo da leggere), Google Calendar (solo se colleghi il tuo account), Google Places e Maps (ricerca e mappa dei luoghi nel planner), Google Fonts (caratteri: riceve il tuo indirizzo IP).',
         'Open-Meteo (previsioni del tempo: riceve la città o le coordinate del luogo scelto) e OpenStreetMap Nominatim (ricerca dei luoghi: riceve il testo cercato).',
         'jsDelivr (rete che distribuisce librerie del sito, per esempio per le formule matematiche: riceve il tuo indirizzo IP).'],
        'Alcuni di questi fornitori, in particolare Google, possono trattare i dati anche fuori dallo Spazio economico europeo, con le garanzie previste dal GDPR (clausole contrattuali standard o decisione di adeguatezza). Non c’è altra condivisione con terzi, salvo obbligo di legge.',
    ]],
    ['h' => 'Cookie e archiviazione nel browser', 'b' => [
        'Usiamo soltanto cookie tecnici, necessari al funzionamento, e per questo non serve un banner di consenso: la sessione di accesso, il cookie «ricordami» (60 giorni, se lo scegli), la lingua e l’ultimo progetto aperto. Il browser conserva inoltre alcune preferenze dell’interfaccia e una copia per l’uso senza rete. Nessun cookie di terze parti per pubblicità o analisi.',
    ]],
    ['h' => 'Per quanto tempo li conserviamo', 'b' => [
        ['Account e contenuti: finché il tuo account esiste. Quando lo elimini, vengono cancellati subito dal sito e dal database, file compresi.',
         'Registro di sicurezza (azioni sensibili con indirizzo IP): fino a 12 mesi. Tentativi di accesso: fino a 30 giorni. Contatori anti-abuso: 24 ore.',
         'Copie di sicurezza del database, conservate in privato dal titolare: i dati eliminati possono restarvi per un periodo limitato, in ogni caso non oltre 12 mesi.'],
    ]],
    ['h' => 'I tuoi diritti', 'b' => [
        'Puoi accedere ai tuoi dati, correggerli, cancellarli, riceverli in un formato leggibile (portabilità), limitarne l’uso, opporti al trattamento e revocare un consenso in qualsiasi momento.',
        'Due diritti li esegui da solo, subito: dal menu dell’account, voce «Privacy e dati», puoi scaricare tutti i tuoi dati ed eliminare l’account. Per gli altri scrivi a {EMAIL}: rispondiamo entro un mese.',
        'Se ritieni che i tuoi dati siano trattati in modo non corretto puoi fare reclamo al Garante per la protezione dei dati personali (garanteprivacy.it).',
    ]],
    ['h' => 'Minori', 'b' => [
        'Per iscriverti devi avere almeno 14 anni. Se scopriamo che un account appartiene a una persona più giovane lo eliminiamo.',
    ]],
    ['h' => 'Sicurezza', 'b' => [
        'Le password sono conservate con un hash (mai in chiaro), la connessione è cifrata (HTTPS) e le richieste sono protette da controlli contro gli abusi. Nessun sistema è sicuro al 100%: se trovi un problema, segnalalo in modo riservato (vedi SECURITY.md sul repository pubblico del progetto).',
    ]],
    ['h' => 'Modifiche a questo testo', 'b' => [
        'Versione {VERSIONE}. Se questo testo cambia in modo importante te lo diciamo prima che le modifiche si applichino.',
    ]],
];
