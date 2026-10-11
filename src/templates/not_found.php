<?php
/** @var string $attempt @var list<array{slug:string,headword:string}> $suggestions */
?>
<section class="browse">
  <h1>Not in this dictionary</h1>
  <?php if ($attempt !== ''): ?>
    <p class="empty">“<?= h($attempt) ?>” has no entry.</p>
  <?php else: ?>
    <p class="empty">That page does not exist.</p>
  <?php endif; ?>
  <?php if ($suggestions !== []): ?>
    <h2>Possible matches</h2>
    <ul class="word-list">
      <?php foreach ($suggestions as $row): ?>
        <li><a href="/dictionary/english/<?= h(rawurlencode($row['slug'])) ?>"><?= h($row['headword']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
