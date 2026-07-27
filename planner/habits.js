/* =====================================================================
   HABIT TRACKER — logica di pagina
   Tutti i calcoli (streak, percentuali, grafici) girano sul client sui
   log già presenti in memoria: così ogni spunta aggiorna i numeri e i
   grafici all'istante, e il salvataggio sul server avviene in parallelo.
   ===================================================================== */
(function () {
'use strict';

/* =====================================================================
   1. STATO
   ===================================================================== */
var BOOT = window.HB_BOOT || {};
var S = {
    today:        BOOT.today,
    selected:     BOOT.today,
    habits:       BOOT.habits || [],
    logs:         {},                       // "habitId|YYYY-MM-DD" -> log
    categories:   BOOT.categories || [],
    journal:      BOOT.journal || {},       // "YYYY-MM-DD" -> { mood, energy, note }
    achievements: BOOT.achievements || [],
    settings:     BOOT.settings || {},
    catalog:      BOOT.catalog || { palette: [], icons: {}, units: [], presets: [], day_parts: [] },
    filter:       'all',
    carouselIdx:  0,
    detailHabit:  null,
    detailMonth:  null,
    tableDays:    14,
    lastUndo:     null
};
(BOOT.logs || []).forEach(function (l) { S.logs[l.habit_id + '|' + l.date] = l; });

var DOW_SHORT = ['Lun','Mar','Mer','Gio','Ven','Sab','Dom'];
var DOW_MINI  = ['L','M','M','G','V','S','D'];
var MONTHS    = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
var MONTHS_S  = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
var DAY_PARTS = { any:'Tutto il giorno', morning:'Mattina', afternoon:'Pomeriggio', evening:'Sera', night:'Notte' };
var DAY_PART_ICON = { any:'🕐', morning:'🌅', afternoon:'☀️', evening:'🌆', night:'🌙' };
/* i colori del grafico a barre ruotano per settimana: è quello che dà
   l'effetto "arcobaleno" degli screenshot senza diventare confusione */
var WEEK_HUES = ['#7C5CFF', '#0A84FF', '#00C7BE', '#FF2D8F', '#00E0A0'];

/* =====================================================================
   2. UTILITÀ DATE
   ===================================================================== */
function pad(n) { return n < 10 ? '0' + n : '' + n; }
function toStr(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function toDate(s) { var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
function addDays(s, n) { var d = toDate(s); d.setDate(d.getDate() + n); return toStr(d); }
function dowIdx(s) { return (toDate(s).getDay() + 6) % 7; }              // 0 = lunedì
function daysBetween(a, b) { return Math.round((toDate(b) - toDate(a)) / 86400000); }
function isFuture(s) { return s > S.today; }
function labelDate(s) {
    if (s === S.today) return 'Oggi';
    if (s === addDays(S.today, -1)) return 'Ieri';
    var d = toDate(s);
    return DOW_SHORT[dowIdx(s)] + ' ' + d.getDate() + ' ' + MONTHS_S[d.getMonth()].toLowerCase();
}
function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
}
function fmtNum(n) {
    if (typeof n !== 'number' || isNaN(n)) return String(n != null ? n : 0);
    n = Math.round(n * 100) / 100;
    return (n % 1 === 0) ? String(n) : n.toFixed(1).replace('.', ',');
}

/* =====================================================================
   3. LOGICA ABITUDINI
   ===================================================================== */
function habitById(id) {
    for (var i = 0; i < S.habits.length; i++) if (S.habits[i].id === id) return S.habits[i];
    return null;
}
function logOf(habitId, date) { return S.logs[habitId + '|' + date] || null; }

/* l'abitudine è prevista in quel giorno? */
function isDue(h, date) {
    if (date < h.start_date) return false;
    if (h.end_date && date > h.end_date) return false;
    if (h.frequency_type === 'daily')    return h.weekdays.charAt(dowIdx(date)) === '1';
    if (h.frequency_type === 'interval') {
        var diff = daysBetween(h.start_date, date);
        return diff >= 0 && diff % Math.max(1, h.interval_days) === 0;
    }
    return true; // weekly / monthly: flessibili, spuntabili ogni giorno
}

/* stato del giorno, normalizzato per la UI */
function stateOf(h, date) {
    var log = logOf(h.id, date);
    var target = h.goal_type === 'quantity' ? h.target_amount : 1;
    var amount = log ? log.amount : 0;
    if (h.kind === 'quit') {
        var qstatus = log ? log.status : (!isFuture(date) && date >= h.start_date ? 'clean' : 'empty');
        if (qstatus === 'skipped') return { status:'skipped', amount:0, target:target, pct:0, log:log };
        if (qstatus === 'failed')  return { status:'failed', amount:amount, target:target, pct:0, log:log };
        /* quantità: sei ancora sotto la soglia, ma qualcosa è già successo oggi */
        if (h.goal_type === 'quantity' && qstatus === 'partial' && amount > 0) {
            var safePct = target > 0 ? Math.max(0, 1 - amount / target) : 0;
            return { status:'partial', amount:amount, target:target, pct:safePct, log:log };
        }
        return { status:(isFuture(date) ? 'empty' : 'clean'), amount:0, target:target, pct:1, log:log };
    }
    var status = log ? log.status : 'empty';
    var pct = target > 0 ? Math.min(1, amount / target) : 0;
    if (status === 'skipped') return { status:'skipped', amount:amount, target:target, pct:0, log:log };
    if (amount >= target && target > 0) return { status:'done', amount:amount, target:target, pct:1, log:log };
    if (amount > 0) return { status:'partial', amount:amount, target:target, pct:pct, log:log };
    return { status:'empty', amount:0, target:target, pct:0, log:log };
}
/* "conta come riuscito"? usato ovunque nelle statistiche.
   Per le "da evitare" a quantità, restare sotto la soglia (status 'partial')
   conta come riuscito tanto quanto una giornata "pulita" */
function isSuccess(h, date) {
    var st = stateOf(h, date);
    if (h.kind === 'quit') return st.status === 'clean' || st.status === 'partial';
    return st.status === 'done';
}

/* streak corrente: skipped è neutro, oggi non ancora fatto non rompe */
function streakOf(h) {
    var streak = 0, cursor = S.today, guard = 0;
    while (guard++ < 1200) {
        if (cursor < h.start_date) break;
        if (isDue(h, cursor)) {
            var st = stateOf(h, cursor);
            if (isSuccess(h, cursor)) streak++;
            else if (st.status === 'skipped') { /* neutro */ }
            else if (cursor === S.today) { /* la giornata è ancora aperta */ }
            else break;
        }
        cursor = addDays(cursor, -1);
    }
    return streak;
}
function bestStreakOf(h) {
    var best = h.best_streak || 0, run = 0, cursor = h.start_date, guard = 0;
    while (cursor <= S.today && guard++ < 1500) {
        if (isDue(h, cursor)) {
            var st = stateOf(h, cursor);
            if (isSuccess(h, cursor)) { run++; if (run > best) best = run; }
            else if (st.status !== 'skipped') run = 0;
        }
        cursor = addDays(cursor, 1);
    }
    return best;
}
/* giorni previsti e riusciti in una finestra */
function windowStats(h, fromDate, toDate_) {
    var due = 0, ok = 0, skipped = 0, failed = 0, amount = 0, cursor = fromDate, guard = 0;
    if (fromDate < h.start_date) fromDate = h.start_date, cursor = fromDate;
    while (cursor <= toDate_ && guard++ < 1500) {
        if (isDue(h, cursor)) {
            var st = stateOf(h, cursor);
            amount += st.amount;
            if (st.status === 'skipped') skipped++;
            else { due++; if (isSuccess(h, cursor)) ok++; if (st.status === 'failed') failed++; }
        }
        cursor = addDays(cursor, 1);
    }
    return { due:due, ok:ok, skipped:skipped, failed:failed, amount:amount, rate: due ? ok / due : 0 };
}
function totalCheckins(h) {
    var n = 0;
    for (var k in S.logs) if (S.logs[k].habit_id === h.id && S.logs[k].status === 'done') n++;
    return n;
}

/* abitudini attive in un giorno, filtrate dalla UI */
/* Il lunedì della settimana a cui appartiene una data. */
function weekStartOf(date) {
    var d = new Date(date + 'T00:00:00');
    var dow = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - dow);
    return d.toISOString().slice(0, 10);
}

/* Quante volte un'abitudine settimanale è stata fatta in quella settimana.
   Non c'è tetto: cinque palestre su quattro contano cinque. */
function weeklyProgress(h, date) {
    var start = weekStartOf(date);
    var end = new Date(start + 'T00:00:00');
    end.setDate(end.getDate() + 6);
    var endStr = end.toISOString().slice(0, 10);
    var n = 0;
    for (var k in S.logs) {
        if (!S.logs.hasOwnProperty(k)) continue;
        var l = S.logs[k];
        if (l.habit_id !== h.id || l.status !== 'done') continue;
        if (l.date >= start && l.date <= endStr) n++;
    }
    return { done: n, target: Math.max(1, h.times_per_week || 1) };
}

/* Le abitudini settimanali non dipendono dal giorno: valgono per la
   settimana intera, quindi si mostrano sempre nella loro sezione. */
function weeklyHabits(applyFilter) {
    return S.habits.filter(function (h) {
        if (h.is_archived || h.frequency_type !== 'weekly') return false;
        if (applyFilter && S.filter !== 'all') {
            if (S.filter.indexOf('cat:') === 0) return h.category_id === +S.filter.slice(4);
            if (S.filter.indexOf('part:') === 0) return h.day_part === S.filter.slice(5);
        }
        return true;
    });
}

function habitsForDay(date, applyFilter) {
    return S.habits.filter(function (h) {
        if (h.is_archived) return false;
        /* le settimanali hanno una sezione tutta loro: qui darebbero
           l'impressione di essere mancate ogni giorno */
        if (h.frequency_type === 'weekly') return false;
        if (!isDue(h, date)) return false;
        if (applyFilter && S.filter !== 'all') {
            if (S.filter.indexOf('cat:') === 0) return h.category_id === +S.filter.slice(4);
            if (S.filter.indexOf('part:') === 0) return h.day_part === S.filter.slice(5);
            if (S.filter === 'todo') return !isSuccess(h, date);
        }
        return true;
    });
}
/* avanzamento della giornata (0..1) */
function dayProgress(date) {
    var list = habitsForDay(date, false), done = 0, total = 0;
    list.forEach(function (h) {
        var st = stateOf(h, date);
        if (st.status === 'skipped') return;
        total++;
        if (isSuccess(h, date)) done++;
    });
    return { done: done, total: total, pct: total ? done / total : 0 };
}

/* =====================================================================
   4. EFFETTI: vibrazione, suono, coriandoli, toast, contatori
   ===================================================================== */
function buzz(ms) { if (S.settings.haptics_enabled && navigator.vibrate) { try { navigator.vibrate(ms || 12); } catch (e) {} } }

var audioCtx = null;
function blip(up) {
    if (!S.settings.sound_enabled) return;
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        var o = audioCtx.createOscillator(), g = audioCtx.createGain(), t = audioCtx.currentTime;
        o.type = 'sine';
        o.frequency.setValueAtTime(up ? 660 : 420, t);
        o.frequency.exponentialRampToValueAtTime(up ? 1180 : 300, t + 0.11);
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.09, t + 0.015);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
        o.connect(g); g.connect(audioCtx.destination); o.start(t); o.stop(t + 0.24);
    } catch (e) {}
}

var confettiCanvas = document.getElementById('hbConfetti');
var cctx = confettiCanvas ? confettiCanvas.getContext('2d') : null;
var particles = [], rafId = null;
function sizeCanvas() {
    if (!confettiCanvas) return;
    var r = window.devicePixelRatio || 1;
    confettiCanvas.width = window.innerWidth * r;
    confettiCanvas.height = window.innerHeight * r;
    cctx.setTransform(r, 0, 0, r, 0, 0);
}
sizeCanvas();
window.addEventListener('resize', sizeCanvas);

function burst(x, y, color, power) {
    if (!cctx || !S.settings.confetti_enabled) return;
    var n = power || 26;
    for (var i = 0; i < n; i++) {
        var a = (Math.PI * 2 * i) / n + Math.random() * 0.5;
        var v = 3.4 + Math.random() * 6.2;
        particles.push({
            x:x, y:y, vx:Math.cos(a) * v, vy:Math.sin(a) * v - 2.4,
            life:1, size:2.6 + Math.random() * 4.4, rot:Math.random() * 6.28,
            vr:(Math.random() - 0.5) * 0.4,
            c: Math.random() < 0.55 ? color : ['#FFCC00', '#FF2D8F', '#00E0A0', '#0A84FF'][(Math.random() * 4) | 0]
        });
    }
    if (!rafId) rafId = requestAnimationFrame(tick);
}
function tick() {
    cctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
    for (var i = particles.length - 1; i >= 0; i--) {
        var p = particles[i];
        p.vy += 0.28; p.vx *= 0.99; p.x += p.vx; p.y += p.vy; p.rot += p.vr; p.life -= 0.016;
        if (p.life <= 0 || p.y > window.innerHeight + 40) { particles.splice(i, 1); continue; }
        cctx.save(); cctx.globalAlpha = Math.max(0, p.life);
        cctx.translate(p.x, p.y); cctx.rotate(p.rot); cctx.fillStyle = p.c;
        cctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 1.6);
        cctx.restore();
    }
    rafId = particles.length ? requestAnimationFrame(tick) : (cctx.clearRect(0, 0, window.innerWidth, window.innerHeight), null);
}

function toast(emoji, text, undoFn) {
    var stack = document.getElementById('hbToasts');
    var el = document.createElement('div');
    el.className = 'hb-toast';
    el.innerHTML = '<span class="hb-toast-emoji">' + emoji + '</span><span>' + esc(text) + '</span>';
    if (undoFn) {
        var b = document.createElement('button');
        b.className = 'hb-toast-undo'; b.textContent = 'Annulla';
        b.onclick = function () { undoFn(); close(); };
        el.appendChild(b);
    }
    stack.appendChild(el);
    var timer = setTimeout(close, undoFn ? 4200 : 2400);
    function close() { clearTimeout(timer); el.classList.add('out'); setTimeout(function () { el.remove(); }, 320); }
}

/* contatore animato: il numero che sale è metà della soddisfazione */
function countUp(el, to, suffix) {
    var from = parseFloat(el.getAttribute('data-v') || '0');
    if (from === to) { el.textContent = fmtNum(to) + (suffix || ''); return; }
    var start = performance.now(), dur = 620;
    el.setAttribute('data-v', to);
    function step(now) {
        var t = Math.min(1, (now - start) / dur);
        var e = 1 - Math.pow(1 - t, 3);
        el.textContent = fmtNum(Math.round((from + (to - from) * e) * 10) / 10) + (suffix || '');
        if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
}

/* =====================================================================
   5. API
   ===================================================================== */
function api(action, data) {
    var body = new URLSearchParams();
    for (var k in data) if (data[k] !== null && data[k] !== undefined) body.append(k, data[k]);
    return fetch('habits.php?ajax=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
    }).then(function (r) { return r.json(); });
}

/* =====================================================================
   6. CHECK-IN
   ===================================================================== */
function applyLocal(habitId, date, res) {
    var key = habitId + '|' + date;
    if (res.removed) delete S.logs[key];
    else S.logs[key] = {
        habit_id: habitId, date: date, status: res.status, amount: res.amount,
        target: res.target, completions: res.completions,
        note: (S.logs[key] && S.logs[key].note) || null, mood: (S.logs[key] && S.logs[key].mood) || null
    };
    var h = habitById(habitId);
    if (h && res.best_streak != null) h.best_streak = res.best_streak;
}

function checkIn(habitId, date, mode, origin) {
    var h = habitById(habitId);
    if (!h || isFuture(date)) return Promise.resolve();
    var before = stateOf(h, date).status;

    return api('log_set', { habit_id: habitId, date: date, mode: mode, source: origin || 'tap' })
        .then(function (res) {
            if (!res.ok) { toast('⚠️', res.error || 'Non è stato possibile salvare'); return; }
            applyLocal(habitId, date, res);
            var after = stateOf(h, date).status;

            renderAll();

            if ((after === 'done' && before !== 'done') || (h.kind === 'quit' && before === 'failed' && after !== 'failed')) {
                celebrate(habitId, res.streak);
            } else if (h.kind === 'quit' && after === 'failed' && before !== 'failed') {
                buzz(30); toast('🙈', 'Segnato. Domani è un altro giorno.');
            } else { buzz(8); }

            if (res.unlocked && res.unlocked.length) setTimeout(function () { showAward(res.unlocked[0]); }, 620);
        });
}

function celebrate(habitId, streak) {
    buzz(18); blip(true);
    var row = document.querySelector('.hb-row[data-id="' + habitId + '"]');
    var h = habitById(habitId);
    if (row) {
        row.classList.add('just-done');
        setTimeout(function () { row.classList.remove('just-done'); }, 620);
        var r = row.getBoundingClientRect();
        burst(r.left + 46, r.top + r.height / 2, h ? h.color : '#7C5CFF', 24);
    }
    var prog = dayProgress(S.selected);
    if (prog.total > 0 && prog.done === prog.total) {
        setTimeout(function () {
            burst(window.innerWidth * 0.5, window.innerHeight * 0.34, '#FFCC00', 70);
            toast('🌟', 'Giornata perfetta. Tutte completate.');
            blip(true);
        }, 360);
    } else if (streak && [7, 21, 30, 50, 66, 100, 200, 365].indexOf(streak) >= 0) {
        setTimeout(function () { toast('🔥', streak + ' giorni di fila!'); }, 300);
    }
}

function showAward(a) {
    var ov = document.getElementById('hbAward');
    document.getElementById('hbAwardIcon').textContent = a.icon;
    document.getElementById('hbAwardName').textContent = a.name;
    document.getElementById('hbAwardDesc').textContent = a.description;
    ov.classList.add('show');
    buzz(40); blip(true);
    burst(window.innerWidth / 2, window.innerHeight / 2 - 60, '#FFCC00', 80);
    api('achievements_seen', {});
}
document.getElementById('hbAwardClose').onclick = function () { document.getElementById('hbAward').classList.remove('show'); };

/* =====================================================================
   7. RENDER — barra giorni
   ===================================================================== */
function renderDayStrip() {
    var wrap = document.getElementById('hbDayStrip');
    var n = S.settings.visible_days || 11;
    var html = '';
    for (var i = n - 1; i >= 0; i--) {
        var date = addDays(S.today, -i);
        var prog = dayProgress(date);
        var C = 2 * Math.PI * 15;
        var off = C * (1 - prog.pct);
        var cls = 'hb-day';
        if (date === S.selected) cls += ' sel';
        if (date === S.today) cls += ' today';
        if (prog.done > 0) cls += ' has-log';
        if (prog.total > 0 && prog.done === prog.total) cls += ' perfect';
        html += '<button type="button" class="' + cls + '" data-date="' + date + '">' +
            '<span class="hb-day-dow">' + DOW_MINI[dowIdx(date)] + '</span>' +
            '<span class="hb-day-ring">' +
                '<svg viewBox="0 0 34 34"><circle class="hb-day-track" cx="17" cy="17" r="15"/>' +
                '<circle class="hb-day-prog" cx="17" cy="17" r="15" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + off.toFixed(1) + '"/></svg>' +
                '<span class="hb-day-n">' + toDate(date).getDate() + '</span>' +
            '</span>' +
            '<span class="hb-day-mark"></span>' +
        '</button>';
    }
    wrap.innerHTML = html;
    var sel = wrap.querySelector('.hb-day.sel');
    if (sel) sel.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
    document.getElementById('hbEyebrow').textContent = labelDate(S.selected);
}

/* =====================================================================
   8. RENDER — carosello statistiche
   ===================================================================== */
function globalStreak() {
    var streak = 0, cursor = S.today, guard = 0;
    while (guard++ < 900) {
        var list = habitsForDay(cursor, false);
        if (!list.length) { cursor = addDays(cursor, -1); continue; }
        var prog = dayProgress(cursor);
        if (prog.total === 0) { cursor = addDays(cursor, -1); continue; }
        if (prog.done === prog.total) streak++;
        else if (cursor === S.today) { /* giornata ancora aperta */ }
        else break;
        cursor = addDays(cursor, -1);
    }
    return streak;
}
function countDone(fromDate, toDate_) {
    var n = 0;
    for (var k in S.logs) {
        var l = S.logs[k];
        if (l.status === 'done' && l.date >= fromDate && l.date <= toDate_) n++;
    }
    return n;
}
function perfectDaysCount() {
    var n = 0, cursor = addDays(S.today, -364), guard = 0;
    while (cursor <= S.today && guard++ < 400) {
        var p = dayProgress(cursor);
        if (p.total > 0 && p.done === p.total) n++;
        cursor = addDays(cursor, 1);
    }
    return n;
}
function last7Pcts() {
    var out = [];
    for (var i = 6; i >= 0; i--) out.push(dayProgress(addDays(S.today, -i)).pct);
    return out;
}

function statCards() {
    var prog = dayProgress(S.selected);
    var monthStart = S.today.slice(0, 8) + '01';
    var gStreak = globalStreak();
    var w30 = rangeRate(30);
    var best = 0;
    S.habits.forEach(function (h) { var b = bestStreakOf(h); if (b > best) best = b; });

    return [
        { key:'streak',  color:'#FF6B35', emoji:'🔥', kicker:'Serie attuale', value:gStreak, suffix:'',
          sub:gStreak > 0 ? 'giorni perfetti di fila' : 'completa tutto oggi per iniziare', spark:last7Pcts() },
        { key:'today',   color:'#7C5CFF', emoji:'✅', kicker:labelDate(S.selected), value:prog.done, suffix:'/' + prog.total,
          sub:prog.total ? Math.round(prog.pct * 100) + '% della giornata' : 'nessuna abitudine prevista', ring:prog.pct },
        { key:'month',   color:'#0A84FF', emoji:'📅', kicker:'Questo mese', value:countDone(monthStart, S.today), suffix:'',
          sub:'check-in in ' + MONTHS[toDate(S.today).getMonth()].toLowerCase(), spark:lastNPcts(7) },
        { key:'rate',    color:'#00E0A0', emoji:'📈', kicker:'Ultimi 30 giorni', value:Math.round(w30 * 100), suffix:'%',
          sub:'tasso di completamento', ring:w30 },
        { key:'total',   color:'#FFCC00', emoji:'🏆', kicker:'Da sempre', value:countDone('0000-01-01', S.today), suffix:'',
          sub:'abitudini completate in totale', spark:lastNPcts(7) },
        { key:'perfect', color:'#FF2D8F', emoji:'💎', kicker:'Giornate perfette', value:perfectDaysCount(), suffix:'',
          sub:'giorni con tutto completato', spark:last7Pcts() },
        { key:'best',    color:'#BF5AF2', emoji:'👑', kicker:'Record personale', value:best, suffix:'',
          sub:'la serie più lunga mai raggiunta', ring:best ? Math.min(1, gStreak / best) : 0 }
    ];
}
function lastNPcts(n) {
    var out = [];
    for (var i = n - 1; i >= 0; i--) out.push(dayProgress(addDays(S.today, -i)).pct);
    return out;
}
function rangeRate(days) {
    var from = addDays(S.today, -(days - 1)), due = 0, ok = 0;
    S.habits.forEach(function (h) {
        if (h.is_archived) return;
        var w = windowStats(h, from, S.today);
        due += w.due; ok += w.ok;
    });
    return due ? ok / due : 0;
}

var lastStatValues = {};
function renderCarousel() {
    var wrap = document.getElementById('hbCarousel');
    var keepScroll = wrap.scrollLeft;
    var cards = statCards();
    var html = cards.map(function (c) {
        var extra = '';
        if (c.spark) {
            extra = '<div class="hb-stat-spark">' + c.spark.map(function (p, i) {
                return '<i style="height:' + Math.max(6, Math.round(p * 44)) + 'px;animation-delay:' + (i * 0.05) + 's"></i>';
            }).join('') + '</div>';
        } else if (c.ring != null) {
            var C = 2 * Math.PI * 24, off = C * (1 - Math.min(1, c.ring));
            extra = '<div class="hb-stat-ring"><svg width="56" height="56" viewBox="0 0 56 56">' +
                '<circle cx="28" cy="28" r="24" stroke="var(--ink-track)"/>' +
                '<circle cx="28" cy="28" r="24" stroke="' + c.color + '" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + off.toFixed(1) + '"/>' +
                '</svg></div>';
        }
        return '<div class="hb-stat" data-key="' + c.key + '" style="--sc:' + c.color + '">' +
            '<div class="hb-stat-head"><span class="hb-stat-emoji">' + c.emoji + '</span><span class="hb-stat-kicker">' + esc(c.kicker) + '</span></div>' +
            extra +
            '<div><div class="hb-stat-main"><span class="hb-stat-value hb-cnt" data-to="' + c.value + '">0</span>' +
            (c.suffix ? '<span class="hb-stat-suffix">' + esc(c.suffix) + '</span>' : '') + '</div>' +
            '<div class="hb-stat-sub">' + esc(c.sub) + '</div></div>' +
        '</div>';
    }).join('');
    wrap.innerHTML = html;

    wrap.scrollLeft = keepScroll;
    wrap.querySelectorAll('.hb-stat').forEach(function (card) {
        var key = card.getAttribute('data-key');
        var el = card.querySelector('.hb-cnt');
        if (!el) return;
        el.setAttribute('data-v', lastStatValues[key] != null ? lastStatValues[key] : 0);
        var to = parseFloat(el.getAttribute('data-to'));
        lastStatValues[key] = to;
        countUp(el, to, '');
    });
    document.getElementById('hbCarouselDots').innerHTML = cards.map(function (c, i) {
        return '<span class="hb-dot' + (i === S.carouselIdx ? ' on' : '') + '"></span>';
    }).join('');
}

/* il carosello scorre da solo, ma si ferma appena lo tocchi */
var carouselTimer = null, carouselPaused = false;
function startCarousel() {
    if (carouselTimer) clearInterval(carouselTimer);
    if (!S.settings.carousel_autoplay) return;
    carouselTimer = setInterval(function () {
        if (carouselPaused) return;
        var wrap = document.getElementById('hbCarousel');
        var card = wrap.querySelector('.hb-stat');
        if (!card) return;
        var step = card.offsetWidth + 11;
        var next = (S.carouselIdx + 1) % wrap.children.length;
        wrap.scrollTo({ left: next * step, behavior: 'smooth' });
    }, (S.settings.carousel_interval_s || 6) * 1000);
}
(function bindCarousel() {
    var wrap = document.getElementById('hbCarousel');
    wrap.addEventListener('scroll', function () {
        var card = wrap.querySelector('.hb-stat');
        if (!card) return;
        var idx = Math.round(wrap.scrollLeft / (card.offsetWidth + 11));
        if (idx !== S.carouselIdx) {
            S.carouselIdx = idx;
            document.getElementById('hbCarouselDots').querySelectorAll('.hb-dot').forEach(function (d, i) {
                d.classList.toggle('on', i === idx);
            });
            buzz(5);
        }
    }, { passive: true });
    wrap.addEventListener('pointerdown', function () { carouselPaused = true; });
    window.addEventListener('pointerup', function () { setTimeout(function () { carouselPaused = false; }, 6000); });
})();

/* =====================================================================
   9. RENDER — riepilogo giorno
   ===================================================================== */
function renderDayHero() {
    var el = document.getElementById('hbDayHero');
    var prog = dayProgress(S.selected);
    var C = 2 * Math.PI * 33, off = C * (1 - prog.pct);
    var pct = Math.round(prog.pct * 100);
    var msg = prog.total === 0 ? 'Nessuna abitudine prevista'
            : pct === 100 ? 'Giornata perfetta, chiusa così.'
            : pct >= 60 ? 'Ci sei quasi, tieni il ritmo.'
            : pct > 0 ? 'Hai iniziato: continua.'
            : 'Il primo check-in è il più importante.';
    var streak = globalStreak();
    var skipped = habitsForDay(S.selected, false).filter(function (h) { return stateOf(h, S.selected).status === 'skipped'; }).length;
    var jr = S.journal[S.selected] || {};
    var hasJournal = (jr.note && jr.note.length) || jr.mood;

    el.innerHTML =
        '<div class="hb-dayhero-ring">' +
            '<svg width="74" height="74" viewBox="0 0 74 74">' +
            '<circle cx="37" cy="37" r="33" stroke="var(--hairline)"/>' +
            '<circle cx="37" cy="37" r="33" stroke="var(--accent)" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + off.toFixed(1) + '" style="transition:stroke-dashoffset .7s var(--ease-out)"/>' +
            '</svg>' +
            '<div class="hb-dayhero-pct"><span class="hb-cnt" data-to="' + pct + '">0</span></div>' +
        '</div>' +
        '<div class="hb-dayhero-info">' +
            '<div class="hb-dayhero-title">' + esc(labelDate(S.selected)) + '</div>' +
            '<div class="hb-dayhero-sub">' + esc(hasJournal && jr.note ? jr.note : msg) + '</div>' +
            '<div class="hb-dayhero-chips">' +
                '<span class="hb-mini-chip">✅ <b>' + prog.done + '</b>/' + prog.total + '</span>' +
                '<span class="hb-mini-chip">🔥 <b>' + streak + '</b> di fila</span>' +
                (skipped ? '<span class="hb-mini-chip">⏭ <b>' + skipped + '</b> saltate</span>' : '') +
                (jr.mood ? '<span class="hb-mini-chip">' + MOODS[jr.mood - 1] + '</span>' : '') +
                (hasJournal ? '' : '<span class="hb-mini-chip hb-mini-chip-ghost">📔 Scrivi</span>') +
            '</div>' +
        '</div>';
    var cnt = el.querySelector('.hb-cnt');
    countUp(cnt, pct, '%');
}

/* =====================================================================
   10. RENDER — elenco abitudini
   ===================================================================== */
function renderFilters() {
    var el = document.getElementById('hbFilters');
    var parts = [{ k:'all', l:'Tutte' }, { k:'todo', l:'Da fare' }];
    ['morning','afternoon','evening','night'].forEach(function (p) {
        if (S.habits.some(function (h) { return !h.is_archived && h.day_part === p; })) {
            parts.push({ k:'part:' + p, l:DAY_PART_ICON[p] + ' ' + DAY_PARTS[p] });
        }
    });
    el.innerHTML = parts.map(function (p) {
        return '<button type="button" class="hb-filter' + (S.filter === p.k ? ' on' : '') + '" data-f="' + p.k + '">' + esc(p.l) + '</button>';
    }).join('');
}

/* La riga di un'abitudine settimanale: è la stessa riga di sempre, con
   dentro l'anellino del progresso. Riusare rowHTML invece di riscriverla
   significa che spunta, scorrimento e pressione prolungata continuano a
   funzionare identici, senza doverli mantenere in due posti. */
function weeklyRowHTML(h, date) {
    var p = weeklyProgress(h, date);
    var C = 75.4;                                  // 2πr con r = 12
    var frac = Math.min(1, p.done / p.target);
    var oltre = p.done > p.target;

    var ring = '<span class="hb-week-ring' + (oltre ? ' is-over' : '') + '" aria-label="'
        + p.done + ' su ' + p.target + ' questa settimana">'
        + '<svg viewBox="0 0 28 28">'
        + '<circle class="bg" cx="14" cy="14" r="12"/>'
        + '<circle class="fg" cx="14" cy="14" r="12" stroke-dasharray="' + C
        + '" stroke-dashoffset="' + (C * (1 - frac)).toFixed(1) + '"/>'
        + '</svg><b>' + p.done + '/' + p.target + '</b></span>';

    var html = rowHTML(h, date);
    html = html.replace('class="hb-row', 'class="hb-row hb-row-week');
    /* subito dopo il tasto della spunta */
    return html.replace('</button>', '</button>' + ring);
}

function rowHTML(h, date) {
    var st = stateOf(h, date);
    var cls = 'hb-row';
    if (st.status === 'done' || st.status === 'clean') cls += ' is-done';
    else if (st.status === 'partial') cls += ' is-partial';
    else if (st.status === 'failed') cls += ' is-failed';
    else if (st.status === 'skipped') cls += ' is-skipped';

    var streak = streakOf(h);
    var metaBits = [];
    if (streak > 0) metaBits.push('<span class="hb-row-streak">🔥 ' + streak + '</span>');
    if (h.kind === 'quit') {
        metaBits.push('da evitare');
        if (h.goal_type === 'quantity') metaBits.push(fmtNum(st.amount) + '/&lt;' + fmtNum(st.target) + ' ' + esc(h.unit));
    } else if (h.goal_type === 'quantity') {
        metaBits.push(fmtNum(st.amount) + '/' + fmtNum(st.target) + ' ' + esc(h.unit));
    }
    if (h.day_part !== 'any') metaBits.push(DAY_PART_ICON[h.day_part]);

    var qty = '';
    if (h.goal_type === 'quantity') {
        qty = '<div class="hb-row-qty">' + fmtNum(st.amount) + '<small>' + (h.unit ? esc(h.unit) : (h.kind === 'quit' ? '&lt; ' + fmtNum(st.target) : 'su ' + fmtNum(st.target))) + '</small></div>';
    }
    var bar = '';
    if (h.goal_type === 'quantity') {
        /* per le "da evitare" la barra è una barra di rischio: si riempie
           man mano che ci si avvicina al limite (indipendente da st.pct,
           che invece per le quit misura il "successo" ed è usato altrove
           per heatmap e grafici) */
        var barPct = h.kind === 'quit'
            ? (st.target > 0 ? Math.min(1, st.amount / st.target) : 0)
            : st.pct;
        bar = '<div class="hb-row-bar"><i style="width:' + Math.round(barPct * 100) + '%"></i></div>';
    }

    return '<div class="' + cls + '" data-id="' + h.id + '" style="--h:' + h.color + '">' +
        '<div class="hb-row-behind">' +
            '<div class="hb-behind-side hb-behind-done">' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>' +
                (h.kind === 'quit' ? 'Ci sono cascato' : 'Fatto') +
            '</div>' +
            '<div class="hb-behind-side hb-behind-undo">Annulla' +
                '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/></svg>' +
            '</div>' +
        '</div>' +
        '<div class="hb-row-content">' +
            '<button type="button" class="hb-check" data-act="check" aria-label="Segna">' +
                '<svg class="hb-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>' +
                '<svg class="hb-icon-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
            '</button>' +
            '<div class="hb-row-emoji">' + esc(h.icon) + '</div>' +
            '<div class="hb-row-main">' +
                '<div class="hb-row-name">' + esc(h.name) + '</div>' +
                (metaBits.length ? '<div class="hb-row-meta">' + metaBits.join('<span>·</span>') + '</div>' : '') +
                bar +
            '</div>' + qty +
        '</div>' +
    '</div>';
}

function renderList() {
    var list = document.getElementById('hbList');
    var empty = document.getElementById('hbEmpty');
    var items = habitsForDay(S.selected, true);

    if (!S.habits.filter(function (h) { return !h.is_archived; }).length) {
        list.innerHTML = ''; empty.hidden = false;
        document.getElementById('hbListTitle').textContent = 'Le tue abitudini';
        return;
    }
    empty.hidden = true;

    var settimanali = weeklyHabits(true);
    var html = '';

    if (S.settings.group_by_day_part) {
        var order = ['morning','afternoon','evening','night','any'];
        order.forEach(function (p) {
            var group = items.filter(function (h) { return h.day_part === p; });
            if (!group.length) return;
            html += '<div class="hb-group-label">' + DAY_PART_ICON[p] + ' ' + DAY_PARTS[p] + '</div>';
            html += group.map(function (h) { return rowHTML(h, S.selected); }).join('');
        });
    } else {
        /* l'intestazione "Giornalieri" ha senso solo se ci sono anche le
           settimanali, altrimenti sarebbe un titolo per l'unico elenco */
        if (items.length && settimanali.length) html += '<div class="hb-group-label">Giornalieri</div>';
        html += items.map(function (h) { return rowHTML(h, S.selected); }).join('');
    }

    if (settimanali.length) {
        html += '<div class="hb-group-label hb-group-week">Settimanali</div>';
        html += settimanali.map(function (h) { return weeklyRowHTML(h, S.selected); }).join('');
    }

    list.innerHTML = html || '<div class="hb-none">Nessuna abitudine con questo filtro.</div>';
    var prog = dayProgress(S.selected);
    document.getElementById('hbListTitle').textContent = prog.done + ' di ' + prog.total + ' completate';
}

function renderAll() {
    renderDayStrip(); renderCarousel(); renderDayHero(); renderFilters(); renderList();
}

/* =====================================================================
   11. GESTURE: swipe destra = fatto, swipe sinistra = togli
   ===================================================================== */

/* la ripetibilità (più spunte di fila senza dover prima annullare) ha senso
   solo per le abitudini a quantità: per quelle "completamento" (fatte o da
   evitare) una direzione è valida solo se porta a un vero cambio di stato,
   altrimenti non c'è nulla da fare (o nulla da annullare) e il gesto non
   deve produrre alcun effetto. dir: +1 = fare/cascare, -1 = annullare. */
function swipeAllowed(h, date, dir) {
    var st = stateOf(h, date);
    var isQty = h.goal_type === 'quantity';
    if (dir > 0) {
        if (isQty) return true; // le quantità si possono sempre incrementare
        return h.kind === 'quit' ? st.status !== 'failed' : (st.status !== 'done');
    }
    if (isQty) return st.amount > 0; // niente da togliere se è già a zero
    return h.kind === 'quit' ? st.status === 'failed' : (st.status === 'done');
}
function swipeMode(h, dir) {
    var isQty = h.goal_type === 'quantity';
    if (isQty) return dir > 0 ? 'increment' : 'decrement';
    return h.kind === 'quit' ? 'fail' : 'toggle';
}

var swipeJustHappened = false;
(function bindSwipe() {
    var g = null;
    var THRESHOLD = 74;

    document.addEventListener('pointerdown', function (e) {
        var content = e.target.closest('.hb-row-content');
        if (!content || e.target.closest('.hb-check')) return;
        var row = content.closest('.hb-row');
        var id = +row.getAttribute('data-id');
        var h = habitById(id);
        g = {
            row:row, content:content, id:e.pointerId, x0:e.clientX, y0:e.clientY, dx:0, moved:false, locked:false,
            allowRight: h ? swipeAllowed(h, S.selected, 1)  : false,
            allowLeft:  h ? swipeAllowed(h, S.selected, -1) : false
        };
        content.style.transition = 'none';
    });

    document.addEventListener('pointermove', function (e) {
        if (!g || e.pointerId !== g.id) return;
        var dx = e.clientX - g.x0, dy = e.clientY - g.y0;
        if (!g.locked) {
            if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
            if (Math.abs(dy) > Math.abs(dx)) { g = null; return; }   // sta scorrendo la pagina
            g.locked = true;
            g.row.classList.add('is-swiping');
        }
        /* se in quella direzione non c'è nessuna azione valida (es. niente da
           annullare), la riga resta ferma: niente pannello "Annulla" fantasma */
        var dir = dx > 0 ? 1 : -1;
        var allowed = dir > 0 ? g.allowRight : g.allowLeft;
        if (!allowed) dx = 0;
        g.moved = true;
        /* resistenza oltre la soglia: si sente che il gesto è "arrivato" */
        var eased = Math.abs(dx) > THRESHOLD ? (dx > 0 ? 1 : -1) * (THRESHOLD + (Math.abs(dx) - THRESHOLD) * 0.34) : dx;
        g.dx = dx;
        g.content.style.transform = 'translateX(' + eased + 'px)';
        if (!g.buzzed && Math.abs(dx) >= THRESHOLD) { g.buzzed = true; buzz(11); }
        if (g.buzzed && Math.abs(dx) < THRESHOLD) g.buzzed = false;
        e.preventDefault();
    }, { passive: false });

    function end() {
        if (!g) return;
        var p = g; g = null;
        if (p.moved) { swipeJustHappened = true; setTimeout(function () { swipeJustHappened = false; }, 380); }
        p.content.style.transition = 'transform .34s var(--spring)';
        p.content.style.transform = '';
        //Il ritardo di 340 millisecondi serve ad aspettare che l'animazione della molla (var(--spring)) finisca di riportare la riga al centro. Se non lo mettessimo, lo sfondo sparirebbe di scatto mentre la riga si sta ancora muovendo.
        setTimeout(function () {
            p.row.classList.remove('is-swiping');
        }, 340);
        if (!p.moved) return;
        var id = +p.row.getAttribute('data-id');
        var h = habitById(id);
        if (!h) return;
        if (p.dx >= THRESHOLD && p.allowRight) {
            checkIn(id, S.selected, swipeMode(h, 1), 'swipe');
        } else if (p.dx <= -THRESHOLD && p.allowLeft) {
            checkIn(id, S.selected, swipeMode(h, -1), 'swipe');
        }
    }
    document.addEventListener('pointerup', end);
    document.addEventListener('pointercancel', end);
})();

/* tap sul cerchio = completa/azzera; tap sulla riga = dettaglio;
   pressione lunga = menù rapido */
(function bindTaps() {
    var pressTimer = null, longFired = false;

    document.getElementById('hbList').addEventListener('pointerdown', function (e) {
        var row = e.target.closest('.hb-row');
        if (!row) return;
        longFired = false;
        pressTimer = setTimeout(function () {
            longFired = true; buzz(24);
            openQuickMenu(+row.getAttribute('data-id'));
        }, 520);
    });
    ['pointerup','pointercancel','pointermove'].forEach(function (ev) {
        document.getElementById('hbList').addEventListener(ev, function () { clearTimeout(pressTimer); });
    });

    document.getElementById('hbList').addEventListener('click', function (e) {
        if (longFired) { longFired = false; return; }
        if (swipeJustHappened) return;
        var row = e.target.closest('.hb-row');
        if (!row) return;
        var id = +row.getAttribute('data-id');
        var h = habitById(id);
        if (!h) return;
        if (e.target.closest('[data-act="check"]')) {
            checkIn(id, S.selected, 'toggle', 'tap');
        } else {
            openDetail(id);
        }
    });

    document.getElementById('hbDayStrip').addEventListener('click', function (e) {
        var b = e.target.closest('.hb-day');
        if (!b) return;
        S.selected = b.getAttribute('data-date');
        buzz(8); renderAll();
    });
    document.getElementById('hbFilters').addEventListener('click', function (e) {
        var b = e.target.closest('.hb-filter');
        if (!b) return;
        S.filter = b.getAttribute('data-f');
        buzz(6); renderFilters(); renderList();
    });
})();

/* =====================================================================
   12. SHEET: apertura/chiusura
   ===================================================================== */
function openSheet(id) { document.getElementById(id).classList.add('show'); document.body.style.overflow = 'hidden'; }
function closeSheet(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow = ''; }
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-close]');
    if (btn) { closeSheet(btn.getAttribute('data-close')); return; }
    var ov = e.target.classList && e.target.classList.contains('hb-sheet-overlay') ? e.target : null;
    if (ov) closeSheet(ov.id);
});

/* menù rapido su pressione lunga */
function openQuickMenu(id) {
    var h = habitById(id);
    if (!h) return;
    var st = stateOf(h, S.selected);
    confirmBox(h.icon + ' ' + h.name, 'Cosa vuoi fare?', [
        { label:'Modifica', cls:'hb-btn-quiet', fn:function () { openEditor(h); } },
        { label:'Scrivi una nota', cls:'hb-btn-quiet', fn:function () { openNote(id, S.selected); } },
        { label:st.status === 'skipped' ? 'Non saltare' : 'Salta oggi', cls:'hb-btn-quiet', fn:function () { checkIn(id, S.selected, 'skip', 'detail'); } },
        { label:'Statistiche', cls:'hb-btn-ink', fn:function () { openDetail(id); } }
    ]);
}

/* dialogo generico con azioni personalizzate */
function confirmBox(title, msg, actions) {
    var ov = document.getElementById('hbConfirm');
    document.getElementById('hbConfirmTitle').textContent = title;
    document.getElementById('hbConfirmMsg').textContent = msg;
    var box = ov.querySelector('.hb-confirm-actions');
    box.innerHTML = '';
    (actions || []).forEach(function (a) {
        var b = document.createElement('button');
        b.className = 'hb-btn ' + (a.cls || 'hb-btn-quiet');
        b.textContent = a.label;
        b.onclick = function () { ov.classList.remove('show'); if (a.fn) a.fn(); };
        box.appendChild(b);
    });
    var cancel = document.createElement('button');
    cancel.className = 'hb-btn hb-btn-quiet'; cancel.textContent = 'Chiudi';
    cancel.onclick = function () { ov.classList.remove('show'); };
    box.appendChild(cancel);
    ov.classList.add('show');
    ov.onclick = function (e) { if (e.target === ov) ov.classList.remove('show'); };
}

/* =====================================================================
   13. EDITOR ABITUDINE
   ===================================================================== */
var draft = null;

function newDraft() {
    return {
        id:0, name:'', icon:'⭐️', color:(S.catalog.palette[0] ? S.catalog.palette[0].hex : '#7C5CFF'),
        motivation:'', preset_key:'', category_id:null, kind:'build', goal_type:'completion',
        target_amount:1, step_amount:1, unit:'', frequency_type:'daily', weekdays:'1111111',
        times_per_week:4, times_per_month:12, interval_days:2, day_part:'any',
        duration_type:'forever', duration_days:30, start_date:S.today, end_date:null,
        planner_minutes:''
    };
}
function draftFromHabit(h) {
    return JSON.parse(JSON.stringify({
        id:h.id, name:h.name, icon:h.icon, color:h.color, motivation:h.motivation || '',
        preset_key:h.preset_key || '', category_id:h.category_id, kind:h.kind, goal_type:h.goal_type,
        target_amount:h.target_amount, step_amount:h.step_amount, unit:h.unit,
        frequency_type:h.frequency_type, weekdays:h.weekdays, times_per_week:h.times_per_week,
        times_per_month:h.times_per_month, interval_days:h.interval_days, day_part:h.day_part,
        duration_type:h.duration_type, duration_days:h.duration_days || 30,
        start_date:h.start_date, end_date:h.end_date,
        planner_minutes:(h.planner_minutes === null || h.planner_minutes === undefined) ? '' : h.planner_minutes
    }));
}

function openEditor(habit) {
    draft = habit ? draftFromHabit(habit) : newDraft();
    document.getElementById('hbEditorTitle').textContent = habit ? 'Modifica abitudine' : 'Nuova abitudine';
    renderEditor();
    openSheet('hbSheetEditor');
}

function renderEditor() {
    var d = draft;
    var body = document.getElementById('hbEditorBody');
    var cats = S.categories;

    var freqBlock = '';
    if (d.frequency_type === 'daily') {
        freqBlock = '<div class="hb-hint">Scegli in quali giorni della settimana.</div><div class="hb-chips" style="margin-top:10px">' +
            DOW_SHORT.map(function (l, i) {
                return '<button type="button" class="hb-chip hb-chip-day' + (d.weekdays.charAt(i) === '1' ? ' on' : '') +
                    '" style="--cc:' + d.color + '" data-wd="' + i + '">' + l.slice(0, 3) + '</button>';
            }).join('') + '</div>';
    } else if (d.frequency_type === 'weekly') {
        freqBlock = stepperHTML('times_per_week', d.times_per_week, 'volte a settimana', 1, 7);
    } else if (d.frequency_type === 'monthly') {
        freqBlock = stepperHTML('times_per_month', d.times_per_month, 'volte al mese', 1, 31);
    } else {
        freqBlock = stepperHTML('interval_days', d.interval_days, 'ogni quanti giorni', 1, 365);
    }

    var isQuit = d.kind === 'quit';
    var goalBlock = d.goal_type === 'quantity'
        ? '<div class="hb-row-2" style="margin-top:12px">' +
              '<div><label class="hb-label" style="display:block;margin-bottom:8px;color:var(--text-2)">Obiettivo' + (isQuit ? ' (resta sotto)' : '') + '</label>' +
                  stepperHTML('target_amount', d.target_amount, '', 0.5, 100000, isQuit ? '<' : '') + '</div>' +
              '<div><label class="hb-label" style="display:block;margin-bottom:8px;color:var(--text-2)">Per spunta</label>' + stepperHTML('step_amount', d.step_amount, '', 0.5, 100000) + '</div>' +
          '</div>' +
          '<div class="hb-field" style="margin-top:14px"><label>Unità di misura</label>' +
          '<input class="hb-input" id="hbUnit" value="' + esc(d.unit) + '" placeholder="min, km, bicchieri..." list="hbUnits">' +
          '<datalist id="hbUnits">' + (S.catalog.units || []).map(function (u) { return '<option value="' + esc(u) + '">'; }).join('') + '</datalist>' +
          '<div class="hb-hint">' + (isQuit
              ? 'Ogni spunta aggiunge ' + fmtNum(d.step_amount) + ' ' + esc(d.unit || 'unità') + '. Il simbolo "<" indica che l\'obiettivo è restare sotto ' + fmtNum(d.target_amount) + ': raggiunta o superata quella soglia la giornata è segnata come "cascato".'
              : 'Ogni spunta aggiunge ' + fmtNum(d.step_amount) + ' ' + esc(d.unit || 'unità') + '. Servono ' + Math.ceil(d.target_amount / Math.max(0.5, d.step_amount)) + ' spunte per completarla.') + '</div></div>'
        : '<div class="hb-hint">' + (isQuit
              ? 'Una sola spunta e la giornata è segnata come "cascato": si annulla con un\'altra spunta.'
              : 'Una sola spunta e l\'abitudine del giorno è chiusa.') + '</div>';

    var durBlock = d.duration_type === 'days'
        ? stepperHTML('duration_days', d.duration_days, 'giorni totali', 1, 3650)
        : d.duration_type === 'end_date'
            ? '<input type="date" class="hb-input" id="hbEndDate" value="' + esc(d.end_date || '') + '" min="' + d.start_date + '">'
            : '<div class="hb-hint">L\'abitudine resta attiva finché non la archivi.</div>';

    body.innerHTML =
        /* nome + icona */
        '<div class="hb-field"><label>Nome e icona</label>' +
            '<div class="hb-name-row">' +
                '<button type="button" class="hb-icon-btn-big" id="hbPickIcon" style="--pick:' + d.color + '">' + esc(d.icon) + '</button>' +
                '<input class="hb-input" id="hbName" value="' + esc(d.name) + '" placeholder="Es. Meditazione" maxlength="120">' +
            '</div>' +
            (d.id === 0 ? '<button type="button" class="hb-btn hb-btn-quiet" id="hbFromPreset" style="margin-top:11px">✨ Parti da un\'abitudine pronta</button>' : '') +
        '</div>' +

        /* colore */
        '<div class="hb-field"><label>Colore</label><div class="hb-swatches">' +
            (S.catalog.palette || []).map(function (c) {
                return '<button type="button" class="hb-swatch' + (c.hex.toUpperCase() === d.color.toUpperCase() ? ' on' : '') + '" style="--sw:' + c.hex + '" data-color="' + c.hex + '" aria-label="' + esc(c.name) + '">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg></button>';
            }).join('') +
        '</div></div>' +

        /* categoria */
        '<div class="hb-field"><label>Categoria</label><div class="hb-chips">' +
            cats.map(function (c) {
                return '<button type="button" class="hb-chip' + (d.category_id === c.id ? ' on' : '') + '" style="--cc:' + c.color + '" data-cat="' + c.id + '">' + c.icon + ' ' + esc(c.name) + '</button>';
            }).join('') +
        '</div></div>' +

        /* da fare / da evitare */
        '<div class="hb-field"><label>Tipo di abitudine</label>' +
            '<div class="hb-seg ink"><button type="button" data-kind="build" class="' + (d.kind === 'build' ? 'on' : '') + '">✅ Da fare</button>' +
            '<button type="button" data-kind="quit" class="' + (d.kind === 'quit' ? 'on' : '') + '">🚫 Da evitare</button></div>' +
            '<div class="hb-hint">' + (d.kind === 'build'
                ? 'Parte non fatta: ogni spunta la avvicina all\'obiettivo.'
                : 'Parte già riuscita: la spunti solo quando ci caschi.') + '</div>' +
        '</div>' +

        /* obiettivo */
        '<div class="hb-field"><label>Obiettivo</label>' +
            '<div class="hb-seg"><button type="button" data-goal="completion" class="' + (d.goal_type === 'completion' ? 'on' : '') + '">Completata</button>' +
            '<button type="button" data-goal="quantity" class="' + (d.goal_type === 'quantity' ? 'on' : '') + '">Quantità</button></div>' +
            goalBlock +
        '</div>' +

        /* frequenza */
        '<div class="hb-field"><label>Frequenza</label>' +
            '<div class="hb-seg-wrap"><div class="hb-seg" style="flex:1">' +
                ['daily|Giornaliera','weekly|Settimanale','monthly|Mensile','interval|Intervallo'].map(function (o) {
                    var p = o.split('|');
                    return '<button type="button" data-freq="' + p[0] + '" class="' + (d.frequency_type === p[0] ? 'on' : '') + '">' + p[1] + '</button>';
                }).join('') +
            '</div></div>' + freqBlock +
        '</div>' +

        /* parte della giornata */
        '<div class="hb-field"><label>Parte della giornata</label><div class="hb-chips">' +
            Object.keys(DAY_PARTS).map(function (p) {
                return '<button type="button" class="hb-chip' + (d.day_part === p ? ' on' : '') + '" style="--cc:' + d.color + '" data-part="' + p + '">' + DAY_PART_ICON[p] + ' ' + DAY_PARTS[p] + '</button>';
            }).join('') +
        '</div></div>' +

        /* durata */
        '<div class="hb-field"><label>Per quanto</label>' +
            '<div class="hb-seg">' +
                ['forever|Per sempre','days|N giorni','end_date|Fino al'].map(function (o) {
                    var p = o.split('|');
                    return '<button type="button" data-dur="' + p[0] + '" class="' + (d.duration_type === p[0] ? 'on' : '') + '">' + p[1] + '</button>';
                }).join('') +
            '</div>' +
            '<div style="margin-top:12px">' + durBlock + '</div>' +
        '</div>' +

        /* durata stimata: la usa il planner per l'altezza del blocco */
        '<div class="hb-field"><label>Durata stimata (per il planner)</label>' +
            '<input class="hb-input" id="hbPlannerMin" type="time" step="300"' +
                ' value="' + esc(d.planner_minutes ? (String(Math.floor(d.planner_minutes/60)).padStart(2,"0") + ":" + String(d.planner_minutes%60).padStart(2,"0")) : "") + '">' +
            '<div class="hb-hint">Quando trascini questa abitudine nel planner, il blocco durerà questi minuti. ' +
                'Se lasci vuoto, userà la durata dello scatto che stai guardando.</div>' +
        '</div>' +

        /* motivazione */
        '<div class="hb-field"><label>Frase motivazionale</label>' +
            '<input class="hb-input" id="hbMoti" value="' + esc(d.motivation) + '" placeholder="Perché lo stai facendo?" maxlength="255"></div>' +

        '<div class="hb-field"><label>Inizio</label>' +
            '<input type="date" class="hb-input" id="hbStart" value="' + esc(d.start_date) + '"></div>';

    /* footer */
    var actions = document.getElementById('hbEditorActions');
    actions.innerHTML =
        (d.id ? '<button type="button" class="hb-btn hb-btn-quiet hb-btn-icon" id="hbDelHabit" aria-label="Elimina">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="#FF3B30" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>' : '') +
        (d.id ? '<button type="button" class="hb-btn hb-btn-quiet" id="hbArchHabit">Archivia</button>' : '') +
        '<button type="button" class="hb-btn hb-btn-primary" id="hbSaveHabit">' + (d.id ? 'Salva' : 'Crea abitudine') + '</button>';

    bindEditor();
}

function stepperHTML(field, value, suffix, min, max, prefix) {
    return '<div class="hb-stepper" data-field="' + field + '" data-min="' + min + '" data-max="' + max + '">' +
        '<button type="button" data-step="-1">−</button>' +
        (prefix ? '<span class="hb-stepper-prefix">' + esc(prefix) + '</span>' : '') +
        '<input type="text" inputmode="decimal" value="' + fmtNum(value) + '">' +
        '<button type="button" data-step="1">+</button>' +
        (suffix ? '<span style="font-size:12px;font-weight:700;color:var(--text-2);padding-right:6px;white-space:nowrap">' + esc(suffix) + '</span>' : '') +
    '</div>';
}

function bindEditor() {
    var body = document.getElementById('hbEditorBody');
    var d = draft;

    body.onclick = function (e) {
        var t = e.target;
        var b;
        if ((b = t.closest('[data-color]'))) { d.color = b.getAttribute('data-color'); renderEditor(); buzz(8); return; }
        if ((b = t.closest('[data-cat]')))   { var c = +b.getAttribute('data-cat'); d.category_id = (d.category_id === c ? null : c); renderEditor(); buzz(8); return; }
        if ((b = t.closest('[data-kind]')))  { d.kind = b.getAttribute('data-kind'); renderEditor(); buzz(8); return; }
        if ((b = t.closest('[data-goal]')))  { d.goal_type = b.getAttribute('data-goal'); renderEditor(); buzz(8); return; }
        if ((b = t.closest('[data-freq]')))  { d.frequency_type = b.getAttribute('data-freq'); renderEditor(); buzz(8); return; }
        if ((b = t.closest('[data-part]')))  { d.day_part = b.getAttribute('data-part'); renderEditor(); buzz(8); return; }
        if ((b = t.closest('[data-dur]')))   { d.duration_type = b.getAttribute('data-dur'); renderEditor(); buzz(8); return; }
        if ((b = t.closest('[data-wd]')))    {
            var i = +b.getAttribute('data-wd');
            var arr = d.weekdays.split('');
            arr[i] = arr[i] === '1' ? '0' : '1';
            if (arr.indexOf('1') === -1) arr[i] = '1';
            d.weekdays = arr.join(''); renderEditor(); buzz(8); return;
        }
        if ((b = t.closest('[data-step]')))  {
            var wrap = b.closest('.hb-stepper');
            var field = wrap.getAttribute('data-field');
            var min = parseFloat(wrap.getAttribute('data-min')), max = parseFloat(wrap.getAttribute('data-max'));
            var inc = (field === 'target_amount' || field === 'step_amount') ? (d[field] >= 10 ? 5 : 1) : 1;
            var v = (parseFloat(d[field]) || 0) + inc * (+b.getAttribute('data-step'));
            d[field] = Math.max(min, Math.min(max, v));
            renderEditor(); buzz(8); return;
        }
        if (t.closest('#hbPickIcon'))   { openIconPicker(); return; }
        if (t.closest('#hbFromPreset')) { openPresetPicker(); return; }
    };
    body.oninput = function (e) {
        var t = e.target;
        if (t.id === 'hbName') d.name = t.value;
        if (t.id === 'hbMoti') d.motivation = t.value;
        if (t.id === 'hbUnit') d.unit = t.value;
        if (t.id === 'hbStart') d.start_date = t.value;
        if (t.id === 'hbEndDate') d.end_date = t.value;
        if (t.id === 'hbPlannerMin') {
            /* il campo è un orario: lo convertiamo in minuti per il database */
            var pm = String(t.value || '').split(':');
            d.planner_minutes = t.value ? ((parseInt(pm[0],10)||0) * 60 + (parseInt(pm[1],10)||0)) : '';
        }
        if (t.closest('.hb-stepper')) {
            var wrap = t.closest('.hb-stepper');
            var v = parseFloat(String(t.value).replace(',', '.'));
            if (!isNaN(v)) d[wrap.getAttribute('data-field')] = v;
        }
    };

    var save = document.getElementById('hbSaveHabit');
    if (save) save.onclick = saveHabit;
    var del = document.getElementById('hbDelHabit');
    if (del) del.onclick = function () {
        confirmBox('Eliminare ' + d.name + '?', 'Sparisce anche tutto lo storico dei check-in. Non si può annullare.', [
            { label:'Elimina', cls:'hb-btn-danger', fn:function () {
                api('habit_delete', { id:d.id }).then(function () {
                    S.habits = S.habits.filter(function (h) { return h.id !== d.id; });
                    for (var k in S.logs) if (S.logs[k].habit_id === d.id) delete S.logs[k];
                    closeSheet('hbSheetEditor'); closeSheet('hbSheetDetail');
                    toast('🗑', 'Abitudine eliminata'); renderAll();
                });
            }}
        ]);
    };
    var arch = document.getElementById('hbArchHabit');
    if (arch) arch.onclick = function () {
        api('habit_archive', { id:d.id, archive:'1' }).then(function () {
            var h = habitById(d.id); if (h) h.is_archived = true;
            closeSheet('hbSheetEditor'); closeSheet('hbSheetDetail');
            toast('📦', 'Archiviata: la trovi nelle impostazioni'); renderAll();
        });
    };
}

function saveHabit() {
    var d = draft;
    if (!d.name.trim()) { toast('✏️', 'Manca il nome'); return; }
    var payload = {
        id:d.id, name:d.name, icon:d.icon, color:d.color, motivation:d.motivation,
        preset_key:d.preset_key, category_id:d.category_id || 0, kind:d.kind, goal_type:d.goal_type,
        target_amount:d.target_amount, step_amount:d.step_amount, unit:d.unit,
        frequency_type:d.frequency_type, weekdays:d.weekdays, times_per_week:d.times_per_week,
        times_per_month:d.times_per_month, interval_days:d.interval_days, day_part:d.day_part,
        duration_type:d.duration_type, duration_days:d.duration_days,
        start_date:d.start_date, end_date:d.end_date || '',
        planner_minutes:d.planner_minutes || 0
    };
    api('habit_save', payload).then(function (res) {
        if (!res.ok) { toast('⚠️', res.error || 'Salvataggio non riuscito'); return; }
        var existing = habitById(res.habit.id);
        if (existing) { for (var k in res.habit) existing[k] = res.habit[k]; }
        else S.habits.push(res.habit);
        closeSheet('hbSheetEditor');
        toast(res.habit.icon, d.id ? 'Modifiche salvate' : 'Abitudine creata');
        if (!d.id) { buzz(20); burst(window.innerWidth / 2, window.innerHeight * 0.6, res.habit.color, 34); }
        renderAll();
    });
}

/* --- selettore icone -------------------------------------------------- */
function openIconPicker() {
    document.getElementById('hbPickerTitle').textContent = 'Scegli un\'icona';
    var body = document.getElementById('hbPickerBody');
    var groups = S.catalog.icons || {};
    body.innerHTML = '<div class="hb-icon-groups">' + Object.keys(groups).map(function (g) {
        return '<div><div class="hb-card-title" style="margin-bottom:9px">' + esc(g) + '</div>' +
            '<div class="hb-icon-grid">' + groups[g].map(function (ic) {
                return '<button type="button" class="hb-icon-cell' + (ic === draft.icon ? ' on' : '') + '" data-icon="' + esc(ic) + '">' + ic + '</button>';
            }).join('') + '</div></div>';
    }).join('') + '</div>';
    body.onclick = function (e) {
        var b = e.target.closest('[data-icon]');
        if (!b) return;
        draft.icon = b.getAttribute('data-icon');
        buzz(10); closeSheet('hbSheetPicker'); renderEditor();
    };
    openSheet('hbSheetPicker');
}

/* --- selettore preset ------------------------------------------------- */
function openPresetPicker() {
    document.getElementById('hbPickerTitle').textContent = 'Abitudini pronte';
    var body = document.getElementById('hbPickerBody');
    var presets = S.catalog.presets || [];

    function draw(q) {
        var list = presets.filter(function (p) { return !q || p.name.toLowerCase().indexOf(q) >= 0; });
        return '<div class="hb-preset-grid">' + list.map(function (p) {
            var cat = S.categories.filter(function (c) { return c.slug === p.category; })[0];
            var sub = p.goal_type === 'quantity' ? fmtNum(p.target) + ' ' + p.unit : (cat ? cat.name : '');
            return '<button type="button" class="hb-preset" style="--pc:' + p.color + '" data-preset="' + esc(p.key) + '">' +
                '<span class="hb-preset-emoji">' + p.icon + '</span>' +
                '<span><span class="hb-preset-name">' + esc(p.name) + '</span>' +
                '<span class="hb-preset-sub">' + esc(sub) + '</span></span></button>';
        }).join('') + '</div>';
    }

    body.innerHTML = '<div class="hb-search"><input class="hb-input" id="hbPresetSearch" placeholder="Cerca fra ' + presets.length + ' abitudini..."></div><div id="hbPresetList">' + draw('') + '</div>';
    body.oninput = function (e) {
        if (e.target.id !== 'hbPresetSearch') return;
        document.getElementById('hbPresetList').innerHTML = draw(e.target.value.toLowerCase().trim());
    };
    body.onclick = function (e) {
        var b = e.target.closest('[data-preset]');
        if (!b) return;
        var key = b.getAttribute('data-preset');
        var p = presets.filter(function (x) { return x.key === key; })[0];
        if (!p) return;
        var cat = S.categories.filter(function (c) { return c.slug === p.category; })[0];
        draft.name = p.name; draft.icon = p.icon; draft.color = p.color; draft.preset_key = p.key;
        draft.motivation = p.motivation || ''; draft.category_id = cat ? cat.id : null;
        draft.kind = p.kind; draft.goal_type = p.goal_type;
        draft.target_amount = p.target; draft.step_amount = p.step; draft.unit = p.unit;
        draft.day_part = p.day_part;
        draft.frequency_type = p.frequency.type;
        if (p.frequency.weekdays) draft.weekdays = p.frequency.weekdays;
        if (p.frequency.times_per_week) draft.times_per_week = p.frequency.times_per_week;
        if (p.frequency.times_per_month) draft.times_per_month = p.frequency.times_per_month;
        if (p.frequency.interval_days) draft.interval_days = p.frequency.interval_days;
        buzz(12); closeSheet('hbSheetPicker'); renderEditor();
    };
    openSheet('hbSheetPicker');
}

/* =====================================================================
   14. GRAFICI
   ===================================================================== */
/* barre verticali: una per giorno, colore che ruota di settimana in settimana */
function barsChart(days, valueFn, opts) {
    opts = opts || {};
    if (days > 62) return weeklyBars(days, valueFn, opts);
    var html = '<div class="hb-bars">';
    for (var i = days - 1; i >= 0; i--) {
        var date = addDays(S.today, -i);
        var v = valueFn(date);
        var weekIdx = Math.floor((days - 1 - i) / 7) % WEEK_HUES.length;
        var color = opts.color || WEEK_HUES[weekIdx];
        var h = Math.max(3, Math.round(v * 108));
        var lbl = toDate(date).getDate();
        html += '<div class="hb-bars-col' + (date === S.today ? ' hl' : '') + '" style="--bc:' + color + '" title="' + date + '">' +
            '<i style="height:' + h + 'px;animation-delay:' + ((days - 1 - i) * 0.012) + 's"></i>' +
            (days <= 31 ? '<b>' + lbl + '</b>' : '') + '</div>';
    }
    return html + '</div>';
}

/* stessa idea ma una barra per settimana: per finestre lunghe */
function weeklyBars(days, valueFn, opts) {
    var weeks = Math.ceil(days / 7), html = '<div class="hb-bars">';
    for (var w = weeks - 1; w >= 0; w--) {
        var sum = 0, n = 0;
        for (var d = 0; d < 7; d++) {
            var date = addDays(S.today, -(w * 7 + d));
            if (date < addDays(S.today, -(days - 1))) break;
            sum += valueFn(date); n++;
        }
        var v = n ? sum / n : 0;
        var color = opts.color || WEEK_HUES[(weeks - 1 - w) % WEEK_HUES.length];
        html += '<div class="hb-bars-col' + (w === 0 ? ' hl' : '') + '" style="--bc:' + color + '">' +
            '<i style="height:' + Math.max(3, Math.round(v * 108)) + 'px;animation-delay:' + ((weeks - 1 - w) * 0.02) + 's"></i></div>';
    }
    return html + '</div><div class="hb-axis"><span>una barra = una settimana</span></div>';
}

/* linea con area: andamento del tasso di completamento */
function lineChart(points, color) {
    var w = 320, h = 140, pad = 6;
    if (!points.length) return '<div class="hb-none">Ancora pochi dati.</div>';
    var max = 1, step = points.length > 1 ? (w - pad * 2) / (points.length - 1) : 0;
    var coords = points.map(function (p, i) {
        return [pad + i * step, h - pad - (p / max) * (h - pad * 2)];
    });
    var dPath = coords.map(function (c, i) { return (i ? 'L' : 'M') + c[0].toFixed(1) + ' ' + c[1].toFixed(1); }).join(' ');
    var area = dPath + ' L' + coords[coords.length - 1][0].toFixed(1) + ' ' + (h - pad) + ' L' + pad + ' ' + (h - pad) + ' Z';
    var grid = [0.25, 0.5, 0.75].map(function (g) {
        var y = h - pad - g * (h - pad * 2);
        return '<line class="hb-line-grid" x1="' + pad + '" y1="' + y + '" x2="' + (w - pad) + '" y2="' + y + '"/>';
    }).join('');
    var last = coords[coords.length - 1];
    return '<svg class="hb-line" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="--lc:' + color + '">' +
        '<defs><linearGradient id="hbGrad" x1="0" y1="0" x2="0" y2="1">' +
        '<stop offset="0%" stop-color="' + color + '" stop-opacity=".35"/>' +
        '<stop offset="100%" stop-color="' + color + '" stop-opacity="0"/></linearGradient></defs>' +
        grid +
        '<path class="hb-line-area" d="' + area + '"/>' +
        '<path class="hb-line-path" vector-effect="non-scaling-stroke" d="' + dPath + '" style="--len:' + (points.length * 40) + '"/>' +
        '<circle class="hb-line-dot" cx="' + last[0].toFixed(1) + '" cy="' + last[1].toFixed(1) + '" r="4.5"/>' +
    '</svg>';
}

/* barre orizzontali per categoria, completate vs previste */
function categoryBars(days) {
    var from = addDays(S.today, -(days - 1));
    var byCat = {};
    S.habits.forEach(function (h) {
        if (h.is_archived) return;
        var cat = S.categories.filter(function (c) { return c.id === h.category_id; })[0];
        var key = cat ? cat.id : 0;
        if (!byCat[key]) byCat[key] = { name: cat ? cat.name : 'Senza categoria', color: cat ? cat.color : '#8E8E93', ok:0, due:0 };
        var w = windowStats(h, from, S.today);
        byCat[key].ok += w.ok; byCat[key].due += w.due;
    });
    var rows = Object.keys(byCat).map(function (k) { return byCat[k]; })
        .filter(function (r) { return r.due > 0; })
        .sort(function (a, b) { return b.ok - a.ok; });
    if (!rows.length) return '<div class="hb-none">Nessun dato ancora.</div>';
    var max = Math.max.apply(null, rows.map(function (r) { return r.due; }));
    return '<div class="hb-hbars">' + rows.map(function (r) {
        return '<div class="hb-hbar-row" style="--bc:' + r.color + '">' +
            '<span class="hb-hbar-name">' + esc(r.name) + '</span>' +
            '<span class="hb-hbar-track"><span class="hb-hbar-fill" style="width:' + Math.round(r.ok / max * 100) + '%"></span></span>' +
            '<span class="hb-hbar-val">' + r.ok + '/' + r.due + '</span>' +
        '</div>';
    }).join('') + '</div>';
}

/* heatmap annuale in stile "un quadratino al giorno" */
function heatmap(valueFn, color) {
    var end = S.today;
    var start = addDays(end, -363);
    while (dowIdx(start) !== 0) start = addDays(start, -1);      // parte di lunedì
    var cells = '', months = '', cursor = start, lastMonth = -1, col = 0;
    while (cursor <= addDays(end, 6)) {
        var m = toDate(cursor).getMonth();
        if (dowIdx(cursor) === 0) {
            months += '<span style="grid-column:' + (col + 1) + '">' + (m !== lastMonth ? MONTHS_S[m] : '') + '</span>';
            lastMonth = m; col++;
        }
        var v = cursor > end ? -1 : valueFn(cursor);
        var lvl = v < 0 ? 0 : v === 0 ? 0 : v < 0.34 ? 1 : v < 0.67 ? 2 : v < 1 ? 3 : 4;
        cells += '<div class="hb-heat-cell' + (cursor > end ? ' future' : '') + '" data-l="' + lvl + '" data-d="' + cursor + '" title="' + cursor + '"></div>';
        cursor = addDays(cursor, 1);
    }
    return '<div class="hb-heat-scroll" style="--hc:' + color + '">' +
        '<div class="hb-heat-months" style="grid-auto-columns:12px">' + months + '</div>' +
        '<div class="hb-heat">' + cells + '</div></div>' +
        '<div class="hb-heat-legend">meno' +
            [0,1,2,3,4].map(function (l) { return '<i class="hb-heat-cell" data-l="' + l + '" style="--hc:' + color + '"></i>'; }).join('') +
        'più</div>';
}

/* calendario mensile di una singola abitudine */
function monthCalendar(h, ym) {
    var y = +ym.slice(0, 4), m = +ym.slice(5, 7) - 1;
    var first = new Date(y, m, 1), lastDay = new Date(y, m + 1, 0).getDate();
    var lead = (first.getDay() + 6) % 7;
    var html = '<div class="hb-cal-dows">' + DOW_MINI.map(function (d) { return '<span class="hb-cal-dow">' + d + '</span>'; }).join('') + '</div><div class="hb-cal">';
    for (var i = 0; i < lead; i++) html += '<div class="hb-cal-cell pad"></div>';
    for (var d = 1; d <= lastDay; d++) {
        var date = y + '-' + pad(m + 1) + '-' + pad(d);
        var cls = 'hb-cal-cell';
        if (isDue(h, date)) cls += ' due';
        var st = stateOf(h, date);
        if (st.status === 'done' || st.status === 'clean') cls += ' done';
        else if (st.status === 'partial') cls += ' partial';
        else if (st.status === 'skipped') cls += ' skipped';
        else if (st.status === 'failed') cls += ' failed';
        if (date === S.today) cls += ' today';
        if (isFuture(date)) cls += ' future';
        html += '<div class="' + cls + '" data-cal="' + date + '">' + d + '</div>';
    }
    return html + '</div>';
}

/* distribuzione per giorno della settimana */
function weekdayBars(h) {
    var counts = [0,0,0,0,0,0,0], due = [0,0,0,0,0,0,0];
    var cursor = h ? h.start_date : addDays(S.today, -180);
    if (cursor < addDays(S.today, -365)) cursor = addDays(S.today, -365);
    var guard = 0;
    while (cursor <= S.today && guard++ < 400) {
        var i = dowIdx(cursor);
        var list = h ? [h] : S.habits.filter(function (x) { return !x.is_archived; });
        list.forEach(function (hh) {
            if (!isDue(hh, cursor)) return;
            due[i]++;
            if (isSuccess(hh, cursor)) counts[i]++;
        });
        cursor = addDays(cursor, 1);
    }
    var max = Math.max.apply(null, counts.map(function (c, i) { return due[i] ? c / due[i] : 0; }).concat([0.01]));
    return '<div class="hb-bars" style="height:104px">' + counts.map(function (c, i) {
        var r = due[i] ? c / due[i] : 0;
        var color = WEEK_HUES[i % WEEK_HUES.length];
        return '<div class="hb-bars-col" style="--bc:' + color + '">' +
            '<i style="height:' + Math.max(4, Math.round(r / max * 78)) + 'px;animation-delay:' + (i * 0.05) + 's"></i>' +
            '<b>' + DOW_MINI[i] + '</b></div>';
    }).join('') + '</div>';
}

/* frequenza per mese: quante volte completata in ciascun mese dell'anno */
function monthlyBars(h) {
    var year = toDate(S.today).getFullYear();
    var counts = new Array(12).fill(0);
    for (var k in S.logs) {
        var l = S.logs[k];
        if (h && l.habit_id !== h.id) continue;
        if (l.status !== 'done') continue;
        if (+l.date.slice(0, 4) !== year) continue;
        counts[+l.date.slice(5, 7) - 1]++;
    }
    var max = Math.max.apply(null, counts.concat([1]));
    return '<div class="hb-bars" style="height:118px">' + counts.map(function (c, i) {
        return '<div class="hb-bars-col' + (i === toDate(S.today).getMonth() ? ' hl' : '') + '" style="--bc:' + WEEK_HUES[i % WEEK_HUES.length] + '">' +
            '<i style="height:' + Math.max(3, Math.round(c / max * 88)) + 'px;animation-delay:' + (i * 0.04) + 's"></i>' +
            '<b>' + MONTHS_S[i].slice(0, 1) + '</b></div>';
    }).join('') + '</div>' +
    '<div class="hb-axis"><span>' + year + '</span><span>max ' + max + '</span></div>';
}

/* radar dell'equilibrio fra aree della vita */
function radarChart(days) {
    var from = addDays(S.today, -(days - 1));
    var data = [];
    S.categories.forEach(function (c) {
        var ok = 0, due = 0;
        S.habits.forEach(function (h) {
            if (h.is_archived || h.category_id !== c.id) return;
            var w = windowStats(h, from, S.today);
            ok += w.ok; due += w.due;
        });
        if (due > 0) data.push({ name:c.name, v:ok / due });
    });
    if (data.length < 3) return '<div class="hb-none">Servono abitudini in almeno 3 categorie.</div>';
    var cx = 150, cy = 130, R = 92, n = data.length;
    function pt(i, r) {
        var a = (Math.PI * 2 * i) / n - Math.PI / 2;
        return [cx + Math.cos(a) * r, cy + Math.sin(a) * r];
    }
    var webs = [0.25, 0.5, 0.75, 1].map(function (g) {
        var p = data.map(function (_, i) { return pt(i, R * g).map(function (x) { return x.toFixed(1); }).join(','); }).join(' ');
        return '<polygon class="hb-radar-web" points="' + p + '"/>';
    }).join('');
    var shape = data.map(function (d, i) { return pt(i, Math.max(6, R * d.v)).map(function (x) { return x.toFixed(1); }).join(','); }).join(' ');
    var labels = data.map(function (d, i) {
        var p = pt(i, R + 17);
        var anchor = p[0] < cx - 8 ? 'end' : p[0] > cx + 8 ? 'start' : 'middle';
        return '<text class="hb-radar-label" x="' + p[0].toFixed(1) + '" y="' + (p[1] + 3).toFixed(1) + '" text-anchor="' + anchor + '">' + esc(d.name.slice(0, 11)) + '</text>';
    }).join('');
    return '<svg class="hb-radar" viewBox="0 0 300 262">' + webs + '<polygon class="hb-radar-shape" points="' + shape + '"/>' + labels + '</svg>';
}

/* =====================================================================
   15. DETTAGLIO ABITUDINE
   ===================================================================== */
function openDetail(id) {
    var h = habitById(id);
    if (!h) return;
    S.detailHabit = id;
    S.detailMonth = S.selected.slice(0, 7);
    renderDetail();
    openSheet('hbSheetDetail');
}

function freqLabel(h) {
    if (h.frequency_type === 'daily') {
        var n = h.weekdays.split('').filter(function (c) { return c === '1'; }).length;
        return n === 7 ? 'Tutti i giorni' : n + ' giorni a settimana';
    }
    if (h.frequency_type === 'weekly')  return h.times_per_week + ' volte a settimana';
    if (h.frequency_type === 'monthly') return h.times_per_month + ' volte al mese';
    return 'Ogni ' + h.interval_days + ' giorni';
}

function renderDetail() {
    var h = habitById(S.detailHabit);
    if (!h) return;
    var body = document.getElementById('hbDetailBody');
    document.getElementById('hbDetailTitle').textContent = h.name;

    var streak = streakOf(h), best = bestStreakOf(h), total = totalCheckins(h);
    var w30 = windowStats(h, addDays(S.today, -29), S.today);
    var wAll = windowStats(h, h.start_date, S.today);
    var monthStart = S.detailMonth + '-01';
    var monthEnd = S.detailMonth + '-' + pad(new Date(+S.detailMonth.slice(0,4), +S.detailMonth.slice(5,7), 0).getDate());
    var wMonth = windowStats(h, monthStart, monthEnd > S.today ? S.today : monthEnd);

    var notes = [];
    for (var k in S.logs) {
        var l = S.logs[k];
        if (l.habit_id === h.id && l.note) notes.push(l);
    }
    notes.sort(function (a, b) { return a.date < b.date ? 1 : -1; });

    body.innerHTML =
        '<div class="hb-detail-hero" style="--dc:' + h.color + '">' +
            '<div class="hb-detail-emoji">' + esc(h.icon) + '</div>' +
            '<div class="hb-detail-name">' + esc(h.name) + '</div>' +
            (h.motivation ? '<div class="hb-detail-moti">' + esc(h.motivation) + '</div>' : '') +
            '<div class="hb-detail-tags">' +
                '<span class="hb-detail-tag">' + esc(freqLabel(h)) + '</span>' +
                '<span class="hb-detail-tag">' + DAY_PART_ICON[h.day_part] + ' ' + DAY_PARTS[h.day_part] + '</span>' +
                '<span class="hb-detail-tag">' + (h.kind === 'quit'
                    ? (h.goal_type === 'quantity' ? '🚫 &lt; ' + fmtNum(h.target_amount) + ' ' + esc(h.unit) : '🚫 Da evitare')
                    : (h.goal_type === 'quantity' ? '🎯 ' + fmtNum(h.target_amount) + ' ' + esc(h.unit) : '✅ Da fare')) + '</span>' +
                (h.end_date ? '<span class="hb-detail-tag">Fino al ' + esc(h.end_date) + '</span>' : '') +
            '</div>' +
        '</div>' +

        '<div class="hb-detail-actions">' +
            '<button type="button" class="hb-btn hb-btn-quiet" id="hbDetEdit">Modifica</button>' +
            '<button type="button" class="hb-btn hb-btn-ink" id="hbDetToggle">' +
                (isSuccess(h, S.selected) ? 'Annulla oggi' : (h.kind === 'quit' ? 'Ci sono cascato' : 'Segna fatto')) + '</button>' +
        '</div>' +

        '<div class="hb-kpis">' +
            kpi(streak, 'giorni', 'Serie attuale', '#FF6B35') +
            kpi(best, 'giorni', 'Record', '#FFCC00') +
            kpi(total, '', 'Check-in totali', '#00E0A0') +
            kpi(Math.round(wAll.rate * 100), '%', 'Completamento', h.color) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-cal-head">' +
                '<button type="button" class="hb-cal-nav" data-mon="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M15 6l-6 6 6 6"/></svg></button>' +
                '<div class="hb-cal-month">' + MONTHS[+S.detailMonth.slice(5, 7) - 1] + ' ' + S.detailMonth.slice(0, 4) + '</div>' +
                '<button type="button" class="hb-cal-nav" data-mon="1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M9 6l6 6-6 6"/></svg></button>' +
            '</div>' +
            '<div style="--cc:' + h.color + '">' + monthCalendar(h, S.detailMonth) + '</div>' +
            '<div class="hb-heat-legend" style="justify-content:space-between;margin-top:14px">' +
                '<span>' + wMonth.ok + ' su ' + wMonth.due + ' nel mese</span>' +
                '<span>' + Math.round(wMonth.rate * 100) + '%</span>' +
            '</div>' +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Heatmap annuale</span><span class="hb-card-note">365 giorni</span></div>' +
            heatmap(function (d) { return isDue(h, d) ? (isSuccess(h, d) ? 1 : stateOf(h, d).pct) : 0; }, h.color) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Ultimi 30 giorni</span><span class="hb-card-note">' + Math.round(w30.rate * 100) + '% completato</span></div>' +
            barsChart(30, function (d) { return isDue(h, d) ? stateOf(h, d).pct : 0; }, { color:h.color }) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Giorni forti e deboli</span></div>' +
            weekdayBars(h) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Frequenza per mese</span></div>' +
            monthlyBars(h) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Note dei check-in</span>' +
                '<button type="button" class="hb-card-act" id="hbDetNote">Scrivi</button></div>' +
            (notes.length ? '<div class="hb-notes">' + notes.slice(0, 12).map(function (n) {
                return '<div class="hb-note"><div class="hb-note-date">' + esc(labelDate(n.date)) + '</div><div class="hb-note-text">' + esc(n.note) + '</div></div>';
            }).join('') + '</div>' : '<div class="hb-none">Nessuna nota per ora. Tieni premuto un giorno del calendario per scriverne una.</div>') +
        '</div>';

    body.onclick = function (e) {
        var b;
        if ((b = e.target.closest('[data-mon]'))) {
            var delta = +b.getAttribute('data-mon');
            var y = +S.detailMonth.slice(0, 4), m = +S.detailMonth.slice(5, 7) - 1 + delta;
            var nd = new Date(y, m, 1);
            S.detailMonth = nd.getFullYear() + '-' + pad(nd.getMonth() + 1);
            buzz(6); renderDetail(); return;
        }
        if ((b = e.target.closest('[data-cal]'))) {
            if (calLongFired) { calLongFired = false; return; }   // la pressione lunga ha già aperto la nota
            var date = b.getAttribute('data-cal');
            if (isFuture(date)) return;
            checkIn(h.id, date, 'toggle', 'detail').then(function () { renderDetail(); });
            return;
        }
        if (e.target.closest('#hbDetEdit')) { openEditor(h); return; }
        if (e.target.closest('#hbDetNote')) { openNote(h.id, S.selected); return; }
        if (e.target.closest('#hbDetToggle')) {
            checkIn(h.id, S.selected, 'toggle', 'detail').then(function () { renderDetail(); });
            return;
        }
    };
}
function kpi(value, suffix, label, color) {
    return '<div class="hb-kpi" style="--kc:' + color + '">' +
        '<div class="hb-kpi-v">' + fmtNum(value) + (suffix ? '<small>' + esc(suffix) + '</small>' : '') + '</div>' +
        '<div class="hb-kpi-l">' + esc(label) + '</div></div>';
}

/* =====================================================================
   16. STATISTICHE GENERALI
   ===================================================================== */
function renderStats() {
    var body = document.getElementById('hbStatsBody');
    var range = S.settings.chart_range_days || 30;
    var gStreak = globalStreak();
    var prog = dayProgress(S.today);
    var monthStart = S.today.slice(0, 8) + '01';
    var pts = [];
    for (var i = range - 1; i >= 0; i--) pts.push(dayProgress(addDays(S.today, -i)).pct);

    var unlocked = S.achievements.filter(function (a) { return a.unlocked; }).length;

    body.innerHTML =
        '<div class="hb-kpis">' +
            kpi(gStreak, 'gg', 'Serie perfetta', '#FF6B35') +
            kpi(prog.done, '/' + prog.total, 'Oggi', '#7C5CFF') +
            kpi(countDone(monthStart, S.today), '', 'Questo mese', '#0A84FF') +
            kpi(countDone('0000-01-01', S.today), '', 'Da sempre', '#FFCC00') +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Completamento giornaliero</span>' +
            '<span class="hb-card-note">' + range + ' giorni</span></div>' +
            barsChart(range, function (d) { return dayProgress(d).pct; }) +
            '<div class="hb-axis"><span>' + labelDate(addDays(S.today, -(range - 1))) + '</span><span>Oggi</span></div>' +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Andamento</span>' +
            '<span class="hb-card-note">' + Math.round(rangeRate(range) * 100) + '% medio</span></div>' +
            lineChart(pts, 'var(--accent)') +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Per categoria</span><span class="hb-card-note">completate / previste</span></div>' +
            categoryBars(range) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Equilibrio delle aree</span></div>' +
            radarChart(range) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Settimana per abitudine</span></div>' +
            weekGridHTML() +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Heatmap annuale</span><span class="hb-card-note">tutte le abitudini</span></div>' +
            heatmap(function (d) { return dayProgress(d).pct; }, 'var(--accent)') +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Giorni forti e deboli</span></div>' +
            weekdayBars(null) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Frequenza per mese</span></div>' +
            monthlyBars(null) +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Quando ti muovi</span><span class="hb-card-note">ultimi 90 giorni</span></div>' +
            '<div id="hbHours"><div class="hb-none">Carico…</div></div>' +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Traguardi</span><span class="hb-card-note">' + unlocked + '/' + S.achievements.length + '</span></div>' +
            '<div class="hb-badges">' + S.achievements.map(function (a) {
                return '<div class="hb-badge' + (a.unlocked ? '' : ' locked') + '" data-tier="' + a.tier + '">' +
                    '<div class="hb-badge-icon">' + a.icon + '</div>' +
                    '<div class="hb-badge-name">' + esc(a.name) + '</div>' +
                    '<div class="hb-badge-tier">' + esc(a.tier) + '</div></div>';
            }).join('') + '</div>' +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Classifica abitudini</span></div>' +
            rankingHTML(range) +
        '</div>';

    /* il grafico degli orari arriva dal server */
    api('hour_distribution', {}).then(function (res) {
        if (!res.ok) return;
        var max = Math.max.apply(null, res.hours.concat([1]));
        document.getElementById('hbHours').innerHTML =
            '<div class="hb-bars" style="height:100px">' + res.hours.map(function (c, i) {
                return '<div class="hb-bars-col" style="--bc:' + WEEK_HUES[Math.floor(i / 5) % WEEK_HUES.length] + '">' +
                    '<i style="height:' + Math.max(3, Math.round(c / max * 80)) + 'px"></i>' +
                    (i % 6 === 0 ? '<b>' + i + '</b>' : '<b></b>') + '</div>';
            }).join('') + '</div>';
    });
}

function weekGridHTML() {
    var start = S.today;
    while (dowIdx(start) !== 0) start = addDays(start, -1);
    var habits = S.habits.filter(function (h) { return !h.is_archived; });
    if (!habits.length) return '<div class="hb-none">Nessuna abitudine attiva.</div>';
    var html = '<div class="hb-week-dows"><span></span>' + DOW_MINI.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div><div class="hb-week-grid">';
    habits.forEach(function (h) {
        html += '<div class="hb-week-row" style="--wc:' + h.color + '">' +
            '<span class="hb-week-name">' + esc(h.icon) + ' ' + esc(h.name) + '</span>';
        for (var i = 0; i < 7; i++) {
            var date = addDays(start, i);
            var cls = 'hb-week-cell';
            if (!isDue(h, date) || isFuture(date)) cls += ' na';
            else {
                var st = stateOf(h, date);
                if (st.status === 'done' || st.status === 'clean') cls += ' done';
                else if (st.status === 'partial') cls += ' partial';
                else if (st.status === 'skipped') cls += ' skipped';
                else if (st.status === 'failed') cls += ' failed';
            }
            html += '<span class="' + cls + '"></span>';
        }
        html += '</div>';
    });
    return html + '</div>';
}

function rankingHTML(days) {
    var from = addDays(S.today, -(days - 1));
    var rows = S.habits.filter(function (h) { return !h.is_archived; }).map(function (h) {
        var w = windowStats(h, from, S.today);
        return { h:h, rate:w.rate, ok:w.ok, due:w.due, streak:streakOf(h) };
    }).filter(function (r) { return r.due > 0; })
      .sort(function (a, b) { return b.rate - a.rate; });
    if (!rows.length) return '<div class="hb-none">Nessun dato ancora.</div>';
    return '<div class="hb-hbars">' + rows.map(function (r) {
        return '<div class="hb-hbar-row" style="--bc:' + r.h.color + '">' +
            '<span class="hb-hbar-name">' + esc(r.h.icon) + ' ' + esc(r.h.name) + '</span>' +
            '<span class="hb-hbar-track"><span class="hb-hbar-fill" style="width:' + Math.round(r.rate * 100) + '%"></span></span>' +
            '<span class="hb-hbar-val">' + Math.round(r.rate * 100) + '%</span>' +
        '</div>';
    }).join('') + '</div>';
}

/* =====================================================================
   17. VISTA TABELLA — spunta veloce con grafici che si aggiornano sotto
   ===================================================================== */
function renderTable() {
    var body = document.getElementById('hbTableBody');
    var days = S.tableDays;
    var habits = S.habits.filter(function (h) { return !h.is_archived; });
    var dates = [];
    for (var i = days - 1; i >= 0; i--) dates.push(addDays(S.today, -i));

    if (!habits.length) { body.innerHTML = '<div class="hb-none">Nessuna abitudine attiva.</div>'; return; }

    var head = '<tr><th class="hb-th-name">Abitudine</th>' + dates.map(function (d) {
        return '<th class="' + (d === S.today ? 'today' : '') + '">' + DOW_MINI[dowIdx(d)] + '<br>' + toDate(d).getDate() + '</th>';
    }).join('') + '<th>%</th></tr>';

    var rows = habits.map(function (h) {
        var ok = 0, due = 0;
        var tds = dates.map(function (d) {
            var cls = 'hb-cellbox';
            if (!isDue(h, d) || isFuture(d)) cls += ' na';
            else {
                due++;
                var st = stateOf(h, d);
                if (st.status === 'done' || st.status === 'clean') { cls += ' done'; ok++; }
                else if (st.status === 'partial') { cls += ' partial'; if (isSuccess(h, d)) ok++; }
                else if (st.status === 'skipped') cls += ' skipped';
                else if (st.status === 'failed') cls += ' failed';
            }
            return '<td><button type="button" class="' + cls + '" data-h="' + h.id + '" data-d="' + d + '">' +
                '<svg class="hb-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg>' +
                '<svg class="hb-icon-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
                '</button></td>';
        }).join('');
        return '<tr style="--tc:' + h.color + '"><td class="hb-td-name">' + esc(h.icon) + ' ' + esc(h.name) + '</td>' + tds +
            '<td><span class="hb-rowpct">' + (due ? Math.round(ok / due * 100) : 0) + '%</span></td></tr>';
    }).join('');

    var foot = '<tr><td class="hb-td-name">Giornata</td>' + dates.map(function (d) {
        var p = dayProgress(d);
        return '<td>' + (p.total ? Math.round(p.pct * 100) : '–') + '</td>';
    }).join('') + '<td></td></tr>';

    body.innerHTML =
        '<div class="hb-seg" style="margin-bottom:14px">' +
            [7, 14, 30].map(function (n) {
                return '<button type="button" data-td="' + n + '" class="' + (days === n ? 'on' : '') + '">' + n + ' giorni</button>';
            }).join('') +
        '</div>' +
        '<div class="hb-table-scroll"><table class="hb-table"><thead>' + head + '</thead><tbody>' + rows + '</tbody><tfoot>' + foot + '</tfoot></table></div>' +
        '<div class="hb-card" style="margin-top:16px">' +
            '<div class="hb-card-head"><span class="hb-card-title">Completamento giornaliero</span><span class="hb-card-note" id="hbTblRate">' + Math.round(rangeRate(days) * 100) + '% medio</span></div>' +
            '<div id="hbTblBars">' + barsChart(days, function (d) { return dayProgress(d).pct; }) + '</div>' +
        '</div>' +
        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Classifica</span></div>' +
            '<div id="hbTblRank">' + rankingHTML(days) + '</div>' +
        '</div>';

    body.onclick = function (e) {
        var b;
        if ((b = e.target.closest('[data-td]'))) { S.tableDays = +b.getAttribute('data-td'); buzz(8); renderTable(); return; }
        if ((b = e.target.closest('[data-h]'))) {
            var id = +b.getAttribute('data-h'), date = b.getAttribute('data-d');
            var h = habitById(id);
            if (!h) return;
            checkIn(id, date, 'toggle', 'table').then(function () {
                renderTable();   // tabella e grafici sotto si aggiornano insieme
            });
        }
    };
}

/* =====================================================================
   18. IMPOSTAZIONI
   ===================================================================== */
function renderSettings() {
    var body = document.getElementById('hbSettingsBody');
    var s = S.settings;
    var archived = S.habits.filter(function (h) { return h.is_archived; });

    function toggle(key, label, desc) {
        return '<div class="hb-toggle-row"><div><div class="hb-toggle-label">' + esc(label) + '</div>' +
            (desc ? '<div class="hb-toggle-desc">' + esc(desc) + '</div>' : '') + '</div>' +
            '<button type="button" class="hb-switch' + (s[key] ? ' on' : '') + '" data-toggle="' + key + '"></button></div>';
    }

    body.innerHTML =
        '<div class="hb-field"><label>Colore della pagina</label><div class="hb-swatches">' +
            (S.catalog.palette || []).map(function (c) {
                return '<button type="button" class="hb-swatch' + (c.hex.toUpperCase() === (s.accent_color || '').toUpperCase() ? ' on' : '') +
                    '" style="--sw:' + c.hex + '" data-accent="' + c.hex + '">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"><path d="M20 6L9 17l-5-5"/></svg></button>';
            }).join('') +
        '</div></div>' +

        '<div class="hb-field"><label>Giorni nella barra in alto</label>' +
            stepperHTML('visible_days', s.visible_days, 'giorni', 5, 31) + '</div>' +

        '<div class="hb-field"><label>Finestra dei grafici</label>' +
            '<div class="hb-seg">' + [7, 30, 90, 365].map(function (n) {
                return '<button type="button" data-range="' + n + '" class="' + (s.chart_range_days === n ? 'on' : '') + '">' + n + 'g</button>';
            }).join('') + '</div></div>' +

        '<div class="hb-card">' +
            '<div class="hb-toggle-row"><div>' +
                '<div class="hb-toggle-label">Ordine dell\'elenco</div>' +
                '<div class="hb-toggle-desc">Decidi tu quali abitudini stanno in cima</div>' +
            '</div>' +
            '<button type="button" class="hb-btn hb-btn-quiet" style="flex:0 0 auto;padding:9px 15px;font-size:13px" id="hbOpenReorder">Riordina</button></div>' +
        '</div>' +

        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Dati</span></div>' +
            '<div class="hb-toggle-row"><div>' +
                '<div class="hb-toggle-label">Importa da file</div>' +
                '<div class="hb-toggle-desc">Da un file Excel o CSV, anche di un\'altra app</div>' +
            '</div>' +
            '<button type="button" class="hb-btn hb-btn-quiet" style="flex:0 0 auto;padding:9px 15px;font-size:13px" id="hbOpenImport">Importa</button></div>' +
            '<div class="hb-toggle-row"><div>' +
                '<div class="hb-toggle-label">Esporta tutto</div>' +
                '<div class="hb-toggle-desc">Un file .xlsx con lo storico dei check-in</div>' +
            '</div>' +
            '<button type="button" class="hb-btn hb-btn-quiet" style="flex:0 0 auto;padding:9px 15px;font-size:13px" id="hbOpenExport">Esporta</button></div>' +
        '</div>' +

        '<div class="hb-card">' +
            toggle('group_by_day_part', 'Raggruppa per momento', 'Mattina, pomeriggio, sera, notte') +
            toggle('carousel_autoplay', 'Statistiche a scorrimento', 'Le schede in alto scorrono da sole') +
            toggle('confetti_enabled', 'Coriandoli', 'Festeggia ogni completamento') +
            toggle('haptics_enabled', 'Vibrazione', 'Piccolo riscontro tattile') +
            toggle('sound_enabled', 'Suono', 'Un tocco sonoro alla spunta') +
        '</div>' +

        (archived.length ?
        '<div class="hb-card">' +
            '<div class="hb-card-head"><span class="hb-card-title">Archiviate</span><span class="hb-card-note">' + archived.length + '</span></div>' +
            archived.map(function (h) {
                return '<div class="hb-toggle-row"><div class="hb-toggle-label">' + esc(h.icon) + ' ' + esc(h.name) + '</div>' +
                    '<button type="button" class="hb-btn hb-btn-quiet" style="flex:0 0 auto;padding:9px 15px;font-size:13px" data-unarch="' + h.id + '">Ripristina</button></div>';
            }).join('') +
        '</div>' : '');

    body.onclick = function (e) {
        var b;
        if (e.target.closest('#hbOpenReorder')) { openReorder(); return; }
        if (e.target.closest('#hbOpenImport')) { if (window.openHabitsImport) window.openHabitsImport(); return; }
        if (e.target.closest('#hbOpenExport')) { if (window.exportHabitsFile) window.exportHabitsFile(); return; }
        if ((b = e.target.closest('[data-toggle]'))) {
            var key = b.getAttribute('data-toggle');
            s[key] = !s[key];
            b.classList.toggle('on', s[key]);
            buzz(10);
            var patch = {}; patch[key] = s[key] ? '1' : '0';
            api('settings_save', patch);
            renderAll();
            return;
        }
        if ((b = e.target.closest('[data-accent]'))) {
            s.accent_color = b.getAttribute('data-accent');
            document.body.style.setProperty('--accent', s.accent_color);
            api('settings_save', { accent_color: s.accent_color });
            buzz(10); renderSettings(); renderAll();
            return;
        }
        if ((b = e.target.closest('[data-range]'))) {
            s.chart_range_days = +b.getAttribute('data-range');
            api('settings_save', { chart_range_days: s.chart_range_days });
            buzz(8); renderSettings();
            return;
        }
        if ((b = e.target.closest('[data-step]'))) {
            var wrap = b.closest('.hb-stepper');
            var v = (parseFloat(s.visible_days) || 11) + (+b.getAttribute('data-step'));
            s.visible_days = Math.max(5, Math.min(31, v));
            api('settings_save', { visible_days: s.visible_days });
            buzz(8); renderSettings(); renderDayStrip();
            return;
        }
        if ((b = e.target.closest('[data-unarch]'))) {
            var id = +b.getAttribute('data-unarch');
            api('habit_archive', { id:id, archive:'0' }).then(function () {
                var h = habitById(id); if (h) h.is_archived = false;
                toast('↩️', 'Ripristinata'); renderSettings(); renderAll();
            });
            return;
        }
    };
}

/* pressione lunga su un giorno del calendario del dettaglio = scrivi la nota */
var calLongFired = false;
(function bindCalendarLongPress() {
    var body = document.getElementById('hbDetailBody');
    var timer = null;

    body.addEventListener('pointerdown', function (e) {
        var cell = e.target.closest('[data-cal]');
        if (!cell) return;
        var date = cell.getAttribute('data-cal');
        if (isFuture(date)) return;
        calLongFired = false;
        timer = setTimeout(function () {
            calLongFired = true; buzz(24);
            if (S.detailHabit) openNote(S.detailHabit, date);
        }, 520);
    });
    ['pointerup', 'pointercancel', 'pointermove'].forEach(function (ev) {
        body.addEventListener(ev, function () { clearTimeout(timer); });
    });
})();

/* =====================================================================
   19. NOTA DEL CHECK-IN E DIARIO DELLA GIORNATA
   Lo stesso foglio serve due casi: la nota legata a una singola
   abitudine (habit_id valorizzato) e il diario libero del giorno.
   ===================================================================== */
var MOODS = ['😞', '😕', '😐', '🙂', '😄'];
var noteCtx = null;   // { habitId | null, date, mood, energy, note }

function moodRowHTML(value) {
    return MOODS.map(function (emoji, i) {
        return '<button type="button" class="hb-mood' + (value === i + 1 ? ' on' : '') +
               '" data-mood="' + (i + 1) + '" aria-label="Livello ' + (i + 1) + ' su 5">' + emoji + '</button>';
    }).join('');
}

function openNote(habitId, date) {
    /* Il diario della giornata ora è il popup condiviso: lo stesso che si
       apre dal planner e che riempie la pagina diario.php. La nota legata a
       una singola abitudine resta invece qui sotto, come prima. */
    if (!habitId && window.nisamaOpenDiary) {
        window.nisamaOpenDiary(date, 'habits');
        return;
    }
    var h = habitId ? habitById(habitId) : null;
    if (habitId && !h) return;
    var existing = habitId ? (logOf(habitId, date) || {}) : (S.journal[date] || {});

    noteCtx = {
        habitId: habitId || null,
        date: date,
        mood: existing.mood || 0,
        energy: existing.energy || 0,
        note: existing.note || ''
    };

    document.getElementById('hbNoteTitle').textContent = habitId ? 'Nota del check-in' : 'Diario del giorno';
    document.getElementById('hbNoteDay').innerHTML =
        '<span class="hb-note-day-icon" style="--nc:' + (h ? h.color : 'var(--accent)') + '">' + (h ? esc(h.icon) : '📔') + '</span>' +
        '<span>' + esc(h ? h.name : 'Come è andata la giornata') +
            '<span class="hb-note-day-sub">' + esc(labelDate(date)) + '</span>' +
        '</span>';

    document.getElementById('hbNoteMood').innerHTML = moodRowHTML(noteCtx.mood);
    var energyField = document.getElementById('hbNoteEnergyField');
    energyField.hidden = !!habitId;                       // l'energia riguarda solo la giornata
    document.getElementById('hbNoteEnergy').innerHTML = moodRowHTML(noteCtx.energy);

    var ta = document.getElementById('hbNoteText');
    ta.value = noteCtx.note;
    ta.placeholder = habitId
        ? 'Com\'è andata questa abitudine oggi?'
        : 'Cosa ti ha aiutato oggi? Cosa ti ha fermato?';
    updateNoteCount();

    openSheet('hbSheetNote');
    buzz(8);
}

function updateNoteCount() {
    var n = document.getElementById('hbNoteText').value.length;
    document.getElementById('hbNoteCount').textContent = n + (n === 1 ? ' carattere' : ' caratteri');
}

document.getElementById('hbNoteText').addEventListener('input', updateNoteCount);

document.getElementById('hbSheetNote').addEventListener('click', function (e) {
    var b = e.target.closest('[data-mood]');
    if (!b || !noteCtx) return;
    var value = +b.getAttribute('data-mood');
    var isEnergy = !!b.closest('#hbNoteEnergy');
    var field = isEnergy ? 'energy' : 'mood';
    noteCtx[field] = (noteCtx[field] === value) ? 0 : value;   // ritocca per deselezionare
    var row = b.parentNode;
    row.querySelectorAll('.hb-mood').forEach(function (m) {
        m.classList.toggle('on', +m.getAttribute('data-mood') === noteCtx[field]);
    });
    buzz(8);
});

function saveNote() {
    if (!noteCtx) return;
    var text = document.getElementById('hbNoteText').value.trim();
    var date = noteCtx.date;

    if (noteCtx.habitId) {
        var key = noteCtx.habitId + '|' + date;
        var log = S.logs[key];
        if (log) { log.note = text; log.mood = noteCtx.mood || null; }
        api('log_note', {
            habit_id: noteCtx.habitId, date: date,
            note: text, mood: noteCtx.mood || ''
        }).then(function () {
            // se il log non esisteva, il server l'ha appena creato: riallinea
            if (!S.logs[key]) {
                S.logs[key] = {
                    habit_id: noteCtx.habitId, date: date, status: 'partial',
                    amount: 0, target: 1, completions: 0,
                    note: text, mood: noteCtx.mood || null
                };
            }
            if (S.detailHabit) renderDetail();
        });
    } else {
        S.journal[date] = { mood: noteCtx.mood || null, energy: noteCtx.energy || null, note: text };
        api('journal_save', {
            date: date, note: text,
            mood: noteCtx.mood || '', energy: noteCtx.energy || ''
        });
    }

    closeSheet('hbSheetNote');
    toast('📝', text ? 'Nota salvata' : 'Nota svuotata');
    buzz(12);
    renderDayHero();
}

document.getElementById('hbNoteSave').onclick = saveNote;
document.getElementById('hbNoteClear').onclick = function () {
    document.getElementById('hbNoteText').value = '';
    if (noteCtx) { noteCtx.mood = 0; noteCtx.energy = 0; }
    document.getElementById('hbNoteMood').innerHTML = moodRowHTML(0);
    document.getElementById('hbNoteEnergy').innerHTML = moodRowHTML(0);
    updateNoteCount();
    buzz(8);
};

/* il riquadro della giornata apre il diario */
document.getElementById('hbDayHero').addEventListener('click', function () { openNote(null, S.selected); });

/* =====================================================================
   20. RIORDINO DELL'ELENCO
   Trascinamento con pointer events: funziona con dito, mouse e penna.
   ===================================================================== */
function openReorder() {
    var list = document.getElementById('hbReorderList');
    var items = S.habits.filter(function (h) { return !h.is_archived; });

    if (!items.length) {
        list.innerHTML = '<div class="hb-none">Non hai ancora abitudini da riordinare.</div>';
    } else {
        list.innerHTML = items.map(function (h) {
            return '<div class="hb-reorder-row" data-id="' + h.id + '">' +
                '<span class="hb-reorder-icon" style="--rc:' + h.color + '">' + esc(h.icon) + '</span>' +
                '<span class="hb-reorder-name">' + esc(h.name) + '</span>' +
                '<span class="hb-reorder-grip" aria-label="Trascina per spostare">' +
                    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">' +
                    '<path d="M4 9h16M4 15h16"/></svg>' +
                '</span>' +
            '</div>';
        }).join('');
    }
    openSheet('hbSheetReorder');
    buzz(8);
}

(function bindReorderDrag() {
    var list = document.getElementById('hbReorderList');
    var row = null, startY = 0, offset = 0, rows = [], height = 0;

    list.addEventListener('pointerdown', function (e) {
        var grip = e.target.closest('.hb-reorder-grip');
        if (!grip) return;
        row = grip.closest('.hb-reorder-row');
        if (!row) return;
        e.preventDefault();
        grip.setPointerCapture(e.pointerId);
        startY = e.clientY; offset = 0;
        rows = Array.prototype.slice.call(list.children);
        height = row.offsetHeight + 8;                 // altezza riga + gap
        row.classList.add('dragging');
        buzz(14);
    });

    list.addEventListener('pointermove', function (e) {
        if (!row) return;
        offset = e.clientY - startY;
        row.style.transform = 'translateY(' + offset + 'px) scale(1.03)';

        var idx = rows.indexOf(row);
        var moved = Math.round(offset / height);
        var target = Math.max(0, Math.min(rows.length - 1, idx + moved));
        if (target === idx) {
            rows.forEach(function (r) { if (r !== row) r.style.transform = ''; });
            return;
        }
        // sposta visivamente le righe scavalcate, così si vede dove atterrerà
        rows.forEach(function (r, i) {
            if (r === row) return;
            var shift = 0;
            if (target > idx && i > idx && i <= target) shift = -height;
            else if (target < idx && i >= target && i < idx) shift = height;
            r.style.transform = shift ? 'translateY(' + shift + 'px)' : '';
        });
    });

    function drop() {
        if (!row) return;
        var idx = rows.indexOf(row);
        var target = Math.max(0, Math.min(rows.length - 1, idx + Math.round(offset / height)));

        rows.forEach(function (r) { r.style.transform = ''; });
        row.classList.remove('dragging');
        row.style.transform = '';

        if (target !== idx) {
            list.insertBefore(row, target > idx ? rows[target].nextSibling : rows[target]);
            var ids = Array.prototype.slice.call(list.children).map(function (r) { return +r.getAttribute('data-id'); });
            // riallinea l'ordine in memoria e poi salva
            ids.forEach(function (id, i) {
                var h = habitById(id);
                if (h) h.sort_order = (i + 1) * 10;
            });
            S.habits.sort(function (a, b) { return (a.sort_order || 0) - (b.sort_order || 0); });
            api('habit_reorder', { ids: JSON.stringify(ids) });
            buzz(16);
            renderAll();
        }
        row = null; rows = [];
    }

    list.addEventListener('pointerup', drop);
    list.addEventListener('pointercancel', drop);
})();

/* =====================================================================
   21. AVVIO
   ===================================================================== */
document.getElementById('hbFab').onclick = function () { buzz(12); openEditor(null); };
document.getElementById('hbEmptyAdd').onclick = function () { openEditor(null); };
document.getElementById('hbBtnStats').onclick = function () { renderStats(); openSheet('hbSheetStats'); buzz(8); };
document.getElementById('hbBtnTable').onclick = function () { renderTable(); openSheet('hbSheetTable'); buzz(8); };
document.getElementById('hbBtnSettings').onclick = function () { renderSettings(); openSheet('hbSheetSettings'); buzz(8); };

/* se la pagina resta aperta oltre la mezzanotte, la data si aggiorna da sola */
setInterval(function () {
    var now = toStr(new Date());
    if (now !== S.today) {
        var wasToday = S.selected === S.today;
        S.today = now;
        if (wasToday) S.selected = now;
        renderAll();
    }
}, 60000);

/* rientro sulla pagina: ricarica i dati dal server, così le spunte fatte
   altrove (altro telefono, altra scheda) compaiono subito. */
var lastSync = Date.now();
document.addEventListener('visibilitychange', function () {
    if (document.hidden) return;
    if (Date.now() - lastSync < 60000) return;      // niente richieste a raffica
    lastSync = Date.now();
    api('bootstrap', {}).then(function (d) {
        if (!d || !d.ok) return;
        S.today   = d.today;
        S.habits  = d.habits || [];
        S.journal = d.journal || {};
        S.categories   = d.categories || [];
        S.achievements = d.achievements || [];
        S.settings     = d.settings || S.settings;
        S.logs = {};
        (d.logs || []).forEach(function (l) { S.logs[l.habit_id + '|' + l.date] = l; });
        if (isFuture(S.selected)) S.selected = S.today;
        renderAll();
    }).catch(function () { /* offline: si continua con i dati in memoria */ });
});

/* se il diario viene scritto dal popup condiviso, rileggiamo la giornata */
document.addEventListener('diaryupdated', function () {
    api('bootstrap', {}).then(function (d) {
        if (!d || !d.ok) return;
        S.journal = d.journal || {};
        renderAll();
    }).catch(function () { /* offline: si continua con i dati in memoria */ });
});

renderAll();
startCarousel();

})();