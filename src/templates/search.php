<?php
/** @var string $query @var list<array{slug:string,headword:string}> $results */
?>
<section class="browse">
  <h1>Search</h1>
  <?php if ($query === ''): ?>
    <p class="empty">Type a word in the box above.</p>
  <?php elseif ($results === []): ?>
    <p class="empty">No matches for “<?= h($query) ?>”.</p>
  <?php else: ?>
    <p class="empty">Matches for “<?= h($query) ?>”.</p>
    <ul class="word-list">
      <?php foreach ($results as $row): ?>
        <li><a href="/dictionary/english/<?= h(rawurlencode($row['slug'])) ?>"><?= h($row['headword']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
