<?php
require_once __DIR__ . '/auth_core.php';
$nisama_utente_fab = nisama_utente_corrente();
$nisama_csrf = nisama_csrf_token();
?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
<style>
/*
  IMPORTANTE: queste variabili sono su :root (globali) e non su
  #nisama-auth-root. Il popup viene clonato da un <template> dentro
  document.body al primo utilizzo (per l'autofill di iOS, vedi sotto),
  quindi finisce FUORI da #nisama-auth-root nel DOM: se le variabili
  fossero scoperte solo lì dentro, il popup perderebbe tutti i colori
  (è esattamente il bug della "flashcard trasparente").
*/
:root{
  --nis-paper:#FBF7EE; --nis-paper-line:#E7DFC9; --nis-ink:#26241C;
  --nis-accent:#5B8DEF; --nis-accent-dark:#3E6FD9; --nis-danger:#E5484D; --nis-good:#4CC38A;
  --nis-text-soft:#8A8368;
}
#nisama-auth-root, #nisama-auth-root *{ box-sizing:border-box; }
#nisama-auth-root{ font-family:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
.nisama-overlay, .nisama-overlay *{ box-sizing:border-box; font-family:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }

.nisama-fab{ position:fixed; top:18px; right:18px; z-index:999990; }
button.nisama-fab--login{
  appearance:none; border:none; cursor:pointer; background:var(--nis-ink); color:#fff;
  font-family:inherit; font-weight:700; font-size:14.5px; padding:11px 22px; border-radius:999px;
  box-shadow:0 6px 20px rgba(0,0,0,.18); transition:transform .25s cubic-bezier(.34,1.56,.64,1), box-shadow .25s ease;
}
button.nisama-fab--login:hover{ transform:translateY(-2px) scale(1.03); box-shadow:0 10px 26px rgba(0,0,0,.24); }
button.nisama-fab--login:active{ transform:scale(.96); }

.nisama-fab--user{ display:flex; flex-direction:column; align-items:flex-end; }
.nisama-fab-avatar{
  appearance:none; border:2px solid #fff; cursor:pointer; width:42px; height:42px; border-radius:50%;
  background:linear-gradient(135deg, var(--nis-accent), #A78BFA); color:#fff; font-weight:800; font-size:16px;
  box-shadow:0 6px 18px rgba(0,0,0,.22); transition:transform .2s cubic-bezier(.34,1.56,.64,1);
}
.nisama-fab-avatar:hover{ transform:scale(1.06); }
.nisama-user-menu{
  margin-top:10px; min-width:180px; background:rgba(255,255,255,.9); backdrop-filter:blur(24px) saturate(180%);
  -webkit-backdrop-filter:blur(24px) saturate(180%); border:1px solid rgba(0,0,0,.06); border-radius:16px; padding:14px;
  box-shadow:0 12px 30px rgba(0,0,0,.18); opacity:0; transform:translateY(-8px) scale(.96); pointer-events:none;
  transition:opacity .18s ease, transform .18s cubic-bezier(.34,1.56,.64,1);
}
.nisama-user-menu--open{ opacity:1; transform:translateY(0) scale(1); pointer-events:auto; }
.nisama-user-menu-name{ font-weight:700; font-size:14px; color:var(--nis-ink); margin-bottom:10px; word-break:break-word; }
.nisama-user-menu-logout{
  display:block; text-align:center; text-decoration:none; font-weight:700; font-size:13.5px; color:var(--nis-danger);
  padding:8px; border-radius:10px; background:rgba(229,72,77,.08); transition:background .15s ease;
}
.nisama-user-menu-logout:hover{ background:rgba(229,72,77,.16); }

.nisama-overlay{
  position:fixed; inset:0; z-index:999999; background:rgba(18,17,12,.6);
  backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px);
  display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none;
  transition:opacity .3s ease; padding:16px; perspective:1600px;
}
.nisama-overlay--open{ opacity:1; pointer-events:auto; }

.nisama-modal{
  width:100%; max-width:400px; background:var(--nis-paper); border:1px solid var(--nis-paper-line);
  border-radius:26px; padding:30px 26px 26px; position:relative;
  box-shadow:0 40px 90px rgba(0,0,0,.45), 0 1px 0 rgba(255,255,255,.5) inset;
  transform:scale(.9) translateY(16px); opacity:0; transform-style:preserve-3d;
  transition:transform .45s cubic-bezier(.34,1.56,.64,1), opacity .3s ease, box-shadow .3s ease;
  max-height:calc(100vh - 32px); overflow-y:auto; overflow-x:hidden; color:var(--nis-ink);
}
.nisama-overlay--open .nisama-modal{ transform:scale(1) translateY(0); opacity:1; }
.nisama-modal.is-success{ box-shadow:0 0 0 3px var(--nis-good), 0 40px 90px rgba(0,0,0,.45); }
.nisama-modal.is-flip-away{
  transition:transform .7s cubic-bezier(.5,0,.5,1), opacity .45s ease .3s;
  transform:scale(.92) rotateY(180deg); opacity:0;
}

.nisama-modal-close{
  position:absolute; top:16px; right:16px; width:30px; height:30px; border-radius:50%; border:none;
  background:rgba(38,36,28,.06); color:var(--nis-text-soft); font-size:14px; cursor:pointer; transition:background .15s ease; z-index:2;
}
.nisama-modal-close:hover{ background:rgba(38,36,28,.12); }
.nisama-corner-mark{ position:absolute; top:14px; left:18px; font-family:'Fraunces',serif; font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:var(--nis-text-soft); }

.nisama-tabs{ position:relative; display:flex; background:rgba(38,36,28,.06); border-radius:12px; padding:4px; margin:22px 0 22px; }
.nisama-tab{ flex:1; z-index:1; appearance:none; border:none; background:transparent; font-family:inherit; font-weight:700; font-size:13.5px; color:var(--nis-text-soft); padding:9px 0; border-radius:9px; cursor:pointer; transition:color .25s ease; }
.nisama-tab.active{ color:var(--nis-ink); }
.nisama-tab-indicator{ position:absolute; top:4px; left:4px; width:calc(50% - 4px); height:calc(100% - 8px); background:#fff; border-radius:9px; box-shadow:0 2px 8px rgba(0,0,0,.08); transition:transform .32s cubic-bezier(.34,1.56,.64,1); }

/* --- vero meccanismo di flip: le due facce occupano la stessa cella
   di una grid, così l'altezza del contenitore segue sempre la faccia
   più alta senza bisogno di calcoli via Javascript --- */
.nisama-flip3d{ perspective:1400px; }
.nisama-flip3d-inner{ display:grid; transform-style:preserve-3d; transition:transform .75s cubic-bezier(.36,1.4,.36,1); }
.nisama-flip3d-inner.is-flipped{ transform:rotateY(180deg); }
.nisama-face{ grid-area:1 / 1; backface-visibility:hidden; -webkit-backface-visibility:hidden; min-width:0; }
.nisama-face-back{ transform:rotateY(180deg); }

.nisama-form-title{ font-family:'Fraunces',serif; font-size:23px; font-weight:700; color:var(--nis-ink); margin:0 0 4px; }
.nisama-form-sub{ font-size:13.5px; color:var(--nis-text-soft); margin:0 0 20px; }

.nisama-field{ display:block; margin-bottom:14px; position:relative; }
.nisama-field span{ display:block; font-size:12.5px; font-weight:700; color:var(--nis-text-soft); margin-bottom:6px; }
.nisama-field input{
  width:100%; font-family:inherit; font-size:15px; color:var(--nis-ink); padding:12px 14px; border-radius:13px;
  border:1.5px solid var(--nis-paper-line); background:rgba(255,255,255,.7); outline:none;
  transition:border-color .18s ease, box-shadow .18s ease;
}
.nisama-field input:focus{ border-color:var(--nis-accent); box-shadow:0 0 0 4px rgba(91,141,239,.15); }
.nisama-field--password input{ padding-right:44px; }
.nisama-toggle-pw{ position:absolute; right:10px; bottom:9px; width:28px; height:28px; border:none; background:transparent; cursor:pointer; font-size:15px; opacity:.6; }
.nisama-toggle-pw:hover{ opacity:1; }

.nisama-pw-strength{ height:4px; border-radius:3px; background:rgba(38,36,28,.08); margin:-6px 0 14px; overflow:hidden; }
.nisama-pw-strength span{ display:block; height:100%; width:0%; border-radius:3px; background:var(--nis-danger); transition:width .25s ease, background .25s ease; }
.nisama-pw-strength span[data-livello="media"]{ background:#F5A623; }
.nisama-pw-strength span[data-livello="buona"], .nisama-pw-strength span[data-livello="forte"]{ background:var(--nis-good); }

.nisama-error{ font-size:13px; font-weight:600; color:var(--nis-danger); background:rgba(229,72,77,.08); border-radius:10px; padding:10px 12px; margin-bottom:14px; }

.nisama-submit{
  width:100%; border:none; cursor:pointer; font-family:inherit; font-weight:800; font-size:15px; color:#fff;
  background:linear-gradient(135deg, var(--nis-accent), var(--nis-accent-dark)); padding:13px; border-radius:14px;
  display:flex; align-items:center; justify-content:center; gap:10px; box-shadow:0 10px 24px rgba(91,141,239,.35);
  transition:transform .18s cubic-bezier(.34,1.56,.64,1), box-shadow .18s ease, opacity .18s ease;
}
.nisama-submit:hover{ transform:translateY(-1px); box-shadow:0 14px 30px rgba(91,141,239,.42); }
.nisama-submit:active{ transform:scale(.98); }
.nisama-submit:disabled{ opacity:.75; cursor:default; transform:none; }
.nisama-spinner{ width:16px; height:16px; border-radius:50%; border:2.5px solid rgba(255,255,255,.4); border-top-color:#fff; animation:nisama-spin .7s linear infinite; }
@keyframes nisama-spin{ to{ transform:rotate(360deg); } }

.nisama-switch{ text-align:center; font-size:13px; color:var(--nis-text-soft); margin:18px 0 0; }
.nisama-switch a{ color:var(--nis-accent); font-weight:700; text-decoration:none; }
.nisama-switch a:hover{ text-decoration:underline; }

@keyframes nisama-shake{ 10%,90%{transform:translateX(-1px);} 20%,80%{transform:translateX(2px);} 30%,50%,70%{transform:translateX(-4px);} 40%,60%{transform:translateX(4px);} }
.nisama-shake{ animation:nisama-shake .5s; }

@media (max-width:640px){
  .nisama-overlay{ align-items:flex-end; padding:0; }
  .nisama-modal{ max-width:100%; border-radius:26px 26px 0 0; padding:28px 22px 30px; max-height:88vh; }
  .nisama-overlay--open .nisama-modal{ transform:translateY(0); }
}
@media (prefers-reduced-motion: reduce){
  .nisama-overlay, .nisama-modal, .nisama-flip3d-inner{ transition-duration:.01ms !important; animation-duration:.01ms !important; }
}
</style>

<div id="nisama-auth-root">
  <?php if ($nisama_utente_fab):
          include __DIR__ . '/notifiche_widget.php';
        else: ?>
    <button class="nisama-fab nisama-fab--login" id="nisamaFabLogin" type="button">Accedi</button>

    <template id="nisamaAuthTemplate">
      <div class="nisama-overlay" id="nisamaOverlay" aria-hidden="true">
        <div class="nisama-modal" id="nisamaModal" role="dialog" aria-modal="true" aria-labelledby="nisamaModalTitle">
          <span class="nisama-corner-mark">Nisama</span>
          <button class="nisama-modal-close" id="nisamaClose" aria-label="Chiudi" type="button">&#10005;</button>

          <div class="nisama-tabs">
            <button class="nisama-tab active" id="tabLogin" type="button">Accedi</button>
            <button class="nisama-tab" id="tabRegister" type="button">Registrati</button>
            <span class="nisama-tab-indicator" id="tabIndicator"></span>
          </div>

          <div class="nisama-flip3d">
            <div class="nisama-flip3d-inner" id="nisamaFlip3dInner">
              <div class="nisama-face nisama-face-front">
                <form class="nisama-form" id="formLogin" novalidate>
                  <h2 id="nisamaModalTitle" class="nisama-form-title">Bentornato</h2>
                  <p class="nisama-form-sub">Accedi per continuare a studiare</p>

                  <label class="nisama-field">
                    <span>Email o nome utente</span>
                    <input type="text" name="identifier" autocomplete="username" required>
                  </label>
                  <label class="nisama-field nisama-field--password">
                    <span>Password</span>
                    <input type="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="nisama-toggle-pw" aria-label="Mostra password">&#128065;</button>
                  </label>

                  <div class="nisama-error" id="loginError" hidden></div>

                  <button type="submit" class="nisama-submit">
                    <span class="nisama-submit-label">Accedi</span>
                    <span class="nisama-spinner" hidden></span>
                  </button>

                  <p class="nisama-switch">Non hai un account? <a href="#" data-switch="registrati">Registrati</a></p>
                </form>
              </div>

              <div class="nisama-face nisama-face-back">
                <form class="nisama-form" id="formRegister" novalidate>
                  <h2 class="nisama-form-title">Crea il tuo account</h2>
                  <p class="nisama-form-sub">Inizia gratis in pochi secondi</p>

                  <label class="nisama-field">
                    <span>Nome utente</span>
                    <input type="text" name="username" autocomplete="username" required minlength="3" maxlength="30">
                  </label>
                  <label class="nisama-field">
                    <span>Email</span>
                    <input type="email" name="email" autocomplete="email" required>
                  </label>
                  <label class="nisama-field nisama-field--password">
                    <span>Password</span>
                    <input type="password" name="password" id="registerPasswordInput" autocomplete="new-password" required minlength="8">
                    <button type="button" class="nisama-toggle-pw" aria-label="Mostra password">&#128065;</button>
                  </label>
                  <div class="nisama-pw-strength" id="pwStrength"><span></span></div>
                  <label class="nisama-field nisama-field--password">
                    <span>Ripeti password</span>
                    <input type="password" name="password_confirm" autocomplete="new-password" required>
                  </label>

                  <div class="nisama-error" id="registerError" hidden></div>

                  <button type="submit" class="nisama-submit">
                    <span class="nisama-submit-label">Crea account</span>
                    <span class="nisama-spinner" hidden></span>
                  </button>

                  <p class="nisama-switch">Hai già un account? <a href="#" data-switch="login">Accedi</a></p>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  <?php endif; ?>
</div>

<?php if (!$nisama_utente_fab): ?>
<script>
(function(){
  const NISAMA_CSRF = "<?php echo htmlspecialchars($nisama_csrf, ENT_QUOTES); ?>";
  const loginFab = document.getElementById('nisamaFabLogin');
  const template = document.getElementById('nisamaAuthTemplate');

  let overlay, modal, closeBtn, tabLogin, tabRegister, tabIndicator, flipInner, formLogin, formRegister, mounted = false;

  function mostraTab(tab, animato) {
    const isLogin = tab !== 'registrati';
    tabLogin.classList.toggle('active', isLogin);
    tabRegister.classList.toggle('active', !isLogin);
    tabIndicator.style.transform = isLogin ? 'translateX(0%)' : 'translateX(100%)';
    if (animato === false) {
      flipInner.style.transition = 'none';
      flipInner.classList.toggle('is-flipped', !isLogin);
      void flipInner.offsetWidth;
      flipInner.style.transition = '';
    } else {
      flipInner.classList.toggle('is-flipped', !isLogin);
    }
  }

  function mostraErrore(container, messaggio) {
    container.textContent = messaggio;
    container.hidden = false;
    const form = container.closest('form');
    form.classList.remove('nisama-shake');
    void form.offsetWidth;
    form.classList.add('nisama-shake');
  }

  async function invia(form, url, corpo, erroreEl) {
    const submitBtn = form.querySelector('.nisama-submit');
    const spinner = submitBtn.querySelector('.nisama-spinner');
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
      let msg = (data && data.message) || 'Si è verificato un errore, riprova.';
      if (data && data.errori) msg = Object.values(data.errori)[0];
      mostraErrore(erroreEl, msg);
      submitBtn.disabled = false;
      spinner.hidden = true;
    } catch (e) {
      mostraErrore(erroreEl, 'Errore di connessione. Controlla la rete e riprova.');
      submitBtn.disabled = false;
      spinner.hidden = true;
    }
  }

  function montaModale() {
    if (mounted) return;
    mounted = true;
    document.body.appendChild(template.content.cloneNode(true));

    overlay = document.getElementById('nisamaOverlay');
    modal = document.getElementById('nisamaModal');
    closeBtn = document.getElementById('nisamaClose');
    tabLogin = document.getElementById('tabLogin');
    tabRegister = document.getElementById('tabRegister');
    tabIndicator = document.getElementById('tabIndicator');
    flipInner = document.getElementById('nisamaFlip3dInner');
    formLogin = document.getElementById('formLogin');
    formRegister = document.getElementById('formRegister');

    tabLogin.addEventListener('click', () => mostraTab('login'));
    tabRegister.addEventListener('click', () => mostraTab('registrati'));
    closeBtn.addEventListener('click', chiudi);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) chiudi(); });
    overlay.querySelectorAll('[data-switch]').forEach(a => {
      a.addEventListener('click', (e) => { e.preventDefault(); mostraTab(a.dataset.switch); });
    });
    overlay.querySelectorAll('.nisama-toggle-pw').forEach(btn => {
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
      invia(formLogin, 'login.php', {
        identifier: fd.get('identifier'), password: fd.get('password'), csrf_token: NISAMA_CSRF
      }, document.getElementById('loginError'));
    });

    formRegister.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(formRegister);
      if (fd.get('password') !== fd.get('password_confirm')) {
        mostraErrore(document.getElementById('registerError'), 'Le due password non coincidono.');
        return;
      }
      invia(formRegister, 'register.php', {
        username: fd.get('username'), email: fd.get('email'),
        password: fd.get('password'), password_confirm: fd.get('password_confirm'), csrf_token: NISAMA_CSRF
      }, document.getElementById('registerError'));
    });

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.classList.contains('nisama-overlay--open')) chiudi(); });
  }

  function apri(tab) {
    montaModale();
    mostraTab(tab || 'login', false);
    requestAnimationFrame(() => {
      overlay.classList.add('nisama-overlay--open');
      overlay.setAttribute('aria-hidden', 'false');
    });
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
      const form = (tab === 'registrati') ? formRegister : formLogin;
      const firstInput = form.querySelector('input');
      if (firstInput) firstInput.focus({ preventScroll: true });
    }, 320);
  }

  function chiudi() {
    if (!overlay) return;
    overlay.classList.remove('nisama-overlay--open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  if (loginFab) loginFab.addEventListener('click', () => apri('login'));

  if (new URLSearchParams(window.location.search).get('auth') === 'richiesto') {
    apri('login');
  }

  window.NisamaAuth = {
    apriLogin: () => apri('login'),
    apriRegistrazione: () => apri('registrati'),
    chiudi: () => chiudi()
  };
})();
</script>
<?php else: ?>
<script>
(function(){
  const userBtn = document.getElementById('nisamaUserBtn');
  if (userBtn) {
    const menu = document.getElementById('nisamaUserMenu');
    userBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const aperto = menu.classList.toggle('nisama-user-menu--open');
      userBtn.setAttribute('aria-expanded', aperto ? 'true' : 'false');
    });
    document.addEventListener('click', () => menu.classList.remove('nisama-user-menu--open'));
  }
  window.NisamaAuth = {
    apriLogin: () => { window.location.href = 'index.php'; },
    apriRegistrazione: () => { window.location.href = 'index.php'; },
    chiudi: () => {}
  };
})();
</script>
<?php endif; ?>
