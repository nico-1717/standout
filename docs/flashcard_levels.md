# Flashcard levels

Every flashcard (and every sub-card, and every vocabulary word) has a **level**.
It is computed from the history of the answers to that card, never stored: there
is no counter to keep in sync.

Code: `lib/fc/fc_vocab_gioco.php` (`fcv_livello_prefisso()`, `fcv_livello()`).
The same rules are repeated in `livello()` of `public/fc/fc_game_juice.js`
(used for the toast after a vote): **if you change one, change the other.**

## How the history is read

Each answer is one letter, from the oldest to the newest:

| Letter | Meaning |
|---|---|
| `C` | right, in a normal game |
| `W` | wrong, in a normal game |
| `c` | right, in a **review** game ("review your mistakes") |
| `w` | wrong, in a review game |

Review games only contain cards that were just answered wrong, so their answers
count less: right answers there do not help a card recover, but a wrong answer
there can make a card "super hard".

The last 30 answers are kept as letters. The total number of answers and
**whether the card was ever wrong in its whole history** are kept as well, even
for answers older than those 30.

## The levels

| Level | Condition |
|---|---|
| **New** | Never played. |
| **To learn** | Played at most twice, and always wrong. |
| **Seems easy** | Never wrong in the whole history, answered only **1** time. |
| **Easy** | Never wrong in the whole history, answered **2 times or more**. |
| **You know it** | Right the last **3** times in a row, but wrong **at least once** in the whole history. |
| **Tricky** | In the last 5 normal games, wrong exactly **once** (if played fewer than 5 times: once in all of them). |
| **Hard** | In the last 5 normal games, wrong **twice or more** (if played only 3 times: 2 wrong). |
| **Super hard** | Hard, and also wrong in a **review** game among the last **5** answers. |
| **Achieving** | It was hard, and the last answer in a normal game was right. |
| **Mastering** | It was hard, and the last **2** answers in normal games were right. |

Notes:

- A card that has **never been wrong** is "Seems easy" after one answer and
  "Easy" from two answers on, and it **stays Easy** however many right answers
  follow. "You know it" is for a card that was wrong at some point and then
  was answered right 3 times in a row.
- From "Achieving" or "Mastering", **one wrong answer** sends the card back to
  "Hard" (or "Super hard").
- The third right answer in a row always ends "Achieving" / "Mastering": the card
  becomes "You know it".
- "Was hard" can only be known by replaying the history one answer at a time, so
  the level is computed step by step (`fcv_livello_avanza()`).

## "To review" and "cards you know"

- **Cards to review** (badge on the decks, "hard cards only" game): levels
  *To learn*, *Hard* and *Super hard*.
- **Cards you know** (home, stats): a card counts when it is marked as learnt,
  or its level is *You know it*, or it is *Easy* with at least 3 answers, or it
  is in an archived deck. Cards that are only *Easy* with 2 answers do not count yet.

## Order, from worst to best

Super hard, Hard, Tricky, To learn, New, Achieving, Seems easy, Easy, Mastering,
You know it (`FCV_LIVELLI`). It is used to sort "hardest first" and to say
whether a card went up or down after a vote.

## Group titles have no level

In study flashcards, a card that has **2 or more sub-cards and an empty answer**
is the *title* of its group, not a question. Titles have no level at all: no tag in
the card list or in the game, and they are not counted in the deck level bars,
in "to review", in "hard cards only" or in "cards you know"
(`fcv_titoli_studio()`). Vocabulary words are never titles.

## Where the level is shown

- Card list (`fc_view.php`): tag on each card, level filter and "hardest first" sort.
- Deck list (`fc_decks.php`): the coloured level bar and the "to review" badge.
- Game: not in the card title. Only a short message after a vote, when a card
  reaches "You know it", "Mastering" or "Achieving", or becomes "Hard".
