<?php
/** @var array{slug:string,headword:string,gloss:string}|null $word */
?>
<section class="hero">
  <p class="eyebrow">Open English dictionary</p>
  <h1>A headword, its senses, and the words beside it.</h1>
  <p class="lede">Look up an English word. Each page gives the pronunciation, the part of speech, numbered senses, and the alphabetical neighbours.</p>
</section>
<?php if ($word !== null): ?>
<section class="day">
  <p class="eyebrow">Word of the day</p>
  <h2><a href="/dictionary/english/<?= h(rawurlencode($word['slug'])) ?>"><?= h($word['headword']) ?></a></h2>
  <?php if ($word['gloss'] !== ''): ?><p><?= h($word['gloss']) ?></p><?php endif; ?>
</section>
<?php endif; ?>
<nav class="az" aria-label="Alphabetical browse">
  <a href="/browse/0">0–9</a>
  <?php foreach (range('a', 'z') as $letter): ?>
    <a href="/browse/<?= h($letter) ?>"><?= h(strtoupper($letter)) ?></a>
  <?php endforeach; ?>
</nav>
