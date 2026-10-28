<?php
/**
 * fc_check_old_names.php — controllo da lanciare UNA volta, prima di
 * cancellare i vecchi file delle flashcard.
 *
 * La ristrutturazione delle flashcard ha dato un nome inglese a ogni file
 * (categories_fc.php -> fc_decks.php, floating_player.php ->
 * fc_audio_player.php...) e alle tabelle (fc_images -> fc_study_cards...).
 * I file consegnati sono gia' aggiornati; ma il sito ha anche pagine che non
 * facevano parte della consegna, e se una di loro include o chiama un vecchio
 * nome, cancellare il vecchio file la romperebbe. Questa pagina legge tutti i
 * .php, .js, .html e .css della cartella e dice chi usa ancora un nome
 * vecchio, e dove.
 *
 * Accesso: livello 10, come admin_ai.php. Non scrive niente. Quando dice
 * "nessun riferimento", i vecchi file si possono cancellare, e anche questa
 * pagina.
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once 'auth_check.php';
require_once 'security_core.php';

sec_require_level(10, false);

/* I vecchi nomi dei file. Per le tabelle si cercano solo quelle con un nome
   abbastanza particolare da non dare falsi allarmi ("games" o "images" da
   soli comparirebbero ovunque): per quelle conta la clausola SQL intera. */
$file_vecchi = [
    'admin_ia.php', 'admin_ia.css', 'ai_crediti.php', 'api_get_counts_fc.php', 'autosave_bar.css', 'autosave_bar.js',
    'autosave_bar.php', 'azione_post.php', 'big_edit_save_fc.php', 'categories_fc.css', 'categories_fc.php',
    'categories_game_fc.css', 'categories_game_fc.php', 'category_selector.css', 'category_selector.php',
    'crediti.css', 'crediti.php', 'crediti_corpo.php', 'examples_api.php', 'examples_box.css', 'examples_box.php',
    'examples_modal.css', 'examples_modal.js', 'examples_modal.php', 'fc_ai_abbina.js', 'fc_ai_formato.php',
    'fc_ai_pannello.css', 'fc_ai_pannello.js', 'fc_ai_pannello.php', 'fc_ai_stile.php', 'fc_editor_immagine.js',
    'fc_editor_immagine.php', 'fc_editor_rifiniture.css', 'fc_migliora_carta.css', 'fc_migliora_carta.js',
    'fc_migliora_carta.php', 'fc_misure_backfill.php', 'fc_misure_immagini.php', 'fc_sequenza_backfill.php',
    'floating_player.css', 'floating_player.php', 'game_fc.css', 'game_fc.php', 'history_fc.css', 'history_fc.php',
    'inserimento_big_fc.css', 'inserimento_big_fc.php', 'inserimento_fc.css', 'inserimento_fc.php',
    'modifica_immagine_fc.css', 'modifica_immagine_fc.php', 'result_fc.css', 'result_fc.php', 'script_read.js',
    'start_game_fc.php', 'start_retry.php', 'stats_fc.css', 'stats_fc.php', 'update_learnt.php',
    'visualizzazione_fc.css', 'visualizzazione_fc.php', 'voice_game_mode.css', 'voice_game_mode.php',
    // vecchio vocabolario
    'big_edit.php', 'big_edit_save.php', 'categories.php', 'inserimento.php', 'inserimento_big.php',
    'modifica_immagine.php', 'visualizzazione.php', 'generate_ai_image.php', 'generate_ai_image_pollination.php',
    'generate_ai_text.php', 'generate_ai_text_pollination.php', 'history_flashcards.php', 'categories_game.php',
    'big_edit.css', 'categories.css', 'inserimento.css', 'inserimento_big.css', 'modifica_immagine.css', 'visualizzazione.css',
];
$tabelle_vecchie = [
    'fc_images', 'fc_child_cards', 'fc_categories', 'fc_ai_data', 'user_learnt_fc', 'user_favorites_fc',
    'fc_gameplays', 'fc_game_sequence', 'game_fc_categories', 'project_fc_categories', 'session_durations',
    'fc_user_goals', 'english_child_words', 'user_learnt_english', 'gameplays', 'game_categories',
];
$sql_vecchie = ['FROM games', 'JOIN games', 'INTO games', 'UPDATE games', 'FROM images', 'JOIN images',
                'INTO images', 'UPDATE images', 'FROM categories', 'JOIN categories', 'INTO categories',
                'UPDATE categories'];

$cartella = __DIR__;
$trovati = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cartella, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $nome = $f->getFilename();
    if (!preg_match('/\.(php|js|html|css)$/i', $nome)) continue;
    if ($nome === basename(__FILE__)) continue;
    $rel = substr($f->getPathname(), strlen($cartella) + 1);
    /* I vecchi file stessi non contano: sono quelli da cancellare. */
    if (in_array($rel, $file_vecchi, true)) continue;
    $righe = @file($f->getPathname());
    if (!$righe) continue;
    foreach ($righe as $n => $riga) {
        /* Le righe di commento citano spesso i vecchi nomi per spiegare la
           storia di un file: non rompono niente, quindi non si contano. */
        if (preg_match('~^\s*(\*|//|/\*|#|<!--)~', $riga)) continue;
        foreach ($file_vecchi as $v) {
            if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote($v, '/') . '/', $riga)) $trovati[] = [$rel, $n + 1, $v, trim($riga)];
        }
        foreach ($tabelle_vecchie as $v) {
            if (preg_match('/(?<![A-Za-z0-9_\/$])`?' . preg_quote($v, '/') . '`?(?![A-Za-z0-9_\/])/', $riga)
                && preg_match('/\b(FROM|JOIN|INTO|UPDATE|TABLE)\b/i', $riga)) $trovati[] = [$rel, $n + 1, $v, trim($riga)];
        }
        foreach ($sql_vecchie as $v) {
            if (preg_match('/\b' . str_replace(' ', '\s+`?', preg_quote($v, '/')) . '`?\b/i', $riga)) $trovati[] = [$rel, $n + 1, $v, trim($riga)];
        }
    }
}
?><!DOCTYPE html>
<html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Controllo vecchi nomi</title>
<style>
body{ font:14px/1.45 -apple-system, system-ui, sans-serif; margin:20px; color:#1C1C1E; background:#F2F2F7; }
h1{ font-size:20px; } .ok{ color:#248A3D; font-weight:700; } .ko{ color:#D70015; font-weight:700; }
table{ border-collapse:collapse; width:100%; background:#fff; border-radius:12px; overflow:hidden; }
td,th{ padding:7px 10px; border-bottom:1px solid #E5E5EA; text-align:left; vertical-align:top; }
code{ font-size:12px; word-break:break-all; }
@media (prefers-color-scheme: dark){ body{ background:#000; color:#F5F5F7; } table{ background:#1C1C1E; } td,th{ border-color:#38383A; } }
</style></head><body>
<h1>Riferimenti ai vecchi nomi delle flashcard</h1>
<?php if (!$trovati): ?>
<p class="ok">Nessun riferimento: i vecchi file si possono cancellare (e anche questa pagina).</p>
<?php else: ?>
<p class="ko"><?php echo count($trovati); ?> riferimenti da controllare prima di cancellare i vecchi file.</p>
<p>Quelli dentro un commento (una spiegazione, non un include o un link) si possono ignorare: non rompono niente.</p>
<table><tr><th>File</th><th>Riga</th><th>Vecchio nome</th><th>Testo</th></tr>
<?php foreach ($trovati as $t): ?>
<tr><td><?php echo sec_h($t[0]); ?></td><td><?php echo (int)$t[1]; ?></td><td><code><?php echo sec_h($t[2]); ?></code></td><td><code><?php echo sec_h(mb_substr($t[3], 0, 220)); ?></code></td></tr>
<?php endforeach; ?>
</table>
<?php endif; ?>
</body></html>
