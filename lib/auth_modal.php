<?php
require_once __DIR__ . '/auth_core.php';
$standout_utente_fab = standout_utente_corrente();
$standout_csrf = standout_csrf_token();
?>
<?php /* i caratteri senza bloccare il disegno: finche' non arrivano si usa
   quello di sistema. Un <link> normale qui, in mezzo al body, fermava tutto
   quello che veniva dopo, compreso il pulsante Accedi. */ ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap" media="print" data-on-load="this.media='all'">
<style nonce="<?php echo sec_nonce(); ?>">
/*
  IMPORTANTE: queste variabili sono su :root (globali) e non su
  #standout-auth-root. Il popup viene clonato da un <template> dentro
  document.body al primo utilizzo (per l'autofill di iOS, vedi sotto),
  quindi finisce FUORI da #standout-auth-root nel DOM: se le variabili
  fossero scoperte solo lì dentro, il popup perderebbe tutti i colori
  (è esattamente il bug della "flashcard trasparente").
*/
:root{
  --nis-paper:#FBF7EE; --nis-paper-line:#E7DFC9; --nis-ink:#26241C;
  --nis-accent:#5B8DEF; --nis-accent-dark:#3E6FD9; --nis-danger:#E5484D; --nis-good:#4CC38A;
  --nis-text-soft:#8A8368;
}
#standout-auth-root, #standout-auth-root *{ box-sizing:border-box; }
#standout-auth-root{ font-family:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
.standout-overlay, .standout-overlay *{ box-sizing:border-box; font-family:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }

.standout-fab{ position:fixed; top:18px; right:18px; z-index:999990; }
/* non connessi: lingua e "Accedi" affiancati in alto a destra */
.standout-topbar{ position:fixed; top:18px; right:18px; z-index:999990; display:flex; align-items:center; gap:10px; }
.standout-topbar .standout-fab{ position:static; }
button.standout-fab--login{
  appearance:none; border:none; cursor:pointer; background:#F5F3EE; color:#12131A;
  font-family:inherit; font-weight:700; font-size:14.5px; padding:11px 22px; border-radius:999px;
  box-shadow:0 6px 20px rgba(0,0,0,.18); transition:transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s ease;
}
button.standout-fab--login:hover{ transform:translateY(-2px) scale(1.03); box-shadow:0 10px 26px rgba(0,0,0,.24); }
button.standout-fab--login:active{ transform:scale(.96); }

.standout-fab--user{ display:flex; flex-direction:column; align-items:flex-end; }
.standout-fab-avatar{
  appearance:none; border:2px solid #fff; cursor:pointer; width:42px; height:42px; border-radius:50%;
  background:linear-gradient(135deg, var(--nis-accent), #A78BFA); color:#fff; font-weight:800; font-size:16px;
  box-shadow:0 6px 18px rgba(0,0,0,.22); transition:transform .2s cubic-bezier(.34,1.56,.64,1);
}
.standout-fab-avatar:hover{ transform:scale(1.06); }
.standout-user-menu{
  margin-top:10px; min-width:180px; background:rgba(255,255,255,.9); backdrop-filter:blur(24px) saturate(180%);
  -webkit-backdrop-filter:blur(24px) saturate(180%); border:1px solid rgba(0,0,0,.06); border-radius:16px; padding:14px;
  box-shadow:0 12px 30px rgba(0,0,0,.18); opacity:0; transform:translateY(-8px) scale(.96); pointer-events:none;
  transition:opacity .18s ease, transform .18s cubic-bezier(.34,1.56,.64,1);
}
.standout-user-menu--open{ opacity:1; transform:translateY(0) scale(1); pointer-events:auto; }
.standout-user-menu-name{ font-weight:700; font-size:14px; color:var(--nis-ink); margin-bottom:10px; word-break:break-word; }
.standout-user-menu-logout{
  display:block; text-align:center; text-decoration:none; font-weight:700; font-size:13.5px; color:var(--nis-danger);
  padding:8px; border-radius:10px; background:rgba(229,72,77,.08); transition:background .15s ease;
}
.standout-user-menu-logout:hover{ background:rgba(229,72,77,.16); }

.standout-overlay{
  position:fixed; inset:0; z-index:999999; background:rgba(18,17,12,.6);
  backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
  display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none;
  transition:opacity .3s ease; padding:16px;
}
.standout-overlay--open{ opacity:1; pointer-events:auto; }

.standout-modal{
  width:100%; max-width:400px; background:var(--nis-paper); border:1px solid var(--nis-paper-line);
  border-radius:26px; padding:30px 26px 26px; position:relative;
  box-shadow:0 40px 90px rgba(0,0,0,.45), 0 1px 0 rgba(255,255,255,.5) inset;
  transform:scale(.94) translateY(16px); opacity:0;
  transition:transform .4s cubic-bezier(.22,1,.36,1), opacity .3s ease, box-shadow .3s ease;
  max-height:calc(100vh - 32px); overflow-y:auto; overflow-x:hidden; color:var(--nis-ink);
}
/* aperto: nessuna trasformazione rimasta addosso. Un campo dentro un
   elemento trasformato (peggio se in 3D) su iPhone a volte non apre la
   tastiera, o la apre con il cursore nel posto sbagliato. */
.standout-overlay--open .standout-modal{ transform:none; opacity:1; }
.standout-modal.is-success{ box-shadow:0 0 0 3px var(--nis-good), 0 40px 90px rgba(0,0,0,.45); }
.standout-modal.is-flip-away{
  transition:transform .55s cubic-bezier(.5,0,.5,1), opacity .4s ease .15s;
  transform:scale(.9); opacity:0;
}

.standout-modal-close{
  position:absolute; top:16px; right:16px; width:30px; height:30px; border-radius:50%; border:none;
  background:rgba(38,36,28,.06); color:var(--nis-text-soft); font-size:14px; cursor:pointer; transition:background .15s ease; z-index:2;
}
.standout-modal-close:hover{ background:rgba(38,36,28,.12); }
.standout-corner-mark{ position:absolute; top:14px; left:18px; font-family:'Fraunces',serif; font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:var(--nis-text-soft); }

.standout-tabs{ position:relative; display:flex; background:rgba(38,36,28,.06); border-radius:12px; padding:4px; margin:22px 0 22px; }
.standout-tab{ flex:1; z-index:1; appearance:none; border:none; background:transparent; font-family:inherit; font-weight:700; font-size:13.5px; color:var(--nis-text-soft); padding:9px 0; border-radius:9px; cursor:pointer; transition:color .25s ease; }
.standout-tab.active{ color:var(--nis-ink); }
.standout-tab-indicator{ position:absolute; top:4px; left:4px; width:calc(50% - 4px); height:calc(100% - 8px); background:#fff; border-radius:9px; box-shadow:0 2px 8px rgba(0,0,0,.08); transition:transform .32s cubic-bezier(.34,1.56,.64,1); }

/* --- Accedi / Registrati: le due facce occupano la stessa cella di una
   grid (l'altezza segue la piu' alta, senza calcoli) e si danno il cambio
   scivolando di lato.
   Prima era una carta che si girava in 3D (rotateY + preserve-3d). Bella,
   ma su iPhone i campi dentro un elemento 3D spesso non aprivano la
   tastiera al tocco: era il "tocco il campo e la tastiera non esce".
   La faccia nascosta ha anche visibility:hidden, cosi' il tasto Tab e il
   riempimento automatico non finiscono nei campi che non si vedono. --- */
.standout-flip3d{ position:relative; }
.standout-flip3d-inner{ position:relative; display:grid; transition:height .3s ease; }
.standout-face{
  grid-area:1 / 1; min-width:0;
  transition:opacity .25s ease, transform .35s cubic-bezier(.22,1,.36,1), visibility 0s linear 0s;
}
.standout-face-back,
.standout-flip3d-inner.is-flipped .standout-face-front{
  /* fuori dal flusso: l'altezza del popup e' quella della faccia che si
     vede, non della piu' alta (il login restava con mezzo foglio vuoto) */
  position:absolute; top:0; left:0; right:0;
  opacity:0; visibility:hidden; pointer-events:none;
  transition:opacity .2s ease, transform .3s cubic-bezier(.22,1,.36,1), visibility 0s linear .3s;
}
.standout-face-back{ transform:translateX(28px); }
.standout-flip3d-inner.no-anim .standout-face{ transition:none !important; }
.standout-flip3d-inner.is-flipped .standout-face-front{ transform:translateX(-28px); }
.standout-flip3d-inner.is-flipped .standout-face-back{
  position:relative; opacity:1; visibility:visible; pointer-events:auto; transform:none;
  transition:opacity .25s ease .05s, transform .35s cubic-bezier(.22,1,.36,1), visibility 0s linear 0s;
}

.standout-form-title{ font-family:'Fraunces',serif; font-size:23px; font-weight:700; color:var(--nis-ink); margin:0 0 4px; }
.standout-form-sub{ font-size:13.5px; color:var(--nis-text-soft); margin:0 0 20px; }

.standout-field{ display:block; margin-bottom:14px; position:relative; }
.standout-field span{ display:block; font-size:12.5px; font-weight:700; color:var(--nis-text-soft); margin-bottom:6px; }
.standout-field input{
  /* 16px e non meno: sotto i 16 iPhone ingrandisce la pagina a ogni tocco
     sul campo, e il popup salta di lato mentre si apre la tastiera */
  width:100%; font-family:inherit; font-size:16px; color:var(--nis-ink); padding:12px 14px; border-radius:13px;
  border:1.5px solid var(--nis-paper-line); background:rgba(255,255,255,.7); outline:none;
  transition:border-color .18s ease, box-shadow .18s ease;
}
.standout-field input:focus{ border-color:var(--nis-accent); box-shadow:0 0 0 4px rgba(91,141,239,.15); }
.standout-field--password input{ padding-right:44px; }
.standout-toggle-pw{ position:absolute; right:10px; bottom:9px; width:28px; height:28px; border:none; background:transparent; cursor:pointer; font-size:15px; opacity:.6; }
.standout-toggle-pw:hover{ opacity:1; }

.standout-pw-strength{ height:4px; border-radius:3px; background:rgba(38,36,28,.08); margin:-6px 0 14px; overflow:hidden; }
.standout-pw-strength span{ display:block; height:100%; width:0%; border-radius:3px; background:var(--nis-danger); transition:width .25s ease, background .25s ease; }
.standout-pw-strength span[data-livello="media"]{ background:#F5A623; }
.standout-pw-strength span[data-livello="buona"], .standout-pw-strength span[data-livello="forte"]{ background:var(--nis-good); }

.standout-error{ font-size:13px; font-weight:600; color:var(--nis-danger); background:rgba(229,72,77,.08); border-radius:10px; padding:10px 12px; margin-bottom:14px; }

.standout-submit{
  width:100%; border:none; cursor:pointer; font-family:inherit; font-weight:800; font-size:15px; color:#fff;
  background:linear-gradient(135deg, var(--nis-accent), var(--nis-accent-dark)); padding:13px; border-radius:14px;
  display:flex; align-items:center; justify-content:center; gap:10px; box-shadow:0 10px 24px rgba(91,141,239,.35);
  transition:transform .18s cubic-bezier(.34,1.56,.64,1), box-shadow .18s ease, opacity .18s ease;
}
.standout-submit:hover{ transform:translateY(-1px); box-shadow:0 14px 30px rgba(91,141,239,.42); }
.standout-submit:active{ transform:scale(.98); }
.standout-submit:disabled{ opacity:.75; cursor:default; transform:none; }
.standout-spinner{ width:16px; height:16px; border-radius:50%; border:2.5px solid rgba(255,255,255,.4); border-top-color:#fff; animation:standout-spin .7s linear infinite; }
@keyframes standout-spin{ to{ transform:rotate(360deg); } }

.standout-switch{ text-align:center; font-size:13px; color:var(--nis-text-soft); margin:18px 0 0; }
.standout-switch a{ color:var(--nis-accent); font-weight:700; text-decoration:none; }
.standout-switch a:hover{ text-decoration:underline; }

@keyframes standout-shake{ 10%,90%{transform:translateX(-1px);} 20%,80%{transform:translateX(2px);} 30%,50%,70%{transform:translateX(-4px);} 40%,60%{transform:translateX(4px);} }
.standout-shake{ animation:standout-shake .5s; }

@media (max-width:640px){
  .standout-overlay{ align-items:flex-end; padding:0; }
  .standout-modal{ max-width:100%; border-radius:26px 26px 0 0; padding:28px 22px 30px; max-height:88vh; }
  .standout-overlay--open .standout-modal{ transform:translateY(0); }
}
@media (prefers-reduced-motion: reduce){
  .standout-overlay, .standout-modal, .standout-flip3d-inner{ transition-duration:.01ms !important; animation-duration:.01ms !important; }
}
</style>

<div id="standout-auth-root">
  <?php if ($standout_utente_fab):
          include __DIR__ . '/../public/partials/notifiche_widget.php';
        else: ?>
    <?php require_once __DIR__ . '/../public/partials/lang_picker.php'; ?>
    <div class="standout-topbar">
      <?php standout_lang_picker('pill'); ?>
      <button class="standout-fab standout-fab--login" id="standoutFabLogin" type="button"><?php te('auth.accedi'); ?></button>
    </div>

    <template id="standoutAuthTemplate">
      <div class="standout-overlay" id="standoutOverlay" aria-hidden="true">
        <div class="standout-modal" id="standoutModal" role="dialog" aria-modal="true" aria-labelledby="standoutModalTitle">
          <span class="standout-corner-mark">Standout</span>
          <button class="standout-modal-close" id="standoutClose" aria-label="<?php te('auth.chiudi'); ?>" type="button">&#10005;</button>

          <div class="standout-tabs">
            <button class="standout-tab active" id="tabLogin" type="button"><?php te('auth.accedi'); ?></button>
            <button class="standout-tab" id="tabRegister" type="button"><?php te('auth.registrati'); ?></button>
            <span class="standout-tab-indicator" id="tabIndicator"></span>
          </div>

          <div class="standout-flip3d">
            <div class="standout-flip3d-inner" id="standoutFlip3dInner">
              <div class="standout-face standout-face-front">
                <form class="standout-form" id="formLogin" novalidate>
                  <h2 id="standoutModalTitle" class="standout-form-title"><?php te('auth.bentornato'); ?></h2>
                  <p class="standout-form-sub"><?php te('auth.sub_login'); ?></p>

                  <label class="standout-field">
                    <span><?php te('auth.email_o_utente'); ?></span>
                    <input type="text" name="identifier" autocomplete="username" required>
                  </label>
                  <label class="standout-field standout-field--password">
                    <span><?php te('auth.password'); ?></span>
                    <input type="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="standout-toggle-pw" aria-label="<?php te('auth.mostra_password'); ?>">&#128065;</button>
                  </label>

                  <div class="standout-error" id="loginError" hidden></div>

                  <button type="submit" class="standout-submit">
                    <span class="standout-submit-label"><?php te('auth.accedi'); ?></span>
                    <span class="standout-spinner" hidden></span>
                  </button>

                  <p class="standout-switch"><?php te('auth.no_account'); ?> <a href="#" data-switch="registrati"><?php te('auth.registrati'); ?></a></p>
                </form>
              </div>

              <div class="standout-face standout-face-back">
                <form class="standout-form" id="formRegister" novalidate>
                  <h2 class="standout-form-title"><?php te('auth.crea_account_titolo'); ?></h2>
                  <p class="standout-form-sub"><?php te('auth.sub_registrati'); ?></p>

                  <label class="standout-field">
                    <span><?php te('auth.nome_utente'); ?></span>
                    <input type="text" name="username" autocomplete="username" required minlength="3" maxlength="30">
                  </label>
                  <label class="standout-field">
                    <span><?php te('auth.email'); ?></span>
                    <input type="email" name="email" autocomplete="email" required>
                  </label>
                  <label class="standout-field standout-field--password">
                    <span><?php te('auth.password'); ?></span>
                    <input type="password" name="password" id="registerPasswordInput" autocomplete="new-password" required minlength="10" maxlength="128">
                    <button type="button" class="standout-toggle-pw" aria-label="<?php te('auth.mostra_password'); ?>">&#128065;</button>
                  </label>
                  <div class="standout-pw-strength" id="pwStrength"><span></span></div>
                  <label class="standout-field standout-field--password">
                    <span><?php te('auth.ripeti_password'); ?></span>
                    <input type="password" name="password_confirm" autocomplete="new-password" required>
                  </label>

                  <div class="standout-error" id="registerError" hidden></div>

                  <button type="submit" class="standout-submit">
                    <span class="standout-submit-label"><?php te('auth.crea_account'); ?></span>
                    <span class="standout-spinner" hidden></span>
                  </button>

                  <p class="standout-switch"><?php te('auth.hai_account'); ?> <a href="#" data-switch="login"><?php te('auth.accedi'); ?></a></p>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  <?php endif; ?>
</div>

<?php if (!$standout_utente_fab): ?>
<script nonce="<?php echo sec_nonce(); ?>">
(function(){
  const STANDOUT_CSRF = "<?php echo htmlspecialchars($standout_csrf, ENT_QUOTES); ?>";
  const TXT = <?php echo json_encode(['generico' => t('auth.err_generico'), 'rete' => t('auth.err_rete'), 'password_diverse' => t('auth.err_password_diverse')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
  const loginFab = document.getElementById('standoutFabLogin');
  const template = document.getElementById('standoutAuthTemplate');

  let overlay, modal, closeBtn, tabLogin, tabRegister, tabIndicator, flipInner, formLogin, formRegister, mounted = false;

  function mostraTab(tab, animato) {
    const isLogin = tab !== 'registrati';
    tabLogin.classList.toggle('active', isLogin);
    tabRegister.classList.toggle('active', !isLogin);
    tabIndicator.style.transform = isLogin ? 'translateX(0%)' : 'translateX(100%)';
    if (animato === false) {
      flipInner.classList.add('no-anim');
      flipInner.classList.toggle('is-flipped', !isLogin);
      void flipInner.offsetWidth;
      flipInner.classList.remove('no-anim');
    } else {
      flipInner.classList.toggle('is-flipped', !isLogin);
    }
  }

  function mostraErrore(container, messaggio) {
    container.textContent = messaggio;
    container.hidden = false;
    const form = container.closest('form');
    form.classList.remove('standout-shake');
    void form.offsetWidth;
    form.classList.add('standout-shake');
  }

  async function invia(form, url, corpo, erroreEl) {
    const submitBtn = form.querySelector('.standout-submit');
    const spinner = submitBtn.querySelector('.standout-spinner');
    submitBtn.disabled = true;
    spinner.hidden = false;
    erroreEl.hidden = true;

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(corpo)
      });
      const data = await res.json().catch(() => ({}));
      if (data && data.success) {
        // animazione di successo: un breve impulso, poi la carta si gira
        // rivelando il sito, e solo allora ricarichiamo la pagina
        modal.classList.add('is-success');
        setTimeout(() => {
          modal.classList.remove('is-success');
          modal.classList.add('is-flip-away');
        }, 260);
        setTimeout(() => { window.location.href = window.location.pathname; }, 980);
        return;
      }
      let msg = (data && data.message) || TXT.generico;
      if (data && data.errori) msg = Object.values(data.errori)[0];
      mostraErrore(erroreEl, msg);
      submitBtn.disabled = false;
      spinner.hidden = true;
    } catch (e) {
      mostraErrore(erroreEl, TXT.rete);
      submitBtn.disabled = false;
      spinner.hidden = true;
    }
  }

  function montaModale() {
    if (mounted) return;
    mounted = true;
    document.body.appendChild(template.content.cloneNode(true));

    overlay = document.getElementById('standoutOverlay');
    modal = document.getElementById('standoutModal');
    closeBtn = document.getElementById('standoutClose');
    tabLogin = document.getElementById('tabLogin');
    tabRegister = document.getElementById('tabRegister');
    tabIndicator = document.getElementById('tabIndicator');
    flipInner = document.getElementById('standoutFlip3dInner');
    formLogin = document.getElementById('formLogin');
    formRegister = document.getElementById('formRegister');

    tabLogin.addEventListener('click', () => mostraTab('login'));
    tabRegister.addEventListener('click', () => mostraTab('registrati'));
    closeBtn.addEventListener('click', chiudi);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) chiudi(); });
    overlay.querySelectorAll('[data-switch]').forEach(a => {
      a.addEventListener('click', (e) => { e.preventDefault(); mostraTab(a.dataset.switch); });
    });
    overlay.querySelectorAll('.standout-toggle-pw').forEach(btn => {
      btn.addEventListener('click', () => {
        const input = btn.previousElementSibling;
        input.type = input.type === 'password' ? 'text' : 'password';
      });
    });

    const pwInput = document.getElementById('registerPasswordInput');
    const pwStrengthBar = overlay.querySelector('#pwStrength span');
    if (pwInput) {
      pwInput.addEventListener('input', () => {
        const v = pwInput.value;
        let punteggio = 0;
        if (v.length >= 8) punteggio++;
        if (v.length >= 12) punteggio++;
        if (/[0-9]/.test(v)) punteggio++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) punteggio++;
        if (/[^A-Za-z0-9]/.test(v)) punteggio++;
        const livelli = ['scarsa','scarsa','media','buona','forte','forte'];
        const percentuali = [0,25,45,65,85,100];
        const idx = Math.max(0, Math.min(5, punteggio));
        pwStrengthBar.style.width = (v.length ? percentuali[idx] : 0) + '%';
        pwStrengthBar.dataset.livello = livelli[idx];
      });
    }

    formLogin.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(formLogin);
      invia(formLogin, 'account/login.php', {
        identifier: fd.get('identifier'), password: fd.get('password'), csrf_token: STANDOUT_CSRF
      }, document.getElementById('loginError'));
    });

    formRegister.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(formRegister);
      if (fd.get('password') !== fd.get('password_confirm')) {
        mostraErrore(document.getElementById('registerError'), TXT.password_diverse);
        return;
      }
      invia(formRegister, 'account/register.php', {
        username: fd.get('username'), email: fd.get('email'),
        password: fd.get('password'), password_confirm: fd.get('password_confirm'), csrf_token: STANDOUT_CSRF
      }, document.getElementById('registerError'));
    });

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.classList.contains('standout-overlay--open')) chiudi(); });
  }

  function apri(tab) {
    montaModale();
    mostraTab(tab || 'login', false);
    requestAnimationFrame(() => {
      overlay.classList.add('standout-overlay--open');
      overlay.setAttribute('aria-hidden', 'false');
    });
    document.body.style.overflow = 'hidden';
    /* Il primo campo si accende da solo solo con mouse e tastiera. Sul
       telefono no: un focus() dato da un timer non apre la tastiera (iOS la
       concede solo dentro il tocco), ma lascia il campo "gia' attivo" — e
       toccandolo dopo, la tastiera non usciva piu'. */
    if (window.matchMedia && window.matchMedia('(pointer:fine)').matches) {
      setTimeout(() => {
        const form = (tab === 'registrati') ? formRegister : formLogin;
        const firstInput = form.querySelector('input');
        if (firstInput) firstInput.focus({ preventScroll: true });
      }, 320);
    }
  }

  function chiudi() {
    if (!overlay) return;
    overlay.classList.remove('standout-overlay--open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  if (loginFab) loginFab.addEventListener('click', () => apri('login'));

  if (new URLSearchParams(window.location.search).get('auth') === 'richiesto') {
    apri('login');
  }

  window.StandoutAuth = {
    apriLogin: () => apri('login'),
    apriRegistrazione: () => apri('registrati'),
    chiudi: () => chiudi()
  };
})();
</script>
<?php else: ?>
<script nonce="<?php echo sec_nonce(); ?>">
(function(){
  const userBtn = document.getElementById('standoutUserBtn');
  if (userBtn) {
    const menu = document.getElementById('standoutUserMenu');
    userBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const aperto = menu.classList.toggle('standout-user-menu--open');
      userBtn.setAttribute('aria-expanded', aperto ? 'true' : 'false');
    });
    document.addEventListener('click', () => menu.classList.remove('standout-user-menu--open'));
  }
  window.StandoutAuth = {
    apriLogin: () => { window.location.href = 'index.php'; },
    apriRegistrazione: () => { window.location.href = 'index.php'; },
    chiudi: () => {}
  };
})();
</script>
<?php endif; ?>
